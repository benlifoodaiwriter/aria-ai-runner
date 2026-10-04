<?php
/**
 * Competitor Intelligence + Content Gap (sections 10–11).
 *
 * Data source: the competitor's public sitemap + their public HTML (polite,
 * rate-limited crawling with SEOlity's UA, robots.txt respected). Tracks
 * pages, new content, titles, metas, headings, length, schema, FAQ use and
 * internal linking; AI turns it into strategy summaries and a content-gap
 * map (keyword → intent → suggested page). Backlink/SERP data can be plugged
 * in via the `seolity_competitor_external_data` filter (e.g. a SERP API).
 *
 * @package SEOlity
 */

namespace SEOlity\Competitors;

use SEOlity\Analysis\Text;
use SEOlity\Core\Database;
use SEOlity\Core\Http;
use SEOlity\Crawler\PageParser;
use SEOlity\Plugin;

defined( 'ABSPATH' ) || exit;

final class CompetitorService {

	const BATCH = 6;

	/** @var Plugin */
	private $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	private function pid() {
		return $this->plugin->db->project_id();
	}

	public function create( $name, $domain, $frequency = 'weekly', $sitemap = '' ) {
		$domain = strtolower( preg_replace( '#^https?://#i', '', trim( (string) $domain ) ) );
		$domain = preg_replace( '#/.*$#', '', $domain );
		if ( ! preg_match( '/^[a-z0-9.-]+\.[a-z]{2,}$/i', $domain ) && ! preg_match( '/^[\p{L}0-9.-]+\.[\p{L}]{2,}$/u', $domain ) ) {
			return new \WP_Error( 'seolity_comp', __( 'Enter a valid domain, e.g. example.com', 'seolity' ), array( 'status' => 400 ) );
		}
		if ( ! Http::is_safe_public_url( 'https://' . $domain ) ) {
			return new \WP_Error( 'seolity_comp', __( 'This domain does not resolve to a public address.', 'seolity' ), array( 'status' => 400 ) );
		}
		$id = $this->plugin->db->insert(
			'competitors',
			array(
				'project_id'  => $this->pid(),
				'name'        => sanitize_text_field( $name ? $name : $domain ),
				'domain'      => $domain,
				'sitemap_url' => $sitemap ? esc_url_raw( $sitemap ) : null,
				'frequency'   => in_array( $frequency, array( 'daily', 'weekly', 'monthly' ), true ) ? $frequency : 'weekly',
				'status'      => 'active',
				'created_at'  => Database::now(),
			)
		);
		$this->plugin->queue->push( 'competitor_analyze', array( 'id' => $id ), array( 'unique' => true, 'priority' => 20 ) );
		\SEOlity\Queue\Scheduler::kick();
		$this->plugin->audit_log->log( 'competitor.created', 'competitor', $id, array( 'domain' => $domain ) );
		return $id;
	}

	public function delete( $id ) {
		$this->plugin->db->delete( 'competitor_pages', array( 'competitor_id' => (int) $id ) );
		return (bool) $this->plugin->db->delete( 'competitors', array( 'id' => (int) $id, 'project_id' => $this->pid() ) );
	}

	public function due_ids() {
		$out = array();
		$map = array( 'daily' => DAY_IN_SECONDS, 'weekly' => WEEK_IN_SECONDS, 'monthly' => 30 * DAY_IN_SECONDS );
		foreach ( $this->plugin->db->rows( "SELECT id, frequency, last_analyzed FROM {competitors} WHERE project_id = %d AND status = 'active'", array( $this->pid() ) ) as $c ) {
			$last = $c['last_analyzed'] ? strtotime( $c['last_analyzed'] . ' UTC' ) : 0;
			if ( time() - $last >= $map[ $c['frequency'] ] - HOUR_IN_SECONDS ) {
				$out[] = (int) $c['id'];
			}
		}
		return $out;
	}

	private function get( $id ) {
		return Database::decode( $this->plugin->db->row( 'SELECT * FROM {competitors} WHERE id = %d', array( (int) $id ) ), array( 'summary' ) );
	}

	/** Step 1: discover URLs from the competitor's sitemap(s). */
	public function analyze( $id ) {
		$c = $this->get( $id );
		if ( ! $c ) {
			return true;
		}
		$base    = 'https://' . $c['domain'];
		$robots  = wp_remote_get( $base . '/robots.txt', array( 'timeout' => 10, 'user-agent' => Http::user_agent() ) );
		$rtxt    = is_wp_error( $robots ) ? '' : (string) wp_remote_retrieve_body( $robots );
		$parsed  = $this->plugin->robots->parse( $rtxt );
		$maps    = $c['sitemap_url'] ? array( $c['sitemap_url'] ) : ( $parsed['sitemaps'] ? $parsed['sitemaps'] : array( $base . '/sitemap_index.xml', $base . '/sitemap.xml', $base . '/wp-sitemap.xml' ) );
		$urls    = $this->read_sitemaps( $maps, (int) $this->plugin->settings->get( 'competitor_max_pages' ) * 3 );
		$max     = (int) $this->plugin->settings->get( 'competitor_max_pages' );
		$db      = $this->plugin->db;
		$now     = Database::now();
		$known   = (int) $db->value( 'SELECT COUNT(*) FROM {competitor_pages} WHERE competitor_id = %d', array( (int) $id ) );
		$new     = 0;
		uasort( $urls, static function ( $a, $b ) {
			return strcmp( (string) $b, (string) $a ); // Newest lastmod first.
		} );
		foreach ( array_slice( $urls, 0, $max * 2, true ) as $url => $lastmod ) {
			if ( ! $this->plugin->robots->is_allowed( $url, 'seolitybot', $parsed ) ) {
				continue;
			}
			$hash   = Database::url_hash( $url );
			$exists = $db->row( 'SELECT id, lastmod FROM {competitor_pages} WHERE competitor_id = %d AND url_hash = %s', array( (int) $id, $hash ) );
			if ( $exists ) {
				$changed = $lastmod && $exists['lastmod'] && strtotime( $lastmod ) > strtotime( $exists['lastmod'] . ' UTC' );
				$update  = array( 'last_seen' => $now, 'lastmod' => $lastmod ? gmdate( 'Y-m-d H:i:s', strtotime( $lastmod ) ) : null );
				if ( $changed ) {
					$update['crawled_at'] = null; // Content updated → re-crawl.
				}
				$db->update( 'competitor_pages', $update, array( 'id' => (int) $exists['id'] ) );
				continue;
			}
			$db->insert(
				'competitor_pages',
				array(
					'competitor_id' => (int) $id,
					'url'           => $url,
					'url_hash'      => $hash,
					'lastmod'       => $lastmod ? gmdate( 'Y-m-d H:i:s', strtotime( $lastmod ) ) : null,
					'first_seen'    => $now,
					'last_seen'     => $now,
				)
			);
			$new++;
		}
		$summary              = is_array( $c['summary'] ) ? $c['summary'] : array();
		$summary['new_pages'] = $known ? $new : 0;
		$summary['sitemaps']  = $maps;
		$db->update( 'competitors', array( 'summary' => $summary ), array( 'id' => (int) $id ) );
		$this->plugin->queue->push( 'competitor_crawl_batch', array( 'id' => (int) $id ), array( 'priority' => 25 ) );
		return true;
	}

	private function read_sitemaps( array $queue, $max ) {
		$urls = array();
		$seen = array();
		while ( $queue && count( $seen ) < 30 && count( $urls ) < $max ) {
			$sm = array_shift( $queue );
			if ( isset( $seen[ $sm ] ) || ! Http::is_safe_public_url( $sm ) ) {
				continue;
			}
			$seen[ $sm ] = 1;
			$res         = wp_remote_get( $sm, array( 'timeout' => 20, 'user-agent' => Http::user_agent() ) );
			if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
				continue;
			}
			$prev = libxml_use_internal_errors( true );
			$xml  = simplexml_load_string( wp_remote_retrieve_body( $res ), 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA );
			libxml_use_internal_errors( $prev );
			if ( ! $xml ) {
				continue;
			}
			if ( 'sitemapindex' === $xml->getName() ) {
				foreach ( $xml->sitemap as $s ) {
					// Skip image/attachment/tag sitemaps — not useful for content gaps.
					$loc = trim( (string) $s->loc );
					if ( ! preg_match( '/(attachment|image|tag|author|video)/i', $loc ) ) {
						$queue[] = $loc;
					}
				}
			} else {
				foreach ( $xml->url as $u ) {
					$urls[ trim( (string) $u->loc ) ] = trim( (string) $u->lastmod );
				}
			}
		}
		return $urls;
	}

	/** Step 2: crawl uncrawled pages politely in batches. */
	public function crawl_batch( $id ) {
		$db   = $this->plugin->db;
		$rows = $db->rows( 'SELECT id, url FROM {competitor_pages} WHERE competitor_id = %d AND crawled_at IS NULL ORDER BY lastmod DESC, id DESC LIMIT %d', array( (int) $id, self::BATCH ) );
		$done = (int) $db->value( 'SELECT COUNT(*) FROM {competitor_pages} WHERE competitor_id = %d AND crawled_at IS NOT NULL', array( (int) $id ) );
		$max  = (int) $this->plugin->settings->get( 'competitor_max_pages' );
		if ( ! $rows || $done >= $max ) {
			$this->plugin->queue->push( 'competitor_summarize', array( 'id' => (int) $id ), array( 'unique' => true, 'priority' => 26 ) );
			return true;
		}
		$urls = wp_list_pluck( $rows, 'url', 'id' );
		foreach ( Http::multi( array_values( $urls ), 15 ) as $url => $res ) {
			$page_id = (int) array_search( $url, $urls, true );
			$page    = 200 === $res['status'] ? PageParser::parse( $res['body'], $url, $res['headers'] ) : null;
			$faq     = $page ? count( array_filter( array_merge( $page['h2'], $page['h3'] ), static function ( $h ) {
				return (bool) preg_match( '/[?؟]\s*$/u', $h );
			} ) ) : 0;
			$db->update(
				'competitor_pages',
				array(
					'status_code'      => (int) $res['status'],
					'title'            => $page ? $page['title'] : null,
					'meta_description' => $page ? $page['meta_description'] : null,
					'h1'               => $page && $page['h1'] ? $page['h1'][0] : null,
					'headings'         => $page ? array( 'h2' => array_slice( $page['h2'], 0, 25 ), 'h3' => array_slice( $page['h3'], 0, 25 ) ) : null,
					'word_count'       => $page ? (int) $page['word_count'] : 0,
					'schema_types'     => $page ? substr( implode( ',', $page['schema_types'] ), 0, 250 ) : null,
					'internal_links'   => $page ? count( array_filter( $page['links'], static function ( $l ) {
						return $l['internal'];
					} ) ) : 0,
					'faq_count'        => $faq,
					'topics'           => $page ? array_slice( array_keys( Text::top_ngrams( $page['title'] . ' ' . implode( ' ', $page['h1'] ) . ' ' . implode( ' ', $page['h2'] ), 8 ) ), 0, 8 ) : null,
					'content_hash'     => $page ? $page['content_hash'] : null,
					'crawled_at'       => Database::now(),
				),
				array( 'id' => $page_id )
			);
		}
		$this->plugin->queue->push( 'competitor_crawl_batch', array( 'id' => (int) $id ), array( 'priority' => 25, 'delay' => 5 ) );
		return true;
	}

	/** Step 3: stats + AI strategy summary + content gap. */
	public function summarize( $id ) {
		$c = $this->get( $id );
		if ( ! $c ) {
			return true;
		}
		$stats   = $this->stats( $id );
		$summary = array_merge( is_array( $c['summary'] ) ? $c['summary'] : array(), array( 'pages' => $stats['pages'], 'stats' => $stats ) );
		if ( $this->plugin->ai->is_ready() ) {
			$mine = $this->my_stats();
			$res  = $this->plugin->ai->run(
				'competitor_summary',
				array( 'competitor' => $c['name'], 'domain' => $c['domain'], 'competitor_stats' => $stats, 'my_site_stats' => $mine, 'new_pages_since_last' => $summary['new_pages'], 'external_data' => apply_filters( 'seolity_competitor_external_data', null, $c ) )
			);
			if ( ! is_wp_error( $res ) ) {
				$summary['ai'] = $res['data'];
			}
			$gap = $this->gap( $id, $stats, $mine );
			if ( ! is_wp_error( $gap ) ) {
				$summary['gap'] = $gap;
			}
		}
		$this->plugin->db->update( 'competitors', array( 'summary' => $summary, 'last_analyzed' => Database::now() ), array( 'id' => (int) $id ) );
		$this->plugin->queue->push( 'recommendations_refresh', array(), array( 'unique' => true, 'priority' => 12, 'delay' => 60 ) );
		return true;
	}

	private function section( $url ) {
		$path = trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
		$segs = explode( '/', urldecode( $path ) );
		return count( $segs ) > 1 ? $segs[0] : '(root)';
	}

	public function stats( $id ) {
		$db    = $this->plugin->db;
		$rows  = $db->rows( 'SELECT url, title, h1, word_count, schema_types, internal_links, faq_count, topics, first_seen FROM {competitor_pages} WHERE competitor_id = %d AND status_code = 200', array( (int) $id ) );
		$sect  = array();
		$topic = array();
		$schema = array();
		foreach ( $rows as $r ) {
			$s          = $this->section( $r['url'] );
			$sect[ $s ] = isset( $sect[ $s ] ) ? $sect[ $s ] + 1 : 1;
			foreach ( (array) json_decode( (string) $r['topics'], true ) as $t ) {
				$topic[ $t ] = isset( $topic[ $t ] ) ? $topic[ $t ] + 1 : 1;
			}
			foreach ( array_filter( explode( ',', (string) $r['schema_types'] ) ) as $t ) {
				$schema[ $t ] = isset( $schema[ $t ] ) ? $schema[ $t ] + 1 : 1;
			}
		}
		arsort( $sect );
		arsort( $topic );
		arsort( $schema );
		$words = wp_list_pluck( $rows, 'word_count' );
		return array(
			'pages'          => count( $rows ),
			'sections'       => array_slice( $sect, 0, 15, true ),
			'top_topics'     => array_slice( $topic, 0, 40, true ),
			'schema'         => $schema,
			'avg_words'      => $words ? (int) round( array_sum( $words ) / count( $words ) ) : 0,
			'avg_internal_links' => $rows ? (int) round( array_sum( wp_list_pluck( $rows, 'internal_links' ) ) / count( $rows ) ) : 0,
			'pages_with_faq' => count( array_filter( wp_list_pluck( $rows, 'faq_count' ) ) ),
			'recent_titles'  => array_slice( wp_list_pluck( $db->rows( 'SELECT title FROM {competitor_pages} WHERE competitor_id = %d AND status_code = 200 ORDER BY first_seen DESC, lastmod DESC LIMIT 25', array( (int) $id ) ), 'title' ), 0, 25 ),
			'sample_titles'  => array_slice( wp_list_pluck( $rows, 'title' ), 0, 80 ),
		);
	}

	private function my_stats() {
		$db    = $this->plugin->db;
		$rows  = $db->rows( "SELECT url, title, word_count FROM {urls} WHERE project_id = %d AND status_code = 200 AND indexable = 1 AND crawl_status = 'done' LIMIT 3000", array( $this->pid() ) );
		$sect  = array();
		foreach ( $rows as $r ) {
			$s          = $this->section( $r['url'] );
			$sect[ $s ] = isset( $sect[ $s ] ) ? $sect[ $s ] + 1 : 1;
		}
		arsort( $sect );
		$words = wp_list_pluck( $rows, 'word_count' );
		return array(
			'pages'       => count( $rows ),
			'sections'    => array_slice( $sect, 0, 15, true ),
			'avg_words'   => $words ? (int) round( array_sum( $words ) / count( $words ) ) : 0,
			'titles'      => array_slice( wp_list_pluck( $rows, 'title' ), 0, 150 ),
			'top_queries' => $this->plugin->gsc_analytics->has_data() ? wp_list_pluck( $this->plugin->gsc_analytics->top_queries( 90, 80 ), 'query' ) : array(),
		);
	}

	/** Competitor → keyword/topic → URL → intent gap analysis. */
	public function gap( $id, array $stats = null, array $mine = null ) {
		$c = $this->get( $id );
		if ( ! $c ) {
			return new \WP_Error( 'seolity_comp', __( 'Competitor not found.', 'seolity' ), array( 'status' => 404 ) );
		}
		$stats = $stats ? $stats : $this->stats( $id );
		$mine  = $mine ? $mine : $this->my_stats();
		$pages = $this->plugin->db->rows( 'SELECT url, title, h1, word_count, headings FROM {competitor_pages} WHERE competitor_id = %d AND status_code = 200 ORDER BY word_count DESC LIMIT 60', array( (int) $id ) );
		foreach ( $pages as &$p ) {
			$h          = json_decode( (string) $p['headings'], true );
			$p['h2']    = isset( $h['h2'] ) ? array_slice( $h['h2'], 0, 8 ) : array();
			unset( $p['headings'] );
		}
		$res = $this->plugin->ai->run(
			'content_gap',
			array( 'competitor' => $c['name'], 'competitor_pages' => $pages, 'competitor_topics' => $stats['top_topics'], 'my_titles' => $mine['titles'], 'my_queries' => $mine['top_queries'] ),
			array( 'knowledge_query' => 'content gap competitor analysis' )
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return array_merge( $res['data'], array( 'generated_at' => gmdate( 'c' ) ) );
	}

	public function query() {
		return array_map(
			function ( $c ) {
				$c          = Database::decode( $c, array( 'summary' ) );
				$c['crawled'] = (int) $this->plugin->db->value( 'SELECT COUNT(*) FROM {competitor_pages} WHERE competitor_id = %d AND crawled_at IS NOT NULL', array( (int) $c['id'] ) );
				$c['known']   = (int) $this->plugin->db->value( 'SELECT COUNT(*) FROM {competitor_pages} WHERE competitor_id = %d', array( (int) $c['id'] ) );
				return $c;
			},
			$this->plugin->db->rows( 'SELECT * FROM {competitors} WHERE project_id = %d ORDER BY id DESC', array( $this->pid() ) )
		);
	}

	public function pages( $id, $search = '' ) {
		$db   = $this->plugin->db;
		$sql  = 'SELECT url, title, meta_description, h1, word_count, schema_types, faq_count, first_seen, lastmod FROM {competitor_pages} WHERE competitor_id = %d AND status_code = 200';
		$args = array( (int) $id );
		if ( $search ) {
			$sql   .= ' AND (title LIKE %s OR url LIKE %s)';
			$like   = '%' . $db->wpdb->esc_like( $search ) . '%';
			$args[] = $like;
			$args[] = $like;
		}
		return $db->rows( $sql . ' ORDER BY first_seen DESC LIMIT 200', $args );
	}
}
