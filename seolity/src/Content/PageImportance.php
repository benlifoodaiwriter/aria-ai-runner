<?php
/**
 * How much a page matters, before SEOlity (or its AI) suggests changing it.
 *
 * A redirect, title or description suggestion is only worth making for a page that is part of
 * the site: an Elementor template that no page uses, a floating-button snippet, a draft or a
 * page with noindex does not need SEO work. The answer comes from the site itself — post type
 * and status, whether a template is used anywhere, menus, internal links found by the crawler,
 * the XML sitemap, Search Console clicks and impressions, and WooCommerce sales.
 *
 * Levels: critical (home, shop, top traffic) · important · normal · minor (draft, noindex,
 * nothing points to it) · support (a template/snippet that IS used inside other pages — never
 * an SEO target itself) · unused (a template/system item nothing uses).
 *
 * @package SEOlity
 */

namespace SEOlity\Content;

use SEOlity\Core\Database;
use SEOlity\Plugin;

defined( 'ABSPATH' ) || exit;

final class PageImportance {

	/** Post types that are building blocks, not pages (Elementor, block themes, plugins). */
	const SYSTEM_TYPES = array( 'elementor_library', 'e-floating-buttons', 'elementor_snippet', 'elementor_font', 'elementor_icons', 'wp_template', 'wp_template_part', 'wp_block', 'wp_navigation', 'wp_global_styles', 'nav_menu_item', 'revision', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request', 'wpcf7_contact_form', 'attachment' );

	/** @var Plugin */
	private $plugin;

	/** @var array Per-request cache. */
	private $cache = array();

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * @param int|string|array $target Post ID, URL, or array( object_type, object_id ) / ( url ).
	 * @return array{level:string,score:int,seo_target:bool,reasons:string[],signals:array,url:string,object_type:string,object_id:int}
	 */
	public function of( $target ) {
		list( $type, $id, $url ) = $this->resolve( $target );
		$key = $type . ':' . $id . ':' . $url;
		if ( isset( $this->cache[ $key ] ) ) {
			return $this->cache[ $key ];
		}
		$reasons = array();
		$signals = array();
		$score   = 0;
		$level   = '';
		if ( 'post' === $type && $id ) {
			$post = get_post( $id );
			if ( ! $post ) {
				return $this->cache[ $key ] = $this->out( 'unused', 0, array( __( 'The page does not exist.', 'seolity' ) ), array(), $url, $type, $id );
			}
			$pt                 = $post->post_type;
			$signals['type']    = $pt;
			$signals['status']  = $post->post_status;
			$obj                = get_post_type_object( $pt );
			$viewable           = $obj && is_post_type_viewable( $obj );
			if ( in_array( $pt, self::SYSTEM_TYPES, true ) || ! $viewable ) {
				$used = $this->template_used( $post );
				$signals['used_in'] = $used;
				/* translators: %s: post type label */
				$what = sprintf( __( '“%s” is a building block (template, snippet or system item), not a page visitors find in Google.', 'seolity' ), $obj ? $obj->labels->singular_name : $pt );
				if ( $used ) {
					/* translators: %d: count */
					return $this->cache[ $key ] = $this->out( 'support', 10, array( $what, sprintf( __( 'Used in %d place(s) of the site — keep it, but it needs no SEO title, description or redirect.', 'seolity' ), $used ) ), $signals, $url, $type, $id );
				}
				return $this->cache[ $key ] = $this->out( 'unused', 0, array( $what, __( 'Nothing on the site uses it, so SEO changes to it have no effect.', 'seolity' ) ), $signals, $url, $type, $id );
			}
			$url = $url ? $url : (string) get_permalink( $post );
			if ( 'publish' !== $post->post_status ) {
				$level     = 'minor';
				$reasons[] = __( 'Not published.', 'seolity' );
			} else {
				$score += 20;
			}
			if ( '1' === (string) $this->plugin->meta->get_post( $id, 'noindex' ) ) {
				$level     = 'minor';
				$reasons[] = __( 'Set to noindex — Google is told not to show it.', 'seolity' );
			}
			$front = (int) get_option( 'page_on_front' );
			$shop  = function_exists( 'wc_get_page_id' ) ? (int) wc_get_page_id( 'shop' ) : 0;
			if ( $id === $front || $id === $shop || $id === (int) get_option( 'page_for_posts' ) ) {
				$score    += 40;
				$reasons[] = __( 'Home, shop or blog page.', 'seolity' );
			}
			if ( $this->in_menu( $id ) ) {
				$score    += 10;
				$reasons[] = __( 'Linked in a navigation menu.', 'seolity' );
				$signals['in_menu'] = true;
			}
			if ( 'product' === $pt ) {
				$sales = (int) get_post_meta( $id, 'total_sales', true );
				$signals['sales'] = $sales;
				if ( $sales > 0 ) {
					$score += min( 15, (int) ceil( $sales / 3 ) );
					/* translators: %d: sales */
					$reasons[] = sprintf( __( '%d sales.', 'seolity' ), $sales );
				}
			}
		} elseif ( 'term' === $type && $id ) {
			$term = get_term( $id );
			if ( ! $term || is_wp_error( $term ) ) {
				return $this->cache[ $key ] = $this->out( 'unused', 0, array( __( 'The archive does not exist.', 'seolity' ) ), array(), $url, $type, $id );
			}
			$url               = $url ? $url : (string) get_term_link( $term );
			$signals['type']   = $term->taxonomy;
			$signals['items']  = (int) $term->count;
			$score            += $term->count > 0 ? 20 : 5;
			if ( 0 === (int) $term->count ) {
				$level     = 'minor';
				$reasons[] = __( 'Empty archive (no items).', 'seolity' );
			}
			if ( $this->in_menu( $id, 'taxonomy' ) ) {
				$score    += 10;
				$reasons[] = __( 'Linked in a navigation menu.', 'seolity' );
			}
		} else {
			$score += untrailingslashit( $url ) === untrailingslashit( home_url( '/' ) ) ? 60 : 10;
		}
		// What the crawler and Search Console know about the URL.
		if ( $url ) {
			$db   = $this->plugin->db;
			$hash = Database::url_hash( $url );
			$u    = $db->row( 'SELECT inlinks, in_sitemap, status_code, depth FROM {urls} WHERE project_id = %d AND url_hash = %s', array( $db->project_id(), $hash ) );
			if ( $u ) {
				$signals['inlinks']    = (int) $u['inlinks'];
				$signals['in_sitemap'] = (bool) $u['in_sitemap'];
				$score                += min( 25, (int) round( (int) $u['inlinks'] * 2.5 ) );
				$score                += $u['in_sitemap'] ? 5 : 0;
				if ( (int) $u['inlinks'] > 0 ) {
					/* translators: %d: links */
					$reasons[] = sprintf( __( 'Internal links pointing to it: %d.', 'seolity' ), (int) $u['inlinks'] );
				} else {
					$reasons[] = __( 'No internal link points to it.', 'seolity' );
				}
			}
			$g = $db->row( 'SELECT SUM(clicks) c, SUM(impressions) i FROM {gsc_page_query} WHERE project_id = %d AND page_hash = %s AND date > DATE_SUB(UTC_DATE(), INTERVAL 90 DAY)', array( $db->project_id(), $hash ) );
			if ( $g && ( (int) $g['c'] || (int) $g['i'] ) ) {
				$signals['clicks_90d']      = (int) $g['c'];
				$signals['impressions_90d'] = (int) $g['i'];
				$score                     += min( 30, (int) ceil( (int) $g['c'] / 5 ) ) + min( 10, (int) floor( (int) $g['i'] / 200 ) );
				/* translators: 1: clicks 2: impressions */
				$reasons[] = sprintf( __( '%1$s clicks and %2$s impressions in Google (90 days).', 'seolity' ), number_format_i18n( (int) $g['c'] ), number_format_i18n( (int) $g['i'] ) );
			}
		}
		$score = max( 0, min( 100, $score ) );
		if ( '' === $level ) {
			$level = $score >= 70 ? 'critical' : ( $score >= 45 ? 'important' : ( $score >= 20 ? 'normal' : 'minor' ) );
		}
		return $this->cache[ $key ] = $this->out( $level, $score, $reasons, $signals, $url, $type, $id );
	}

	/** Is it worth an SEO change (title, description, redirect, content)? */
	public function worth_changing( $target ) {
		return $this->of( $target )['seo_target'];
	}

	private function out( $level, $score, array $reasons, array $signals, $url, $type, $id ) {
		$labels = array(
			'critical'  => __( 'Critical page', 'seolity' ),
			'important' => __( 'Important page', 'seolity' ),
			'normal'    => __( 'Normal page', 'seolity' ),
			'minor'     => __( 'Minor page', 'seolity' ),
			'support'   => __( 'Building block used by other pages', 'seolity' ),
			'unused'    => __( 'Unused / not a page', 'seolity' ),
		);
		return array(
			'level'       => $level,
			'label'       => $labels[ $level ],
			'score'       => (int) $score,
			'seo_target'  => ! in_array( $level, array( 'support', 'unused' ), true ),
			'reasons'     => array_values( array_unique( $reasons ) ),
			'signals'     => $signals,
			'url'         => (string) $url,
			'object_type' => (string) $type,
			'object_id'   => (int) $id,
		);
	}

	/** [ 'post'|'term'|'url', id, url ]. */
	private function resolve( $target ) {
		if ( is_array( $target ) ) {
			$t   = isset( $target['object_type'] ) ? (string) $target['object_type'] : '';
			$id  = isset( $target['object_id'] ) ? (int) $target['object_id'] : 0;
			$url = isset( $target['url'] ) ? (string) $target['url'] : '';
			if ( $id && in_array( $t, array( 'post', 'product', 'page' ), true ) ) {
				return array( 'post', $id, $url );
			}
			if ( $id && in_array( $t, array( 'term', 'category', 'post_tag', 'product_cat' ), true ) ) {
				return array( 'term', $id, $url );
			}
			$target = $url;
		}
		if ( is_numeric( $target ) ) {
			return array( 'post', (int) $target, '' );
		}
		$url = (string) $target;
		$pid = $url ? url_to_postid( $url ) : 0;
		if ( $pid ) {
			return array( 'post', $pid, $url );
		}
		if ( $url ) {
			$db  = $this->plugin->db;
			$row = $db->row( 'SELECT object_type, object_id FROM {urls} WHERE project_id = %d AND url_hash = %s', array( $db->project_id(), Database::url_hash( $url ) ) );
			if ( $row && $row['object_id'] ) {
				return array( 'term' === $row['object_type'] ? 'term' : 'post', (int) $row['object_id'], $url );
			}
		}
		return array( 'url', 0, $url );
	}

	private function in_menu( $id, $kind = 'post_type' ) {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT pm.post_id FROM {$wpdb->postmeta} pm JOIN {$wpdb->postmeta} t ON t.post_id = pm.post_id AND t.meta_key = '_menu_item_type' AND t.meta_value = %s WHERE pm.meta_key = '_menu_item_object_id' AND pm.meta_value = %s LIMIT 1", $kind, (string) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/** In how many places a template / snippet is used (Elementor display conditions, shortcodes, template widgets, reusable blocks). */
	private function template_used( \WP_Post $post ) {
		global $wpdb;
		$id    = (int) $post->ID;
		$count = 0;
		$cond  = get_post_meta( $id, '_elementor_conditions', true );
		if ( ! empty( $cond ) ) {
			$count += count( (array) $cond );
		}
		if ( 'publish' !== $post->post_status ) {
			return $count;
		}
		// [elementor-template id="X"], template widgets in Elementor data, and reusable blocks.
		$count += (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish' AND ID <> %d AND ( post_content LIKE %s OR post_content LIKE %s )", $id, '%elementor-template id="' . $id . '"%', '%wp:block {"ref":' . $id . '}%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$count += (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_status = 'publish' WHERE pm.meta_key = '_elementor_data' AND pm.post_id <> %d AND ( pm.meta_value LIKE %s OR pm.meta_value LIKE %s )", $id, '%"template_id":"' . $id . '"%', '%"template_id":' . $id . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		// Floating buttons / popups are shown site-wide when published (they have no "uses" to count).
		if ( in_array( $post->post_type, array( 'e-floating-buttons' ), true ) ) {
			$count = max( 1, $count );
		}
		return $count;
	}
}
