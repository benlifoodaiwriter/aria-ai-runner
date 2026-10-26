<?php
/**
 * Proves that an applied change is really in effect — never trusts "the request was accepted".
 *
 * Two levels:
 *  - persisted: the value read back from WordPress / the active SEO plugin (Rank Math meta,
 *    post content, Rank Math redirect table) equals what was approved;
 *  - effective: the public page answers as expected (robots meta / X-Robots-Tag, canonical,
 *    title, description, redirect status + Location), fetched like a visitor would.
 * A page served from a full-page cache can lag; that is reported as "persisted, live check
 * pending" instead of a false success or a false failure.
 *
 * @package SEOlity
 */

namespace SEOlity\Operations;

use SEOlity\Content\Objects;
use SEOlity\Crawler\PageParser;
use SEOlity\Plugin;
use SEOlity\Redirects\Manager as Redirects;

defined( 'ABSPATH' ) || exit;

final class Verifier {

	/** @var Plugin */
	private $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/** Fetch a URL like a visitor (no redirects followed, cache-busting query). */
	public function fetch( $url, $bust = true ) {
		$u   = $bust ? add_query_arg( 'seolity_verify', wp_rand( 1000, 999999 ), $url ) : $url;
		$res = wp_remote_get( $u, array( 'timeout' => 20, 'redirection' => 0, 'sslverify' => apply_filters( 'https_local_ssl_verify', false ), 'headers' => array( 'Cache-Control' => 'no-cache' ), 'user-agent' => 'Mozilla/5.0 (compatible; SEOlity-Verify/1.0; +' . home_url( '/' ) . ')' ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$headers = array();
		$raw     = wp_remote_retrieve_headers( $res );
		// WordPress returns a case-insensitive dictionary object; casting it to an array loses the headers.
		$raw = is_object( $raw ) && method_exists( $raw, 'getAll' ) ? $raw->getAll() : (array) $raw;
		foreach ( $raw as $k => $v ) {
			$headers[ strtolower( $k ) ] = $v;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$html = (string) wp_remote_retrieve_body( $res );
		return array(
			'status'   => $code,
			'headers'  => $headers,
			'location' => isset( $headers['location'] ) ? ( is_array( $headers['location'] ) ? end( $headers['location'] ) : $headers['location'] ) : '',
			'page'     => 200 === $code ? PageParser::parse( $html, $url, $headers ) : null,
		);
	}

	private static function norm_url( $u ) {
		$u = remove_query_arg( 'seolity_verify', (string) $u );
		return untrailingslashit( strtolower( rawurldecode( preg_replace( '#^https?://#i', '', $u ) ) ) );
	}

	private static function norm_text( $s ) {
		return trim( preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( (string) $s ), ENT_QUOTES, 'UTF-8' ) ) );
	}

	/**
	 * Verify one applied change.
	 *
	 * @param array $c Change row (object_type, object_id, field, after_value, url).
	 * @return array{ok:bool,persisted:bool,effective:?bool,detail:string}
	 */
	public function change( array $c ) {
		$type  = (string) $c['object_type'];
		$id    = (int) $c['object_id'];
		$field = (string) $c['field'];
		$want  = (string) $c['after_value'];
		$now   = Objects::read( $this->plugin, $type, $id, $field );
		$same  = in_array( $field, array( 'post_content', 'term_description', 'post_excerpt' ), true ) ? self::norm_text( $now ) === self::norm_text( $want ) || false !== strpos( (string) $now, trim( $want ) ) : self::norm_text( $now ) === self::norm_text( $want );
		if ( in_array( $field, array( 'noindex', 'nofollow' ), true ) ) {
			$same = ( '1' === (string) $now ) === (bool) $want;
		}
		$out = array( 'ok' => $same, 'persisted' => $same, 'effective' => null, 'detail' => $same ? __( 'Saved value confirmed.', 'seolity' ) : __( 'The saved value differs from the approved value.', 'seolity' ) );
		if ( ! $same ) {
			return $out;
		}
		// Rank Math: confirm in Rank Math's own storage (what its editor shows).
		if ( 'rankmath' === $this->plugin->meta->engine() && in_array( $type, array( 'post', 'term' ), true ) ) {
			$get = 'term' === $type ? 'get_term_meta' : 'get_post_meta';
			$map = array( 'meta_title' => 'rank_math_title', 'meta_description' => 'rank_math_description', 'canonical' => 'rank_math_canonical_url' );
			if ( isset( $map[ $field ] ) && self::norm_text( $get( $id, $map[ $field ], true ) ) !== self::norm_text( $want ) ) {
				return array( 'ok' => false, 'persisted' => false, 'effective' => null, 'detail' => __( 'Rank Math does not hold the new value.', 'seolity' ) );
			}
			if ( 'noindex' === $field ) {
				$robots = (array) $get( $id, 'rank_math_robots', true );
				if ( in_array( 'noindex', $robots, true ) !== (bool) $want ) {
					return array( 'ok' => false, 'persisted' => false, 'effective' => null, 'detail' => __( 'Rank Math robots setting was not updated.', 'seolity' ) );
				}
			}
		}
		// Effective check on the live page for head tags.
		$url = Objects::url( $type, $id );
		if ( $url && in_array( $field, array( 'meta_title', 'meta_description', 'canonical', 'noindex', 'nofollow' ), true ) && 'publish' === ( 'post' === $type ? get_post_status( $id ) : 'publish' ) ) {
			$res = $this->fetch( $url );
			if ( is_wp_error( $res ) || ! $res['page'] ) {
				$out['detail'] = __( 'Saved; the live page could not be checked right now.', 'seolity' );
				return $out;
			}
			$p = $res['page'];
			switch ( $field ) {
				case 'noindex':
					$has = false !== strpos( $p['robots'] . ',' . $p['x_robots'], 'noindex' );
					$out['effective'] = $has === (bool) $want;
					break;
				case 'canonical':
					$out['effective'] = '' === $want || ( ! empty( $p['canonicals'] ) && self::norm_url( $p['canonicals'][0] ) === self::norm_url( $want ) );
					break;
				case 'meta_description':
					$out['effective'] = false !== mb_strpos( self::norm_text( $p['meta_description'] ), mb_substr( self::norm_text( $want ), 0, 60 ) );
					break;
				case 'meta_title':
					// SEO plugins may add the site name / separator: the approved text must be part of <title>.
					$out['effective'] = false !== mb_strpos( self::norm_text( $p['title'] ), mb_substr( self::norm_text( $want ), 0, 40 ) );
					break;
				default:
					$out['effective'] = null;
			}
			$out['detail'] = true === $out['effective'] ? __( 'Confirmed on the live page.', 'seolity' ) : ( false === $out['effective'] ? __( 'Saved, but the live page does not show it yet (a page cache may need clearing).', 'seolity' ) : $out['detail'] );
		}
		return $out;
	}

	/**
	 * Verify a redirect: stored in the owning system and answered by the site.
	 *
	 * @return array{ok:bool,persisted:bool,effective:?bool,detail:string}
	 */
	public function redirect( $source, $target, $type = 301 ) {
		$path = Redirects::normalize_source( $source );
		$url  = home_url( $path );
		$res  = $this->fetch( $url, false );
		if ( ! is_wp_error( $res ) && $this->is_redirect_to( $res, $target, $type ) ) {
			return $this->redirect_ok( $res );
		}
		// A page cache or CDN often still serves the old 404: clear this URL and look again past the cache.
		$purged = $this->purge_url( $url );
		usleep( 800000 );
		$again = $this->fetch( $url, true );
		if ( ! is_wp_error( $again ) && $this->is_redirect_to( $again, $target, $type ) ) {
			$out = $this->redirect_ok( $again );
			if ( $purged ) {
				/* translators: %s: cache names */
				$out['detail'] .= ' ' . sprintf( __( '(The old 404 was still cached; cleared in: %s.)', 'seolity' ), implode( ', ', $purged ) );
			} else {
				$out['detail'] .= ' ' . __( '(Visitors may still get a cached 404 until your page cache or CDN expires — clear it there.)', 'seolity' );
			}
			return $out;
		}
		$res = is_wp_error( $res ) ? $again : $res;
		if ( is_wp_error( $res ) ) {
			return array( 'ok' => false, 'persisted' => true, 'effective' => null, 'detail' => $res->get_error_message() );
		}
		$why = $this->redirect_diagnosis( $path, $res );
		return array(
			'ok'        => false,
			'persisted' => $why['persisted'],
			'effective' => false,
			'status'    => (int) $res['status'],
			'location'  => $res['location'],
			'cause'     => $why['cause'],
			/* translators: 1 status 2 location */
			'detail'    => sprintf( __( 'The old URL answers %1$d %2$s instead of the redirect.', 'seolity' ), $res['status'], urldecode( (string) $res['location'] ) ) . ' ' . $why['detail'],
		);
	}

	/** Does the URL now answer 410 Gone? (Cache cleared and checked again when needed.) */
	public function gone( $source ) {
		$url = home_url( Redirects::normalize_source( $source ) );
		$res = $this->fetch( $url, false );
		if ( is_wp_error( $res ) || 410 !== (int) $res['status'] ) {
			$this->purge_url( $url );
			usleep( 800000 );
			$res = $this->fetch( $url, true );
		}
		if ( is_wp_error( $res ) ) {
			return array( 'ok' => false, 'persisted' => true, 'effective' => null, 'detail' => $res->get_error_message() );
		}
		$ok = 410 === (int) $res['status'];
		return array(
			'ok'        => $ok,
			'persisted' => true,
			'effective' => $ok,
			'status'    => (int) $res['status'],
			/* translators: %d: status */
			'detail'    => $ok ? __( 'The URL now answers 410 Gone.', 'seolity' ) : sprintf( __( 'The URL answers %d instead of 410.', 'seolity' ), $res['status'] ) . ' ' . $this->redirect_diagnosis( Redirects::normalize_source( $source ), $res )['detail'],
		);
	}

	private function is_redirect_to( array $res, $target, $type ) {
		return (int) $res['status'] === (int) $type && self::norm_url( $res['location'] ) === self::norm_url( $target );
	}

	private function redirect_ok( array $res ) {
		return array(
			'ok'        => true,
			'persisted' => true,
			'effective' => true,
			'status'    => (int) $res['status'],
			'location'  => $res['location'],
			'by'        => isset( $res['headers']['x-redirect-by'] ) ? ( is_array( $res['headers']['x-redirect-by'] ) ? end( $res['headers']['x-redirect-by'] ) : $res['headers']['x-redirect-by'] ) : '',
			/* translators: 1 status 2 location */
			'detail'    => sprintf( __( 'The old URL answers %1$d → %2$s.', 'seolity' ), $res['status'], urldecode( remove_query_arg( 'seolity_verify', $res['location'] ) ) ),
		);
	}

	/** Clear one URL in the page caches SEOlity knows; returns the names of the caches cleared. */
	public function purge_url( $url ) {
		$done = array();
		if ( has_action( 'litespeed_purge_url' ) || defined( 'LSCWP_V' ) ) {
			do_action( 'litespeed_purge_url', $url );
			$done[] = 'LiteSpeed Cache';
		}
		if ( function_exists( 'rocket_clean_files' ) ) {
			rocket_clean_files( array( $url ) );
			$done[] = 'WP Rocket';
		}
		if ( function_exists( 'w3tc_flush_url' ) ) {
			w3tc_flush_url( $url );
			$done[] = 'W3 Total Cache';
		}
		if ( function_exists( 'wpsc_delete_url_cache' ) ) {
			wpsc_delete_url_cache( $url );
			$done[] = 'WP Super Cache';
		}
		if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
			sg_cachepress_purge_cache( $url );
			$done[] = 'SiteGround Optimizer';
		}
		do_action( 'seolity_purge_url', $url );
		return $done;
	}

	/**
	 * Why a saved redirect is not answered: does the redirect owner match this URL at all, is
	 * the redirect module switched on, or does a cache / CDN / another plugin answer first?
	 */
	private function redirect_diagnosis( $path, array $res ) {
		$p       = Plugin::instance();
		$h       = $res['headers'];
		$cache   = array();
		foreach ( array( 'x-litespeed-cache', 'cf-cache-status', 'x-cache', 'x-cache-status', 'x-proxy-cache', 'x-sg-cache', 'x-srcache-fetch-status', 'ar-cache-status' ) as $k ) {
			if ( isset( $h[ $k ] ) && false !== stripos( is_array( $h[ $k ] ) ? implode( ',', $h[ $k ] ) : $h[ $k ], 'hit' ) ) {
				$cache[] = $k;
			}
		}
		$server = isset( $h['server'] ) ? ( is_array( $h['server'] ) ? end( $h['server'] ) : $h['server'] ) : '';
		if ( $p->rankmath->owns_redirects() ) {
			$m = class_exists( '\\RankMath\\Redirections\\DB' ) ? \RankMath\Redirections\DB::match_redirections( trim( $path, '/' ) ) : false;
			if ( ! $m ) {
				return array( 'persisted' => false, 'cause' => 'not_matched', 'detail' => __( 'Rank Math has no active redirect that matches this URL — check Rank Math → Redirections (status, and that the source was saved exactly like this).', 'seolity' ) );
			}
			$owner = 'Rank Math';
		} else {
			if ( ! $p->settings->get( 'redirects_enabled' ) ) {
				return array( 'persisted' => true, 'cause' => 'disabled', 'detail' => __( 'SEOlity redirects are switched off (Settings → Technical SEO → Enable redirects), so the saved redirect is not served.', 'seolity' ) );
			}
			if ( ! $p->redirects->match( $path ) ) {
				return array( 'persisted' => false, 'cause' => 'not_matched', 'detail' => __( 'The saved redirect does not match this URL.', 'seolity' ) );
			}
			$owner = 'SEOlity';
		}
		if ( $cache ) {
			/* translators: 1 owner 2 headers */
			return array( 'persisted' => true, 'cause' => 'cache', 'detail' => sprintf( __( '%1$s holds the redirect, but a cache answered (%2$s: HIT). Clear the page cache / CDN for this URL; the redirect then works.', 'seolity' ), $owner, implode( ', ', $cache ) ) );
		}
		/* translators: 1 owner 2 server */
		return array( 'persisted' => true, 'cause' => 'other', 'detail' => sprintf( __( '%1$s holds a matching redirect, but something answers before it (a CDN or cache such as %2$s, a security plugin, or a server rule for this path). Clear the caches and check the server’s redirect rules.', 'seolity' ), $owner, $server ? $server : __( 'the server', 'seolity' ) ) );
	}

	/** Is a redirect target a live, indexable page of this site (or an external URL)? */
	public function target_ok( $target ) {
		$res = $this->fetch( $target, false );
		if ( is_wp_error( $res ) ) {
			return array( 'ok' => false, 'status' => 0, 'detail' => $res->get_error_message() );
		}
		if ( in_array( (int) $res['status'], array( 301, 302, 307, 308 ), true ) ) {
			/* translators: %s: url */
			return array( 'ok' => false, 'status' => (int) $res['status'], 'detail' => sprintf( __( 'The target itself redirects to %s (this would create a chain).', 'seolity' ), urldecode( $res['location'] ) ), 'final' => $res['location'] );
		}
		if ( 200 !== (int) $res['status'] ) {
			/* translators: %d: status */
			return array( 'ok' => false, 'status' => (int) $res['status'], 'detail' => sprintf( __( 'The target answers HTTP %d.', 'seolity' ), $res['status'] ) );
		}
		$noindex = $res['page'] && false !== strpos( $res['page']['robots'] . ',' . $res['page']['x_robots'], 'noindex' );
		return array( 'ok' => true, 'status' => 200, 'noindex' => $noindex, 'detail' => $noindex ? __( 'The target is live but set to noindex.', 'seolity' ) : __( 'The target is a live page.', 'seolity' ) );
	}
}
