<?php
/**
 * Unicode / Persian-aware text utilities used by every analyzer.
 *
 * @package SEOlity
 */

namespace SEOlity\Analysis;

defined( 'ABSPATH' ) || exit;

final class Text {

	const ZWNJ = "\xE2\x80\x8C";

	/** @var array<string,string[]> */
	private static $stopwords = array(
		'en' => array( 'a', 'an', 'the', 'and', 'or', 'but', 'if', 'of', 'to', 'in', 'on', 'for', 'with', 'by', 'at', 'from', 'as', 'is', 'are', 'was', 'were', 'be', 'been', 'it', 'its', 'this', 'that', 'these', 'those', 'you', 'your', 'we', 'our', 'they', 'their', 'he', 'she', 'i', 'me', 'my', 'not', 'no', 'so', 'do', 'does', 'did', 'have', 'has', 'had', 'can', 'will', 'would', 'should', 'could', 'about', 'into', 'than', 'then', 'there', 'here', 'what', 'which', 'who', 'how', 'when', 'where', 'why', 'all', 'any', 'more', 'most', 'some', 'such', 'only', 'also', 'just', 'very', 'up', 'out', 'over' ),
		'fa' => array( 'و', 'در', 'به', 'از', 'که', 'این', 'را', 'با', 'است', 'برای', 'آن', 'یک', 'خود', 'تا', 'کرد', 'بر', 'هم', 'نیز', 'گفت', 'می', 'شود', 'شده', 'ای', 'اما', 'یا', 'هر', 'او', 'ما', 'من', 'شما', 'آنها', 'ها', 'های', 'بود', 'باشد', 'دارد', 'کند', 'کنید', 'کنند', 'نیست', 'هست', 'چه', 'اگر', 'همه', 'پس', 'بی', 'دیگر', 'چون', 'روی', 'وی', 'ان', 'بین', 'پیش', 'کنیم', 'شد', 'شوند', 'میشود', 'می‌شود', 'دهد', 'باید', 'نمی', 'هیچ', 'همین', 'چند', 'اینکه', 'آنکه', 'زیرا', 'ولی', 'سپس', 'تر', 'ترین' ),
	);

	public static function strip( $html ) {
		$html = (string) $html;
		$html = preg_replace( '#<(script|style|noscript|template)[^>]*>.*?</\1>#is', ' ', $html );
		$html = preg_replace( '/\[\/?[a-z0-9_-]+[^\]]*\]/i', ' ', $html ); // Shortcodes.
		$html = preg_replace( '/<(br|p|div|li|h[1-6]|tr|td|th)[^>]*>/i', "\n", $html );
		$text = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return trim( preg_replace( '/[ \t\x{00A0}]+/u', ' ', $text ) );
	}

	/** Normalize Arabic variants, digits, diacritics and whitespace (Persian-first). */
	public static function normalize( $text ) {
		$text = (string) $text;
		$map  = array(
			'ي' => 'ی', 'ى' => 'ی', 'ك' => 'ک', 'ة' => 'ه', 'ۀ' => 'ه', 'أ' => 'ا', 'إ' => 'ا', 'ٱ' => 'ا',
			'٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
			'۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
			'ـ' => '',
		);
		$text = strtr( $text, $map );
		$text = preg_replace( '/[\x{064B}-\x{065F}\x{0670}]/u', '', $text ); // Harakat.
		$text = function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
		return trim( preg_replace( '/\s+/u', ' ', $text ) );
	}

	public static function lang( $text ) {
		$text   = (string) $text;
		$arabic = preg_match_all( '/[\x{0600}-\x{06FF}]/u', $text );
		$latin  = preg_match_all( '/[a-zA-Z]/', $text );
		return $arabic > $latin ? 'fa' : 'en';
	}

	/**
	 * Tokenize into words (keeps ZWNJ-joined Persian compounds together).
	 *
	 * @return string[]
	 */
	public static function words( $text ) {
		$text = self::normalize( $text );
		preg_match_all( '/[\p{L}\p{N}\x{200C}\'-]+/u', $text, $m );
		return array_values(
			array_filter(
				$m[0],
				static function ( $w ) {
					return '' !== trim( $w, "-'\xE2\x80\x8C" );
				}
			)
		);
	}

	public static function word_count( $text ) {
		return count( self::words( $text ) );
	}

	/** @return string[] */
	public static function sentences( $text ) {
		$parts = preg_split( '/(?<=[.!?؟؛…])\s+|\n+/u', (string) $text );
		return array_values(
			array_filter(
				array_map( 'trim', $parts ),
				static function ( $s ) {
					return self::word_count( $s ) >= 2;
				}
			)
		);
	}

	public static function stopwords( $lang ) {
		return isset( self::$stopwords[ $lang ] ) ? self::$stopwords[ $lang ] : array();
	}

	/**
	 * Count non-overlapping occurrences of a phrase (normalized, word-boundary aware).
	 */
	public static function count_phrase( $haystack, $phrase ) {
		$phrase = self::normalize( $phrase );
		if ( '' === $phrase ) {
			return 0;
		}
		$haystack = self::normalize( $haystack );
		$pattern  = '/(?<![\p{L}\p{N}])' . preg_quote( $phrase, '/' ) . '(?![\p{L}\p{N}])/u';
		return (int) preg_match_all( $pattern, $haystack );
	}

	public static function contains( $haystack, $phrase ) {
		return self::count_phrase( $haystack, $phrase ) > 0;
	}

	/**
	 * Term frequencies without stopwords.
	 *
	 * @return array<string,int>
	 */
	public static function term_frequencies( $text, $lang = null ) {
		$lang  = $lang ? $lang : self::lang( $text );
		$stops = array_flip( self::stopwords( $lang ) );
		$tf    = array();
		foreach ( self::words( $text ) as $w ) {
			if ( isset( $stops[ $w ] ) || mb_strlen( $w ) < 2 || is_numeric( $w ) ) {
				continue;
			}
			$tf[ $w ] = isset( $tf[ $w ] ) ? $tf[ $w ] + 1 : 1;
		}
		arsort( $tf );
		return $tf;
	}

	/**
	 * Top n-grams (1–3 words) — used as a lightweight local keyword extractor.
	 *
	 * @return array<string,int>
	 */
	public static function top_ngrams( $text, $limit = 20, $max_n = 3 ) {
		$lang  = self::lang( $text );
		$stops = array_flip( self::stopwords( $lang ) );
		$words = self::words( $text );
		$out   = array();
		$total = count( $words );
		for ( $n = 1; $n <= $max_n; $n++ ) {
			for ( $i = 0; $i + $n <= $total; $i++ ) {
				$gram = array_slice( $words, $i, $n );
				if ( isset( $stops[ $gram[0] ] ) || isset( $stops[ $gram[ $n - 1 ] ] ) || mb_strlen( implode( '', $gram ) ) < 3 ) {
					continue;
				}
				$key         = implode( ' ', $gram );
				$out[ $key ] = isset( $out[ $key ] ) ? $out[ $key ] + $n : $n; // Longer grams weigh more.
			}
		}
		$out = array_filter(
			$out,
			static function ( $c ) {
				return $c >= 2;
			}
		);
		arsort( $out );
		return array_slice( $out, 0, $limit, true );
	}

	/** Cosine similarity of two texts' term vectors (0–1). */
	public static function similarity( $a, $b ) {
		$ta  = self::term_frequencies( $a );
		$tb  = self::term_frequencies( $b );
		$dot = 0;
		foreach ( $ta as $term => $c ) {
			if ( isset( $tb[ $term ] ) ) {
				$dot += $c * $tb[ $term ];
			}
		}
		$na = sqrt( array_sum( array_map( static function ( $x ) {
			return $x * $x;
		}, $ta ) ) );
		$nb = sqrt( array_sum( array_map( static function ( $x ) {
			return $x * $x;
		}, $tb ) ) );
		return ( $na && $nb ) ? $dot / ( $na * $nb ) : 0.0;
	}

	/** Shingle (5-word) fingerprint for near-duplicate detection. */
	public static function simhash( $text ) {
		$words = self::words( $text );
		$v     = array_fill( 0, 64, 0 );
		$count = count( $words );
		for ( $i = 0; $i + 4 < $count; $i++ ) {
			$h = substr( md5( implode( ' ', array_slice( $words, $i, 5 ) ) ), 0, 16 );
			$b = str_pad( base_convert( substr( $h, 0, 8 ), 16, 2 ), 32, '0', STR_PAD_LEFT ) . str_pad( base_convert( substr( $h, 8, 8 ), 16, 2 ), 32, '0', STR_PAD_LEFT );
			for ( $j = 0; $j < 64; $j++ ) {
				$v[ $j ] += '1' === $b[ $j ] ? 1 : -1;
			}
		}
		$bits = '';
		foreach ( $v as $x ) {
			$bits .= $x > 0 ? '1' : '0';
		}
		return $bits;
	}

	public static function hamming( $a, $b ) {
		$d = 0;
		$n = min( strlen( $a ), strlen( $b ) );
		for ( $i = 0; $i < $n; $i++ ) {
			if ( $a[ $i ] !== $b[ $i ] ) {
				$d++;
			}
		}
		return $d;
	}

	public static function truncate( $text, $length ) {
		$text = trim( (string) $text );
		if ( mb_strlen( $text ) <= $length ) {
			return $text;
		}
		$cut = mb_substr( $text, 0, $length );
		$pos = mb_strrpos( $cut, ' ' );
		return rtrim( false !== $pos && $pos > $length * 0.6 ? mb_substr( $cut, 0, $pos ) : $cut, " ,.;:-|،" ) . '…';
	}

	/** Approx pixel width of a SERP title (Google truncates ≈ 580px desktop). */
	public static function pixel_width( $text ) {
		$width = 0;
		foreach ( preg_split( '//u', (string) $text, -1, PREG_SPLIT_NO_EMPTY ) as $ch ) {
			if ( preg_match( '/[A-Z]/', $ch ) ) {
				$width += 11;
			} elseif ( preg_match( '/[mwMW]/', $ch ) ) {
				$width += 14;
			} elseif ( preg_match( '/[ilj.,:;\'|!]/', $ch ) ) {
				$width += 4;
			} elseif ( ' ' === $ch ) {
				$width += 5;
			} elseif ( preg_match( '/[\x{0600}-\x{06FF}]/u', $ch ) ) {
				$width += 8;
			} else {
				$width += 9;
			}
		}
		return $width;
	}

	public static function slugify( $text ) {
		return sanitize_title( $text );
	}
}
