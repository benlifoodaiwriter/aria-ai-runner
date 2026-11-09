<?php
/**
 * Audit check catalog + page-level evaluation + issue persistence.
 *
 * The catalog is the single source of truth used by the crawler, SEO Doctor,
 * health score and recommendations: each check defines category, severity,
 * whether AI/automation can fix it, why it matters and how to fix it.
 *
 * @package SEOlity
 */

namespace SEOlity\Crawler;

use SEOlity\Core\Database;
use SEOlity\Plugin;

defined( 'ABSPATH' ) || exit;

final class Checks {

	/**
	 * code => [category, severity, fixable(none|ai|auto|manual), title, why, fix]
	 */
	public static function catalog() {
		static $c = null;
		if ( null !== $c ) {
			return $c;
		}
		$c = array(
			// Indexation & status.
			'http_4xx'            => array( 'indexation', 'critical', 'auto', __( 'Pages returning 4xx errors', 'seolity' ), __( 'Broken pages waste crawl budget, lose link equity and frustrate users.', 'seolity' ), __( 'Restore the page or 301-redirect it to the closest relevant URL; update internal links.', 'seolity' ) ),
			'http_5xx'            => array( 'indexation', 'critical', 'manual', __( 'Pages returning server errors (5xx)', 'seolity' ), __( 'Repeated 5xx responses make Google crawl less and can drop pages from the index.', 'seolity' ), __( 'Check PHP error logs, hosting resources and plugin conflicts.', 'seolity' ) ),
			'redirect_302'        => array( 'indexation', 'medium', 'auto', __( 'Temporary (302/307) redirects', 'seolity' ), __( 'Temporary redirects signal the move is not permanent; signals may not consolidate.', 'seolity' ), __( 'Use 301 for permanent moves.', 'seolity' ) ),
			'redirect_chain'      => array( 'indexation', 'medium', 'auto', __( 'Redirect chains', 'seolity' ), __( 'Each hop adds latency and Google may stop following long chains.', 'seolity' ), __( 'Point the first URL directly at the final destination.', 'seolity' ) ),
			'redirect_loop'       => array( 'indexation', 'critical', 'manual', __( 'Redirect loops', 'seolity' ), __( 'Loops make pages completely inaccessible.', 'seolity' ), __( 'Break the loop by fixing or deleting one of the redirects.', 'seolity' ) ),
			'noindex'             => array( 'indexation', 'info', 'none', __( 'Noindex pages', 'seolity' ), __( 'These pages are excluded from Google. That is fine for utility pages but harmful for pages meant to rank.', 'seolity' ), __( 'Confirm each noindex is intentional.', 'seolity' ) ),
			'noindex_with_traffic' => array( 'indexation', 'critical', 'auto', __( 'Noindex pages that still earn search impressions', 'seolity' ), __( 'A page Google still shows is set to noindex — traffic will be lost when Google re-crawls it.', 'seolity' ), __( 'Remove noindex if the page should rank.', 'seolity' ) ),
			'noindex_in_sitemap'  => array( 'indexation', 'high', 'auto', __( 'Noindex URLs listed in the XML sitemap', 'seolity' ), __( 'Conflicting signals: the sitemap asks Google to index a page that says noindex.', 'seolity' ), __( 'Remove these URLs from the sitemap or remove the noindex.', 'seolity' ) ),
			'robots_blocked'      => array( 'indexation', 'high', 'manual', __( 'Published pages blocked by robots.txt', 'seolity' ), __( 'Google cannot crawl blocked pages, so their content cannot rank.', 'seolity' ), __( 'Adjust robots.txt rules (SEOlity → Robots).', 'seolity' ) ),
			'nofollow_meta'       => array( 'indexation', 'medium', 'auto', __( 'Pages with meta nofollow', 'seolity' ), __( 'Links on these pages pass no signals to the rest of the site.', 'seolity' ), __( 'Remove nofollow unless intentional.', 'seolity' ) ),
			'canonical_missing'   => array( 'indexation', 'low', 'auto', __( 'Missing canonical tag', 'seolity' ), __( 'Without a canonical, URL variants (parameters, trailing slashes) can be indexed as duplicates.', 'seolity' ), __( 'Add a self-referencing canonical.', 'seolity' ) ),
			'canonical_multiple'  => array( 'indexation', 'high', 'manual', __( 'Multiple canonical tags', 'seolity' ), __( 'Google ignores conflicting canonicals.', 'seolity' ), __( 'Make sure only one plugin/theme outputs a canonical.', 'seolity' ) ),
			'canonical_invalid'   => array( 'indexation', 'medium', 'auto', __( 'Relative or invalid canonical URL', 'seolity' ), __( 'Canonicals should be absolute URLs.', 'seolity' ), __( 'Use the absolute URL.', 'seolity' ) ),
			'canonical_to_error'  => array( 'indexation', 'high', 'auto', __( 'Canonical points to a redirect or error page', 'seolity' ), __( 'Google will likely ignore it and may choose another canonical.', 'seolity' ), __( 'Point the canonical at a 200, indexable URL.', 'seolity' ) ),
			'canonicalized'       => array( 'indexation', 'info', 'none', __( 'Canonicalized to another URL', 'seolity' ), __( 'This URL declares another URL as the primary version.', 'seolity' ), __( 'Verify the canonical target is correct.', 'seolity' ) ),
			'sitemap_missing'     => array( 'indexation', 'high', 'auto', __( 'No XML sitemap found', 'seolity' ), __( 'Sitemaps help Google discover and prioritize URLs.', 'seolity' ), __( 'Enable SEOlity sitemaps or your SEO plugin sitemap and submit it in GSC.', 'seolity' ) ),
			'sitemap_error_urls'  => array( 'indexation', 'high', 'auto', __( 'Sitemap lists redirects or broken URLs', 'seolity' ), __( 'Sitemaps should only list canonical, 200, indexable URLs.', 'seolity' ), __( 'Exclude these URLs from the sitemap.', 'seolity' ) ),
			'robots_no_sitemap'   => array( 'indexation', 'low', 'auto', __( 'robots.txt does not reference the sitemap', 'seolity' ), __( 'Listing the sitemap in robots.txt helps all crawlers find it.', 'seolity' ), __( 'Add a Sitemap: line.', 'seolity' ) ),
			// Meta.
			'title_missing'       => array( 'meta', 'high', 'ai', __( 'Missing title tag', 'seolity' ), __( 'The title is the strongest on-page relevance signal and the SERP headline.', 'seolity' ), __( 'Write a unique, descriptive title.', 'seolity' ) ),
			'title_too_long'      => array( 'meta', 'medium', 'ai', __( 'Title too long (truncated in SERP)', 'seolity' ), __( 'Truncated titles hide your value proposition and lower CTR.', 'seolity' ), __( 'Keep titles under ~580px / 60 characters.', 'seolity' ) ),
			'title_too_short'     => array( 'meta', 'low', 'ai', __( 'Title too short', 'seolity' ), __( 'Short titles miss qualifiers searchers look for.', 'seolity' ), __( 'Add a benefit, qualifier or year.', 'seolity' ) ),
			'title_multiple'      => array( 'meta', 'medium', 'manual', __( 'Multiple <title> tags', 'seolity' ), __( 'Usually a theme/plugin conflict.', 'seolity' ), __( 'Ensure only one title is output.', 'seolity' ) ),
			'duplicate_title'     => array( 'meta', 'medium', 'ai', __( 'Duplicate titles', 'seolity' ), __( 'Duplicate titles make pages compete and confuse Google.', 'seolity' ), __( 'Make every title unique.', 'seolity' ) ),
			'meta_desc_missing'   => array( 'meta', 'high', 'ai', __( 'Missing meta description', 'seolity' ), __( 'Google writes its own snippet, often lowering CTR.', 'seolity' ), __( 'Write a compelling 120–158 character description.', 'seolity' ) ),
			'meta_desc_length'    => array( 'meta', 'low', 'ai', __( 'Meta description too short or too long', 'seolity' ), __( 'Short descriptions under-sell; long ones get cut off.', 'seolity' ), __( 'Aim for 120–158 characters.', 'seolity' ) ),
			'duplicate_meta'      => array( 'meta', 'medium', 'ai', __( 'Duplicate meta descriptions', 'seolity' ), __( 'Duplicates reduce snippet relevance.', 'seolity' ), __( 'Write unique descriptions.', 'seolity' ) ),
			'og_missing'          => array( 'meta', 'low', 'auto', __( 'Missing Open Graph tags', 'seolity' ), __( 'Shares on social networks look broken without OG title/image.', 'seolity' ), __( 'SEOlity can output OG tags automatically.', 'seolity' ) ),
			'twitter_missing'     => array( 'meta', 'low', 'auto', __( 'Missing Twitter Card tags', 'seolity' ), __( 'X/Twitter previews fall back to plain links.', 'seolity' ), __( 'Enable Twitter Cards output.', 'seolity' ) ),
			// Content.
			'h1_missing'          => array( 'content', 'high', 'manual', __( 'Missing H1', 'seolity' ), __( 'The H1 tells users and Google what the page is about.', 'seolity' ), __( 'Ensure the theme prints the title as H1 or add one.', 'seolity' ) ),
			'h1_multiple'         => array( 'content', 'low', 'manual', __( 'Multiple H1 headings', 'seolity' ), __( 'Not an error for Google, but a weak document outline for accessibility.', 'seolity' ), __( 'Use one H1, then H2/H3.', 'seolity' ) ),
			'h2_missing'          => array( 'content', 'low', 'ai', __( 'Long content without subheadings', 'seolity' ), __( 'Walls of text hurt scanning and passage ranking.', 'seolity' ), __( 'Add descriptive H2/H3 sections.', 'seolity' ) ),
			'heading_skip'        => array( 'content', 'low', 'ai', __( 'Heading levels skipped', 'seolity' ), __( 'Skipped levels break the outline for screen readers.', 'seolity' ), __( 'Keep a logical heading hierarchy.', 'seolity' ) ),
			'thin_content'        => array( 'content', 'high', 'ai', __( 'Thin content', 'seolity' ), __( 'Pages with little unique content rarely rank and can drag down site quality.', 'seolity' ), __( 'Expand with genuinely helpful information, merge with a related page, or noindex.', 'seolity' ) ),
			'duplicate_content'   => array( 'content', 'high', 'manual', __( 'Duplicate content', 'seolity' ), __( 'Identical pages split signals; Google picks one at random.', 'seolity' ), __( 'Canonicalize, merge or rewrite.', 'seolity' ) ),
			'near_duplicate'      => array( 'content', 'medium', 'ai', __( 'Near-duplicate content', 'seolity' ), __( 'Very similar pages may cannibalize each other.', 'seolity' ), __( 'Differentiate the intent of each page or consolidate.', 'seolity' ) ),
			'cannibalization'     => array( 'content', 'medium', 'ai', __( 'Keyword cannibalization', 'seolity' ), __( 'Several URLs compete for the same query, diluting rankings.', 'seolity' ), __( 'Merge, redirect, differentiate or re-target.', 'seolity' ) ),
			'js_dependent'        => array( 'content', 'medium', 'manual', __( 'Content may depend on JavaScript', 'seolity' ), __( 'Very little text in the HTML but many scripts — content rendered client-side may be indexed late or not at all.', 'seolity' ), __( 'Server-render important content.', 'seolity' ) ),
			// Links.
			'broken_internal_link' => array( 'links', 'high', 'auto', __( 'Broken internal links', 'seolity' ), __( 'Links to 404 pages waste crawl budget and frustrate users.', 'seolity' ), __( 'Fix the link or redirect the target.', 'seolity' ) ),
			'link_to_redirect'    => array( 'links', 'low', 'auto', __( 'Internal links pointing to redirects', 'seolity' ), __( 'Extra hops slow crawling and users.', 'seolity' ), __( 'Link directly to the final URL.', 'seolity' ) ),
			'broken_external_link' => array( 'links', 'medium', 'manual', __( 'Broken external links', 'seolity' ), __( 'Links to dead external pages hurt UX and trust.', 'seolity' ), __( 'Update or remove the outdated links.', 'seolity' ) ),
			'orphan_page'         => array( 'links', 'medium', 'ai', __( 'Orphan pages (no internal links)', 'seolity' ), __( 'Pages without internal links are hard to discover and seem unimportant.', 'seolity' ), __( 'Link to them from relevant pages (SEOlity → Internal Links).', 'seolity' ) ),
			'deep_page'           => array( 'links', 'low', 'ai', __( 'Pages deeper than 4 clicks', 'seolity' ), __( 'Deep pages get crawled less often and receive less internal PageRank.', 'seolity' ), __( 'Improve navigation, hubs and internal links.', 'seolity' ) ),
			'too_many_links'      => array( 'links', 'low', 'manual', __( 'Excessive links on page', 'seolity' ), __( 'Hundreds of links dilute value and overwhelm users.', 'seolity' ), __( 'Trim mega-menus/footers.', 'seolity' ) ),
			// Images.
			'img_alt_missing'     => array( 'images', 'medium', 'ai', __( 'Images without ALT text', 'seolity' ), __( 'ALT text is required for accessibility and helps image search.', 'seolity' ), __( 'Generate descriptive ALT text (SEOlity → Images).', 'seolity' ) ),
			'img_broken'          => array( 'images', 'medium', 'manual', __( 'Broken images', 'seolity' ), __( 'Broken images look unprofessional and waste requests.', 'seolity' ), __( 'Re-upload or remove the image.', 'seolity' ) ),
			'img_no_dimensions'   => array( 'performance', 'low', 'manual', __( 'Images without width/height', 'seolity' ), __( 'Missing dimensions cause layout shift (CLS).', 'seolity' ), __( 'Set width and height attributes.', 'seolity' ) ),
			// Schema.
			'schema_missing'      => array( 'schema', 'low', 'auto', __( 'No structured data', 'seolity' ), __( 'Structured data enables rich results and helps entity understanding.', 'seolity' ), __( 'Enable SEOlity schema output.', 'seolity' ) ),
			'schema_invalid'      => array( 'schema', 'high', 'manual', __( 'Invalid JSON-LD', 'seolity' ), __( 'Invalid structured data is ignored by Google.', 'seolity' ), __( 'Fix the JSON syntax.', 'seolity' ) ),
			'product_schema_missing' => array( 'schema', 'high', 'auto', __( 'Product pages without Product schema', 'seolity' ), __( 'Product rich results (price, stock, rating) increase CTR.', 'seolity' ), __( 'Enable Product schema.', 'seolity' ) ),
			'breadcrumb_missing'  => array( 'schema', 'low', 'auto', __( 'No breadcrumbs', 'seolity' ), __( 'Breadcrumbs improve navigation and SERP display.', 'seolity' ), __( 'Enable BreadcrumbList schema / breadcrumbs.', 'seolity' ) ),
			// Technical / mobile / security.
			'not_https'           => array( 'security', 'high', 'manual', __( 'Pages not served over HTTPS', 'seolity' ), __( 'HTTPS is a ranking signal and required for trust.', 'seolity' ), __( 'Install SSL and redirect HTTP → HTTPS.', 'seolity' ) ),
			'mixed_content'       => array( 'security', 'high', 'manual', __( 'Mixed content (HTTP resources on HTTPS)', 'seolity' ), __( 'Browsers block or warn on insecure resources.', 'seolity' ), __( 'Load all resources over HTTPS.', 'seolity' ) ),
			'viewport_missing'    => array( 'mobile', 'high', 'manual', __( 'Missing viewport meta (not mobile-friendly)', 'seolity' ), __( 'Google uses mobile-first indexing.', 'seolity' ), __( 'Add <meta name="viewport" content="width=device-width, initial-scale=1">.', 'seolity' ) ),
			'lang_missing'        => array( 'international', 'low', 'manual', __( 'Missing html lang attribute', 'seolity' ), __( 'Helps search engines and screen readers pick the right language.', 'seolity' ), __( 'Set the site language in Settings → General.', 'seolity' ) ),
			'hreflang_invalid'    => array( 'international', 'medium', 'manual', __( 'Invalid hreflang annotations', 'seolity' ), __( 'Broken hreflang can show the wrong language version.', 'seolity' ), __( 'Use valid ISO codes, self-references and x-default.', 'seolity' ) ),
			'url_issues'          => array( 'technical', 'low', 'manual', __( 'Non-friendly URLs', 'seolity' ), __( 'Long, parameter-heavy or uppercase URLs are harder to share and may duplicate.', 'seolity' ), __( 'Use short, lowercase, hyphenated slugs (redirect old ones).', 'seolity' ) ),
			'slow_response'       => array( 'performance', 'medium', 'manual', __( 'Slow server response (TTFB)', 'seolity' ), __( 'Slow responses hurt Core Web Vitals and crawl rate.', 'seolity' ), __( 'Enable page caching, upgrade hosting, reduce plugins.', 'seolity' ) ),
			'large_page'          => array( 'performance', 'low', 'manual', __( 'Very large HTML document', 'seolity' ), __( 'Heavy HTML slows rendering on mobile.', 'seolity' ), __( 'Reduce inline code and DOM size.', 'seolity' ) ),
			'cwv_poor'            => array( 'performance', 'high', 'manual', __( 'Poor Core Web Vitals', 'seolity' ), __( 'Poor LCP/INP/CLS hurt UX and are part of page experience signals.', 'seolity' ), __( 'Optimize images, fonts, scripts and caching.', 'seolity' ) ),
		);
		return $c;
	}

	public static function meta( $code ) {
		$c = self::catalog();
		if ( ! isset( $c[ $code ] ) ) {
			return null;
		}
		list( $category, $severity, $fixable, $title, $why, $fix ) = $c[ $code ];
		return compact( 'category', 'severity', 'fixable', 'title', 'why', 'fix' );
	}

	/**
	 * Page-level checks.
	 *
	 * @param array $row    URL row (object_type etc.).
	 * @param array $page   PageParser output.
	 * @param array $fetch  status, time_ms, bytes, url.
	 * @param array $ctx    thin_words, robots_allowed.
	 * @return array<string, array> code => context
	 */
	public static function evaluate_page( array $row, array $page, array $fetch, array $ctx ) {
		$found  = array();
		$url    = $fetch['url'];
		$status = (int) $fetch['status'];

		if ( $status >= 500 ) {
			$found['http_5xx'] = array( 'status' => $status );
			return $found;
		}
		if ( $status >= 400 ) {
			$found['http_4xx'] = array( 'status' => $status );
			return $found;
		}
		if ( in_array( $status, array( 302, 303, 307 ), true ) ) {
			$found['redirect_302'] = array( 'status' => $status, 'to' => $fetch['location'] );
		}
		if ( $status >= 300 ) {
			return $found;
		}
		if ( empty( $ctx['robots_allowed'] ) && ! empty( $row['object_id'] ) ) {
			$found['robots_blocked'] = array();
		}
		if ( $fetch['time_ms'] > 1500 ) {
			$found['slow_response'] = array( 'ms' => $fetch['time_ms'] );
		}
		if ( $fetch['bytes'] > 2 * MB_IN_BYTES ) {
			$found['large_page'] = array( 'bytes' => $fetch['bytes'] );
		}
		if ( 0 !== stripos( $url, 'https://' ) ) {
			$found['not_https'] = array();
		}
		if ( $page['mixed_content'] ) {
			$found['mixed_content'] = array( 'resources' => array_slice( $page['mixed_content'], 0, 5 ) );
		}
		$robots  = $page['robots'] . ',' . $page['x_robots'];
		$noindex = false !== strpos( $robots, 'noindex' ) || false !== strpos( $robots, 'none' );
		if ( $noindex ) {
			$found['noindex'] = array( 'robots' => trim( $robots, ',' ) );
		}
		if ( false !== strpos( $robots, 'nofollow' ) ) {
			$found['nofollow_meta'] = array();
		}
		// Canonical.
		if ( count( $page['canonicals'] ) > 1 && count( array_unique( $page['canonicals'] ) ) > 1 ) {
			$found['canonical_multiple'] = array( 'canonicals' => $page['canonicals'] );
		} elseif ( ! $page['canonicals'] ) {
			if ( ! $noindex ) {
				$found['canonical_missing'] = array();
			}
		} else {
			$canon = $page['canonicals'][0];
			if ( ! preg_match( '#^https?://#i', $canon ) ) {
				$found['canonical_invalid'] = array( 'canonical' => $canon );
			} elseif ( untrailingslashit( PageParser::normalize_url( $canon ) ) !== untrailingslashit( PageParser::normalize_url( $url ) ) ) {
				$found['canonicalized'] = array( 'canonical' => $canon );
			}
		}
		$indexable = ! $noindex && ! isset( $found['canonicalized'] );

		// Meta.
		$t_len = mb_strlen( $page['title'] );
		if ( $page['titles'] > 1 ) {
			$found['title_multiple'] = array( 'count' => $page['titles'] );
		}
		if ( 0 === $t_len ) {
			$found['title_missing'] = array();
		} elseif ( $t_len > 65 || \SEOlity\Analysis\Text::pixel_width( $page['title'] ) > 600 ) {
			$found['title_too_long'] = array( 'length' => $t_len, 'title' => $page['title'] );
		} elseif ( $t_len < 20 ) {
			$found['title_too_short'] = array( 'length' => $t_len, 'title' => $page['title'] );
		}
		$d_len = mb_strlen( $page['meta_description'] );
		if ( $indexable ) {
			if ( 0 === $d_len ) {
				$found['meta_desc_missing'] = array();
			} elseif ( $d_len < 70 || $d_len > 170 ) {
				$found['meta_desc_length'] = array( 'length' => $d_len );
			}
			if ( empty( $page['og']['title'] ) || empty( $page['og']['image'] ) ) {
				$found['og_missing'] = array( 'present' => array_keys( $page['og'] ) );
			}
			if ( empty( $page['twitter']['card'] ) ) {
				$found['twitter_missing'] = array();
			}
		}

		// Content & structure.
		if ( ! $page['h1'] ) {
			$found['h1_missing'] = array();
		} elseif ( count( $page['h1'] ) > 1 ) {
			$found['h1_multiple'] = array( 'h1' => array_slice( $page['h1'], 0, 5 ) );
		}
		if ( $page['word_count'] > 500 && ! $page['h2'] ) {
			$found['h2_missing'] = array( 'words' => $page['word_count'] );
		}
		$prev = 0;
		foreach ( $page['heading_levels'] as $lvl ) {
			if ( $prev && $lvl > $prev + 1 ) {
				$found['heading_skip'] = array( 'from' => $prev, 'to' => $lvl );
				break;
			}
			$prev = $lvl;
		}
		$type = isset( $row['object_type'] ) ? $row['object_type'] : '';
		if ( $indexable && 'post' === $type && $page['word_count'] < (int) $ctx['thin_words'] && ! self::is_listing( $url ) ) {
			$found['thin_content'] = array( 'words' => $page['word_count'], 'threshold' => (int) $ctx['thin_words'] );
		}
		if ( $page['word_count'] < 80 && $page['scripts'] > 25 ) {
			$found['js_dependent'] = array( 'words' => $page['word_count'], 'scripts' => $page['scripts'] );
		}
		if ( count( $page['links'] ) > 350 ) {
			$found['too_many_links'] = array( 'links' => count( $page['links'] ) );
		}

		// Images.
		$no_alt = array();
		$no_dim = 0;
		foreach ( $page['images'] as $img ) {
			if ( null === $img['alt'] || ( '' === $img['alt'] && ! preg_match( '/(spacer|pixel|blank)/i', $img['src'] ) ) ) {
				$no_alt[] = $img['src'];
			}
			if ( ! $img['width'] || ! $img['height'] ) {
				$no_dim++;
			}
		}
		if ( $no_alt ) {
			$found['img_alt_missing'] = array( 'count' => count( $no_alt ), 'images' => array_slice( $no_alt, 0, 10 ) );
		}
		if ( $no_dim >= 3 ) {
			$found['img_no_dimensions'] = array( 'count' => $no_dim );
		}

		// Schema.
		if ( $page['schema_errors'] ) {
			$found['schema_invalid'] = array( 'blocks' => $page['schema_errors'] );
		}
		if ( $indexable && ! $page['schema_types'] ) {
			$found['schema_missing'] = array();
		}
		if ( isset( $row['post_type'] ) && 'product' === $row['post_type'] && ! in_array( 'Product', $page['schema_types'], true ) && ! in_array( 'ProductGroup', $page['schema_types'], true ) ) {
			$found['product_schema_missing'] = array();
		}
		if ( $indexable && ! $page['has_breadcrumb'] && substr_count( trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' ), '/' ) >= 1 ) {
			$found['breadcrumb_missing'] = array();
		}

		// Mobile / i18n / URL.
		if ( ! $page['viewport'] ) {
			$found['viewport_missing'] = array();
		}
		if ( '' === $page['lang'] ) {
			$found['lang_missing'] = array();
		}
		if ( $page['hreflang'] ) {
			$bad = array();
			$self = false;
			foreach ( $page['hreflang'] as $h ) {
				if ( ! preg_match( '/^([a-z]{2,3}(-[a-z0-9]{2,4})?|x-default)$/i', $h['lang'] ) ) {
					$bad[] = $h['lang'];
				}
				if ( untrailingslashit( PageParser::normalize_url( (string) PageParser::absolutize( $h['href'], $url ) ) ) === untrailingslashit( PageParser::normalize_url( $url ) ) ) {
					$self = true;
				}
			}
			if ( $bad || ! $self ) {
				$found['hreflang_invalid'] = array( 'invalid_codes' => $bad, 'self_reference' => $self );
			}
		}
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$q    = (string) wp_parse_url( $url, PHP_URL_QUERY );
		$decoded = rawurldecode( $path );
		if ( mb_strlen( $decoded ) > 115 || preg_match( '/[A-Z_]/', $decoded ) || substr_count( $q, '&' ) >= 2 ) {
			$found['url_issues'] = array( 'path' => $decoded );
		}
		return $found;
	}

	private static function is_listing( $url ) {
		return (bool) preg_match( '#/(page/\d+|category|tag|author|shop|product-category|product-tag)/#', $url );
	}

	/**
	 * Persist the issues found for one URL; resolve page-level issues no longer present.
	 *
	 * @param string[]|null $scope Check codes this evaluation is authoritative for (null = page-level).
	 */
	public static function record( Plugin $plugin, $url_id, array $found, $scope = null ) {
		$db    = $plugin->db;
		$pid   = $db->project_id();
		$now   = Database::now();
		$codes = array_keys( $found );
		if ( null === $scope ) {
			$scope = self::page_level_codes();
		}
		$resolve = array_diff( $scope, $codes );
		if ( $resolve ) {
			$in = implode( ',', array_fill( 0, count( $resolve ), '%s' ) );
			$db->query(
				"UPDATE {issues} SET status = 'fixed', last_seen = %s WHERE project_id = %d AND url_id = %d AND status = 'open' AND check_code IN ($in)",
				array_merge( array( $now, $pid, (int) $url_id ), array_values( $resolve ) )
			);
		}
		foreach ( $found as $code => $context ) {
			$m = self::meta( $code );
			if ( ! $m ) {
				continue;
			}
			$db->query(
				"INSERT INTO {issues} (project_id,url_id,check_code,category,severity,context,status,fixable,first_seen,last_seen) VALUES (%d,%d,%s,%s,%s,%s,'open',%d,%s,%s)
				ON DUPLICATE KEY UPDATE context = VALUES(context), severity = VALUES(severity), last_seen = VALUES(last_seen), status = IF(status = 'ignored', 'ignored', 'open')",
				array( $pid, (int) $url_id, $code, $m['category'], $m['severity'], wp_json_encode( $context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ), 'none' === $m['fixable'] || 'manual' === $m['fixable'] ? 0 : 1, $now, $now )
			);
		}
	}

	/** Codes produced by evaluate_page(). */
	public static function page_level_codes() {
		return array( 'http_4xx', 'http_5xx', 'redirect_302', 'robots_blocked', 'slow_response', 'large_page', 'not_https', 'mixed_content', 'noindex', 'nofollow_meta', 'canonical_multiple', 'canonical_missing', 'canonical_invalid', 'canonicalized', 'title_multiple', 'title_missing', 'title_too_long', 'title_too_short', 'meta_desc_missing', 'meta_desc_length', 'og_missing', 'twitter_missing', 'h1_missing', 'h1_multiple', 'h2_missing', 'heading_skip', 'thin_content', 'js_dependent', 'too_many_links', 'img_alt_missing', 'img_no_dimensions', 'schema_invalid', 'schema_missing', 'product_schema_missing', 'breadcrumb_missing', 'viewport_missing', 'lang_missing', 'hreflang_invalid', 'url_issues' );
	}

	/** Codes produced by the site-level finalize pass. */
	public static function site_level_codes() {
		return array( 'duplicate_title', 'duplicate_meta', 'duplicate_content', 'near_duplicate', 'orphan_page', 'deep_page', 'broken_internal_link', 'link_to_redirect', 'redirect_chain', 'redirect_loop', 'canonical_to_error', 'noindex_in_sitemap', 'noindex_with_traffic', 'img_broken' );
	}
}
