<?php
/**
 * Duplicate, near-duplicate and cannibalizing articles: detection (content overlap, titles,
 * H1, shared Search Console queries) and the consolidation workflow (pick the primary,
 * AI merge with the owner's instructions, preview, then 301s and internal links — each
 * step only after the previous one succeeded and was verified; nothing is ever deleted).
 *
 * @package SEOlity
 */

namespace SEOlity\Content;

use SEOlity\Analysis\Text;
use SEOlity\Core\Database;
use SEOlity\Plugin;

defined( 'ABSPATH' ) || exit;

final class Duplicates {

	const OPTION       = 'seolity_duplicates';
	const INSTRUCTIONS = 'seolity_duplicates_instructions';
	const MAX_POSTS    = 3000;

	/** @var Plugin */
	private $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	public function last() {
		$r = get_option( self::OPTION );
		return is_array( $r ) ? $r : null;
	}

	/** Standing instructions for every consolidation (reused until changed). */
	public function instructions() {
		return (string) get_option( self::INSTRUCTIONS, '' );
	}

	public function save_instructions( $text ) {
		update_option( self::INSTRUCTIONS, sanitize_textarea_field( (string) $text ), false );
		return $this->instructions();
	}

	private static function norm( $url ) {
		return untrailingslashit( strtolower( rawurldecode( preg_replace( '#^https?://(www\.)?#i', '', (string) $url ) ) ) );
	}

	/** 3-word shingles (hashed) of a text. */
	private static function shingles( array $words ) {
		$out = array();
		$n   = count( $words );
		for ( $i = 0; $i + 2 < $n; $i++ ) {
			$out[ crc32( $words[ $i ] . ' ' . $words[ $i + 1 ] . ' ' . $words[ $i + 2 ] ) ] = true;
		}
		return $out;
	}

	private static function title_tokens( $title ) {
		$stop = array_flip( Text::stopwords( Text::lang( $title ) ) );
		$out  = array();
		foreach ( Text::words( $title ) as $w ) {
			if ( mb_strlen( $w ) > 1 && ! isset( $stop[ $w ] ) ) {
				$out[ $w ] = true;
			}
		}
		return $out;
	}

	/** Run the scan (background job). */
	public function scan() {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		$act   = $this->plugin->activity;
		$db    = $this->plugin->db;
		$pid   = $db->project_id();
		// Blog articles only: pages, products and builder templates are not compared (filterable).
		$types = (array) apply_filters( 'seolity_duplicates_post_types', array( 'post' ) );
		$ids   = get_posts( array( 'post_type' => $types, 'post_status' => 'publish', 'numberposts' => self::MAX_POSTS, 'fields' => 'ids', 'orderby' => 'date', 'order' => 'DESC' ) );
		$act->step( 0, sprintf( /* translators: %d: posts */ __( 'Reading %d blog articles…', 'seolity' ), count( $ids ) ), 10 );

		// Search Console clicks per page (90 days) and the crawl's H1 + inlinks.
		$gsc = array();
		foreach ( (array) $db->rows( 'SELECT page, SUM(clicks) c, SUM(impressions) i FROM {gsc_page_query} WHERE project_id = %d AND date >= %s GROUP BY page_hash, page', array( $pid, gmdate( 'Y-m-d', time() - 90 * DAY_IN_SECONDS ) ) ) as $r ) {
			$gsc[ self::norm( $r['page'] ) ] = array( (int) $r['c'], (int) $r['i'] );
		}
		$crawl = array();
		foreach ( (array) $db->rows( 'SELECT url, h1, inlinks FROM {urls} WHERE project_id = %d', array( $pid ) ) as $r ) {
			$crawl[ self::norm( $r['url'] ) ] = $r;
		}

		$posts  = array();
		$sh     = array();
		$tt     = array();
		$inv_sh = array();
		$inv_tt = array();
		$by_url = array();
		foreach ( $ids as $id ) {
			// Merged (or deliberately hidden) articles are out of the index: not a competitor any more.
			if ( '1' === (string) $this->plugin->meta->get_post( $id, 'noindex' ) ) {
				continue;
			}
			$p     = get_post( $id );
			$url   = (string) get_permalink( $id );
			$text  = Text::strip( (string) $p->post_content );
			$words = Text::words( $text );
			$n     = self::norm( $url );
			$h1    = isset( $crawl[ $n ] ) ? trim( (string) $crawl[ $n ]['h1'] ) : '';
			$posts[ $id ] = array(
				'id'          => (int) $id,
				'title'       => get_the_title( $id ),
				'h1'          => $h1,
				'url'         => $url,
				'type'        => $p->post_type,
				'words'       => count( $words ),
				'modified'    => $p->post_modified_gmt,
				'published'   => $p->post_date_gmt,
				'clicks'      => isset( $gsc[ $n ] ) ? $gsc[ $n ][0] : 0,
				'impressions' => isset( $gsc[ $n ] ) ? $gsc[ $n ][1] : 0,
				'inlinks'     => isset( $crawl[ $n ] ) ? (int) $crawl[ $n ]['inlinks'] : 0,
				'edit'        => admin_url( 'post.php?post=' . (int) $id . '&action=edit' ),
			);
			$by_url[ $n ] = (int) $id;
			$sh[ $id ]    = self::shingles( $words );
			$tt[ $id ]    = self::title_tokens( $posts[ $id ]['title'] . ( $h1 && $h1 !== $posts[ $id ]['title'] ? ' ' . $h1 : '' ) );
			foreach ( $sh[ $id ] as $k => $_ ) {
				$inv_sh[ $k ][] = (int) $id;
			}
			foreach ( $tt[ $id ] as $k => $_ ) {
				$inv_tt[ $k ][] = (int) $id;
			}
		}
		$act->step( 0, __( 'Comparing articles…', 'seolity' ), 40 );
		$total = max( 1, count( $posts ) );

		// Candidate pairs via inverted indexes (boilerplate shingles shared by many posts are skipped).
		$shared = array();
		$cap    = max( 6, (int) ( $total * 0.05 ) );
		foreach ( $inv_sh as $list ) {
			$c = count( $list );
			if ( $c < 2 || $c > $cap ) {
				continue;
			}
			for ( $i = 0; $i < $c; $i++ ) {
				for ( $j = $i + 1; $j < $c; $j++ ) {
					$k = min( $list[ $i ], $list[ $j ] ) . ':' . max( $list[ $i ], $list[ $j ] );
					$shared[ $k ] = isset( $shared[ $k ] ) ? $shared[ $k ] + 1 : 1;
				}
			}
		}
		$tshared = array();
		foreach ( $inv_tt as $list ) {
			$c = count( $list );
			if ( $c < 2 || $c > max( 10, (int) ( $total * 0.1 ) ) ) {
				continue;
			}
			for ( $i = 0; $i < $c; $i++ ) {
				for ( $j = $i + 1; $j < $c; $j++ ) {
					$k = min( $list[ $i ], $list[ $j ] ) . ':' . max( $list[ $i ], $list[ $j ] );
					$tshared[ $k ] = isset( $tshared[ $k ] ) ? $tshared[ $k ] + 1 : 1;
				}
			}
		}
		// Queries several of our pages rank for (Search Console).
		$qpairs = array();
		if ( $this->plugin->gsc_oauth->is_connected() ) {
			foreach ( $this->plugin->keywords->cannibalization( 28, 200 ) as $q ) {
				$pp = array();
				foreach ( $q['pages'] as $pg ) {
					$n = self::norm( $pg['page'] );
					if ( isset( $by_url[ $n ] ) ) {
						$pp[] = $by_url[ $n ];
					}
				}
				$pp = array_values( array_unique( $pp ) );
				$c  = count( $pp );
				for ( $i = 0; $i < $c; $i++ ) {
					for ( $j = $i + 1; $j < $c; $j++ ) {
						$k              = min( $pp[ $i ], $pp[ $j ] ) . ':' . max( $pp[ $i ], $pp[ $j ] );
						$qpairs[ $k ][] = $q['query'];
					}
				}
			}
		}

		$pairs  = array();
		$parent = array();
		$find   = static function ( $x ) use ( &$parent ) {
			while ( isset( $parent[ $x ] ) && $parent[ $x ] !== $x ) {
				$parent[ $x ] = isset( $parent[ $parent[ $x ] ] ) ? $parent[ $parent[ $x ] ] : $parent[ $x ];
				$x            = $parent[ $x ];
			}
			return $x;
		};
		foreach ( array_unique( array_merge( array_keys( $shared ), array_keys( $tshared ), array_keys( $qpairs ) ) ) as $k ) {
			list( $a, $b ) = array_map( 'intval', explode( ':', $k ) );
			$min     = min( count( $sh[ $a ] ), count( $sh[ $b ] ) );
			$content = $min >= 20 && isset( $shared[ $k ] ) ? $shared[ $k ] / $min : 0;
			$union   = count( $tt[ $a ] + $tt[ $b ] );
			$title   = $union && isset( $tshared[ $k ] ) ? $tshared[ $k ] / $union : 0;
			$queries = isset( $qpairs[ $k ] ) ? array_slice( $qpairs[ $k ], 0, 5 ) : array();
			$h1_same = '' !== $posts[ $a ]['h1'] && mb_strtolower( $posts[ $a ]['h1'] ) === mb_strtolower( $posts[ $b ]['h1'] );
			if ( $content >= 0.8 ) {
				$kind = 'duplicate';
			} elseif ( $content >= 0.4 ) {
				$kind = 'near_duplicate';
			} elseif ( $title >= 0.6 || $h1_same || ( $queries && ( $title >= 0.3 || $content >= 0.15 ) ) || count( $queries ) >= 3 ) {
				$kind = 'cannibalization';
			} else {
				continue;
			}
			$pairs[] = array( 'a' => $a, 'b' => $b, 'kind' => $kind, 'content' => round( $content * 100 ), 'title' => round( $title * 100 ), 'h1' => $h1_same, 'queries' => $queries );
			$ra      = $find( $a );
			$rb      = $find( $b );
			$parent += array( $ra => $ra, $rb => $rb );
			if ( $ra !== $rb ) {
				$parent[ $rb ] = $ra;
			}
		}

		$groups = array();
		foreach ( $pairs as $p ) {
			$groups[ $find( $p['a'] ) ][] = $p;
		}
		$rank     = array( 'duplicate' => 3, 'near_duplicate' => 2, 'cannibalization' => 1 );
		$clusters = array();
		foreach ( $groups as $gp ) {
			$ids_in = array();
			$kind   = 'cannibalization';
			foreach ( $gp as $p ) {
				$ids_in[ $p['a'] ] = true;
				$ids_in[ $p['b'] ] = true;
				if ( $rank[ $p['kind'] ] > $rank[ $kind ] ) {
					$kind = $p['kind'];
				}
			}
			$members = array();
			foreach ( array_keys( $ids_in ) as $id ) {
				$members[] = $posts[ $id ];
			}
			$primary = $this->suggest_primary( $members );
			$ids_s   = array_keys( $ids_in );
			sort( $ids_s );
			$clusters[] = array(
				'key'     => 'dup:' . implode( '-', $ids_s ),
				'kind'    => $kind,
				'posts'   => $members,
				'pairs'   => $gp,
				'primary' => $primary['id'],
				'why'     => $primary['why'],
				'clicks'  => array_sum( wp_list_pluck( $members, 'clicks' ) ),
			);
		}
		usort(
			$clusters,
			static function ( $x, $y ) use ( $rank ) {
				return array( $rank[ $y['kind'] ], $y['clicks'], count( $y['posts'] ) ) <=> array( $rank[ $x['kind'] ], $x['clicks'], count( $x['posts'] ) );
			}
		);
		$out = array( 'at' => gmdate( 'c' ), 'checked' => count( $posts ), 'gsc' => $this->plugin->gsc_oauth->is_connected(), 'clusters' => $clusters );
		update_option( self::OPTION, $out, false );
		$this->sync_issues( $clusters );
		$this->plugin->audit_log->log( 'duplicates.scanned', 'site', null, array( 'clusters' => count( $clusters ) ) );
		return $out;
	}

	/** The article that should survive: most clicks, then most internal links, then longest, then oldest. */
	private function suggest_primary( array $members ) {
		usort(
			$members,
			static function ( $a, $b ) {
				return array( $b['clicks'], $b['inlinks'], $b['words'], $b['published'] ? -strtotime( $b['published'] ) : 0 ) <=> array( $a['clicks'], $a['inlinks'], $a['words'], $a['published'] ? -strtotime( $a['published'] ) : 0 );
			}
		);
		$p = $members[0];
		if ( $p['clicks'] ) {
			/* translators: %d: clicks */
			$why = sprintf( __( 'Most Search Console clicks (%d in 90 days).', 'seolity' ), $p['clicks'] );
		} elseif ( $p['inlinks'] ) {
			/* translators: %d: links */
			$why = sprintf( __( 'Most internal links pointing to it (%d).', 'seolity' ), $p['inlinks'] );
		} else {
			/* translators: %d: words */
			$why = sprintf( __( 'Most complete article (%d words).', 'seolity' ), $p['words'] );
		}
		return array( 'id' => (int) $p['id'], 'why' => $why );
	}

	private static function kind_label( $kind ) {
		$l = array(
			'duplicate'       => __( 'Duplicate articles', 'seolity' ),
			'near_duplicate'  => __( 'Near-duplicate articles', 'seolity' ),
			'cannibalization' => __( 'Articles competing for the same topic', 'seolity' ),
		);
		return isset( $l[ $kind ] ) ? $l[ $kind ] : $kind;
	}

	/** One canonical issue per cluster (category "duplicates"); vanished clusters resolve. */
	private function sync_issues( array $clusters ) {
		$rec  = $this->plugin->recommendations;
		$seen = array();
		foreach ( $clusters as $c ) {
			$titles = wp_list_pluck( $c['posts'], 'title' );
			$seen[] = $rec->add(
				array(
					'type'         => 'duplicate_cluster',
					'key'          => $c['key'],
					'url'          => $c['posts'][0]['url'],
					/* translators: 1 kind 2 titles */
					'title'        => sprintf( __( '%1$s: %2$s', 'seolity' ), self::kind_label( $c['kind'] ), implode( ' · ', array_slice( $titles, 0, 3 ) ) ),
					'reason'       => __( 'Several articles answer the same question, so Google splits rankings, links and clicks between them. Merging them into the strongest one usually lifts it.', 'seolity' ),
					'evidence'     => array( 'source' => 'duplicates', 'cluster' => $c['key'], 'kind' => $c['kind'], 'posts' => wp_list_pluck( $c['posts'], 'id' ) ),
					'action'       => array( 'type' => 'route', 'route' => '#/duplicates', 'cluster' => $c['key'] ),
					'impact'       => 'duplicate' === $c['kind'] ? 80 : 60,
					'effort'       => 25,
					'confidence'   => 'cannibalization' === $c['kind'] ? 60 : 85,
					'priority'     => 'cannibalization' === $c['kind'] ? 'medium' : 'high',
					'auto_fixable' => true,
				)
			);
		}
		$db = $this->plugin->db;
		$in = $seen ? implode( ',', array_map( 'intval', array_filter( $seen ) ) ) : '0';
		$db->query( "UPDATE {recommendations} SET status = 'resolved', resolved_at = %s WHERE project_id = %d AND type = 'duplicate_cluster' AND status IN ('open','stale') AND id NOT IN ($in)", array( Database::now(), $db->project_id() ) ); // phpcs:ignore WordPress.DB
	}

	private function cluster( $key ) {
		$last = $this->last();
		foreach ( $last ? $last['clusters'] : array() as $c ) {
			if ( $c['key'] === $key ) {
				return $c;
			}
		}
		return null;
	}

	private function issue_id( $key ) {
		return (int) $this->plugin->db->value( 'SELECT id FROM {recommendations} WHERE project_id = %d AND dedupe_key = %s', array( $this->plugin->db->project_id(), sha1( 'duplicate_cluster|' . $key ) ) );
	}

	/**
	 * Start a consolidation: an operation that the AI prepares in the background, then waits
	 * for approval with a full preview.
	 *
	 * @param string $key          Cluster key.
	 * @param int    $primary      Post that survives.
	 * @param int[]  $secondaries  Posts merged into it (default: the rest of the cluster).
	 * @param string $instructions Owner's instructions for this merge (standing ones are added).
	 */
	public function start( $key, $primary, array $secondaries = array(), $instructions = '' ) {
		$c = $this->cluster( $key );
		if ( ! $c ) {
			return new \WP_Error( 'seolity_dup', __( 'This group was not found — run the scan again.', 'seolity' ), array( 'status' => 404 ) );
		}
		$ids = array_map( 'intval', wp_list_pluck( $c['posts'], 'id' ) );
		if ( ! in_array( (int) $primary, $ids, true ) ) {
			return new \WP_Error( 'seolity_dup', __( 'The primary article must be one of the group.', 'seolity' ), array( 'status' => 400 ) );
		}
		$secondaries = $secondaries ? array_values( array_intersect( array_map( 'intval', $secondaries ), $ids ) ) : $ids;
		$secondaries = array_values( array_diff( $secondaries, array( (int) $primary ) ) );
		if ( ! $secondaries ) {
			return new \WP_Error( 'seolity_dup', __( 'Choose at least one article to merge into the primary.', 'seolity' ), array( 'status' => 400 ) );
		}
		if ( ! $this->plugin->ai->is_ready() ) {
			return new \WP_Error( 'seolity_ai', __( 'Connect an AI engine first (Settings → AI).', 'seolity' ), array( 'status' => 400 ) );
		}
		$instructions = sanitize_textarea_field( (string) $instructions );
		$issue        = $this->issue_id( $key );
		if ( $issue && '' !== $instructions ) {
			$this->plugin->db->update( 'recommendations', array( 'instructions' => $instructions ), array( 'id' => $issue ) );
		}
		/* translators: %s: title */
		$title = sprintf( __( 'Merge into “%s”', 'seolity' ), get_the_title( (int) $primary ) );
		$id    = $this->plugin->operations->create( 'consolidate', array( 'issue_id' => $issue, 'title' => $title, 'instructions' => trim( $this->instructions() . "\n" . $instructions ), 'input' => array( 'cluster' => $key, 'primary' => (int) $primary, 'secondaries' => $secondaries ) ) );
		$aid   = $this->plugin->activity->start( 'agent', __( 'Preparing the merge', 'seolity' ), array( 'detail' => $title, 'dock' => true, 'status' => 'queued', 'step' => __( 'Queued — starting in a moment…', 'seolity' ), 'meta' => array( 'type' => 'operation', 'operation_id' => (int) $id ) ) );
		$this->plugin->operations->update( $id, array( 'activity_id' => $aid ) );
		$job = $this->plugin->queue->push( 'consolidate_prepare', array( 'id' => (int) $id, 'user_id' => get_current_user_id() ), array( 'unique' => 'cons_' . (int) $id, 'priority' => 0, 'max_attempts' => 1, 'timeout' => 900 ) );
		$this->plugin->activity->attach_job( $aid, $job );
		if ( $issue ) {
			$this->plugin->recommendations->set_state( $issue, 'in_progress', array( 'operation_id' => (int) $id ) );
		}
		\SEOlity\Activity\AiJobs::run_after_response( $this->plugin );
		return $this->plugin->operations->get( $id );
	}

	/** Every URL form a link to this post can take inside content. */
	private static function url_variants( $url ) {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$abs  = untrailingslashit( $url );
		$out  = array( $abs . '/', $abs, untrailingslashit( rawurldecode( $url ) ) . '/', untrailingslashit( rawurldecode( $url ) ) );
		if ( $path && '/' !== $path ) {
			$out[] = trailingslashit( $path );
			$out[] = untrailingslashit( $path );
			$out[] = trailingslashit( rawurldecode( $path ) );
			$out[] = untrailingslashit( rawurldecode( $path ) );
		}
		return array_values( array_unique( $out ) );
	}

	/** Point every href to one of $from at $to (exact attribute values only). */
	public static function relink( $html, array $from, $to ) {
		return preg_replace_callback(
			'#(href\s*=\s*)(["\'])(.*?)\2#i',
			static function ( $m ) use ( $from, $to ) {
				$u    = html_entity_decode( $m[3] );
				$base = preg_replace( '/[#?].*$/', '', $u );
				$tail = substr( $u, strlen( $base ) );
				foreach ( $from as $f ) {
					if ( $base === $f ) {
						return $m[1] . $m[2] . esc_url( $to ) . $tail . $m[2];
					}
				}
				return $m[0];
			},
			(string) $html
		);
	}

	/** Queue handler: AI merge + staging of every step for review. */
	public function prepare( $op_id ) {
		$ops = $this->plugin->operations;
		$op  = $ops->get( $op_id );
		if ( ! $op || 'preparing' !== $op['status'] ) {
			return true;
		}
		$act     = $this->plugin->activity;
		$aid     = (int) $op['activity_id'];
		$prev    = \SEOlity\Activity\Activity::set_current( $aid );
		$in      = $op['input'];
		$primary = get_post( (int) $in['primary'] );
		$secs    = array_filter( array_map( 'get_post', (array) $in['secondaries'] ) );
		$fail    = function ( $msg ) use ( $ops, $op_id, $act, $aid, $prev, $op ) {
			$ops->update( $op_id, array( 'status' => 'failed', 'error' => $msg ) );
			if ( $op['issue_id'] ) {
				$this->plugin->recommendations->set_state( (int) $op['issue_id'], 'open' );
			}
			$act->finish( $aid, 'failed', $msg );
			\SEOlity\Activity\Activity::set_current( $prev );
			return true;
		};
		if ( ! $primary || ! $secs ) {
			return $fail( __( 'The articles no longer exist.', 'seolity' ) );
		}
		$act->step( $aid, __( 'AI is merging the articles…', 'seolity' ), 20 );
		$sources = array();
		foreach ( $secs as $s ) {
			$sources[] = array( 'url' => get_permalink( $s ), 'title' => $s->post_title, 'content' => mb_substr( (string) $s->post_content, 0, 40000 ) );
		}
		$meta  = $this->plugin->meta;
		$prev_instr = \SEOlity\AI\Engine::with_instructions( (string) $op['instructions'] );
		$res   = $this->plugin->ai->run(
			'consolidate',
			array(
				'primary'     => array( 'url' => get_permalink( $primary ), 'title' => $primary->post_title, 'meta_title' => $meta->get_post( $primary->ID, 'meta_title' ), 'meta_description' => $meta->get_post( $primary->ID, 'meta_description' ), 'focus_keyword' => $meta->get_post( $primary->ID, 'focus_keyword' ), 'content' => mb_substr( (string) $primary->post_content, 0, 60000 ) ),
				'secondaries' => $sources,
			),
			array( 'object_type' => 'post', 'object_id' => $primary->ID )
		);
		\SEOlity\AI\Engine::with_instructions( $prev_instr );
		if ( is_wp_error( $res ) ) {
			return $fail( $res->get_error_message() );
		}
		$d = $res['data'];
		if ( mb_strlen( wp_strip_all_tags( (string) $d['content'] ) ) < 0.6 * mb_strlen( wp_strip_all_tags( (string) $primary->post_content ) ) ) {
			return $fail( __( 'The AI returned a merged article much shorter than the primary one, so nothing was prepared. Try again or add instructions.', 'seolity' ) );
		}
		$act->step( $aid, __( 'Preparing the preview…', 'seolity' ), 70 );

		$to    = (string) get_permalink( $primary );
		$from  = array();
		$items = array();
		foreach ( $secs as $s ) {
			$from[ $s->ID ] = self::url_variants( get_permalink( $s ) );
		}
		$all_from = call_user_func_array( 'array_merge', array_values( $from ) );
		$content  = self::relink( (string) $d['content'], $all_from, $to );
		$src      = array( 'source' => 'consolidation', 'ai' => $res['meta'], 'recommendation_id' => (int) $op['issue_id'] );
		$fields   = array(
			'post_content'     => $content,
			'post_title'       => (string) $d['title'],
			'meta_title'       => (string) $d['meta_title'],
			'meta_description' => (string) $d['meta_description'],
			'focus_keyword'    => (string) $d['focus_keyword'],
		);
		foreach ( $fields as $field => $value ) {
			if ( '' === trim( $value ) ) {
				continue;
			}
			/* translators: %d: articles */
			$cid = $this->plugin->changes->propose( $src + array( 'object_type' => 'post', 'object_id' => $primary->ID, 'field' => $field, 'after' => $value, 'reason' => sprintf( __( 'Merged with %d competing article(s).', 'seolity' ), count( $secs ) ) ) );
			if ( ! is_wp_error( $cid ) ) {
				$items[] = array( 'kind' => 'change', 'id' => (int) $cid, 'key' => 'post_content' === $field ? 'merge' : 'm_' . $field, 'requires' => 'post_content' === $field ? array() : array( 'merge' ) );
			}
		}
		if ( ! $items || 'merge' !== $items[0]['key'] ) {
			return $fail( __( 'The merged article could not be prepared.', 'seolity' ) );
		}
		foreach ( $secs as $s ) {
			$surl = (string) get_permalink( $s );
			$cid  = $this->plugin->changes->propose( $src + array( 'object_type' => 'post', 'object_id' => $s->ID, 'field' => 'noindex', 'after' => '1', 'reason' => __( 'Merged into the primary article: keep it out of the index and the sitemap (it is not deleted).', 'seolity' ) ) );
			if ( ! is_wp_error( $cid ) ) {
				$items[] = array( 'kind' => 'change', 'id' => (int) $cid, 'key' => 'noindex_' . $s->ID, 'requires' => array( 'merge' ) );
			}
			$items[] = array( 'kind' => 'redirect', 'source' => $surl, 'target' => $to, 'type' => 301, 'origin' => 'consolidation', 'merge' => true, 'key' => 'r_' . $s->ID, 'requires' => array( 'merge' ) );
		}
		// Internal links elsewhere that point at the merged URLs.
		$like  = array();
		foreach ( $secs as $s ) {
			$like[] = '%' . $GLOBALS['wpdb']->esc_like( (string) wp_parse_url( get_permalink( $s ), PHP_URL_PATH ) ) . '%';
		}
		$skip  = array_merge( array( $primary->ID ), wp_list_pluck( $secs, 'ID' ) );
		$where = implode( ' OR ', array_fill( 0, count( $like ), 'post_content LIKE %s' ) );
		global $wpdb;
		$link_ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type NOT IN ('revision','attachment','nav_menu_item') AND ($where) LIMIT 300", $like ) ); // phpcs:ignore WordPress.DB
		foreach ( array_diff( array_map( 'intval', $link_ids ), $skip ) as $lid ) {
			$before = (string) get_post_field( 'post_content', $lid );
			$req    = array();
			foreach ( $from as $sid => $vars ) {
				if ( self::relink( $before, $vars, $to ) !== $before ) {
					$req[] = 'r_' . $sid;
				}
			}
			$after = self::relink( $before, $all_from, $to );
			if ( $after === $before ) {
				continue;
			}
			$cid = $this->plugin->changes->propose( $src + array( 'object_type' => 'post', 'object_id' => $lid, 'field' => 'post_content', 'after' => $after, 'reason' => __( 'Internal link now points straight at the merged article.', 'seolity' ) ) );
			if ( ! is_wp_error( $cid ) ) {
				$items[] = array( 'kind' => 'change', 'id' => (int) $cid, 'key' => 'link_' . $lid, 'requires' => $req );
			}
		}
		$plan = array( (string) $d['notes'] );
		foreach ( (array) $d['merged'] as $m ) {
			/* translators: 1 url 2 list */
			$plan[] = sprintf( __( 'From %1$s: %2$s', 'seolity' ), urldecode( (string) $m['url'] ), implode( '; ', (array) $m['added'] ) );
		}
		if ( ! empty( $d['dropped'] ) ) {
			$plan[] = __( 'Left out:', 'seolity' ) . ' ' . implode( '; ', (array) $d['dropped'] );
		}
		$plan[] = __( 'Order: merged article first; the old articles get noindex, a 301 and updated internal links only after the merge was saved and verified. Nothing is deleted and every edit keeps a WordPress revision.', 'seolity' );
		$ops->staged( $op_id, $items, array_values( array_filter( $plan ) ) );
		$act->finish( $aid, 'done', __( 'Ready for your review', 'seolity' ), array( 'summary' => __( 'Ready for your review', 'seolity' ), 'result' => array( 'operation_id' => (int) $op_id ) ) );
		\SEOlity\Activity\Activity::set_current( $prev );
		return true;
	}
}
