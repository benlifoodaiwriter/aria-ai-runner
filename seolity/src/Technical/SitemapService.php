<?php
/**
 * Sitemap intelligence (section 23).
 *
 * - Enhances WordPress core sitemaps (wp-sitemap.xml) when SEOlity owns SEO:
 *   excludes noindex/canonicalized content, adds lastmod, drops the users
 *   provider for single-author sites, honours excluded post types.
 * - Discovers & parses any sitemap (index or urlset) and cross-checks every
 *   listed URL against crawl data (404, redirects, noindex, canonicalized),
 *   plus important pages missing from the sitemap.
 *
 * @package SEOlity
 */

namespace SEOlity\Technical;

use SEOlity\Core\Database;
use SEOlity\Core\Http;
use SEOlity\Crawler\PageParser;
use SEOlity\Plugin;

defined( 'ABSPATH' ) || exit;

final class SitemapService {

	/** @var Plugin */
	private $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	public function enhances_core() {
		$mode = $this->plugin->settings->get( 'sitemap_enabled' );
		return 'on' === $mode || ( 'auto' === $mode && 'seolity' === $this->plugin->meta->engine() );
	}

	/** Frontend hooks (registered only when enhancing core sitemaps). */
	public function register_core_filters() {
		add_filter(
			'wp_sitemaps_post_types',
			function ( $types ) {
				foreach ( (array) $this->plugin->settings->get( 'sitemap_exclude_types' ) as $t ) {
					unset( $types[ $t ] );
				}
				return $types;
			}
		);
		add_filter(
			'wp_sitemaps_posts_query_args',
			static function ( $args ) {
				$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery
					'relation' => 'OR',
					array( 'key' => '_seolity_noindex', 'compare' => 'NOT EXISTS' ),
					array( 'key' => '_seolity_noindex', 'value' => '1', 'compare' => '!=' ),
				);
				return $args;
			}
		);
		add_filter(
			'wp_sitemaps_posts_entry',
			static function ( $entry, $post ) {
				$canonical = get_post_meta( $post->ID, '_seolity_canonical', true );
				if ( $canonical && untrailingslashit( $canonical ) !== untrailingslashit( get_permalink( $post ) ) ) {
					$entry['loc'] = $canonical; // Point at the canonical instead of a non-canonical URL.
				}
				$entry['lastmod'] = get_post_modified_time( 'c', true, $post );
				return $entry;
			},
			10,
			2
		);
		add_filter(
			'wp_sitemaps_taxonomies_query_args',
			static function ( $args ) {
				$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery
					'relation' => 'OR',
					array( 'key' => '_seolity_noindex', 'compare' => 'NOT EXISTS' ),
					array( 'key' => '_seolity_noindex', 'value' => '1', 'compare' => '!=' ),
				);
				return $args;
			}
		);
		add_filter(
			'wp_sitemaps_add_provider',
			static function ( $provider, $name ) {
				if ( 'users' === $name && count( get_users( array( 'has_published_posts' => true, 'fields' => 'ID', 'number' => 2 ) ) ) < 2 ) {
					return false; // Single-author author archives duplicate the blog.
				}
				return $provider;
			},
			10,
			2
		);
	}

	/** Candidate sitemap index locations (robots.txt first). */
	public function locate() {
		$found = $this->plugin->robots->parse()['sitemaps'];
		if ( ! $found ) {
			foreach ( array( '/sitemap_index.xml', '/wp-sitemap.xml', '/sitemap.xml' ) as $path ) {
				$res = wp_remote_head( home_url( $path ), array( 'timeout' => 8, 'redirection' => 2 ) );
				if ( ! is_wp_error( $res ) && 200 === (int) wp_remote_retrieve_response_code( $res ) ) {
					$found[] = home_url( $path );
					break;
				}
			}
		}
		return array_values( array_unique( $found ) );
	}

	/**
	 * Recursively read sitemaps.
	 *
	 * @return array{sitemaps:array, urls:array<string,array{lastmod:?string, sitemap:string}>}
	 */
	public function read( $max_urls = 20000 ) {
		$queue    = $this->locate();
		$seen     = array();
		$sitemaps = array();
		$urls     = array();
		while ( $queue && count( $seen ) < 60 && count( $urls ) < $max_urls ) {
			$sm = array_shift( $queue );
			if ( isset( $seen[ $sm ] ) ) {
				continue;
			}
			$seen[ $sm ] = true;
			$res         = wp_remote_get( $sm, array( 'timeout' => 20, 'user-agent' => Http::user_agent() ) );
			$entry       = array( 'url' => $sm, 'status' => 0, 'type' => '', 'count' => 0, 'with_lastmod' => 0, 'error' => '' );
			if ( is_wp_error( $res ) ) {
				$entry['error'] = $res->get_error_message();
				$sitemaps[]     = $entry;
				continue;
			}
			$entry['status'] = (int) wp_remote_retrieve_response_code( $res );
			$body            = wp_remote_retrieve_body( $res );
			if ( 0 === strpos( $body, "\x1f\x8b" ) && function_exists( 'gzdecode' ) ) {
				$body = (string) gzdecode( $body );
			}
			$prev = libxml_use_internal_errors( true );
			$xml  = simplexml_load_string( $body, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA );
			libxml_use_internal_errors( $prev );
			if ( ! $xml ) {
				$entry['error'] = __( 'Invalid XML', 'seolity' );
				$sitemaps[]     = $entry;
				continue;
			}
			$root = $xml->getName();
			if ( 'sitemapindex' === $root ) {
				$entry['type'] = 'index';
				foreach ( $xml->sitemap as $child ) {
					$queue[] = trim( (string) $child->loc );
					$entry['count']++;
				}
			} else {
				$entry['type'] = 'urlset';
				foreach ( $xml->url as $u ) {
					$loc = trim( (string) $u->loc );
					if ( '' === $loc ) {
						continue;
					}
					$lastmod = trim( (string) $u->lastmod );
					$urls[ $loc ] = array( 'lastmod' => $lastmod ? $lastmod : null, 'sitemap' => $sm );
					$entry['count']++;
					if ( $lastmod ) {
						$entry['with_lastmod']++;
					}
				}
			}
			$sitemaps[] = $entry;
		}
		return array( 'sitemaps' => $sitemaps, 'urls' => $urls );
	}

	public function collect_urls( $max = 5000 ) {
		return array_keys( $this->read( $max )['urls'] );
	}

	/** Cross-check sitemap vs crawl data (cached 6h). */
	public function analyze( $fresh = false ) {
		$cached = get_transient( 'seolity_sitemap_analysis' );
		if ( false !== $cached && ! $fresh ) {
			return $cached;
		}
		$data    = $this->read();
		$db      = $this->plugin->db;
		$pid     = $db->project_id();
		$problem = array();
		$hashes  = array();
		foreach ( array_keys( $data['urls'] ) as $u ) {
			$hashes[ Database::url_hash( $u ) ] = $u;
		}
		foreach ( array_chunk( array_keys( $hashes ), 300 ) as $chunk ) {
			$in = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			foreach ( $db->rows( "SELECT url_hash, status_code, indexable, robots, canonical, url FROM {urls} WHERE project_id = %d AND crawl_status = 'done' AND url_hash IN ($in)", array_merge( array( $pid ), $chunk ) ) as $r ) {
				$reason = null;
				if ( $r['status_code'] >= 400 ) {
					$reason = 'error_' . $r['status_code'];
				} elseif ( $r['status_code'] >= 300 ) {
					$reason = 'redirect';
				} elseif ( false !== strpos( (string) $r['robots'], 'noindex' ) ) {
					$reason = 'noindex';
				} elseif ( ! (int) $r['indexable'] ) {
					$reason = 'canonicalized';
				}
				if ( $reason ) {
					$problem[] = array( 'url' => $hashes[ $r['url_hash'] ], 'reason' => $reason, 'status' => (int) $r['status_code'] );
				}
			}
		}
		$missing = $db->rows(
			"SELECT url, inlinks FROM {urls} WHERE project_id = %d AND crawl_status = 'done' AND status_code = 200 AND indexable = 1 AND in_sitemap = 0 AND object_id IS NOT NULL ORDER BY inlinks DESC LIMIT 100",
			array( $pid )
		);
		$stale = 0;
		foreach ( $data['urls'] as $meta ) {
			if ( $meta['lastmod'] && strtotime( $meta['lastmod'] ) < strtotime( '-2 years' ) ) {
				$stale++;
			}
		}
		$out = array(
			'sitemaps'       => $data['sitemaps'],
			'total_urls'     => count( $data['urls'] ),
			'problem_urls'   => $problem,
			'missing_urls'   => $missing,
			'stale_lastmod'  => $stale,
			'enhances_core'  => $this->enhances_core(),
			'engine'         => $this->plugin->meta->engine(),
			'recommendations' => $this->recommendations( $data, $problem, $missing ),
			'checked_at'     => gmdate( 'c' ),
		);
		set_transient( 'seolity_sitemap_analysis', $out, 6 * HOUR_IN_SECONDS );
		return $out;
	}

	private function recommendations( array $data, array $problem, array $missing ) {
		$r = array();
		if ( ! $data['sitemaps'] ) {
			$r[] = __( 'No sitemap found. Enable SEOlity sitemap enhancements (or your SEO plugin sitemap) and submit it in Search Console.', 'seolity' );
		}
		$by = array();
		foreach ( $problem as $p ) {
			$by[ $p['reason'] ] = isset( $by[ $p['reason'] ] ) ? $by[ $p['reason'] ] + 1 : 1;
		}
		foreach ( $by as $reason => $n ) {
			/* translators: 1 count 2 reason */
			$r[] = sprintf( __( 'Remove %1$d URL(s) from the sitemap (%2$s). Sitemaps should only contain canonical, indexable 200 pages.', 'seolity' ), $n, $reason );
		}
		if ( $missing ) {
			/* translators: %d count */
			$r[] = sprintf( __( '%d indexable published page(s) are not in any sitemap.', 'seolity' ), count( $missing ) );
		}
		foreach ( $data['sitemaps'] as $s ) {
			if ( 'urlset' === $s['type'] && $s['count'] && ! $s['with_lastmod'] ) {
				/* translators: %s sitemap url */
				$r[] = sprintf( __( 'Sitemap %s has no <lastmod> values — accurate lastmod helps Google prioritize recrawls.', 'seolity' ), $s['url'] );
			}
			if ( $s['count'] > 50000 ) {
				/* translators: %s sitemap url */
				$r[] = sprintf( __( 'Sitemap %s exceeds 50,000 URLs and will be rejected.', 'seolity' ), $s['url'] );
			}
		}
		return $r;
	}
}
