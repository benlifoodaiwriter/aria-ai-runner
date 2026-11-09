<?php
/**
 * WordPress content lifecycle hooks: incremental crawling, background scoring,
 * editor change finalization, automatic slug-change redirects, rule triggers.
 * All handlers are O(1) and defer real work to the queue.
 *
 * @package SEOlity
 */

namespace SEOlity\Content;

use SEOlity\Core\Database;
use SEOlity\Plugin;

defined( 'ABSPATH' ) || exit;

final class ContentEvents {

	/** @var Plugin */
	private $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	public function register_hooks() {
		add_action( 'save_post', array( $this, 'on_save_post' ), 20, 3 );
		add_action( 'post_updated', array( $this, 'on_post_updated' ), 10, 3 );
		add_action( 'wp_trash_post', array( $this, 'on_trash' ) );
		add_action( 'edited_term', array( $this, 'on_term_saved' ), 20, 3 );
		add_action( 'created_term', array( $this, 'on_term_saved' ), 20, 3 );
		add_action( 'add_attachment', array( $this, 'on_attachment' ) );
	}

	private function is_trackable( $post ) {
		if ( ! $post || wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
			return false;
		}
		$type = get_post_type_object( $post->post_type );
		return $type && $type->public && 'attachment' !== $post->post_type;
	}

	public function on_save_post( $post_id, $post, $update ) {
		if ( ! $this->is_trackable( $post ) ) {
			return;
		}
		$this->plugin->changes->finalize_editor_changes( $post_id );
		if ( 'publish' !== $post->post_status ) {
			return;
		}
		$url = get_permalink( $post );
		if ( $url ) {
			$this->mark_dirty( $url, 'post', $post_id );
		}
		$this->plugin->queue->push( 'analyze_object', array( 'object_type' => 'post', 'object_id' => $post_id ), array( 'unique' => 'analyze_post_' . $post_id, 'priority' => 25, 'delay' => 30 ) );
		$this->plugin->queue->push(
			'rules_event',
			array( 'event' => $update ? 'content_updated' : 'content_published', 'context' => array( 'object_type' => 'post', 'object_id' => $post_id, 'post_type' => $post->post_type ) ),
			array( 'unique' => 'rules_post_' . $post_id, 'priority' => 30, 'delay' => 60 )
		);
	}

	/** Slug change on a published post → 301 from the old URL (only when SEOlity owns SEO, to avoid duplicates with Yoast/Rank Math). */
	public function on_post_updated( $post_id, $after, $before ) {
		if ( ! $this->is_trackable( $after ) || 'publish' !== $before->post_status || 'publish' !== $after->post_status ) {
			return;
		}
		// An edit made in WordPress itself (not applied by SEOlity a moment ago) is measured as an experiment.
		$what = array();
		if ( $before->post_title !== $after->post_title ) {
			$what[] = 'title';
		}
		if ( $before->post_content !== $after->post_content ) {
			$what[] = 'content';
		}
		if ( $what ) {
			$own = $this->plugin->db->value( "SELECT id FROM {changes} WHERE object_type = 'post' AND object_id = %d AND applied_at >= %s LIMIT 1", array( (int) $post_id, gmdate( 'Y-m-d H:i:s', time() - 120 ) ) );
			if ( ! $own ) {
				$this->plugin->experiments->start_for_editor( (int) $post_id, implode( '+', $what ) );
			}
		}
		if ( $before->post_name === $after->post_name || 'seolity' !== $this->plugin->meta->engine() || ! $this->plugin->settings->get( 'redirects_enabled' ) ) {
			return;
		}
		$old = get_permalink( $before );
		if ( ! $old ) {
			return;
		}
		$old_path = wp_make_link_relative( str_replace( $after->post_name, $before->post_name, get_permalink( $after ) ) );
		$this->plugin->redirects->create( $old_path, get_permalink( $after ), 301, false, 'slug_change' );
	}

	/** Trashing a page that earns traffic or links → alert with redirect suggestion. */
	public function on_trash( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $this->is_trackable( $post ) || 'publish' !== $post->post_status ) {
			return;
		}
		$url     = get_permalink( $post );
		$metrics = $this->plugin->gsc_oauth->is_connected() ? $this->plugin->gsc_analytics->page_metrics( $url, 28 ) : null;
		$inlinks = (int) $this->plugin->db->value( 'SELECT inlinks FROM {urls} WHERE project_id = %d AND url_hash = %s', array( $this->plugin->db->project_id(), Database::url_hash( $url ) ) );
		if ( ( $metrics && $metrics['clicks'] > 0 ) || $inlinks > 0 ) {
			$this->plugin->alerts->raise(
				'page_removed',
				'high',
				sprintf( /* translators: %s title */ __( 'A page with organic traffic was trashed: %s', 'seolity' ), $post->post_title ),
				sprintf( /* translators: 1 clicks 2 links */ __( 'It received %1$d clicks in the last 28 days and has %2$d internal links. Add a 301 redirect to the closest relevant page to keep its value.', 'seolity' ), $metrics ? $metrics['clicks'] : 0, $inlinks ),
				array( 'url' => $url, 'post_id' => $post_id ),
				'trash_' . $post_id
			);
		}
	}

	public function on_term_saved( $term_id, $tt_id, $taxonomy ) {
		$tax = get_taxonomy( $taxonomy );
		if ( ! $tax || ! $tax->public ) {
			return;
		}
		$url = get_term_link( (int) $term_id );
		if ( ! is_wp_error( $url ) ) {
			$this->mark_dirty( $url, 'term', $term_id );
		}
		$this->plugin->queue->push( 'analyze_object', array( 'object_type' => 'term', 'object_id' => $term_id ), array( 'unique' => 'analyze_term_' . $term_id, 'priority' => 25, 'delay' => 30 ) );
	}

	public function on_attachment( $attachment_id ) {
		if ( wp_attachment_is_image( $attachment_id ) ) {
			$this->plugin->queue->push(
				'rules_event',
				array( 'event' => 'image_uploaded', 'context' => array( 'object_type' => 'attachment', 'object_id' => $attachment_id ) ),
				array( 'priority' => 30, 'delay' => 120 )
			);
		}
	}

	private function mark_dirty( $url, $type, $id ) {
		$db = $this->plugin->db;
		$db->upsert(
			'urls',
			array(
				'project_id'   => $db->project_id(),
				'url'          => \SEOlity\Crawler\PageParser::normalize_url( $url ),
				'url_hash'     => Database::url_hash( $url ),
				'object_type'  => $type,
				'object_id'    => (int) $id,
				'source'       => 'wp',
				'crawl_status' => 'pending',
				'modified_at'  => Database::now(),
			),
			array( 'object_type', 'object_id', 'crawl_status', 'modified_at' )
		);
	}
}
