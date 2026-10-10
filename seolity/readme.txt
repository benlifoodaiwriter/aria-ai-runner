=== ARIAGH.SEOLITY – AI SEO Intelligence for WordPress ===
Contributors: seolity
Tags: seo, ai, search console, woocommerce, schema
Requires at least: 6.2
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.6.0
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
* **Cloudflare API + your Cloudflare Worker** (optional) – when you connect Cloudflare, SEOlity uses the Cloudflare API with your token to deploy a worker on your account; encrypted subscription results pass through its KV mailbox and its cron calls your site's REST API to run the background queue. Cloudflare terms: https://www.cloudflare.com/terms/ · Privacy: https://www.cloudflare.com/privacypolicy/
* **Custom API** (optional) – prompts are sent to the AI service whose address you enter, under that service's terms.
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

= 1.6.0 =
* New "Server & speed" page (Connections): choose whether your host is in Iran or abroad, pick the route for AI requests (Auto, Direct, Cloudflare, GitHub) and run a live speed test of every route.
* Iran: AI requests (Claude, ChatGPT/OpenAI, Gemini, OpenRouter, custom APIs) and Google services (Search Console, PageSpeed, Google sign-in, SerpApi) travel through your own Cloudflare worker (synchronous, encrypted) or your private GitHub runner, whichever is fastest and working; never direct. The page explains why (sanctions and filtering).
* Abroad: direct by default; a relay is used automatically only when the direct connection fails. Every route can be chosen manually in both cases, and a failing route automatically falls through to the next one.
* Cloudflare worker: new encrypted /proxy (AES-256-GCM + HMAC, https only, no private networks, nothing stored). Outdated workers are redeployed automatically after a plugin update.
* GitHub runner: new "http" engine for API requests, queue checks every 2 seconds instead of 10, stays warm for a configurable time (default 10 minutes), a run that is already warm is reused instead of starting a new one, and the chat / optimize button start the runner ahead of time. The admin waits 2.5 s between checks instead of 6 s.
* Storage on GitHub is on by default for every host: AI logs, chats, 404 logs, learnings, experiments, knowledge notes and weekly competitor snapshots are archived too (plus Search Console data, reports, change history and settings as before), and the host keeps only recent data. Uses the runner repository's seolity-data branch when no separate data repository is set. Table sizes are shown on the page.

= 1.5.0 =
* ARIAGH.SEOLITY branding: new icon, logo, banners and menu icon in the ARIAGH style.
* Simpler navigation: 9 sections instead of 28 menu items; related pages are tabs inside each section (nothing removed, every page keeps its link).
* Subscription AI really deploys now (like ARIAGH AI Writer): one card, one "Deploy now" button. It checks the token type (rejects API keys), creates or repairs the private repository, enables GitHub Actions and the workflow, stores and verifies the secrets on GitHub and activates the engine — with a live checklist and a status read from GitHub.
* GitHub: "Create & set up everything" builds a single private repository (runner + own knowledge + data archive on the seolity-data branch).
* Cloudflare: the connected state is now remembered and shown (account, ID, verified token); account picker when a token sees several accounts; clear message when the Account ID cannot be detected.
* Knowledge collection: "Collect knowledge now" runs a GitHub workflow that learns from SEO sources, your Search Console queries, pages and competitors using your Claude/ChatGPT subscription (or an Anthropic key) and writes a site playbook to GitHub; live status and report; the AI always uses this site's playbook.
* AI everywhere: media modal, Media Library and attachment screens get an AI button for ALT, title, caption and description (with optional hint), a bulk action and optional auto-ALT on upload.
* Content-writing AI tasks now receive market evidence: cached SERP top results and People Also Ask, matching competitor pages (length, FAQ, schema) and this site's Search Console standing.

= 1.4.0 =
* Subscription AI wizard (like ARIAGH AI Writer, simpler): prerequisite check first, then GitHub, optional Cloudflare, the Claude Pro/Max or ChatGPT Plus/Pro account (copyable Windows / macOS commands) and a real test request.
* New "GitHub & data" page: connect with one token (ready link with the right scopes), see owner, scopes and expiry, and create the private runner, knowledge and data repositories in one click each — with manual step-by-step guides, advanced settings and FAQ.
* Own private knowledge repository in one click (bundled knowledge + weekly knowledge builder workflow + optional Anthropic key), or keep the shared one.
* Cloudflare relay: deploys a free worker on your own Cloudflare account from WordPress (KV mailbox, signed requests, workers.dev, cron). Subscription answers arrive faster, local sites work in pull mode, and the background queue runs on time. Built-in diagnostics.
* Custom API rebuilt: service presets (DeepSeek, Groq, Mistral, Together, Fireworks, xAI, Perplexity, Gemini, Cloudflare Workers AI, Azure OpenAI, LM Studio, vLLM, LiteLLM, Anthropic-compatible gateways), OpenAI or Anthropic format, any key method (Bearer, x-api-key, api-key, custom header, query), extra headers and query parameters, model list, automatic structured-output fallback that remembers what works, token parameter, temperature, request preview.
* Connection guide: new entries for Custom API, GitHub, knowledge base, data archive and Cloudflare.

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
