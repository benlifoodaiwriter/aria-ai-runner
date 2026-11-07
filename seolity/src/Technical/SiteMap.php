<?php
/**
 * Site map explorer: every URL of the site in one place — from the crawl, the XML sitemap,
 * WordPress and Google Search Console — each with what Google can do with it: indexable,
 * noindex, blocked by robots.txt, redirect, error, canonicalized elsewhere or not crawled yet,
 * plus flags (orphan, not in sitemap, sitemap problem, deep, thin, slow, traffic, Google-only).
 * Rebuilt daily (and on demand) with a daily history of the counts.
 *
 * @package SEOlity
 */

namespace SEOlity\Technical;

use SEOlity\Core\Database;
use SEOlity\Crawler\PageParser;
use SEOlity\Plugin;

defined( 'ABSPATH' ) || exit;

final class SiteMap {

	const CACHE   = 'seolity_sitemap_map';
	const HISTORY = 'seolity_sitemap_map_history';
	const MAX     = 50000;

	const STATUSES = array( 'indexable', 'noindex', 'blocked', 'redirect', 'error', 'canonicalized', 'not_crawled' );
	const FLAGS    = array( 'orphan', 'not_in_sitemap', 'sitemap_problem', 'deep', 'thin', 'slow', 'traffic', 'google_only', 'in_sitemap' );

	/** @var Plugin */
	private $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	private static function norm( $u ) {
		return untrailingslashit( strtolower( rawurldecode( preg_replace( '#^https?://(www\.)?#i', '', (string) $u ) ) ) );
	}

	/**
	 * Daily job: read the XML sitemap and Search Console pages into the URL list (new URLs are
	 * queued for crawling), then rebuild the map and add today's counts to the history.
	 */
	public function refresh() {
		$db   = $this->plugin->db;
		$pid  = $db->project_id();
		$now  = Database::now();
		$sm   = $this->plugin->sitemap->read( self::MAX );
		$seen = array();
		foreach ( $sm['urls'] as $loc => $meta ) {
			$seen[ Database::url_hash( $loc ) ] = $loc;
		}
		// Flags in_sitemap from scratch.
		$db->query( 'UPDATE {urls} SET in_sitemap = 0 WHERE project_id = %d', array( $pid ) );
		foreach ( array_chunk( array_keys( $seen ), 500 ) as $chunk ) {
			$in = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			$db->query( "UPDATE {urls} SET in_sitemap = 1 WHERE project_id = %d AND url_hash IN ($in)", array_merge( array( $pid ), $chunk ) ); // phpcs:ignore WordPress.DB
		}
		$known = array_flip( (array) $db->column( 'SELECT url_hash FROM {urls} WHERE project_id = %d', array( $pid ) ) );
		$added = 0;
		foreach ( $seen as $hash => $loc ) {
			if ( ! isset( $known[ $hash ] ) && $added < 5000 ) {
				$db->insert( 'urls', array( 'project_id' => $pid, 'url' => PageParser::normalize_url( $loc ), 'url_hash' => $hash, 'source' => 'sitemap', 'in_sitemap' => 1, 'crawl_status' => 'pending', 'modified_at' => $now ) );
				$known[ $hash ] = true;
				$added++;
			}
		}
		// Pages Google shows that the site no longer lists anywhere.
		$own = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		foreach ( $db->rows( 'SELECT page_hash, MAX(page) page FROM {gsc_page_query} WHERE project_id = %d AND date >= %s GROUP BY page_hash LIMIT 20000', array( $pid, gmdate( 'Y-m-d', time() - 90 * DAY_IN_SECONDS ) ) ) as $g ) {
			$h = Database::url_hash( $g['page'] );
			if ( ! isset( $known[ $h ] ) && $added < 8000 && false !== stripos( (string) wp_parse_url( $g['page'], PHP_URL_HOST ), preg_replace( '/^www\./', '', $own ) ) ) {
				$db->insert( 'urls', array( 'project_id' => $pid, 'url' => PageParser::normalize_url( $g['page'] ), 'url_hash' => $h, 'source' => 'gsc', 'crawl_status' => 'pending', 'modified_at' => $now ) );
				$known[ $h ] = true;
				$added++;
			}
		}
		if ( $added ) {
			// Fill in the new URLs' status with an incremental crawl.
			$this->plugin->crawler->start( 'incremental' );
		}
		$map = $this->build();
		$h   = (array) get_option( self::HISTORY, array() );
		$h[ gmdate( 'Y-m-d' ) ] = $map['counts'];
		update_option( self::HISTORY, array_slice( $h, -90, null, true ), false );
		update_option( 'seolity_sitemap_map_last', array( 'at' => gmdate( 'c' ), 'added' => $added, 'sitemap_urls' => count( $seen ) ), false );
		return true;
	}

	/** Classify every URL and cache the compact map. */
	public function build() {
		$db    = $this->plugin->db;
		$pid   = $db->project_id();
		$gsc   = array();
		foreach ( $db->rows( 'SELECT page_hash, SUM(clicks) c, SUM(impressions) i, SUM(position*impressions) pw FROM {gsc_page_query} WHERE project_id = %d AND date >= %s GROUP BY page_hash', array( $pid, gmdate( 'Y-m-d', time() - 90 * DAY_IN_SECONDS ) ) ) as $g ) {
			$gsc[ $g['page_hash'] ] = array( (int) $g['c'], (int) $g['i'], (int) $g['i'] ? round( $g['pw'] / $g['i'], 1 ) : null );
		}
		$robots = $this->plugin->robots;
		$home   = self::norm( home_url( '/' ) );
		$rows   = $db->rows( 'SELECT url, url_hash, object_type, object_id, source, status_code, redirect_to, indexable, robots, canonical, title, word_count, response_ms, depth, inlinks, outlinks, in_sitemap, crawl_status, last_crawled FROM {urls} WHERE project_id = %d ORDER BY url LIMIT %d', array( $pid, self::MAX ) );
		$items  = array();
		$counts = array_fill_keys( array_merge( self::STATUSES, self::FLAGS ), 0 );
		foreach ( $rows as $r ) {
			$code    = (int) $r['status_code'];
			$blocked = ! $robots->is_allowed( $r['url'] );
			$noindex = false !== stripos( (string) $r['robots'], 'noindex' );
			$canon   = $r['canonical'] && self::norm( $r['canonical'] ) !== self::norm( $r['url'] );
			if ( $blocked ) {
				$st = 'blocked';
			} elseif ( ! $code || 'pending' === $r['crawl_status'] ) {
				$st = 'not_crawled';
			} elseif ( $code >= 300 && $code < 400 ) {
				$st = 'redirect';
			} elseif ( $code >= 400 ) {
				$st = 'error';
			} elseif ( $noindex ) {
				$st = 'noindex';
			} elseif ( $canon ) {
				$st = 'canonicalized';
			} else {
				$st = 'indexable';
			}
			$g     = isset( $gsc[ $r['url_hash'] ] ) ? $gsc[ $r['url_hash'] ] : null;
			$flags = array();
			if ( 'indexable' === $st && ! (int) $r['inlinks'] && self::norm( $r['url'] ) !== $home && $code ) {
				$flags[] = 'orphan';
			}
			if ( (int) $r['in_sitemap'] ) {
				$flags[] = 'in_sitemap';
				if ( 'indexable' !== $st && 'not_crawled' !== $st ) {
					$flags[] = 'sitemap_problem';
				}
			} elseif ( 'indexable' === $st && in_array( $r['object_type'], array( 'post', 'term', '' ), true ) ) {
				$flags[] = 'not_in_sitemap';
			}
			if ( (int) $r['depth'] > 3 ) {
				$flags[] = 'deep';
			}
			if ( 'indexable' === $st && (int) $r['word_count'] && (int) $r['word_count'] < 300 ) {
				$flags[] = 'thin';
			}
			if ( (int) $r['response_ms'] > 1500 ) {
				$flags[] = 'slow';
			}
			if ( $g && $g[0] > 0 ) {
				$flags[] = 'traffic';
			}
			if ( 'gsc' === $r['source'] ) {
				$flags[] = 'google_only';
			}
			$counts[ $st ]++;
			foreach ( $flags as $f ) {
				$counts[ $f ]++;
			}
			$items[] = array( $r['url'], $st, $flags, $g ? $g[0] : 0, $g ? $g[1] : 0, $g ? $g[2] : null, (int) $r['inlinks'], (int) $r['depth'], (int) $r['word_count'], (int) $r['response_ms'], $code, (string) $r['title'] );
		}
		$out = array( 'at' => gmdate( 'c' ), 'total' => count( $items ), 'counts' => $counts, 'items' => $items );
		set_transient( self::CACHE, $out, DAY_IN_SECONDS );
		return $out;
	}

	private function map() {
		$m = get_transient( self::CACHE );
		return is_array( $m ) ? $m : $this->build();
	}

	/** Counts and history (for the chat and the KPI tiles). */
	public function summary() {
		$m = $this->map();
		return array( 'at' => $m['at'], 'total' => $m['total'], 'counts' => $m['counts'], 'history' => (array) get_option( self::HISTORY, array() ), 'last_refresh' => get_option( 'seolity_sitemap_map_last' ) );
	}

	private static function row( array $i ) {
		return array( 'url' => $i[0], 'status' => $i[1], 'flags' => $i[2], 'clicks' => $i[3], 'impressions' => $i[4], 'position' => $i[5], 'inlinks' => $i[6], 'depth' => $i[7], 'words' => $i[8], 'ms' => $i[9], 'code' => $i[10], 'title' => $i[11] );
	}

	private static function path( $url ) {
		$p = (string) wp_parse_url( $url, PHP_URL_PATH );
		return rawurldecode( '' === $p ? '/' : $p );
	}

	/**
	 * Filtered view: the folder tree one level below $folder (each folder with counts per
	 * status) and a page of URLs.
	 */
	public function view( array $f ) {
		$m      = $this->map();
		$status = isset( $f['status'] ) ? (string) $f['status'] : '';
		$flag   = isset( $f['flag'] ) ? (string) $f['flag'] : '';
		$q      = isset( $f['q'] ) ? mb_strtolower( (string) $f['q'] ) : '';
		$folder = isset( $f['folder'] ) ? '/' . trim( (string) $f['folder'], '/' ) : '/';
		$folder = '/' === $folder ? '/' : $folder . '/';
		$sort   = isset( $f['sort'] ) ? (string) $f['sort'] : 'url';
		$tree   = array();
		$list   = array();
		foreach ( $m['items'] as $i ) {
			if ( ( $status && $i[1] !== $status ) || ( $flag && ! in_array( $flag, $i[2], true ) ) || ( $q && false === mb_strpos( mb_strtolower( rawurldecode( $i[0] ) . ' ' . $i[11] ), $q ) ) ) {
				continue;
			}
			$path = self::path( $i[0] );
			if ( 0 !== strpos( $path, $folder ) && rtrim( $path, '/' ) . '/' !== $folder ) {
				continue;
			}
			$rest = trim( substr( $path, strlen( $folder ) ), '/' );
			$seg  = '' === $rest ? '' : explode( '/', $rest )[0];
			$leaf = '' === $rest || false === strpos( $rest, '/' );
			$key  = $leaf ? '' : $seg;
			if ( '' !== $key ) {
				if ( ! isset( $tree[ $key ] ) ) {
					$tree[ $key ] = array( 'folder' => $folder . $key . '/', 'name' => $key, 'total' => 0, 'clicks' => 0, 'counts' => array_fill_keys( self::STATUSES, 0 ) );
				}
				$tree[ $key ]['total']++;
				$tree[ $key ]['clicks'] += $i[3];
				$tree[ $key ]['counts'][ $i[1] ]++;
			}
			if ( $leaf || ! empty( $f['all'] ) ) {
				$list[] = $i;
			}
		}
		$sorters = array(
			'clicks'      => 3,
			'impressions' => 4,
			'inlinks'     => 6,
			'depth'       => 7,
			'words'       => 8,
			'ms'          => 9,
		);
		if ( isset( $sorters[ $sort ] ) ) {
			$idx = $sorters[ $sort ];
			usort(
				$list,
				static function ( $a, $b ) use ( $idx ) {
					return $b[ $idx ] <=> $a[ $idx ];
				}
			);
		}
		uasort(
			$tree,
			static function ( $a, $b ) {
				return $b['total'] <=> $a['total'];
			}
		);
		$per  = isset( $f['per_page'] ) ? max( 20, min( 5000, (int) $f['per_page'] ) ) : 200;
		$page = isset( $f['page'] ) ? max( 1, (int) $f['page'] ) : 1;
		return array(
			'at'      => $m['at'],
			'total'   => $m['total'],
			'counts'  => $m['counts'],
			'folder'  => $folder,
			'folders' => array_values( $tree ),
			'urls'    => array_map( array( self::class, 'row' ), array_slice( $list, ( $page - 1 ) * $per, $per ) ),
			'found'   => count( $list ),
			'page'    => $page,
			'per_page' => $per,
		);
	}

	/** Every URL with only status (for the pixel map of the whole site). */
	public function pixels() {
		$m = $this->map();
		return array(
			'at'    => $m['at'],
			'items' => array_map(
				static function ( $i ) {
					return array( $i[0], $i[1], $i[3] );
				},
				$m['items']
			),
		);
	}

	/** One URL in full: map entry, crawl row, open issues, Search Console, actions. */
	public function url( $url ) {
		$db  = $this->plugin->db;
		$pid = $db->project_id();
		$row = $db->row( 'SELECT * FROM {urls} WHERE project_id = %d AND url_hash = %s', array( $pid, Database::url_hash( $url ) ) );
		$item = null;
		foreach ( $this->map()['items'] as $i ) {
			if ( $i[0] === $url ) {
				$item = self::row( $i );
				break;
			}
		}
		$issues = $row ? $db->rows( "SELECT check_code, severity FROM {issues} WHERE url_id = %d AND status = 'open'", array( (int) $row['id'] ) ) : array();
		$cat    = \SEOlity\Crawler\Checks::catalog();
		foreach ( $issues as &$is ) {
			$is['title'] = isset( $cat[ $is['check_code'] ] ) ? $cat[ $is['check_code'] ][3] : $is['check_code'];
			$is['fix']   = isset( $cat[ $is['check_code'] ] ) ? $cat[ $is['check_code'] ][5] : '';
		}
		unset( $is );
		$gsc  = $this->plugin->gsc_insights->page_report( $url );
		$prop = (string) $this->plugin->settings->get( 'gsc_property' );
		return array(
			'map'     => $item,
			'crawl'   => $row ? array_intersect_key( $row, array_flip( array( 'status_code', 'redirect_to', 'indexable', 'robots', 'canonical', 'title', 'meta_description', 'word_count', 'response_ms', 'page_bytes', 'depth', 'inlinks', 'outlinks', 'in_sitemap', 'source', 'last_crawled', 'object_type', 'object_id' ) ) ) : null,
			'robots_txt_allowed' => $this->plugin->robots->is_allowed( $url ),
			'issues'  => $issues,
			'queries' => $gsc ? array_slice( $gsc['queries'], 0, 10 ) : array(),
			'weekly'  => $gsc ? array_slice( $gsc['weekly'], -12 ) : array(),
			'links'   => array(
				'live'    => $url,
				'edit'    => $row && 'post' === $row['object_type'] && $row['object_id'] ? admin_url( 'post.php?post=' . (int) $row['object_id'] . '&action=edit' ) : '',
				'inspect' => 'https://search.google.com/search-console/inspect?resource_id=' . rawurlencode( $prop ? $prop : home_url( '/' ) ) . '&id=' . rawurlencode( $url ),
				'rich'    => 'https://search.google.com/test/rich-results?url=' . rawurlencode( $url ),
				'speed'   => 'https://pagespeed.web.dev/analysis?url=' . rawurlencode( $url ),
			),
		);
	}

	/** Re-check one URL live now (status, robots, canonical) and update its entry. */
	public function check( $url ) {
		$res = $this->plugin->operations->verifier()->fetch( $url, false );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$p    = $res['page'];
		$data = array(
			'status_code'  => (int) $res['status'],
			'redirect_to'  => $res['location'] ? $res['location'] : null,
			'robots'       => $p ? trim( $p['robots'] . ',' . $p['x_robots'], ',' ) : '',
			'canonical'    => $p && ! empty( $p['canonicals'] ) ? $p['canonicals'][0] : null,
			'indexable'    => 200 === (int) $res['status'] && ! ( $p && false !== strpos( $p['robots'] . $p['x_robots'], 'noindex' ) ) ? 1 : 0,
			'crawl_status' => 'done',
			'last_crawled' => Database::now(),
		);
		if ( $p ) {
			$data['title']      = $p['title'];
			$data['word_count'] = (int) $p['word_count'];
		}
		$this->plugin->db->update( 'urls', $data, array( 'project_id' => $this->plugin->db->project_id(), 'url_hash' => Database::url_hash( $url ) ) );
		$this->build();
		return $this->url( $url );
	}
}
