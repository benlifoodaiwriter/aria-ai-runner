<?php
/**
 * "Let AI do it": the SEOlity agent executes a recommendation on the site, step by step.
 *
 *  1. plan( rec )  — what it will do, in plain words, before anything changes (shown for approval).
 *  2. start( rec ) — after approval: an activity (live card) and a queue job.
 *  3. run( id )    — executes the steps, reporting each one live ("Adding a link to … in …").
 *
 * Every content change goes through the change manager (versioned, undo in AI Center → AI
 * Changes). Steps already finished are remembered, so a retry (e.g. while the AI answers through
 * GitHub) continues where it stopped instead of repeating work.
 *
 * @package SEOlity
 */

namespace SEOlity\Agent;

use SEOlity\Activity\Activity;
use SEOlity\Content\Objects;
use SEOlity\Core\Database;
use SEOlity\Plugin;
use SEOlity\Roadmap\Guides;

defined( 'ABSPATH' ) || exit;

final class Agent {

	/** Actions the agent can carry out alone. */
	const RUNNABLE = array( 'optimize_meta', 'optimize_content', 'optimize_product', 'generate_faq', 'set_field', 'create_redirect', 'internal_links_to', 'apply_link', 'fix_redirect_chains', 'bulk', 'plan_content' );

	/** @var Plugin */
	private $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	private function label( $type, $id ) {
		$l = $type && $id ? Objects::label( $type, (int) $id ) : '';
		return $l ? $l : __( 'this page', 'seolity' );
	}

	/** Manual guide + agent plan for the "Do it" popup. */
	public function guide( $rec_id ) {
		$r = $this->plugin->recommendations->get( (int) $rec_id );
		if ( ! $r ) {
			return new \WP_Error( 'seolity_rec', __( 'Recommendation not found.', 'seolity' ), array( 'status' => 404 ) );
		}
		$a    = (array) $r['action'];
		$type = isset( $a['type'] ) ? $a['type'] : '';
		$edit = '';
		if ( ! empty( $r['object_id'] ) && 'post' === $r['object_type'] ) {
			$edit = admin_url( 'post.php?post=' . (int) $r['object_id'] . '&action=edit' );
		}
		return array(
			'id'        => (int) $r['id'],
			'title'     => $r['title'],
			'reason'    => $r['reason'],
			'priority'  => $r['priority'],
			'url'       => $r['url'],
			'edit'      => $edit,
			'evidence'  => $r['evidence'],
			'guide'     => Guides::steps( $r['type'], array( 'route' => isset( $a['route'] ) ? ltrim( (string) $a['route'], '#/' ) : '' ) ),
			'done_when' => Guides::done_when( $r['type'] ),
			'plan'      => $this->plan( $r ),
			'can_run'   => in_array( $type, self::RUNNABLE, true ),
			'ai_ready'  => $this->plugin->ai->is_ready(),
		);
	}

	/** What the agent will do, in plain words. */
	public function plan( array $r ) {
		$a     = (array) $r['action'];
		$type  = isset( $a['type'] ) ? $a['type'] : '';
		$obj   = isset( $a['object_type'] ) ? $this->label( $a['object_type'], isset( $a['object_id'] ) ? $a['object_id'] : 0 ) : '';
		$seo   = \SEOlity\Admin\EditorIntegration::engine_name( $this->plugin->meta->engine() );
		$steps = array();
		switch ( $type ) {
			case 'optimize_meta':
				/* translators: %s: page */
				$steps[] = sprintf( __( 'Read the real Search Console queries and the content of “%s”.', 'seolity' ), $obj );
				$steps[] = __( 'Write a new SEO title and meta description with AI (SEO knowledge base + competitor results).', 'seolity' );
				/* translators: 1 SEO plugin 2 page */
				$steps[] = sprintf( __( 'Save them in %1$s for “%2$s” (a new version; undo anytime).', 'seolity' ), $seo, $obj );
				$steps[] = __( 'Track the result as an experiment (CTR after 14–28 days).', 'seolity' );
				break;
			case 'optimize_content':
				/* translators: %s: page */
				$steps[] = sprintf( __( 'Read “%s”, its Search Console queries, the knowledge base and competitor pages.', 'seolity' ), $obj );
				/* translators: %s: the goal */
				$steps[] = sprintf( __( 'Rewrite and expand the content with AI — goal: %s', 'seolity' ), $r['reason'] );
				$steps[] = __( 'Check the result (no keyword stuffing, links and shortcodes kept, score before vs. after).', 'seolity' );
				/* translators: %s: page */
				$steps[] = sprintf( __( 'Update “%s” (a new version; undo anytime).', 'seolity' ), $obj );
				break;
			case 'optimize_product':
				/* translators: %s: product */
				$steps[] = sprintf( __( 'Read the product data of “%s” (attributes, price, categories, images, queries).', 'seolity' ), $obj );
				$steps[] = __( 'Write SEO title, meta description, short description, focus keywords and image ALT with AI.', 'seolity' );
				$steps[] = __( 'Save every field (each one versioned; undo anytime).', 'seolity' );
				break;
			case 'generate_faq':
				/* translators: %s: page */
				$steps[] = sprintf( __( 'Collect the question queries people search for “%s”.', 'seolity' ), $obj );
				$steps[] = __( 'Write short, factual answers with AI.', 'seolity' );
				$steps[] = __( 'Add the FAQ (with FAQPage schema) to the page.', 'seolity' );
				break;
			case 'set_field':
				/* translators: 1 field 2 page */
				$steps[] = sprintf( __( 'Change “%1$s” of “%2$s” (a new version; undo anytime).', 'seolity' ), Activity::field_label( (string) $a['field'] ), $obj );
				break;
			case 'create_redirect':
				$target = ! empty( $a['target'] ) ? $a['target'] : __( 'the best matching live page', 'seolity' );
				/* translators: 1 source 2 target */
				$steps[] = sprintf( __( 'Create a 301 redirect from %1$s to %2$s.', 'seolity' ), urldecode( $a['source'] ), urldecode( $target ) );
				break;
			case 'internal_links_to':
				/* translators: %s: page */
				$steps[] = sprintf( __( 'Search the whole site for pages related to “%s” that already mention its topic.', 'seolity' ), $obj );
				$cands = $this->link_candidates( (int) $a['object_id'], false );
				foreach ( $cands as $l ) {
					/* translators: 1 anchor 2 source page */
					$steps[] = sprintf( __( 'Add a link with the text “%1$s” in “%2$s”.', 'seolity' ), $l['anchor'], $l['source'] );
				}
				if ( ! $cands ) {
					foreach ( $this->related_sources( (int) $a['object_id'] ) as $sid ) {
						/* translators: %s: source page */
						$steps[] = sprintf( __( 'Write one natural sentence with a link into “%s” (AI, using the whole site’s content).', 'seolity' ), get_the_title( $sid ) );
					}
				}
				$steps[] = __( 'Save each edited page (versioned; undo anytime).', 'seolity' );
				break;
			case 'apply_link':
				$p = $this->plugin->links->preview( (int) $a['link_id'] );
				if ( ! is_wp_error( $p ) ) {
					/* translators: 1 anchor 2 source page */
					$steps[] = sprintf( __( 'Add a link with the text “%1$s” in “%2$s”.', 'seolity' ), $p['anchor'], $p['source'] );
				}
				break;
			case 'fix_redirect_chains':
				$steps[] = __( 'Point every redirect of a chain straight to its final URL (loops are left for you to decide).', 'seolity' );
				break;
			case 'bulk':
				/* translators: %d: items */
				$steps[] = sprintf( __( 'Run the AI on %d items in the background, one by one.', 'seolity' ), count( (array) $a['ids'] ) );
				break;
			case 'plan_content':
				$steps[] = __( 'Add the topic to the content planner with an AI brief (headings, questions, length).', 'seolity' );
				break;
			default:
				$steps[] = __( 'This task needs your decision; follow the steps on the left. SEOlity can open the right screen for you.', 'seolity' );
		}
		return $steps;
	}

	/** Pending link suggestions to a page (created once, reused by plan and run). */
	private function link_candidates( $post_id, $create = true ) {
		$db  = $this->plugin->db;
		$url = (string) get_permalink( (int) $post_id );
		$tid = (int) $db->value( 'SELECT id FROM {urls} WHERE project_id = %d AND url_hash = %s', array( $db->project_id(), Database::url_hash( $url ) ) );
		if ( ! $tid ) {
			return array();
		}
		$ids = $db->column( "SELECT id FROM {internal_links} WHERE project_id = %d AND target_id = %d AND link_type = 'suggested' AND status = 'pending' ORDER BY relevance DESC LIMIT 3", array( $db->project_id(), $tid ) );
		if ( ! $ids ) {
			$res = $this->plugin->links->suggest_to( (int) $post_id );
			if ( is_wp_error( $res ) ) {
				return array();
			}
			$ids = array_slice( wp_list_pluck( (array) $res['suggestions'], 'id' ), 0, 3 );
		}
		$out = array();
		foreach ( $ids as $id ) {
			$p = $this->plugin->links->preview( (int) $id );
			if ( ! is_wp_error( $p ) && $p['possible'] ) {
				$out[] = $p;
			}
		}
		return $out;
	}

	/* ------------------------------------------------------------------ running */

	public function start( $rec_id ) {
		$g = $this->guide( $rec_id );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		if ( ! $g['can_run'] ) {
			return new \WP_Error( 'seolity_agent', __( 'This task needs your decision; SEOlity cannot do it alone.', 'seolity' ), array( 'status' => 400 ) );
		}
		// One run per task: a second click shows the run that is already queued or working.
		$alive = $this->plugin->queue->find_unique( 'agent_run', 'agent_' . (int) $rec_id );
		if ( $alive ) {
			$aid = (int) $this->plugin->db->value( 'SELECT id FROM {activity} WHERE job_id = %d ORDER BY id DESC LIMIT 1', array( $alive ) );
			if ( $aid ) {
				return $this->plugin->activity->get( $aid, false );
			}
		}
		$id = $this->plugin->activity->start(
			'agent',
			__( 'AI is doing it', 'seolity' ),
			array(
				'detail' => $g['title'],
				'dock'   => true,
				'status' => 'queued',
				'step'   => __( 'Queued — starting in a moment…', 'seolity' ),
				'meta'   => array( 'type' => 'agent', 'rec_id' => (int) $rec_id, 'plan' => $g['plan'], 'subject' => $g['title'], 'state' => array() ),
			)
		);
		$job = $this->plugin->queue->push( 'agent_run', array( 'id' => $id, 'user_id' => get_current_user_id() ), array( 'unique' => 'agent_' . (int) $rec_id, 'priority' => 0, 'max_attempts' => 2 ) );
		$this->plugin->activity->attach_job( $id, $job );
		\SEOlity\Activity\AiJobs::run_after_response( $this->plugin );
		return $this->plugin->activity->get( $id, false );
	}

	/** Queue handler. */
	public function run( $id ) {
		$act = $this->plugin->activity;
		$a   = $act->get( (int) $id );
		if ( ! $a || ! in_array( $a['status'], array( 'running', 'waiting' ), true ) ) {
			return true;
		}
		if ( $a['user_id'] ) {
			wp_set_current_user( (int) $a['user_id'] );
		}
		$r = $this->plugin->recommendations->get( (int) $a['meta']['rec_id'] );
		if ( ! $r ) {
			$act->finish( $id, 'failed', __( 'Recommendation not found.', 'seolity' ) );
			return true;
		}
		$prev  = Activity::set_current( (int) $id );
		$state = isset( $a['meta']['state'] ) ? (array) $a['meta']['state'] : array();
		$save  = function () use ( $id, &$state ) {
			$this->plugin->db->update( 'activity', array( 'meta' => wp_json_encode( array_merge( (array) $this->plugin->activity->get( $id )['meta'], array( 'state' => $state ) ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ), array( 'id' => (int) $id ) );
		};
		try {
			$result = $this->execute( (int) $id, $r, $state, $save );
			if ( is_wp_error( $result ) ) {
				$act->finish( $id, 'failed', $result->get_error_message(), array( 'state' => $state ), array( 'step' => __( 'Failed', 'seolity' ) ) );
				return true;
			}
			$this->plugin->recommendations->mark( (int) $r['id'], 'done' );
			$act->finish( $id, 'done', $r['title'], array( 'state' => $state, 'summary' => __( 'Done — every change is versioned (undo in AI Center → AI Changes).', 'seolity' ), 'lines' => $result, 'result' => array( 'lines' => $result, 'rec' => (int) $r['id'] ) ), array( 'step' => __( 'Finished', 'seolity' ) ) );
			return true;
		} finally {
			Activity::set_current( $prev );
		}
	}

	/** Related published posts for a target (title words, same category), best first. */
	private function related_sources( $post_id, $limit = 2 ) {
		$target = get_post( (int) $post_id );
		if ( ! $target ) {
			return array();
		}
		$words = array_slice( array_filter( \SEOlity\Analysis\Text::words( $target->post_title ), static function ( $w ) {
			return mb_strlen( $w ) > 2;
		} ), 0, 3 );
		$ids   = array();
		$types = array( 'post', 'page' );
		foreach ( $words as $w ) {
			$ids = array_merge( $ids, get_posts( array( 'post_type' => $types, 'post_status' => 'publish', 's' => $w, 'posts_per_page' => 5, 'fields' => 'ids', 'post__not_in' => array( (int) $post_id ) ) ) );
		}
		$cats = wp_get_post_categories( (int) $post_id );
		if ( $cats ) {
			$ids = array_merge( $ids, get_posts( array( 'post_type' => 'post', 'post_status' => 'publish', 'category__in' => $cats, 'posts_per_page' => 5, 'fields' => 'ids', 'post__not_in' => array( (int) $post_id ) ) ) );
		}
		$counts = array_count_values( array_map( 'intval', $ids ) );
		arsort( $counts );
		$out = array();
		foreach ( array_keys( $counts ) as $sid ) {
			$c = (string) get_post_field( 'post_content', $sid );
			if ( false === strpos( $c, (string) get_permalink( (int) $post_id ) ) && str_word_count( wp_strip_all_tags( $c ) ) + mb_strlen( wp_strip_all_tags( $c ) ) / 6 > 60 ) {
				$out[] = (int) $sid;
			}
			if ( count( $out ) >= $limit ) {
				break;
			}
		}
		return $out;
	}

	/** AI writes one natural sentence with the link into each related page. */
	private function write_links( $id, $post_id, array &$state, callable $save, array $done ) {
		$act     = $this->plugin->activity;
		$target  = get_post( $post_id );
		$url     = (string) get_permalink( $post_id );
		$sources = $this->related_sources( $post_id );
		if ( ! $sources ) {
			return new \WP_Error( 'seolity_agent', __( 'No related page was found to link from. Publish a related article first, or add the link by hand.', 'seolity' ) );
		}
		$state['written'] = isset( $state['written'] ) ? (array) $state['written'] : array();
		foreach ( $sources as $i => $sid ) {
			if ( in_array( $sid, array_map( 'intval', $state['written'] ), true ) ) {
				continue;
			}
			$title = get_the_title( $sid );
			/* translators: %s: source page */
			$act->step( $id, sprintf( __( 'Reading “%s” and writing a sentence that links to the page…', 'seolity' ), $title ), 20 + 35 * $i );
			$html = (string) get_post_field( 'post_content', $sid );
			$res  = $this->plugin->ai->run(
				'link_sentence',
				array(
					'source_title'  => $title,
					'source_text'   => \SEOlity\Analysis\Text::truncate( \SEOlity\Analysis\Text::strip( $html ), 5000 ),
					'target_title'  => $target->post_title,
					'target_url'    => $url,
					'focus_keyword' => (string) $this->plugin->meta->get_post( $post_id, 'focus_keyword' ),
				),
				array( 'knowledge_query' => 'internal links anchor text' )
			);
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			$d = $res['data'];
			if ( empty( $d['fits'] ) || false === strpos( (string) $d['sentence_html'], $url ) ) {
				/* translators: %s: source page */
				$act->step( $id, sprintf( __( 'Skipped “%s”: the topics do not fit together.', 'seolity' ), $title ) );
				$state['written'][] = $sid;
				$save();
				continue;
			}
			$sentence = ' ' . wp_kses( (string) $d['sentence_html'], array( 'a' => array( 'href' => true ) ) );
			$after    = $this->insert_after_text( $html, (string) $d['after_text'], $sentence );
			/* translators: %s: source page */
			$act->step( $id, sprintf( __( 'Adding the link in “%s”…', 'seolity' ), $title ), 40 + 35 * $i );
			$cid = $this->plugin->changes->propose(
				array(
					'object_type' => 'post',
					'object_id'   => $sid,
					'field'       => 'post_content',
					'after'       => $after,
					'source'      => 'internal_link',
					/* translators: 1 anchor 2 url */
					'reason'      => sprintf( __( 'Add internal link "%1$s" → %2$s', 'seolity' ), $d['anchor'], $url ),
					'ai'          => $res['meta'],
				)
			);
			if ( is_wp_error( $cid ) ) {
				return $cid;
			}
			$ok = $this->apply_change( $cid );
			if ( is_wp_error( $ok ) ) {
				return $ok;
			}
			/* translators: 1 anchor 2 source page */
			$done[]             = sprintf( __( 'Link “%1$s” written into “%2$s”', 'seolity' ), $d['anchor'], $title );
			$state['written'][] = $sid;
			$state['lines']     = $done;
			$save();
			$act->step( $id, end( $done ), null, array( 'ok' => true ) );
		}
		return $done ? $done : new \WP_Error( 'seolity_agent', __( 'The related pages are about different topics, so no natural link could be added.', 'seolity' ) );
	}

	/** Put $add right after the paragraph that contains $needle (or at the end of the content). */
	private function insert_after_text( $html, $needle, $add ) {
		$needle = trim( $needle );
		$pos    = '' !== $needle ? mb_stripos( $html, $needle ) : false;
		if ( false !== $pos ) {
			$end = mb_stripos( $html, '</p>', $pos );
			if ( false !== $end ) {
				return mb_substr( $html, 0, $end ) . $add . mb_substr( $html, $end );
			}
			$cut = $pos + mb_strlen( $needle );
			return mb_substr( $html, 0, $cut ) . $add . mb_substr( $html, $cut );
		}
		return rtrim( $html ) . "\n\n<p>" . trim( $add ) . '</p>';
	}

	/** Apply a proposed change right away (the owner approved the plan). */
	private function apply_change( $cid ) {
		$res = $this->plugin->changes->apply( (int) $cid );
		return is_wp_error( $res ) ? $res : true;
	}

	/**
	 * @return string[]|\WP_Error Lines describing what was done.
	 */
	private function execute( $id, array $r, array &$state, callable $save ) {
		$act  = $this->plugin->activity;
		$a    = (array) $r['action'];
		$type = isset( $a['type'] ) ? $a['type'] : '';
		$obj  = isset( $a['object_type'] ) ? $this->label( $a['object_type'], isset( $a['object_id'] ) ? $a['object_id'] : 0 ) : '';
		$done = isset( $state['lines'] ) ? (array) $state['lines'] : array();
		$step = function ( $text, $pct ) use ( $act, $id ) {
			// Safe point between steps: a cancel request stops the run here, before the next write.
			$this->plugin->queue->checkpoint( $pct );
			$act->step( $id, $text, $pct );
		};
		switch ( $type ) {
			case 'optimize_meta':
				/* translators: %s: page */
				$step( sprintf( __( 'Reading the search queries of “%s”…', 'seolity' ), $obj ), 15 );
				$res = $this->plugin->optimizer->meta( $a['object_type'], (int) $a['object_id'] );
				if ( is_wp_error( $res ) ) {
					return $res;
				}
				$step( __( 'Saving the new SEO title and meta description…', 'seolity' ), 75 );
				foreach ( $res['changes'] as $field => $cid ) {
					$ok = $this->apply_change( $cid );
					if ( is_wp_error( $ok ) ) {
						return $ok;
					}
					/* translators: 1 field 2 page */
					$done[] = sprintf( __( '%1$s updated on “%2$s”', 'seolity' ), Activity::field_label( $field ), $obj );
					$act->step( $id, end( $done ), 85, array( 'ok' => true ) );
				}
				return $done;

			case 'optimize_content':
				/* translators: %s: page */
				$step( sprintf( __( 'Analyzing “%s” and writing the improved version with AI…', 'seolity' ), $obj ), 20 );
				$field = isset( $a['field'] ) ? $a['field'] : ( 'term' === $a['object_type'] ? 'term_description' : 'post_content' );
				$res   = $this->plugin->optimizer->optimize( array( 'object_type' => $a['object_type'], 'object_id' => (int) $a['object_id'], 'field' => $field, 'goal' => $r['reason'] ) );
				if ( is_wp_error( $res ) ) {
					return $res;
				}
				if ( empty( $res['changes']['content'] ) ) {
					return new \WP_Error( 'seolity_agent', __( 'The AI produced no usable change.', 'seolity' ) );
				}
				/* translators: 1 before 2 after */
				$step( sprintf( __( 'SEO score %1$s → %2$s — updating the page…', 'seolity' ), $res['before']['score'], $res['after']['score'] ), 80 );
				$ok = $this->apply_change( $res['changes']['content'] );
				if ( is_wp_error( $ok ) ) {
					return $ok;
				}
				/* translators: 1 page 2 before 3 after */
				$done[] = sprintf( __( '“%1$s” rewritten (SEO score %2$s → %3$s)', 'seolity' ), $obj, $res['before']['score'], $res['after']['score'] );
				return $done;

			case 'optimize_product':
				/* translators: %s: product */
				$step( sprintf( __( 'Optimizing the product “%s” with AI…', 'seolity' ), $obj ), 20 );
				$res = $this->plugin->products->optimize( (int) $a['object_id'] );
				if ( is_wp_error( $res ) ) {
					return $res;
				}
				$i = 0;
				foreach ( $res['changes'] as $field => $cid ) {
					$i++;
					$label = 0 === strpos( $field, 'alt_' ) ? __( 'Image ALT', 'seolity' ) : Activity::field_label( $field );
					/* translators: %s: field */
					$step( sprintf( __( 'Saving %s…', 'seolity' ), $label ), 40 + (int) ( 50 * $i / max( 1, count( $res['changes'] ) ) ) );
					$ok = $this->apply_change( $cid );
					if ( ! is_wp_error( $ok ) ) {
						/* translators: %s: field */
						$done[] = sprintf( __( '%s saved', 'seolity' ), $label );
					}
				}
				return $done;

			case 'generate_faq':
				/* translators: %s: page */
				$step( sprintf( __( 'Writing the FAQ for “%s”…', 'seolity' ), $obj ), 30 );
				$cid = $this->plugin->optimizer->faq( (int) $a['object_id'] );
				if ( is_wp_error( $cid ) ) {
					return $cid;
				}
				$ok = $this->apply_change( $cid );
				if ( is_wp_error( $ok ) ) {
					return $ok;
				}
				/* translators: %s: page */
				$done[] = sprintf( __( 'FAQ added to “%s”', 'seolity' ), $obj );
				return $done;

			case 'set_field':
				$cid = $this->plugin->changes->propose( array( 'object_type' => $a['object_type'], 'object_id' => (int) $a['object_id'], 'field' => $a['field'], 'after' => $a['value'], 'source' => 'agent', 'reason' => $r['reason'], 'recommendation_id' => (int) $r['id'] ) );
				if ( is_wp_error( $cid ) ) {
					return $cid;
				}
				$ok = $this->apply_change( $cid );
				if ( is_wp_error( $ok ) ) {
					return $ok;
				}
				/* translators: 1 field 2 page */
				$done[] = sprintf( __( '%1$s changed on “%2$s”', 'seolity' ), Activity::field_label( (string) $a['field'] ), $obj );
				return $done;

			case 'create_redirect':
				$target = ! empty( $a['target'] ) ? $a['target'] : '';
				if ( '' === $target ) {
					$c      = $this->plugin->not_found->candidates( $a['source'] );
					$target = $c ? $c[0]['url'] : '';
				}
				if ( '' === $target ) {
					return new \WP_Error( 'seolity_agent', __( 'No matching live page was found; choose the target yourself in Technical SEO → Redirects.', 'seolity' ) );
				}
				/* translators: 1 source 2 target */
				$step( sprintf( __( 'Creating the redirect %1$s → %2$s…', 'seolity' ), urldecode( $a['source'] ), urldecode( $target ) ), 60 );
				$res = $this->plugin->redirects->create( $a['source'], $target, 301, false, 'ai' );
				if ( is_wp_error( $res ) ) {
					return $res;
				}
				/* translators: 1 source 2 target */
				$done[] = sprintf( __( '301 redirect created: %1$s → %2$s', 'seolity' ), urldecode( $a['source'] ), urldecode( $target ) );
				return $done;

			case 'internal_links_to':
				/* translators: %s: page */
				$step( sprintf( __( 'Finding pages across the site that should link to “%s”…', 'seolity' ), $obj ), 10 );
				$links = $this->link_candidates( (int) $a['object_id'] );
				if ( ! $links ) {
					// No existing anchor text: write a linking sentence into related pages with AI.
					return $this->write_links( $id, (int) $a['object_id'], $state, $save, $done );
				}
				$state['links'] = isset( $state['links'] ) ? (array) $state['links'] : array();
				$i              = 0;
				foreach ( $links as $l ) {
					$i++;
					if ( in_array( (int) $l['id'], array_map( 'intval', $state['links'] ), true ) ) {
						continue;
					}
					/* translators: 1 anchor 2 source page */
					$step( sprintf( __( 'Adding the link “%1$s” in “%2$s”…', 'seolity' ), $l['anchor'], $l['source'] ), 15 + (int) ( 80 * $i / count( $links ) ) );
					$res = $this->plugin->links->apply( (int) $l['id'] );
					if ( ! is_wp_error( $res ) ) {
						$ok = $this->apply_change( $res['change_id'] );
						if ( ! is_wp_error( $ok ) ) {
							/* translators: 1 anchor 2 source page */
							$done[]           = sprintf( __( 'Link “%1$s” added in “%2$s”', 'seolity' ), $l['anchor'], $l['source'] );
							$state['links'][] = (int) $l['id'];
							$state['lines']   = $done;
							$save();
							$act->step( $id, end( $done ), null, array( 'ok' => true ) );
						}
					}
				}
				return $done ? $done : new \WP_Error( 'seolity_agent', __( 'The suggested anchors are no longer in the source pages.', 'seolity' ) );

			case 'apply_link':
				$step( __( 'Adding the link…', 'seolity' ), 40 );
				$p   = $this->plugin->links->preview( (int) $a['link_id'] );
				$res = $this->plugin->links->apply( (int) $a['link_id'] );
				if ( is_wp_error( $res ) ) {
					return $res;
				}
				$ok = $this->apply_change( $res['change_id'] );
				if ( is_wp_error( $ok ) ) {
					return $ok;
				}
				/* translators: 1 anchor 2 source page */
				$done[] = is_wp_error( $p ) ? __( 'Link added', 'seolity' ) : sprintf( __( 'Link “%1$s” added in “%2$s”', 'seolity' ), $p['anchor'], $p['source'] );
				return $done;

			case 'fix_redirect_chains':
				$step( __( 'Pointing redirect chains straight to their final URL…', 'seolity' ), 50 );
				/* translators: %d: redirects */
				$done[] = sprintf( __( '%d redirects fixed', 'seolity' ), (int) $this->plugin->redirects->fix_chains() );
				return $done;

			case 'bulk':
				$res = $this->plugin->optimizer->queue_bulk( $a['task'], (array) $a['ids'], $a['object_type'] );
				if ( is_wp_error( $res ) ) {
					return $res;
				}
				/* translators: %d: items */
				$done[] = sprintf( __( '%d items queued — follow them in Live activity', 'seolity' ), (int) $res['queued'] );
				return $done;

			case 'plan_content':
				$res = $this->plugin->planner->add_from_opportunity( (array) $a['opportunity'] );
				if ( is_wp_error( $res ) ) {
					return $res;
				}
				$done[] = __( 'Topic added to the content planner', 'seolity' );
				return $done;
		}
		return new \WP_Error( 'seolity_agent', __( 'This task needs your decision; SEOlity cannot do it alone.', 'seolity' ) );
	}
}
