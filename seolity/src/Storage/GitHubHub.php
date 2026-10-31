<?php
/**
 * One place for everything SEOlity does on GitHub, with one-click setup:
 *
 *  - Account: save + verify the token (owner, token type, scopes, expiry).
 *  - Runner repository (subscription AI): private repo, workflow, runner,
 *    queue branch and Actions secrets (delegates to SubscriptionRunner).
 *  - Knowledge repository: either the shared public SEOlity knowledge base
 *    (read-only, default) or the site owner's own private copy, seeded with
 *    the bundled knowledge, the weekly "knowledge builder" workflow and
 *    (opt-in) the Anthropic key it needs.
 *  - Data repository: private archive of cold data (GSC rows, logs, reports,
 *    change history) so the hosting database stays small.
 *
 * Repository names default to <login>/seolity-runner, -knowledge, -data.
 *
 * @package SEOlity
 */

namespace SEOlity\Storage;

use SEOlity\Plugin;

defined( 'ABSPATH' ) || exit;

final class GitHubHub {

	/** The shared, read-only knowledge base every install can sync from. */
	const SHARED_KB = array(
		'repo'   => 'benlifoodaiwriter/aria-ai-runner',
		'branch' => 'main',
		'path'   => 'seolity/knowledge',
	);

	const KB_WORKFLOW = 'seolity-knowledge.yml';

	/** @var Plugin */
	private $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	private function gh() {
		return $this->plugin->github;
	}

	/** Cached token owner (5 minutes) so the status screen stays fast. */
	private function account( $fresh = false ) {
		if ( ! $this->gh()->has_token() ) {
			return null;
		}
		$cache = get_transient( 'seolity_gh_whoami' );
		if ( ! $fresh && is_array( $cache ) ) {
			return $cache;
		}
		$me = $this->gh()->whoami();
		if ( is_wp_error( $me ) ) {
			return array( 'error' => $me->get_error_message() );
		}
		set_transient( 'seolity_gh_whoami', $me, 5 * MINUTE_IN_SECONDS );
		return $me;
	}

	/** Default repository names for this account. */
	public function defaults() {
		$me    = $this->account();
		$login = is_array( $me ) && ! empty( $me['login'] ) ? $me['login'] : 'your-user';
		// Two repositories: the AI runner is PUBLIC (GitHub Actions minutes are free for public
		// repositories, so it runs without a paid plan; it only holds code and encrypted requests,
		// logins stay in hidden Secrets). The knowledge base and the data archive are not encrypted,
		// so they live together in a separate PRIVATE repository.
		$data = $login . '/seolity-ai';
		return array(
			'runner'    => $login . '/seolity-ai-runner',
			'knowledge' => $data,
			'storage'   => $data,
		);
	}

	public function shared_knowledge() {
		return (string) $this->plugin->settings->get( 'kb_repo' ) === self::SHARED_KB['repo'];
	}

	/* ------------------------------------------------------------------ status */

	public function status( $fresh = false ) {
		$s    = $this->plugin->settings;
		$me   = $this->account( $fresh );
		$warn = array();
		if ( is_array( $me ) && isset( $me['scopes'] ) && is_array( $me['scopes'] ) ) {
			foreach ( array( 'repo', 'workflow' ) as $need ) {
				if ( ! in_array( $need, $me['scopes'], true ) ) {
					/* translators: %s: OAuth scope */
					$warn[] = sprintf( __( 'The token is missing the “%s” scope.', 'seolity' ), $need );
				}
			}
		}
		if ( is_array( $me ) && ! empty( $me['expires'] ) && strtotime( $me['expires'] ) < time() + 14 * DAY_IN_SECONDS ) {
			/* translators: %s: date */
			$warn[] = sprintf( __( 'The token expires on %s. Create a new one before then.', 'seolity' ), $me['expires'] );
		}
		$sub     = $this->plugin->subscription->status();
		$runner  = $this->gh()->inspect( (string) $s->get( 'sub_repo' ) );
		$kb_repo = (string) $s->get( 'kb_repo' );
		$kb      = $this->shared_knowledge() ? array( 'repo' => $kb_repo, 'valid' => true, 'exists' => true, 'private' => false, 'url' => 'https://github.com/' . $kb_repo ) : $this->gh()->inspect( $kb_repo );
		$store   = $this->gh()->inspect( (string) $s->get( 'storage_repo' ) );
		$docs    = $this->plugin->db->rows( 'SELECT source, COUNT(DISTINCT path) docs FROM {knowledge} GROUP BY source' );
		$counts  = array();
		foreach ( $docs as $d ) {
			$counts[ $d['source'] ] = (int) $d['docs'];
		}
		$kb_runs = array();
		if ( ! $this->shared_knowledge() && ! empty( $kb['exists'] ) ) {
			$kb_runs = $this->gh()->runs( $kb_repo, self::KB_WORKFLOW, 5 );
			$kb_runs = is_wp_error( $kb_runs ) ? array() : $kb_runs;
		}
		return array(
			'token'     => array(
				'set'      => $this->gh()->has_token(),
				'account'  => is_array( $me ) && empty( $me['error'] ) ? $me : null,
				'error'    => is_array( $me ) && ! empty( $me['error'] ) ? $me['error'] : null,
				'warnings' => $warn,
			),
			'defaults'  => $this->defaults(),
			'runner'    => array_merge( $runner, array( 'setup' => $sub['setup'], 'files_current' => $sub['files_current'], 'claude' => $sub['claude_token'], 'codex' => $sub['codex_auth'] ) ),
			'knowledge' => array_merge(
				$kb,
				array(
					'mode'          => $this->shared_knowledge() ? 'shared' : 'own',
					'branch'        => (string) $s->get( 'kb_branch' ),
					'path'          => (string) $s->get( 'kb_path' ),
					'auto_sync'     => (bool) $s->get( 'kb_auto_sync' ),
					'contribute'    => (bool) $s->get( 'kb_contribute' ),
					'docs'          => $counts,
					'last_sync'     => get_option( 'seolity_kb_synced' ),
					'builder_key'   => get_option( 'seolity_kb_builder_key' ) ? true : false,
					'anthropic_key' => $s->has_secret( 'anthropic_api_key' ),
					'runs'          => $kb_runs,
					'shared'        => self::SHARED_KB,
				)
			),
			'storage'   => array_merge( $store, array( 'enabled' => (bool) $s->get( 'storage_enabled' ), 'archive' => $this->plugin->archive->status() ) ),
		);
	}

	/* ------------------------------------------------------------------ account */

	/**
	 * Save and verify a token. A token GitHub rejects is not stored.
	 *
	 * @return array|\WP_Error
	 */
	public function connect( $token ) {
		$token = trim( (string) $token );
		if ( '' === $token ) {
			return new \WP_Error( 'seolity_github', __( 'Paste the GitHub token first.', 'seolity' ), array( 'status' => 400 ) );
		}
		$me = $this->gh()->whoami( $token );
		if ( is_wp_error( $me ) ) {
			return new \WP_Error( 'seolity_github', __( 'GitHub did not accept this token. Copy it again (it starts with ghp_ or github_pat_) or create a new one.', 'seolity' ) . ' (' . $me->get_error_message() . ')', array( 'status' => 400 ) );
		}
		$this->plugin->settings->set_secret( 'github_token', $token );
		delete_transient( 'seolity_gh_whoami' );
		$this->plugin->audit_log->log( 'github.connected', 'site', null, array( 'login' => $me['login'], 'type' => $me['type'] ) );
		// The runner repository keeps its own copy of nothing GitHub-related, so nothing else to sync.
		return $this->status( true );
	}

	public function disconnect() {
		$this->plugin->settings->set_secret( 'github_token', '' );
		delete_transient( 'seolity_gh_whoami' );
		$this->plugin->audit_log->log( 'github.disconnected', 'site', null, array() );
		return $this->status( true );
	}

	private function need_token() {
		if ( ! $this->gh()->has_token() ) {
			return new \WP_Error( 'seolity_github', __( 'Connect your GitHub account first (step 1).', 'seolity' ), array( 'status' => 400 ) );
		}
		return true;
	}

	private function pick_repo( $repo, $kind ) {
		$repo = trim( (string) $repo );
		if ( '' === $repo ) {
			$repo = $this->defaults()[ $kind ];
		}
		if ( false === strpos( $repo, '/' ) ) {
			$me   = $this->account();
			$repo = ( is_array( $me ) && ! empty( $me['login'] ) ? $me['login'] : '' ) . '/' . $repo;
		}
		if ( ! GitHubClient::valid_repo( $repo ) || 0 === strpos( $repo, 'your-user/' ) ) {
			return new \WP_Error( 'seolity_github', __( 'Repository must look like owner/name.', 'seolity' ), array( 'status' => 400 ) );
		}
		return $repo;
	}

	/* ------------------------------------------------------------------ runner */

	/** One click: create the private runner repo and install workflow, runner and secrets. */
	public function setup_runner( $repo ) {
		$ok = $this->need_token();
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$repo = $this->pick_repo( $repo, 'runner' );
		if ( is_wp_error( $repo ) ) {
			return $repo;
		}
		$this->plugin->settings->update( array( 'sub_repo' => $repo ) );
		$res = $this->plugin->subscription->setup();
		return is_wp_error( $res ) ? $res : $this->status( true );
	}

	/* ------------------------------------------------------------------ knowledge */

	/** Files of the knowledge repository (same layout as the shared one, so the builder runs unchanged). */
	private static function knowledge_files() {
		$files = array();
		$root  = SEOLITY_DIR . 'knowledge/';
		$it    = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $f ) {
			if ( $f->isFile() && preg_match( '/\.(md|json)$/', $f->getFilename() ) ) {
				$rel                                 = str_replace( '\\', '/', substr( $f->getPathname(), strlen( $root ) ) );
				$files[ 'seolity/knowledge/' . $rel ] = $f->getPathname();
			}
		}
		foreach ( array( 'build.mjs', 'llm.mjs', 'package.json', 'sources.json' ) as $name ) {
			if ( file_exists( SEOLITY_DIR . 'tools/knowledge-builder/' . $name ) ) {
				$files[ 'seolity/tools/knowledge-builder/' . $name ] = SEOLITY_DIR . 'tools/knowledge-builder/' . $name;
			}
		}
		$files[ '.github/workflows/' . self::KB_WORKFLOW ] = SEOLITY_DIR . 'runner/seolity-knowledge.yml';
		return $files;
	}

	/**
	 * One click: create the owner's private knowledge repository, seed it and switch to it.
	 *
	 * @param bool $share_key Store the site's Anthropic API key as the builder secret.
	 */
	public function setup_knowledge( $repo, $share_key = false ) {
		$ok = $this->need_token();
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$repo = $this->pick_repo( $repo, 'knowledge' );
		if ( is_wp_error( $repo ) ) {
			return $repo;
		}
		$info = $this->gh()->ensure_repo( $repo, 'SEOlity SEO knowledge base (private)' );
		if ( is_wp_error( $info ) ) {
			return $info;
		}
		if ( empty( $info['private'] ) ) {
			return new \WP_Error( 'seolity_github', __( 'The knowledge base needs a private repository (the public one is only for the AI runner). Choose another name.', 'seolity' ), array( 'status' => 400 ) );
		}
		$branch = (string) $info['default_branch'];
		$files  = array();
		foreach ( self::knowledge_files() as $path => $file ) {
			$files[ $path ] = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		$files['README.md'] = "# SEOlity knowledge base\n\nPrivate SEO knowledge for SEOlity (" . home_url( '/' ) . ").\n\n- `seolity/knowledge/fa`, `seolity/knowledge/en`: bundled best practices.\n- `seolity/knowledge/notes`: notes you add in SEOlity.\n- `seolity/knowledge/distilled`: written weekly by the **SEOlity knowledge builder** workflow (needs the repository secret `ANTHROPIC_API_KEY`).\n- `seolity/knowledge/learned`: measured learnings from your SEO experiments.\n";
		$res = $this->gh()->commit_files( $repo, $branch, $files, 'SEOlity: knowledge base ' . SEOLITY_VERSION );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( $share_key && $this->plugin->settings->has_secret( 'anthropic_api_key' ) ) {
			$sec = $this->gh()->put_secret( $repo, 'ANTHROPIC_API_KEY', (string) $this->plugin->settings->secret( 'anthropic_api_key' ) );
			if ( ! is_wp_error( $sec ) ) {
				update_option( 'seolity_kb_builder_key', time(), false );
			}
		}
		$this->plugin->settings->update( array( 'kb_repo' => $repo, 'kb_branch' => $branch, 'kb_path' => 'seolity/knowledge' ) );
		$this->plugin->knowledge->sync( false );
		$this->plugin->audit_log->log( 'github.knowledge_setup', 'site', null, array( 'repo' => $repo ) );
		return $this->status( true );
	}

	public function use_shared_knowledge() {
		$this->plugin->settings->update( array( 'kb_repo' => self::SHARED_KB['repo'], 'kb_branch' => self::SHARED_KB['branch'], 'kb_path' => self::SHARED_KB['path'], 'kb_contribute' => false ) );
		$this->plugin->knowledge->sync( false );
		return $this->status( true );
	}

	public function sync_knowledge() {
		return $this->plugin->knowledge->sync( false );
	}

	/** Store the Anthropic key in the knowledge repo so the weekly builder can run. */
	public function share_builder_key() {
		if ( $this->shared_knowledge() ) {
			return new \WP_Error( 'seolity_github', __( 'Create your own knowledge repository first.', 'seolity' ), array( 'status' => 400 ) );
		}
		if ( ! $this->plugin->settings->has_secret( 'anthropic_api_key' ) ) {
			return new \WP_Error( 'seolity_github', __( 'Save an Anthropic API key in AI engine settings first.', 'seolity' ), array( 'status' => 400 ) );
		}
		$res = $this->gh()->put_secret( (string) $this->plugin->settings->get( 'kb_repo' ), 'ANTHROPIC_API_KEY', (string) $this->plugin->settings->secret( 'anthropic_api_key' ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		update_option( 'seolity_kb_builder_key', time(), false );
		return array( 'ok' => true );
	}

	/** Host name used for this site's folder in the knowledge repository. */
	public static function site_host() {
		return preg_replace( '/[^a-z0-9.-]/', '', strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) );
	}

	/** What the knowledge builder should study for this site. */
	public function site_brief() {
		$p       = $this->plugin;
		$a       = $p->gsc_analytics;
		$queries = array();
		$pages   = array();
		if ( $a->has_data() ) {
			foreach ( $a->grouped( 'query', 90, 60, 'impressions' ) as $q ) {
				$queries[] = array( 'query' => $q['query'], 'impressions' => (int) $q['impressions'], 'clicks' => (int) $q['clicks'], 'position' => $q['position'] );
			}
			foreach ( $a->grouped( 'page', 90, 25, 'impressions' ) as $row ) {
				$id      = url_to_postid( $row['page'] );
				$pages[] = array( 'url' => $row['page'], 'title' => $id ? get_the_title( $id ) : '', 'impressions' => (int) $row['impressions'] );
			}
		}
		if ( ! $pages ) {
			foreach ( get_posts( array( 'post_type' => array( 'page', 'post', 'product' ), 'posts_per_page' => 25, 'post_status' => 'publish' ) ) as $post ) {
				$pages[] = array( 'url' => get_permalink( $post ), 'title' => $post->post_title, 'impressions' => 0 );
			}
		}
		$competitors = array();
		$urls        = array();
		foreach ( $p->db->rows( "SELECT id, domain FROM {competitors} WHERE project_id = %d AND status = 'active' LIMIT 6", array( $p->db->project_id() ) ) as $c ) {
			$competitors[] = $c['domain'];
			$top           = $p->db->column( 'SELECT url FROM {competitor_pages} WHERE competitor_id = %d AND status_code = 200 ORDER BY word_count DESC LIMIT 3', array( (int) $c['id'] ) );
			$urls          = array_merge( $urls, $top ? $top : array( 'https://' . $c['domain'] . '/' ) );
		}
		$key     = $p->meta->meta_key( 'focus_keyword' );
		$focus   = $p->db->column( "SELECT DISTINCT meta_value FROM {$p->db->wpdb->postmeta} WHERE meta_key = %s AND meta_value <> '' LIMIT 60", array( $key ) );
		$lang    = (string) $p->settings->get( 'ai_content_language' );
		$lang    = in_array( $lang, array( 'fa', 'en' ), true ) ? $lang : ( 0 === strpos( get_locale(), 'fa' ) ? 'fa' : 'en' );
		return array(
			'site_name'       => get_bloginfo( 'name' ),
			'url'             => home_url( '/' ),
			'description'     => get_bloginfo( 'description' ),
			'language'        => $lang,
			'is_store'        => class_exists( 'WooCommerce' ),
			'queries'         => $queries,
			'pages'           => $pages,
			'focus_keywords'  => array_values( array_slice( $focus, 0, 60 ) ),
			'competitors'     => $competitors,
			'competitor_urls' => array_values( array_slice( array_unique( $urls ), 0, 12 ) ),
			'generated'       => gmdate( 'c' ),
		);
	}

	/**
	 * "Collect knowledge now": write the site brief, make sure the builder can use the
	 * subscription account, and start the workflow. The page polls collect_status().
	 */
	public function collect_knowledge() {
		if ( $this->shared_knowledge() ) {
			return new \WP_Error( 'seolity_github', __( 'Create your own knowledge repository first (one click on the GitHub & data page); the shared one cannot learn about your site.', 'seolity' ), array( 'status' => 400 ) );
		}
		$ok = $this->need_token();
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$s      = $this->plugin->settings;
		$repo   = (string) $s->get( 'kb_repo' );
		$branch = (string) $s->get( 'kb_branch' );
		$host   = self::site_host();
		// Builder files may be from an older plugin version: refresh them with the brief in one commit.
		$files = array();
		foreach ( self::knowledge_files() as $path => $file ) {
			if ( 0 !== strpos( $path, 'seolity/knowledge/' ) ) {
				$files[ $path ] = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
		}
		$files[ trim( (string) $s->get( 'kb_path' ), '/' ) . '/site/' . $host . '/brief.json' ] = (string) wp_json_encode( $this->site_brief(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		$res = $this->gh()->commit_files( $repo, $branch, $files, 'SEOlity: knowledge brief for ' . $host );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		// The builder needs an AI account in this repository.
		$names = $this->gh()->secret_names( $repo );
		$names = is_wp_error( $names ) ? array() : $names;
		foreach ( array( 'CLAUDE_CODE_OAUTH_TOKEN' => 'claude_oauth_token', 'CODEX_AUTH_JSON' => 'codex_auth_json' ) as $secret => $local ) {
			if ( ! in_array( $secret, $names, true ) && $s->has_secret( $local ) ) {
				$v = $s->secret( $local );
				$this->gh()->put_secret( $repo, $secret, is_array( $v ) ? wp_json_encode( $v ) : (string) $v );
				$names[] = $secret;
			}
		}
		if ( ! array_intersect( array( 'ANTHROPIC_API_KEY', 'CLAUDE_CODE_OAUTH_TOKEN', 'CODEX_AUTH_JSON' ), $names ) ) {
			return new \WP_Error( 'seolity_github', __( 'The knowledge builder needs an AI account: deploy subscription AI (Claude or ChatGPT) or give it your Anthropic key first.', 'seolity' ), array( 'status' => 400 ) );
		}
		$this->gh()->ensure_actions( $repo );
		$this->gh()->workflow_state( $repo, self::KB_WORKFLOW, true );
		$run = $this->gh()->dispatch( $repo, self::KB_WORKFLOW, $branch, array( 'site' => $host, 'max_sources' => '8' ) );
		if ( is_wp_error( $run ) ) {
			return $run;
		}
		update_option( 'seolity_kb_collect', array( 'started' => time(), 'host' => $host, 'synced' => false ), false );
		$this->plugin->audit_log->log( 'knowledge.collect', 'site', null, array( 'repo' => $repo ) );
		return array( 'dispatched' => true, 'url' => 'https://github.com/' . $repo . '/actions' );
	}

	/** Progress of the last collection; syncs the new knowledge once the run succeeded. */
	public function collect_status() {
		$job = get_option( 'seolity_kb_collect' );
		if ( ! is_array( $job ) || $this->shared_knowledge() ) {
			return array( 'state' => 'idle' );
		}
		$repo = (string) $this->plugin->settings->get( 'kb_repo' );
		$runs = $this->gh()->runs( $repo, self::KB_WORKFLOW, 3 );
		$run  = null;
		foreach ( is_wp_error( $runs ) ? array() : $runs as $r ) {
			if ( strtotime( $r['created_at'] ) >= (int) $job['started'] - 60 ) {
				$run = $r;
				break;
			}
		}
		$out = array( 'state' => 'starting', 'started' => gmdate( 'c', (int) $job['started'] ), 'run' => $run );
		if ( $run ) {
			$out['state'] = 'completed' === $run['status'] ? ( 'success' === $run['conclusion'] ? 'done' : 'failed' ) : 'running';
		} elseif ( time() - (int) $job['started'] > 15 * MINUTE_IN_SECONDS ) {
			$out['state'] = 'failed';
		}
		if ( 'done' === $out['state'] && empty( $job['synced'] ) ) {
			$out['sync']   = $this->plugin->knowledge->sync( false );
			$job['synced'] = true;
			update_option( 'seolity_kb_collect', $job, false );
		}
		if ( 'done' === $out['state'] ) {
			$f = $this->gh()->get_file( $repo, trim( (string) $this->plugin->settings->get( 'kb_path' ), '/' ) . '/last-run.json', (string) $this->plugin->settings->get( 'kb_branch' ) );
			$out['report'] = is_wp_error( $f ) ? null : json_decode( $f['content'], true );
		}
		return $out;
	}

	/** Start the knowledge builder now. */
	public function run_builder() {
		if ( $this->shared_knowledge() ) {
			return new \WP_Error( 'seolity_github', __( 'The shared knowledge base is updated by SEOlity. Create your own knowledge repository to run the builder yourself.', 'seolity' ), array( 'status' => 400 ) );
		}
		$repo = (string) $this->plugin->settings->get( 'kb_repo' );
		$res  = $this->gh()->dispatch( $repo, self::KB_WORKFLOW, (string) $this->plugin->settings->get( 'kb_branch' ), array( 'max_sources' => '12' ) );
		return is_wp_error( $res ) ? $res : array( 'dispatched' => true, 'url' => 'https://github.com/' . $repo . '/actions' );
	}

	/**
	 * One click: a PUBLIC runner repository (free GitHub Actions minutes) and a PRIVATE data
	 * repository for the knowledge base and the data archive.
	 *
	 * @param string $repo  Runner repository (empty = default).
	 * @param array  $parts runner, knowledge, storage (bools).
	 * @param string $data  Data repository (empty = "<runner>-data" or the default).
	 * @return array{ok:bool,steps:array[],status:array}
	 */
	public function setup_all( $repo, array $parts, $share_key = false, $data = '' ) {
		$ok = $this->need_token();
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$given = trim( (string) $repo );
		$repo  = $this->pick_repo( $repo, 'runner' );
		if ( is_wp_error( $repo ) ) {
			return $repo;
		}
		$data = $this->pick_repo( '' !== trim( (string) $data ) ? $data : ( '' !== $given ? $repo . '-data' : '' ), 'storage' );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( $data === $repo ) {
			return new \WP_Error( 'seolity_github', __( 'The runner and the data need two different repositories: the runner is public, the data stays private.', 'seolity' ), array( 'status' => 400 ) );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 240 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		$steps = array();
		$all   = true;
		$jobs  = array(
			'runner'    => array( __( 'AI runner — public repository (free GitHub Actions minutes; requests are encrypted, logins stay in hidden Secrets)', 'seolity' ), function () use ( $repo ) {
				$this->plugin->settings->update( array( 'sub_repo' => $repo ) );
				return $this->plugin->subscription->setup();
			} ),
			'knowledge' => array( __( 'Own knowledge base — private data repository', 'seolity' ), function () use ( $data, $share_key ) {
				return $this->setup_knowledge( $data, $share_key );
			} ),
			'storage'   => array( __( 'Data archive — private data repository', 'seolity' ), function () use ( $data ) {
				return $this->setup_storage( $data );
			} ),
		);
		foreach ( $jobs as $key => $job ) {
			if ( empty( $parts[ $key ] ) ) {
				continue;
			}
			$res     = call_user_func( $job[1] );
			$steps[] = array( 'title' => $job[0], 'ok' => ! is_wp_error( $res ), 'detail' => is_wp_error( $res ) ? $res->get_error_message() : ( 'runner' === $key ? $repo : $data ) );
			$all     = $all && ! is_wp_error( $res );
		}
		return array( 'ok' => $all, 'steps' => $steps, 'status' => $this->status( true ) );
	}

	/* ------------------------------------------------------------------ data storage */

	/** One click: private data repository + enable archiving + write test. */
	public function setup_storage( $repo ) {
		$ok = $this->need_token();
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$repo = $this->pick_repo( $repo, 'storage' );
		if ( is_wp_error( $repo ) ) {
			return $repo;
		}
		$info = $this->gh()->ensure_repo( $repo, 'SEOlity data archive (private)' );
		if ( is_wp_error( $info ) ) {
			return $info;
		}
		if ( empty( $info['private'] ) ) {
			return new \WP_Error( 'seolity_github', __( 'The data repository must be private. Change its visibility on GitHub or choose another name.', 'seolity' ), array( 'status' => 400 ) );
		}
		$host   = preg_replace( '/[^a-z0-9.-]/', '', strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) );
		$path   = trim( (string) $this->plugin->settings->get( 'storage_path' ), '/' );
		$path   = '' === $path ? 'seolity-data' : $path;
		$branch = (string) $info['default_branch'];
		$shared = in_array( $repo, array( (string) $this->plugin->settings->get( 'sub_repo' ), (string) $this->plugin->settings->get( 'kb_repo' ) ), true );
		if ( $shared ) {
			// Same repository as the runner/knowledge: keep archives on their own branch.
			$b = $this->gh()->ensure_branch( $repo, 'seolity-data', $branch );
			if ( is_wp_error( $b ) ) {
				return $b;
			}
			$branch = 'seolity-data';
		}
		$res = $this->gh()->put_file( $repo, $path . '/' . $host . '/README.md', "# SEOlity data — {$host}\n\nArchived automatically by SEOlity. Do not edit by hand.\n", 'SEOlity: data archive for ' . $host, $branch );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$this->plugin->settings->update( array( 'storage_repo' => $repo, 'storage_branch' => $branch, 'storage_path' => $path, 'storage_enabled' => true ) );
		$this->plugin->audit_log->log( 'github.storage_setup', 'site', null, array( 'repo' => $repo ) );
		return $this->status( true );
	}
}
