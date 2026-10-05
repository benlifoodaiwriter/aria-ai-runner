<?php
/**
 * <head> output: title, meta description, robots, canonical, Open Graph,
 * Twitter Cards and JSON-LD — only when SEOlity owns SEO output (no other SEO
 * plugin active, or forced on). FAQ schema is always output for pages that
 * have SEOlity FAQs, because other plugins don't know about them.
 *
 * @package SEOlity
 */

namespace SEOlity\Frontend;

use SEOlity\Analysis\Text;
use SEOlity\Plugin;

defined( 'ABSPATH' ) || exit;

final class Head {

	/** @var Plugin */
	private $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	public function register() {
		if ( is_feed() || is_robots() || is_trackback() ) {
			return;
		}
		if ( $this->plugin->meta->outputs_head() ) {
			add_filter( 'pre_get_document_title', array( $this, 'title' ), 20 );
			add_filter( 'wp_robots', array( $this, 'robots' ), 20 );
			remove_action( 'wp_head', 'rel_canonical' );
			add_action( 'wp_head', array( $this, 'meta_tags' ), 1 );
		}
		if ( $this->plugin->settings->get( 'schema_enabled' ) ) {
			add_action( 'wp_head', array( $this, 'schema' ), 20 );
		}
	}

	private function object() {
		if ( is_singular() ) {
			return array( 'post', get_queried_object_id() );
		}
		if ( is_category() || is_tag() || is_tax() ) {
			return array( 'term', get_queried_object_id() );
		}
		return array( null, 0 );
	}

	private function meta_value( $field ) {
		list( $type, $id ) = $this->object();
		if ( 'post' === $type ) {
			return $this->plugin->meta->get_post( $id, $field );
		}
		if ( 'term' === $type ) {
			return $this->plugin->meta->get_term( $id, $field );
		}
		return '';
	}

	public function title( $title ) {
		$custom = $this->meta_value( 'meta_title' );
		if ( '' !== (string) $custom ) {
			return wp_strip_all_tags( $this->replace_vars( $custom ) );
		}
		return $title;
	}

	private function replace_vars( $text ) {
		return strtr(
			$text,
			array(
				'%sitename%' => get_bloginfo( 'name' ),
				'%sep%'      => $this->plugin->settings->get( 'title_separator' ),
				'%year%'     => wp_date( 'Y' ),
				'%title%'    => is_singular() ? get_the_title() : single_term_title( '', false ),
			)
		);
	}

	public function description() {
		$desc = (string) $this->meta_value( 'meta_description' );
		if ( '' !== $desc ) {
			return $this->replace_vars( $desc );
		}
		if ( is_singular() ) {
			$post = get_queried_object();
			$src  = $post->post_excerpt ? $post->post_excerpt : $post->post_content;
			return Text::truncate( Text::strip( strip_shortcodes( $src ) ), 155 );
		}
		if ( is_category() || is_tag() || is_tax() ) {
			return Text::truncate( Text::strip( term_description() ), 155 );
		}
		if ( is_front_page() ) {
			return get_bloginfo( 'description' );
		}
		return '';
	}

	public function robots( array $robots ) {
		if ( '1' === $this->meta_value( 'noindex' ) ) {
			$robots['noindex'] = true;
			unset( $robots['max-image-preview'] );
		}
		if ( '1' === $this->meta_value( 'nofollow' ) ) {
			$robots['nofollow'] = true;
		}
		if ( is_search() || ( is_paged() && is_search() ) ) {
			$robots['noindex'] = true;
		}
		if ( empty( $robots['noindex'] ) ) {
			$robots['max-image-preview'] = 'large';
			$robots['max-snippet']       = '-1';
		}
		return $robots;
	}

	public function canonical() {
		$custom = $this->meta_value( 'canonical' );
		if ( $custom ) {
			return $custom;
		}
		if ( is_singular() ) {
			return wp_get_canonical_url();
		}
		if ( is_category() || is_tag() || is_tax() ) {
			$link = get_term_link( get_queried_object() );
			$page = get_query_var( 'paged' );
			return is_wp_error( $link ) ? '' : ( $page > 1 ? trailingslashit( $link ) . 'page/' . (int) $page . '/' : $link );
		}
		if ( is_front_page() ) {
			return home_url( '/' );
		}
		return '';
	}

	public function meta_tags() {
		$desc      = $this->description();
		$canonical = $this->canonical();
		$title     = wp_get_document_title();
		echo "\n<!-- SEOlity -->\n";
		if ( '' !== $desc ) {
			echo '<meta name="description" content="' . esc_attr( $desc ) . '">' . "\n";
		}
		if ( $canonical && '1' !== $this->meta_value( 'noindex' ) ) {
			echo '<link rel="canonical" href="' . esc_url( $canonical ) . '">' . "\n";
		}
		$og = array(
			'og:locale'    => get_locale(),
			'og:site_name' => get_bloginfo( 'name' ),
			'og:title'     => $this->meta_value( 'social_title' ) ? $this->meta_value( 'social_title' ) : $title,
			'og:description' => $this->meta_value( 'social_description' ) ? $this->meta_value( 'social_description' ) : $desc,
			'og:url'       => $canonical,
			'og:type'      => is_singular( 'post' ) ? 'article' : ( is_singular( 'product' ) ? 'product' : 'website' ),
		);
		$image = '';
		if ( is_singular() && has_post_thumbnail() ) {
			$image = get_the_post_thumbnail_url( null, 'large' );
		}
		if ( ! $image && $this->plugin->settings->get( 'org_logo' ) ) {
			$image = $this->plugin->settings->get( 'org_logo' );
		}
		if ( $image ) {
			$og['og:image'] = $image;
		}
		if ( is_singular( 'post' ) ) {
			$og['article:published_time'] = get_the_date( 'c' );
			$og['article:modified_time']  = get_the_modified_date( 'c' );
		}
		foreach ( $og as $prop => $val ) {
			if ( '' !== (string) $val ) {
				echo '<meta property="' . esc_attr( $prop ) . '" content="' . esc_attr( 'og:url' === $prop || 'og:image' === $prop ? esc_url( $val ) : $val ) . '">' . "\n";
			}
		}
		echo '<meta name="twitter:card" content="' . esc_attr( $image ? 'summary_large_image' : 'summary' ) . '">' . "\n";
		echo '<meta name="twitter:title" content="' . esc_attr( $title ) . '">' . "\n";
		if ( '' !== $desc ) {
			echo '<meta name="twitter:description" content="' . esc_attr( $desc ) . '">' . "\n";
		}
		if ( $image ) {
			echo '<meta name="twitter:image" content="' . esc_url( $image ) . '">' . "\n";
		}
		echo "<!-- /SEOlity -->\n";
	}

	public function schema() {
		$blocks = array();
		if ( $this->plugin->meta->outputs_head() ) {
			$blocks[] = array( '@context' => 'https://schema.org', '@graph' => $this->plugin->schema->graph() );
		}
		if ( is_singular() ) {
			$faq = $this->plugin->schema->faq_schema( get_queried_object_id() );
			if ( $faq ) {
				$blocks[] = $faq;
			}
		}
		foreach ( $blocks as $b ) {
			echo '<script type="application/ld+json" class="seolity-schema">' . wp_json_encode( $b, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG ) . "</script>\n";
		}
	}
}
