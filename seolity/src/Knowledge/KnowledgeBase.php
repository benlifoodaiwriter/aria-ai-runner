<?php
/**
 * SEO Knowledge Base (RAG for the AI engine).
 *
 * Sources:
 *  1. Bundled curated knowledge (seolity/knowledge/*.md, Persian + English).
 *  2. A GitHub repository (default: this plugin's repo, `seolity/knowledge`)
 *     that a scheduled GitHub Action keeps updated by distilling top Iranian
 *     and international SEO documentation (see tools/knowledge-builder).
 *  3. User-added notes (optionally contributed back to GitHub).
 *
 * Documents are chunked by headings and retrieved with a fast lexical scorer
 * (topic match + normalized term overlap + language preference). The engine
 * injects the top chunks into prompts so AI output follows best practices.
 *
 * @package SEOlity
 */

namespace SEOlity\Knowledge;

use SEOlity\Analysis\Text;
use SEOlity\Core\Database;
use SEOlity\Plugin;
use SEOlity\Storage\GitHubClient;

defined( 'ABSPATH' ) || exit;

final class KnowledgeBase {

	const CHUNK_CHARS = 1800;

	/** @var Plugin */
	private $plugin;

	/** @var array */
	private $cache = array();

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/** Parse optional YAML-ish front matter. */
	public static function parse( $markdown, $path ) {
		$meta = array( 'title' => '', 'lang' => '', 'topic' => '', 'tags' => '' );
		if ( preg_match( '/^---\s*\n(.*?)\n---\s*\n/s', $markdown, $m ) ) {
			foreach ( explode( "\n", $m[1] ) as $line ) {
				if ( preg_match( '/^([a-z_]+)\s*:\s*(.+)$/i', trim( $line ), $kv ) ) {
					$meta[ strtolower( $kv[1] ) ] = trim( $kv[2], " \"'[]" );
				}
			}
			$markdown = substr( $markdown, strlen( $m[0] ) );
		}
		if ( '' === $meta['title'] && preg_match( '/^#\s+(.+)$/m', $markdown, $h ) ) {
			$meta['title'] = trim( $h[1] );
		}
		if ( '' === $meta['title'] ) {
			$meta['title'] = ucwords( str_replace( array( '-', '_' ), ' ', pathinfo( $path, PATHINFO_FILENAME ) ) );
		}
		if ( '' === $meta['lang'] ) {
			$meta['lang'] = Text::lang( $markdown );
		}
		if ( '' === $meta['topic'] ) {
			$parts         = explode( '/', trim( $path, '/' ) );
			$meta['topic'] = count( $parts ) > 1 ? $parts[ count( $parts ) - 2 ] : 'general';
		}
		return array( $meta, $markdown );
	}

	/** Split by ## headings, then by size. */
	public static function chunk( $markdown ) {
		$sections = preg_split( '/\n(?=##\s)/', "\n" . trim( $markdown ) );
		$chunks   = array();
		foreach ( $sections as $sec ) {
			$sec = trim( $sec );
			if ( '' === $sec ) {
				continue;
			}
			while ( mb_strlen( $sec ) > self::CHUNK_CHARS ) {
				$cut      = mb_strrpos( mb_substr( $sec, 0, self::CHUNK_CHARS ), "\n" );
				$cut      = $cut && $cut > 400 ? $cut : self::CHUNK_CHARS;
				$chunks[] = trim( mb_substr( $sec, 0, $cut ) );
				$sec      = trim( mb_substr( $sec, $cut ) );
			}
			$chunks[] = $sec;
		}
		return $chunks;
	}

	private function store_doc( $source, $path, $sha, $markdown ) {
		list( $meta, $body ) = self::parse( $markdown, $path );
		$db = $this->plugin->db;
		$db->delete( 'knowledge', array( 'source' => $source, 'path' => $path ) );
		foreach ( self::chunk( $body ) as $i => $chunk ) {
			$db->insert(
				'knowledge',
				array(
					'source'      => $source,
					'path'        => substr( $path, 0, 255 ),
					'sha'         => $sha,
					'title'       => mb_substr( $meta['title'], 0, 250 ),
					'lang'        => substr( $meta['lang'], 0, 10 ),
					'topic'       => substr( sanitize_key( $meta['topic'] ), 0, 60 ),
					'tags'        => mb_substr( $meta['tags'], 0, 250 ),
					'chunk_index' => $i,
					'content'     => wp_strip_all_tags( $chunk ),
					'updated_at'  => Database::now(),
				)
			);
		}
	}

	/**
	 * Sync bundled + GitHub knowledge.
	 *
	 * @return array{bundled:int, github:int, removed:int, error:?string}
	 */
	public function sync( $bundled_only = false ) {
		$stats = array( 'bundled' => 0, 'github' => 0, 'removed' => 0, 'error' => null );
		$dir   = SEOLITY_DIR . 'knowledge/';
		$files = is_dir( $dir ) ? $this->scan( $dir ) : array();
		$known = array();
		foreach ( $this->plugin->db->rows( "SELECT DISTINCT path, sha FROM {knowledge} WHERE source = 'bundled'" ) as $r ) {
			$known[ $r['path'] ] = $r['sha'];
		}
		foreach ( $files as $file ) {
			$rel = ltrim( str_replace( $dir, '', $file ), '/' );
			$md  = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			$sha = sha1( $md );
			if ( ! isset( $known[ $rel ] ) || $known[ $rel ] !== $sha ) {
				$this->store_doc( 'bundled', $rel, $sha, $md );
				$stats['bundled']++;
			}
			unset( $known[ $rel ] );
		}
		foreach ( array_keys( $known ) as $gone ) {
			$this->plugin->db->delete( 'knowledge', array( 'source' => 'bundled', 'path' => $gone ) );
		}

		$repo = (string) $this->plugin->settings->get( 'kb_repo' );
		if ( ! $bundled_only && GitHubClient::valid_repo( $repo ) ) {
			$tree = $this->plugin->github->tree( $repo, (string) $this->plugin->settings->get( 'kb_branch' ), (string) $this->plugin->settings->get( 'kb_path' ) );
			if ( is_wp_error( $tree ) ) {
				$stats['error'] = $tree->get_error_message();
			} else {
				$remote = array();
				foreach ( $this->plugin->db->rows( "SELECT DISTINCT path, sha FROM {knowledge} WHERE source = 'github'" ) as $r ) {
					$remote[ $r['path'] ] = $r['sha'];
				}
				foreach ( array_slice( $tree, 0, 400 ) as $node ) {
					if ( $node['size'] > 400000 ) {
						continue;
					}
					if ( ! isset( $remote[ $node['path'] ] ) || $remote[ $node['path'] ] !== $node['sha'] ) {
						$content = $this->plugin->github->blob( $repo, $node['sha'] );
						if ( ! is_wp_error( $content ) ) {
							$this->store_doc( 'github', $node['path'], $node['sha'], $content );
							$stats['github']++;
						}
					}
					unset( $remote[ $node['path'] ] );
				}
				foreach ( array_keys( $remote ) as $gone ) {
					$this->plugin->db->delete( 'knowledge', array( 'source' => 'github', 'path' => $gone ) );
					$stats['removed']++;
				}
			}
		}
		update_option( 'seolity_kb_synced', array( 'time' => time(), 'stats' => $stats ), false );
		return $stats;
	}

	private function scan( $dir ) {
		$out = array();
		$it  = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $f ) {
			if ( 'md' === strtolower( $f->getExtension() ) && 'README.md' !== $f->getFilename() ) {
				$out[] = $f->getPathname();
			}
		}
		sort( $out );
		return $out;
	}

	/**
	 * Retrieve the most relevant chunks.
	 *
	 * @param string   $query  Free text (keyword, task, question).
	 * @param string[] $topics Preferred topics.
	 * @return array<int, array{title:string, content:string, topic:string, path:string}>
	 */
	public function retrieve( $query, array $topics = array(), $limit = 4 ) {
		$key = md5( $query . '|' . implode( ',', $topics ) . '|' . $limit );
		if ( isset( $this->cache[ $key ] ) ) {
			return $this->cache[ $key ];
		}
		$db   = $this->plugin->db;
		$args = array();
		$sql  = 'SELECT id, source, path, title, lang, topic, tags, content FROM {knowledge}';
		if ( $topics ) {
			$sql .= ' WHERE topic IN (' . implode( ',', array_fill( 0, count( $topics ), '%s' ) ) . ') OR tags REGEXP %s';
			$args = array_merge( array_map( 'sanitize_key', $topics ), array( implode( '|', array_map( static function ( $t ) {
				return preg_quote( sanitize_key( $t ), '/' );
			}, $topics ) ) ) );
		}
		$rows = $db->rows( $sql . ' LIMIT 600', $args );
		if ( ! $rows && $topics ) {
			$rows = $db->rows( 'SELECT id, source, path, title, lang, topic, tags, content FROM {knowledge} LIMIT 600' );
		}
		$terms     = array_keys( Text::term_frequencies( $query ) );
		$site_lang = 0 === strpos( get_locale(), 'fa' ) ? 'fa' : 'en';
		$scored    = array();
		foreach ( $rows as $r ) {
			$score = in_array( $r['topic'], $topics, true ) ? 3 : 0;
			$score += $r['lang'] === $site_lang ? 1.5 : 0;
			$score += 'github' === $r['source'] ? 0.3 : 0; // Fresher distilled knowledge.
			$hay    = Text::normalize( $r['title'] . ' ' . $r['tags'] . ' ' . $r['content'] );
			$title  = Text::normalize( $r['title'] );
			foreach ( $terms as $t ) {
				if ( false !== mb_strpos( $hay, $t ) ) {
					$score += 1 + ( false !== mb_strpos( $title, $t ) ? 1.5 : 0 );
				}
			}
			$scored[] = array( $score, $r );
		}
		usort( $scored, static function ( $a, $b ) {
			return $b[0] <=> $a[0];
		} );
		$out   = array();
		$paths = array();
		foreach ( $scored as $s ) {
			if ( $s[0] <= 0 || isset( $paths[ $s[1]['path'] ] ) && $paths[ $s[1]['path'] ] >= 2 ) {
				continue;
			}
			$paths[ $s[1]['path'] ] = isset( $paths[ $s[1]['path'] ] ) ? $paths[ $s[1]['path'] ] + 1 : 1;
			$out[]                  = array( 'title' => $s[1]['title'], 'content' => $s[1]['content'], 'topic' => $s[1]['topic'], 'path' => $s[1]['path'] );
			if ( count( $out ) >= $limit ) {
				break;
			}
		}
		$this->cache[ $key ] = $out;
		return $out;
	}

	public function documents() {
		return array(
			'items'  => $this->plugin->db->rows( 'SELECT source, path, MAX(title) title, MAX(lang) lang, MAX(topic) topic, COUNT(*) chunks, MAX(updated_at) updated_at FROM {knowledge} GROUP BY source, path ORDER BY topic, path' ),
			'synced' => get_option( 'seolity_kb_synced' ),
			'repo'   => $this->plugin->settings->get( 'kb_repo' ),
			'path'   => $this->plugin->settings->get( 'kb_path' ),
		);
	}

	public function document( $source, $path ) {
		$rows = $this->plugin->db->rows( 'SELECT title, content FROM {knowledge} WHERE source = %s AND path = %s ORDER BY chunk_index', array( $source, $path ) );
		return $rows ? array( 'title' => $rows[0]['title'], 'content' => implode( "\n\n", wp_list_pluck( $rows, 'content' ) ) ) : null;
	}

	/** Add a user note; optionally push to the GitHub knowledge repo. */
	public function add_note( $title, $content, $topic = 'general', $push = false ) {
		$title   = sanitize_text_field( $title );
		$content = sanitize_textarea_field( $content );
		if ( '' === $title || '' === $content ) {
			return new \WP_Error( 'seolity_kb', __( 'Title and content are required.', 'seolity' ), array( 'status' => 400 ) );
		}
		$slug = sanitize_title( $title );
		$slug = $slug ? $slug : md5( $title );
		$path = 'user/' . sanitize_key( $topic ) . '/' . $slug . '.md';
		$md   = "---\ntitle: {$title}\ntopic: " . sanitize_key( $topic ) . "\nlang: " . Text::lang( $content ) . "\n---\n\n# {$title}\n\n{$content}\n";
		$this->store_doc( 'user', $path, sha1( $md ), $md );
		$pushed = null;
		$repo   = (string) $this->plugin->settings->get( 'kb_repo' );
		if ( $push && $this->plugin->github->has_token() && GitHubClient::valid_repo( $repo ) ) {
			$pushed = $this->plugin->github->put_file( $repo, trim( (string) $this->plugin->settings->get( 'kb_path' ), '/' ) . '/' . $path, $md, 'SEOlity: add knowledge note ' . $slug, (string) $this->plugin->settings->get( 'kb_branch' ) );
			$pushed = ! is_wp_error( $pushed );
		}
		$this->plugin->audit_log->log( 'knowledge.note_added', 'knowledge', null, array( 'path' => $path, 'pushed' => $pushed ) );
		return array( 'path' => $path, 'pushed' => $pushed );
	}

	public function delete_note( $path ) {
		return (bool) $this->plugin->db->delete( 'knowledge', array( 'source' => 'user', 'path' => $path ) );
	}

	/**
	 * Contribute anonymized, aggregated experiment learnings back to the shared
	 * GitHub knowledge base (opt-in). No URLs, domains or content are sent.
	 */
	public function contribute_learnings() {
		$repo = (string) $this->plugin->settings->get( 'kb_repo' );
		if ( ! $this->plugin->settings->get( 'kb_contribute' ) || ! $this->plugin->github->has_token() || ! GitHubClient::valid_repo( $repo ) ) {
			return false;
		}
		$insights = $this->plugin->learning->insights();
		if ( ! $insights ) {
			return false;
		}
		$site = substr( hash_hmac( 'sha256', home_url(), wp_salt( 'auth' ) ), 0, 12 );
		$md   = "---\ntitle: Measured SEO experiment results ({$site})\ntopic: experiments\nlang: en\ntags: learnings, experiments, measured\n---\n\n# Measured SEO experiment results\n\n";
		$md  .= "Aggregated, anonymized results from SEOlity experiments (before/after windows adjusted by site-wide control).\n\n";
		foreach ( $insights as $i ) {
			$md .= sprintf( "- **%s** on *%s* pages → %s %s%s (n=%d, win rate %d%%)\n", $i['change_type'], $i['object_type'], $i['metric'], $i['avg_delta'] > 0 ? '+' : '', $i['avg_delta'] . ( 'position' === $i['metric'] ? '' : '%' ), $i['samples'], $i['win_rate'] );
		}
		$res = $this->plugin->github->put_file( $repo, trim( (string) $this->plugin->settings->get( 'kb_path' ), '/' ) . '/learned/' . $site . '.md', $md, 'SEOlity: update measured learnings ' . $site, (string) $this->plugin->settings->get( 'kb_branch' ) );
		return ! is_wp_error( $res );
	}
}
