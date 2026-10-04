<?php
/**
 * Deterministic, explainable on-page SEO scoring (no AI required).
 *
 * Produces the "SEO Score Before / After", keyword coverage, readability,
 * semantic & entity coverage, intent match and content depth metrics shown in
 * the optimizer. Every point of the score is backed by a named check so the
 * UI can always answer "why is my score X?".
 *
 * @package SEOlity
 */

namespace SEOlity\Analysis;

use SEOlity\Plugin;

defined( 'ABSPATH' ) || exit;

final class ContentAnalyzer {

	/** Recommended minimum words per content type. */
	const DEPTH_TARGETS = array(
		'post'     => 900,
		'page'     => 500,
		'product'  => 350,
		'term'     => 250,
		'user'     => 80,
		'default'  => 400,
	);

	/** @var Plugin|null */
	private $plugin;

	public function __construct( Plugin $plugin = null ) {
		$this->plugin = $plugin;
	}

	/**
	 * Analyze content.
	 *
	 * @param array $in {
	 *   content, title, meta_title, meta_description, focus_keyword, secondary_keywords[],
	 *   semantic_terms[], entities[], slug, object_type, intent, depth_target
	 * }
	 * @return array{score:int, grade:string, metrics:array, checks:array, stats:array}
	 */
	public function analyze( array $in ) {
		$html      = isset( $in['content'] ) ? (string) $in['content'] : '';
		$text      = Text::strip( $html );
		$title     = isset( $in['title'] ) ? (string) $in['title'] : '';
		$mtitle    = ! empty( $in['meta_title'] ) ? (string) $in['meta_title'] : $title;
		$mdesc     = isset( $in['meta_description'] ) ? (string) $in['meta_description'] : '';
		$kw        = isset( $in['focus_keyword'] ) ? trim( (string) $in['focus_keyword'] ) : '';
		$secondary = array_values( array_filter( array_map( 'trim', (array) ( isset( $in['secondary_keywords'] ) ? $in['secondary_keywords'] : array() ) ) ) );
		$semantic  = array_values( array_filter( array_map( 'trim', (array) ( isset( $in['semantic_terms'] ) ? $in['semantic_terms'] : array() ) ) ) );
		$entities  = array_values( array_filter( array_map( 'trim', (array) ( isset( $in['entities'] ) ? $in['entities'] : array() ) ) ) );
		$type      = isset( $in['object_type'] ) ? $this->base_type( $in['object_type'] ) : 'default';
		$slug      = isset( $in['slug'] ) ? urldecode( (string) $in['slug'] ) : '';
		$lang      = Text::lang( $title . ' ' . $text );

		$words      = Text::words( $text );
		$word_count = count( $words );
		$sentences  = Text::sentences( $text );
		$paragraphs = max( 1, preg_match_all( '#<p[\s>]#i', $html ) ? preg_match_all( '#<p[\s>]#i', $html ) : count( array_filter( preg_split( "/\n{2,}/", $text ) ) ) );
		$headings   = $this->headings( $html );
		$links      = $this->links( $html );
		$images     = $this->images( $html );
		$lists      = preg_match_all( '#<(ul|ol)[\s>]#i', $html );
		$tables     = preg_match_all( '#<table[\s>]#i', $html );
		$faq_like   = preg_match_all( '/[?؟]\s*(<\/h[2-4]>|\n)/u', $html . "\n" );
		$target     = isset( $in['depth_target'] ) ? (int) $in['depth_target'] : self::DEPTH_TARGETS[ isset( self::DEPTH_TARGETS[ $type ] ) ? $type : 'default' ];

		$checks = array();
		$add    = static function ( $id, $group, $status, $weight, $message, $data = array() ) use ( &$checks ) {
			$checks[] = compact( 'id', 'group', 'status', 'weight', 'message', 'data' );
		};

		/* ---------- Keyword checks ---------- */
		$kw_count = 0;
		$density  = 0.0;
		if ( '' !== $kw ) {
			$kw_count = Text::count_phrase( $text, $kw );
			$kw_words = max( 1, count( Text::words( $kw ) ) );
			$density  = $word_count ? round( ( $kw_count * $kw_words / $word_count ) * 100, 2 ) : 0.0;
			$first    = implode( ' ', array_slice( $words, 0, max( 60, (int) ( $word_count * 0.1 ) ) ) );

			$add( 'kw_title', 'keyword', Text::contains( $mtitle, $kw ) ? 'pass' : 'fail', 8, Text::contains( $mtitle, $kw ) ? __( 'Focus keyword appears in the SEO title.', 'seolity' ) : __( 'Add the focus keyword to the SEO title, ideally near the beginning.', 'seolity' ) );
			$add( 'kw_meta', 'keyword', Text::contains( $mdesc, $kw ) ? 'pass' : 'warn', 4, Text::contains( $mdesc, $kw ) ? __( 'Focus keyword appears in the meta description.', 'seolity' ) : __( 'Mention the focus keyword naturally in the meta description (Google bolds matching terms).', 'seolity' ) );
			$add( 'kw_intro', 'keyword', Text::contains( $first, $kw ) ? 'pass' : 'warn', 5, Text::contains( $first, $kw ) ? __( 'Focus keyword is used in the introduction.', 'seolity' ) : __( 'Use the focus keyword (or a close variant) in the first paragraph.', 'seolity' ) );
			$in_h = false;
			foreach ( $headings as $h ) {
				if ( $h['level'] >= 2 && Text::contains( $h['text'], $kw ) ) {
					$in_h = true;
					break;
				}
			}
			$add( 'kw_subheading', 'keyword', $in_h ? 'pass' : 'warn', 4, $in_h ? __( 'Focus keyword appears in a subheading.', 'seolity' ) : __( 'Use the focus keyword or a variant in at least one H2/H3.', 'seolity' ) );
			$slug_ok = '' === $slug || Text::contains( str_replace( '-', ' ', $slug ), $kw ) || Text::contains( str_replace( '-', ' ', $slug ), Text::slugify( $kw ) );
			$add( 'kw_slug', 'keyword', $slug_ok ? 'pass' : 'warn', 3, $slug_ok ? __( 'URL slug reflects the topic.', 'seolity' ) : __( 'The URL slug does not contain the focus keyword (only change slugs of new pages, or add a redirect).', 'seolity' ) );
			if ( $density > 3.0 ) {
				$add( 'kw_density', 'keyword', 'fail', 6, sprintf( /* translators: %s density */ __( 'Keyword density is %s%% — this looks like keyword stuffing. Use synonyms and natural language.', 'seolity' ), $density ), array( 'density' => $density ) );
			} elseif ( $kw_count < 1 || ( $word_count > 300 && $density < 0.3 ) ) {
				$add( 'kw_density', 'keyword', 'warn', 6, sprintf( /* translators: %d count */ __( 'Focus keyword used %d time(s). Make sure the main topic is clearly covered.', 'seolity' ), $kw_count ), array( 'density' => $density ) );
			} else {
				$add( 'kw_density', 'keyword', 'pass', 6, sprintf( /* translators: %s density */ __( 'Natural keyword usage (%s%%).', 'seolity' ), $density ), array( 'density' => $density ) );
			}
			$img_alt_kw = false;
			foreach ( $images as $img ) {
				if ( Text::contains( $img['alt'], $kw ) ) {
					$img_alt_kw = true;
					break;
				}
			}
			if ( $images ) {
				$add( 'kw_alt', 'keyword', $img_alt_kw ? 'pass' : 'warn', 2, $img_alt_kw ? __( 'At least one image ALT describes the topic.', 'seolity' ) : __( 'Describe at least one relevant image with an ALT that relates to the topic.', 'seolity' ) );
			}
		} else {
			$add( 'kw_missing', 'keyword', 'warn', 10, __( 'No focus keyword set — SEOlity can suggest one from Search Console data.', 'seolity' ) );
		}

		/* ---------- Meta checks ---------- */
		$t_len = mb_strlen( $mtitle );
		$t_px  = Text::pixel_width( $mtitle );
		if ( 0 === $t_len ) {
			$add( 'title_length', 'meta', 'fail', 6, __( 'SEO title is missing.', 'seolity' ) );
		} elseif ( $t_len < 25 ) {
			$add( 'title_length', 'meta', 'warn', 6, sprintf( /* translators: %d chars */ __( 'SEO title is short (%d chars). Use the space to add a benefit or qualifier.', 'seolity' ), $t_len ) );
		} elseif ( $t_px > 600 ) {
			$add( 'title_length', 'meta', 'warn', 6, sprintf( /* translators: %d px */ __( 'SEO title may be truncated in Google (~%dpx, limit ≈580px).', 'seolity' ), $t_px ) );
		} else {
			$add( 'title_length', 'meta', 'pass', 6, __( 'SEO title length is good.', 'seolity' ) );
		}
		$d_len = mb_strlen( $mdesc );
		if ( 0 === $d_len ) {
			$add( 'meta_length', 'meta', 'fail', 5, __( 'Meta description is missing — Google will pick a random snippet.', 'seolity' ) );
		} elseif ( $d_len < 70 ) {
			$add( 'meta_length', 'meta', 'warn', 5, sprintf( /* translators: %d chars */ __( 'Meta description is short (%d chars). Aim for 120–160.', 'seolity' ), $d_len ) );
		} elseif ( $d_len > 165 ) {
			$add( 'meta_length', 'meta', 'warn', 5, sprintf( /* translators: %d chars */ __( 'Meta description is long (%d chars) and will be truncated.', 'seolity' ), $d_len ) );
		} else {
			$add( 'meta_length', 'meta', 'pass', 5, __( 'Meta description length is good.', 'seolity' ) );
		}

		/* ---------- Structure ---------- */
		$h1s = array_filter( $headings, static function ( $h ) {
			return 1 === $h['level'];
		} );
		if ( count( $h1s ) > 1 ) {
			$add( 'h1_multiple', 'structure', 'warn', 3, __( 'Content contains more than one H1. Themes usually output the title as H1 — use H2 inside content.', 'seolity' ) );
		}
		$h2 = count( array_filter( $headings, static function ( $h ) {
			return 2 === $h['level'];
		} ) );
		$need_h = $word_count > 300;
		$add( 'subheadings', 'structure', ( ! $need_h || $h2 >= max( 1, (int) floor( $word_count / 350 ) ) ) ? 'pass' : 'warn', 4, ( ! $need_h || $h2 ) ? __( 'Content is structured with subheadings.', 'seolity' ) : __( 'Break the content into sections with descriptive H2/H3 headings.', 'seolity' ) );
		$skip = $this->heading_skips( $headings );
		if ( $skip ) {
			$add( 'heading_order', 'structure', 'warn', 2, __( 'Heading levels are skipped (e.g. H2 → H4). Keep a logical hierarchy for accessibility.', 'seolity' ) );
		}
		$internal = count( array_filter( $links, static function ( $l ) {
			return $l['internal'];
		} ) );
		$external = count( $links ) - $internal;
		$add( 'internal_links', 'structure', $internal >= ( $word_count > 600 ? 2 : 1 ) ? 'pass' : 'warn', 5, $internal ? sprintf( /* translators: %d */ __( '%d internal link(s) found.', 'seolity' ), $internal ) : __( 'Add internal links to related pages to help users and crawlers.', 'seolity' ), array( 'internal' => $internal, 'external' => $external ) );
		if ( in_array( $type, array( 'post', 'page' ), true ) && $word_count > 600 ) {
			$add( 'external_links', 'structure', $external ? 'pass' : 'warn', 1, $external ? __( 'Cites external sources.', 'seolity' ) : __( 'Consider citing authoritative sources (supports E-E-A-T).', 'seolity' ) );
		}
		$missing_alt = count( array_filter( $images, static function ( $i ) {
			return '' === trim( $i['alt'] );
		} ) );
		if ( $images ) {
			$add( 'image_alt', 'structure', $missing_alt ? 'fail' : 'pass', 3, $missing_alt ? sprintf( /* translators: %d */ __( '%d image(s) have no ALT text.', 'seolity' ), $missing_alt ) : __( 'All images have ALT text.', 'seolity' ) );
		} elseif ( $word_count > 400 ) {
			$add( 'image_alt', 'structure', 'warn', 2, __( 'No images — visuals improve engagement and can rank in Image Search.', 'seolity' ) );
		}

		/* ---------- Readability ---------- */
		$readability = $this->readability( $words, $sentences, $lang, $paragraphs, $word_count );
		$add( 'readability', 'readability', $readability >= 60 ? 'pass' : ( $readability >= 40 ? 'warn' : 'fail' ), 6, $readability >= 60 ? __( 'Text is easy to read.', 'seolity' ) : __( 'Shorten long sentences and paragraphs; use lists for steps and specs.', 'seolity' ), array( 'score' => $readability ) );

		/* ---------- Depth ---------- */
		$depth = $this->depth( $word_count, $target, $h2, $lists, $tables, count( $images ), $faq_like );
		$add( 'depth', 'depth', $depth >= 70 ? 'pass' : ( $depth >= 45 ? 'warn' : 'fail' ), 10, sprintf( /* translators: 1 words 2 target */ __( '%1$d words (recommended ≥ %2$d for this type) — depth score reflects coverage, structure and supporting elements, not length alone.', 'seolity' ), $word_count, $target ), array( 'score' => $depth ) );

		/* ---------- Semantic / entities ---------- */
		$semantic_cov = $this->coverage( $text . ' ' . $title, array_merge( $secondary, $semantic ) );
		$entity_cov   = $this->coverage( $text . ' ' . $title, $entities );
		if ( null !== $semantic_cov ) {
			$add( 'semantic', 'semantic', $semantic_cov['ratio'] >= 0.6 ? 'pass' : ( $semantic_cov['ratio'] >= 0.3 ? 'warn' : 'fail' ), 8, sprintf( /* translators: 1 found 2 total */ __( 'Covers %1$d of %2$d related terms/subtopics.', 'seolity' ), $semantic_cov['found'], $semantic_cov['total'] ), $semantic_cov );
		}
		if ( null !== $entity_cov ) {
			$add( 'entities', 'semantic', $entity_cov['ratio'] >= 0.5 ? 'pass' : 'warn', 4, sprintf( /* translators: 1 found 2 total */ __( 'Mentions %1$d of %2$d key entities.', 'seolity' ), $entity_cov['found'], $entity_cov['total'] ), $entity_cov );
		}

		/* ---------- Intent ---------- */
		$intent       = isset( $in['intent'] ) ? (string) $in['intent'] : $this->guess_intent( $kw . ' ' . $title, $type );
		$intent_score = $this->intent_match( $intent, $text, $html, $type );
		$add( 'intent', 'intent', $intent_score >= 70 ? 'pass' : 'warn', 6, sprintf( /* translators: %s intent */ __( 'Content format vs. "%s" search intent.', 'seolity' ), $intent ), array( 'intent' => $intent, 'score' => $intent_score ) );

		/* ---------- Quality guardrails ---------- */
		$dup_sentences = $this->repeated_sentences( $sentences );
		if ( $dup_sentences > 2 ) {
			$add( 'repetition', 'quality', 'warn', 3, __( 'Several sentences are repeated — remove boilerplate to increase information gain.', 'seolity' ) );
		}

		$score = $this->score( $checks );

		$kw_coverage = '' === $kw ? 0 : (int) round( 100 * count( array_filter( $checks, static function ( $c ) {
			return 'keyword' === $c['group'] && 'pass' === $c['status'];
		} ) ) / max( 1, count( array_filter( $checks, static function ( $c ) {
			return 'keyword' === $c['group'];
		} ) ) ) );

		return array(
			'score'   => $score,
			'grade'   => $score >= 80 ? 'good' : ( $score >= 55 ? 'ok' : 'poor' ),
			'metrics' => array(
				'keyword_coverage' => $kw_coverage,
				'intent_match'     => $intent_score,
				'readability'      => $readability,
				'semantic_coverage' => null === $semantic_cov ? null : (int) round( $semantic_cov['ratio'] * 100 ),
				'entity_coverage'  => null === $entity_cov ? null : (int) round( $entity_cov['ratio'] * 100 ),
				'content_depth'    => $depth,
			),
			'checks'  => $checks,
			'stats'   => array(
				'words'          => $word_count,
				'sentences'      => count( $sentences ),
				'paragraphs'     => $paragraphs,
				'headings'       => $headings,
				'internal_links' => $internal,
				'external_links' => $external,
				'images'         => count( $images ),
				'images_no_alt'  => $missing_alt,
				'keyword_count'  => $kw_count,
				'keyword_density' => $density,
				'title_px'       => $t_px,
				'lang'           => $lang,
				'intent'         => $intent,
				'top_terms'      => array_slice( Text::top_ngrams( $text, 15 ), 0, 15, true ),
			),
		);
	}

	private function base_type( $type ) {
		if ( 0 === strpos( (string) $type, 'term' ) ) {
			return 'term';
		}
		return in_array( $type, array( 'post', 'page', 'product', 'user' ), true ) ? $type : 'post';
	}

	private function score( array $checks ) {
		$total = 0;
		$got   = 0;
		foreach ( $checks as $c ) {
			$total += $c['weight'];
			if ( 'pass' === $c['status'] ) {
				$got += $c['weight'];
			} elseif ( 'warn' === $c['status'] ) {
				$got += $c['weight'] * 0.5;
			}
		}
		return $total ? (int) round( 100 * $got / $total ) : 0;
	}

	/** @return array<int, array{level:int, text:string}> */
	public function headings( $html ) {
		preg_match_all( '#<h([1-6])[^>]*>(.*?)</h\1>#is', (string) $html, $m, PREG_SET_ORDER );
		$out = array();
		foreach ( $m as $h ) {
			$out[] = array(
				'level' => (int) $h[1],
				'text'  => trim( Text::strip( $h[2] ) ),
			);
		}
		return $out;
	}

	private function heading_skips( array $headings ) {
		$prev = 1;
		foreach ( $headings as $h ) {
			if ( $h['level'] > $prev + 1 ) {
				return true;
			}
			$prev = $h['level'];
		}
		return false;
	}

	/** @return array<int, array{href:string, text:string, internal:bool}> */
	public function links( $html ) {
		preg_match_all( '#<a\s[^>]*href=(["\'])(.*?)\1[^>]*>(.*?)</a>#is', (string) $html, $m, PREG_SET_ORDER );
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$out  = array();
		foreach ( $m as $a ) {
			$href = html_entity_decode( $a[2] );
			if ( '' === $href || '#' === $href[0] || 0 === stripos( $href, 'mailto:' ) || 0 === stripos( $href, 'tel:' ) || 0 === stripos( $href, 'javascript:' ) ) {
				continue;
			}
			$h     = wp_parse_url( $href, PHP_URL_HOST );
			$out[] = array(
				'href'     => $href,
				'text'     => Text::strip( $a[3] ),
				'internal' => ! $h || $h === $host,
			);
		}
		return $out;
	}

	/** @return array<int, array{src:string, alt:string}> */
	public function images( $html ) {
		preg_match_all( '#<img\s[^>]*>#is', (string) $html, $m );
		$out = array();
		foreach ( $m[0] as $tag ) {
			$src   = preg_match( '/\ssrc=(["\'])(.*?)\1/i', $tag, $s ) ? $s[2] : '';
			$alt   = preg_match( '/\salt=(["\'])(.*?)\1/i', $tag, $a ) ? html_entity_decode( $a[2] ) : '';
			$out[] = array( 'src' => $src, 'alt' => $alt );
		}
		return $out;
	}

	/**
	 * Readability 0–100. English: Flesch Reading Ease. Persian/other: a
	 * calibrated model based on sentence length, word length and paragraph
	 * length (Flesch is not valid for Persian script).
	 */
	private function readability( array $words, array $sentences, $lang, $paragraphs, $word_count ) {
		if ( $word_count < 30 ) {
			return 70;
		}
		$sentence_count = max( 1, count( $sentences ) );
		$asl            = $word_count / $sentence_count;
		if ( 'en' === $lang ) {
			$syllables = 0;
			foreach ( $words as $w ) {
				$syllables += $this->syllables( $w );
			}
			$flesch = 206.835 - 1.015 * $asl - 84.6 * ( $syllables / $word_count );
			return (int) max( 0, min( 100, round( $flesch ) ) );
		}
		$awl   = array_sum( array_map( 'mb_strlen', $words ) ) / $word_count;
		$apl   = $word_count / max( 1, $paragraphs );
		$score = 100;
		$score -= max( 0, $asl - 18 ) * 2.2;  // Long sentences.
		$score -= max( 0, $awl - 5.2 ) * 12;  // Long words.
		$score -= max( 0, $apl - 120 ) * 0.15; // Walls of text.
		return (int) max( 0, min( 100, round( $score ) ) );
	}

	private function syllables( $word ) {
		$word = preg_replace( '/[^a-z]/', '', strtolower( $word ) );
		if ( strlen( $word ) <= 3 ) {
			return 1;
		}
		$word = preg_replace( '/(?:[^laeiouy]es|ed|[^laeiouy]e)$/', '', $word );
		$word = preg_replace( '/^y/', '', $word );
		return max( 1, preg_match_all( '/[aeiouy]{1,2}/', $word ) );
	}

	private function depth( $words, $target, $h2, $lists, $tables, $images, $faq ) {
		$length    = min( 1, $words / max( 1, $target ) ) * 60;
		$structure = min( 15, $h2 * 4 );
		$elements  = min( 10, ( $lists ? 5 : 0 ) + ( $tables ? 5 : 0 ) );
		$media     = min( 7, $images * 3.5 );
		$questions = min( 8, $faq * 2 );
		return (int) min( 100, round( $length + $structure + $elements + $media + $questions ) );
	}

	/** @return array{found:int,total:int,ratio:float,missing:string[]}|null */
	private function coverage( $text, array $terms ) {
		$terms = array_values( array_unique( array_filter( $terms ) ) );
		if ( ! $terms ) {
			return null;
		}
		$found   = 0;
		$missing = array();
		foreach ( $terms as $t ) {
			if ( Text::contains( $text, $t ) ) {
				$found++;
			} else {
				// Partial credit: all significant words present.
				$parts = array_filter( Text::words( $t ), static function ( $w ) {
					return mb_strlen( $w ) > 2;
				} );
				$all   = $parts && count( array_filter( $parts, static function ( $p ) use ( $text ) {
					return Text::contains( $text, $p );
				} ) ) === count( $parts );
				if ( $all ) {
					$found += 0.5;
				} else {
					$missing[] = $t;
				}
			}
		}
		return array(
			'found'   => (int) floor( $found ),
			'total'   => count( $terms ),
			'ratio'   => round( $found / count( $terms ), 3 ),
			'missing' => array_slice( $missing, 0, 20 ),
		);
	}

	public function guess_intent( $text, $type = '' ) {
		$t = Text::normalize( $text );
		$patterns = array(
			'transactional' => '/\b(buy|price|cheap|discount|order|deal|coupon|shop|sale)\b|خرید|قیمت|ارزان|تخفیف|سفارش|فروش|فروشگاه/u',
			'commercial'    => '/\b(best|top|review|vs|compare|comparison|alternative)\b|بهترین|مقایسه|بررسی|نقد|معرفی|مشخصات/u',
			'navigational'  => '/\b(login|sign in|contact|official|website)\b|ورود|تماس|سایت رسمی|نمایندگی/u',
			'informational' => '/\b(how|what|why|guide|tutorial|tips|ideas|learn)\b|چگونه|چطور|چیست|چرا|آموزش|راهنما|روش|نکات/u',
		);
		foreach ( $patterns as $intent => $re ) {
			if ( preg_match( $re, $t ) ) {
				return $intent;
			}
		}
		return 'product' === $type ? 'transactional' : ( 'term' === $type ? 'commercial' : 'informational' );
	}

	/** Does the format match the intent? (e.g. transactional → price/CTA/specs). */
	private function intent_match( $intent, $text, $html, $type ) {
		$t     = Text::normalize( $text );
		$score = 50;
		switch ( $intent ) {
			case 'transactional':
				$score += preg_match( '/price|\$|€|تومان|ریال|قیمت/u', $t ) ? 15 : 0;
				$score += preg_match( '/buy|add to cart|order|shipping|warranty|خرید|ارسال|گارانتی|سفارش/u', $t ) ? 15 : 0;
				$score += preg_match( '#<(ul|table)#i', $html ) ? 10 : 0;
				$score += 'product' === $type ? 10 : 0;
				break;
			case 'commercial':
				$score += preg_match( '/compar|vs|pros|cons|best|مقایسه|مزایا|معایب|بهترین/u', $t ) ? 20 : 0;
				$score += preg_match( '#<table#i', $html ) ? 15 : 0;
				$score += preg_match( '#<(ul|ol)#i', $html ) ? 15 : 0;
				break;
			case 'informational':
				$score += preg_match_all( '#<h[23]#i', $html ) >= 3 ? 20 : 5;
				$score += preg_match( '#<(ol|ul)#i', $html ) ? 15 : 0;
				$score += preg_match( '/[?؟]/u', $text ) ? 15 : 0;
				break;
			case 'navigational':
				$score += preg_match( '/contact|address|phone|تماس|آدرس|تلفن/u', $t ) ? 40 : 10;
				break;
		}
		return (int) min( 100, $score );
	}

	private function repeated_sentences( array $sentences ) {
		$seen = array();
		$dup  = 0;
		foreach ( $sentences as $s ) {
			$k = md5( Text::normalize( $s ) );
			if ( isset( $seen[ $k ] ) && Text::word_count( $s ) > 5 ) {
				$dup++;
			}
			$seen[ $k ] = true;
		}
		return $dup;
	}
}
