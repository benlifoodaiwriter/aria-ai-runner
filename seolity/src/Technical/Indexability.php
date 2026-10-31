<?php
/**
 * Indexability & crawl budget audit.
 *
 * Finds URLs that are discoverable / crawlable / indexable although they are not meant for
 * search (Elementor and other builder templates, popups, forms, attachment pages, internal
 * search, date archives, thin tag archives, parameter URLs …) — and important ones that are
 * blocked. Every finding is based on what the site really answers right now:
 *   HTTP status, robots meta, X-Robots-Tag, canonical, robots.txt, XML sitemap inclusion,
 *   internal links, the active SEO plugin's settings, and (when Search Console is connected)
 *   Google's own URL Inspection result for the sample URL.
 * A local crawl cannot see Google's index; only the Inspection result says what Google has.
 *
 * Fixes use the authoritative setting (Rank Math options / per-object robots) through the
 * operations system: preview → approval → apply → verification on a sample URL → undo.
 * The right tool is chosen per case: noindex removes pages from Google (and must stay
 * crawlable so Google can see it); robots.txt only stops crawling (an indexed URL can stay in
 * the index); removing a URL from the sitemap does not by itself stop indexing.
 *
 * @package SEOlity
 */

namespace SEOlity\Technical;

use SEOlity\Core\Database;
use SEOlity\Plugin;

defined( 'ABSPATH' ) || exit;

final class Indexability {

	const OPTION = 'seolity_indexability';

	/** Post types made by builders / plugins for internal use (never content for search). */
	const UTILITY_TYPES = array(
		'elementor_library'       => 'Elementor',
		'e-landing-page'          => 'Elementor',
		'elementor_snippet'       => 'Elementor Pro',
		'elementor_font'          => 'Elementor Pro',
		'elementor_icons'         => 'Elementor Pro',
		'elementskit_template'    => 'ElementsKit',
		'elementskit_content'     => 'ElementsKit',
		'jet-theme-core'          => 'JetThemeCore',
		'jet-popup'               => 'JetPopup',
		'jet-engine'              => 'JetEngine',
		'jet-woo-builder'         => 'JetWooBuilder',
		'jet-menu'                => 'JetMenu',
		'ae_global_templates'     => 'AnyWhere Elementor',
		'fl-builder-template'     => 'Beaver Builder',
		'et_pb_layout'            => 'Divi',
		'ct_template'             => 'Oxygen',
		'brizy_template'          => 'Brizy',
		'wpforms'                 => 'WPForms',
		'wpcf7_contact_form'      => 'Contact Form 7',
		'popup'                   => 'Popup Maker',
		'popup_theme'             => 'Popup Maker',
		'wp_block'                => 'WordPress (reusable blocks)',
		'astra-advanced-hook'     => 'Astra',
		'kadence_element'         => 'Kadence',
		'gp_elements'             => 'GeneratePress',
		'oceanwp_library'         => 'OceanWP',
		'xts-template'            => 'XTemplate',
		'porto_builder'           => 'Porto',
		'woodmart_layout'         => 'WoodMart',
		'mc4wp-form'              => 'Mailchimp for WP',
		'shop_order'              => 'WooCommerce',
		'shop_coupon'             => 'WooCommerce',
		'product_variation'       => 'WooCommerce',
	);

	/** @var Plugin */
	private $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	public function last() {
		$r = get_option( self::OPTION );
		return is_array( $r ) ? $r : null;
	}

	private function rm() {
		return 'rankmath' === $this->plugin->meta->engine() && $this->plugin->rankmath->active();
	}

	private static function rm_opt( $group, $key, $default = null ) {
		$o = get_option( 'rank-math-options-' . $group );
		return is_array( $o ) && array_key_exists( $key, $o ) ? $o[ $key ] : $default;
	}

	/** Live state of one URL (status, robots, canonical, robots.txt, sitemap, links, Google). */
	private function probe( $url, array $sitemap, $inspect = false ) {
		$res   = $this->plugin->operations->verifier()->fetch( $url, false );
		$state = array( 'status' => 0, 'robots' => '', 'x_robots' => '', 'canonical' => '', 'location' => '' );
		if ( ! is_wp_error( $res ) ) {
			$state['status']   = (int) $res['status'];
			$state['location'] = (string) $res['location'];
			if ( $res['page'] ) {
				$state['robots']    = (string) $res['page']['robots'];
				$state['x_robots']  = (string) $res['page']['x_robots'];
				$state['canonical'] = ! empty( $res['page']['canonicals'] ) ? (string) $res['page']['canonicals'][0] : '';
			} elseif ( isset( $res['headers']['x-robots-tag'] ) ) {
				$state['x_robots'] = strtolower( is_array( $res['headers']['x-robots-tag'] ) ? implode( ',', $res['headers']['x-robots-tag'] ) : $res['headers']['x-robots-tag'] );
			}
		} else {
			$state['error'] = $res->get_error_message();
		}
		$state['robots_txt_blocked'] = ! $this->plugin->robots->is_allowed( $url );
		$state['in_sitemap']         = isset( $sitemap[ untrailingslashit( strtolower( rawurldecode( $url ) ) ) ] );
		$row                         = $this->plugin->db->row( 'SELECT inlinks FROM {urls} WHERE project_id = %d AND url_hash = %s', array( $this->plugin->db->project_id(), Database::url_hash( $url ) ) );
		$state['inlinks']            = $row ? (int) $row['inlinks'] : null;
		$noindex                     = false !== strpos( $state['robots'] . ',' . $state['x_robots'], 'noindex' );
		$canon_self                  = '' === $state['canonical'] || untrailingslashit( strtolower( rawurldecode( $state['canonical'] ) ) ) === untrailingslashit( strtolower( rawurldecode( $url ) ) );
		$state['indexable']          = 200 === $state['status'] && ! $noindex && $canon_self && ! $state['robots_txt_blocked'];
		$state['noindex']            = $noindex;
		if ( $inspect && $this->plugin->gsc_oauth->is_connected() ) {
			$g = $this->plugin->gsc_client->inspect( $url );
			if ( ! is_wp_error( $g ) && isset( $g['inspectionResult']['indexStatusResult'] ) ) {
				$i               = $g['inspectionResult']['indexStatusResult'];
				$state['google'] = array(
					'verdict'  => isset( $i['verdict'] ) ? $i['verdict'] : '',
					'coverage' => isset( $i['coverageState'] ) ? $i['coverageState'] : '',
					'crawled'  => isset( $i['lastCrawlTime'] ) ? $i['lastCrawlTime'] : '',
					'canonical' => isset( $i['googleCanonical'] ) ? $i['googleCanonical'] : '',
				);
			}
		}
		return $state;
	}

	private function sitemap_index() {
		$out = array();
		foreach ( $this->plugin->sitemap->collect_urls( 5000 ) as $u ) {
			$out[ untrailingslashit( strtolower( rawurldecode( $u ) ) ) ] = true;
		}
		return $out;
	}

	/** Run the audit (background job). */
	public function scan() {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		$act      = $this->plugin->activity;
		$sitemap  = $this->sitemap_index();
		$rm       = $this->rm();
		$findings = array();
		$inventory = array();
		$act->step( 0, __( 'Checking post types, templates and attachments…', 'seolity' ), 10 );

		/* 1. Post types (incl. builder templates). */
		foreach ( get_post_types( array(), 'objects' ) as $pt ) {
			if ( ! $pt->publicly_queryable && ! $pt->public ) {
				continue;
			}
			if ( 'attachment' === $pt->name ) {
				continue;
			}
			$ids = get_posts( array( 'post_type' => $pt->name, 'post_status' => 'publish', 'numberposts' => 2, 'fields' => 'ids', 'orderby' => 'date' ) );
			$cnt = (int) wp_count_posts( $pt->name )->publish;
			if ( ! $cnt || ! $ids ) {
				continue;
			}
			$utility = isset( self::UTILITY_TYPES[ $pt->name ] );
			$source  = $utility ? self::UTILITY_TYPES[ $pt->name ] : ( ! empty( $pt->_builtin ) ? 'WordPress' : ( 'product' === $pt->name ? 'WooCommerce' : __( 'a plugin or the theme', 'seolity' ) ) );
			$url     = (string) get_permalink( $ids[0] );
			if ( '' === $url ) {
				continue;
			}
			$state   = $this->probe( $url, $sitemap, $utility );
			$inventory[] = array( 'group' => 'post_type', 'name' => $pt->name, 'label' => $pt->labels->name, 'source' => $source, 'count' => $cnt, 'url' => $url, 'intended' => ! $utility, 'state' => $state );
			if ( $utility && ( $state['indexable'] || $state['in_sitemap'] ) ) {
				$items = $rm ? array(
					array( 'kind' => 'setting', 'option' => 'rank-math-options-titles', 'path' => 'pt_' . $pt->name . '_custom_robots', 'after' => 'on', 'label' => sprintf( /* translators: %s: type */ __( 'Rank Math: use custom robots for “%s”', 'seolity' ), $pt->labels->name ) ),
					array( 'kind' => 'setting', 'option' => 'rank-math-options-titles', 'path' => 'pt_' . $pt->name . '_robots', 'after' => array( 'noindex' ), 'label' => sprintf( /* translators: %s: type */ __( 'Rank Math: noindex all “%s”', 'seolity' ), $pt->labels->name ), 'check_url' => $url, 'expect' => 'noindex' ),
					array( 'kind' => 'setting', 'option' => 'rank-math-options-sitemap', 'path' => 'pt_' . $pt->name . '_sitemap', 'after' => 'off', 'label' => sprintf( /* translators: %s: type */ __( 'Rank Math: remove “%s” from the sitemap', 'seolity' ), $pt->labels->name ) ),
				) : array();
				$findings[] = $this->finding(
					'pt:' . $pt->name,
					array(
						'url'      => $url,
						'type'     => $pt->labels->name . ' (' . $pt->name . ')',
						'source'   => $source,
						'count'    => $cnt,
						'state'    => $state,
						'severity' => 'high',
						/* translators: 1 source 2 count */
						'issue'    => sprintf( __( '%1$s templates are public: %2$d URLs can be crawled and indexed.', 'seolity' ), $source, $cnt ),
						'reason'   => __( 'These are layout parts (headers, footers, popups, saved sections) for internal use. As separate URLs they are thin, duplicate fragments that waste crawl budget and can appear in Google.', 'seolity' ),
						'solution' => __( 'Set them to noindex and remove them from the XML sitemap. Do not block them in robots.txt — Google must be able to crawl them to see the noindex. Templates keep working inside your pages.', 'seolity' ),
						'edit'     => $rm ? admin_url( 'admin.php?page=rank-math-options-titles#setting-panel-post-type-' . $pt->name ) : admin_url( 'edit.php?post_type=' . $pt->name ),
						'items'    => $items,
					)
				);
			}
			if ( $utility && $state['robots_txt_blocked'] && $state['noindex'] ) {
				$findings[] = $this->finding(
					'ptblock:' . $pt->name,
					array(
						'url'      => $url,
						'type'     => $pt->labels->name,
						'source'   => $source,
						'state'    => $state,
						'severity' => 'medium',
						'issue'    => __( 'noindex is set, but robots.txt blocks these URLs.', 'seolity' ),
						'reason'   => __( 'A blocked URL is not crawled, so Google never sees its noindex. URLs Google already knows can stay in the index as “Indexed, though blocked by robots.txt”.', 'seolity' ),
						'solution' => __( 'Remove the robots.txt rule for these URLs (keep the noindex). After Google has recrawled them and dropped them, a robots.txt rule may be added back if crawl budget matters.', 'seolity' ),
						'edit'     => admin_url( 'admin.php?page=seolity#/robots' ),
						'items'    => array(),
					)
				);
			}
		}

		/* 2. Attachment pages. */
		$att = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'numberposts' => 1, 'fields' => 'ids' ) );
		if ( $att ) {
			$url   = (string) get_attachment_link( $att[0] );
			$state = $this->probe( $url, $sitemap );
			$inventory[] = array( 'group' => 'attachment', 'name' => 'attachment', 'label' => __( 'Attachment pages', 'seolity' ), 'source' => 'WordPress', 'count' => (int) wp_count_posts( 'attachment' )->inherit, 'url' => $url, 'intended' => false, 'state' => $state );
			if ( 200 === $state['status'] && ! $state['noindex'] ) {
				$findings[] = $this->finding(
					'attachment',
					array(
						'url'      => $url,
						'type'     => __( 'Attachment pages', 'seolity' ),
						'source'   => 'WordPress',
						'count'    => (int) wp_count_posts( 'attachment' )->inherit,
						'state'    => $state,
						'severity' => 'medium',
						'issue'    => __( 'Every uploaded image has its own indexable page.', 'seolity' ),
						'reason'   => __( 'Attachment pages contain only an image: thousands of thin pages that dilute crawl budget.', 'seolity' ),
						'solution' => __( 'Redirect attachment pages to the post they belong to (301). Image search keeps working (it uses the image file, not the attachment page).', 'seolity' ),
						'edit'     => $rm ? admin_url( 'admin.php?page=rank-math-options-general#setting-panel-links' ) : admin_url( 'upload.php' ),
						'items'    => $rm ? array( array( 'kind' => 'setting', 'option' => 'rank-math-options-general', 'path' => 'attachment_redirect_urls', 'after' => 'on', 'label' => __( 'Rank Math: redirect attachment pages to their parent post', 'seolity' ), 'check_url' => $url, 'expect' => 'redirect' ) ) : array(),
					)
				);
			}
		}
		$act->step( 0, __( 'Checking archives, internal search and feeds…', 'seolity' ), 45 );

		/* 3. Author archives. */
		$authors = get_users( array( 'has_published_posts' => true, 'fields' => 'ID', 'number' => 5 ) );
		if ( $authors ) {
			$url   = (string) get_author_posts_url( (int) $authors[0] );
			$state = $this->probe( $url, $sitemap );
			$inventory[] = array( 'group' => 'author', 'name' => 'author', 'label' => __( 'Author archives', 'seolity' ), 'source' => 'WordPress', 'count' => count( $authors ), 'url' => $url, 'intended' => count( $authors ) > 1, 'state' => $state );
			if ( 1 === count( $authors ) && $state['indexable'] ) {
				$findings[] = $this->finding(
					'author',
					array(
						'url'      => $url,
						'type'     => __( 'Author archive', 'seolity' ),
						'source'   => 'WordPress',
						'state'    => $state,
						'severity' => 'medium',
						'issue'    => __( 'The site has one author, so the author archive duplicates the blog list.', 'seolity' ),
						'reason'   => __( 'A second URL listing exactly the same posts competes with the blog / category pages.', 'seolity' ),
						'solution' => __( 'Disable the author archive (it redirects to the home page). With several authors, keep author pages indexable only if they have real biographies.', 'seolity' ),
						'edit'     => $rm ? admin_url( 'admin.php?page=rank-math-options-titles#setting-panel-author' ) : admin_url( 'users.php' ),
						'items'    => $rm ? array( array( 'kind' => 'setting', 'option' => 'rank-math-options-titles', 'path' => 'disable_author_archives', 'after' => 'on', 'label' => __( 'Rank Math: disable author archives', 'seolity' ), 'check_url' => $url, 'expect' => 'redirect' ) ) : array(),
					)
				);
			}
		}

		/* 4. Date archives. */
		$first = get_posts( array( 'post_type' => 'post', 'post_status' => 'publish', 'numberposts' => 1 ) );
		if ( $first ) {
			$url   = (string) get_year_link( (int) get_the_date( 'Y', $first[0] ) );
			$state = $this->probe( $url, $sitemap );
			$inventory[] = array( 'group' => 'date', 'name' => 'date', 'label' => __( 'Date archives', 'seolity' ), 'source' => 'WordPress', 'count' => null, 'url' => $url, 'intended' => false, 'state' => $state );
			if ( $state['indexable'] ) {
				$findings[] = $this->finding(
					'date',
					array(
						'url'      => $url,
						'type'     => __( 'Date archives', 'seolity' ),
						'source'   => 'WordPress',
						'state'    => $state,
						'severity' => 'medium',
						'issue'    => __( 'Year / month / day archives are indexable.', 'seolity' ),
						'reason'   => __( 'Date archives repeat the same posts in many lists with no search demand.', 'seolity' ),
						'solution' => __( 'Disable date archives (they redirect to the home page).', 'seolity' ),
						'edit'     => $rm ? admin_url( 'admin.php?page=rank-math-options-titles#setting-panel-misc' ) : '',
						'items'    => $rm ? array( array( 'kind' => 'setting', 'option' => 'rank-math-options-titles', 'path' => 'disable_date_archives', 'after' => 'on', 'label' => __( 'Rank Math: disable date archives', 'seolity' ), 'check_url' => $url, 'expect' => 'redirect' ) ) : array(),
					)
				);
			}
		}

		/* 5. Internal search results. */
		$url   = add_query_arg( 's', 'seolity-index-test', home_url( '/' ) );
		$state = $this->probe( $url, $sitemap );
		$inventory[] = array( 'group' => 'search', 'name' => 'search', 'label' => __( 'Internal search results', 'seolity' ), 'source' => 'WordPress', 'count' => null, 'url' => $url, 'intended' => false, 'state' => $state );
		if ( 200 === $state['status'] && ! $state['noindex'] ) {
			$findings[] = $this->finding(
				'search',
				array(
					'url'      => $url,
					'type'     => __( 'Internal search results', 'seolity' ),
					'source'   => 'WordPress',
					'state'    => $state,
					'severity' => 'high',
					'issue'    => __( 'Internal search result pages can be indexed.', 'seolity' ),
					'reason'   => __( 'Anyone can create unlimited search URLs (spam keywords included) that Google may crawl and index.', 'seolity' ),
					'solution' => __( 'Set search result pages to noindex.', 'seolity' ),
					'edit'     => $rm ? admin_url( 'admin.php?page=rank-math-options-titles#setting-panel-misc' ) : '',
					'items'    => $rm ? array( array( 'kind' => 'setting', 'option' => 'rank-math-options-titles', 'path' => 'noindex_search', 'after' => 'on', 'label' => __( 'Rank Math: noindex internal search results', 'seolity' ), 'check_url' => $url, 'expect' => 'noindex' ) ) : array(),
				)
			);
		}

		/* 6. Taxonomies (thin tag archives) + empty terms. */
		foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $tax ) {
			if ( in_array( $tax->name, array( 'post_format', 'product_shipping_class' ), true ) ) {
				continue;
			}
			$total = wp_count_terms( array( 'taxonomy' => $tax->name, 'hide_empty' => false ) );
			$total = is_wp_error( $total ) ? 0 : (int) $total;
			if ( ! $total ) {
				continue;
			}
			$thin  = (int) $this->plugin->db->wpdb->get_var( $this->plugin->db->wpdb->prepare( "SELECT COUNT(*) FROM {$this->plugin->db->wpdb->term_taxonomy} WHERE taxonomy = %s AND count <= 1", $tax->name ) ); // phpcs:ignore WordPress.DB
			$terms = get_terms( array( 'taxonomy' => $tax->name, 'hide_empty' => true, 'number' => 1, 'orderby' => 'count', 'order' => 'ASC' ) );
			if ( ! $terms || is_wp_error( $terms ) ) {
				continue;
			}
			// Taxonomies registered as public without a front-end address return a WP_Error here.
			$link = get_term_link( $terms[0] );
			if ( is_wp_error( $link ) || ! $link ) {
				continue;
			}
			$url   = (string) $link;
			$state = $this->probe( $url, $sitemap );
			$inventory[] = array( 'group' => 'taxonomy', 'name' => $tax->name, 'label' => $tax->labels->name, 'source' => ! empty( $tax->_builtin ) ? 'WordPress' : __( 'a plugin or the theme', 'seolity' ), 'count' => $total, 'url' => $url, 'intended' => 'post_tag' !== $tax->name && 'product_tag' !== $tax->name, 'state' => $state, 'thin' => $thin );
			$tagish = in_array( $tax->name, array( 'post_tag', 'product_tag' ), true );
			if ( $tagish && $state['indexable'] && $thin >= max( 5, $total / 2 ) ) {
				$findings[] = $this->finding(
					'tax:' . $tax->name,
					array(
						'url'      => $url,
						'type'     => $tax->labels->name,
						'source'   => 'WordPress',
						'count'    => $total,
						'state'    => $state,
						'severity' => 'medium',
						/* translators: 1 thin 2 total */
						'issue'    => sprintf( __( '%1$d of %2$d tags have one post or none, and all are indexable.', 'seolity' ), $thin, $total ),
						'reason'   => __( 'Tag pages with one post are near-duplicates of that post; many of them waste crawl budget.', 'seolity' ),
						'solution' => __( 'Noindex tag archives and remove them from the sitemap (categories stay indexable). Tags keep working for visitors.', 'seolity' ),
						'edit'     => $rm ? admin_url( 'admin.php?page=rank-math-options-titles#setting-panel-taxonomy-' . $tax->name ) : admin_url( 'edit-tags.php?taxonomy=' . $tax->name ),
						'items'    => $rm ? array(
							array( 'kind' => 'setting', 'option' => 'rank-math-options-titles', 'path' => 'tax_' . $tax->name . '_custom_robots', 'after' => 'on', 'label' => sprintf( /* translators: %s: taxonomy */ __( 'Rank Math: use custom robots for “%s”', 'seolity' ), $tax->labels->name ) ),
							array( 'kind' => 'setting', 'option' => 'rank-math-options-titles', 'path' => 'tax_' . $tax->name . '_robots', 'after' => array( 'noindex' ), 'label' => sprintf( /* translators: %s: taxonomy */ __( 'Rank Math: noindex “%s” archives', 'seolity' ), $tax->labels->name ), 'check_url' => $url, 'expect' => 'noindex' ),
							array( 'kind' => 'setting', 'option' => 'rank-math-options-sitemap', 'path' => 'tax_' . $tax->name . '_sitemap', 'after' => 'off', 'label' => sprintf( /* translators: %s: taxonomy */ __( 'Rank Math: remove “%s” from the sitemap', 'seolity' ), $tax->labels->name ) ),
						) : array(),
					)
				);
			}
		}
		if ( $rm && ! in_array( self::rm_opt( 'titles', 'noindex_empty_taxonomies', 'off' ), array( 'on', true, 1, '1' ), true ) ) {
			$empty = (int) $this->plugin->db->wpdb->get_var( "SELECT COUNT(*) FROM {$this->plugin->db->wpdb->term_taxonomy} WHERE count = 0 AND taxonomy IN ('category','post_tag','product_cat','product_tag')" ); // phpcs:ignore WordPress.DB
			if ( $empty ) {
				$findings[] = $this->finding(
					'empty_tax',
					array(
						'url'      => '',
						'type'     => __( 'Empty categories / tags', 'seolity' ),
						'source'   => 'WordPress',
						'count'    => $empty,
						'state'    => array(),
						'severity' => 'low',
						/* translators: %d: terms */
						'issue'    => sprintf( __( '%d categories / tags have no posts and are indexable.', 'seolity' ), $empty ),
						'reason'   => __( 'Empty archive pages are soft-404s for Google.', 'seolity' ),
						'solution' => __( 'Noindex empty categories and tags automatically.', 'seolity' ),
						'edit'     => admin_url( 'admin.php?page=rank-math-options-titles#setting-panel-global' ),
						'items'    => array( array( 'kind' => 'setting', 'option' => 'rank-math-options-titles', 'path' => 'noindex_empty_taxonomies', 'after' => 'on', 'label' => __( 'Rank Math: noindex empty taxonomies', 'seolity' ) ) ),
					)
				);
			}
		}

		/* 7. Parameter URLs found by the crawler that are indexable. */
		$params = $this->plugin->db->rows( "SELECT url, inlinks FROM {urls} WHERE project_id = %d AND url LIKE %s AND status_code = 200 AND indexable = 1 ORDER BY inlinks DESC LIMIT 15", array( $this->plugin->db->project_id(), '%?%' ) );
		foreach ( $params as $p ) {
			if ( preg_match( '/[?&](s|p|page_id|preview)=/', $p['url'] ) ) {
				continue;
			}
			$state = $this->probe( $p['url'], $sitemap );
			if ( ! $state['indexable'] ) {
				continue;
			}
			$findings[] = $this->finding(
				'param:' . md5( $p['url'] ),
				array(
					'url'      => $p['url'],
					'type'     => __( 'URL with parameters', 'seolity' ),
					'source'   => __( 'internal links / a plugin', 'seolity' ),
					'state'    => $state,
					'severity' => 'low',
					'issue'    => __( 'A parameter URL is indexable and has no canonical to the clean URL.', 'seolity' ),
					/* translators: %d: links */
					'reason'   => sprintf( __( 'Duplicate versions of the same page split signals. %d internal links point to it.', 'seolity' ), (int) $p['inlinks'] ),
					'solution' => __( 'Change the links that generate this URL, or make the page output a canonical to the clean URL. Blocking parameters in robots.txt is only right once no indexed version is left.', 'seolity' ),
					'edit'     => '',
					'items'    => array(),
				)
			);
		}

		/* 8. Feeds and pagination: reported, not flagged (normally fine). */
		foreach ( array( array( 'feed', __( 'RSS feed', 'seolity' ), get_feed_link() ), array( 'paged', __( 'Paginated archives', 'seolity' ), home_url( '/page/2/' ) ) ) as $g ) {
			$state       = $this->probe( $g[2], $sitemap );
			$inventory[] = array( 'group' => $g[0], 'name' => $g[0], 'label' => $g[1], 'source' => 'WordPress', 'count' => null, 'url' => $g[2], 'intended' => 'paged' === $g[0], 'state' => $state, 'note' => 'paged' === $g[0] ? __( 'Paginated pages should normally stay indexable (Google uses them to find older posts).', 'seolity' ) : __( 'Feeds are not shown as pages in Google; nothing to do.', 'seolity' ) );
		}

		$out = array( 'at' => gmdate( 'c' ), 'engine' => $this->plugin->meta->engine(), 'rankmath' => $rm, 'gsc' => $this->plugin->gsc_oauth->is_connected(), 'findings' => $findings, 'inventory' => $inventory );
		update_option( self::OPTION, $out, false );
		$this->sync_issues( $findings );
		$this->plugin->audit_log->log( 'indexability.scanned', 'site', null, array( 'findings' => count( $findings ) ) );
		return $out;
	}

	private function finding( $key, array $f ) {
		$f['key']     = $key;
		$f['fixable'] = ! empty( $f['items'] );
		return $f;
	}

	/** Every finding is a canonical issue (category "indexability"); fixed ones resolve. */
	private function sync_issues( array $findings ) {
		$rec  = $this->plugin->recommendations;
		$seen = array();
		$sev  = array( 'high' => 75, 'medium' => 50, 'low' => 25 );
		foreach ( $findings as $f ) {
			$seen[] = $rec->add(
				array(
					'type'         => 'index_waste',
					'key'          => $f['key'],
					'url'          => $f['url'],
					'title'        => $f['type'] . ': ' . $f['issue'],
					'reason'       => $f['reason'] . ' ' . $f['solution'],
					'evidence'     => array( 'source' => 'indexability', 'finding' => $f['key'], 'state' => $f['state'] ),
					'action'       => array( 'type' => $f['fixable'] ? 'indexability_fix' : 'route', 'finding' => $f['key'], 'route' => '#/indexability' ),
					'impact'       => $sev[ $f['severity'] ],
					'effort'       => $f['fixable'] ? 5 : 30,
					'confidence'   => 80,
					'priority'     => 'high' === $f['severity'] ? 'high' : ( 'medium' === $f['severity'] ? 'medium' : 'low' ),
					'auto_fixable' => $f['fixable'],
				)
			);
		}
		$db = $this->plugin->db;
		$in = $seen ? implode( ',', array_map( 'intval', $seen ) ) : '0';
		$db->query( "UPDATE {recommendations} SET status = 'resolved', resolved_at = %s WHERE project_id = %d AND type = 'index_waste' AND status IN ('open','stale') AND id NOT IN ($in)", array( Database::now(), $db->project_id() ) ); // phpcs:ignore WordPress.DB
	}

	/** Prepare the fix of one finding as an operation (preview → approval). */
	public function prepare_fix( $key ) {
		$last = $this->last();
		if ( ! $last ) {
			return new \WP_Error( 'seolity_idx', __( 'Run the audit first.', 'seolity' ), array( 'status' => 400 ) );
		}
		foreach ( $last['findings'] as $f ) {
			if ( $f['key'] === $key ) {
				if ( empty( $f['items'] ) ) {
					return new \WP_Error( 'seolity_idx', __( 'This finding needs a manual change; see the suggested solution.', 'seolity' ), array( 'status' => 400 ) );
				}
				$issue = (int) $this->plugin->db->value( "SELECT id FROM {recommendations} WHERE project_id = %d AND dedupe_key = %s", array( $this->plugin->db->project_id(), sha1( 'index_waste|' . $key ) ) );
				$items = array();
				foreach ( $f['items'] as $i => $it ) {
					$it['key'] = 's' . $i;
					$items[]   = $it;
				}
				$id = $this->plugin->operations->create( 'indexability', array( 'issue_id' => $issue, 'title' => $f['type'] . ': ' . $f['issue'], 'input' => array( 'finding' => $key ), 'plan' => array( $f['solution'] ) ) );
				return $this->plugin->operations->staged( $id, $items, array( $f['solution'] ) );
			}
		}
		return new \WP_Error( 'seolity_idx', __( 'Finding not found — run the audit again.', 'seolity' ), array( 'status' => 404 ) );
	}
}
