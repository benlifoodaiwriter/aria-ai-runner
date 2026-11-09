<?php
/**
 * AI Content Optimizer (section 2) + bulk AI (section 32).
 *
 * Flow: local "before" analysis → AI optimize task (with GSC queries & site
 * learnings) → HTML sanitizing + guardrail warnings → "after" analysis using
 * the same semantic/entity targets for a fair comparison → pending changes.
 *
 * @package SEOlity
 */

namespace SEOlity\Content;

use SEOlity\AI\Guardrails;
use SEOlity\Analysis\Text;
use SEOlity\Core\Database;
use SEOlity\Plugin;

defined( 'ABSPATH' ) || exit;

final class Optimizer {

	const BULK_TASKS = array( 'titles', 'meta', 'alt', 'products', 'categories', 'posts', 'faq', 'internal_links', 'missing_h1', 'missing_meta' );

	/** @var Plugin */
	private $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Optimize a piece of content.
	 *
	 * @param array $p object_type, object_id, field, content, title, focus_keyword, goal, meta_title, meta_description.
	 * @return array|\WP_Error
	 */
	public function optimize( array $p ) {
		$type    = sanitize_key( $p['object_type'] );
		$id      = (int) $p['object_id'];
		$field   = sanitize_key( $p['field'] );
		$ctx     = $id ? Objects::context( $this->plugin, $type, $id ) : null;
		$content = isset( $p['content'] ) ? (string) $p['content'] : ( $ctx ? (string) $ctx['content'] : '' );
		if ( '' === trim( wp_strip_all_tags( $content ) ) && empty( $p['generate'] ) ) {
			return new \WP_Error( 'seolity_empty', __( 'There is no content to optimize yet. Write a draft or use "Generate".', 'seolity' ), array( 'status' => 400 ) );
		}
		$title   = isset( $p['title'] ) && '' !== $p['title'] ? (string) $p['title'] : ( $ctx ? $ctx['title'] : '' );
		$keyword = isset( $p['focus_keyword'] ) && '' !== $p['focus_keyword'] ? (string) $p['focus_keyword'] : ( $ctx ? (string) $ctx['focus_keyword'] : '' );
		$queries = array();
		if ( $ctx && ! empty( $ctx['url'] ) && $this->plugin->gsc_oauth->is_connected() ) {
			$queries = $this->plugin->gsc_analytics->page_queries( $ctx['url'], 90, 25 );
		}
		if ( '' === $keyword && $queries ) {
			$keyword = $queries[0]['query'];
		}
		$object_kind = $ctx ? $ctx['object_type'] : $this->kind_from_type( $type, $field );

		$input = array(
			'field'              => $field,
			'object_type'        => $object_kind,
			'title'              => $title,
			'focus_keyword'      => $keyword,
			'goal'               => isset( $p['goal'] ) ? sanitize_text_field( $p['goal'] ) : 'improve rankings and usefulness',
			'current_meta_title' => $ctx ? (string) $ctx['meta_title'] : '',
			'current_meta_description' => $ctx ? (string) $ctx['meta_description'] : '',
			'url'                => $ctx ? (string) $ctx['url'] : '',
			'content'            => $content,
			'gsc_queries'        => array_slice( $queries, 0, 20 ),
			'local_analysis'     => $this->compact_checks( $this->plugin->analyzer->analyze( $this->analysis_input( $content, $title, $keyword, $ctx, $object_kind ) ) ),
			'target_length_hint' => 'post_excerpt' === $field || 'short_description' === $field ? 'short (40-120 words)' : 'as needed for intent',
		);
		$res = $this->plugin->ai->run( 'optimize_content', $input, array( 'knowledge_query' => $keyword . ' ' . $object_kind ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$ai        = $res['data'];
		$optimized = Guardrails::clean_html( $ai['optimized_content'] );
		$warnings  = array_merge( (array) $ai['warnings'], Guardrails::inspect_content( $optimized, $content, $ai['focus_keyword'] ) );

		$targets = array(
			'secondary_keywords' => $ai['secondary_keywords'],
			'semantic_terms'     => $ai['semantic_terms'],
			'entities'           => $ai['entities'],
			'intent'             => $ai['search_intent'],
		);
		$before  = $this->plugin->analyzer->analyze( array_merge( $this->analysis_input( $content, $title, $ai['focus_keyword'], $ctx, $object_kind ), $targets ) );
		$after   = $this->plugin->analyzer->analyze(
			array_merge(
				$this->analysis_input( $optimized, $title, $ai['focus_keyword'], $ctx, $object_kind ),
				$targets,
				array(
					'meta_title'       => $ai['suggested_title'],
					'meta_description' => $ai['suggested_meta_description'],
				)
			)
		);

		$batch   = 'opt_' . wp_generate_password( 10, false, false );
		$changes = array();
		if ( $id && Objects::supports( $type, $field ) ) {
			$cid = $this->plugin->changes->propose(
				array(
					'object_type'  => $type,
					'object_id'    => $id,
					'field'        => $field,
					'after'        => $optimized,
					'source'       => 'ai',
					'reason'       => $ai['summary'],
					'ai'           => $res['meta'],
					'keywords'     => array_merge( array( $ai['focus_keyword'] ), $ai['secondary_keywords'] ),
					'score_before' => $before['score'],
					'score_after'  => $after['score'],
					'batch_id'     => $batch,
				)
			);
			if ( ! is_wp_error( $cid ) ) {
				$changes['content'] = $cid;
			}
			if ( in_array( $type, array( 'post', 'term' ), true ) && in_array( $field, array( 'post_content', 'term_description' ), true ) ) {
				foreach ( array( 'meta_title' => 'suggested_title', 'meta_description' => 'suggested_meta_description', 'focus_keyword' => 'focus_keyword' ) as $meta_field => $key ) {
					if ( ! empty( $ai[ $key ] ) ) {
						$mid = $this->plugin->changes->propose(
							array(
								'object_type' => $type,
								'object_id'   => $id,
								'field'       => $meta_field,
								'after'       => $ai[ $key ],
								'reason'      => $ai['summary'],
								'ai'          => $res['meta'],
								'batch_id'    => $batch,
							)
						);
						if ( ! is_wp_error( $mid ) ) {
							$changes[ $meta_field ] = $mid;
						}
					}
				}
			}
		}

		return array(
			'original'  => $content,
			'optimized' => $optimized,
			'ai'        => $ai,
			'before'    => $before,
			'after'     => $after,
			'warnings'  => array_values( array_unique( $warnings ) ),
			'changes'   => $changes,
			'batch_id'  => $batch,
			'meta'      => $res['meta'],
			'queries'   => array_slice( $queries, 0, 10 ),
		);
	}

	/** Local-only analysis (no AI cost), also used by the editor sidebar live score. */
	public function analyze( array $p ) {
		$type  = sanitize_key( isset( $p['object_type'] ) ? $p['object_type'] : 'post' );
		$id    = isset( $p['object_id'] ) ? (int) $p['object_id'] : 0;
		$ctx   = $id ? Objects::context( $this->plugin, $type, $id ) : null;
		$kind  = $ctx ? $ctx['object_type'] : $this->kind_from_type( $type, isset( $p['field'] ) ? $p['field'] : '' );
		$input = $this->analysis_input(
			isset( $p['content'] ) ? (string) $p['content'] : ( $ctx ? $ctx['content'] : '' ),
			isset( $p['title'] ) ? (string) $p['title'] : ( $ctx ? $ctx['title'] : '' ),
			isset( $p['focus_keyword'] ) ? (string) $p['focus_keyword'] : ( $ctx ? $ctx['focus_keyword'] : '' ),
			$ctx,
			$kind
		);
		if ( isset( $p['meta_title'] ) ) {
			$input['meta_title'] = (string) $p['meta_title'];
		}
		if ( isset( $p['meta_description'] ) ) {
			$input['meta_description'] = (string) $p['meta_description'];
		}
		return $this->plugin->analyzer->analyze( $input );
	}

	/** Background: analyze & store score for an object (feeds lists, health score, rules). */
	public function analyze_object( $type, $id ) {
		$ctx = Objects::context( $this->plugin, $type, $id );
		if ( ! $ctx ) {
			return false;
		}
		$analysis = $this->plugin->analyzer->analyze( $this->analysis_input( $ctx['content'], $ctx['title'], $ctx['focus_keyword'], $ctx, $ctx['object_type'] ) );
		$this->plugin->db->upsert(
			'content_analysis',
			array(
				'project_id'    => $this->plugin->db->project_id(),
				'object_type'   => $type,
				'object_id'     => (int) $id,
				'focus_keyword' => $ctx['focus_keyword'] ? $ctx['focus_keyword'] : null,
				'score'         => $analysis['score'],
				'metrics'       => array( 'metrics' => $analysis['metrics'], 'stats' => array_diff_key( $analysis['stats'], array( 'headings' => 1, 'top_terms' => 1 ) ), 'failed' => array_values( array_map( static function ( $c ) {
					return $c['id'];
				}, array_filter( $analysis['checks'], static function ( $c ) {
					return 'fail' === $c['status'];
				} ) ) ) ),
				'content_hash'  => sha1( (string) $ctx['content'] . $ctx['title'] . $ctx['meta_title'] . $ctx['meta_description'] ),
				'analyzed_at'   => Database::now(),
			),
			array( 'focus_keyword', 'score', 'metrics', 'content_hash', 'analyzed_at' )
		);
		return $analysis['score'];
	}

	/**
	 * GSC-aware meta title/description suggestions (creates pending changes).
	 */
	public function meta( $type, $id, $auto = false ) {
		$ctx = Objects::context( $this->plugin, $type, $id );
		if ( ! $ctx ) {
			return new \WP_Error( 'seolity_object', __( 'Object not found.', 'seolity' ), array( 'status' => 404 ) );
		}
		$queries = ( $ctx['url'] && $this->plugin->gsc_oauth->is_connected() ) ? $this->plugin->gsc_analytics->page_queries( $ctx['url'], 90, 15 ) : array();
		$page    = ( $ctx['url'] && $this->plugin->gsc_oauth->is_connected() ) ? $this->plugin->gsc_analytics->page_metrics( $ctx['url'], 28 ) : null;
		$task    = ( $page && $page['impressions'] > 200 ) ? 'ctr_rewrite' : 'meta_tags';
		$res     = $this->plugin->ai->run(
			$task,
			array(
				'object_type'      => $ctx['object_type'],
				'title'            => $ctx['title'],
				'url'              => $ctx['url'],
				'current_title'    => $ctx['meta_title'] ? $ctx['meta_title'] : $ctx['title'],
				'current_description' => $ctx['meta_description'],
				'focus_keyword'    => $ctx['focus_keyword'],
				'content_summary'  => Text::truncate( Text::strip( $ctx['content'] ), 1500 ),
				'gsc_page'         => $page,
				'gsc_queries'      => $queries,
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$d     = $res['data'];
		$title = 'ctr_rewrite' === $task ? $d['title'] : $d['recommended_title'];
		$desc  = 'ctr_rewrite' === $task ? $d['meta_description'] : $d['recommended_description'];
		$why   = 'ctr_rewrite' === $task ? $d['diagnosis'] . ' ' . $d['expected_impact'] : $d['expected_impact'];
		$batch = 'meta_' . wp_generate_password( 10, false, false );
		$ids   = array();
		foreach ( array( 'meta_title' => $title, 'meta_description' => $desc ) as $field => $value ) {
			$cid = $this->plugin->changes->propose(
				array(
					'object_type'        => $type,
					'object_id'          => $id,
					'field'              => $field,
					'after'              => $value,
					'reason'             => $why,
					'ai'                 => $res['meta'],
					'keywords'           => array_slice( wp_list_pluck( $queries, 'query' ), 0, 5 ),
					'batch_id'           => $batch,
					'auto_apply_allowed' => $auto,
				)
			);
			if ( ! is_wp_error( $cid ) ) {
				$ids[ $field ] = $cid;
			}
		}
		return array( 'task' => $task, 'ai' => $d, 'changes' => $ids, 'meta' => $res['meta'], 'current' => array( 'title' => $ctx['meta_title'] ? $ctx['meta_title'] : $ctx['title'], 'description' => $ctx['meta_description'] ) );
	}

	/**
	 * Queue a bulk AI operation; each object becomes its own job.
	 *
	 * @return array{batch_id:string, queued:int}|\WP_Error
	 */
	public function queue_bulk( $task, array $ids, $object_type = 'post' ) {
		if ( ! in_array( $task, self::BULK_TASKS, true ) ) {
			return new \WP_Error( 'seolity_bulk', __( 'Unknown bulk task.', 'seolity' ), array( 'status' => 400 ) );
		}
		$ids   = array_slice( array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) ), 0, 500 );
		$batch = 'bulk_' . wp_generate_password( 10, false, false );
		$n     = 0;
		foreach ( $ids as $id ) {
			$n += $this->plugin->queue->push(
				'bulk_item',
				array( 'task' => $task, 'object_type' => $object_type, 'object_id' => $id, 'batch_id' => $batch, 'user_id' => get_current_user_id() ),
				array( 'group' => $batch, 'priority' => 20, 'max_attempts' => 2 )
			) ? 1 : 0;
		}
		$this->plugin->audit_log->log( 'bulk.queued', $object_type, null, array( 'task' => $task, 'count' => $n, 'batch' => $batch ) );
		\SEOlity\Queue\Scheduler::kick();
		return array( 'batch_id' => $batch, 'queued' => $n );
	}

	public function bulk_status( $batch ) {
		$batch = sanitize_key( $batch );
		return array(
			'remaining' => $this->plugin->queue->pending_in_group( $batch ),
			'changes'   => (int) $this->plugin->db->value( 'SELECT COUNT(*) FROM {changes} WHERE batch_id = %s', array( $batch ) ),
			'applied'   => (int) $this->plugin->db->value( "SELECT COUNT(*) FROM {changes} WHERE batch_id = %s AND status = 'applied'", array( $batch ) ),
		);
	}

	/** Worker for one bulk item. */
	public function bulk_item( array $a ) {
		if ( ! empty( $a['user_id'] ) ) {
			wp_set_current_user( (int) $a['user_id'] ); // Attribute & respect the requesting user's caps.
		}
		$type = sanitize_key( $a['object_type'] );
		$id   = (int) $a['object_id'];
		switch ( $a['task'] ) {
			case 'titles':
			case 'meta':
			case 'missing_meta':
				$res = $this->meta( $type, $id, true );
				break;
			case 'alt':
				$res = $this->plugin->images->generate( array( $id ), true, $a['batch_id'] );
				break;
			case 'products':
				$res = $this->plugin->products->optimize( $id, array( 'fields' => array( 'meta_title', 'meta_description', 'post_excerpt', 'focus_keyword' ), 'batch_id' => $a['batch_id'] ) );
				break;
			case 'categories':
			case 'posts':
				$field = 'term' === $type ? 'term_description' : 'post_content';
				$res   = $this->optimize( array( 'object_type' => $type, 'object_id' => $id, 'field' => $field ) );
				break;
			case 'faq':
				$res = $this->faq( $id );
				break;
			case 'internal_links':
				$res = $this->plugin->links->suggest_for( $id );
				break;
			case 'missing_h1':
				$res = $this->fix_missing_h1( $id );
				break;
			default:
				$res = new \WP_Error( 'seolity_bulk', 'Unknown task' );
		}
		if ( is_wp_error( $res ) && in_array( $res->get_error_code(), array( 'seolity_ai_rate_limited' ), true ) ) {
			throw new \RuntimeException( $res->get_error_message() ); // Retry with backoff.
		}
		return true;
	}

	/** Generate FAQ (stored as pending faq_schema change; rendered as FAQ schema + optional block). */
	public function faq( $post_id ) {
		$ctx = Objects::context( $this->plugin, 'post', $post_id );
		if ( ! $ctx ) {
			return new \WP_Error( 'seolity_object', __( 'Object not found.', 'seolity' ) );
		}
		$questions = $this->plugin->gsc_oauth->is_connected() ? array_values( array_filter( $this->plugin->gsc_analytics->page_queries( $ctx['url'], 90, 50 ), static function ( $q ) {
			return (bool) preg_match( '/^(how|what|why|when|which|can|is|does|do|where)\b|چ(ه|طور|گونه|را)|آیا|کدام|کی\b|چند/u', $q['query'] );
		} ) ) : array();
		$res = $this->plugin->ai->run(
			'faq_generate',
			array(
				'title'          => $ctx['title'],
				'url'            => $ctx['url'],
				'content'        => Text::truncate( Text::strip( $ctx['content'] ), 6000 ),
				'question_queries' => array_slice( wp_list_pluck( $questions, 'query' ), 0, 15 ),
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return $this->plugin->changes->propose(
			array(
				'object_type'        => 'post',
				'object_id'          => $post_id,
				'field'              => 'faq_schema',
				'after'              => wp_json_encode( $res['data']['faqs'], JSON_UNESCAPED_UNICODE ),
				'reason'             => __( 'FAQ generated from page content and real question queries.', 'seolity' ),
				'ai'                 => $res['meta'],
				'auto_apply_allowed' => true,
			)
		);
	}

	/**
	 * E-E-A-T analysis of one object (read-only; nothing is changed).
	 *
	 * Combines page content with site-level trust signals the model cannot see
	 * from the page alone (author profile, about/contact/policy pages, reviews).
	 */
	public function eeat( $type, $id ) {
		$ctx = Objects::context( $this->plugin, $type, $id );
		if ( ! $ctx ) {
			return new \WP_Error( 'seolity_object', __( 'Object not found.', 'seolity' ), array( 'status' => 404 ) );
		}
		$input = array(
			'title'   => $ctx['title'],
			'url'     => $ctx['url'],
			'kind'    => $ctx['object_type'],
			'content' => Text::truncate( Text::strip( (string) $ctx['content'] ), 6000 ),
			'site'    => $this->trust_signals(),
		);
		if ( 'post' === $type ) {
			$post   = get_post( $id );
			$author = $post ? get_userdata( (int) $post->post_author ) : null;
			$input['published'] = $post ? $post->post_date_gmt : '';
			$input['modified']  = $post ? $post->post_modified_gmt : '';
			$input['author']    = $author ? array(
				'name'        => $author->display_name,
				'bio_words'   => Text::word_count( (string) $author->description ),
				'bio'         => Text::truncate( (string) $author->description, 400 ),
				'has_website' => '' !== (string) $author->user_url,
				'posts'       => (int) count_user_posts( $author->ID, 'post', true ),
			) : null;
			if ( $post && 'product' === $post->post_type && function_exists( 'wc_get_product' ) ) {
				$product = wc_get_product( $id );
				if ( $product ) {
					$input['reviews'] = array( 'count' => (int) $product->get_review_count(), 'average' => (float) $product->get_average_rating() );
				}
			}
		}
		$res = $this->plugin->ai->run( 'eeat_analysis', $input );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return array( 'analysis' => $res['data'], 'signals' => $input['site'], 'meta' => $res['meta'] );
	}

	/** Site-level trust signals (pages that exist, HTTPS, contact data). */
	private function trust_signals() {
		$find = static function ( array $slugs ) {
			foreach ( $slugs as $slug ) {
				$page = get_page_by_path( $slug );
				if ( $page && 'publish' === $page->post_status ) {
					return get_permalink( $page );
				}
			}
			return null;
		};
		$privacy = (int) get_option( 'wp_page_for_privacy_policy' );
		return array(
			'https'          => 0 === strpos( home_url(), 'https://' ),
			'about_page'     => $find( array( 'about', 'about-us', 'درباره-ما' ) ),
			'contact_page'   => $find( array( 'contact', 'contact-us', 'تماس-با-ما' ) ),
			'privacy_policy' => $privacy && 'publish' === get_post_status( $privacy ) ? get_permalink( $privacy ) : null,
			'returns_policy' => $find( array( 'refund_returns', 'returns', 'refund-policy', 'shipping-returns', 'شرایط-بازگشت-کالا' ) ),
			'organization'   => array_filter(
				array(
					'name'    => (string) $this->plugin->settings->get( 'org_name' ),
					'phone'   => '' !== (string) $this->plugin->settings->get( 'org_phone' ),
					'address' => '' !== (string) $this->plugin->settings->get( 'org_address' ),
					'same_as' => count( (array) $this->plugin->settings->get( 'org_same_as' ) ),
				)
			),
		);
	}

	/** Content with no H1 and a theme that doesn't print one: propose a title-based H1? We only fix empty titles. */
	private function fix_missing_h1( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || '' !== trim( $post->post_title ) ) {
			return true;
		}
		$text = Text::strip( $post->post_content );
		$ngrams = array_keys( Text::top_ngrams( $text, 3 ) );
		if ( ! $ngrams ) {
			return true;
		}
		return $this->plugin->changes->propose(
			array(
				'object_type' => 'post',
				'object_id'   => $post_id,
				'field'       => 'post_title',
				'after'       => mb_convert_case( $ngrams[0], MB_CASE_TITLE, 'UTF-8' ),
				'source'      => 'rule',
				'reason'      => __( 'Page has an empty title, so no H1 / title tag is generated.', 'seolity' ),
			)
		);
	}

	private function analysis_input( $content, $title, $keyword, $ctx, $kind ) {
		return array(
			'content'          => $content,
			'title'            => $title,
			'meta_title'       => $ctx ? (string) $ctx['meta_title'] : '',
			'meta_description' => $ctx ? (string) $ctx['meta_description'] : '',
			'focus_keyword'    => $keyword,
			'slug'             => $ctx ? (string) $ctx['slug'] : '',
			'object_type'      => $kind,
		);
	}

	private function kind_from_type( $type, $field ) {
		if ( 'term' === $type ) {
			return 'term';
		}
		if ( 'user' === $type ) {
			return 'user';
		}
		return 'post';
	}

	private function compact_checks( array $analysis ) {
		return array(
			'score'  => $analysis['score'],
			'issues' => array_values( array_map( static function ( $c ) {
				return $c['message'];
			}, array_filter( $analysis['checks'], static function ( $c ) {
				return 'pass' !== $c['status'];
			} ) ) ),
			'words'  => $analysis['stats']['words'],
		);
	}
}
