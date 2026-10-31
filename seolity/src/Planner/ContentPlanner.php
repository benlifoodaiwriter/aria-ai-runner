<?php
/**
 * Content Planner (section 19) + Topic Clusters (section 18).
 *
 * @package SEOlity
 */

namespace SEOlity\Planner;

use SEOlity\Core\Database;
use SEOlity\Plugin;

defined( 'ABSPATH' ) || exit;

final class ContentPlanner {

	/** @var Plugin */
	private $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	private function pid() {
		return $this->plugin->db->project_id();
	}

	/** Generate a calendar (daily|weekly|monthly cadence) for N items. */
	public function generate( $cadence = 'weekly', $items = 8, $focus = '' ) {
		$step  = array( 'daily' => 1, 'weekly' => 7, 'monthly' => 30 );
		$step  = isset( $step[ $cadence ] ) ? $step[ $cadence ] : 7;
		$items = max( 1, min( 30, (int) $items ) );
		$p     = $this->plugin;
		$db    = $p->db;
		$gaps  = array();
		foreach ( $db->column( "SELECT summary FROM {competitors} WHERE project_id = %d AND status = 'active'", array( $this->pid() ) ) as $s ) {
			$d    = json_decode( (string) $s, true );
			$gaps = array_merge( $gaps, isset( $d['gap']['opportunities'] ) ? array_slice( $d['gap']['opportunities'], 0, 10 ) : array() );
		}
		$cluster_gaps = array();
		foreach ( $db->rows( 'SELECT name, gaps FROM {clusters} WHERE project_id = %d', array( $this->pid() ) ) as $c ) {
			foreach ( (array) json_decode( (string) $c['gaps'], true ) as $g ) {
				$cluster_gaps[] = array_merge( $g, array( 'cluster' => $c['name'] ) );
			}
		}
		$input = array(
			'cadence'          => $cadence,
			'items'            => $items,
			'start_date'       => gmdate( 'Y-m-d', strtotime( '+1 day' ) ),
			'focus'            => sanitize_text_field( $focus ),
			'existing_pages'   => $db->rows( "SELECT url, title FROM {urls} WHERE project_id = %d AND status_code = 200 AND indexable = 1 AND crawl_status = 'done' ORDER BY inlinks DESC LIMIT 120", array( $this->pid() ) ),
			'keyword_opportunities' => $db->rows( "SELECT keyword, intent, opportunity, target_url FROM {keywords} WHERE project_id = %d ORDER BY opportunity DESC LIMIT 60", array( $this->pid() ) ),
			'competitor_gaps'  => array_slice( $gaps, 0, 25 ),
			'cluster_gaps'     => array_slice( $cluster_gaps, 0, 25 ),
			'already_planned'  => $db->column( "SELECT topic FROM {content_plan} WHERE project_id = %d AND status IN ('planned','in_progress')", array( $this->pid() ) ),
			'month'            => wp_date( 'F' ),
		);
		$res = $p->ai->run( 'content_plan', $input, array( 'knowledge_query' => 'content strategy ' . $focus ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$ids = array();
		foreach ( $res['data']['items'] as $i => $item ) {
			$offset = isset( $item['day_offset'] ) ? (int) $item['day_offset'] : $i * $step;
			$ids[]  = $db->insert(
				'content_plan',
				array(
					'project_id'     => $this->pid(),
					'scheduled_date' => gmdate( 'Y-m-d', strtotime( '+' . max( 1, $offset ? $offset : ( $i + 1 ) * $step ) . ' days' ) ),
					'topic'          => sanitize_text_field( $item['topic'] ),
					'keyword'        => sanitize_text_field( $item['keyword'] ),
					'intent'         => sanitize_key( $item['intent'] ),
					'title'          => sanitize_text_field( $item['title'] ),
					'h1'             => sanitize_text_field( $item['h1'] ),
					'brief'          => array( 'outline' => $item['outline'], 'supporting_keywords' => $item['supporting_keywords'], 'internal_links' => $item['internal_links'], 'competitors' => $item['competitors'], 'reason' => $item['reason'] ),
					'priority'       => sanitize_key( $item['priority'] ),
					'status'         => 'planned',
					'created_at'     => Database::now(),
				)
			);
		}
		return array( 'created' => count( $ids ), 'meta' => $res['meta'] );
	}

	public function add_from_opportunity( array $o ) {
		$id = $this->plugin->db->insert(
			'content_plan',
			array(
				'project_id'     => $this->pid(),
				'scheduled_date' => gmdate( 'Y-m-d', strtotime( '+7 days' ) ),
				'topic'          => sanitize_text_field( isset( $o['suggested_title'] ) ? $o['suggested_title'] : $o['keyword'] ),
				'keyword'        => sanitize_text_field( $o['keyword'] ),
				'intent'         => sanitize_key( isset( $o['intent'] ) ? $o['intent'] : '' ),
				'title'          => sanitize_text_field( isset( $o['suggested_title'] ) ? $o['suggested_title'] : '' ),
				'h1'             => sanitize_text_field( isset( $o['suggested_h1'] ) ? $o['suggested_h1'] : '' ),
				'brief'          => array( 'outline' => isset( $o['outline'] ) ? $o['outline'] : array(), 'supporting_keywords' => isset( $o['supporting_keywords'] ) ? $o['supporting_keywords'] : array(), 'word_count' => isset( $o['recommended_word_count'] ) ? $o['recommended_word_count'] : null, 'reason' => isset( $o['reason'] ) ? $o['reason'] : '', 'suggested_url' => isset( $o['suggested_url'] ) ? $o['suggested_url'] : '' ),
				'priority'       => sanitize_key( isset( $o['priority'] ) ? $o['priority'] : 'medium' ),
				'status'         => 'planned',
				'created_at'     => Database::now(),
			)
		);
		return array( 'plan_id' => $id, 'route' => '#/content?tab=planner' );
	}

	public function query( array $f = array() ) {
		$rows = $this->plugin->db->rows( 'SELECT * FROM {content_plan} WHERE project_id = %d ORDER BY scheduled_date ASC LIMIT 300', array( $this->pid() ) );
		return array_map(
			static function ( $r ) {
				$r = Database::decode( $r, array( 'brief' ) );
				if ( $r['post_id'] ) {
					$r['edit_link'] = get_edit_post_link( (int) $r['post_id'], 'raw' );
				}
				return $r;
			},
			$rows
		);
	}

	public function update( $id, array $data ) {
		$allowed = array();
		if ( isset( $data['status'] ) && in_array( $data['status'], array( 'planned', 'in_progress', 'published', 'skipped' ), true ) ) {
			$allowed['status'] = $data['status'];
		}
		if ( isset( $data['scheduled_date'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $data['scheduled_date'] ) ) {
			$allowed['scheduled_date'] = $data['scheduled_date'];
		}
		foreach ( array( 'title', 'h1', 'keyword' ) as $k ) {
			if ( isset( $data[ $k ] ) ) {
				$allowed[ $k ] = sanitize_text_field( $data[ $k ] );
			}
		}
		return (bool) $this->plugin->db->update( 'content_plan', $allowed, array( 'id' => (int) $id, 'project_id' => $this->pid() ) );
	}

	public function delete( $id ) {
		return (bool) $this->plugin->db->delete( 'content_plan', array( 'id' => (int) $id, 'project_id' => $this->pid() ) );
	}

	/**
	 * Create a WordPress draft from a planned item with an AI brief (outline as
	 * headings + notes). The writer still writes — no auto-published AI pages.
	 */
	public function create_draft( $id ) {
		$item = Database::decode( $this->plugin->db->row( 'SELECT * FROM {content_plan} WHERE id = %d AND project_id = %d', array( (int) $id, $this->pid() ) ), array( 'brief' ) );
		if ( ! $item ) {
			return new \WP_Error( 'seolity_plan', __( 'Plan item not found.', 'seolity' ), array( 'status' => 404 ) );
		}
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new \WP_Error( 'seolity_forbidden', __( 'You cannot create posts.', 'seolity' ), array( 'status' => 403 ) );
		}
		$brief = $this->plugin->ai->run(
			'content_brief',
			array(
				'topic'      => $item['topic'],
				'keyword'    => $item['keyword'],
				'intent'     => $item['intent'],
				'title'      => $item['title'],
				'site_pages' => $this->plugin->db->rows( "SELECT url, title FROM {urls} WHERE project_id = %d AND status_code = 200 AND indexable = 1 AND crawl_status = 'done' ORDER BY inlinks DESC LIMIT 60", array( $this->pid() ) ),
			),
			array( 'knowledge_query' => $item['keyword'] )
		);
		$content = '';
		$b       = is_wp_error( $brief ) ? null : $brief['data'];
		if ( $b ) {
			$content .= '<!-- wp:paragraph --><p><em>' . esc_html__( 'SEOlity brief — replace the notes below with your own expertise, examples and sources before publishing.', 'seolity' ) . '</em></p><!-- /wp:paragraph -->';
			foreach ( $b['outline'] as $sec ) {
				$level    = max( 2, min( 3, (int) $sec['level'] ) );
				$content .= '<!-- wp:heading {"level":' . $level . '} --><h' . $level . '>' . esc_html( $sec['heading'] ) . '</h' . $level . '><!-- /wp:heading -->';
				if ( $sec['points'] ) {
					$content .= '<!-- wp:list --><ul>' . implode( '', array_map( static function ( $pt ) {
						return '<li>' . esc_html( $pt ) . '</li>';
					}, $sec['points'] ) ) . '</ul><!-- /wp:list -->';
				}
			}
		}
		$post_id = wp_insert_post(
			array(
				'post_title'   => $b ? $b['title'] : $item['title'],
				'post_content' => $content,
				'post_status'  => 'draft',
				'post_type'    => 'post',
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}
		if ( $b ) {
			$this->plugin->meta->set_post( $post_id, 'meta_description', $b['meta_description'] );
			$this->plugin->meta->set_post( $post_id, 'focus_keyword', $item['keyword'] );
		}
		$this->plugin->db->update( 'content_plan', array( 'post_id' => $post_id, 'status' => 'in_progress', 'brief' => array_merge( (array) $item['brief'], $b ? array( 'ai_brief' => $b ) : array() ) ), array( 'id' => (int) $id ) );
		$this->plugin->audit_log->log( 'plan.draft_created', 'post', $post_id, array( 'plan_id' => (int) $id ) );
		return array( 'post_id' => $post_id, 'edit_link' => get_edit_post_link( $post_id, 'raw' ) );
	}

	/** Topic clusters from crawled pages + top queries. */
	public function build_clusters() {
		$db    = $this->plugin->db;
		$rows  = $db->rows( "SELECT id, url, object_id, title FROM {urls} WHERE project_id = %d AND object_type = 'post' AND status_code = 200 AND indexable = 1 AND crawl_status = 'done' ORDER BY inlinks DESC LIMIT 250", array( $this->pid() ) );
		if ( count( $rows ) < 5 || ! $this->plugin->ai->is_ready() ) {
			return false;
		}
		$pages = array();
		foreach ( $rows as $r ) {
			$q       = $this->plugin->gsc_analytics->has_data() ? $this->plugin->gsc_analytics->page_queries( $r['url'], 90, 1 ) : array();
			$pages[] = array( 'id' => (int) $r['id'], 'title' => $r['title'], 'top_query' => $q ? $q[0]['query'] : '' );
		}
		$res = $this->plugin->ai->run( 'topic_clusters', array( 'pages' => $pages ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$valid = wp_list_pluck( $pages, 'id' );
		$by_id = array();
		foreach ( $rows as $r ) {
			$by_id[ (int) $r['id'] ] = $r;
		}
		$db->query( 'DELETE FROM {clusters} WHERE project_id = %d', array( $this->pid() ) );
		foreach ( $res['data']['clusters'] as $c ) {
			$members = array_values( array_intersect( array_map( 'intval', $c['member_ids'] ), $valid ) );
			$pillar  = in_array( (int) $c['pillar_id'], $valid, true ) ? (int) $c['pillar_id'] : ( $members ? $members[0] : 0 );
			$db->insert(
				'clusters',
				array(
					'project_id'       => $this->pid(),
					'name'             => sanitize_text_field( $c['name'] ),
					'pillar_url'       => $pillar ? $by_id[ $pillar ]['url'] : null,
					'pillar_object_id' => $pillar ? (int) $by_id[ $pillar ]['object_id'] : null,
					'members'          => array_map( static function ( $m ) use ( $by_id ) {
						return array( 'url_id' => $m, 'url' => $by_id[ $m ]['url'], 'title' => $by_id[ $m ]['title'] );
					}, $members ),
					'gaps'             => $c['gaps'],
					'coverage'         => max( 0, min( 100, (int) $c['coverage'] ) ),
					'created_at'       => Database::now(),
					'updated_at'       => Database::now(),
				)
			);
		}
		return count( $res['data']['clusters'] );
	}

	public function clusters() {
		$rows = $this->plugin->db->rows( 'SELECT * FROM {clusters} WHERE project_id = %d ORDER BY coverage ASC', array( $this->pid() ) );
		foreach ( $rows as &$r ) {
			$r = Database::decode( $r, array( 'members', 'gaps' ) );
			// Pillar ↔ member internal-link check.
			$missing = array();
			if ( $r['pillar_url'] ) {
				$pillar_id = (int) $this->plugin->db->value( 'SELECT id FROM {urls} WHERE project_id = %d AND url_hash = %s', array( $this->pid(), Database::url_hash( $r['pillar_url'] ) ) );
				foreach ( (array) $r['members'] as $m ) {
					if ( (int) $m['url_id'] === $pillar_id ) {
						continue;
					}
					$linked = (int) $this->plugin->db->value( "SELECT COUNT(*) FROM {internal_links} WHERE project_id = %d AND link_type = 'existing' AND ( ( source_id = %d AND target_id = %d ) OR ( source_id = %d AND target_id = %d ) )", array( $this->pid(), $pillar_id, (int) $m['url_id'], (int) $m['url_id'], $pillar_id ) );
					if ( ! $linked ) {
						$missing[] = $m['url'];
					}
				}
			}
			$r['unlinked_members'] = $missing;
		}
		return $rows;
	}
}
