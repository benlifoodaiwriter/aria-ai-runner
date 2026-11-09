<?php
/**
 * Schema intelligence (section 21): JSON-LD graph generation + validation of
 * Product, Article, FAQPage, BreadcrumbList, Organization, LocalBusiness,
 * Review, AggregateRating, WebSite, WebPage, Person.
 *
 * @package SEOlity
 */

namespace SEOlity\Schema;

use SEOlity\Core\Http;
use SEOlity\Crawler\PageParser;
use SEOlity\Plugin;

defined( 'ABSPATH' ) || exit;

final class SchemaService {

	/** type => [required[], recommended[]] (Google rich-result guidelines). */
	const RULES = array(
		'Product'         => array( array( 'name' ), array( 'image', 'description', 'offers', 'brand', 'sku', 'aggregateRating', 'review' ) ),
		'Offer'           => array( array( 'price', 'priceCurrency' ), array( 'availability', 'url', 'priceValidUntil', 'itemCondition' ) ),
		'Article'         => array( array( 'headline' ), array( 'image', 'datePublished', 'dateModified', 'author', 'publisher' ) ),
		'BlogPosting'     => array( array( 'headline' ), array( 'image', 'datePublished', 'dateModified', 'author' ) ),
		'NewsArticle'     => array( array( 'headline' ), array( 'image', 'datePublished', 'dateModified', 'author' ) ),
		'FAQPage'         => array( array( 'mainEntity' ), array() ),
		'Question'        => array( array( 'name', 'acceptedAnswer' ), array() ),
		'BreadcrumbList'  => array( array( 'itemListElement' ), array() ),
		'ListItem'        => array( array( 'position' ), array( 'name', 'item' ) ),
		'Organization'    => array( array( 'name' ), array( 'url', 'logo', 'sameAs', 'contactPoint' ) ),
		'LocalBusiness'   => array( array( 'name', 'address' ), array( 'telephone', 'openingHoursSpecification', 'geo', 'url', 'image', 'priceRange' ) ),
		'Review'          => array( array( 'reviewRating', 'author' ), array( 'itemReviewed', 'datePublished' ) ),
		'AggregateRating' => array( array( 'ratingValue' ), array( 'reviewCount', 'ratingCount', 'bestRating' ) ),
		'WebSite'         => array( array( 'name', 'url' ), array( 'potentialAction' ) ),
		'WebPage'         => array( array( 'name' ), array( 'url', 'description', 'breadcrumb' ) ),
		'Person'          => array( array( 'name' ), array( 'url', 'sameAs', 'jobTitle', 'image' ) ),
	);

	/** @var Plugin */
	private $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/* ------------------------------------------------------------------
	 * Generation (frontend)
	 * ---------------------------------------------------------------- */

	/** Full graph when SEOlity owns SEO output. */
	public function graph() {
		$s     = $this->plugin->settings;
		$home  = home_url( '/' );
		$graph = array();
		$org_id = $home . '#organization';
		$org    = array(
			'@type' => 'LocalBusiness' === $s->get( 'org_type' ) ? 'LocalBusiness' : ( 'Person' === $s->get( 'org_type' ) ? 'Person' : ( 'OnlineStore' === $s->get( 'org_type' ) ? 'OnlineStore' : 'Organization' ) ),
			'@id'   => $org_id,
			'name'  => $s->get( 'org_name' ) ? $s->get( 'org_name' ) : get_bloginfo( 'name' ),
			'url'   => $home,
		);
		$logo = $s->get( 'org_logo' ) ? $s->get( 'org_logo' ) : ( get_theme_mod( 'custom_logo' ) ? wp_get_attachment_image_url( get_theme_mod( 'custom_logo' ), 'full' ) : '' );
		if ( $logo ) {
			$org['Person' === $org['@type'] ? 'image' : 'logo'] = $logo;
		}
		if ( $s->get( 'org_same_as' ) ) {
			$org['sameAs'] = array_values( array_filter( array_map( 'esc_url_raw', (array) $s->get( 'org_same_as' ) ) ) );
		}
		if ( 'LocalBusiness' === $org['@type'] ) {
			if ( $s->get( 'org_phone' ) ) {
				$org['telephone'] = $s->get( 'org_phone' );
			}
			if ( $s->get( 'org_address' ) ) {
				$org['address'] = array( '@type' => 'PostalAddress', 'streetAddress' => $s->get( 'org_address' ) );
			}
		}
		$graph[] = $org;
		$graph[] = array(
			'@type'           => 'WebSite',
			'@id'             => $home . '#website',
			'url'             => $home,
			'name'            => get_bloginfo( 'name' ),
			'description'     => get_bloginfo( 'description' ),
			'publisher'       => array( '@id' => $org_id ),
			'inLanguage'      => get_bloginfo( 'language' ),
			'potentialAction' => array(
				'@type'       => 'SearchAction',
				'target'      => array( '@type' => 'EntryPoint', 'urlTemplate' => home_url( '/?s={search_term_string}' ) ),
				'query-input' => 'required name=search_term_string',
			),
		);

		if ( is_singular() ) {
			$post = get_queried_object();
			$url  = get_permalink( $post );
			$page = array(
				'@type'         => 'WebPage',
				'@id'           => $url . '#webpage',
				'url'           => $url,
				'name'          => wp_strip_all_tags( get_the_title( $post ) ),
				'isPartOf'      => array( '@id' => $home . '#website' ),
				'datePublished' => get_the_date( 'c', $post ),
				'dateModified'  => get_the_modified_date( 'c', $post ),
				'inLanguage'    => get_bloginfo( 'language' ),
			);
			$crumbs = $this->breadcrumbs( $post );
			if ( count( $crumbs ) > 1 ) {
				$graph[]            = $this->breadcrumb_list( $crumbs, $url );
				$page['breadcrumb'] = array( '@id' => $url . '#breadcrumb' );
			}
			$graph[] = $page;
			if ( 'post' === $post->post_type ) {
				$author  = get_userdata( $post->post_author );
				$article = array(
					'@type'            => 'BlogPosting',
					'@id'              => $url . '#article',
					'headline'         => mb_substr( wp_strip_all_tags( get_the_title( $post ) ), 0, 110 ),
					'datePublished'    => get_the_date( 'c', $post ),
					'dateModified'     => get_the_modified_date( 'c', $post ),
					'mainEntityOfPage' => array( '@id' => $url . '#webpage' ),
					'publisher'        => array( '@id' => $org_id ),
					'inLanguage'       => get_bloginfo( 'language' ),
				);
				if ( $author ) {
					$article['author'] = array( '@type' => 'Person', 'name' => $author->display_name, 'url' => get_author_posts_url( $author->ID ) );
				}
				$img = get_the_post_thumbnail_url( $post, 'full' );
				if ( $img ) {
					$article['image'] = $img;
				}
				$graph[] = $article;
			}
		} elseif ( is_category() || is_tag() || is_tax() ) {
			$term   = get_queried_object();
			$link   = get_term_link( $term );
			$graph[] = array(
				'@type'    => 'CollectionPage',
				'@id'      => $link . '#webpage',
				'url'      => $link,
				'name'     => $term->name,
				'isPartOf' => array( '@id' => $home . '#website' ),
			);
		}
		return apply_filters( 'seolity_schema_graph', $graph );
	}

	/** FAQPage from SEOlity FAQ meta (output even when another SEO plugin is active). */
	public function faq_schema( $post_id ) {
		$faq = get_post_meta( $post_id, '_seolity_faq', true );
		if ( ! is_array( $faq ) || ! $faq ) {
			return null;
		}
		$entities = array();
		foreach ( $faq as $row ) {
			$entities[] = array(
				'@type'          => 'Question',
				'name'           => wp_strip_all_tags( $row['question'] ),
				'acceptedAnswer' => array( '@type' => 'Answer', 'text' => wp_kses( $row['answer'], array( 'a' => array( 'href' => array() ), 'strong' => array(), 'em' => array(), 'br' => array(), 'p' => array(), 'ul' => array(), 'ol' => array(), 'li' => array() ) ) ),
			);
		}
		return array( '@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => $entities );
	}

	/** @return array<int, array{name:string, url:string}> */
	public function breadcrumbs( $post ) {
		$crumbs = array( array( 'name' => __( 'Home', 'seolity' ), 'url' => home_url( '/' ) ) );
		if ( 'product' === $post->post_type ) {
			$terms = get_the_terms( $post, 'product_cat' );
			if ( $terms && ! is_wp_error( $terms ) ) {
				$term = $terms[0];
				foreach ( array_reverse( get_ancestors( $term->term_id, 'product_cat' ) ) as $aid ) {
					$a        = get_term( $aid, 'product_cat' );
					$crumbs[] = array( 'name' => $a->name, 'url' => get_term_link( $a ) );
				}
				$crumbs[] = array( 'name' => $term->name, 'url' => get_term_link( $term ) );
			}
		} elseif ( 'post' === $post->post_type ) {
			$cats = get_the_category( $post->ID );
			if ( $cats ) {
				$crumbs[] = array( 'name' => $cats[0]->name, 'url' => get_category_link( $cats[0] ) );
			}
		} elseif ( $post->post_parent ) {
			foreach ( array_reverse( get_post_ancestors( $post ) ) as $aid ) {
				$crumbs[] = array( 'name' => get_the_title( $aid ), 'url' => get_permalink( $aid ) );
			}
		}
		$crumbs[] = array( 'name' => wp_strip_all_tags( get_the_title( $post ) ), 'url' => get_permalink( $post ) );
		return $crumbs;
	}

	private function breadcrumb_list( array $crumbs, $url ) {
		$items = array();
		foreach ( $crumbs as $i => $c ) {
			$items[] = array( '@type' => 'ListItem', 'position' => $i + 1, 'name' => $c['name'], 'item' => $c['url'] );
		}
		return array( '@type' => 'BreadcrumbList', '@id' => $url . '#breadcrumb', 'itemListElement' => $items );
	}

	/* ------------------------------------------------------------------
	 * Validation
	 * ---------------------------------------------------------------- */

	/**
	 * Validate JSON-LD nodes.
	 *
	 * @return array{types:string[], errors:array, warnings:array}
	 */
	public function validate( array $blocks ) {
		$errors   = array();
		$warnings = array();
		$types    = array();
		$walk     = function ( $node, $path ) use ( &$walk, &$errors, &$warnings, &$types ) {
			if ( ! is_array( $node ) ) {
				return;
			}
			if ( isset( $node[0] ) ) {
				foreach ( $node as $i => $n ) {
					$walk( $n, $path . "[$i]" );
				}
				return;
			}
			if ( isset( $node['@graph'] ) ) {
				$walk( $node['@graph'], $path . '.@graph' );
			}
			foreach ( (array) ( isset( $node['@type'] ) ? $node['@type'] : array() ) as $type ) {
				if ( ! is_string( $type ) ) {
					continue;
				}
				$types[] = $type;
				$rule    = isset( self::RULES[ $type ] ) ? self::RULES[ $type ] : ( in_array( $type, array( 'OnlineStore', 'Store', 'Restaurant' ), true ) ? self::RULES['LocalBusiness'] : null );
				if ( ! $rule ) {
					continue;
				}
				foreach ( $rule[0] as $req ) {
					if ( empty( $node[ $req ] ) && ! ( isset( $node[ $req ] ) && 0 === $node[ $req ] ) ) {
						/* translators: 1 type 2 property */
						$errors[] = array( 'type' => $type, 'path' => $path, 'property' => $req, 'message' => sprintf( __( '%1$s is missing required property "%2$s".', 'seolity' ), $type, $req ) );
					}
				}
				foreach ( $rule[1] as $rec ) {
					if ( empty( $node[ $rec ] ) ) {
						/* translators: 1 type 2 property */
						$warnings[] = array( 'type' => $type, 'path' => $path, 'property' => $rec, 'message' => sprintf( __( '%1$s is missing recommended property "%2$s".', 'seolity' ), $type, $rec ) );
					}
				}
				if ( 'Product' === $type && empty( $node['offers'] ) && empty( $node['review'] ) && empty( $node['aggregateRating'] ) ) {
					$errors[] = array( 'type' => $type, 'path' => $path, 'property' => 'offers', 'message' => __( 'Product needs offers, review or aggregateRating to be eligible for rich results.', 'seolity' ) );
				}
				if ( 'AggregateRating' === $type && isset( $node['ratingValue'] ) && (float) $node['ratingValue'] > ( isset( $node['bestRating'] ) ? (float) $node['bestRating'] : 5 ) ) {
					$errors[] = array( 'type' => $type, 'path' => $path, 'property' => 'ratingValue', 'message' => __( 'ratingValue exceeds bestRating.', 'seolity' ) );
				}
			}
			foreach ( $node as $k => $v ) {
				if ( is_array( $v ) && '@graph' !== $k ) {
					$walk( $v, $path . '.' . $k );
				}
			}
		};
		$walk( $blocks, '$' );
		return array( 'types' => array_values( array_unique( $types ) ), 'errors' => $errors, 'warnings' => array_slice( $warnings, 0, 50 ) );
	}

	/** Fetch a URL and validate its structured data + list opportunities. */
	public function check_url( $url, &$page = null ) {
		if ( ! PageParser::same_site( wp_parse_url( $url, PHP_URL_HOST ), wp_parse_url( home_url(), PHP_URL_HOST ) ) ) {
			return new \WP_Error( 'seolity_schema_url', __( 'Only URLs of this site can be checked.', 'seolity' ), array( 'status' => 400 ) );
		}
		$res = wp_remote_get( $url, array( 'timeout' => 20, 'user-agent' => Http::user_agent() ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$page   = PageParser::parse( wp_remote_retrieve_body( $res ), $url );
		$result = $this->validate( $page['schema_blocks'] );
		$opps   = array();
		$post_id = url_to_postid( $url );
		$ptype   = $post_id ? get_post_type( $post_id ) : '';
		if ( 'product' === $ptype && ! array_intersect( array( 'Product', 'ProductGroup' ), $result['types'] ) ) {
			$opps[] = __( 'Product page without Product schema — enable WooCommerce structured data or SEOlity schema.', 'seolity' );
		}
		if ( 'post' === $ptype && ! array_intersect( array( 'Article', 'BlogPosting', 'NewsArticle' ), $result['types'] ) ) {
			$opps[] = __( 'Blog post without Article/BlogPosting schema.', 'seolity' );
		}
		$questions = count( array_filter( array_merge( $page['h2'], $page['h3'] ), static function ( $h ) {
			return (bool) preg_match( '/[?؟]\s*$/u', $h );
		} ) );
		if ( $questions >= 2 && ! in_array( 'FAQPage', $result['types'], true ) ) {
			/* translators: %d */
			$opps[] = sprintf( __( 'Page contains %d question headings — FAQ schema could describe them.', 'seolity' ), $questions );
		}
		if ( ! in_array( 'BreadcrumbList', $result['types'], true ) && '/' !== wp_parse_url( $url, PHP_URL_PATH ) ) {
			$opps[] = __( 'Add BreadcrumbList schema.', 'seolity' );
		}
		if ( ! array_intersect( array( 'Organization', 'LocalBusiness', 'Person', 'OnlineStore' ), $result['types'] ) && untrailingslashit( $url ) === untrailingslashit( home_url() ) ) {
			$opps[] = __( 'Homepage has no Organization/LocalBusiness entity — important for the Knowledge Panel and E-E-A-T.', 'seolity' );
		}
		$result['opportunities'] = $opps;
		$result['blocks']        = $page['schema_blocks'];
		$result['invalid_json']  = $page['schema_errors'];
		return $result;
	}

	/**
	 * Rule-based check plus AI suggestions: which schema.org types are eligible and
	 * truthful for this page, and what is wrong or missing in the current markup.
	 */
	public function suggest( $url ) {
		$page  = null;
		$check = $this->check_url( $url, $page );
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		$post_id = url_to_postid( $url );
		$res     = $this->plugin->ai->run(
			'schema_suggest',
			array(
				'url'                => $url,
				'post_type'          => $post_id ? get_post_type( $post_id ) : '',
				'title'              => $page['title'],
				'headings'           => array_slice( array_merge( $page['h1'], $page['h2'], $page['h3'] ), 0, 30 ),
				'visible_text'       => $page['text_sample'],
				'existing_types'     => $check['types'],
				'existing_json_ld'   => mb_substr( wp_json_encode( $check['blocks'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ), 0, 6000 ),
				'validation_errors'  => array_slice( wp_list_pluck( $check['errors'], 'message' ), 0, 20 ),
				'validation_warnings' => array_slice( wp_list_pluck( $check['warnings'], 'message' ), 0, 20 ),
				'rule_opportunities' => $check['opportunities'],
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$check['ai']   = $res['data'];
		$check['meta'] = $res['meta'];
		return $check;
	}

	/** Site-wide coverage from crawl data. */
	public function coverage() {
		$rows   = $this->plugin->db->rows( "SELECT object_type, data FROM {urls} WHERE project_id = %d AND crawl_status = 'done' AND status_code = 200 AND indexable = 1 LIMIT 20000", array( $this->plugin->db->project_id() ) );
		$counts = array();
		$total  = 0;
		$none   = 0;
		foreach ( $rows as $r ) {
			$d = json_decode( (string) $r['data'], true );
			$total++;
			$types = isset( $d['schema_types'] ) ? (array) $d['schema_types'] : array();
			if ( ! $types ) {
				$none++;
			}
			foreach ( $types as $t ) {
				$counts[ $t ] = isset( $counts[ $t ] ) ? $counts[ $t ] + 1 : 1;
			}
		}
		arsort( $counts );
		return array(
			'pages'      => $total,
			'without'    => $none,
			'types'      => $counts,
			'issues'     => $this->plugin->db->rows( "SELECT check_code, COUNT(*) c FROM {issues} WHERE project_id = %d AND category = 'schema' AND status = 'open' GROUP BY check_code", array( $this->plugin->db->project_id() ) ),
			'outputs'    => $this->plugin->meta->outputs_head(),
			'faq_pages'  => (int) $this->plugin->db->value( "SELECT COUNT(*) FROM {$this->plugin->db->wpdb->postmeta} WHERE meta_key = '_seolity_faq'" ),
		);
	}
}
