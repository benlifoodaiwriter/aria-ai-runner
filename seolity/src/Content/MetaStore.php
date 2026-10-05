<?php
/**
 * Reads/writes SEO meta (title, description, focus keyword, canonical, robots)
 * through whichever SEO plugin is active, so SEOlity can run alongside Yoast,
 * Rank Math, SEOPress or AIOSEO — or standalone with its own fields.
 *
 * @package SEOlity
 */

namespace SEOlity\Content;

use SEOlity\Plugin;

defined( 'ABSPATH' ) || exit;

final class MetaStore {

	const FIELDS = array( 'meta_title', 'meta_description', 'focus_keyword', 'canonical', 'noindex', 'nofollow', 'focus_keywords', 'pillar_content', 'social_title', 'social_description' );

	const OWN = array(
		'meta_title'       => '_seolity_title',
		'meta_description' => '_seolity_description',
		'focus_keyword'    => '_seolity_focus_keyword',
		'canonical'        => '_seolity_canonical',
		'noindex'          => '_seolity_noindex',
		'nofollow'         => '_seolity_nofollow',
		'focus_keywords'   => '_seolity_focus_keywords',
		'pillar_content'   => '_seolity_pillar',
		'social_title'     => '_seolity_social_title',
		'social_description' => '_seolity_social_description',
	);

	/**
	 * Extended fields per engine (posts & terms). focus_keywords is the full
	 * comma list (primary first); pillar_content is a flag.
	 */
	const EXTRA_KEYS = array(
		'rankmath' => array(
			'focus_keywords'     => 'rank_math_focus_keyword',
			'pillar_content'     => 'rank_math_pillar_content',
			'social_title'       => 'rank_math_facebook_title',
			'social_description' => 'rank_math_facebook_description',
		),
		'yoast'    => array(
			'pillar_content'     => '_yoast_wpseo_is_cornerstone',
			'social_title'       => '_yoast_wpseo_opengraph-title',
			'social_description' => '_yoast_wpseo_opengraph-description',
		),
		'seopress' => array(
			'focus_keywords'     => '_seopress_analysis_target_kw',
			'social_title'       => '_seopress_social_fb_title',
			'social_description' => '_seopress_social_fb_desc',
		),
	);

	const POST_KEYS = array(
		'yoast'    => array(
			'meta_title'       => '_yoast_wpseo_title',
			'meta_description' => '_yoast_wpseo_metadesc',
			'focus_keyword'    => '_yoast_wpseo_focuskw',
			'canonical'        => '_yoast_wpseo_canonical',
			'noindex'          => '_yoast_wpseo_meta-robots-noindex',
			'nofollow'         => '_yoast_wpseo_meta-robots-nofollow',
		),
		'rankmath' => array(
			'meta_title'       => 'rank_math_title',
			'meta_description' => 'rank_math_description',
			'focus_keyword'    => 'rank_math_focus_keyword',
			'canonical'        => 'rank_math_canonical_url',
		),
		'seopress' => array(
			'meta_title'       => '_seopress_titles_title',
			'meta_description' => '_seopress_titles_desc',
			'focus_keyword'    => '_seopress_analysis_target_kw',
			'canonical'        => '_seopress_robots_canonical',
			'noindex'          => '_seopress_robots_index',
			'nofollow'         => '_seopress_robots_follow',
		),
	);

	/** @var Plugin */
	private $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/** Which SEO plugin owns the meta (seolity when none). */
	public function engine() {
		if ( defined( 'WPSEO_VERSION' ) ) {
			return 'yoast';
		}
		if ( class_exists( 'RankMath' ) || defined( 'RANK_MATH_VERSION' ) ) {
			return 'rankmath';
		}
		if ( defined( 'SEOPRESS_VERSION' ) ) {
			return 'seopress';
		}
		if ( defined( 'AIOSEO_VERSION' ) ) {
			return 'aioseo';
		}
		return 'seolity';
	}

	/** Whether SEOlity should print head tags itself. */
	public function outputs_head() {
		$mode = $this->plugin->settings->get( 'frontend_output' );
		if ( 'on' === $mode ) {
			return true;
		}
		if ( 'off' === $mode ) {
			return false;
		}
		return 'seolity' === $this->engine();
	}

	/** Post meta key holding a field for the active engine (own key as fallback). */
	public function meta_key( $field ) {
		$engine = $this->engine();
		if ( isset( self::POST_KEYS[ $engine ][ $field ] ) ) {
			return self::POST_KEYS[ $engine ][ $field ];
		}
		return isset( self::EXTRA_KEYS[ $engine ][ $field ] ) ? self::EXTRA_KEYS[ $engine ][ $field ] : self::OWN[ $field ];
	}

	/** Comma list → clean, de-duplicated keywords (primary first). */
	public static function keyword_list( $value ) {
		$out = array();
		foreach ( explode( ',', (string) $value ) as $k ) {
			$k = trim( preg_replace( '/\s+/u', ' ', $k ) );
			if ( '' !== $k && ! in_array( mb_strtolower( $k ), array_map( 'mb_strtolower', $out ), true ) ) {
				$out[] = $k;
			}
		}
		return $out;
	}

	private function get_extra( $kind, $id, $field ) {
		$engine = $this->engine();
		$get    = 'term' === $kind ? 'get_term_meta' : 'get_post_meta';
		$own    = $get( $id, self::OWN[ $field ], true );
		if ( 'focus_keywords' === $field ) {
			if ( isset( self::EXTRA_KEYS[ $engine ][ $field ] ) ) {
				$v = (string) $get( $id, self::EXTRA_KEYS[ $engine ][ $field ], true );
			} elseif ( 'yoast' === $engine && 'post' === $kind ) {
				$list = array( (string) get_post_meta( $id, '_yoast_wpseo_focuskw', true ) );
				$more = json_decode( (string) get_post_meta( $id, '_yoast_wpseo_focuskeywords', true ), true );
				foreach ( (array) $more as $k ) {
					if ( ! empty( $k['keyword'] ) ) {
						$list[] = $k['keyword'];
					}
				}
				$v = implode( ',', $list );
			} else {
				$v = 'term' === $kind ? $this->get_term( $id, 'focus_keyword' ) : $this->get_post( $id, 'focus_keyword' );
			}
			$v = '' !== trim( (string) $v, ' ,' ) ? $v : $own;
			return implode( ', ', self::keyword_list( $v ) );
		}
		$v = isset( self::EXTRA_KEYS[ $engine ][ $field ] ) ? $get( $id, self::EXTRA_KEYS[ $engine ][ $field ], true ) : '';
		if ( 'pillar_content' === $field ) {
			return ( 'on' === $v || '1' === (string) $v || '1' === (string) $own ) ? '1' : '';
		}
		return '' !== (string) $v ? (string) $v : (string) $own;
	}

	private function set_extra( $kind, $id, $field, $value ) {
		$engine = $this->engine();
		$update = 'term' === $kind ? 'update_term_meta' : 'update_post_meta';
		$delete = 'term' === $kind ? 'delete_term_meta' : 'delete_post_meta';
		if ( 'focus_keywords' === $field ) {
			$list = self::keyword_list( $value );
			$update( $id, self::OWN[ $field ], implode( ', ', $list ) );
			$update( $id, self::OWN['focus_keyword'], $list ? $list[0] : '' );
			if ( isset( self::EXTRA_KEYS[ $engine ][ $field ] ) ) {
				$update( $id, self::EXTRA_KEYS[ $engine ][ $field ], implode( ',', $list ) );
			} elseif ( 'yoast' === $engine && 'post' === $kind ) {
				update_post_meta( $id, '_yoast_wpseo_focuskw', $list ? $list[0] : '' );
				$more = array();
				foreach ( array_slice( $list, 1 ) as $k ) {
					$more[] = array( 'keyword' => $k, 'score' => 0 );
				}
				$more ? update_post_meta( $id, '_yoast_wpseo_focuskeywords', wp_json_encode( $more, JSON_UNESCAPED_UNICODE ) ) : delete_post_meta( $id, '_yoast_wpseo_focuskeywords' );
			} elseif ( 'term' === $kind ) {
				$this->set_term( $id, 'focus_keyword', $list ? $list[0] : '' );
			} else {
				$this->set_post( $id, 'focus_keyword', $list ? $list[0] : '' );
			}
			return;
		}
		if ( 'pillar_content' === $field ) {
			$on = $value && '0' !== $value;
			$update( $id, self::OWN[ $field ], $on ? '1' : '' );
			if ( isset( self::EXTRA_KEYS[ $engine ][ $field ] ) ) {
				if ( $on ) {
					$update( $id, self::EXTRA_KEYS[ $engine ][ $field ], 'rankmath' === $engine ? 'on' : '1' );
				} else {
					$delete( $id, self::EXTRA_KEYS[ $engine ][ $field ] );
				}
			}
			return;
		}
		$value = sanitize_text_field( (string) $value );
		$update( $id, self::OWN[ $field ], $value );
		if ( isset( self::EXTRA_KEYS[ $engine ][ $field ] ) ) {
			$update( $id, self::EXTRA_KEYS[ $engine ][ $field ], $value );
		}
	}

	/* ------------------------------ Posts ------------------------------ */

	public function get_post( $post_id, $field ) {
		if ( in_array( $field, array( 'focus_keywords', 'pillar_content', 'social_title', 'social_description' ), true ) ) {
			return $this->get_extra( 'post', $post_id, $field );
		}
		$engine = $this->engine();
		$value  = null;
		if ( 'aioseo' === $engine ) {
			$value = $this->aioseo_get( $post_id, $field );
		} elseif ( isset( self::POST_KEYS[ $engine ][ $field ] ) ) {
			$value = get_post_meta( $post_id, self::POST_KEYS[ $engine ][ $field ], true );
			if ( 'rankmath' === $engine && 'focus_keyword' === $field && $value ) {
				$value = trim( explode( ',', $value )[0] ); // Rank Math stores a comma list.
			}
		} elseif ( 'rankmath' === $engine && in_array( $field, array( 'noindex', 'nofollow' ), true ) ) {
			$robots = (array) get_post_meta( $post_id, 'rank_math_robots', true );
			$value  = in_array( $field, $robots, true ) ? '1' : '';
		}
		if ( null === $value || '' === $value ) {
			$value = get_post_meta( $post_id, self::OWN[ $field ], true );
		}
		return $this->normalize_out( $engine, $field, $value );
	}

	public function set_post( $post_id, $field, $value ) {
		if ( in_array( $field, array( 'focus_keywords', 'pillar_content', 'social_title', 'social_description' ), true ) ) {
			$this->set_extra( 'post', $post_id, $field, $value );
			clean_post_cache( $post_id );
			return;
		}
		$engine = $this->engine();
		$value  = $this->sanitize( $field, $value );
		update_post_meta( $post_id, self::OWN[ $field ], $value ); // Always keep our copy (survives SEO-plugin switches).
		if ( 'rankmath' === $engine && 'focus_keyword' === $field ) {
			// Rank Math keeps primary + secondary keywords in one comma list: replace only the primary.
			$rest = array_values( array_filter( array_slice( self::keyword_list( get_post_meta( $post_id, 'rank_math_focus_keyword', true ) ), 1 ), static function ( $k ) use ( $value ) {
				return mb_strtolower( $k ) !== mb_strtolower( $value );
			} ) );
			$list = '' === $value ? $rest : array_merge( array( $value ), $rest );
			update_post_meta( $post_id, 'rank_math_focus_keyword', implode( ',', $list ) );
		} elseif ( 'aioseo' === $engine ) {
			$this->aioseo_set( $post_id, $field, $value );
		} elseif ( isset( self::POST_KEYS[ $engine ][ $field ] ) ) {
			update_post_meta( $post_id, self::POST_KEYS[ $engine ][ $field ], $this->normalize_in( $engine, $field, $value ) );
		} elseif ( 'rankmath' === $engine && in_array( $field, array( 'noindex', 'nofollow' ), true ) ) {
			$robots = array_diff( (array) get_post_meta( $post_id, 'rank_math_robots', true ), array( $field, 'index', 'follow' ) );
			if ( $value ) {
				$robots[] = $field;
			}
			update_post_meta( $post_id, 'rank_math_robots', array_values( array_filter( $robots ) ) );
		}
		clean_post_cache( $post_id );
	}

	/* ------------------------------ Terms ------------------------------ */

	public function get_term( $term_id, $field ) {
		if ( in_array( $field, array( 'focus_keywords', 'pillar_content', 'social_title', 'social_description' ), true ) ) {
			return $this->get_extra( 'term', $term_id, $field );
		}
		$engine = $this->engine();
		$term   = get_term( $term_id );
		$value  = '';
		if ( 'yoast' === $engine && $term && ! is_wp_error( $term ) ) {
			$meta = get_option( 'wpseo_taxonomy_meta', array() );
			$map  = array(
				'meta_title'       => 'wpseo_title',
				'meta_description' => 'wpseo_desc',
				'focus_keyword'    => 'wpseo_focuskw',
				'canonical'        => 'wpseo_canonical',
				'noindex'          => 'wpseo_noindex',
			);
			if ( isset( $map[ $field ], $meta[ $term->taxonomy ][ $term_id ][ $map[ $field ] ] ) ) {
				$value = $meta[ $term->taxonomy ][ $term_id ][ $map[ $field ] ];
				if ( 'noindex' === $field ) {
					$value = 'noindex' === $value ? '1' : '';
				}
			}
		} elseif ( isset( self::POST_KEYS[ $engine ][ $field ] ) && 'yoast' !== $engine ) {
			$value = get_term_meta( $term_id, self::POST_KEYS[ $engine ][ $field ], true );
		}
		if ( '' === $value || null === $value ) {
			$value = get_term_meta( $term_id, self::OWN[ $field ], true );
		}
		return $this->normalize_out( $engine, $field, $value );
	}

	public function set_term( $term_id, $field, $value ) {
		if ( in_array( $field, array( 'focus_keywords', 'pillar_content', 'social_title', 'social_description' ), true ) ) {
			$this->set_extra( 'term', $term_id, $field, $value );
			return;
		}
		$engine = $this->engine();
		$value  = $this->sanitize( $field, $value );
		update_term_meta( $term_id, self::OWN[ $field ], $value );
		$term = get_term( $term_id );
		if ( 'yoast' === $engine && $term && ! is_wp_error( $term ) ) {
			$meta = get_option( 'wpseo_taxonomy_meta', array() );
			$map  = array(
				'meta_title'       => 'wpseo_title',
				'meta_description' => 'wpseo_desc',
				'focus_keyword'    => 'wpseo_focuskw',
				'canonical'        => 'wpseo_canonical',
				'noindex'          => 'wpseo_noindex',
			);
			if ( isset( $map[ $field ] ) ) {
				$meta[ $term->taxonomy ][ $term_id ][ $map[ $field ] ] = 'noindex' === $field ? ( $value ? 'noindex' : 'default' ) : $value;
				update_option( 'wpseo_taxonomy_meta', $meta );
			}
		} elseif ( isset( self::POST_KEYS[ $engine ][ $field ] ) && 'yoast' !== $engine ) {
			update_term_meta( $term_id, self::POST_KEYS[ $engine ][ $field ], $this->normalize_in( $engine, $field, $value ) );
		}
	}

	/* ------------------------------ Helpers ------------------------------ */

	private function sanitize( $field, $value ) {
		switch ( $field ) {
			case 'canonical':
				return esc_url_raw( (string) $value );
			case 'noindex':
			case 'nofollow':
				return $value && '0' !== $value ? '1' : '';
			default:
				return sanitize_text_field( (string) $value );
		}
	}

	private function normalize_in( $engine, $field, $value ) {
		if ( 'seopress' === $engine && in_array( $field, array( 'noindex', 'nofollow' ), true ) ) {
			return $value ? 'yes' : '';
		}
		return $value;
	}

	private function normalize_out( $engine, $field, $value ) {
		if ( in_array( $field, array( 'noindex', 'nofollow' ), true ) ) {
			return ( '1' === (string) $value || 'yes' === $value || 'noindex' === $value || true === $value ) ? '1' : '';
		}
		return is_string( $value ) ? $value : (string) $value;
	}

	private function aioseo_table() {
		global $wpdb;
		$table = $wpdb->prefix . 'aioseo_posts';
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ? $table : null; // phpcs:ignore WordPress.DB
	}

	private function aioseo_get( $post_id, $field ) {
		$map = array( 'meta_title' => 'title', 'meta_description' => 'description', 'canonical' => 'canonical_url', 'noindex' => 'robots_noindex', 'nofollow' => 'robots_nofollow' );
		$table = $this->aioseo_table();
		if ( ! $table || ! isset( $map[ $field ] ) ) {
			return null;
		}
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare( "SELECT {$map[ $field ]} FROM {$table} WHERE post_id = %d", $post_id ) ); // phpcs:ignore WordPress.DB
	}

	private function aioseo_set( $post_id, $field, $value ) {
		$map = array( 'meta_title' => 'title', 'meta_description' => 'description', 'canonical' => 'canonical_url', 'noindex' => 'robots_noindex', 'nofollow' => 'robots_nofollow' );
		$table = $this->aioseo_table();
		if ( ! $table || ! isset( $map[ $field ] ) ) {
			return;
		}
		global $wpdb;
		$col = $map[ $field ];
		$val = in_array( $field, array( 'noindex', 'nofollow' ), true ) ? ( $value ? 1 : 0 ) : $value;
		if ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE post_id = %d", $post_id ) ) ) { // phpcs:ignore WordPress.DB
			$wpdb->update( $table, array( $col => $val ), array( 'post_id' => $post_id ) ); // phpcs:ignore WordPress.DB
		}
	}
}
