=== ARIAGH.SEOLITY – AI SEO Intelligence for WordPress ===
Contributors: seolity
Tags: seo, ai, search console, woocommerce, schema
Requires at least: 6.2
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.12.1
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

= 1.12.1 =
* Site map: every URL is now a large horizontal card (status icon and colour, title, address, what Google does with it, clicks, impressions, position, internal links, depth, words, warnings, Details / Open page); status tiles and folders use the dashboard card style; whole-site bar; the square picture moved to its own tab.
* Competitors: "who you meet most in Google" is shown as cards with a presence bar instead of a small table.

= 1.12.0 =
* Google results from your choice of source, with what each costs and needs: Serper.dev (2,500 free searches, email only), SearchApi.io, SerpApi, DataForSEO, Google Programmable Search (official) — or "your own browser": no key, account, phone or card; search in an incognito window and one bookmark click sends the results page to SEOlity.
* Visibility in AI search: SEOlity asks Gemini (with Google Search — free tier), Claude with web search, ChatGPT web search or Perplexity your tracked queries and records whether your site is cited, in which place and which competitors are cited instead; checked weekly.
* The AI chat can see everything: a snapshot of the whole site and plugin, every setting (never secrets) and connection, and 19 data tools (Search Console per query/page, page audit, roadmap, issues, audit, indexability, duplicates, 404s, competitors, Google and AI results, experiments, site map, activity) chosen by a short planning step for each question; answers link straight to the right page and tab and never claim to have no access.
* "Where the site's problems are": a weighted breakdown by area — technical & indexing, on-page, internal links, content & cannibalization, speed, products, off-site — with the share, affected URLs and top problems; the AI diagnosis ranks the areas.
* Much stronger cannibalization detection: topic similarity (TF-IDF), the queries two pages split in Search Console and how often Google swapped them week by week, search intent, a severity score, and the right strategy with alternatives — merge, differentiate (AI retargets one article to its own query), canonical, or strengthen with internal links — each previewed and verified.
* New site map explorer: every URL of the site (crawl, XML sitemap, WordPress, Search Console) coloured by what Google can do with it — indexable, noindex, blocked by robots.txt, redirect, error, canonicalized, not crawled — with flags (orphan, not in sitemap, sitemap problem, deep, thin, slow, traffic, Google-only), a pixel map of the whole site, folder tree, sortable list with CSV export, daily trend and a detail panel per URL (live re-check, Search Console inspection, rich results and PageSpeed links). Rebuilt every day.
* Phones: the end of every page stays reachable above the task dock.

= 1.11.0 =
* The AI now sees every detail of Search Console: for any query or page named in a question (or matched from your stored queries) it gets the weekly clicks, impressions and position for the whole stored period, which of your pages Google showed in which week (page swaps — e.g. "why does the category page rank instead of the home page"), the sharpest drop with the edits made around it, the measured results of past edits and the home page's own numbers. Answers like "only site totals are available" no longer happen when the data exists.
* Edit history: edits made in WordPress or a page builder are measured like SEOlity's own changes, and "Analyze the edit history" measures past edits from WordPress revisions inside your Search Console period — each with a before/after verdict (positive, negative, neutral) and the position change of every main query.
* New competitor workspace: SEOlity searches your tracked queries in Google through the SERP API (location-specific, not personalized — the incognito view; scraping Google directly is against its terms and blocked) and stores every result page as history. Each competitor has its own panel: every query where it is above or below you with its page and trend, its estimated Search Console (queries, pages, clicks — clearly marked as an estimate and how it is calculated), its crawled pages, AI discovery of the queries its pages target, and a tab per analyzed query in which the AI compares their page with yours from the live results, both pages, People Also Ask and your Search Console history, explains why they rank where they do (measured vs. hypothesis), why Google shows the page of yours it shows, and gives ordered steps — which also go to the SEO roadmap. Domains you meet most in Google are suggested as competitors. A monthly search budget prevents surprise costs.
* AI power level per connection (Settings → AI → each provider → "3. AI power"): sent in each provider's real parameter — Anthropic effort, OpenAI reasoning_effort (reasoning models), OpenRouter reasoning.effort, Gemini thinking budget / level, Ollama think, Claude Code MAX_THINKING_TOKENS and Codex model_reasoning_effort; a model that rejects it is asked again without it.
* Models are chosen in one place only: Settings → AI (the subscription wizard and the custom API form no longer have their own model fields); the list always comes from the provider.

= 1.10.0 =
* New: AI review of 404 errors (404 Monitor → "AI review: 301, 410 or leave?"). For every open 404 SEOlity collects the evidence — Search Console clicks/impressions/position of that exact URL, internal links still pointing to it, external referrers, human vs. Googlebot requests, deleted posts at that address and how close each candidate page is — and the AI decides per URL: 301 to a genuine equivalent (to keep its rankings and move crawling to the new URL), 410 when it is gone for good, leave it as 404 when it has no value, or fix a mistyped internal link. It also says honestly whether the 404s cost crawl budget on a site of your size. The AI may only pick targets SEOlity checked; nothing changes until you approve; every 301 and 410 is verified on the live site and the whole review can be undone.
* Undo of a redirect or 410 puts the URL back into the open 404 list.
* The operation window shows a progress message while the AI is still working instead of "Nothing to change was found".

= 1.9.2 =
* Fixed: the indexability audit failed with “Object of class WP_Error could not be converted to string” on sites with a public taxonomy that has no front-end address (common with WooCommerce attributes and filter plugins). Such taxonomies are now skipped instead of stopping the audit.

= 1.9.1 =
* Redirect check: when the old URL still answers 404, SEOlity now clears it in the page cache (LiteSpeed, WP Rocket, W3 Total Cache, WP Super Cache, SiteGround), checks again past the cache, and otherwise says exactly why: Rank Math has no matching redirect, redirects are switched off, a cache answered (HIT), or a CDN / security plugin / server rule answers first. A 404 leaves the open list only after the redirect really answers.
* Before approval, a redirect is flagged when nothing would serve it (SEOlity redirects off and Rank Math Redirections not active).
* Duplicate & near-duplicate articles checks blog articles only (no pages, products or templates) and suggests how to fix the cannibalization; nothing changes until you approve.

= 1.9.0 =
* AI settings rebuilt into two boxes — "AI with API" and "Subscription" — with one panel per provider: key field and link, "Connect & load models" (the models are read from your own account: Anthropic, OpenAI, Gemini, OpenRouter, Ollama, custom APIs and the new GitHub Models), a real one-request model test, a clear status (not connected / key works / model tested) and plain-language errors (wrong key, no credit, model not available, rate limit, network). Fixed: a saved and working AI connection could still show "not configured".
* Subscriptions are offered only the way each company allows: Claude Pro/Max through the official Claude Code program, ChatGPT plans through the official Codex program — on your private GitHub runner or, new, on your own server. SEOlity never asks for passwords or cookies.
* Server & speed → "Check this server & install": checks PHP, Node.js, npm, disk (measured package sizes: Claude Code ≈ 292 MB, Codex ≈ 444 MB), memory and folder rights, and installs the official CLI with one fixed npm command when the host allows it; shared hosts get an honest explanation.
* Tasks: no task stays "running" forever any more. Every background task has a heartbeat and a time limit, is detected when the server kills it, retried and finally marked "timed out". New Task queue tab with cancel, retry, delete and exact counts.
* SEO roadmap («مسیر راه»): one "Run full-site analysis" button updates every section (crawl, Search Console, issues, indexability, duplicates, 404 targets, AI plan). Every problem appears once, with category links in the recommended order and the reason, status, priority, AI recommendation, "Do it with AI" (preview → approval → apply → verify → undo) or manual edit. A checkmark appears only after the result was verified on the live site; "mark as done" by hand is shown as such.
* New: Indexability & crawl budget audit — Elementor templates, attachments, author/date archives, internal search, tags, empty taxonomies, parameter URLs, feeds and pagination are checked live (status, robots meta, X-Robots-Tag, canonical, robots.txt, sitemap, internal links, Rank Math settings, optional Search Console inspection), with the right fix for each (noindex vs robots.txt vs sitemap vs 301) applied in Rank Math and verified.
* New: Duplicate & near-duplicate articles («مقالات تکراری و شبه تکراری») — finds articles that repeat or compete (content overlap, titles, H1, shared queries) and merges them into the strongest one: your standing and per-merge instructions, AI merge, full before/after preview, then noindex, 301 redirects and internal-link updates only after the merged article was saved and verified. Nothing is deleted; every edit keeps a WordPress revision and can be undone.
* 404 monitor: select many URLs and redirect each one to its own target in one step, with validation and a verified result per URL.
* Rank Math is the authority when it is active: robots, titles, descriptions and settings are written where Rank Math reads them, redirects are created in Rank Math Redirections, and every change is checked both in the database and on the live page.
* Navigation: every menu item shows its sections underneath; the knowledge base moved to "Data collection".

= 1.8.0 =
* "Do it" on the dashboard and in the SEO roadmap opens a popup with two sides: exactly where to do it yourself (menu paths and buttons), and the AI's plan for your site. Press "Approve & let the AI do it" and watch every step live (e.g. "Finding pages that should link to …", "Adding the link in …"). It optimizes titles and content, writes FAQs, creates redirects, fixes redirect chains and adds internal links to weak pages by reading related posts across the whole site and writing a natural sentence with the link. Every change is versioned.
* New Activity page (AI Center → Activity) with two tabs: Live activity (what is running now, step by step, with the route each AI request took) and Completed work (an exact timeline of everything SEOlity did on your site: AI answers with route, model, tokens and error details, edits, redirects, audits, Search Console syncs, archives …, filterable and grouped by day).
* AI edit cards (optimizer, titles, products, agent) now run on the server and follow you across every admin page and after a reload until you review and close them; finished cards stay compact.
* Chat: conversations sidebar with search and delete, questions about a specific page, answers that arrive even after a reload or when the page was closed, retry / answer again, copy, and the route, model and time of each answer.
* Direct really means direct: with the Direct route nothing is sent through GitHub. API keys are called directly; a Claude/ChatGPT subscription runs with the official CLI on your own server (VPS), which SEOlity detects and can test under Server & speed. Without the CLI, Direct shows a clear error instead of silently using GitHub.
* Server & speed: choosing a route is instant and no longer reloads the page; a route that is not set up yet explains what to do instead of jumping to another page.

= 1.7.0 =
* New SEO roadmap replaces "What should I do today?": nothing is analyzed until you press "Analyze". SEOlity then reads Search Console, the site audit, your content, new posts and competitors, studies the SEO knowledge base (up to 10 documents plus your site playbook) and writes an expert diagnosis, strengths, risks, the focus for this week and a prioritized plan in four phases (this week, next 2 weeks, this month, later).
* Down to the smallest detail: e.g. new articles Google has not indexed yet (with "Inspect in Search Console" per page, sitemap / internal-link / noindex problems), CTR fixes, page-2 pages, declining pages, cannibalization, broken URLs, thin content, products, images, schema and the AI's own strategic tasks.
* Every task opens a guide: why it matters (with the numbers), the affected pages with Edit / View / one-click AI actions, the AI's plan for your site and an exact step-by-step guide in the dashboard (menu paths for your SEO plugin, with buttons), and when the task counts as done. Mark tasks done, in progress or not relevant.
* The roadmap remembers: every version is stored in the database, the next analysis receives the previous plan and keeps priorities stable unless the data really changed, and shows what changed (new, solved, priority changes with the reason). Done tasks come back only when the problem grows again.
* Non-intrusive AI editing: optimizing a text (also Elementor widgets), a product or a title/description no longer keeps a big popup open. A small card in the corner shows what is being edited and the progress; when it finishes it stays until you close it, with the score change, what was changed (ticked list) and a button to open the full report and apply. Cards survive a page reload.

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
