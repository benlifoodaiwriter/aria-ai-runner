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
		return array(
			'runner'    => $login . '/seolity-runner',
			'knowledge' => $login . '/seolity-knowledge',
			'storage'   => $login . '/seolity-data',
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
		foreach ( array( 'build.mjs', 'package.json', 'sources.json' ) as $name ) {
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

	/** Start the knowledge builder now. */
	public function run_builder() {
		if ( $this->shared_knowledge() ) {
			return new \WP_Error( 'seolity_github', __( 'The shared knowledge base is updated by SEOlity. Create your own knowledge repository to run the builder yourself.', 'seolity' ), array( 'status' => 400 ) );
		}
		$repo = (string) $this->plugin->settings->get( 'kb_repo' );
		$res  = $this->gh()->dispatch( $repo, self::KB_WORKFLOW, (string) $this->plugin->settings->get( 'kb_branch' ), array( 'max_sources' => '12' ) );
		return is_wp_error( $res ) ? $res : array( 'dispatched' => true, 'url' => 'https://github.com/' . $repo . '/actions' );
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
		$host = preg_replace( '/[^a-z0-9.-]/', '', strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) );
		$path = trim( (string) $this->plugin->settings->get( 'storage_path' ), '/' );
		$path = '' === $path ? 'seolity-data' : $path;
		$res  = $this->gh()->put_file( $repo, $path . '/' . $host . '/README.md', "# SEOlity data — {$host}\n\nArchived automatically by SEOlity. Do not edit by hand.\n", 'SEOlity: data archive for ' . $host, (string) $info['default_branch'] );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$this->plugin->settings->update( array( 'storage_repo' => $repo, 'storage_branch' => (string) $info['default_branch'], 'storage_path' => $path, 'storage_enabled' => true ) );
		$this->plugin->audit_log->log( 'github.storage_setup', 'site', null, array( 'repo' => $repo ) );
		return $this->status( true );
	}
}
