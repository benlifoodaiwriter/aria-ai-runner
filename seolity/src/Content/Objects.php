<?php
/**
 * Uniform read/write access to every editable WordPress object field
 * (posts of any type incl. products & pages, terms, users, attachments,
 * site-level settings) so changes, undo and bulk AI share one code path.
 *
 * Object types: post | term | user | attachment | site
 *
 * @package SEOlity
 */

namespace SEOlity\Content;

use SEOlity\Plugin;

defined( 'ABSPATH' ) || exit;

final class Objects {

	/** Field => risk level (drives Semi-automatic / Autonomous approval). */
	const RISK = array(
		'alt'              => 'low',
		'image_title'      => 'low',
		'caption'          => 'low',
		'meta_description' => 'low',
		'focus_keyword'    => 'low',
		'focus_keywords'   => 'low',
		'pillar_content'   => 'low',
		'social_title'     => 'low',
		'social_description' => 'low',
		'meta_title'       => 'medium',
		'post_excerpt'     => 'medium',
		'faq_schema'       => 'medium',
		'term_description' => 'medium',
		'user_description' => 'medium',
		'product_tags'     => 'medium',
		'post_content'     => 'high',
		'post_title'       => 'high',
		'term_name'        => 'high',
		'post_name'        => 'high',
		'canonical'        => 'high',
		'noindex'          => 'high',
		'nofollow'         => 'high',
		'robots_txt'       => 'high',
	);

	const FIELDS_BY_TYPE = array(
		'post'       => array( 'post_title', 'post_content', 'post_excerpt', 'post_name', 'meta_title', 'meta_description', 'focus_keyword', 'focus_keywords', 'pillar_content', 'social_title', 'social_description', 'canonical', 'noindex', 'nofollow', 'faq_schema', 'product_tags' ),
		'term'       => array( 'term_name', 'term_description', 'meta_title', 'meta_description', 'focus_keyword', 'focus_keywords', 'social_title', 'social_description', 'canonical', 'noindex' ),
		'user'       => array( 'user_description' ),
		'attachment' => array( 'alt', 'image_title', 'caption' ),
		'site'       => array( 'robots_txt' ),
	);

	public static function risk( $field ) {
		return isset( self::RISK[ $field ] ) ? self::RISK[ $field ] : 'high';
	}

	public static function supports( $type, $field ) {
		return isset( self::FIELDS_BY_TYPE[ $type ] ) && in_array( $field, self::FIELDS_BY_TYPE[ $type ], true );
	}

	/**
	 * Read a field value.
	 *
	 * @return string|null Null if the object does not exist.
	 */
	public static function read( Plugin $plugin, $type, $id, $field ) {
		$id = (int) $id;
		switch ( $type ) {
			case 'post':
				$post = get_post( $id );
				if ( ! $post ) {
					return null;
				}
				if ( in_array( $field, array( 'post_title', 'post_content', 'post_excerpt', 'post_name' ), true ) ) {
					return (string) $post->$field;
				}
				if ( 'faq_schema' === $field ) {
					$faq = get_post_meta( $id, '_seolity_faq', true );
					return $faq ? wp_json_encode( $faq, JSON_UNESCAPED_UNICODE ) : '';
				}
				if ( 'product_tags' === $field ) {
					$terms = wp_get_post_terms( $id, 'product_tag', array( 'fields' => 'names' ) );
					return is_wp_error( $terms ) ? '' : implode( ', ', $terms );
				}
				return in_array( $field, MetaStore::FIELDS, true ) ? $plugin->meta->get_post( $id, $field ) : null;
			case 'term':
				$term = get_term( $id );
				if ( ! $term || is_wp_error( $term ) ) {
					return null;
				}
				if ( 'term_name' === $field ) {
					return $term->name;
				}
				if ( 'term_description' === $field ) {
					return $term->description;
				}
				return in_array( $field, MetaStore::FIELDS, true ) ? $plugin->meta->get_term( $id, $field ) : null;
			case 'user':
				$user = get_userdata( $id );
				return $user ? (string) $user->description : null;
			case 'attachment':
				$post = get_post( $id );
				if ( ! $post || 'attachment' !== $post->post_type ) {
					return null;
				}
				if ( 'alt' === $field ) {
					return (string) get_post_meta( $id, '_wp_attachment_image_alt', true );
				}
				return 'caption' === $field ? (string) $post->post_excerpt : (string) $post->post_title;
			case 'site':
				return 'robots_txt' === $field ? (string) $plugin->settings->get( 'robots_custom' ) : null;
		}
		return null;
	}

	/**
	 * Write a field value. Caller must have checked capabilities.
	 *
	 * @return true|\WP_Error
	 */
	public static function write( Plugin $plugin, $type, $id, $field, $value ) {
		$id = (int) $id;
		if ( ! self::supports( $type, $field ) ) {
			return new \WP_Error( 'seolity_field', sprintf( 'Field %s is not supported for %s.', $field, $type ) );
		}
		$value = (string) $value;
		switch ( $type ) {
			case 'post':
				if ( in_array( $field, array( 'post_title', 'post_content', 'post_excerpt', 'post_name' ), true ) ) {
					$old_url = get_permalink( $id );
					$data    = array( 'ID' => $id );
					if ( 'post_content' === $field || 'post_excerpt' === $field ) {
						$data[ $field ] = current_user_can( 'unfiltered_html' ) ? $value : wp_kses_post( $value );
					} elseif ( 'post_name' === $field ) {
						$data[ $field ] = sanitize_title( $value );
					} else {
						$data[ $field ] = sanitize_text_field( $value );
					}
					$res = wp_update_post( wp_slash( $data ), true );
					if ( is_wp_error( $res ) ) {
						return $res;
					}
					if ( 'post_name' === $field ) {
						$new_url = get_permalink( $id );
						if ( $old_url && $new_url && $old_url !== $new_url && 'publish' === get_post_status( $id ) ) {
							$plugin->redirects->create( wp_make_link_relative( $old_url ), $new_url, 301, false, 'slug_change' );
						}
					}
					if ( function_exists( 'wc_delete_product_transients' ) && 'product' === get_post_type( $id ) ) {
						wc_delete_product_transients( $id );
					}
					return true;
				}
				if ( 'faq_schema' === $field ) {
					$faq = json_decode( $value, true );
					if ( '' !== $value && ! is_array( $faq ) ) {
						return new \WP_Error( 'seolity_faq', __( 'FAQ must be valid JSON.', 'seolity' ) );
					}
					$clean = array();
					foreach ( (array) $faq as $row ) {
						if ( ! empty( $row['question'] ) && ! empty( $row['answer'] ) ) {
							$clean[] = array(
								'question' => sanitize_text_field( $row['question'] ),
								'answer'   => wp_kses_post( $row['answer'] ),
							);
						}
					}
					$clean ? update_post_meta( $id, '_seolity_faq', $clean ) : delete_post_meta( $id, '_seolity_faq' );
					return true;
				}
				if ( 'product_tags' === $field ) {
					$tags = array_filter( array_map( 'sanitize_text_field', array_map( 'trim', explode( ',', $value ) ) ) );
					$res  = wp_set_post_terms( $id, $tags, 'product_tag', false );
					return is_wp_error( $res ) ? $res : true;
				}
				$plugin->meta->set_post( $id, $field, $value );
				return true;
			case 'term':
				$term = get_term( $id );
				if ( ! $term || is_wp_error( $term ) ) {
					return new \WP_Error( 'seolity_term', __( 'Term not found.', 'seolity' ) );
				}
				if ( 'term_name' === $field || 'term_description' === $field ) {
					$args = 'term_name' === $field ? array( 'name' => sanitize_text_field( $value ) ) : array( 'description' => wp_kses_post( $value ) );
					$res  = wp_update_term( $id, $term->taxonomy, $args );
					return is_wp_error( $res ) ? $res : true;
				}
				$plugin->meta->set_term( $id, $field, $value );
				return true;
			case 'user':
				$res = wp_update_user( array( 'ID' => $id, 'description' => wp_kses_post( $value ) ) );
				return is_wp_error( $res ) ? $res : true;
			case 'attachment':
				if ( 'alt' === $field ) {
					update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $value ) );
					return true;
				}
				$res = wp_update_post( array( 'ID' => $id, ( 'caption' === $field ? 'post_excerpt' : 'post_title' ) => sanitize_text_field( $value ) ), true );
				return is_wp_error( $res ) ? $res : true;
			case 'site':
				$plugin->settings->update( array( 'robots_custom' => $value, 'robots_override' => '' !== trim( $value ) ) );
				return true;
		}
		return new \WP_Error( 'seolity_type', 'Unknown object type' );
	}

	/** Public URL for an object (null if none). */
	public static function url( $type, $id ) {
		switch ( $type ) {
			case 'post':
				$url = get_permalink( (int) $id );
				return $url ? $url : null;
			case 'term':
				$url = get_term_link( (int) $id );
				return is_wp_error( $url ) ? null : $url;
			case 'user':
				return get_author_posts_url( (int) $id );
			case 'attachment':
				$url = wp_get_attachment_url( (int) $id );
				return $url ? $url : null;
		}
		return null;
	}

	/** Human label for lists. */
	public static function label( $type, $id ) {
		switch ( $type ) {
			case 'post':
			case 'attachment':
				return get_the_title( (int) $id );
			case 'term':
				$t = get_term( (int) $id );
				return ( $t && ! is_wp_error( $t ) ) ? $t->name : '';
			case 'user':
				$u = get_userdata( (int) $id );
				return $u ? $u->display_name : '';
			case 'site':
				return get_bloginfo( 'name' );
		}
		return '';
	}

	/** Gather the full SEO context of an object for analysis / AI prompts. */
	public static function context( Plugin $plugin, $type, $id ) {
		$id = (int) $id;
		if ( 'post' === $type ) {
			$post = get_post( $id );
			if ( ! $post ) {
				return null;
			}
			return array(
				'object_type'      => 'product' === $post->post_type ? 'product' : ( 'page' === $post->post_type ? 'page' : 'post' ),
				'post_type'        => $post->post_type,
				'title'            => $post->post_title,
				'content'          => self::rendered_content( $post ),
				'excerpt'          => $post->post_excerpt,
				'slug'             => $post->post_name,
				'url'              => get_permalink( $post ),
				'meta_title'       => $plugin->meta->get_post( $id, 'meta_title' ),
				'meta_description' => $plugin->meta->get_post( $id, 'meta_description' ),
				'focus_keyword'    => $plugin->meta->get_post( $id, 'focus_keyword' ),
				'modified'         => $post->post_modified_gmt,
			);
		}
		if ( 'term' === $type ) {
			$term = get_term( $id );
			if ( ! $term || is_wp_error( $term ) ) {
				return null;
			}
			$link = get_term_link( $term );
			return array(
				'object_type'      => 'term',
				'taxonomy'         => $term->taxonomy,
				'title'            => $term->name,
				'content'          => $term->description,
				'excerpt'          => '',
				'slug'             => $term->slug,
				'url'              => is_wp_error( $link ) ? '' : $link,
				'meta_title'       => $plugin->meta->get_term( $id, 'meta_title' ),
				'meta_description' => $plugin->meta->get_term( $id, 'meta_description' ),
				'focus_keyword'    => $plugin->meta->get_term( $id, 'focus_keyword' ),
				'count'            => $term->count,
			);
		}
		if ( 'user' === $type ) {
			$user = get_userdata( $id );
			return $user ? array(
				'object_type' => 'user',
				'title'       => $user->display_name,
				'content'     => $user->description,
				'url'         => get_author_posts_url( $id ),
				'slug'        => $user->user_nicename,
			) : null;
		}
		return null;
	}

	/**
	 * Content as seen by users (Elementor / page builders store content elsewhere).
	 */
	public static function rendered_content( \WP_Post $post ) {
		if ( class_exists( '\Elementor\Plugin' ) && get_post_meta( $post->ID, '_elementor_edit_mode', true ) === 'builder' ) {
			try {
				$html = \Elementor\Plugin::$instance->frontend->get_builder_content( $post->ID, false );
				if ( $html ) {
					return $html;
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}
		return (string) $post->post_content;
	}
}
