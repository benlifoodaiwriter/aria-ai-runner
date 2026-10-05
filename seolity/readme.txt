=== SEOlity – AI SEO Intelligence for WordPress ===
Contributors: seolity
Tags: seo, ai, search console, woocommerce, schema
Requires at least: 6.2
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

An AI-powered SEO operating system: site audits, Google Search Console analytics, AI content and product optimization with preview/approval/undo, experiments, redirects, schema and more. Persian (RTL) and English.

== Description ==

SEOlity measures, decides, acts safely and learns:

* **Measure** – a real crawler with ~60 checks, Google Search Console analytics (page × query × day), content analysis, competitor monitoring, PageSpeed data.
* **Decide** – SEO Doctor diagnoses, a prioritized AI Action Center ("What should I do today?") with impact, effort, confidence and a "Why?" explanation for everything, rules engine, daily AI briefing, AI SEO chat grounded in your data.
* **Act safely** – "✨ Optimize with SEOlity AI" in Gutenberg, the Classic editor, Elementor, terms, author profiles and WooCommerce products. Every change is previewed (original / suggested / diff), approved (Manual, Semi-automatic or Autonomous mode), versioned and reversible. High-risk changes always need a human.
* **Learn** – applied changes become measured experiments; results feed back into future AI suggestions. A curated SEO knowledge base (Persian + English) grounds every AI answer and can be kept fresh from GitHub.

White-hat only: guardrails reject keyword stuffing, hidden text, removed links and invented facts.

Works alongside Yoast SEO, Rank Math, SEOPress and AIOSEO (reads/writes their fields) or standalone. WooCommerce HPOS compatible.

Full documentation: `docs/ARCHITECTURE.md` and the 579-feature catalog in `docs/FEATURES.md`.

== Installation ==

1. Upload the `seolity` folder to `/wp-content/plugins/` (or upload the ZIP in Plugins → Add New) and activate it.
2. **AI** – SEOlity → Settings → AI: choose a provider (Anthropic Claude, OpenAI, Google Gemini, OpenRouter, a local Ollama server or any OpenAI-compatible API) and enter its key. Click "Test connection".
   **Or use your subscription** (Claude Pro/Max or ChatGPT Plus/Pro) instead of an API key: SEOlity → Settings → Subscription AI. Save a GitHub token (Contents, Actions, Secrets, Workflows), enter a repository name, paste the output of `claude setup-token` (or the contents of `~/.codex/auth.json` after `codex login`) and click "Set up GitHub runner". Answers take about 1–3 minutes; SEOlity waits automatically.
3. **Search Console** – create an OAuth client (type "Web application") in Google Cloud Console, enable the "Google Search Console API", add the redirect URI shown in SEOlity → Settings → Search Console, then paste the Client ID and Secret and click Connect. Choose your property.
4. **First audit** – SEOlity → Site Audit → Start audit. Work continues in the background.
5. Optional: SerpApi key (live SERP analysis), PageSpeed Insights key, a GitHub fine-grained token (knowledge sync / data storage), competitors.

For large sites, disable WP-Cron and run the queue from a real cron job:

`* * * * * wp seolity queue run --seconds=50 --path=/var/www/html`

Secrets can be defined in wp-config.php instead of the database, e.g. `define( 'SEOLITY_ANTHROPIC_API_KEY', '…' );` and `define( 'SEOLITY_ENCRYPTION_KEY', '…' );`.

== External services ==

SEOlity only contacts external services that you configure:

* **AI providers** (Anthropic, OpenAI, Google Gemini, OpenRouter, your own endpoint) – page content and SEO data needed for the requested task are sent when you (or a rule you created) run an AI action. Each provider's terms and privacy policy apply.
* **GitHub Actions + Claude Code / OpenAI Codex** (subscription AI, optional) – AI requests are written encrypted to your private GitHub repository and processed there by the official CLI with your own Claude or ChatGPT plan; results are posted back to your site (HMAC-signed) or collected from GitHub.
* **Google Search Console API / OAuth** – read-only Search Console data for the property you select (https://policies.google.com/privacy).
* **Google PageSpeed Insights API** – the URL you test.
* **SerpApi** – the keyword and market you analyze (https://serpapi.com/legal).
* **GitHub API** – knowledge base sync (public repository by default) and, if enabled, archiving of your SEO data to the private repository you choose (https://docs.github.com/site-policy).
* **Google Fonts** (admin only, optional) – the Vazirmatn typeface for the admin screens; turn it off in Settings → General (https://policies.google.com/privacy).
* **Competitor websites** – their public sitemaps and pages, fetched politely (robots.txt respected).

No data is sent to the plugin authors.

== Frequently Asked Questions ==

= Will SEOlity change my content without asking? =
No. In Manual mode nothing is applied without approval. Semi-automatic applies only low-risk changes (e.g. ALT text, meta descriptions); Autonomous adds medium-risk ones. Content, titles, slugs, canonical and noindex always require approval, and every change can be reverted.

= Do I need another SEO plugin? =
No. SEOlity outputs titles, meta, Open Graph, canonical, robots and JSON-LD when it is the SEO engine, and steps back automatically when Yoast, Rank Math, SEOPress or AIOSEO is active.

= Is it suitable for Persian sites? =
Yes. The interface is fully translated and RTL-native, text analysis understands Persian (ZWNJ, Arabic ي/ك, Persian digits), Persian URLs are handled correctly, and the knowledge base includes Iran-specific guidance (e.g. IRR currency in product schema).

= How do I remove all data? =
Enable "Delete all data on uninstall" in Settings → Privacy, then delete the plugin.

== Changelog ==

= 1.3.0 =
* Rank Math integration that goes beyond Rank Math Content AI: focus keywords chosen from the Search Console queries each page already ranks for, written to Rank Math's full keyword list (primary + secondary), cannibalization-safe across the whole site, with preview, approval and undo. Also works with Yoast, SEOPress and AIOSEO.
* GSC keyword intelligence for every page (editor, content list, Rank Math page): query coverage in title/description/headings/content, not-covered queries, question queries for FAQ, striking distance, cannibalization, CTR gap, focus keyword audit and an AI keyword strategy.
* Rank Math editor sync (Gutenberg + classic): SEOlity changes appear live in the Rank Math sidebar and are never overwritten on save. Rank Math score in the content list, low-score triage by search demand, pillar content sync, redirection import, social title/description fields.
* New Connection guide: step-by-step bilingual instructions with direct links for every key and account (Claude, OpenAI, Gemini, OpenRouter, Ollama, Claude/ChatGPT subscriptions, GitHub, Search Console OAuth, PageSpeed, SerpApi), plus docs/CONNECT.md and "Guide" links next to each key field.
* Emoji glyphs replaced with line icons everywhere (status marks, priorities, editor buttons, checklists); icon-only buttons have accessible names.

= 1.2.0 =
* New admin design shared with ARIAGH AI Writer: full-width top bar with status chips, grouped sidebar with line icons and AI/Search Console engine cards, icon cards, two-column settings rows with toggle switches, floating save bar, provider cards, blue palette and Vazirmatn font (light by default, dark mode kept).

= 1.1.0 =
* Subscription AI: use your Claude Pro/Max or ChatGPT Plus/Pro plan (official Claude Code / Codex CLI on GitHub Actions in your private repository) instead of an API key. Encrypted job queue, signed callback, local-site mode, automatic waiting in every AI feature.

= 1.0.0 =
* First release.
