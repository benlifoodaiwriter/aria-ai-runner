<?php
/**
 * Full-site / incremental crawler (section 7).
 *
 * crawl_start → crawl_seed (WP objects + sitemap, chunked) → crawl_batch × N
 * (parallel fetch, parse, page checks, link discovery) → crawl_finalize
 * (link graph, depth, duplicates, orphans, chains, sitemap/robots, summary).
 *
 * @package SEOlity
 */

namespace SEOlity\Crawler;

use SEOlity\Analysis\Text;
use SEOlity\Core\Database;
use SEOlity\Core\Http;
use SEOlity\Plugin;

defined( 'ABSPATH' ) || exit;

final class Crawler {

	const SKIP_PATTERNS = '#(/wp-admin|/wp-json|/wp-login|xmlrpc\.php|/feed/?$|/comments/feed|/embed/?$|[?&](add-to-cart|replytocom|s|orderby|filter_|min_price|max_price|wc-ajax|_wpnonce)=|\.(jpe?g|png|gif|webp|avif|svg|pdf|zip|rar|mp4|mp3|css|js|xml|txt|ico|woff2?)(\?|$))#i';

	/** @var Plugin */
	private $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	private function pid() {
		return $this->plugin->db->project_id();
	}

	public function current_audit() {
		return Database::decode( $this->plugin->db->row( 'SELECT * FROM {audits} WHERE project_id = %d ORDER BY id DESC', array( $this->pid() ) ), array( 'summary' ) );
	}

	public function start( $type = 'full' ) {
		$db      = $this->plugin->db;
		$running = $db->row( "SELECT id, started_at FROM {audits} WHERE project_id = %d AND status = 'running' ORDER BY id DESC", array( $this->pid() ) );
		if ( $running && strtotime( $running['started_at'] . ' UTC' ) > time() - 6 * HOUR_IN_SECONDS ) {
			return (int) $running['id'];
		}
		if ( $running ) {
			$db->update( 'audits', array( 'status' => 'abandoned', 'finished_at' => Database::now() ), array( 'id' => (int) $running['id'] ) );
		}
		$type     = 'incremental' === $type ? 'incremental' : 'full';
		$audit_id = $db->insert(
			'audits',
			array(
				'project_id' => $this->pid(),
				'type'       => $type,
				'status'     => 'running',
				'summary'    => array( 'phase' => 'seeding' ),
				'started_at' => Database::now(),
			)
		);
		if ( 'full' === $type ) {
			$db->query( "UPDATE {urls} SET crawl_status = 'pending' WHERE project_id = %d AND source IN ('wp','crawl','sitemap')", array( $this->pid() ) );
		}
		$this->plugin->audit_log->log( 'audit.started', 'audit', $audit_id, array( 'type' => $type ) );
		$this->plugin->queue->push( 'crawl_seed', array( 'audit_id' => $audit_id, 'phase' => 'home', 'offset' => 0 ), array( 'priority' => 5, 'group' => 'crawl' ) );
		return $audit_id;
	}

	/**
	 * Seed URLs from WordPress itself (authoritative list of published content) and the sitemap.
	 */
	public function seed( $audit_id, $phase, $offset ) {
		$audit = $this->plugin->db->row( 'SELECT type, status FROM {audits} WHERE id = %d', array( (int) $audit_id ) );
		if ( ! $audit || 'running' !== $audit['status'] ) {
			return true;
		}
		$full  = 'full' === $audit['type'];
		$chunk = 500;
		$next  = null;
		switch ( $phase ) {
			case 'home':
				$this->add_url( home_url( '/' ), 0, 'wp', 'home', 0, array() );
				$next = array( 'posts', 0 );
				break;
			case 'posts':
				$types = array_values( array_diff( get_post_types( array( 'public' => true ) ), array( 'attachment' ) ) );
				$ids   = get_posts(
					array(
						'post_type'        => $types,
						'post_status'      => 'publish',
						'fields'           => 'ids',
						'posts_per_page'   => $chunk,
						'offset'           => (int) $offset,
						'orderby'          => 'ID',
						'order'            => 'ASC',
						'has_password'     => false,
						'suppress_filters' => true,
						'no_found_rows'    => true,
					)
				);
				if ( $full || $offset < 2000 ) {
					_prime_post_caches( $ids, false, false );
					foreach ( $ids as $id ) {
						$url = get_permalink( $id );
						if ( $url ) {
							$this->add_url( $url, 1, 'wp', 'post', $id, array( 'post_type' => get_post_type( $id ) ), $full );
						}
					}
				}
				$next = count( $ids ) === $chunk && $this->url_count() < (int) $this->plugin->settings->get( 'crawl_max_urls' ) ? array( 'posts', $offset + $chunk ) : array( 'terms', 0 );
				break;
			case 'terms':
				$terms = get_terms(
					array(
						'taxonomy'   => get_taxonomies( array( 'public' => true ) ),
						'hide_empty' => true,
						'number'     => $chunk,
						'offset'     => (int) $offset,
					)
				);
				foreach ( is_wp_error( $terms ) ? array() : $terms as $term ) {
					if ( 'post_format' === $term->taxonomy ) {
						continue;
					}
					$url = get_term_link( $term );
					if ( ! is_wp_error( $url ) ) {
						$this->add_url( $url, 1, 'wp', 'term', $term->term_id, array( 'taxonomy' => $term->taxonomy ), $full );
					}
				}
				$next = ( ! is_wp_error( $terms ) && count( $terms ) === $chunk ) ? array( 'terms', $offset + $chunk ) : array( 'sitemap', 0 );
				break;
			case 'sitemap':
				$this->plugin->db->query( 'UPDATE {urls} SET in_sitemap = 0 WHERE project_id = %d', array( $this->pid() ) );
				foreach ( array_slice( $this->plugin->sitemap->collect_urls( 5000 ), 0, 5000 ) as $url ) {
					$this->add_url( $url, 1, 'sitemap', null, 0, array(), $full, true );
				}
				$next = null;
				break;
		}
		if ( $next ) {
			$this->plugin->queue->push( 'crawl_seed', array( 'audit_id' => (int) $audit_id, 'phase' => $next[0], 'offset' => $next[1] ), array( 'priority' => 5, 'group' => 'crawl' ) );
		} else {
			$this->set_phase( $audit_id, 'crawling' );
			$this->plugin->queue->push( 'crawl_batch', array( 'audit_id' => (int) $audit_id ), array( 'priority' => 6, 'group' => 'crawl' ) );
		}
		return true;
	}

	private function set_phase( $audit_id, $phase ) {
		$this->plugin->db->update( 'audits', array( 'summary' => array( 'phase' => $phase ), 'urls_total' => $this->url_count() ), array( 'id' => (int) $audit_id ) );
	}

	private function url_count() {
		return (int) $this->plugin->db->value( 'SELECT COUNT(*) FROM {urls} WHERE project_id = %d', array( $this->pid() ) );
	}

	/**
	 * Register a URL for crawling (insert or re-queue).
	 */
	private function add_url( $url, $depth, $source, $object_type, $object_id, array $data, $requeue = true, $in_sitemap = false ) {
		$url  = PageParser::normalize_url( $url );
		$db   = $this->plugin->db;
		$hash = Database::url_hash( $url );
		$row  = $db->row( 'SELECT id, data FROM {urls} WHERE project_id = %d AND url_hash = %s', array( $this->pid(), $hash ) );
		if ( $row ) {
			$update = array();
			if ( $requeue ) {
				$update['crawl_status'] = 'pending';
			}
			if ( $object_type ) {
				$update['object_type'] = $object_type;
				$update['object_id']   = (int) $object_id;
				$update['source']      = $source;
				$update['data']        = array_merge( (array) json_decode( (string) $row['data'], true ), $data );
			}
			if ( $in_sitemap ) {
				$update['in_sitemap'] = 1;
			}
			if ( $update ) {
				$db->update( 'urls', $update, array( 'id' => (int) $row['id'] ) );
			}
			return (int) $row['id'];
		}
		return $db->insert(
			'urls',
			array(
				'project_id'   => $this->pid(),
				'url'          => $url,
				'url_hash'     => $hash,
				'object_type'  => $object_type,
				'object_id'    => $object_id ? (int) $object_id : null,
				'source'       => $source,
				'depth'        => (int) $depth,
				'in_sitemap'   => $in_sitemap ? 1 : 0,
				'data'         => $data,
				'crawl_status' => 'pending',
			)
		);
	}

	public function process_batch( $audit_id ) {
		$db    = $this->plugin->db;
		$audit = $db->row( 'SELECT * FROM {audits} WHERE id = %d', array( (int) $audit_id ) );
		if ( ! $audit || 'running' !== $audit['status'] ) {
			return true;
		}
		try {
			$this->plugin->queue->checkpoint();
		} catch ( \SEOlity\Queue\Cancelled $c ) {
			$db->update( 'audits', array( 'status' => 'cancelled', 'finished_at' => Database::now() ), array( 'id' => (int) $audit_id ) );
			$db->query( "UPDATE {urls} SET crawl_status = 'pending' WHERE project_id = %d AND crawl_status = 'crawling'", array( $this->pid() ) );
			throw $c;
		}
		$size = (int) $this->plugin->settings->get( 'crawl_batch_size' );
		$rows = $db->rows( "SELECT * FROM {urls} WHERE project_id = %d AND crawl_status = 'pending' ORDER BY depth ASC, id ASC LIMIT %d", array( $this->pid(), $size ) );
		if ( ! $rows ) {
			$this->plugin->queue->push( 'crawl_finalize', array( 'audit_id' => (int) $audit_id ), array( 'priority' => 6, 'group' => 'crawl', 'unique' => 'finalize_' . $audit_id ) );
			return true;
		}
		$ids = wp_list_pluck( $rows, 'id' );
		$db->query( "UPDATE {urls} SET crawl_status = 'crawling' WHERE id IN (" . implode( ',', array_map( 'intval', $ids ) ) . ')' );

		$urls    = array();
		$skipped = array();
		foreach ( $rows as $r ) {
			if ( ! $this->plugin->robots->is_allowed( $r['url'] ) && ! $r['object_id'] ) {
				$skipped[] = (int) $r['id'];
				continue;
			}
			$urls[ $r['url'] ] = $r;
		}
		if ( $skipped ) {
			$db->query( "UPDATE {urls} SET crawl_status = 'blocked', last_crawled = %s WHERE id IN (" . implode( ',', $skipped ) . ')', array( Database::now() ) );
		}
		$responses = $urls ? Http::multi( array_keys( $urls ), (int) $this->plugin->settings->get( 'crawl_timeout' ) ) : array();
		$max_urls  = (int) $this->plugin->settings->get( 'crawl_max_urls' );
		$count     = $this->url_count();

		foreach ( $responses as $url => $res ) {
			$row  = Database::decode( $urls[ $url ], array( 'data' ) );
			$data = is_array( $row['data'] ) ? $row['data'] : array();
			$ct   = isset( $res['headers']['content-type'] ) ? (string) ( is_array( $res['headers']['content-type'] ) ? end( $res['headers']['content-type'] ) : $res['headers']['content-type'] ) : '';
			$html = ( 200 === $res['status'] && false !== stripos( $ct, 'html' ) ) ? $res['body'] : '';
			$page = PageParser::parse( $html, $url, $res['headers'] );
			$loc  = $res['location'] ? PageParser::absolutize( $res['location'], $url ) : null;

			$found = $res['error'] ? array( 'http_5xx' => array( 'error' => $res['error'] ) ) : Checks::evaluate_page(
				array_merge( $row, $data ),
				$page,
				array(
					'url'      => $url,
					'status'   => $res['status'],
					'time_ms'  => $res['time_ms'],
					'bytes'    => strlen( $res['body'] ),
					'location' => $loc,
				),
				array(
					'thin_words'     => (int) $this->plugin->settings->get( 'thin_content_words' ),
					'robots_allowed' => $this->plugin->robots->is_allowed( $url ),
				)
			);
			Checks::record( $this->plugin, (int) $row['id'], $found );

			$robots    = $page['robots'] . ',' . $page['x_robots'];
			$indexable = 200 === $res['status'] && false === strpos( $robots, 'noindex' ) && ! isset( $found['canonicalized'] );
			$internal  = array_values( array_filter( $page['links'], static function ( $l ) {
				return $l['internal'];
			} ) );

			$data = array_merge(
				$data,
				array(
					'h2'           => array_slice( $page['h2'], 0, 15 ),
					'h1_all'       => array_slice( $page['h1'], 0, 3 ),
					'schema_types' => $page['schema_types'],
					'og'           => array_intersect_key( $page['og'], array_flip( array( 'title', 'description', 'image', 'type' ) ) ),
					'twitter_card' => isset( $page['twitter']['card'] ) ? $page['twitter']['card'] : '',
					'images'       => array_slice( $page['images'], 0, 40 ),
					'hreflang'     => array_slice( $page['hreflang'], 0, 20 ),
					'simhash'      => $page['simhash'],
					'text_sample'  => mb_substr( $page['text_sample'], 0, 600 ),
					'lang'         => $page['lang'],
					'external_links' => count( $page['links'] ) - count( $internal ),
				)
			);
			$db->update(
				'urls',
				array(
					'status_code'      => (int) $res['status'],
					'redirect_to'      => $loc,
					'content_type'     => substr( $ct, 0, 100 ),
					'indexable'        => $indexable ? 1 : 0,
					'robots'           => substr( trim( $robots, ',' ), 0, 190 ),
					'canonical'        => $page['canonicals'] ? $page['canonicals'][0] : null,
					'title'            => $page['title'],
					'meta_description' => $page['meta_description'],
					'h1'               => $page['h1'] ? $page['h1'][0] : null,
					'word_count'       => (int) $page['word_count'],
					'content_hash'     => $page['content_hash'] ? $page['content_hash'] : null,
					'response_ms'      => (int) $res['time_ms'],
					'page_bytes'       => strlen( $res['body'] ),
					'outlinks'         => count( $internal ),
					'data'             => $data,
					'crawl_status'     => 'done',
					'last_crawled'     => Database::now(),
				),
				array( 'id' => (int) $row['id'] )
			);

			// Discovery + link graph.
			$depth   = max( 0, (int) $row['depth'] ) + 1;
			$targets = array();
			foreach ( $internal as $l ) {
				$href = PageParser::normalize_url( $l['href'] );
				if ( preg_match( self::SKIP_PATTERNS, $href ) ) {
					continue;
				}
				$targets[ Database::hash( $href ) ] = array( 'url' => $href, 'anchor' => $l['anchor'], 'nofollow' => $l['nofollow'] );
			}
			if ( $loc && PageParser::same_site( wp_parse_url( $loc, PHP_URL_HOST ), wp_parse_url( home_url(), PHP_URL_HOST ) ) && ! preg_match( self::SKIP_PATTERNS, $loc ) ) {
				$targets[ Database::hash( PageParser::normalize_url( $loc ) ) ] = array( 'url' => PageParser::normalize_url( $loc ), 'anchor' => '', 'nofollow' => false, 'redirect' => true );
			}
			$count = $this->store_links( (int) $row['id'], $targets, $depth, $count, $max_urls );
		}

		$done = (int) $db->value( "SELECT COUNT(*) FROM {urls} WHERE project_id = %d AND crawl_status IN ('done','blocked')", array( $this->pid() ) );
		$db->update( 'audits', array( 'urls_done' => $done, 'urls_total' => $count ), array( 'id' => (int) $audit_id ) );
		$this->plugin->queue->push( 'crawl_batch', array( 'audit_id' => (int) $audit_id ), array( 'priority' => 6, 'group' => 'crawl' ) );
		return true;
	}

	/** Insert newly discovered URLs (INSERT IGNORE) and replace this page's outgoing edges. */
	private function store_links( $source_id, array $targets, $depth, $count, $max_urls ) {
		$db   = $this->plugin->db;
		$wpdb = $db->wpdb;
		$pid  = $this->pid();
		$db->query( "DELETE FROM {internal_links} WHERE project_id = %d AND source_id = %d AND link_type = 'existing'", array( $pid, $source_id ) );
		if ( ! $targets ) {
			return $count;
		}
		$hashes = array_keys( $targets );
		$known  = array();
		foreach ( array_chunk( $hashes, 200 ) as $chunk ) {
			$in = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			foreach ( $db->rows( "SELECT id, url_hash FROM {urls} WHERE project_id = %d AND url_hash IN ($in)", array_merge( array( $pid ), $chunk ) ) as $r ) {
				$known[ $r['url_hash'] ] = (int) $r['id'];
			}
		}
		foreach ( $targets as $hash => $t ) {
			if ( isset( $known[ $hash ] ) || $count >= $max_urls ) {
				continue;
			}
			$wpdb->query(
				$wpdb->prepare(
					'INSERT IGNORE INTO ' . $db->table( 'urls' ) . " (project_id,url,url_hash,source,depth,crawl_status) VALUES (%d,%s,%s,'crawl',%d,'pending')",
					$pid,
					$t['url'],
					$hash,
					$depth
				)
			);
			if ( $wpdb->insert_id ) {
				$known[ $hash ] = (int) $wpdb->insert_id;
				$count++;
			}
		}
		$values = array();
		$args   = array();
		$now    = Database::now();
		foreach ( $targets as $hash => $t ) {
			if ( ! isset( $known[ $hash ] ) || $known[ $hash ] === $source_id ) {
				continue;
			}
			$values[] = "(%d,%d,%d,%s,'existing',%d,'active',%s)";
			array_push( $args, $pid, $source_id, $known[ $hash ], mb_substr( $t['anchor'], 0, 250 ), $t['nofollow'] ? 1 : 0, $now );
		}
		foreach ( array_chunk( $values, 200, true ) as $i => $chunk ) {
			$slice = array_slice( $args, $i * 200 * 7, count( $chunk ) * 7 );
			$wpdb->query( $wpdb->prepare( 'INSERT INTO ' . $db->table( 'internal_links' ) . ' (project_id,source_id,target_id,anchor,link_type,nofollow,status,created_at) VALUES ' . implode( ',', $chunk ), $slice ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		return $count;
	}

	/** Site-level analysis once every URL has been crawled. */
	public function finalize( $audit_id ) {
		$db  = $this->plugin->db;
		$pid = $this->pid();
		$audit = $db->row( 'SELECT * FROM {audits} WHERE id = %d', array( (int) $audit_id ) );
		if ( ! $audit || 'running' !== $audit['status'] ) {
			return true;
		}
		$this->set_phase( $audit_id, 'analyzing' );

		// 1) Inlinks.
		$db->query(
			"UPDATE {urls} u LEFT JOIN ( SELECT target_id, COUNT(DISTINCT source_id) c FROM {internal_links} WHERE project_id = %d AND link_type = 'existing' GROUP BY target_id ) t ON t.target_id = u.id SET u.inlinks = COALESCE(t.c, 0) WHERE u.project_id = %d",
			array( $pid, $pid )
		);

		// 2) Click depth via BFS from the homepage.
		$home_id = (int) $db->value( 'SELECT id FROM {urls} WHERE project_id = %d AND url_hash = %s', array( $pid, Database::url_hash( home_url( '/' ) ) ) );
		$adj     = array();
		foreach ( $db->rows( "SELECT source_id, target_id FROM {internal_links} WHERE project_id = %d AND link_type = 'existing'", array( $pid ) ) as $e ) {
			$adj[ (int) $e['source_id'] ][] = (int) $e['target_id'];
		}
		$depths = array();
		if ( $home_id ) {
			$depths[ $home_id ] = 0;
			$queue              = array( $home_id );
			while ( $queue ) {
				$n = array_shift( $queue );
				foreach ( isset( $adj[ $n ] ) ? $adj[ $n ] : array() as $m ) {
					if ( ! isset( $depths[ $m ] ) ) {
						$depths[ $m ] = $depths[ $n ] + 1;
						$queue[]      = $m;
					}
				}
			}
		}
		unset( $adj );
		$db->query( 'UPDATE {urls} SET depth = -1 WHERE project_id = %d', array( $pid ) );
		$by_depth = array();
		foreach ( $depths as $id => $d ) {
			$by_depth[ $d ][] = $id;
		}
		foreach ( $by_depth as $d => $ids ) {
			foreach ( array_chunk( $ids, 500 ) as $chunk ) {
				$db->query( 'UPDATE {urls} SET depth = %d WHERE id IN (' . implode( ',', array_map( 'intval', $chunk ) ) . ')', array( (int) $d ) );
			}
		}

		// 3) Site-level findings.
		$site  = array();
		$add   = static function ( $url_id, $code, $ctx ) use ( &$site ) {
			$site[ (int) $url_id ][ $code ] = $ctx;
		};
		$ok    = "project_id = %d AND status_code = 200 AND indexable = 1 AND crawl_status = 'done'";
		foreach ( array( 'title' => 'duplicate_title', 'meta_description' => 'duplicate_meta', 'content_hash' => 'duplicate_content' ) as $col => $code ) {
			$groups = $db->rows( "SELECT {$col} v, GROUP_CONCAT(id ORDER BY inlinks DESC) ids, COUNT(*) c FROM {urls} WHERE {$ok} AND {$col} IS NOT NULL AND {$col} <> '' GROUP BY {$col} HAVING c > 1 LIMIT 500", array( $pid ) );
			foreach ( $groups as $g ) {
				$ids = array_map( 'intval', explode( ',', $g['ids'] ) );
				foreach ( $ids as $id ) {
					$add( $id, $code, array( 'value' => mb_substr( (string) $g['v'], 0, 160 ), 'duplicates' => array_values( array_diff( array_slice( $ids, 0, 10 ), array( $id ) ) ), 'count' => (int) $g['c'] ) );
				}
			}
		}
		$this->near_duplicates( $add );

		foreach ( $db->rows( "SELECT id FROM {urls} WHERE {$ok} AND object_id IS NOT NULL AND object_type IN ('post','term') AND inlinks = 0 AND id <> %d", array( $pid, $home_id ) ) as $r ) {
			$add( $r['id'], 'orphan_page', array() );
		}
		foreach ( $db->rows( "SELECT id, depth FROM {urls} WHERE {$ok} AND depth > 4", array( $pid ) ) as $r ) {
			$add( $r['id'], 'deep_page', array( 'depth' => (int) $r['depth'] ) );
		}
		$broken = $db->rows(
			"SELECT l.source_id, t.url, t.status_code, l.anchor FROM {internal_links} l JOIN {urls} t ON t.id = l.target_id WHERE l.project_id = %d AND l.link_type = 'existing' AND ( t.status_code >= 400 OR ( t.status_code = 0 AND t.crawl_status = 'done' ) ) LIMIT 5000",
			array( $pid )
		);
		$per = array();
		foreach ( $broken as $b ) {
			$per[ $b['source_id'] ][] = array( 'url' => $b['url'], 'status' => (int) $b['status_code'], 'anchor' => $b['anchor'] );
		}
		foreach ( $per as $sid => $links ) {
			$add( $sid, 'broken_internal_link', array( 'count' => count( $links ), 'links' => array_slice( $links, 0, 10 ) ) );
		}
		$redir = $db->rows(
			"SELECT l.source_id, t.url, t.redirect_to FROM {internal_links} l JOIN {urls} t ON t.id = l.target_id WHERE l.project_id = %d AND l.link_type = 'existing' AND t.status_code BETWEEN 300 AND 399 LIMIT 5000",
			array( $pid )
		);
		$per = array();
		foreach ( $redir as $b ) {
			$per[ $b['source_id'] ][] = array( 'url' => $b['url'], 'to' => $b['redirect_to'] );
		}
		foreach ( $per as $sid => $links ) {
			$add( $sid, 'link_to_redirect', array( 'count' => count( $links ), 'links' => array_slice( $links, 0, 10 ) ) );
		}

		// Redirect chains / loops.
		$map = array();
		$ids = array();
		foreach ( $db->rows( 'SELECT id, url, redirect_to FROM {urls} WHERE project_id = %d AND status_code BETWEEN 300 AND 399 AND redirect_to IS NOT NULL', array( $pid ) ) as $r ) {
			$map[ PageParser::normalize_url( $r['url'] ) ] = PageParser::normalize_url( $r['redirect_to'] );
			$ids[ PageParser::normalize_url( $r['url'] ) ] = (int) $r['id'];
		}
		foreach ( $map as $from => $to ) {
			$chain = array( $from );
			$cur   = $to;
			while ( isset( $map[ $cur ] ) && count( $chain ) < 10 && ! in_array( $cur, $chain, true ) ) {
				$chain[] = $cur;
				$cur     = $map[ $cur ];
			}
			if ( in_array( $cur, $chain, true ) ) {
				$add( $ids[ $from ], 'redirect_loop', array( 'chain' => array_merge( $chain, array( $cur ) ) ) );
			} elseif ( count( $chain ) > 1 ) {
				$add( $ids[ $from ], 'redirect_chain', array( 'chain' => array_merge( $chain, array( $cur ) ), 'hops' => count( $chain ) ) );
			}
		}

		// Canonical to non-200.
		$status_by_hash = array();
		foreach ( $db->rows( "SELECT url_hash, status_code FROM {urls} WHERE project_id = %d AND crawl_status = 'done'", array( $pid ) ) as $r ) {
			$status_by_hash[ $r['url_hash'] ] = (int) $r['status_code'];
		}
		foreach ( $db->rows( 'SELECT id, url, url_hash, canonical FROM {urls} WHERE project_id = %d AND canonical IS NOT NULL AND canonical <> \'\'', array( $pid ) ) as $r ) {
			$h = Database::url_hash( $r['canonical'] );
			if ( $h !== $r['url_hash'] && isset( $status_by_hash[ $h ] ) && 200 !== $status_by_hash[ $h ] ) {
				$add( $r['id'], 'canonical_to_error', array( 'canonical' => $r['canonical'], 'status' => $status_by_hash[ $h ] ) );
			}
		}
		// Noindex vs sitemap / traffic.
		$gsc = $this->plugin->gsc_oauth->is_connected();
		foreach ( $db->rows( "SELECT id, url, in_sitemap FROM {urls} WHERE project_id = %d AND status_code = 200 AND robots LIKE %s", array( $pid, '%noindex%' ) ) as $r ) {
			if ( (int) $r['in_sitemap'] ) {
				$add( $r['id'], 'noindex_in_sitemap', array() );
			}
			if ( $gsc ) {
				$m = $this->plugin->gsc_analytics->page_metrics( $r['url'], 28 );
				if ( $m['impressions'] > 0 ) {
					$add( $r['id'], 'noindex_with_traffic', $m );
				}
			}
		}
		$this->broken_images( $add );
		if ( $this->plugin->settings->get( 'crawl_check_external' ) ) {
			$this->broken_external( $add );
		}

		// Persist site-level issues for every crawled URL (resolves those that disappeared).
		$scope = array_merge( Checks::site_level_codes(), array( 'broken_external_link' ) );
		foreach ( $db->column( "SELECT id FROM {urls} WHERE project_id = %d AND crawl_status = 'done'", array( $pid ) ) as $uid ) {
			Checks::record( $this->plugin, (int) $uid, isset( $site[ (int) $uid ] ) ? $site[ (int) $uid ] : array(), $scope );
		}

		// 4) Site-wide technical checks (url_id = 0).
		$global = array();
		$sm     = $this->plugin->sitemap->analyze( true );
		if ( empty( $sm['sitemaps'] ) ) {
			$global['sitemap_missing'] = array();
		} elseif ( ! empty( $sm['problem_urls'] ) ) {
			$global['sitemap_error_urls'] = array( 'count' => count( $sm['problem_urls'] ), 'urls' => array_slice( $sm['problem_urls'], 0, 20 ) );
		}
		$robots = $this->plugin->robots->analyze();
		if ( empty( $robots['sitemaps'] ) ) {
			$global['robots_no_sitemap'] = array();
		}
		Checks::record( $this->plugin, 0, $global, array( 'sitemap_missing', 'sitemap_error_urls', 'robots_no_sitemap' ) );

		// 5) Summary.
		$summary = array(
			'phase'       => 'done',
			'urls'        => $this->url_count(),
			'crawled'     => (int) $db->value( "SELECT COUNT(*) FROM {urls} WHERE project_id = %d AND crawl_status = 'done'", array( $pid ) ),
			'status'      => $db->rows( "SELECT status_code, COUNT(*) c FROM {urls} WHERE project_id = %d AND crawl_status = 'done' GROUP BY status_code", array( $pid ) ),
			'by_severity' => $db->rows( "SELECT severity, COUNT(*) c FROM {issues} WHERE project_id = %d AND status = 'open' GROUP BY severity", array( $pid ) ),
			'by_category' => $db->rows( "SELECT category, COUNT(*) c FROM {issues} WHERE project_id = %d AND status = 'open' GROUP BY category", array( $pid ) ),
			'avg_response_ms' => (int) $db->value( "SELECT AVG(response_ms) FROM {urls} WHERE project_id = %d AND crawl_status = 'done' AND status_code = 200", array( $pid ) ),
		);
		$db->update( 'audits', array( 'status' => 'completed', 'summary' => $summary, 'finished_at' => Database::now(), 'urls_done' => $summary['crawled'] ), array( 'id' => (int) $audit_id ) );
		if ( 'full' === $audit['type'] ) {
			update_option( 'seolity_last_full_crawl', time(), false );
		}
		$this->plugin->audit_log->log( 'audit.completed', 'audit', (int) $audit_id, array( 'urls' => $summary['crawled'] ) );
		$this->plugin->queue->push( 'recommendations_refresh', array(), array( 'unique' => true, 'priority' => 12, 'delay' => 30 ) );
		$this->plugin->queue->push( 'health_snapshot', array(), array( 'unique' => true, 'priority' => 15, 'delay' => 60 ) );
		do_action( 'seolity_audit_completed', (int) $audit_id, $summary );
		return true;
	}

	/** Near duplicates via simhash LSH (4 bands × 16 bits, hamming ≤ 3). */
	private function near_duplicates( callable $add ) {
		$rows    = $this->plugin->db->rows( "SELECT id, url, data, content_hash FROM {urls} WHERE project_id = %d AND status_code = 200 AND indexable = 1 AND crawl_status = 'done' AND word_count > 150 LIMIT 5000", array( $this->pid() ) );
		$hashes  = array();
		$content = array();
		foreach ( $rows as $r ) {
			$d = json_decode( (string) $r['data'], true );
			if ( ! empty( $d['simhash'] ) ) {
				$hashes[ (int) $r['id'] ]  = $d['simhash'];
				$content[ (int) $r['id'] ] = $r['content_hash'];
			}
		}
		$buckets = array();
		foreach ( $hashes as $id => $h ) {
			for ( $b = 0; $b < 4; $b++ ) {
				$buckets[ $b . substr( $h, $b * 16, 16 ) ][] = $id;
			}
		}
		$pairs = array();
		foreach ( $buckets as $ids ) {
			$n = count( $ids );
			if ( $n < 2 || $n > 50 ) {
				continue;
			}
			for ( $i = 0; $i < $n; $i++ ) {
				for ( $j = $i + 1; $j < $n; $j++ ) {
					$a = $ids[ $i ];
					$b = $ids[ $j ];
					if ( isset( $pairs[ "$a-$b" ] ) || $content[ $a ] === $content[ $b ] ) {
						continue; // Exact duplicates are reported separately.
					}
					$dist = Text::hamming( $hashes[ $a ], $hashes[ $b ] );
					if ( $dist <= 3 ) {
						$pairs[ "$a-$b" ] = true;
						$add( $a, 'near_duplicate', array( 'similar_to' => $b, 'distance' => $dist ) );
						$add( $b, 'near_duplicate', array( 'similar_to' => $a, 'distance' => $dist ) );
					}
				}
			}
		}
	}

	private function broken_images( callable $add ) {
		$rows = $this->plugin->db->rows( "SELECT id, data FROM {urls} WHERE project_id = %d AND status_code = 200 AND crawl_status = 'done' ORDER BY inlinks DESC LIMIT 300", array( $this->pid() ) );
		$src  = array();
		foreach ( $rows as $r ) {
			$d = json_decode( (string) $r['data'], true );
			foreach ( isset( $d['images'] ) ? $d['images'] : array() as $img ) {
				if ( preg_match( '#^https?://#', $img['src'] ) && count( $src ) < 150 ) {
					$src[ $img['src'] ][] = (int) $r['id'];
				}
			}
		}
		foreach ( array_chunk( array_keys( $src ), 15 ) as $chunk ) {
			foreach ( Http::multi( $chunk, 8, 'HEAD' ) as $u => $res ) {
				if ( $res['status'] >= 400 || ( 0 === $res['status'] && $res['error'] ) ) {
					foreach ( array_unique( $src[ $u ] ) as $pid ) {
						$add( $pid, 'img_broken', array( 'src' => $u, 'status' => $res['status'] ) );
					}
				}
			}
		}
	}

	private function broken_external( callable $add ) {
		$rows    = $this->plugin->db->rows( "SELECT id, url FROM {urls} WHERE project_id = %d AND status_code = 200 AND crawl_status = 'done' ORDER BY inlinks DESC LIMIT 200", array( $this->pid() ) );
		$targets = array();
		foreach ( $rows as $r ) {
			$res = wp_remote_get( $r['url'], array( 'timeout' => 10, 'user-agent' => Http::user_agent() ) );
			if ( is_wp_error( $res ) ) {
				continue;
			}
			foreach ( PageParser::parse( wp_remote_retrieve_body( $res ), $r['url'] )['links'] as $l ) {
				if ( ! $l['internal'] && count( $targets ) < 300 ) {
					$targets[ $l['href'] ][] = (int) $r['id'];
				}
			}
			if ( count( $targets ) >= 300 ) {
				break;
			}
		}
		foreach ( array_chunk( array_keys( $targets ), 15 ) as $chunk ) {
			foreach ( Http::multi( $chunk, 8, 'HEAD' ) as $u => $res ) {
				if ( in_array( $res['status'], array( 404, 410 ), true ) ) {
					foreach ( array_unique( $targets[ $u ] ) as $pid ) {
						$add( $pid, 'broken_external_link', array( 'url' => $u, 'status' => $res['status'] ) );
					}
				}
			}
		}
	}

	/** Issues list for the Audit screen. */
	public function issues( array $f ) {
		$db    = $this->plugin->db;
		$where = array( 'i.project_id = %d' );
		$args  = array( $this->pid() );
		$where[] = 'i.status = %s';
		$args[]  = ! empty( $f['status'] ) ? $f['status'] : 'open';
		foreach ( array( 'check_code', 'category', 'severity' ) as $k ) {
			if ( ! empty( $f[ $k ] ) ) {
				$where[] = "i.$k = %s";
				$args[]  = $f[ $k ];
			}
		}
		$per   = isset( $f['per_page'] ) ? max( 1, min( 200, (int) $f['per_page'] ) ) : 50;
		$page  = isset( $f['page'] ) ? max( 1, (int) $f['page'] ) : 1;
		$total = (int) $db->value( 'SELECT COUNT(*) FROM {issues} i WHERE ' . implode( ' AND ', $where ), $args );
		$rows  = $db->rows(
			'SELECT i.*, u.url, u.object_type, u.object_id, u.title FROM {issues} i LEFT JOIN {urls} u ON u.id = i.url_id WHERE ' . implode( ' AND ', $where ) . " ORDER BY FIELD(i.severity,'critical','high','medium','low','info'), i.id DESC LIMIT %d OFFSET %d",
			array_merge( $args, array( $per, ( $page - 1 ) * $per ) )
		);
		foreach ( $rows as &$r ) {
			$r         = Database::decode( $r, array( 'context' ) );
			$r['meta'] = Checks::meta( $r['check_code'] );
		}
		return array( 'items' => $rows, 'total' => $total );
	}

	public function urls( array $f ) {
		$db    = $this->plugin->db;
		$where = array( 'project_id = %d', "crawl_status = 'done'" );
		$args  = array( $this->pid() );
		if ( ! empty( $f['search'] ) ) {
			$where[] = 'url LIKE %s';
			$args[]  = '%' . $db->wpdb->esc_like( $f['search'] ) . '%';
		}
		if ( ! empty( $f['status_code'] ) ) {
			$where[] = 'status_code = %d';
			$args[]  = (int) $f['status_code'];
		}
		$per   = isset( $f['per_page'] ) ? max( 1, min( 200, (int) $f['per_page'] ) ) : 50;
		$page  = isset( $f['page'] ) ? max( 1, (int) $f['page'] ) : 1;
		$order = isset( $f['orderby'] ) && in_array( $f['orderby'], array( 'inlinks', 'word_count', 'response_ms', 'depth', 'status_code' ), true ) ? $f['orderby'] : 'inlinks';
		return array(
			'items' => $db->rows( 'SELECT id, url, object_type, object_id, status_code, indexable, title, meta_description, h1, word_count, response_ms, depth, inlinks, outlinks, in_sitemap, last_crawled FROM {urls} WHERE ' . implode( ' AND ', $where ) . " ORDER BY {$order} DESC LIMIT %d OFFSET %d", array_merge( $args, array( $per, ( $page - 1 ) * $per ) ) ),
			'total' => (int) $db->value( 'SELECT COUNT(*) FROM {urls} WHERE ' . implode( ' AND ', $where ), $args ),
		);
	}

	public function set_issue_status( $id, $status ) {
		if ( ! in_array( $status, array( 'open', 'ignored', 'fixed' ), true ) ) {
			return false;
		}
		$this->plugin->audit_log->log( 'issue.' . $status, 'issue', (int) $id );
		return (bool) $this->plugin->db->update( 'issues', array( 'status' => $status ), array( 'id' => (int) $id ) );
	}
}
