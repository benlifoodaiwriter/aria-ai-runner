<?php
/**
 * GSC-driven focus keywords: picks the keyword set each page should target
 * from the queries Google ALREADY shows it for, instead of guessing.
 *
 * - Per page: query coverage (title / meta / headings / body), missing
 *   queries, question queries (FAQ), striking-distance queries, CTR gap and
 *   cannibalization conflicts, plus a suggested primary + secondary set.
 * - Site-wide: cannibalization-safe bulk assignment (one primary keyword is
 *   never given to two URLs; a query belongs to the page Google prefers).
 *
 * Writes go through ChangeManager (approval, undo, experiments) into the
 * active SEO plugin — Rank Math gets the full comma list (primary first).
 *
 * @package SEOlity
 */

namespace SEOlity\Keywords;

use SEOlity\Analysis\Text;
use SEOlity\Content\Objects;
use SEOlity\Core\Database;
use SEOlity\GSC\Analytics;
use SEOlity\Plugin;

defined( 'ABSPATH' ) || exit;

final class FocusKeywords {

	/** Question openers (English + Persian) used to spot FAQ-worthy queries. */
	const QUESTION_WORDS = array( 'what', 'how', 'why', 'when', 'where', 'which', 'who', 'can', 'does', 'do', 'is', 'are', 'should', 'چگونه', 'چطور', 'چطوری', 'چرا', 'چیست', 'چیه', 'چه', 'کدام', 'کدوم', 'آیا', 'کی', 'کجا', 'چقدر', 'چند', 'بهترین' );

	/** Max secondary keywords (Rank Math free allows 5 total; Pro more). */
	const SECONDARY = 4;

	/** @var Plugin */
	private $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	private function pid() {
		return $this->plugin->db->project_id();
	}

	/** Current focus keywords (primary first). */
	public function current( $type, $id ) {
		$list = Objects::read( $this->plugin, $type, $id, 'focus_keywords' );
		return self::split( (string) $list );
	}

	public static function split( $list ) {
		$out = array();
		foreach ( explode( ',', (string) $list ) as $k ) {
			$k = trim( preg_replace( '/\s+/u', ' ', $k ) );
			if ( '' !== $k && ! in_array( mb_strtolower( $k ), array_map( 'mb_strtolower', $out ), true ) ) {
				$out[] = $k;
			}
		}
		return $out;
	}

	/**
	 * Owner page for each query: the URL with the most clicks (then impressions).
	 *
	 * @param string[] $queries Query strings.
	 * @return array query_hash => array( owner, pages[] )
	 */
	private function owners( array $queries, $days ) {
		if ( ! $queries ) {
			return array();
		}
		list( $cs, $ce ) = $this->plugin->gsc_analytics->periods( $days );
		$hashes          = array_values( array_unique( array_map( array( Database::class, 'hash' ), $queries ) ) );
		$out             = array();
		foreach ( array_chunk( $hashes, 150 ) as $chunk ) {
			$in   = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			$rows = $this->plugin->db->rows(
				"SELECT query_hash, page_hash, MAX(page) page, SUM(clicks) clicks, SUM(impressions) impressions, SUM(position*impressions)/NULLIF(SUM(impressions),0) position
				FROM {gsc_page_query} WHERE project_id = %d AND date BETWEEN %s AND %s AND query_hash IN ($in) GROUP BY query_hash, page_hash",
				array_merge( array( $this->pid(), $cs, $ce ), $chunk )
			);
			foreach ( $rows as $r ) {
				$out[ $r['query_hash'] ]['pages'][] = array( 'page' => $r['page'], 'hash' => $r['page_hash'], 'clicks' => (int) $r['clicks'], 'impressions' => (int) $r['impressions'], 'position' => round( (float) $r['position'], 1 ) );
			}
		}
		foreach ( $out as &$q ) {
			usort(
				$q['pages'],
				static function ( $a, $b ) {
					return array( $b['clicks'], $b['impressions'] ) <=> array( $a['clicks'], $a['impressions'] );
				}
			);
			$q['owner'] = $q['pages'][0];
			$q['total'] = array_sum( wp_list_pluck( $q['pages'], 'impressions' ) );
		}
		return $out;
	}

	/** Text facets of an object used for coverage checks. */
	private function facets( $type, $id ) {
		$f = array( 'title' => '', 'meta_title' => '', 'meta_description' => '', 'headings' => '', 'intro' => '', 'body' => '', 'slug' => '' );
		if ( 'post' === $type ) {
			$post = get_post( $id );
			if ( ! $post ) {
				return $f;
			}
			$html          = (string) $post->post_content;
			$f['title']    = (string) $post->post_title;
			$f['slug']     = str_replace( '-', ' ', urldecode( (string) $post->post_name ) );
			$f['body']     = Text::strip( $html . ' ' . $post->post_excerpt );
			$f['intro']    = implode( ' ', array_slice( Text::words( $f['body'] ), 0, 120 ) );
			$f['headings'] = preg_match_all( '#<h[1-4][^>]*>(.*?)</h[1-4]>#isu', $html, $m ) ? Text::strip( implode( ' · ', $m[1] ) ) : '';
		} elseif ( 'term' === $type ) {
			$term = get_term( $id );
			if ( ! $term || is_wp_error( $term ) ) {
				return $f;
			}
			$f['title'] = $term->name;
			$f['slug']  = str_replace( '-', ' ', urldecode( $term->slug ) );
			$f['body']  = Text::strip( $term->description );
			$f['intro'] = $f['body'];
		}
		$f['meta_title']       = (string) Objects::read( $this->plugin, $type, $id, 'meta_title' );
		$f['meta_description'] = (string) Objects::read( $this->plugin, $type, $id, 'meta_description' );
		return $f;
	}

	/** Share of the query's words found in a text (0..1). */
	private static function coverage( $text, $query ) {
		$words = array_filter(
			Text::words( $query ),
			static function ( $w ) {
				return mb_strlen( $w ) > 1;
			}
		);
		if ( ! $words ) {
			return 0;
		}
		$hay = ' ' . Text::normalize( $text ) . ' ';
		$hit = 0;
		foreach ( $words as $w ) {
			if ( false !== mb_strpos( $hay, Text::normalize( $w ) ) ) {
				++$hit;
			}
		}
		return round( $hit / count( $words ), 2 );
	}

	public static function is_question( $q ) {
		if ( false !== strpos( $q, '?' ) || false !== strpos( $q, '؟' ) ) {
			return true;
		}
		$words = Text::words( mb_strtolower( trim( $q ) ) );
		if ( ! $words ) {
			return false;
		}
		// English questions start with the question word; Persian ones often end with it ("… چیست").
		$fa = array( 'چگونه', 'چطور', 'چطوری', 'چرا', 'چیست', 'چیه', 'کدام', 'کدوم', 'آیا', 'کجا', 'چقدر', 'چند' );
		return in_array( $words[0], array_diff( self::QUESTION_WORDS, array( 'بهترین' ) ), true ) || (bool) array_intersect( $words, $fa );
	}

	/** Brand signals: the full site name, the domain label, or two+ words of the site name. */
	private function brand_signals() {
		static $sig = null;
		if ( null === $sig ) {
			$host  = (string) wp_parse_url( home_url(), PHP_URL_HOST );
			$label = preg_replace( '/^www\./', '', $host );
			$label = strtok( $label, '.' );
			$name  = Text::normalize( get_bloginfo( 'name' ) );
			$sig   = array(
				'name'  => mb_strlen( $name ) > 2 ? $name : '',
				'label' => ( $label && ! filter_var( $host, FILTER_VALIDATE_IP ) && mb_strlen( $label ) > 3 ) ? Text::normalize( $label ) : '',
				'words' => array_values(
					array_filter(
						array_map( array( Text::class, 'normalize' ), Text::words( get_bloginfo( 'name' ) ) ),
						static function ( $w ) {
							return mb_strlen( $w ) > 2;
						}
					)
				),
			);
		}
		return $sig;
	}

	private function is_brand( $q ) {
		$n   = Text::normalize( $q );
		$sig = $this->brand_signals();
		if ( ( $sig['name'] && false !== mb_strpos( $n, $sig['name'] ) ) || ( $sig['label'] && false !== mb_strpos( str_replace( ' ', '', $n ), $sig['label'] ) ) ) {
			return true;
		}
		if ( count( $sig['words'] ) >= 2 ) {
			$words = Text::words( $n );
			return count( array_intersect( $sig['words'], $words ) ) >= 2;
		}
		return false;
	}

	/** Candidate value of a query for this page (0 when it belongs to another URL). */
	private function score( array $q, $is_owner, $share ) {
		$own   = $is_owner ? 1 : ( $share >= 40 ? 0.6 : 0 );
		$value = log10( 1 + $q['impressions'] ) * 10 + log10( 1 + $q['clicks'] ) * 15;
		$pos   = $q['position'] <= 20 ? 1 : 0.6;
		$len   = count( Text::words( $q['query'] ) );
		$shape = $len >= 7 ? 0.75 : ( 1 === $len ? 0.85 : 1 );
		$brand = $this->is_brand( $q['query'] ) ? 0.35 : 1;
		return round( $own * $value * $pos * $shape * $brand, 1 );
	}

	/**
	 * Full keyword intelligence for one object.
	 *
	 * @return array|\WP_Error
	 */
	public function insights( $type, $id, $days = 90 ) {
		$type = in_array( $type, array( 'post', 'term' ), true ) ? $type : 'post';
		$url  = Objects::url( $type, (int) $id );
		if ( ! $url ) {
			return new \WP_Error( 'seolity_object', __( 'Object not found.', 'seolity' ), array( 'status' => 404 ) );
		}
		$a       = $this->plugin->gsc_analytics;
		$current = $this->current( $type, $id );
		$out     = array(
			'url'       => $url,
			'engine'    => $this->plugin->meta->engine(),
			'has_gsc'   => $a->has_data(),
			'current'   => $current,
			'metrics'   => $a->has_data() ? $a->page_metrics( $url, 28 ) : null,
			'rank_math' => $this->plugin->rankmath->post_info( $type, (int) $id ),
			'queries'   => array(),
			'suggested' => array( 'primary' => '', 'secondary' => array() ),
		);
		if ( ! $out['has_gsc'] ) {
			return $out;
		}
		$facets  = $this->facets( $type, (int) $id );
		$rows    = $a->page_queries( $url, $days, 80 );
		$owners  = $this->owners( wp_list_pluck( $rows, 'query' ), $days );
		$hash    = Database::url_hash( $url );
		$queries = array();
		foreach ( $rows as $r ) {
			$r         = array( 'query' => (string) $r['query'], 'clicks' => (int) $r['clicks'], 'impressions' => (int) $r['impressions'], 'ctr' => (float) $r['ctr'], 'position' => (float) $r['position'] );
			$o         = isset( $owners[ Database::hash( $r['query'] ) ] ) ? $owners[ Database::hash( $r['query'] ) ] : null;
			$is_owner  = ! $o || $o['owner']['hash'] === $hash;
			$share     = $o && $o['total'] ? round( $r['impressions'] / $o['total'] * 100, 1 ) : 100;
			$competing = array();
			if ( $o ) {
				foreach ( $o['pages'] as $p ) {
					if ( $p['hash'] !== $hash && $p['impressions'] >= max( 10, $o['total'] * 0.1 ) ) {
						$competing[] = array( 'page' => $p['page'], 'impressions' => $p['impressions'], 'position' => $p['position'] );
					}
				}
			}
			$cov_body    = self::coverage( $facets['body'], $r['query'] );
			$queries[]   = array_merge(
				$r,
				array(
					'in_title'    => Text::contains( $facets['title'] . ' ' . $facets['meta_title'], $r['query'] ),
					'in_meta'     => Text::contains( $facets['meta_description'], $r['query'] ),
					'in_headings' => Text::contains( $facets['headings'], $r['query'] ),
					'in_intro'    => Text::contains( $facets['intro'], $r['query'] ),
					'in_body'     => Text::contains( $facets['body'], $r['query'] ),
					'coverage'    => $cov_body,
					'question'    => self::is_question( $r['query'] ),
					'brand'       => $this->is_brand( $r['query'] ),
					'owner'       => $is_owner,
					'owner_url'   => $is_owner ? null : $o['owner']['page'],
					'share'       => $share,
					'competing'   => array_slice( $competing, 0, 3 ),
					'score'       => $this->score( $r, $is_owner, $share ),
					'is_focus'    => in_array( mb_strtolower( $r['query'] ), array_map( 'mb_strtolower', $current ), true ),
				)
			);
		}
		$out['queries']   = $queries;
		$out['suggested'] = $this->pick( $queries, array() );

		// Current focus keywords vs. reality.
		$by_q = array();
		foreach ( $queries as $q ) {
			$by_q[ Text::normalize( $q['query'] ) ] = $q;
		}
		$check = array();
		foreach ( $current as $kw ) {
			$q       = isset( $by_q[ Text::normalize( $kw ) ] ) ? $by_q[ Text::normalize( $kw ) ] : null;
			$own     = $this->owners( array( $kw ), $days );
			$o       = isset( $own[ Database::hash( $kw ) ] ) ? $own[ Database::hash( $kw ) ]['owner'] : null;
			$check[] = array(
				'keyword'     => $kw,
				'impressions' => $q ? $q['impressions'] : 0,
				'clicks'      => $q ? $q['clicks'] : 0,
				'position'    => $q ? $q['position'] : null,
				'in_title'    => Text::contains( $facets['title'] . ' ' . $facets['meta_title'], $kw ),
				'in_body'     => Text::contains( $facets['body'], $kw ),
				'owned_by'    => $o && $o['hash'] !== $hash ? $o['page'] : null,
				'status'      => $o && $o['hash'] !== $hash ? 'conflict' : ( $q ? 'ok' : 'no_data' ),
			);
		}
		$out['current_check'] = $check;

		$min = max( 5, (int) round( ( $out['metrics'] ? $out['metrics']['impressions'] : 0 ) * 0.01 ) );
		$out['missing'] = array_values(
			array_slice(
				array_filter(
					$queries,
					static function ( $q ) use ( $min ) {
						return $q['owner'] && ! $q['brand'] && $q['impressions'] >= $min && $q['coverage'] < 0.75;
					}
				),
				0,
				10
			)
		);
		$out['questions'] = array_values(
			array_slice(
				array_filter(
					$queries,
					static function ( $q ) {
						return $q['question'];
					}
				),
				0,
				10
			)
		);
		$striking = array_filter(
			$queries,
			static function ( $q ) {
				return $q['owner'] && $q['position'] >= 4 && $q['position'] <= 20 && $q['impressions'] >= 20;
			}
		);
		usort(
			$striking,
			static function ( $a, $b ) {
				return $b['impressions'] <=> $a['impressions'];
			}
		);
		$out['striking']  = array_slice( $striking, 0, 10 );
		$out['conflicts'] = array_values(
			array_slice(
				array_filter(
					$queries,
					static function ( $q ) {
						return ! $q['owner'] && $q['impressions'] >= 20;
					}
				),
				0,
				10
			)
		);
		if ( $out['metrics'] && $out['metrics']['impressions'] >= 100 && $out['metrics']['position'] > 0 ) {
			$exp        = Analytics::expected_ctr( $out['metrics']['position'] ) * 100;
			$out['ctr'] = array(
				'position' => $out['metrics']['position'],
				'ctr'      => $out['metrics']['ctr'],
				'expected' => round( $exp, 2 ),
				'gap'      => $out['metrics']['position'] <= 10 && $out['metrics']['ctr'] < $exp * 0.7,
			);
		}
		$out['tips'] = $this->tips( $out, $facets );
		return $out;
	}

	/**
	 * Primary + secondary keywords from scored queries.
	 *
	 * @param array    $queries Scored rows (query, score, impressions…).
	 * @param string[] $taken   Normalized primaries already used by other URLs.
	 */
	private function pick( array $queries, array $taken ) {
		usort(
			$queries,
			static function ( $a, $b ) {
				return $b['score'] <=> $a['score'];
			}
		);
		$chosen = array();
		foreach ( $queries as $q ) {
			if ( $q['score'] <= 0 ) {
				break;
			}
			$n = Text::normalize( $q['query'] );
			if ( ! $chosen && in_array( $n, $taken, true ) ) {
				continue; // Primary already targeted elsewhere.
			}
			foreach ( $chosen as $c ) {
				if ( Text::similarity( $c['query'], $q['query'] ) >= 0.85 ) {
					continue 2; // Near duplicate (plural, typo, word order).
				}
			}
			$chosen[] = $q;
			if ( count( $chosen ) > self::SECONDARY ) {
				break;
			}
		}
		if ( ! $chosen ) {
			return array( 'primary' => '', 'secondary' => array(), 'score' => 0 );
		}
		$primary = array_shift( $chosen );
		return array(
			'primary'     => $primary['query'],
			'secondary'   => wp_list_pluck( $chosen, 'query' ),
			'score'       => $primary['score'],
			'impressions' => $primary['impressions'],
			'position'    => $primary['position'],
		);
	}

	/** Human advice derived from the data (what Rank Math's checklist cannot know). */
	private function tips( array $o, array $facets ) {
		$tips = array();
		$p    = $o['suggested']['primary'];
		if ( $p && ! in_array( mb_strtolower( $p ), array_map( 'mb_strtolower', $o['current'] ), true ) ) {
			$tips[] = array( 'type' => 'keyword', 'level' => 'high', 'text' => sprintf( __( 'Google shows this page most for “%s”, but it is not your focus keyword.', 'seolity' ), $p ) );
		}
		foreach ( $o['current_check'] as $c ) {
			if ( 'conflict' === $c['status'] ) {
				$tips[] = array( 'type' => 'conflict', 'level' => 'high', 'text' => sprintf( __( 'Focus keyword “%1$s” is won by another page (%2$s). Targeting it here causes cannibalization.', 'seolity' ), $c['keyword'], wp_make_link_relative( $c['owned_by'] ) ) );
			} elseif ( 'no_data' === $c['status'] ) {
				$tips[] = array( 'type' => 'keyword', 'level' => 'medium', 'text' => sprintf( __( 'Focus keyword “%s” gets no impressions for this page in Search Console.', 'seolity' ), $c['keyword'] ) );
			}
		}
		if ( $p && ! Text::contains( $facets['title'] . ' ' . $facets['meta_title'], $p ) ) {
			$tips[] = array( 'type' => 'title', 'level' => 'high', 'text' => __( 'Put the primary keyword in the SEO title (ideally near the start).', 'seolity' ) );
		}
		if ( $p && ! Text::contains( $facets['intro'], $p ) ) {
			$tips[] = array( 'type' => 'content', 'level' => 'medium', 'text' => __( 'Use the primary keyword naturally in the first paragraph.', 'seolity' ) );
		}
		if ( ! empty( $o['ctr']['gap'] ) ) {
			$tips[] = array( 'type' => 'ctr', 'level' => 'high', 'text' => sprintf( __( 'CTR is %1$s%% at position %2$s (expected about %3$s%%). Rewrite the title and description.', 'seolity' ), $o['ctr']['ctr'], $o['ctr']['position'], $o['ctr']['expected'] ) );
		}
		if ( $o['missing'] ) {
			$tips[] = array( 'type' => 'content', 'level' => 'medium', 'text' => sprintf( _n( '%d search query you already get impressions for is not covered in the content.', '%d search queries you already get impressions for are not covered in the content.', count( $o['missing'] ), 'seolity' ), count( $o['missing'] ) ) );
		}
		if ( $o['questions'] ) {
			$tips[] = array( 'type' => 'faq', 'level' => 'low', 'text' => __( 'Searchers ask questions about this page. Answer them in an FAQ section.', 'seolity' ) );
		}
		if ( $o['striking'] ) {
			$tips[] = array( 'type' => 'striking', 'level' => 'medium', 'text' => sprintf( __( '%d queries rank on positions 4–20: small content and internal-link improvements can move them to the top 3.', 'seolity' ), count( $o['striking'] ) ) );
		}
		return $tips;
	}

	/**
	 * Apply a keyword set (and optionally title / description) to one object
	 * through the change log so every write can be undone.
	 *
	 * @param array $extra meta_title, meta_description (optional).
	 * @return array|\WP_Error change ids
	 */
	public function apply( $type, $id, array $keywords, $reason = '', array $extra = array() ) {
		$fields = array();
		if ( $keywords ) {
			$fields['focus_keywords'] = implode( ', ', array_slice( self::split( implode( ',', $keywords ) ), 0, self::SECONDARY + 1 ) );
		}
		foreach ( array( 'meta_title', 'meta_description' ) as $f ) {
			if ( isset( $extra[ $f ] ) && '' !== trim( (string) $extra[ $f ] ) ) {
				$fields[ $f ] = sanitize_text_field( (string) $extra[ $f ] );
			}
		}
		$out = array( 'changes' => array(), 'keywords' => isset( $fields['focus_keywords'] ) ? $fields['focus_keywords'] : null );
		foreach ( $fields as $field => $value ) {
			$cid = $this->plugin->changes->propose(
				array(
					'object_type' => $type,
					'object_id'   => (int) $id,
					'field'       => $field,
					'after'       => $value,
					'source'      => 'gsc',
					'reason'      => $reason ? $reason : __( 'Chosen from real Search Console queries for this page.', 'seolity' ),
					'keywords'    => $keywords,
				)
			);
			if ( is_wp_error( $cid ) ) {
				if ( 'seolity_nochange' === $cid->get_error_code() ) {
					continue;
				}
				return $cid;
			}
			$res = $this->plugin->changes->apply( $cid );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			$out['changes'][ $field ] = $cid;
		}
		$out['change_id'] = isset( $out['changes']['focus_keywords'] ) ? $out['changes']['focus_keywords'] : null;
		return $out;
	}

	/**
	 * Site-wide, cannibalization-safe assignment. Proposes changes (or applies
	 * them in Semi/Autonomous mode — focus keywords are low risk).
	 *
	 * @param array $o only_empty (bool), post_types (string[]), limit (int), min_impressions (int), dry_run (bool)
	 */
	public function assign_bulk( array $o ) {
		if ( ! $this->plugin->gsc_analytics->has_data() ) {
			return new \WP_Error( 'seolity_gsc', __( 'Connect Google Search Console first: keywords are chosen from real search data.', 'seolity' ), array( 'status' => 409 ) );
		}
		$only_empty = ! isset( $o['only_empty'] ) || ! empty( $o['only_empty'] );
		$limit      = max( 1, min( 500, isset( $o['limit'] ) ? (int) $o['limit'] : 200 ) );
		$min_impr   = max( 0, isset( $o['min_impressions'] ) ? (int) $o['min_impressions'] : 30 );
		$types      = ! empty( $o['post_types'] ) ? array_map( 'sanitize_key', (array) $o['post_types'] ) : array_values( get_post_types( array( 'public' => true ) ) );
		$types      = array_diff( $types, array( 'attachment' ) );
		$days       = 90;

		// Pages with search demand, biggest first.
		$pages = $this->plugin->gsc_analytics->grouped( 'page', $days, $limit * 3, 'impressions' );
		$cands = array();
		$taken = array();
		foreach ( $pages as $row ) {
			if ( (int) $row['impressions'] < $min_impr ) {
				continue;
			}
			$pid = url_to_postid( $row['page'] );
			if ( ! $pid || 'publish' !== get_post_status( $pid ) || ! in_array( get_post_type( $pid ), $types, true ) ) {
				continue;
			}
			$current = $this->current( 'post', $pid );
			if ( $current ) {
				$taken[ Text::normalize( $current[0] ) ] = $pid;
				if ( $only_empty ) {
					continue;
				}
			}
			$cands[ $pid ] = array( 'url' => $row['page'], 'impressions' => (int) $row['impressions'], 'current' => $current );
			if ( count( $cands ) >= $limit ) {
				break;
			}
		}

		$batch   = 'fk' . gmdate( 'ymdHis' );
		$result  = array( 'batch_id' => $batch, 'checked' => count( $cands ), 'proposed' => 0, 'applied' => 0, 'skipped' => 0, 'items' => array() );
		$hashmap = array();
		foreach ( $cands as $pid => $c ) {
			$rows = $this->plugin->gsc_analytics->page_queries( $c['url'], $days, 40 );
			if ( ! $rows ) {
				++$result['skipped'];
				continue;
			}
			$owners = $this->owners( wp_list_pluck( $rows, 'query' ), $days );
			$hash   = Database::url_hash( $c['url'] );
			$scored = array();
			foreach ( $rows as $r ) {
				$r        = array( 'query' => (string) $r['query'], 'clicks' => (int) $r['clicks'], 'impressions' => (int) $r['impressions'], 'position' => (float) $r['position'] );
				$ow       = isset( $owners[ Database::hash( $r['query'] ) ] ) ? $owners[ Database::hash( $r['query'] ) ] : null;
				$is_owner = ! $ow || $ow['owner']['hash'] === $hash;
				$share    = $ow && $ow['total'] ? $r['impressions'] / $ow['total'] * 100 : 100;
				$r['score'] = $this->score( $r, $is_owner, $share );
				$scored[] = $r;
			}
			$taken_other = array_keys(
				array_filter(
					$taken,
					static function ( $owner ) use ( $pid ) {
						return $owner !== $pid;
					}
				)
			);
			$pick = $this->pick( $scored, $taken_other );
			if ( ! $pick['primary'] ) {
				++$result['skipped'];
				continue;
			}
			$taken[ Text::normalize( $pick['primary'] ) ] = $pid;
			$keywords = array_merge( array( $pick['primary'] ), $pick['secondary'] );
			$item     = array( 'object_id' => $pid, 'title' => get_the_title( $pid ), 'url' => $c['url'], 'before' => implode( ', ', $c['current'] ), 'after' => implode( ', ', $keywords ), 'impressions' => $c['impressions'] );
			if ( empty( $o['dry_run'] ) ) {
				$cid = $this->plugin->changes->propose(
					array(
						'object_type'        => 'post',
						'object_id'          => $pid,
						'field'              => 'focus_keywords',
						'after'              => implode( ', ', $keywords ),
						'source'             => 'gsc',
						'batch_id'           => $batch,
						'keywords'           => $keywords,
						'reason'             => sprintf( __( 'Primary keyword “%1$s” has %2$d impressions at position %3$s for this page (Search Console, 90 days). Secondary keywords are other queries this page owns.', 'seolity' ), $pick['primary'], $pick['impressions'], $pick['position'] ),
						'auto_apply_allowed' => true,
					)
				);
				if ( is_wp_error( $cid ) ) {
					++$result['skipped'];
					continue;
				}
				$item['change_id'] = $cid;
				$st                = $this->plugin->db->value( 'SELECT status FROM {changes} WHERE id = %d', array( $cid ) );
				'applied' === $st ? ++$result['applied'] : ++$result['proposed'];
				$item['status'] = $st;
			}
			$result['items'][] = $item;
		}
		$this->plugin->audit_log->log( 'keywords.focus_bulk', 'site', 0, array( 'batch' => $batch, 'proposed' => $result['proposed'], 'applied' => $result['applied'] ) );
		return $result;
	}

	/** Site-wide focus keyword coverage (Rank Math / Yoast / own). */
	public function coverage_stats() {
		global $wpdb;
		$types   = array_values( array_diff( get_post_types( array( 'public' => true ) ), array( 'attachment' ) ) );
		$in      = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$total   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ($in)", $types ) ); // phpcs:ignore WordPress.DB
		$key     = $this->plugin->meta->meta_key( 'focus_keyword' );
		$with    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s AND m.meta_value <> '' WHERE p.post_status = 'publish' AND p.post_type IN ($in)", array_merge( array( $key ), $types ) ) ); // phpcs:ignore WordPress.DB
		$demand  = $this->plugin->gsc_analytics->has_data() ? $this->plugin->gsc_analytics->grouped( 'page', 90, 300, 'impressions' ) : array();
		$no_kw   = array();
		foreach ( $demand as $row ) {
			if ( count( $no_kw ) >= 15 ) {
				break;
			}
			$pid = url_to_postid( $row['page'] );
			if ( $pid && 'publish' === get_post_status( $pid ) && ! $this->current( 'post', $pid ) ) {
				$no_kw[] = array( 'object_id' => $pid, 'title' => get_the_title( $pid ), 'url' => $row['page'], 'clicks' => (int) $row['clicks'], 'impressions' => (int) $row['impressions'], 'position' => $row['position'] );
			}
		}
		return array(
			'engine'         => $this->plugin->meta->engine(),
			'total'          => $total,
			'with_keyword'   => $with,
			'without'        => max( 0, $total - $with ),
			'has_gsc'        => $this->plugin->gsc_analytics->has_data(),
			'demand_no_kw'   => $no_kw,
		);
	}

	/** AI keyword strategy for one page, grounded in its GSC queries. */
	public function ai_plan( $type, $id ) {
		$ins = $this->insights( $type, $id );
		if ( is_wp_error( $ins ) ) {
			return $ins;
		}
		$facets = $this->facets( $type, (int) $id );
		$input  = array(
			'url'               => $ins['url'],
			'title'             => $facets['title'],
			'seo_title'         => $facets['meta_title'],
			'meta_description'  => $facets['meta_description'],
			'headings'          => mb_substr( $facets['headings'], 0, 1500 ),
			'content_excerpt'   => mb_substr( $facets['body'], 0, 3500 ),
			'current_keywords'  => $ins['current'],
			'gsc_queries'       => array_map(
				static function ( $q ) {
					return array_intersect_key( $q, array_flip( array( 'query', 'clicks', 'impressions', 'ctr', 'position', 'owner', 'coverage', 'question' ) ) );
				},
				array_slice( $ins['queries'], 0, 40 )
			),
			'owned_elsewhere'   => wp_list_pluck( $ins['conflicts'] ?? array(), 'query' ),
			'algorithm_pick'    => $ins['suggested'],
			'page_metrics'      => $ins['metrics'],
		);
		$res = $this->plugin->ai->run( 'keyword_plan', $input );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return array( 'insights' => $ins, 'plan' => $res['data'], 'meta' => $res['meta'] );
	}
}
