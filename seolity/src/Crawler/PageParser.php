<?php
/**
 * HTML → SEO facts extractor (DOMDocument, UTF-8/RTL safe).
 *
 * @package SEOlity
 */

namespace SEOlity\Crawler;

use SEOlity\Analysis\Text;

defined( 'ABSPATH' ) || exit;

final class PageParser {

	/**
	 * @param string $html    HTML.
	 * @param string $url     Final URL of the document.
	 * @param array  $headers Lower-cased response headers.
	 * @return array
	 */
	public static function parse( $html, $url, array $headers = array() ) {
		$out = array(
			'title'          => '',
			'titles'         => 0,
			'meta_description' => '',
			'meta_descriptions' => 0,
			'robots'         => '',
			'x_robots'       => isset( $headers['x-robots-tag'] ) ? strtolower( is_array( $headers['x-robots-tag'] ) ? implode( ',', $headers['x-robots-tag'] ) : $headers['x-robots-tag'] ) : '',
			'canonicals'     => array(),
			'h1'             => array(),
			'h2'             => array(),
			'h3'             => array(),
			'heading_levels' => array(),
			'lang'           => '',
			'viewport'       => false,
			'og'             => array(),
			'twitter'        => array(),
			'schema_types'   => array(),
			'schema_errors'  => 0,
			'schema_blocks'  => array(),
			'hreflang'       => array(),
			'links'          => array(),
			'images'         => array(),
			'scripts'        => 0,
			'word_count'     => 0,
			'text_sample'    => '',
			'content_hash'   => '',
			'simhash'        => '',
			'mixed_content'  => array(),
			'rel_next'       => '',
			'rel_prev'       => '',
			'has_breadcrumb' => false,
			'amp'            => '',
		);
		if ( '' === trim( (string) $html ) ) {
			return $out;
		}
		$prev = libxml_use_internal_errors( true );
		$dom  = new \DOMDocument();
		$dom->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		$xp = new \DOMXPath( $dom );

		$titles       = $xp->query( '//head/title|//title' );
		$out['titles'] = $titles->length;
		if ( $titles->length ) {
			$out['title'] = trim( preg_replace( '/\s+/u', ' ', $titles->item( 0 )->textContent ) );
		}
		$html_el = $xp->query( '//html' )->item( 0 );
		if ( $html_el instanceof \DOMElement ) {
			$out['lang'] = $html_el->getAttribute( 'lang' );
		}

		foreach ( $xp->query( '//meta' ) as $m ) {
			/** @var \DOMElement $m */
			$name    = strtolower( $m->getAttribute( 'name' ) );
			$prop    = strtolower( $m->getAttribute( 'property' ) );
			$content = trim( $m->getAttribute( 'content' ) );
			if ( 'description' === $name ) {
				$out['meta_descriptions']++;
				if ( '' === $out['meta_description'] ) {
					$out['meta_description'] = $content;
				}
			} elseif ( 'robots' === $name || 'googlebot' === $name ) {
				$out['robots'] = trim( $out['robots'] . ',' . strtolower( $content ), ',' );
			} elseif ( 'viewport' === $name ) {
				$out['viewport'] = true;
			} elseif ( 0 === strpos( $prop, 'og:' ) ) {
				$out['og'][ substr( $prop, 3 ) ] = $content;
			} elseif ( 0 === strpos( $name, 'twitter:' ) ) {
				$out['twitter'][ substr( $name, 8 ) ] = $content;
			}
		}

		foreach ( $xp->query( '//link' ) as $l ) {
			/** @var \DOMElement $l */
			$rel  = strtolower( $l->getAttribute( 'rel' ) );
			$href = trim( $l->getAttribute( 'href' ) );
			if ( 'canonical' === $rel ) {
				$out['canonicals'][] = $href;
			} elseif ( 'alternate' === $rel && $l->getAttribute( 'hreflang' ) ) {
				$out['hreflang'][] = array( 'lang' => strtolower( $l->getAttribute( 'hreflang' ) ), 'href' => $href );
			} elseif ( 'next' === $rel ) {
				$out['rel_next'] = $href;
			} elseif ( 'prev' === $rel ) {
				$out['rel_prev'] = $href;
			} elseif ( 'amphtml' === $rel ) {
				$out['amp'] = $href;
			} elseif ( 'stylesheet' === $rel ) {
				self::check_mixed( $out, $href, $url, 'stylesheet' );
			}
		}

		foreach ( array( 1, 2, 3, 4, 5, 6 ) as $level ) {
			foreach ( $xp->query( '//h' . $level ) as $h ) {
				$text = trim( preg_replace( '/\s+/u', ' ', $h->textContent ) );
				if ( $level <= 3 ) {
					$out[ 'h' . $level ][] = mb_substr( $text, 0, 300 );
				}
				$out['heading_levels'][] = $level;
			}
		}

		foreach ( $xp->query( '//script[@type="application/ld+json"]' ) as $s ) {
			$json = json_decode( trim( $s->textContent ), true );
			if ( null === $json ) {
				$out['schema_errors']++;
				continue;
			}
			$out['schema_blocks'][] = $json;
			foreach ( self::schema_types( $json ) as $t ) {
				$out['schema_types'][] = $t;
			}
		}
		$out['schema_types'] = array_values( array_unique( $out['schema_types'] ) );
		foreach ( $xp->query( '//*[@itemtype]' ) as $el ) {
			/** @var \DOMElement $el */
			$type = basename( $el->getAttribute( 'itemtype' ) );
			if ( $type && ! in_array( $type, $out['schema_types'], true ) ) {
				$out['schema_types'][] = $type;
			}
		}
		$out['has_breadcrumb'] = in_array( 'BreadcrumbList', $out['schema_types'], true ) || $xp->query( '//*[contains(@class,"breadcrumb")]' )->length > 0;

		$base_host = wp_parse_url( $url, PHP_URL_HOST );
		foreach ( $xp->query( '//a[@href]' ) as $a ) {
			/** @var \DOMElement $a */
			$href = trim( $a->getAttribute( 'href' ) );
			if ( '' === $href || '#' === $href[0] || preg_match( '#^(mailto|tel|javascript|data|sms|whatsapp):#i', $href ) ) {
				continue;
			}
			$abs = self::absolutize( $href, $url );
			if ( ! $abs ) {
				continue;
			}
			$host           = wp_parse_url( $abs, PHP_URL_HOST );
			$out['links'][] = array(
				'href'     => $abs,
				'anchor'   => mb_substr( trim( preg_replace( '/\s+/u', ' ', $a->textContent ) ), 0, 200 ),
				'nofollow' => false !== stripos( $a->getAttribute( 'rel' ), 'nofollow' ),
				'internal' => self::same_site( $host, $base_host ),
			);
		}

		foreach ( $xp->query( '//img' ) as $img ) {
			/** @var \DOMElement $img */
			$src = $img->getAttribute( 'src' );
			if ( ! $src || 0 === strpos( $src, 'data:' ) ) {
				$src = $img->getAttribute( 'data-src' ) ? $img->getAttribute( 'data-src' ) : $img->getAttribute( 'data-lazy-src' );
			}
			if ( ! $src ) {
				continue;
			}
			$abs             = self::absolutize( $src, $url );
			$out['images'][] = array(
				'src'     => $abs ? $abs : $src,
				'alt'     => $img->hasAttribute( 'alt' ) ? trim( $img->getAttribute( 'alt' ) ) : null,
				'width'   => $img->getAttribute( 'width' ),
				'height'  => $img->getAttribute( 'height' ),
				'loading' => $img->getAttribute( 'loading' ),
			);
			self::check_mixed( $out, $src, $url, 'img' );
		}
		foreach ( $xp->query( '//script' ) as $s ) {
			/** @var \DOMElement $s */
			$out['scripts']++;
			if ( $s->getAttribute( 'src' ) ) {
				self::check_mixed( $out, $s->getAttribute( 'src' ), $url, 'script' );
			}
		}
		foreach ( $xp->query( '//iframe[@src]|//video[@src]|//audio[@src]|//source[@src]' ) as $el ) {
			/** @var \DOMElement $el */
			self::check_mixed( $out, $el->getAttribute( 'src' ), $url, $el->nodeName );
		}

		// Main content text: prefer <main>/<article>, strip chrome.
		foreach ( $xp->query( '//script|//style|//noscript|//template|//svg' ) as $junk ) {
			$junk->parentNode->removeChild( $junk );
		}
		$main = $xp->query( '//main|//article|//*[@role="main"]' );
		$node = $main->length ? $main->item( 0 ) : $xp->query( '//body' )->item( 0 );
		if ( $node ) {
			if ( ! $main->length ) {
				foreach ( $xp->query( './/header|.//footer|.//nav|.//aside', $node ) as $chrome ) {
					$chrome->parentNode->removeChild( $chrome );
				}
			}
			$text               = trim( preg_replace( '/\s+/u', ' ', $node->textContent ) );
			$out['word_count']  = Text::word_count( $text );
			$out['text_sample'] = mb_substr( $text, 0, 1500 );
			$out['content_hash'] = $out['word_count'] > 30 ? sha1( Text::normalize( $text ) ) : '';
			$out['simhash']      = $out['word_count'] > 80 ? Text::simhash( mb_substr( $text, 0, 20000 ) ) : '';
		}
		return $out;
	}

	private static function schema_types( $json ) {
		$types = array();
		if ( isset( $json['@graph'] ) && is_array( $json['@graph'] ) ) {
			foreach ( $json['@graph'] as $node ) {
				$types = array_merge( $types, self::schema_types( $node ) );
			}
		}
		if ( isset( $json[0] ) && is_array( $json[0] ) ) {
			foreach ( $json as $node ) {
				$types = array_merge( $types, self::schema_types( $node ) );
			}
		}
		if ( isset( $json['@type'] ) ) {
			$types = array_merge( $types, (array) $json['@type'] );
		}
		return array_filter( $types, 'is_string' );
	}

	private static function check_mixed( array &$out, $src, $page_url, $kind ) {
		if ( 0 === stripos( $page_url, 'https://' ) && 0 === stripos( (string) $src, 'http://' ) && count( $out['mixed_content'] ) < 20 ) {
			$out['mixed_content'][] = array( 'kind' => $kind, 'src' => $src );
		}
	}

	public static function same_site( $host, $base_host ) {
		if ( ! $host ) {
			return true;
		}
		$strip = static function ( $h ) {
			return preg_replace( '/^www\./', '', strtolower( (string) $h ) );
		};
		return $strip( $host ) === $strip( $base_host );
	}

	/** Resolve a (possibly relative) URL against a base, drop fragments. */
	public static function absolutize( $href, $base ) {
		$href = html_entity_decode( trim( $href ), ENT_QUOTES, 'UTF-8' );
		$href = preg_replace( '/#.*$/', '', $href );
		if ( '' === $href ) {
			return null;
		}
		if ( preg_match( '#^https?://#i', $href ) ) {
			return $href;
		}
		$b = wp_parse_url( $base );
		if ( empty( $b['scheme'] ) || empty( $b['host'] ) ) {
			return null;
		}
		$root = $b['scheme'] . '://' . $b['host'] . ( isset( $b['port'] ) ? ':' . $b['port'] : '' );
		if ( 0 === strpos( $href, '//' ) ) {
			return $b['scheme'] . ':' . $href;
		}
		if ( '/' === $href[0] ) {
			return $root . $href;
		}
		if ( '?' === $href[0] ) {
			return $root . ( isset( $b['path'] ) ? $b['path'] : '/' ) . $href;
		}
		$dir  = isset( $b['path'] ) ? preg_replace( '#/[^/]*$#', '/', $b['path'] ) : '/';
		$path = $dir . $href;
		$segs = array();
		foreach ( explode( '/', $path ) as $seg ) {
			if ( '..' === $seg ) {
				array_pop( $segs );
			} elseif ( '.' !== $seg ) {
				$segs[] = $seg;
			}
		}
		return $root . implode( '/', $segs );
	}

	/** Canonical form used as crawl identity (lower host, no fragment, keep query). */
	public static function normalize_url( $url ) {
		$p = wp_parse_url( $url );
		if ( empty( $p['host'] ) ) {
			return $url;
		}
		$path  = isset( $p['path'] ) ? $p['path'] : '/';
		$query = isset( $p['query'] ) ? '?' . $p['query'] : '';
		return strtolower( $p['scheme'] ) . '://' . strtolower( $p['host'] ) . ( isset( $p['port'] ) ? ':' . $p['port'] : '' ) . $path . $query;
	}
}
