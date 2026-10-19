# SEOlity — Architecture

SEOlity ("AI SEO Intelligence for WordPress") is a modular WordPress + WooCommerce plugin that works
as an SEO operating system: it measures (crawler, Google Search Console, content analysis), decides
(recommendations, doctor, rules, AI), acts safely (preview → approve → apply → measure → revert), and
learns from the results. This document describes how it is built.

Contents

1. Design principles
2. Folder structure
3. Runtime architecture (container, boot, services)
4. Database schema
5. REST API
6. AI architecture
7. Change management: preview, approval, versions, undo
8. Queue, cron and background processing
9. Crawler and site audit
10. Google Search Console (OAuth + sync + analytics)
11. Knowledge base and GitHub storage
12. Security architecture
13. Performance
14. UI architecture
15. Internationalization and RTL
16. Compatibility (WooCommerce, Gutenberg, Elementor, Classic, other SEO plugins)
17. Extensibility (filters, providers, services)
18. Editions: MVP / Pro / Enterprise and the SaaS path
19. Roadmap
20. Dependencies

---

## 1. Design principles

| Principle | How it is enforced |
|---|---|
| Nothing destructive without approval | Every write goes through `ChangeManager`; approval mode decides what may auto-apply (Manual: nothing, Semi: low risk, Autonomous: low + medium). High-risk changes always need a human. |
| Everything is explainable | Scores are sums of named checks with weights; recommendations carry `reason`, `evidence`, `impact`, `effort`, `confidence`; the "Why?" modal renders them. |
| Everything is reversible | Each applied change stores `before_value`/`after_value` and a version number; revert and restore are first-class operations; experiments can auto-revert. |
| White-hat only | AI guardrails (policy prompt + output inspection) reject keyword stuffing, hidden text, removed links/shortcodes and invented figures. |
| Nothing heavy on page load | Front end only reads cached meta; crawling, GSC sync, AI and analytics run in the DB-backed queue. |
| Real data first | Recommendations, briefings and chat are grounded in crawler/GSC/analysis data passed to the model as JSON; the model is told to say when data is missing. |
| Own tables, not `wp_options` | 31 custom tables; settings live in one non-autoloaded option, secrets in another (encrypted). |
| Multi-project from day one | Every data table has `project_id` so one install (or the future SaaS) can manage several sites. |

## 2. Folder structure

```
seolity/
├── seolity.php                 Plugin header, constants, autoloader, activation hooks, HPOS compatibility
├── uninstall.php               Removes cron always; data only when "delete on uninstall" is enabled
├── readme.txt                  WordPress.org-style readme
├── src/                        PSR-4 (namespace SEOlity\) — no Composer needed
│   ├── Autoloader.php
│   ├── Plugin.php              Lazy service container + boot()
│   ├── Core/                   Database, Installer, Settings, Crypto, Capabilities, AuditLog, Http
│   ├── Queue/                  Queue (jobs table), Scheduler (WP-Cron + loopback kick), Jobs (handlers)
│   ├── AI/                     Engine, Tasks (prompt+schema catalog), Schema, Guardrails, ProviderRegistry,
│   │   └── Providers/          Anthropic, OpenAI, OpenRouter, Gemini, Ollama (local LLM), CustomApi
│   ├── Analysis/               Text (Persian-aware NLP), ContentAnalyzer (explainable scoring)
│   ├── Content/                Objects (read/write any field), MetaStore (Yoast/RankMath/SEOPress/AIOSEO/own),
│   │                           ChangeManager, Optimizer, ContentEvents
│   ├── Crawler/                Crawler (seed/batch/finalize), PageParser, Checks (≈60 checks)
│   ├── GSC/                    OAuth (PKCE), Client, Sync, Analytics
│   ├── Doctor/ Recommendations/ Health/ Alerts/ Briefing/ Chat/ Rules/ Reports/ Planner/
│   ├── Keywords/ Links/ Images/ Schema/ Competitors/ Experiments/ Redirects/ Technical/ Woo/
│   ├── Knowledge/              KnowledgeBase (RAG: bundled + GitHub + user notes)
│   ├── Storage/                GitHubClient, GitHubArchive (cold data → private repo)
│   ├── Frontend/               Frontend (redirects, 404 log, robots, sitemap, FAQ), Head (meta/OG/JSON-LD)
│   ├── Api/                    Router (≈120 REST routes), ContentList
│   ├── Admin/                  Admin (SPA shell, menu, assets), EditorIntegration, I18n (generated)
│   └── Cli/                    WP-CLI `wp seolity …`
├── assets/admin/               Vanilla JS SPA (no build step) + CSS
│   ├── ui.js                   SL toolkit: i18n, DOM builder, API client, modals, toasts, word diff, previews
│   ├── charts.js               Dependency-free SVG charts
│   ├── app.js                  Shell, router, dashboard, action center, chat, changes, alerts…
│   ├── pages-seo.js            Audit, GSC, keywords, content, products, images, links
│   ├── pages-intel.js          Competitors, experiments, redirects, 404, schema, sitemap, robots, reports
│   ├── pages-admin.js          Automation (rules, queue, audit log), knowledge, settings
│   ├── editor.js / gutenberg.js / elementor.js   Editor integrations
│   └── app.css / editor.css    Design system (CSS variables, logical properties, dark mode)
├── runner/                     GitHub Actions workflow + runner.mjs for subscription AI
├── knowledge/                  Curated SEO knowledge (fa + en) + distilled/ (GitHub Action output)
├── languages/                  seolity.pot, seolity-fa_IR.po/.mo
├── tools/
│   ├── i18n/                   build.py + translation TSVs → .pot/.po/.mo and Admin/I18n.php
│   └── knowledge-builder/      Node script that distils SEO docs with Claude (run by GitHub Actions)
└── docs/                       ARCHITECTURE.md, FEATURES.md
```

## 3. Runtime architecture

`Plugin::instance()` runs on `plugins_loaded` (priority 5). It is a lazy container: services are
instantiated on first access (`seolity()->crawler`), so a front-end request typically touches only
`settings`, `redirects`, `not_found`, `schema` and `meta`.

```
Plugin::boot()
 ├─ load_textdomain (honours Settings → UI language)
 ├─ Installer::maybe_upgrade()          dbDelta when SEOLITY_DB_VERSION changes
 ├─ Capabilities::register_hooks()      map_meta_cap for object-level checks
 ├─ Scheduler::register_hooks()         cron schedules, tick, loopback endpoint
 ├─ Frontend::register_hooks()          redirects, 404 logging, robots.txt, sitemap filters, <head>
 ├─ rest_api_init → Api\Router::register()
 ├─ admin_post → GSC OAuth callback
 ├─ is_admin → Admin, EditorIntegration
 ├─ ContentEvents                       save_post / edited_term / attachment hooks → queue jobs
 └─ WP_CLI → Cli\Command
```

Services are replaceable/extendable through the `seolity_register_services` filter.

## 4. Database schema

All tables are `{$wpdb->prefix}seolity_<name>`, created with `dbDelta`, utf8mb4. URLs are identified by
`url_hash = sha1(normalized URL)` where normalization lowercases scheme/host, decodes and re-encodes
each path segment (so `%d9` and `%D9` are the same Persian URL), and drops fragments.

| Table | Purpose | Key columns |
|---|---|---|
| `projects` | Sites managed by this install (SaaS-ready) | id, name, domain, type, settings |
| `urls` | Crawled URL inventory | url_hash, status_code, redirect_to, indexable, robots, canonical, title, meta_description, h1, word_count, content_hash, response_ms, page_bytes, depth, inlinks, outlinks, in_sitemap, score, data(JSON) |
| `audits` | Crawl runs | type, status, urls_total, urls_done, summary(JSON) |
| `issues` | Check results per URL | url_id, check_code, category, severity, context(JSON), status, fixable, first_seen, last_seen |
| `gsc_daily` | Site totals per day | date, clicks, impressions, ctr, position |
| `gsc_page_query` | Page × query × day | page_hash, query_hash, page, query, clicks, impressions, ctr, position |
| `gsc_breakdown` | Country / device / appearance per day | dimension, dim_value, metrics |
| `keywords` | Keyword set and mapping | keyword_hash, intent, kw_type, difficulty, opportunity, target_url, target_object_*, tracked, data |
| `competitors`, `competitor_pages` | Competitor monitoring | domain, sitemap_url; per page: title, h1, headings, word_count, schema_types, topics, content_hash |
| `changes` | Every proposed/applied change (versioned) | batch_id, object_type/id, field, before_value, after_value, version, status, source, risk, ai_provider/model, prompt_version, score_before/after, gsc_before/after, reason, recommendation_id, experiment_id |
| `experiments` | Before/after measurement of applied changes | change_ids, window_days, metrics_before/after, outcome, confidence |
| `learnings` | Aggregated results per change type | change_type, metric, samples, avg_delta, positive/negative/neutral |
| `redirects` | Redirect manager | source_hash, target, type (301/302/307/410/451), is_regex, hits, origin |
| `not_found` | 404 log | url_hash, referrer, is_bot, hits, suggested_target, suggestion_score |
| `recommendations` | Action center | dedupe_key, type, evidence, action, priority, impact, effort, confidence, score, auto_fixable |
| `content_analysis` | Cached content scores | object_type/id, focus_keyword, score, metrics(JSON), content_hash |
| `images` | Media inventory | attachment_id, alt, filesize, width, height, issues, usage_count |
| `internal_links` | Link suggestions | source_id, target_id, anchor, relevance, reason, status |
| `alerts` | AI alerts | type, severity, dedupe_key, status, notified |
| `reports` | Reports & briefings | type, period, data(JSON), archive_ref (GitHub) |
| `jobs` | Background queue | hook, args, unique_key, group_key, status, priority, attempts, available_at |
| `audit_log` | Who did what | user_id, action, object, details, ip_hash |
| `ai_requests` | AI usage & cost | provider, model, task, prompt_version, tokens, cost_usd, duration_ms, status |
| `ai_cache` | Response cache | cache_key, task, response, expires_at |
| `rules` | Rules engine | trigger_event, conditions, actions, mode, enabled |
| `chat` | AI SEO chat history | conversation_id, role, content, meta |
| `knowledge` | Knowledge chunks (RAG) | source (bundled/github/user), path, sha, lang, topic, tags, chunk_index, content |
| `content_plan` | Content calendar | scheduled_date, topic, keyword, intent, brief(JSON), status, post_id |
| `clusters` | Topic clusters | pillar_url, members, gaps, coverage |
| `remote_jobs` | Subscription AI requests | request_hash, engine, model, task, status, payload, response, error, usage |

Settings: option `seolity_settings` (non-autoloaded, schema-validated). Secrets: option `seolity_secrets`
(encrypted values; never sent to the browser). Retention: the daily `cleanup` job prunes logs, 404 hits,
AI cache and old GSC rows (`retention_days`); with GitHub storage enabled, cold data is archived first.

## 5. REST API

Namespace `seolity/v1`, ~120 routes registered in `src/Api/Router.php`. Every route has a permission
tier mapped to a capability:

| Tier | Capability | Examples |
|---|---|---|
| view | `seolity_view` | dashboard, health, issues, gsc/*, keywords, history, analyze |
| ai | `seolity_ai` | optimize, meta, faq, chat, briefing/generate, keywords/research, images/generate |
| apply | `seolity_apply` | changes/{id}/apply|reject|revert|restore, links/{id}/apply, issues status |
| manage | `seolity_manage` | settings, secrets, gsc credentials/property, audit/start, redirects, robots, queue, storage |

Object-level checks (`Capabilities::can_edit_object`) are applied in addition, so an editor cannot
optimize a post they cannot edit. Requests use the WordPress REST nonce (`X-WP-Nonce`). Errors are
`WP_Error` with 4xx statuses (409 for disconnected integrations); unexpected exceptions return 500
without leaking details unless `WP_DEBUG`.

Main route groups: `bootstrap`, `dashboard`, `action-center`, `health`, `doctor`, `recommendations`,
`analyze`/`optimize`/`meta`/`faq`, `changes`, `history`, `bulk`, `content`, `search`, `audit`, `issues`,
`urls`, `pagespeed`, `gsc/*`, `keywords/*`, `products/*`, `images/*`, `links/*`, `competitors/*`,
`experiments/*`, `redirects/*`, `404/*`, `schema/*`, `sitemap/*`, `robots/*`, `reports/*`, `alerts/*`,
`briefing/*`, `chat/*`, `rules/*`, `queue/*`, `audit-log`, `planner/*`, `clusters/*`, `settings/*`,
`ai/usage`, `knowledge/*`, `storage/*`, `focus/*` (insights, apply, plan, bulk), `rankmath/*` (status, import-redirects, sync-pillars), `github/*` (status, connect, runner, knowledge, storage), `cloudflare/*` (connect, accounts, deploy, diagnose, pull, remove), `subscription/check|account|activate|test`, `settings/custom-models|custom-describe`; public signed `remote/callback`, `remote/heartbeat`, `remote/ping`.

## 6. AI architecture

```
caller (Optimizer, Woo\ProductSeo, ChatService, …)
   └─ AI\Engine::run( task, input, opts )
        ├─ Tasks::get(task)          versioned prompt + strict JSON schema + knowledge topics + temperature
        ├─ budget check              monthly USD budget from ai_requests
        ├─ cache lookup              ai_cache (hash of provider, model, task version, input)
        ├─ KnowledgeBase::retrieve   top chunks for the task's topics (RAG)
        ├─ Learning::insights        site-specific results of past experiments
        ├─ Engine::market_context    content tasks: cached SERP + PAA, matching competitor pages, GSC standing
        ├─ system prompt             role + policy (white-hat rules) + brand voice + language + knowledge
        ├─ Provider::complete        structured output (JSON schema) via the selected provider
        ├─ Schema::validate          strict validation; one self-repair round with the validation errors
        ├─ Guardrails::inspect       stuffing, hidden text, removed links/shortcodes, invented numbers
        └─ log usage                 ai_requests (tokens, cost, duration, status)
```

Providers implement `ProviderInterface` (`id`, `label`, `is_configured`, `model`, `complete`). Built-in:

| Provider | Transport | Structured output |
|---|---|---|
| Anthropic | Messages API (`x-api-key`, `anthropic-version: 2023-06-01`), default model `claude-opus-5-5` | `output_config.format` json_schema + `output_config.effort`; refusal handling via `stop_reason`; server-side refusal fallbacks (`fallbacks: "default"`) enabled for models that support them |
| OpenAI | Chat Completions | `response_format` json_schema (strict) |
| OpenRouter | OpenAI-compatible | json_schema |
| Google Gemini | generateContent | `responseJsonSchema` |
| Ollama / local LLM | `/api/chat` | `format` = schema |
| Custom | Any OpenAI-compatible base URL | json_schema |
| Claude subscription (Pro/Max) | Claude Code CLI on GitHub Actions (asynchronous, see below) | schema in prompt + validation/repair |
| ChatGPT subscription (Plus/Pro) | OpenAI Codex CLI on GitHub Actions (asynchronous, see below) | schema in prompt + validation/repair |

### Network gateway (Iran / abroad)

`AI\Gateway` decides how every AI request leaves the server (`AbstractProvider::post()` calls
`Gateway::request()`), and a `pre_http_request` filter applies the same decision to Google APIs,
OAuth and SerpApi:

| Route | How | Latency | When |
|---|---|---|---|
| direct | `Http::json()` from the host | API only | abroad (default) |
| cloudflare | signed POST to the owner's worker `/proxy`; request and answer AES-256-GCM sealed | +0.3–1 s | Iran (default when deployed) |
| github | `remote_jobs` row with engine `http` (sealed), runner sends it from GitHub, answer via relay/callback | seconds warm, ~40 s cold | Iran fallback, or chosen |

Settings `host_region` (iran/abroad, guessed from timezone/locale until chosen) and `ai_route`
(auto/direct/cloudflare/github). "auto" = Iran: relays ordered by the last speed test (never direct);
abroad: direct first, then relays. A transport failure or a sanctions-style 403 falls through to the
next route and schedules a new probe. `Gateway::probe()` measures each route against the active
provider's API (daily, and from the "Server & speed" page). Local endpoints (Ollama, private IPs) are
never relayed.

Runner speed: the workflow run stays warm `sub_warm_minutes` (default 10) polling the queue branch head
with `git ls-remote` every 2 s; `SubscriptionRunner::dispatch()` reuses an in-progress run whose
`run-name` lists the needed engines (GitHub API `actions/runs`), and `POST /ai/warm` starts a run when
the chat or optimizer opens.

### Subscription AI (Claude Pro/Max, ChatGPT Plus/Pro)

Inspired by the ARIAGH AI Writer runner: instead of a pay-per-token key, the official CLIs run with
the owner's own plan inside the owner's **private** GitHub repository.

```
Engine::run → Providers\SubscriptionClaude|Codex::complete
   └─ AI\Remote\SubscriptionRunner::complete(engine, model, request)
        ├─ answer already stored for this request hash? → return it (synchronous)
        └─ else insert remote_jobs row (queued) and throw AI\Deferred
shutdown → flush(): all queued requests → jobs/<id>.json (AES-256-GCM) in ONE commit on the
           queue branch (default "seolity-jobs") → workflow_dispatch "SEOlity AI"
GitHub Actions → runner/runner.mjs: claude -p / codex exec per job → results/<id>.json (encrypted)
           → POST /seolity/v1/remote/callback (HMAC) when the site is reachable
site → stores the answer (callback, or collect() polling the results folder for local sites)
```

* `AI\Deferred` is caught by the REST router (HTTP 202 `seolity_ai_deferred` with the job id; the admin
  UI shows a waiting indicator, polls `GET /ai/remote/{id}` and re-sends the identical request) and by
  the queue (job re-scheduled in 45 s without counting an attempt; woken immediately when a result arrives).
* Keys: `enc = sha256("enc|" + SEOLITY_SHARED_SECRET)` for AES-256-GCM, `mac = sha256("mac|" + secret)`
  for the callback HMAC (timestamped, 10-minute window). The repository only ever holds ciphertext.
* The runner waits ~2 minutes for follow-up requests (multi-step features), deletes processed jobs,
  prunes results after 24 h, never sets `ANTHROPIC_API_KEY`/`OPENAI_API_KEY` (no API billing), and
  returns a refreshed Codex `auth.json` inside the encrypted result so SEOlity updates the secret.
* Setup (`POST /subscription/setup`) creates the private repository if needed, pushes the workflow and
  runner, creates the queue branch and stores the Actions secrets (`SEOLITY_SHARED_SECRET`,
  `SEOLITY_CALLBACK_URL`, `CLAUDE_CODE_OAUTH_TOKEN`, `CODEX_AUTH_JSON`) with libsodium sealed boxes.
  Runner files are re-pushed automatically when a plugin update ships a newer runner.
* Stalled queues are re-dispatched; requests expire after `sub_wait_minutes` (default 45).

Extensions register more providers with the `seolity_ai_providers` filter. Tasks (22): optimize_content,
meta_tags, product_seo, image_alt, keyword_research, search_intent, ctr_rewrite, content_gap,
competitor_summary, cannibalization, internal_links, faq_generate, content_brief, content_plan,
topic_clusters, redirect_target, experiment_verdict, briefing, chat, eeat_analysis, schema_suggest,
serp_analysis. Each task has a `prompt_version` stored with every change so results can be compared
across prompt versions.

### SEO roadmap

`Roadmap\Roadmap` (route `actions`, REST `/roadmap`, `/roadmap/analyze`, `/roadmap/task`, `/roadmap/history`)
runs only on demand. `POST /roadmap/analyze` inserts a `roadmaps` row and queues `roadmap_build`
(`GET /roadmap` also drives the queue while it waits, for sites without traffic):

1. `Roadmap\Collector` refreshes recommendations, runs the site doctor and gathers facts (health,
   crawl, issue groups, GSC 28-day totals / top queries / pages / movers / ranking distribution,
   competitors, learnings) plus candidate items with real targets: new posts without impressions
   (index check: noindex, inlinks, sitemap, GSC inspect link), recommendation groups and doctor issues
   not covered by a recommendation. The snapshot is stored on the row, so a deferred AI retry reuses it.
2. `Roadmap\Guides` adds exact dashboard steps per task type (SEO-plugin aware) and done criteria.
3. AI task `seo_roadmap` (knowledge-grounded, 10 knowledge documents, previous roadmap and
   owner statuses in the input) returns diagnosis, strengths, risks, focus and per-item priority, phase,
   plan steps and up to 6 strategic `ai:` items.
4. Merge + stability: previous priority/phase are kept unless the AI flags a material change, the
   affected count moved by 30 % or the priority jumps two levels; a diff (added / resolved / priority)
   is stored. Owner statuses (`seolity_roadmap_status`) survive versions; a done task re-opens when
   more pages are affected than when it was closed. Without AI the data roadmap is still produced.

### Task dock

`SL.dock` (ui.js) renders small, non-blocking cards (z-index below modals) backed by the server:
each card is an `activity` row with `dock=1`, restored on every admin page from `GET /activity/dock`
and polled via `GET /activity/{id}`. At most three cards are shown (+N more, minimize pill); finished
cards are compact and keep their report (`meta.result`) until closed (`POST /activity/{id}/dismiss`).

### Activity log (`Activity\Activity`)

One table (`{prefix}seolity_activity`) for everything SEOlity does: `kind` = ai | job | agent | chat |
event, `status` = running | waiting | done | failed | info, `step`/`progress`, `route` (direct,
cloudflare, github, local), `meta.steps[]` with timestamps. Writers: `AI\Engine::run()` (every AI
request, with route/model/tokens/error), `Queue::run_job()` (labelled jobs), `AuditLog` (every change,
redirect, audit, setting …), `AiJobs`, `Agent` and `ChatService`. `GET /activity/live` (running
items + queue counts) feeds the Live tab; `GET /activity` (filters, pages) the Completed tab. Rows
older than 90 days are removed by the clean-up job and archived to GitHub with the other logs.

### Server-side AI jobs (`Activity\AiJobs`)

`POST /ai-jobs {type: optimize|meta|product, params}` creates the dock activity and the `ai_job`
queue item, then runs it after the response (`fastcgi_finish_request`) or via the scheduler kick, so
closing or reloading the page does not stop it.

### AI agent (`Agent\Agent`)

`GET /recommendations/{id}/guide` returns the manual steps (Guides) and the agent plan;
`POST /agent/run` starts an `agent_run` job. Actions: optimize_meta, optimize_content,
optimize_product, generate_faq, set_field, create_redirect, internal_links_to (pending link
suggestions, or AI task `link_sentence` on related posts found by title words and category, inserted
after an existing paragraph), apply_link, fix_redirect_chains, bulk, plan_content. All edits go
through `ChangeManager` (versioned, undoable); state is saved so a retried job resumes.

### Chat

`POST /chat` stores the question and an assistant placeholder, keeps running with
`ignore_user_abort` and answers; a deferred answer (GitHub route) becomes a `chat_reply` job.
`GET /chat/{cid}` returns messages plus pending ones and nudges the queue, so answers appear after
a reload. Each answer stores route, model, time and error.

### Subscription CLI on the host (`AI\Remote\LocalCli`)

If PHP may start programs (`proc_open`) and the official `claude` / `codex` CLI is found (setting
`sub_cli_<engine>` or PATH; only binaries named claude*/codex* are accepted), subscription requests
run synchronously on the host (`route = local`) unless the GitHub route is chosen. With the Direct
route and no CLI, a subscription request fails with an explanation and is never queued on GitHub.
`POST /network/local-test` checks it.

## 7. Change management

```
propose ──► pending ──apply──► applied ──revert──► reverted
              │                   │                   │
              └──reject──► rejected└──(new version)    └──restore──► applied (new version)
```

* `ChangeManager::propose()` stores before/after, risk (from `Objects::RISK`: ALT text, meta description,
  focus keyword = low; meta title, excerpt, FAQ, term/author descriptions = medium; content, title, slug,
  canonical, noindex/nofollow, robots.txt = high), the AI provenance and the score delta.
* Approval modes: **Manual** (everything waits), **Semi-automatic** (low risk auto-applies),
  **Autonomous** (low + medium auto-apply; high still waits). Rules engine actions follow the same gate.
* Editor flows (Gutenberg/Elementor/Classic): suggestions are applied into the editor, and the change is
  finalized when the post is saved (`editor-applied` → `save_post`).
* Applying a change starts an experiment (when GSC is connected) that compares equal windows before/after,
  adjusted by site-wide movement; verdicts feed `learnings`.
* Every apply/revert/restore is written to `audit_log`.

## 8. Queue, cron and background processing

* `jobs` table queue with priorities, unique keys (dedupe), group keys, delayed jobs, max attempts and
  exponential backoff.
* `Scheduler` registers a 1-minute WP-Cron tick plus hourly/daily/weekly pipelines; the tick drains the
  queue for a time budget under an atomic lock (`add_option` based) with stale-reservation recovery.
* A signed loopback request (HMAC token rotating hourly) kicks the queue immediately after user actions
  so the UI doesn't wait for the next cron tick.
* WP-CLI for a real system cron: `wp seolity queue run [--seconds=50]`, `queue stats|retry`, `audit [--type=incremental]`, `gsc-sync [--days=N]`, `daily`, `health`, `knowledge-sync`, `archive`.
* Pipelines: daily (GSC sync → keywords → recommendations → alerts → health snapshot → briefing →
  experiments), weekly (crawl, competitors, clusters, reports, knowledge sync, archive).

## 9. Crawler and site audit

1. **Seed**: home, all public posts/pages/products/terms (WordPress APIs) + sitemap URLs.
2. **Batch**: parallel fetch (`Requests::request_multiple`, no redirect following, timeouts), store status,
   headers, timing; HTML parsed by `PageParser` (DOMDocument) into title, meta, canonical, robots, headings,
   links, images, schema, hreflang, word count, content hash, simhash.
3. **Checks**: ~60 checks in `Checks::catalog()` across indexability, content, meta, headings, links,
   images, performance, schema, security, internationalization; each with severity and fixability.
4. **Finalize**: inlinks/outlinks, click depth (BFS), exact and near-duplicates (simhash + LSH),
   orphan pages, redirect chains/loops, canonical-to-error, noindex vs sitemap/traffic conflicts, broken
   images/external links, sitemap & robots site-level checks, summary.

`Doctor` turns issues into diagnoses (what / why it matters / how to fix), `Recommendations\Engine`
generates prioritized actions, `HealthScore` computes the explainable score.

## 10. Google Search Console

* OAuth 2.0 Authorization Code with PKCE. The site owner creates their own Google Cloud OAuth client
  (no shared secret ships with the plugin). Redirect URI: `wp-admin/admin-post.php` (handled on the
  `admin_post` hook). Scope: `webmasters.readonly`. State is single-use, user-bound and expires.
* Tokens are encrypted at rest; refresh tokens rotate; `invalid_grant` marks the connection as broken.
* Property selection is validated against the account's site list; disconnect revokes tokens.
* `Sync`: per-day page × query rows with pagination (25k rows/request), bulk upsert, daily totals and
  country/device/search-appearance breakdowns; the first run back-fills `gsc_sync_days` (default 90), later runs re-sync the last 5 days because Google finalizes data late.
* `Analytics`: impression-weighted position, period comparison, winners/losers, new/lost queries, rising/
  declining, CTR opportunities vs. an expected-CTR curve, striking-distance queries, cannibalization
  detection, per-page drilldown, with human-readable insight sentences.

## 11. Knowledge base and GitHub storage

* **Knowledge base (RAG)**: `knowledge/` (bundled Persian + English documents), the same folder on GitHub
  (default repo `benlifoodaiwriter/aria-ai-runner`, path `seolity/knowledge`) kept fresh by the
  `SEOlity knowledge builder` GitHub Action, and user notes. Documents are chunked by `##` headings;
  retrieval scores topic match, term overlap and language. A newer GitHub copy supersedes the bundled
  copy of the same file; identical files are skipped.
* **Unified repository**: "Create & set up everything" puts the runner (default branch), the own knowledge
  base (`seolity/knowledge`) and the data archive (branch `seolity-data`) in one private `<login>/seolity-ai`.
* **Knowledge collection**: `POST /github/knowledge/collect` commits the builder + a site brief (top GSC
  queries and pages, competitors) and dispatches `seolity-knowledge.yml`; the builder (`llm.mjs`) uses
  Claude Code (subscription token), Codex (ChatGPT login) or the Anthropic API, writes
  `knowledge/site/<host>/{playbook,topics,keywords,competitors}.md` and `last-run.json`; `GET` polls the run,
  then syncs. Retrieval always includes this host's site documents with a boost.
* **GitHub data storage**: with a fine-grained token, cold data (old GSC rows, audit logs, reports, AI
  request logs) is archived as compressed JSON to a private repository via the Git Data API (one commit
  per run) and pruned locally to keep the host database small. Reports read archived data transparently
  and data can be restored on demand. Optional contribution of anonymized experiment learnings back to the
  shared knowledge base (opt-in, no URLs or content).

## 12. Security architecture

* **Capabilities**: `seolity_view`, `seolity_ai`, `seolity_apply`, `seolity_manage` (+ `seolity_client`
  read-only role for agencies). Every REST route declares a tier; object-level edit checks on top.
* **Input**: REST args sanitized per type (`sanitize_text_field`, `sanitize_key`, `esc_url_raw`,
  `wp_kses_post` for content); settings validated against a typed schema; SQL always through
  `$wpdb->prepare` (wrapper with `{table}` tokens).
* **Output**: PHP escapes with `esc_html`/`esc_attr`/`esc_url`; the JS UI builds DOM nodes with
  `textContent` (no `innerHTML` for data); HTML previews render in sandboxed iframes without scripts.
* **Secrets**: AI keys, OAuth client secret/tokens, GitHub token encrypted with libsodium secretbox
  (fallback AES-256-GCM) using `SEOLITY_ENCRYPTION_KEY` or derived from WordPress salts; can be defined as
  constants in `wp-config.php`; never returned by the API (only "is set" flags).
* **Outbound requests**: SSRF guard (`Http::is_safe_public_url`) for user-supplied URLs (competitors,
  PageSpeed, inspection), timeouts, size limits, polite crawling (robots.txt, rate limits).
* **CSRF**: REST nonces; admin-post actions use nonces; OAuth uses state + PKCE.
* **Audit**: every change, setting update, connection and destructive action is logged with user and a
  hashed IP (anonymized by default).
* **AI safety**: white-hat policy prompt, guardrails on output, source documents treated as untrusted data,
  budgets, and human approval for risky changes.

## 13. Performance

* Front end: no AI or remote calls; head output from post meta (object cache friendly); redirects matched
  by hash index; regex redirects cached; 404 logging is a single upsert (bots optional).
* Admin: SPA loads data per page through REST; heavy work queued.
* Background: batched crawling with parallel requests, bulk upserts, memory and time budgets per tick,
  unique jobs to prevent duplicate work, caches for sitemap/robots analysis and AI responses.
* Data growth: retention pruning and optional GitHub archiving.

## 14. UI architecture

* Single admin page (`admin.php?page=seolity`) hosting a hash-routed SPA (28 routes grouped into 9 sections;
  the sidebar and the WordPress menu show sections, pages inside a section are pill sub-tabs) written in vanilla
  JS (no build step), using the REST API.
* **Visual language shared with the ARIAGH AI Writer admin**: full-width glass top bar (wordmark, status
  chips, "What should I do today?" CTA, theme toggle); sidebar with AI / Search Console engine cards and
  grouped navigation (Overview, Content & SEO, Analysis, Technical SEO, System) with the same 24px line-icon
  set (`SL.ICONS` / `SL.icon()`); page heads with icons; 16px cards with icon tiles; stat tiles with tinted
  icons; ARIAGH form rows (label + help | control) with toggle switches; floating glass save bar; provider
  cards with monogram badges and an "Active" ribbon; pill tabs; blue palette (`#1D4ED8`) and Vazirmatn
  (Google Fonts, optional via Settings → General).
* Design tokens are CSS variables on `.sl-root` (light by default, dark via `[data-theme]`), logical
  properties give automatic RTL, explicit `dir` on the app root and modals, responsive drawer navigation,
  focus states, `aria-*` labels, reduced-motion support. A custom white-label colour replaces the blue.
* Shared components: cards, KPI tiles, tables with pagination, badges, gauges, SVG charts, modals,
  toasts, tabs, diff viewer (word-level Myers diff with line fallback), sandboxed previews, "Why?"
  explainability modal, version history with restore, subscription-AI waiting indicator.
* Editor integrations: Gutenberg sidebar (wp.plugins), Classic editor button + meta box, Elementor panel
  (`$e` commands), term and user profile fields, WooCommerce product screens; all open the same
  optimizer modal (Original / Suggested / Compare / HTML, scores before/after, keyword and semantic coverage).

## 15. Internationalization and RTL

* Text domain `seolity`; PHP strings via `__()`; JS strings via a generated dictionary
  (`src/Admin/I18n.php`) passed to the SPA, so JS translations work without build tooling.
* Persian (`fa_IR`) shipped complete; `.pot` provided for other languages; UI language can be forced
  (Auto / فارسی / English) independently of the site locale.
* Persian-aware text processing: normalization of Arabic ي/ك, ZWNJ handling, Persian digits, stop words,
  tokenization and pixel-width estimation for SERP previews.

## 16. Compatibility

* **WooCommerce**: product SEO (title, descriptions, short description, attributes, images, schema,
  categories), HPOS compatible (CRUD APIs only).
* **Editors**: Gutenberg, Classic, Elementor (reads `_elementor_data` for analysis; writes through
  Elementor's document API in the editor).
* **SEO plugins**: `MetaStore` reads/writes meta through Yoast, Rank Math, SEOPress or AIOSEO when present,
  and SEOlity's own head output turns off automatically (configurable) to avoid duplicate tags. Extended
  fields: `focus_keywords` (full list, primary first: Rank Math comma list, Yoast `_yoast_wpseo_focuskeywords`,
  SEOPress target keywords), `pillar_content` (Rank Math pillar / Yoast cornerstone), `social_title` and
  `social_description` (Open Graph). Setting only the primary keyword keeps Rank Math's secondaries.
* **GitHub hub** (`Storage\GitHubHub`): token verification (owner, classic scopes from X-OAuth-Scopes,
  expiry header), one-click private repositories: runner (delegates to SubscriptionRunner::setup), knowledge
  (bundled knowledge + builder + `runner/seolity-knowledge.yml`, same layout as the shared repo so the builder
  runs unchanged; optional ANTHROPIC_API_KEY secret) and data archive (write test, enables archiving).
* **Cloudflare relay** (`Integrations\Cloudflare` + `cloudflare/worker.js`): deployed through the Cloudflare
  API as an ES module (KV binding SEOLITY_KV, plain-text SITE_URL/WP_API/MODE/VERSION, secret
  SEOLITY_SHARED_SECRET, workers.dev, cron in push mode). Runner → `POST /mailbox` (HMAC over "ts.body");
  push mode forwards to `/remote/callback`, pull mode keeps it in KV until WordPress calls `/mailbox/list` +
  `/mailbox/ack` (signed). Cron → `POST /remote/heartbeat` (signed) runs the queue and collects results.
  `/diagnose` checks the site from Cloudflare's side (`/remote/ping`). The relay URL is stored as the runner
  secret SEOLITY_RELAY_URL. Mode auto = push for public hosts, pull for localhost/private addresses.
* **Custom API** (`AI\Providers\CustomApi`): presets, OpenAI or Anthropic format, base or full endpoint URL,
  {ACCOUNT_ID} placeholder, auth bearer/x-api-key/api-key/header/query/none, extra headers ({key} template)
  and query parameters, structured output auto = json_schema → json_object → prompt (remembered per
  endpoint+model), max_tokens ↔ max_completion_tokens swap, optional temperature, <think> stripping,
  GET …/models listing.
* **Rank Math** (`Integrations\RankMath`): status (version, Pro, modules), score triage by GSC demand,
  redirection import (exact/regex/contains/start/end → SEOlity redirects), pillar sync from topic clusters.
  In the editor `SL.seoSync()` dispatches to the `rank-math` / `yoast-seo/editor` data stores (guarded
  calls) so the plugin's own save never overwrites what SEOlity wrote; SEOlity's meta box shows keywords
  read-only when a third-party plugin owns them.
* **GSC focus keywords** (`Keywords\FocusKeywords`): per-URL query table with an owner page per query
  (most clicks, then impressions), coverage flags (title/meta/headings/intro/body), question/brand
  detection (fa + en), scoring (demand × ownership × position × shape × brand), near-duplicate removal,
  tips, AI task `keyword_plan`, and a site-wide bulk assignment where one primary query is never given
  to two URLs. All writes go through `ChangeManager` (source `gsc`).
* **Multisite**: per-site tables and settings; uninstall iterates sites.

## 17. Extensibility

| Hook | Type | Use |
|---|---|---|
| `seolity_register_services` | action | Add or replace container services |
| `seolity_register_jobs` | action | Register queue job handlers |
| `seolity_rest_routes` | action | Register extra REST routes in the `seolity/v1` namespace |
| `seolity_ai_providers` | filter | Register AI providers (any class implementing `ProviderInterface`) |
| `seolity_ai_tasks` | filter | Add or override AI tasks (prompt + JSON schema) |
| `seolity_generate_recommendations` | action | Add recommendation generators |
| `seolity_schema_graph` | filter | Filter the JSON-LD graph printed in `<head>` |
| `seolity_autonomous_risks` | filter | Risk levels that may auto-apply in Autonomous mode |
| `seolity_queue_budget` | filter | Seconds per queue tick |
| `seolity_current_project_id` | filter | Active project (multi-project / SaaS) |
| `seolity_faq_heading` | filter | Heading of the visible FAQ block |
| `seolity_change_applied`, `seolity_audit_completed`, `seolity_gsc_synced`, `seolity_experiment_evaluated`, `seolity_alert_raised`, `seolity_job_failed`, `seolity_loaded` | actions | React to events (e.g. send Slack/Telegram notifications) |

## 18. Editions and SaaS path

| Edition | Scope |
|---|---|
| **MVP (this release)** | Everything in this repository: audit + doctor, GSC analytics, AI optimizer everywhere, product/image SEO, changes/versions/undo, experiments + learning, keywords, cannibalization, internal links, clusters, planner, competitors, schema, redirects, 404, sitemap, robots, health score, alerts, daily briefing, chat, bulk AI, rules, reports, knowledge base, GitHub storage, white-label basics, fa/en. |
| **Pro** | Scheduled multi-competitor SERP tracking via SERP API, rank tracker with daily positions, PDF rendering server-side, Slack/Telegram alerts, advanced rules (multi-step workflows), Bing Webmaster integration, GA4 conversions, log-file analysis. |
| **Enterprise / Agency** | Multi-site dashboard over many WordPress installs, client portals and roles, white-label reports per client, SSO, API keys for external tools, data warehouse export (BigQuery), SLA. |

SaaS path: the `projects` table, project-scoped queries, provider abstraction and REST API mean the
same services can run against remote sites. A future SaaS control plane would host the AI, crawler and
GSC workers and talk to a thin connector plugin (same REST contracts) on each site.

## 19. Roadmap

1. 1.0 — current MVP (all modules above).
2. 1.1 — rank tracker (SERP API), Bing Webmaster, Telegram/Slack alerts, GA4.
3. 1.2 — multi-step automation workflows, server-side PDF, log-file analysis.
4. 2.0 — agency multi-site console and SaaS control plane.

## 20. Dependencies

* WordPress ≥ 6.2, PHP ≥ 7.4 (8.x recommended), MySQL 5.7+/MariaDB 10.3+.
* PHP extensions: `dom`, `json`, `mbstring`; `sodium` or `openssl` for encryption.
* Optional: WooCommerce ≥ 7.0, Elementor, an AI provider API key or a local Ollama server, a Google Cloud
  OAuth client (Search Console), a GitHub fine-grained token (knowledge sync / storage), PageSpeed API key.
* No Composer or npm needed at runtime. `tools/knowledge-builder` uses Node 20+ and `@anthropic-ai/sdk`
  (GitHub Actions only).
