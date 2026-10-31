<?php
/**
 * Step-by-step guides for roadmap items: exactly where to click in the WordPress
 * dashboard (aware of the active SEO plugin), what to change and how to verify it.
 *
 * Each step: { title, detail, where (breadcrumb), link (URL), action (optional UI action) }.
 * Actions understood by the admin UI: ai_targets (run SEOlity AI on every target),
 * meta_targets (AI title & description per target), inspect_targets (Search Console).
 *
 * @package SEOlity
 */

namespace SEOlity\Roadmap;

use SEOlity\Admin\EditorIntegration;

defined( 'ABSPATH' ) || exit;

final class Guides {

	private static function app( $route ) {
		return admin_url( 'admin.php?page=seolity#/' . ltrim( $route, '#/' ) );
	}

	private static function step( $title, $detail, $where = '', $link = '', $action = '' ) {
		return array_filter( compact( 'title', 'detail', 'where', 'link', 'action' ), static function ( $v ) {
			return '' !== $v;
		} );
	}

	/** Where the SEO title / description / robots are edited with the active SEO plugin. */
	private static function seo_box() {
		$engine = function_exists( 'seolity' ) ? seolity()->meta->engine() : 'seolity';
		$name   = EditorIntegration::engine_name( $engine );
		switch ( $engine ) {
			case 'rankmath':
				return array( $name, __( 'In the editor open the Rank Math panel (the score icon top-right, or the Rank Math box under the content) → General → “Edit Snippet”.', 'seolity' ), __( 'Rank Math panel → Advanced → Robots Meta', 'seolity' ) );
			case 'yoast':
				return array( $name, __( 'Scroll below the content to the Yoast SEO box → “SEO title” and “Meta description”.', 'seolity' ), __( 'Yoast SEO box → Advanced → “Allow search engines to show this content?” = Yes', 'seolity' ) );
			case 'seopress':
			case 'aioseo':
				return array( $name, sprintf( /* translators: %s: SEO plugin */ __( 'Scroll below the content to the %s box → title and meta description fields.', 'seolity' ), $name ), sprintf( /* translators: %s: SEO plugin */ __( '%s box → Advanced → robots: index', 'seolity' ), $name ) );
			default:
				return array( 'SEOlity', __( 'Scroll below the content to the SEOlity box → “SEO title” and “Meta description”.', 'seolity' ), __( 'SEOlity box → “noindex” must be unchecked', 'seolity' ) );
		}
	}

	/** Title for a group of recommendations of one type. */
	public static function group_title( $type, $n ) {
		$t = array(
			/* translators: %d: number of pages */
			'ctr_opportunity'     => _n( 'Raise the click-through rate of %d page', 'Raise the click-through rate of %d pages', $n, 'seolity' ),
			/* translators: %d: number of pages */
			'striking_distance'   => _n( 'Push %d page from page 2 to page 1 of Google', 'Push %d pages from page 2 to page 1 of Google', $n, 'seolity' ),
			/* translators: %d: number of pages */
			'declining_page'      => _n( 'Recover %d page that is losing traffic', 'Recover %d pages that are losing traffic', $n, 'seolity' ),
			/* translators: %d: number of queries */
			'cannibalization'     => _n( 'Resolve keyword cannibalization for %d query', 'Resolve keyword cannibalization for %d queries', $n, 'seolity' ),
			/* translators: %d: number of pages */
			'faq_opportunity'     => _n( 'Add an FAQ section to %d page', 'Add FAQ sections to %d pages', $n, 'seolity' ),
			/* translators: %d: number of pages */
			'remove_noindex'      => _n( 'Remove noindex from %d page that gets traffic', 'Remove noindex from %d pages that get traffic', $n, 'seolity' ),
			/* translators: %d: number of URLs */
			'create_redirect'     => _n( 'Redirect %d broken URL', 'Redirect %d broken URLs', $n, 'seolity' ),
			/* translators: %d: number of pages */
			'orphan_page'         => _n( 'Link to %d orphan page', 'Link to %d orphan pages', $n, 'seolity' ),
			/* translators: %d: number of links */
			'internal_link'       => _n( 'Add %d internal link', 'Add %d internal links', $n, 'seolity' ),
			/* translators: %d: number of products */
			'optimize_product'    => _n( 'Optimize %d product page', 'Optimize %d product pages', $n, 'seolity' ),
			/* translators: %d: number of categories */
			'strengthen_category' => _n( 'Strengthen %d product category', 'Strengthen %d product categories', $n, 'seolity' ),
			/* translators: %d: number of pages */
			'thin_expand'         => _n( 'Expand %d thin page that has traffic', 'Expand %d thin pages that have traffic', $n, 'seolity' ),
			/* translators: %d: number of pages */
			'thin_noindex'        => _n( 'Merge, improve or noindex %d thin page without traffic', 'Merge, improve or noindex %d thin pages without traffic', $n, 'seolity' ),
			/* translators: %d: number of topics */
			'content_gap'         => _n( 'Cover %d topic your competitors rank for', 'Cover %d topics your competitors rank for', $n, 'seolity' ),
		);
		/* translators: %d: number of items */
		return isset( $t[ $type ] ) ? sprintf( $t[ $type ], $n ) : sprintf( __( '%d related improvements', 'seolity' ), $n );
	}

	public static function group_why( $type, $first_reason ) {
		$w = array(
			'ctr_opportunity'   => __( 'Google already shows these pages, but far fewer people click than usual for their position. Better titles and descriptions turn existing impressions into visits — the quickest traffic win.', 'seolity' ),
			'striking_distance' => __( 'These pages rank just below the first page for real queries. A focused improvement of the content and internal links usually moves them onto page 1, where almost all clicks happen.', 'seolity' ),
			'declining_page'    => __( 'These pages earned clearly fewer clicks than in the previous period. Find out why (competitors, outdated content, technical problems) before the drop grows.', 'seolity' ),
			'cannibalization'   => __( 'Several of your pages compete for the same queries, so Google splits rankings between them and none of them wins.', 'seolity' ),
			'faq_opportunity'   => __( 'Searchers ask questions these pages do not answer yet. An FAQ section covers them and can win extra visibility.', 'seolity' ),
			'remove_noindex'    => __( 'These pages are excluded from Google by noindex although people find them — they will drop out of search.', 'seolity' ),
			'create_redirect'   => __( 'These URLs return 404 but still receive links or visits. A 301 redirect keeps visitors and link value.', 'seolity' ),
			'orphan_page'       => __( 'No internal link points to these pages, so Google finds them late and treats them as unimportant.', 'seolity' ),
			'internal_link'     => __( 'Relevant internal links pass authority and context to important pages.', 'seolity' ),
			'optimize_product'  => __( 'These product pages miss important SEO elements (title, descriptions, keywords, image ALT).', 'seolity' ),
			'content_gap'       => __( 'Your competitors rank with content on these topics and you have no page for them.', 'seolity' ),
			'thin_expand'       => __( 'These pages get impressions but have little content, so they cannot compete for their queries. Expanding them with genuinely useful information usually lifts rankings.', 'seolity' ),
			'thin_noindex'      => __( 'These pages have little content and no traffic. They dilute the site’s quality: improve them, merge them into a stronger page (with a redirect) or set them to noindex.', 'seolity' ),
		);
		return isset( $w[ $type ] ) ? $w[ $type ] : (string) $first_reason;
	}

	/**
	 * Steps for an item type.
	 *
	 * @param string $type  Item type (recommendation type, doctor code or index_new).
	 * @param array  $item  The item (route, bulk…).
	 */
	public static function steps( $type, array $item = array() ) {
		list( $engine, $meta_where, $robots_where ) = self::seo_box();
		$edit = __( 'Click “Edit” next to the page in the list above (or Posts / Pages / Products → All → the item → Edit).', 'seolity' );
		switch ( $type ) {
			case 'index_new':
				return array(
					self::step( __( 'Make sure the page may be indexed', 'seolity' ), $edit . ' ' . __( 'The status must be “Published” (not private, not password-protected).', 'seolity' ) . ' ' . sprintf( /* translators: %s: where robots meta is */ __( 'Check: %s.', 'seolity' ), $robots_where ), __( 'Posts → All Posts → Edit → Publish box', 'seolity' ) ),
					self::step( __( 'Link to it from 2–3 related pages', 'seolity' ), __( 'Google discovers new pages through links. Open SEOlity → Keywords & links → Internal Links, find the new page and apply the suggested links, or edit related, already-ranking posts and link to the new page with a descriptive anchor text.', 'seolity' ), __( 'SEOLITY → Keywords & links → Internal Links', 'seolity' ), self::app( 'links' ) ),
					self::step( __( 'Check it is in the sitemap', 'seolity' ), __( 'Open SEOlity → Technical SEO → Sitemap and confirm the page is listed (published posts are added automatically; noindex pages are not).', 'seolity' ), __( 'SEOLITY → Technical SEO → Sitemap', 'seolity' ), self::app( 'sitemap' ) ),
					self::step( __( 'Request indexing in Google Search Console', 'seolity' ), __( 'Click “Inspect in Search Console” next to the page above → wait for the check → “Request indexing”. Do this once per page; repeating it does not help.', 'seolity' ), __( 'Search Console → URL inspection → Request indexing', 'seolity' ), 'https://search.google.com/search-console', 'inspect_targets' ),
					self::step( __( 'Verify in 3–7 days', 'seolity' ), __( 'Run “Analyze” again in a week: the page leaves this list as soon as Search Console records impressions for it. If it is still missing after two weeks, improve its content and internal links.', 'seolity' ) ),
				);
			case 'ctr_opportunity':
			case 'low_ctr':
			case 'bulk_meta':
			case 'title_missing':
			case 'title_too_long':
			case 'title_too_short':
			case 'duplicate_title':
			case 'meta_desc_missing':
			case 'meta_desc_length':
			case 'duplicate_meta':
				return array(
					self::step( __( 'See the queries people use', 'seolity' ), __( 'Open the page in SEOlity → Search Console → Pages (or the “GSC keywords” button in the editor) to see the real queries, impressions and CTR.', 'seolity' ), __( 'SEOLITY → Search Console → Pages', 'seolity' ), self::app( 'gsc' ) ),
					self::step( __( 'Let SEOlity write the title and description', 'seolity' ), __( 'Click “AI title & description” next to each page above. SEOlity writes options from the real queries; pick one, edit if you like and apply (it goes into your SEO plugin and can be undone).', 'seolity' ), __( 'Roadmap → this task → page → AI title & description', 'seolity' ), '', 'meta_targets' ),
					self::step( sprintf( /* translators: %s: SEO plugin */ __( 'Or edit it yourself in %s', 'seolity' ), $engine ), $edit . ' ' . $meta_where . ' ' . __( 'Title: up to ~60 characters, main query near the start, a clear benefit. Description: 140–155 characters, answers the intent, ends with a reason to click. Then “Update”.', 'seolity' ), __( 'Edit page → SEO box → title & description', 'seolity' ) ),
					self::step( __( 'Measure', 'seolity' ), __( 'SEOlity records every change as an experiment and compares CTR after 14–28 days (AI Center → AI Changes / Experiments). Leave the new title in place at least two weeks.', 'seolity' ), __( 'SEOLITY → Analysis → Experiments', 'seolity' ), self::app( 'experiments' ) ),
				);
			case 'striking_distance':
			case 'declining_page':
			case 'thin_content':
			case 'thin_expand':
			case 'thin_noindex':
			case 'near_duplicate':
				$first = 'declining_page' === $type
					? self::step( __( 'Find the cause of the drop', 'seolity' ), __( 'Open the page in SEOlity → Search Console and compare both periods: did impressions fall (Google shows it less: rankings or demand) or only CTR (snippet)? Check the page still returns 200 and is indexable (Site Audit), and whether a competitor published something better (Analysis → Competitors).', 'seolity' ), __( 'SEOLITY → Search Console → Pages', 'seolity' ), self::app( 'gsc' ) )
					: self::step( __( 'Look at the queries it almost ranks for', 'seolity' ), __( 'Open the page queries (positions 8–20) in SEOlity → Search Console or the “GSC keywords” button in the editor. Note which queries are missing from the headings and text.', 'seolity' ), __( 'SEOLITY → Search Console → Pages', 'seolity' ), self::app( 'gsc' ) );
				return array(
					$first,
					self::step( __( 'Improve the content with SEOlity AI', 'seolity' ), $edit . ' ' . __( 'In the editor click “Optimize with SEOlity AI” (above the classic editor, in the Gutenberg sidebar, or in the Elementor panel for a text widget). It uses the real queries, the SEO knowledge base and competitor pages; a small panel shows the progress and the report — review and apply.', 'seolity' ), __( 'Edit page → Optimize with SEOlity AI', 'seolity' ), '', 'ai_targets' ),
					self::step( __( 'Cover the missing intent', 'seolity' ), __( 'Add a section (H2) for each important missing query, answer questions directly in the first sentence under it, add examples, numbers and an updated date. Do not stuff keywords.', 'seolity' ) ),
					self::step( __( 'Add internal links to it', 'seolity' ), __( 'Link to the page from 2–3 strong, related pages with descriptive anchor text (SEOlity → Internal Links shows suggestions).', 'seolity' ), __( 'SEOLITY → Keywords & links → Internal Links', 'seolity' ), self::app( 'links' ) ),
					self::step( __( 'Update and request re-crawl', 'seolity' ), __( 'Click “Update”, then request indexing for the URL in Search Console (URL inspection). Re-check in 2–4 weeks.', 'seolity' ), __( 'Search Console → URL inspection', 'seolity' ), 'https://search.google.com/search-console' ),
				);
			case 'cannibalization':
				return array(
					self::step( __( 'See which pages compete', 'seolity' ), __( 'Open SEOlity → Keywords & links → Keywords → Cannibalization. For each query you see the competing URLs with clicks and positions; “AI advice” recommends merge, redirect, differentiate or re-target.', 'seolity' ), __( 'SEOLITY → Keywords → Cannibalization', 'seolity' ), self::app( 'keywords?tab=cannibalization' ) ),
					self::step( __( 'Pick one main page per query', 'seolity' ), __( 'Usually the page with the most clicks and best fit for the intent. The others must stop targeting that query.', 'seolity' ) ),
					self::step( __( 'Merge, redirect or differentiate', 'seolity' ), __( 'If the weaker page adds nothing: move its useful parts into the main page and create a 301 redirect (SEOlity → Technical SEO → Redirects). If both are useful: change the weaker page’s focus keyword, title and headings to a different intent.', 'seolity' ), __( 'SEOLITY → Technical SEO → Redirects', 'seolity' ), self::app( 'redirects' ) ),
					self::step( __( 'Point internal links to the main page', 'seolity' ), __( 'Links with that query as anchor text should go to the main page only.', 'seolity' ) ),
				);
			case 'faq_opportunity':
				return array(
					self::step( __( 'Generate the FAQ', 'seolity' ), __( 'Click “Do it with AI” next to each page: SEOlity writes questions from the real question queries and short factual answers; review them before applying.', 'seolity' ), '', '', 'ai_targets' ),
					self::step( __( 'Place it on the page', 'seolity' ), $edit . ' ' . __( 'Add an “FAQ” heading near the end and the questions as H3 with short answers (or the FAQ block of your SEO plugin). SEOlity adds FAQPage schema automatically when enabled.', 'seolity' ), __( 'Edit page → add FAQ section', 'seolity' ) ),
					self::step( __( 'Update and verify', 'seolity' ), __( 'Update the page and check the result with Google’s Rich Results Test.', 'seolity' ), '', 'https://search.google.com/test/rich-results' ),
				);
			case 'remove_noindex':
			case 'noindex_with_traffic':
				return array(
					self::step( __( 'Open the page', 'seolity' ), $edit ),
					self::step( __( 'Allow indexing', 'seolity' ), sprintf( /* translators: %s: where robots meta is */ __( 'Go to %s, set it to index, then “Update”. Or click “Do it with AI” above to let SEOlity change it (with undo).', 'seolity' ), $robots_where ), $robots_where, '', 'ai_targets' ),
					self::step( __( 'Check global settings', 'seolity' ), __( 'Make sure Settings → Reading → “Discourage search engines” is NOT checked, and that the post type / category is not set to noindex in your SEO plugin.', 'seolity' ), __( 'Settings → Reading', 'seolity' ), admin_url( 'options-reading.php' ) ),
					self::step( __( 'Request indexing', 'seolity' ), __( 'Search Console → URL inspection → Request indexing.', 'seolity' ), '', 'https://search.google.com/search-console', 'inspect_targets' ),
				);
			case 'create_redirect':
			case 'http_4xx':
			case 'internal_404':
			case 'broken_internal_link':
				return array(
					self::step( __( 'Review the broken URLs', 'seolity' ), __( 'Open SEOlity → Technical SEO → 404 Monitor: each URL shows hits, referrers and the best matching live page.', 'seolity' ), __( 'SEOLITY → Technical SEO → 404 Monitor', 'seolity' ), self::app( '404' ) ),
					self::step( __( 'Create 301 redirects', 'seolity' ), __( 'Click “Do it with AI” next to each URL above (SEOlity proposes the best target), or add them in SEOlity → Technical SEO → Redirects: From = broken path, To = closest live page, type 301.', 'seolity' ), __( 'SEOLITY → Technical SEO → Redirects → Add', 'seolity' ), self::app( 'redirects' ), 'ai_targets' ),
					self::step( __( 'Fix the links at the source', 'seolity' ), __( 'Edit the pages that still link to the broken URL (Site Audit → Broken internal links lists them) and point the link to the live page.', 'seolity' ), __( 'SEOLITY → Analysis → Site Audit', 'seolity' ), self::app( 'audit?check=broken_internal_link' ) ),
				);
			case 'fix_redirect_chains':
			case 'redirect_loops':
			case 'redirect_chain':
			case 'redirect_loop':
				return array(
					self::step( __( 'Open the redirects', 'seolity' ), __( 'SEOlity → Technical SEO → Redirects shows chains (A→B→C) and loops.', 'seolity' ), __( 'SEOLITY → Technical SEO → Redirects', 'seolity' ), self::app( 'redirects' ) ),
					self::step( __( 'Fix them in one click', 'seolity' ), __( 'Click “Do it with AI” here or “Fix chains” on the Redirects page: every redirect then points straight to the final URL; loops are shown for a manual decision.', 'seolity' ), '', '', 'ai_targets' ),
				);
			case 'orphan_page':
			case 'internal_link':
				return array(
					self::step( __( 'Open internal link suggestions', 'seolity' ), __( 'SEOlity → Keywords & links → Internal Links lists links whose anchor text already exists in your content.', 'seolity' ), __( 'SEOLITY → Keywords & links → Internal Links', 'seolity' ), self::app( 'links' ) ),
					self::step( __( 'Apply the links', 'seolity' ), __( 'Click “Do it with AI” next to each page above to see suggestions and preview them, then apply. Prefer links from related pages that already get traffic.', 'seolity' ), '', '', 'ai_targets' ),
					self::step( __( 'Or add them by hand', 'seolity' ), __( 'Edit a related post, select a phrase that describes the target page, press Ctrl+K (or the link button) and choose the page. Update.', 'seolity' ), __( 'Posts → Edit → select text → Link', 'seolity' ) ),
				);
			case 'optimize_product':
			case 'product_no_short_desc':
			case 'product_schema_missing':
				return array(
					self::step( __( 'Open the product', 'seolity' ), __( 'Products → All Products → the product → Edit (or “Edit” in the list above).', 'seolity' ), __( 'Products → All Products → Edit', 'seolity' ), admin_url( 'edit.php?post_type=product' ) ),
					self::step( __( 'Run the AI Product Optimizer', 'seolity' ), __( 'Click “AI Product Optimizer” in the SEOlity box (or “Do it with AI” here). It writes the SEO title, meta description, short description, focus keywords and image ALT from the real product data. A small panel shows the progress; open its report and apply field by field.', 'seolity' ), __( 'Edit product → SEOlity box → AI Product Optimizer', 'seolity' ), '', 'ai_targets' ),
					self::step( __( 'Check price, stock and images', 'seolity' ), __( 'Product schema needs price, availability and an image; fill them in the Product data box. Then “Update”.', 'seolity' ), __( 'Edit product → Product data', 'seolity' ) ),
				);
			case 'strengthen_category':
				return array(
					self::step( __( 'Open the category', 'seolity' ), __( 'Products → Categories → hover the category → Edit.', 'seolity' ), __( 'Products → Categories → Edit', 'seolity' ), admin_url( 'edit-tags.php?taxonomy=product_cat&post_type=product' ) ),
					self::step( __( 'Write a helpful description', 'seolity' ), __( 'Click “Optimize with SEOlity AI” under the description: it writes an intro that answers buyer questions and links to key products. Review and Update.', 'seolity' ), '', '', 'ai_targets' ),
				);
			case 'content_gap':
				return array(
					self::step( __( 'Review the gaps', 'seolity' ), __( 'SEOlity → Analysis → Competitors → the competitor → Gaps: topics they cover and you do not.', 'seolity' ), __( 'SEOLITY → Analysis → Competitors', 'seolity' ), self::app( 'competitors' ) ),
					self::step( __( 'Plan the article', 'seolity' ), __( 'Click “Do it with AI” to add the topic to the content planner with an AI brief (headings, questions, entities, length).', 'seolity' ), '', '', 'ai_targets' ),
					self::step( __( 'Write and publish', 'seolity' ), __( 'Posts → Add New, follow the brief, add internal links to and from related pages, publish. It then appears under “Get new pages indexed” until Google shows it.', 'seolity' ), __( 'Posts → Add New', 'seolity' ), admin_url( 'post-new.php' ) ),
				);
			case 'img_alt_missing':
			case 'media_alt_missing':
				return array(
					self::step( __( 'Bulk-write ALT text', 'seolity' ), __( 'SEOlity → Content → Images → filter “Missing ALT” → select all → “Generate ALT with AI”. Or in Media → Library (list view) select images → Bulk actions → “Write ALT text with SEOlity AI”.', 'seolity' ), __( 'SEOLITY → Content → Images', 'seolity' ), self::app( 'images' ) ),
					self::step( __( 'Single image', 'seolity' ), __( 'Media → Library → click the image → “Write ALT, title, caption & description with AI” in the details panel.', 'seolity' ), __( 'Media → Library → image details', 'seolity' ), admin_url( 'upload.php' ) ),
				);
			case 'schema_missing':
				return array(
					self::step( __( 'Open Schema', 'seolity' ), __( 'SEOlity → Technical SEO → Schema shows which pages lack structured data and suggests the right type (Article, Product, FAQ, Organization…).', 'seolity' ), __( 'SEOLITY → Technical SEO → Schema', 'seolity' ), self::app( 'schema' ) ),
					self::step( __( 'Validate', 'seolity' ), __( 'Test a page with Google’s Rich Results Test after enabling it.', 'seolity' ), '', 'https://search.google.com/test/rich-results' ),
				);
			case 'cwv_poor':
				return array(
					self::step( __( 'See the slow pages', 'seolity' ), __( 'SEOlity → Analysis → Site Audit → Performance shows Core Web Vitals per page (PageSpeed).', 'seolity' ), __( 'SEOLITY → Analysis → Site Audit', 'seolity' ), self::app( 'audit?category=performance' ) ),
					self::step( __( 'Fix the usual causes', 'seolity' ), __( 'Compress and resize large images (WebP), enable a caching plugin, lazy-load images below the fold, remove unused plugins/scripts, and use a faster host or a CDN.', 'seolity' ) ),
				);
			case 'sitemap_error_urls':
			case 'robots_no_sitemap':
				return array(
					self::step( __( 'Open the sitemap check', 'seolity' ), __( 'SEOlity → Technical SEO → Sitemap lists URLs in the sitemap that redirect, return errors or are noindex.', 'seolity' ), __( 'SEOLITY → Technical SEO → Sitemap', 'seolity' ), self::app( 'sitemap' ) ),
					self::step( __( 'Fix robots.txt', 'seolity' ), __( 'SEOlity → Technical SEO → Robots.txt: add the “Sitemap:” line and make sure nothing important is disallowed.', 'seolity' ), __( 'SEOLITY → Technical SEO → Robots.txt', 'seolity' ), self::app( 'robots' ) ),
					self::step( __( 'Submit in Search Console', 'seolity' ), __( 'Search Console → Sitemaps → enter the sitemap URL → Submit.', 'seolity' ), '', 'https://search.google.com/search-console/sitemaps' ),
				);
		}
		// Generic: the route SEOlity knows for this issue.
		$route = isset( $item['route'] ) && $item['route'] ? $item['route'] : 'audit?check=' . $type;
		return array(
			self::step( __( 'Open the affected pages', 'seolity' ), __( 'The list above shows the affected pages; SEOlity’s detail screen for this issue explains each one.', 'seolity' ), __( 'SEOLITY → Analysis → Site Audit', 'seolity' ), self::app( $route ) ),
			self::step( __( 'Apply the fix', 'seolity' ), __( 'Follow the fix described above for each page; where “Do it with AI” is shown, SEOlity prepares the change for you to review.', 'seolity' ), '', '', 'ai_targets' ),
			self::step( __( 'Verify', 'seolity' ), __( 'Run a new site audit (Analysis → Site Audit → Start) and press “Analyze” here again: solved tasks disappear from the roadmap.', 'seolity' ), __( 'SEOLITY → Analysis → Site Audit', 'seolity' ), self::app( 'audit' ) ),
		);
	}

	/** Plain-language result that tells the owner the task is finished. */
	public static function done_when( $type ) {
		$d = array(
			'index_new'         => __( 'Search Console shows impressions for every listed page.', 'seolity' ),
			'ctr_opportunity'   => __( 'New titles/descriptions are live; CTR is measured after 14–28 days.', 'seolity' ),
			'striking_distance' => __( 'The pages reach positions 1–10 for their main queries.', 'seolity' ),
			'remove_noindex'    => __( 'The pages are indexable and indexed again.', 'seolity' ),
			'create_redirect'   => __( 'Every broken URL redirects (301) to a relevant live page.', 'seolity' ),
		);
		return isset( $d[ $type ] ) ? $d[ $type ] : __( 'The issue no longer appears after the next audit and analysis.', 'seolity' );
	}
}
