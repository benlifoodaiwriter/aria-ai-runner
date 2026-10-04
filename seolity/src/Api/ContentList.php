<?php
/**
 * Content inventory for the Content screen (posts, pages, CPTs, terms) with
 * SEO score, meta status, focus keyword, word count and open issues.
 *
 * @package SEOlity
 */

namespace SEOlity\Api;

use SEOlity\Analysis\Text;
use SEOlity\Core\Database;
use SEOlity\Crawler\PageParser;
use SEOlity\Plugin;

defined( 'ABSPATH' ) || exit;

final class ContentList {

	const FILTER_CODES = array(
		'title' => array( 'title_missing', 'title_too_long', 'title_too_short', 'duplicate_title' ),
		'meta'  => array( 'meta_desc_missing', 'meta_desc_length', 'duplicate_meta' ),
		'thin'  => array( 'thin_content' ),
		'h1'    => array( 'h1_missing', 'h1_multiple' ),
	);

	/** @var Plugin */
	private $p;

	public function __construct( Plugin $plugin ) {
		$this->p = $plugin;
	}

	private function ids_with_issues( $codes, $object_type ) {
		$in = implode( ',', array_fill( 0, count( $codes ), '%s' ) );
		return array_map(
			'intval',
			$this->p->db->column(
				"SELECT DISTINCT u.object_id FROM {issues} i JOIN {urls} u ON u.id = i.url_id WHERE i.project_id = %d AND i.status = 'open' AND u.object_type = %s AND i.check_code IN ($in) LIMIT 2000",
				array_merge( array( $this->p->db->project_id(), $object_type ), $codes )
			)
		);
	}

	public function query( array $f ) {
		$per    = isset( $f['per_page'] ) ? max( 1, min( 100, (int) $f['per_page'] ) ) : 30;
		$page   = isset( $f['page'] ) ? max( 1, (int) $f['page'] ) : 1;
		$filter = isset( $f['filter'] ) ? $f['filter'] : '';
		if ( 'term' === $f['kind'] ) {
			return $this->terms( $f, $per, $page, $filter );
		}
		$types = ! empty( $f['post_type'] ) && post_type_exists( $f['post_type'] ) ? array( $f['post_type'] ) : array_values( array_diff( get_post_types( array( 'public' => true ) ), array( 'attachment' ) ) );
		$args  = array(
			'post_type'      => $types,
			'post_status'    => array( 'publish', 'draft', 'pending', 'future' ),
			'posts_per_page' => $per,
			'paged'          => $page,
			's'              => isset( $f['search'] ) ? $f['search'] : '',
			'orderby'        => 'modified',
		);
		if ( isset( self::FILTER_CODES[ $filter ] ) ) {
			$ids              = $this->ids_with_issues( self::FILTER_CODES[ $filter ], 'post' );
			$args['post__in'] = $ids ? $ids : array( 0 );
		} elseif ( 'low_score' === $filter ) {
			$ids              = array_map( 'intval', $this->p->db->column( "SELECT object_id FROM {content_analysis} WHERE project_id = %d AND object_type = 'post' AND score < 55 LIMIT 2000", array( $this->p->db->project_id() ) ) );
			$args['post__in'] = $ids ? $ids : array( 0 );
		} elseif ( 'no_keyword' === $filter ) {
			$with             = array_map( 'intval', $this->p->db->column( "SELECT post_id FROM {$this->p->db->wpdb->postmeta} WHERE meta_key = '_seolity_focus_keyword' AND meta_value <> ''" ) );
			$args['post__not_in'] = $with;
		}
		$q     = new \WP_Query( $args );
		$items = array();
		foreach ( $q->posts as $post ) {
			$items[] = $this->row( 'post', $post->ID, $post->post_title, get_permalink( $post ), $post->post_type, $post->post_status, $post->post_content, $post->post_modified_gmt );
		}
		return array( 'items' => $items, 'total' => (int) $q->found_posts, 'post_types' => $this->post_types() );
	}

	private function terms( array $f, $per, $page, $filter ) {
		$tax  = ! empty( $f['taxonomy'] ) && taxonomy_exists( $f['taxonomy'] ) ? $f['taxonomy'] : ( class_exists( 'WooCommerce' ) ? 'product_cat' : 'category' );
		$args = array(
			'taxonomy'   => $tax,
			'hide_empty' => false,
			'number'     => $per,
			'offset'     => ( $page - 1 ) * $per,
			'search'     => isset( $f['search'] ) ? $f['search'] : '',
		);
		if ( isset( self::FILTER_CODES[ $filter ] ) ) {
			$ids             = $this->ids_with_issues( self::FILTER_CODES[ $filter ], 'term' );
			$args['include'] = $ids ? $ids : array( 0 );
		}
		$terms = get_terms( $args );
		$items = array();
		foreach ( is_wp_error( $terms ) ? array() : $terms as $t ) {
			$link    = get_term_link( $t );
			$items[] = $this->row( 'term', $t->term_id, $t->name, is_wp_error( $link ) ? '' : $link, $t->taxonomy, 'publish', $t->description, null ) + array( 'count' => $t->count );
		}
		$count = wp_count_terms( array( 'taxonomy' => $tax, 'hide_empty' => false ) );
		return array( 'items' => $items, 'total' => is_wp_error( $count ) ? 0 : (int) $count, 'taxonomies' => array_values( array_map( static function ( $t ) {
			return array( 'name' => $t->name, 'label' => $t->label );
		}, get_taxonomies( array( 'public' => true ), 'objects' ) ) ) );
	}

	private function row( $type, $id, $title, $url, $subtype, $status, $content, $modified ) {
		$db    = $this->p->db;
		$meta  = 'term' === $type ? array( $this->p->meta->get_term( $id, 'meta_title' ), $this->p->meta->get_term( $id, 'meta_description' ), $this->p->meta->get_term( $id, 'focus_keyword' ) ) : array( $this->p->meta->get_post( $id, 'meta_title' ), $this->p->meta->get_post( $id, 'meta_description' ), $this->p->meta->get_post( $id, 'focus_keyword' ) );
		$score = $db->value( 'SELECT score FROM {content_analysis} WHERE project_id = %d AND object_type = %s AND object_id = %d', array( $db->project_id(), $type, $id ) );
		$url_id = $url ? $db->value( 'SELECT id FROM {urls} WHERE project_id = %d AND url_hash = %s', array( $db->project_id(), Database::hash( PageParser::normalize_url( $url ) ) ) ) : null;
		return array(
			'object_type'      => $type,
			'id'               => (int) $id,
			'title'            => $title,
			'url'              => $url,
			'subtype'          => $subtype,
			'status'           => $status,
			'modified'         => $modified,
			'words'            => Text::word_count( Text::strip( $content ) ),
			'score'            => null === $score ? null : (int) $score,
			'meta_title'       => $meta[0],
			'meta_description' => $meta[1],
			'focus_keyword'    => $meta[2],
			'issues'           => $url_id ? $db->column( "SELECT check_code FROM {issues} WHERE url_id = %d AND status = 'open' AND severity <> 'info'", array( (int) $url_id ) ) : array(),
			'pending'          => (int) $db->value( "SELECT COUNT(*) FROM {changes} WHERE object_type = %s AND object_id = %d AND status = 'pending'", array( $type, $id ) ),
			'edit_link'        => 'term' === $type ? get_edit_term_link( $id ) : get_edit_post_link( $id, 'raw' ),
		);
	}

	private function post_types() {
		$out = array();
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $pt ) {
			if ( 'attachment' !== $pt->name ) {
				$out[] = array( 'name' => $pt->name, 'label' => $pt->label );
			}
		}
		return $out;
	}
}
