<?php
/**
 * Versioned task catalog for the AI SEO Engine.
 *
 * Each task = instructions + strict output schema + version. The version is
 * stored on every change/experiment ("Prompt Version") so results can be
 * attributed to the exact prompt that produced them. Extensions can add tasks
 * through the `seolity_ai_tasks` filter.
 *
 * @package SEOlity
 */

namespace SEOlity\AI;

use SEOlity\AI\Schema as S;

defined( 'ABSPATH' ) || exit;

final class Tasks {

	/** @var array|null */
	private static $tasks = null;

	public static function get( $name ) {
		$all = self::all();
		return isset( $all[ $name ] ) ? $all[ $name ] : null;
	}

	public static function all() {
		if ( null === self::$tasks ) {
			self::$tasks = apply_filters( 'seolity_ai_tasks', self::define() );
		}
		return self::$tasks;
	}

	private static function keyword_list() {
		return S::arr(
			S::obj(
				array(
					'keyword'     => S::str(),
					'intent'      => S::enum( array( 'informational', 'commercial', 'transactional', 'navigational' ) ),
					'type'        => S::enum( array( 'head', 'long_tail', 'question', 'semantic', 'entity' ) ),
					'difficulty'  => S::int( 'Estimated difficulty 0-100 (state it is an estimate).' ),
					'opportunity' => S::int( 'Opportunity score 0-100 for THIS site.' ),
					'rationale'   => S::str(),
				)
			)
		);
	}

	private static function faq_list() {
		return S::arr(
			S::obj(
				array(
					'question' => S::str(),
					'answer'   => S::str( 'Concise, factual answer (40-80 words). No fabricated facts.' ),
				)
			)
		);
	}

	private static function changes_list() {
		return S::arr(
			S::obj(
				array(
					'area'        => S::str( 'e.g. intro, headings, keyword usage, structure, CTA, facts.' ),
					'description' => S::str(),
					'reason'      => S::str(),
				)
			)
		);
	}

	private static function define() {
		return array(
			'optimize_content'   => array(
				'version'      => 'optimize_content@1.3',
				'max_tokens'   => 12000,
				'topics'       => array( 'content', 'on-page', 'eeat', 'semantic' ),
				'cache'        => false,
				'instructions' => 'Rewrite and optimize the provided content for the given field and object type. Preserve every factual claim, product spec, price, brand name, link (href) and shortcode exactly; never invent facts, statistics, reviews, certifications or guarantees. Improve: search-intent match, clarity, structure (H2/H3, lists, tables when useful), topical completeness (semantic terms, entities, sub-questions), natural keyword placement (title-like opening, intro, one subheading) and a helpful CTA when appropriate. Keep HTML clean (p, h2, h3, ul, ol, li, strong, em, table, a). Do not output H1 inside body content. Keep length appropriate for the field (short descriptions stay short). Write in the content language.',
				'schema'       => S::obj(
					array(
						'optimized_content'  => S::str( 'Optimized HTML for the field.' ),
						'summary'            => S::str( 'One-paragraph summary of what changed and why.' ),
						'changes'            => self::changes_list(),
						'focus_keyword'      => S::str(),
						'secondary_keywords' => S::strings(),
						'semantic_terms'     => S::strings( 'Related terms/subtopics a complete answer covers.' ),
						'entities'           => S::strings( 'Named entities (brands, standards, places, people, concepts).' ),
						'search_intent'      => S::enum( array( 'informational', 'commercial', 'transactional', 'navigational' ) ),
						'suggested_title'    => S::str( 'SEO title ≤ 60 chars.' ),
						'suggested_meta_description' => S::str( 'Meta description 120-158 chars.' ),
						'information_gain'   => S::strings( 'Unique, helpful additions beyond typical competitor content.' ),
						'warnings'           => S::strings( 'Anything the editor must verify (facts, claims).' ),
					)
				),
			),
			'meta_tags'          => array(
				'version'      => 'meta_tags@1.2',
				'max_tokens'   => 3000,
				'topics'       => array( 'meta', 'ctr' ),
				'cache'        => true,
				'instructions' => 'Write SEO titles and meta descriptions that maximize qualified CTR for the real search queries provided. Title ≤ 60 chars (≈580px), primary query near the start, a concrete benefit or qualifier, brand at end only if space. Meta description 120-158 chars, answers the intent, includes a specific value proposition and a soft CTA. No clickbait, no ALL CAPS, no false promises, no keyword lists. Give 3 options each and pick the best.',
				'schema'       => S::obj(
					array(
						'title_options'       => S::arr( S::obj( array( 'title' => S::str(), 'rationale' => S::str() ) ) ),
						'description_options' => S::arr( S::obj( array( 'description' => S::str(), 'rationale' => S::str() ) ) ),
						'recommended_title'   => S::str(),
						'recommended_description' => S::str(),
						'expected_impact'     => S::str(),
					)
				),
			),
			'product_seo'        => array(
				'version'      => 'product_seo@1.4',
				'max_tokens'   => 16000,
				'topics'       => array( 'ecommerce', 'product', 'schema', 'images' ),
				'cache'        => false,
				'instructions' => 'You are a senior e-commerce SEO and conversion copywriter. Analyze the WooCommerce product data (title, descriptions, attributes, categories, price, stock, images, reviews, GSC queries, similar products, competitor snippets if any). Produce an optimized product package. Use ONLY facts present in the data; when a useful fact is missing (dimensions, material, warranty…) list it in missing_attributes instead of inventing it. The description must be genuinely helpful: who it is for, key benefits tied to features, specs table if attributes exist, usage/care, FAQs. Avoid keyword stuffing, avoid duplicate manufacturer copy, write in the store language.',
				'schema'       => S::obj(
					array(
						'title'                => S::str( 'Optimized product title (keep brand/model).' ),
						'short_description'    => S::str( 'HTML, 2-4 bullet benefits or 1-2 sentences.' ),
						'description'          => S::str( 'Full optimized HTML description.' ),
						'meta_title'           => S::str(),
						'meta_description'     => S::str(),
						'focus_keyword'        => S::str(),
						'secondary_keywords'   => S::strings(),
						'long_tail_keywords'   => S::strings(),
						'semantic_keywords'    => S::strings(),
						'search_intent'        => S::enum( array( 'informational', 'commercial', 'transactional', 'navigational' ) ),
						'benefits'             => S::strings(),
						'feature_analysis'     => S::arr( S::obj( array( 'feature' => S::str(), 'benefit' => S::str() ) ) ),
						'content_weaknesses'   => S::strings(),
						'faq'                  => self::faq_list(),
						'cta_suggestions'      => S::strings(),
						'missing_attributes'   => S::strings(),
						'suggested_categories' => S::strings(),
						'suggested_tags'       => S::strings(),
						'image_alts'           => S::arr( S::obj( array( 'image_id' => S::int(), 'alt' => S::str(), 'caption' => S::str() ) ) ),
						'internal_links'       => S::arr( S::obj( array( 'anchor' => S::str(), 'target_url' => S::str(), 'reason' => S::str() ) ) ),
						'schema_issues'        => S::strings(),
						'competitor_gaps'      => S::strings(),
						'summary'              => S::str(),
					)
				),
			),
			'image_alt'          => array(
				'version'      => 'image_alt@1.2',
				'max_tokens'   => 4000,
				'topics'       => array( 'images', 'accessibility' ),
				'cache'        => true,
				'instructions' => 'Write ALT text for each image based on the page topic, title, product/category, nearby text and filename. ALT must describe what the image shows for a screen-reader user (functional + descriptive), 5-15 words, include the topic keyword only when it truthfully describes the image, never start with "image of"/"picture of", no keyword lists, no duplicates across images. Also suggest a short title and an optional caption.',
				'schema'       => S::obj(
					array(
						'items' => S::arr(
							S::obj(
								array(
									'id'      => S::int(),
									'alt'     => S::str(),
									'title'   => S::str(),
									'caption' => S::str(),
									'reason'  => S::str(),
								)
							)
						),
					)
				),
			),
			'media_seo'          => array(
				'version'      => 'media_seo@1.0',
				'max_tokens'   => 2000,
				'topics'       => array( 'images', 'accessibility', 'on-page' ),
				'cache'        => false,
				'instructions' => 'Write SEO metadata for ONE uploaded media file, using the page it belongs to, its focus keyword, nearby text, product data, the filename, the uploader hint and the market evidence. alt: what the image shows for a screen-reader user, 5-15 words, the focus keyword only if it truthfully describes the image, never "image of". title: short, human, 3-8 words. caption: one helpful sentence a reader would see under the image (may be empty for decorative images). description: 1-3 sentences for the attachment page that add context (who/what/where, product or topic) and can rank in image search — no keyword lists. filename: a short lowercase latin slug (a-z, 0-9, hyphens) describing the image, without extension. Never invent facts (brands, places, people) that are not supported by the context.',
				'schema'       => S::obj(
					array(
						'alt'         => S::str(),
						'title'       => S::str(),
						'caption'     => S::str(),
						'description' => S::str(),
						'filename'    => S::str(),
						'reason'      => S::str(),
					)
				),
			),
			'keyword_research'   => array(
				'version'      => 'keyword_research@1.2',
				'max_tokens'   => 8000,
				'topics'       => array( 'keywords', 'intent', 'semantic' ),
				'cache'        => true,
				'instructions' => 'Expand the seed keywords into a keyword universe for THIS site (use the site context, existing pages and GSC queries provided). Include head terms, long-tail, questions, commercial and transactional modifiers, semantic and entity terms in the target language/market (Persian sites: include common colloquial/spelling variants, Finglish only if typical). Difficulty values are estimates — say so in rationale. Map each keyword to the best existing URL when one fits, otherwise propose a new URL slug. Group into clusters.',
				'schema'       => S::obj(
					array(
						'keywords' => self::keyword_list(),
						'questions' => S::strings(),
						'entities' => S::strings(),
						'mapping'  => S::arr( S::obj( array( 'keyword' => S::str(), 'url' => S::str( 'Existing URL or proposed slug.' ), 'is_new' => S::bool() ) ) ),
						'clusters' => S::arr( S::obj( array( 'name' => S::str(), 'keywords' => S::strings() ) ) ),
					)
				),
			),
			'search_intent'      => array(
				'version'      => 'search_intent@1.0',
				'max_tokens'   => 2000,
				'topics'       => array( 'intent' ),
				'cache'        => true,
				'instructions' => 'Classify the search intent for the query and describe what a page must contain to satisfy it.',
				'schema'       => S::obj(
					array(
						'intent'               => S::enum( array( 'informational', 'commercial', 'transactional', 'navigational' ) ),
						'confidence'           => S::int(),
						'serp_format'          => S::str( 'e.g. product listing, how-to guide, comparison, local pack.' ),
						'content_requirements' => S::strings(),
						'explanation'          => S::str(),
					)
				),
			),
			'ctr_rewrite'        => array(
				'version'      => 'ctr_rewrite@1.1',
				'max_tokens'   => 3000,
				'topics'       => array( 'ctr', 'meta' ),
				'cache'        => true,
				'instructions' => 'This URL has many impressions but CTR below the expected CTR for its average position. Using the real top queries (with impressions, CTR, position), current title and description, write a better title + meta description that match the dominant query intent and stand out in the SERP. Explain why CTR is low and give a realistic expected impact.',
				'schema'       => S::obj(
					array(
						'diagnosis'        => S::str(),
						'title'            => S::str(),
						'meta_description' => S::str(),
						'alternatives'     => S::arr( S::obj( array( 'title' => S::str(), 'meta_description' => S::str() ) ) ),
						'expected_impact'  => S::str(),
						'confidence'       => S::int(),
					)
				),
			),
			'content_gap'        => array(
				'version'      => 'content_gap@1.2',
				'max_tokens'   => 10000,
				'topics'       => array( 'competitors', 'content-strategy', 'topic-clusters' ),
				'cache'        => true,
				'instructions' => 'Compare competitor pages/topics with this site\'s pages and queries. Identify missing topics, missing pages and weak pages. For every opportunity give keyword, intent, suggested URL slug, title, H1, an outline, recommended word count (justify by competitor depth), and supporting keywords. Never suggest doorway pages or near-duplicate location/variant pages; each page must have unique user value.',
				'schema'       => S::obj(
					array(
						'summary'       => S::str(),
						'opportunities' => S::arr(
							S::obj(
								array(
									'type'                   => S::enum( array( 'missing_topic', 'missing_page', 'weak_page' ) ),
									'keyword'                => S::str(),
									'intent'                 => S::enum( array( 'informational', 'commercial', 'transactional', 'navigational' ) ),
									'suggested_url'          => S::str(),
									'suggested_title'        => S::str(),
									'suggested_h1'           => S::str(),
									'outline'                => S::strings(),
									'recommended_word_count' => S::int(),
									'supporting_keywords'    => S::strings(),
									'competitors'            => S::strings(),
									'priority'               => S::enum( array( 'critical', 'high', 'medium', 'low' ) ),
									'reason'                 => S::str(),
								)
							)
						),
					)
				),
			),
			'competitor_summary' => array(
				'version'      => 'competitor_summary@1.1',
				'max_tokens'   => 6000,
				'topics'       => array( 'competitors' ),
				'cache'        => true,
				'instructions' => 'Summarize the competitor\'s SEO strategy from the crawled data (page counts by section, titles, headings, content length, schema, FAQ use, internal links, new content since last crawl) versus this site. Be specific and evidence-based ("Competitor X has 34 guides in the /blog/coffee/ cluster vs your 6").',
				'schema'       => S::obj(
					array(
						'summary'       => S::str(),
						'strengths'     => S::arr( S::obj( array( 'area' => S::str(), 'insight' => S::str(), 'evidence' => S::str() ) ) ),
						'weaknesses'    => S::strings(),
						'new_content'   => S::strings(),
						'threats'       => S::strings(),
						'opportunities' => S::strings(),
						'actions'       => S::arr( S::obj( array( 'title' => S::str(), 'reason' => S::str(), 'priority' => S::enum( array( 'critical', 'high', 'medium', 'low' ) ) ) ) ),
					)
				),
			),
			'cannibalization'    => array(
				'version'      => 'cannibalization@1.0',
				'max_tokens'   => 3000,
				'topics'       => array( 'cannibalization', 'site-architecture' ),
				'cache'        => true,
				'instructions' => 'Several URLs of this site rank for the same query/intent. Using their GSC metrics, titles and content summaries decide the best action: merge, redirect, differentiate, internal_link or retarget. Pick a primary URL and give concrete steps.',
				'schema'       => S::obj(
					array(
						'action'      => S::enum( array( 'merge', 'redirect', 'differentiate', 'internal_link', 'retarget', 'none' ) ),
						'primary_url' => S::str(),
						'explanation' => S::str(),
						'steps'       => S::strings(),
						'confidence'  => S::int(),
					)
				),
			),
			'keyword_plan'       => array(
				'version'      => 'keyword_plan@1.0',
				'max_tokens'   => 4000,
				'topics'       => array( 'keywords', 'intent', 'on-page', 'cannibalization' ),
				'cache'        => true,
				'instructions' => 'Choose the focus keyword set for this page from its REAL Google Search Console queries (gsc_queries). Rules: the primary keyword must be a query this page owns (owner=true), with real demand, matching the page intent; never pick a query listed in owned_elsewhere (another URL of the site wins it — targeting it here causes cannibalization); secondary keywords (max 4) are distinct variants/sub-topics the page also owns, not near-duplicates; prefer the algorithm_pick unless you have a clear reason. Then give a title and meta description that contain the primary keyword naturally, content gaps for high-impression queries the content does not cover (coverage < 0.75) as concrete H2/H3 suggestions, FAQ questions from question queries, and anchors other pages should use to link here. Be specific to this page; no generic advice.',
				'schema'       => S::obj(
					array(
						'primary_keyword'    => S::str(),
						'secondary_keywords' => S::strings(),
						'search_intent'      => S::enum( array( 'informational', 'commercial', 'transactional', 'navigational' ) ),
						'rationale'          => S::str(),
						'seo_title'          => S::str(),
						'meta_description'   => S::str(),
						'content_gaps'       => S::arr(
							S::obj(
								array(
									'query'   => S::str(),
									'heading' => S::str(),
									'why'     => S::str(),
								)
							)
						),
						'faq_questions'      => S::strings(),
						'avoid_keywords'     => S::strings(),
						'link_anchors'       => S::strings(),
						'pillar'             => S::bool(),
						'confidence'         => S::int(),
					)
				),
			),
			'internal_links'     => array(
				'version'      => 'internal_links@1.1',
				'max_tokens'   => 5000,
				'topics'       => array( 'internal-linking' ),
				'cache'        => true,
				'instructions' => 'Choose the most useful internal links from the source page to candidate target pages. The anchor text must be a phrase that ALREADY EXISTS verbatim in the source text (so it can be linked without rewriting), descriptive, and varied. Max 5 suggestions; skip weak matches.',
				'schema'       => S::obj(
					array(
						'suggestions' => S::arr(
							S::obj(
								array(
									'target_id' => S::int(),
									'anchor'    => S::str(),
									'reason'    => S::str(),
									'relevance' => S::int(),
								)
							)
						),
					)
				),
			),
			'faq_generate'       => array(
				'version'      => 'faq_generate@1.0',
				'max_tokens'   => 4000,
				'topics'       => array( 'faq', 'schema' ),
				'cache'        => true,
				'instructions' => 'Generate FAQs real users ask about this page topic (use GSC question queries if provided). Answers must be supported by the page content; if the page lacks the info, say what should be added in the answer placeholder clearly marked.',
				'schema'       => S::obj( array( 'faqs' => self::faq_list() ) ),
			),
			'content_brief'      => array(
				'version'      => 'content_brief@1.1',
				'max_tokens'   => 6000,
				'topics'       => array( 'content-strategy', 'eeat', 'semantic' ),
				'cache'        => true,
				'instructions' => 'Create a complete content brief that satisfies the intent with information gain: title, H1, meta description, outline (H2/H3 with key points), recommended word count, supporting keywords, entities, internal links (from provided site pages), FAQs, and E-E-A-T notes (first-hand experience, expert quotes, sources to cite).',
				'schema'       => S::obj(
					array(
						'title'               => S::str(),
						'h1'                  => S::str(),
						'meta_description'    => S::str(),
						'outline'             => S::arr( S::obj( array( 'heading' => S::str(), 'level' => S::int(), 'points' => S::strings() ) ) ),
						'word_count'          => S::int(),
						'supporting_keywords' => S::strings(),
						'entities'            => S::strings(),
						'internal_links'      => S::arr( S::obj( array( 'anchor' => S::str(), 'url' => S::str() ) ) ),
						'faq'                 => S::strings(),
						'eeat_notes'          => S::strings(),
					)
				),
			),
			'content_plan'       => array(
				'version'      => 'content_plan@1.1',
				'max_tokens'   => 10000,
				'topics'       => array( 'content-strategy', 'topic-clusters' ),
				'cache'        => false,
				'instructions' => 'Build a content calendar from the opportunities, keyword gaps, clusters and seasonality provided. Each item must target a distinct intent (no cannibalization with existing URLs listed). Prioritize by business value and ranking feasibility.',
				'schema'       => S::obj(
					array(
						'items' => S::arr(
							S::obj(
								array(
									'day_offset'          => S::int( 'Days from start date.' ),
									'topic'               => S::str(),
									'keyword'             => S::str(),
									'intent'              => S::enum( array( 'informational', 'commercial', 'transactional', 'navigational' ) ),
									'title'               => S::str(),
									'h1'                  => S::str(),
									'outline'             => S::strings(),
									'supporting_keywords' => S::strings(),
									'internal_links'      => S::strings(),
									'competitors'         => S::strings(),
									'priority'            => S::enum( array( 'critical', 'high', 'medium', 'low' ) ),
									'reason'              => S::str(),
								)
							)
						),
					)
				),
			),
			'topic_clusters'     => array(
				'version'      => 'topic_clusters@1.0',
				'max_tokens'   => 8000,
				'topics'       => array( 'topic-clusters', 'site-architecture' ),
				'cache'        => true,
				'instructions' => 'Group the site pages (id, title, top query) into topic clusters. For each, choose the pillar page id (most comprehensive / commercial hub), member ids, and gaps: subtopics a complete cluster should cover that no page covers yet.',
				'schema'       => S::obj(
					array(
						'clusters' => S::arr(
							S::obj(
								array(
									'name'       => S::str(),
									'pillar_id'  => S::int(),
									'member_ids' => S::arr( S::int() ),
									'coverage'   => S::int( '0-100' ),
									'gaps'       => S::arr( S::obj( array( 'topic' => S::str(), 'keyword' => S::str(), 'intent' => S::str() ) ) ),
								)
							)
						),
					)
				),
			),
			'redirect_target'    => array(
				'version'      => 'redirect_target@1.0',
				'max_tokens'   => 1500,
				'topics'       => array( 'redirects' ),
				'cache'        => true,
				'instructions' => 'Pick the best redirect target for the broken URL among the candidates (closest equivalent content, else parent category). Return an empty target_url if none is relevant (a 410 or leaving 404 is better than an irrelevant redirect).',
				'schema'       => S::obj(
					array(
						'target_url' => S::str(),
						'type'       => S::enum( array( '301', '302', '410' ) ),
						'confidence' => S::int(),
						'reason'     => S::str(),
					)
				),
			),
			'experiment_verdict' => array(
				'version'      => 'experiment_verdict@1.0',
				'max_tokens'   => 1500,
				'topics'       => array( 'experiments' ),
				'cache'        => true,
				'instructions' => 'Judge an SEO experiment using before/after GSC windows (same length), site-wide trend over the same windows (control) and the change made. Account for seasonality and site-wide movement. Outcome positive/negative/neutral/inconclusive (low data = inconclusive).',
				'schema'       => S::obj(
					array(
						'outcome'    => S::enum( array( 'positive', 'negative', 'neutral', 'inconclusive' ) ),
						'confidence' => S::int(),
						'reason'     => S::str(),
						'learning'   => S::str( 'One generalizable learning for this site.' ),
					)
				),
			),
			'briefing'           => array(
				'version'      => 'briefing@1.1',
				'max_tokens'   => 4000,
				'topics'       => array( 'reporting' ),
				'cache'        => false,
				'instructions' => 'Write "Today\'s SEO Briefing" for the site owner from the provided signals (GSC deltas, winners/losers, new errors, alerts, competitor changes, experiments, top recommendations). Lead with what matters most, explain in plain language, and end with the 3-5 actions to take today.',
				'schema'       => S::obj(
					array(
						'headline'   => S::str(),
						'summary'    => S::str(),
						'highlights' => S::arr( S::obj( array( 'type' => S::enum( array( 'win', 'loss', 'risk', 'info' ) ), 'title' => S::str(), 'detail' => S::str() ) ) ),
						'actions'    => S::arr( S::obj( array( 'title' => S::str(), 'reason' => S::str(), 'priority' => S::enum( array( 'critical', 'high', 'medium', 'low' ) ) ) ) ),
					)
				),
			),
			'chat'               => array(
				'version'      => 'chat@1.2',
				'max_tokens'   => 6000,
				'topics'       => array(),
				'cache'        => false,
				'instructions' => 'You are SEOlity, the site\'s in-house AI SEO strategist. Answer using ONLY the site data provided in context (GSC, audit issues, recommendations, experiments, competitors, content) plus SEO best practice. Cite the data you used (URLs, numbers, dates). If data is missing, say what is missing and how to get it (e.g. connect GSC). Be concrete and prioritized. Answer in the user\'s language, markdown allowed.',
				'schema'       => S::obj(
					array(
						'answer'            => S::str( 'Markdown answer.' ),
						'data_used'         => S::strings(),
						'suggested_actions' => S::arr( S::obj( array( 'title' => S::str(), 'route' => S::str( 'Admin route e.g. #/products or #/redirects' ) ) ) ),
					)
				),
			),
			'eeat_analysis'      => array(
				'version'      => 'eeat_analysis@1.0',
				'max_tokens'   => 3000,
				'topics'       => array( 'eeat' ),
				'cache'        => true,
				'instructions' => 'Assess Experience, Expertise, Authoritativeness and Trust signals on the page (author info, first-hand evidence, sources, reviews, policies, contact info, about page, dates, credentials).',
				'schema'       => S::obj(
					array(
						'score'           => S::int(),
						'signals_present' => S::strings(),
						'missing_signals' => S::strings(),
						'recommendations' => S::strings(),
					)
				),
			),
			'schema_suggest'     => array(
				'version'      => 'schema_suggest@1.0',
				'max_tokens'   => 4000,
				'topics'       => array( 'schema' ),
				'cache'        => true,
				'instructions' => 'Given page data and existing JSON-LD, list schema issues and the schema.org types that are eligible and truthful for this page. Only use properties supported by visible page content (Google structured-data policies).',
				'schema'       => S::obj(
					array(
						'eligible_types' => S::strings(),
						'issues'         => S::strings(),
						'opportunities'  => S::strings(),
					)
				),
			),
			'serp_analysis'      => array(
				'version'      => 'serp_analysis@1.0',
				'max_tokens'   => 5000,
				'topics'       => array( 'serp', 'intent' ),
				'cache'        => true,
				'instructions' => 'Analyze the SERP data provided (top results titles/snippets/features, PAA) for the keyword. Infer dominant intent and format, common subtopics, title patterns, and what it takes to compete.',
				'schema'       => S::obj(
					array(
						'intent'          => S::enum( array( 'informational', 'commercial', 'transactional', 'navigational' ) ),
						'features'        => S::strings(),
						'content_format'  => S::str(),
						'title_patterns'  => S::strings(),
						'common_topics'   => S::strings(),
						'questions'       => S::strings(),
						'recommendations' => S::strings(),
					)
				),
			),
		);
	}
}
