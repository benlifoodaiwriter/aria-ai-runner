<?php
/**
 * AI Product SEO for WooCommerce (section 3).
 *
 * Builds a complete, fact-grounded product context (attributes, price, stock,
 * categories, images, reviews, GSC queries, similar products, competitor
 * pages) and produces an optimization package: title, short/long description,
 * meta, keywords (focus/secondary/long-tail/semantic), intent, FAQ, CTA,
 * internal links, ALT texts, tags/categories, missing attributes, schema and
 * cannibalization warnings — every write is a reviewable pending change.
 *
 * @package SEOlity
 */

namespace SEOlity\Woo;

use SEOlity\AI\Guardrails;
use SEOlity\Analysis\Text;
use SEOlity\Plugin;

defined( 'ABSPATH' ) || exit;

final class ProductSeo {

	const DEFAULT_FIELDS = array( 'post_title', 'post_excerpt', 'post_content', 'meta_title', 'meta_description', 'focus_keyword', 'faq_schema', 'product_tags', 'image_alts' );

	/** @var Plugin */
	private $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	public function available() {
		return class_exists( 'WooCommerce' ) && function_exists( 'wc_get_product' );
	}

	public function context( $id ) {
		$product = $this->available() ? wc_get_product( (int) $id ) : null;
		if ( ! $product ) {
			return null;
		}
		$attrs = array();
		foreach ( $product->get_attributes() as $key => $attr ) {
			if ( is_object( $attr ) ) {
				$attrs[ wc_attribute_label( $attr->get_name() ) ] = $attr->is_taxonomy() ? wc_get_product_terms( $product->get_id(), $attr->get_name(), array( 'fields' => 'names' ) ) : $attr->get_options();
			} else {
				$attrs[ $key ] = $attr;
			}
		}
		$images = array();
		foreach ( array_merge( array( $product->get_image_id() ), $product->get_gallery_image_ids() ) as $img ) {
			if ( $img ) {
				$images[] = array( 'image_id' => (int) $img, 'alt' => (string) get_post_meta( $img, '_wp_attachment_image_alt', true ), 'filename' => basename( (string) get_attached_file( $img ) ) );
			}
		}
		$cat_ids = $product->get_category_ids();
		$similar = array();
		if ( $cat_ids ) {
			foreach ( wc_get_products( array( 'category' => array_map( static function ( $c ) {
				$t = get_term( $c, 'product_cat' );
				return $t && ! is_wp_error( $t ) ? $t->slug : '';
			}, $cat_ids ), 'limit' => 25, 'exclude' => array( $product->get_id() ), 'status' => 'publish' ) ) as $p ) {
				$similar[] = array( 'id' => $p->get_id(), 'name' => $p->get_name(), 'url' => $p->get_permalink(), 'similarity' => round( Text::similarity( $product->get_name(), $p->get_name() ), 2 ) );
			}
			usort( $similar, static function ( $a, $b ) {
				return $b['similarity'] <=> $a['similarity'];
			} );
		}
		$url     = $product->get_permalink();
		$queries = $this->plugin->gsc_analytics->has_data() ? $this->plugin->gsc_analytics->page_queries( $url, 90, 20 ) : array();
		$comp    = array();
		$words   = array_slice( Text::words( $product->get_name() ), 0, 3 );
		if ( $words ) {
			$comp = $this->plugin->db->rows( 'SELECT url, title, word_count, faq_count FROM {competitor_pages} WHERE status_code = 200 AND title LIKE %s ORDER BY word_count DESC LIMIT 5', array( '%' . $this->plugin->db->wpdb->esc_like( implode( ' ', $words ) ) . '%' ) );
		}
		return array(
			'id'                => $product->get_id(),
			'name'              => $product->get_name(),
			'type'              => $product->get_type(),
			'sku'               => $product->get_sku(),
			'price'             => $product->get_price(),
			'regular_price'     => $product->get_regular_price(),
			'sale_price'        => $product->get_sale_price(),
			'currency'          => get_woocommerce_currency(),
			'stock_status'      => $product->get_stock_status(),
			'categories'        => wp_get_post_terms( $product->get_id(), 'product_cat', array( 'fields' => 'names' ) ),
			'tags'              => wp_get_post_terms( $product->get_id(), 'product_tag', array( 'fields' => 'names' ) ),
			'all_categories'    => array_slice( get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'fields' => 'names', 'number' => 100 ) ), 0, 100 ),
			'attributes'        => $attrs,
			'weight'            => $product->get_weight(),
			'dimensions'        => wc_format_dimensions( $product->get_dimensions( false ) ),
			'short_description' => $product->get_short_description(),
			'description'       => $product->get_description(),
			'images'            => $images,
			'rating'            => array( 'average' => $product->get_average_rating(), 'count' => $product->get_review_count() ),
			'upsells'           => array_map( 'get_the_title', $product->get_upsell_ids() ),
			'cross_sells'       => array_map( 'get_the_title', $product->get_cross_sell_ids() ),
			'similar_products'  => array_slice( $similar, 0, 10 ),
			'url'               => $url,
			'meta_title'        => $this->plugin->meta->get_post( $product->get_id(), 'meta_title' ),
			'meta_description'  => $this->plugin->meta->get_post( $product->get_id(), 'meta_description' ),
			'focus_keyword'     => $this->plugin->meta->get_post( $product->get_id(), 'focus_keyword' ),
			'gsc_queries'       => $queries,
			'competitor_pages'  => $comp,
		);
	}

	/**
	 * Run the AI Product Optimizer.
	 *
	 * @param array $opts fields[] to propose, batch_id.
	 */
	public function optimize( $id, array $opts = array() ) {
		$ctx = $this->context( $id );
		if ( ! $ctx ) {
			return new \WP_Error( 'seolity_product', __( 'Product not found (is WooCommerce active?).', 'seolity' ), array( 'status' => 404 ) );
		}
		$res = $this->plugin->ai->run( 'product_seo', $ctx, array( 'knowledge_query' => 'ecommerce product page ' . $ctx['name'] ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$ai     = $res['data'];
		$fields = isset( $opts['fields'] ) ? (array) $opts['fields'] : self::DEFAULT_FIELDS;
		$batch  = isset( $opts['batch_id'] ) ? $opts['batch_id'] : 'product_' . wp_generate_password( 10, false, false );
		$desc   = Guardrails::clean_html( $ai['description'] );
		$short  = Guardrails::clean_html( $ai['short_description'] );
		$terms  = array( 'secondary_keywords' => $ai['secondary_keywords'], 'semantic_terms' => array_merge( $ai['semantic_keywords'], $ai['long_tail_keywords'] ), 'intent' => $ai['search_intent'], 'object_type' => 'product' );
		$before = $this->plugin->analyzer->analyze( array_merge( $terms, array( 'content' => $ctx['description'] . ' ' . $ctx['short_description'], 'title' => $ctx['name'], 'meta_title' => $ctx['meta_title'], 'meta_description' => $ctx['meta_description'], 'focus_keyword' => $ai['focus_keyword'] ) ) );
		$after  = $this->plugin->analyzer->analyze( array_merge( $terms, array( 'content' => $desc . ' ' . $short, 'title' => $ai['title'], 'meta_title' => $ai['meta_title'], 'meta_description' => $ai['meta_description'], 'focus_keyword' => $ai['focus_keyword'] ) ) );
		$values = array(
			'post_title'       => $ai['title'],
			'post_excerpt'     => $short,
			'post_content'     => $desc,
			'meta_title'       => $ai['meta_title'],
			'meta_description' => $ai['meta_description'],
			'focus_keyword'    => $ai['focus_keyword'],
			'faq_schema'       => $ai['faq'] ? wp_json_encode( $ai['faq'], JSON_UNESCAPED_UNICODE ) : '',
			'product_tags'     => implode( ', ', array_unique( array_merge( (array) $ctx['tags'], array_slice( $ai['suggested_tags'], 0, 8 ) ) ) ),
		);
		$changes = array();
		foreach ( $values as $field => $value ) {
			if ( ! in_array( $field, $fields, true ) || '' === trim( (string) $value ) ) {
				continue;
			}
			$cid = $this->plugin->changes->propose(
				array(
					'object_type'  => 'post',
					'object_id'    => (int) $id,
					'field'        => $field,
					'after'        => $value,
					'reason'       => $ai['summary'],
					'ai'           => $res['meta'],
					'keywords'     => array_merge( array( $ai['focus_keyword'] ), $ai['secondary_keywords'] ),
					'score_before' => $before['score'],
					'score_after'  => $after['score'],
					'batch_id'     => $batch,
				)
			);
			if ( ! is_wp_error( $cid ) ) {
				$changes[ $field ] = $cid;
			}
		}
		if ( in_array( 'image_alts', $fields, true ) ) {
			$valid = wp_list_pluck( $ctx['images'], 'image_id' );
			foreach ( $ai['image_alts'] as $img ) {
				if ( ! in_array( (int) $img['image_id'], $valid, true ) ) {
					continue;
				}
				$cid = $this->plugin->changes->propose(
					array(
						'object_type'        => 'attachment',
						'object_id'          => (int) $img['image_id'],
						'field'              => 'alt',
						'after'              => sanitize_text_field( $img['alt'] ),
						'reason'             => __( 'ALT generated from product data.', 'seolity' ),
						'ai'                 => $res['meta'],
						'batch_id'           => $batch,
						'auto_apply_allowed' => true,
					)
				);
				if ( ! is_wp_error( $cid ) ) {
					$changes[ 'alt_' . $img['image_id'] ] = $cid;
				}
			}
		}
		$warnings = array_merge( Guardrails::inspect_content( $desc, $ctx['description'], $ai['focus_keyword'] ) );
		$cannibal = array_values( array_filter( $ctx['similar_products'], static function ( $s ) {
			return $s['similarity'] >= 0.75;
		} ) );
		if ( $cannibal ) {
			/* translators: %d */
			$warnings[] = sprintf( __( '%d very similar product(s) may compete for the same keywords — differentiate titles/descriptions or canonicalize variants.', 'seolity' ), count( $cannibal ) );
		}
		return array(
			'product'   => array_intersect_key( $ctx, array_flip( array( 'id', 'name', 'url', 'short_description', 'description', 'meta_title', 'meta_description', 'focus_keyword', 'images', 'tags', 'categories', 'gsc_queries' ) ) ),
			'ai'        => $ai,
			'before'    => $before,
			'after'     => $after,
			'changes'   => $changes,
			'batch_id'  => $batch,
			'warnings'  => $warnings,
			'cannibalization' => $cannibal,
			'meta'      => $res['meta'],
		);
	}

	public function query( array $f ) {
		if ( ! $this->available() ) {
			return array( 'items' => array(), 'total' => 0, 'woocommerce' => false );
		}
		$args = array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => isset( $f['per_page'] ) ? max( 1, min( 100, (int) $f['per_page'] ) ) : 30,
			'paged'          => isset( $f['page'] ) ? max( 1, (int) $f['page'] ) : 1,
			's'              => isset( $f['search'] ) ? sanitize_text_field( $f['search'] ) : '',
		);
		if ( ! empty( $f['filter'] ) && 'no_short' === $f['filter'] ) {
			add_filter( 'posts_where', array( $this, 'where_no_excerpt' ) );
		}
		$q = new \WP_Query( $args );
		remove_filter( 'posts_where', array( $this, 'where_no_excerpt' ) );
		$items = array();
		foreach ( $q->posts as $post ) {
			$score   = $this->plugin->db->value( "SELECT score FROM {content_analysis} WHERE object_type = 'post' AND object_id = %d", array( $post->ID ) );
			$product = wc_get_product( $post->ID );
			$img_ids = $product ? array_filter( array_merge( array( $product->get_image_id() ), $product->get_gallery_image_ids() ) ) : array();
			$no_alt  = 0;
			foreach ( $img_ids as $img ) {
				if ( '' === trim( (string) get_post_meta( $img, '_wp_attachment_image_alt', true ) ) ) {
					$no_alt++;
				}
			}
			$items[] = array(
				'id'               => $post->ID,
				'name'             => $post->post_title,
				'url'              => get_permalink( $post ),
				'thumb'            => get_the_post_thumbnail_url( $post, 'thumbnail' ),
				'score'            => null === $score ? null : (int) $score,
				'has_short'        => '' !== trim( $post->post_excerpt ),
				'desc_words'       => Text::word_count( Text::strip( $post->post_content ) ),
				'has_meta'         => '' !== (string) $this->plugin->meta->get_post( $post->ID, 'meta_description' ),
				'focus_keyword'    => $this->plugin->meta->get_post( $post->ID, 'focus_keyword' ),
				'images'           => count( $img_ids ),
				'images_no_alt'    => $no_alt,
				'pending_changes'  => (int) $this->plugin->db->value( "SELECT COUNT(*) FROM {changes} WHERE object_type = 'post' AND object_id = %d AND status = 'pending'", array( $post->ID ) ),
				'price'            => $product ? $product->get_price() : '',
				'stock'            => $product ? $product->get_stock_status() : '',
			);
		}
		return array( 'items' => $items, 'total' => (int) $q->found_posts, 'woocommerce' => true );
	}

	public function where_no_excerpt( $where ) {
		return $where . " AND post_excerpt = ''";
	}
}
