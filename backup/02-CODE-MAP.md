# نقشه کد — برای هر بخش کجا را بخوانم؟

همه مسیرها نسبت به پوشه `seolity/` هستند، مگر خلافش نوشته شده باشد. «صفحه» یعنی مسیر `#/slug` در رابط و فایلی که `App.pages[slug]` را تعریف می‌کند. «ARCH §» یعنی بخشی از `docs/ARCHITECTURE.md`.

**روش کار:** اول این جدول را ببین، بعد فقط خطوط لازم را بخوان (grep نماد، یا Read با offset/limit). فایل‌های بزرگ را کامل نخوان: `Router.php` (1631 خط)، `SubscriptionRunner.php` (1117)، `Recommendations/Engine.php` (1092)، `Tasks.php` (741)، `ui.js` (1808)، `pages-seo.js` (1286).

## الف) هسته و زیرساخت

| موضوع | فایل اصلی | توضیح / نماد مهم |
|---|---|---|
| ورودی افزونه، نسخه | `seolity.php` | `Version:` خط ۶، `SEOLITY_VERSION` خط ۲۲، `SEOLITY_DB_VERSION` خط ۲۳ |
| ظرف سرویس‌ها | `src/Plugin.php` | `seolity()->x` (lazy factories)، هوک‌ها، `seolity_operation_finished` |
| autoload | `src/Autoloader.php` | PSR-4 بدون Composer |
| تنظیمات و اسرار | `src/Core/Settings.php` | schema تنظیمات، `secret()` (ممکن است آرایه برگرداند) |
| جدول‌ها و migration | `src/Core/Database.php`، `src/Core/Installer.php` | `url_hash`، `hash`، `CREATE TABLE` |
| رمزنگاری | `src/Core/Crypto.php` | AES-256-GCM |
| دسترسی‌ها | `src/Core/Capabilities.php` | VIEW/AI/APPLY/MANAGE |
| HTTP امن | `src/Core/Http.php` | SSRF، درخواست موازی |
| لاگ حسابرسی | `src/Core/AuditLog.php` | |
| REST | `src/Api/Router.php` | `route()` با سطح view/ai/apply/manage |
| صف | `src/Queue/Queue.php`، `Jobs.php`، `Scheduler.php` | `daily_pipeline`، `checkpoint` (لغو) |
| WP-CLI | `src/Cli/Command.php` | |
| منو و بوت SPA | `src/Admin/Admin.php` | `routes()`، `nav()`، `ordered()`، `js_config()` |
| ویرایشگرها | `src/Admin/EditorIntegration.php` + `assets/admin/editor.js`، `gutenberg.js`، `elementor.js` | |
| خروجی سایت | `src/Frontend/Frontend.php`، `Head.php` | فقط وقتی افزونه سئوی دیگری نیست |

## ب) هوش مصنوعی

| موضوع | فایل | نکته |
|---|---|---|
| موتور مرکزی | `src/AI/Engine.php` | `run(task)` |
| تسک‌ها و prompt و schema | `src/AI/Tasks.php` | `chat` (chat@1.3 با `proposals`) |
| اعتبارسنجی خروجی | `src/AI/Schema.php`، `Guardrails.php` | |
| اتصال‌ها و مدل‌ها | `src/AI/ProviderManager.php`، `ProviderRegistry.php`، `Providers/*` | مدل فقط در «تنظیمات هوش مصنوعی» انتخاب می‌شود |
| قدرت استدلال | `src/AI/Effort.php` | پارامتر مخصوص هر سرویس |
| مسیر شبکه | `src/AI/Gateway.php` | `current()` برای sub_* مسیر واقعی را برمی‌گرداند |
| اشتراک (روش cli/runner) | `src/AI/Remote/SubscriptionRunner.php` | `method()`، `complete()`، `local_test()`، `setup()`، `make_public()`، `holds_data()` |
| اجرای CLI روی سرور | `src/AI/Remote/LocalCli.php` → `ProcessRunner.php` | تنها محل اجرای برنامه؛ سقف حافظه |
| پیدا کردن Node.js | `src/AI/Remote/NodeLocator.php` | |
| ورود CLIها | `src/AI/Remote/CliAuth.php` | `verified()`، قفل ۲۰ ثانیه، `sync_runner()` |
| نصب و عیب‌یابی | `src/AI/Remote/CliSetup.php`، `CodexInstaller.php` | Codex بدون npm |
| خطاها | `src/AI/Remote/CliErrors.php` | `classify()`، `redact()` |
| اجراکننده GitHub | `runner/runner.mjs`، `runner/seolity-ai.yml` | در مخزن عمومی کاربر کپی می‌شود |
| رله Cloudflare | `cloudflare/worker.js`، `src/Integrations/Cloudflare.php` | |
| مخزن‌ها و Secrets گیت‌هاب | `src/Storage/GitHubHub.php`، `GitHubClient.php`، `GitHubArchive.php` | `setup_all()` |
| پایگاه دانش (RAG) | `src/Knowledge/KnowledgeBase.php`، `knowledge/fa/`، `knowledge/en/`، `tools/knowledge-builder/*` | |

## ج) قابلیت‌ها (بک‌اند ← صفحه رابط)

| قابلیت | بک‌اند | صفحه رابط (`#/slug` در فایل) | ARCH § |
|---|---|---|---|
| داشبورد | `Health/HealthScore.php`، `Briefing/DailyBriefing.php` | `dashboard` در `app.js` | 14 |
| مسیر راه («امروز چه کار کنم؟») | `Roadmap/Roadmap.php`، `Collector.php`، `Guides.php`، `Recommendations/Engine.php`، `Analysis/ProblemAreas.php` | `actions` در `pages-roadmap.js` | 6 (SEO roadmap) |
| چت سئو | `Chat/ChatService.php`، `Toolbox.php`، `ChatActions.php` | `ai` در `pages-chat.js` | 6 (Chat)، 15c |
| فعالیت زنده | `Activity/Activity.php`، `AiJobs.php` | `activity` در `pages-activity.js` | 6 |
| تغییرات هوش مصنوعی / عملیات | `Operations/Operations.php`، `Verifier.php`، `Agent/Agent.php`، `Content/ChangeManager.php` | `changes` در `app.js`، پنجره‌ها در `pages-ops.js` | 7 |
| آزمایش‌ها | `Experiments/ExperimentService.php`، `Learning.php` | `experiments` در `pages-intel.js` | |
| گزارش‌ها | `Reports/ReportService.php` | `reports` در `pages-intel.js` | |
| اعلان‌ها + ایمیل | `Alerts/AlertService.php` | `alerts` در `pages-intel.js` | 15c |
| پیگیری‌ها | `Watch/FollowUps.php` | داخل `alerts` | 15c |
| اهمیت صفحه | `Content/PageImportance.php` | داخل مسیر راه و چت | 15c |
| تحلیل کامل سایت | `Analysis/FullAnalysis.php` | دکمه در `audit` | |
| ممیزی و خزش | `Crawler/Crawler.php`، `Checks.php`، `PageParser.php`، `Doctor/Doctor.php` | `audit` در `pages-seo.js` | 9 |
| سرچ کنسول | `GSC/OAuth.php`، `Client.php`، `Sync.php`، `Analytics.php`، `Insights.php` | `gsc` در `pages-seo.js` | 10 |
| رقبا و نتایج گوگل | `Competitors/Intel.php`، `CompetitorService.php`، `Serp/Sources.php`، `SerpService.php`، `AiVisibility.php` | `competitors` در `pages-intel.js` و `competitor` و `serp-import` در `pages-comp.js` | 6 |
| پایگاه دانش | `Knowledge/KnowledgeBase.php` | `knowledge` در `pages-admin.js` | 11 |
| محتوا و بهینه‌ساز | `Content/Optimizer.php`، `Analysis/ContentAnalyzer.php`، `Api/ContentList.php`، `Planner/ContentPlanner.php` | `content` در `pages-seo.js` | |
| مقالات تکراری / هم‌نوع‌خواری | `Content/Duplicates.php` | `duplicates` در `pages-tech.js` | |
| محصولات ووکامرس | `Woo/ProductSeo.php` | `products` در `pages-seo.js` | |
| تصاویر | `Images/ImageSeo.php` | `images` در `pages-seo.js` | |
| کلمات کلیدی | `Keywords/KeywordService.php` | `keywords` در `pages-seo.js` | |
| Rank Math و کلمات کانونی | `Integrations/RankMath.php`، `Keywords/FocusKeywords.php`، `Content/MetaStore.php` | `rankmath` در `pages-focus.js` | |
| لینک داخلی | `Links/InternalLinker.php` | `links` در `pages-seo.js` | |
| ریدایرکت | `Redirects/Manager.php` | `redirects` در `pages-intel.js` | |
| ۴۰۴ و بررسی هوش مصنوعی | `Redirects/NotFoundMonitor.php`، `NotFoundTriage.php` | `404` در `pages-intel.js` | |
| ایندکس‌پذیری و بودجه خزش | `Technical/Indexability.php` | `indexability` در `pages-tech.js` | |
| اسکیما | `Schema/SchemaService.php` | `schema` در `pages-intel.js` | |
| نقشه سایت | `Technical/SitemapService.php`، `SiteMap.php` | `sitemap` در `pages-map.js` | |
| robots.txt | `Technical/RobotsService.php` | `robots` در `pages-intel.js` | |
| سرعت (CWV) | `Technical/PageSpeed.php` | داخل `audit` | |
| قوانین و اتوماسیون | `Rules/RulesEngine.php`، `Queue/*`، `Core/AuditLog.php` | `automation` در `pages-admin.js` | 8 |
| موتورهای هوش مصنوعی (CLI) | `AI/Remote/*` | `engines` در `pages-engines.js` | 15b |
| سرور و سرعت / مسیر شبکه | `AI/Gateway.php` | `network` در `pages-network.js` | 6 (gateway) |
| اشتراک، GitHub، Cloudflare | `AI/Remote/SubscriptionRunner.php`، `Storage/GitHubHub.php`، `Integrations/Cloudflare.php` | `subscription`، `github`، `cloudflare` در `pages-connect.js` | 6 |
| راهنمای اتصال | — | `guide` در `pages-guide.js` (منبع `docs/CONNECT.md`) | |
| تنظیمات (۷ صفحه) | `Core/Settings.php` | `settings`، `settings-ai`، `settings-crawler`، `settings-reports`، `settings-technical`، `settings-schema`، `settings-privacy` در `pages-admin.js`؛ پنل اتصال AI = `App.aiConnections` در `pages-ai.js` | |

## د) فایل‌های کمکی رابط

| فایل | محتوا |
|---|---|
| `assets/admin/ui.js` | `SL.*`: `h()`، `api()`، `icon()` و `SL.ICONS`، `tabs()`، `toast()`، `optimizer()`، `markdown()`، `FIELD_LABELS` |
| `assets/admin/app.js` | پوسته (`shell`)، `navTile`/`navItem`، `route()`، `App.header`، `App.load`، `App.card`، `App.kpi`، داشبورد، `changes` |
| `assets/admin/charts.js` | نمودارها |
| `assets/admin/app.css` | استایل برنامه (`.sl-mapcard`، `.sl-nav__tile`، موبایل زیر ۹۶۰px) |
| `assets/admin/editor.css` | design tokens (`--sl-*`، کپی رنگ‌های AI Writer) + کامپوننت‌های مشترک |

## ه) ابزارها و اسناد

| فایل | کار |
|---|---|
| `tools/i18n/build.py` + `fa-*.tsv` | ساخت ترجمه |
| `tools/docs/build_features.py` + `features_data.py` | ساخت `docs/FEATURES.md` |
| `tools/docs/build_connect.mjs` | ساخت `docs/CONNECT.md` |
| `tools/knowledge-builder/*` | خلاصه‌سازی دانش سئو با Claude (اجرا در GitHub Actions) |
| `readme.txt` | changelog رسمی |
| ریشه مخزن: `runner/runner.mjs`، `.github/workflows/aria-writer.yml` | مال افزونه AI Writer (به آن دست نزن) |
| ریشه مخزن: `.github/workflows/seolity-knowledge.yml` | ساخت دانش SEOlity |
| ریشه مخزن: `CLAUDE.md`، `.mcp.json`، `.claude/skills/*` | تنظیمات code-review-graph |

## و) درخت کامل فایل‌ها (تولید خودکار)
ستون اول تعداد خطوط است و توضیح از اولین خط مستند هر فایل گرفته شده است.

```text
   28  .claude/settings.json                                      
   23  .claude/skills/debug-issue/SKILL.md                        
   24  .claude/skills/explore-codebase/SKILL.md                   
   24  .claude/skills/refactor-safely/SKILL.md                    
   27  .claude/skills/review-changes/SKILL.md                     
   66  .github/workflows/aria-writer.yml                          ARIAGH.AIWRITER – Subscription Runner
   80  .github/workflows/seolity-knowledge.yml                    SEOlity – SEO knowledge builder
    2  .gitignore                                                 Added by code-review-graph
   13  .mcp.json                                                  
   45  CLAUDE.md                                                  
  118  PROJECT_MAP.md                                             PROJECT_MAP — SEOlity (ARIAGH.SEOLITY)
   10  README.md                                                  Aria AI Writer – Subscription Runner
   34  docs/SESSION-ROADMAP.md                                    SEOlity — session roadmap (read this first; replaces the old chat history)
  910  runner/runner.mjs                                          ARIAGH.AIWRITER – Subscription Runner (GitHub Actions)
    2  seolity/.gitignore                                         
   88  seolity/README.md                                          ARIAGH.SEOLITY — AI SEO Intelligence for WordPress
  834  seolity/assets/admin/app.css                               
  861  seolity/assets/admin/app.js                                SEOlity admin SPA — shell, router, shared page helpers and core pages
  280  seolity/assets/admin/charts.js                             SEOlity charts — tiny dependency-free SVG charts (line, bar, donut, sparkline).
  398  seolity/assets/admin/editor.css                            SEOlity design system — shared components (used by the SPA and editor screens).
  466  seolity/assets/admin/editor.js                             SEOlity editor integrations: Classic editor, WooCommerce short description,
   82  seolity/assets/admin/elementor.js                          SEOlity ↔ Elementor bridge: a floating "Optimize with SEOlity AI" button
  107  seolity/assets/admin/gutenberg.js                          SEOlity Gutenberg sidebar (no JSX / no build step: wp.element.createElement).
  404  seolity/assets/admin/pages-activity.js                     Live activity & Done timeline.
  603  seolity/assets/admin/pages-admin.js                        SEOlity admin pages: Automation (approval workflow, rules engine, queue,
  323  seolity/assets/admin/pages-ai.js                           Settings → AI: two connection methods, each in its own box.
  347  seolity/assets/admin/pages-chat.js                         AI SEO Chat.
  540  seolity/assets/admin/pages-comp.js                         Competitor workspace: real Google results per tracked query (stored as history), one panel per
  824  seolity/assets/admin/pages-connect.js                      SEOlity admin pages for connections that run outside WordPress:
  303  seolity/assets/admin/pages-engines.js                      AI engines → Installation & diagnostics: one place to see whether Claude Code and Codex can
  190  seolity/assets/admin/pages-focus.js                        SEOlity admin page: Rank Math & focus keywords — GSC-driven focus keyword
  475  seolity/assets/admin/pages-guide.js                        SEOlity admin page: Connection guide — step-by-step instructions with
  793  seolity/assets/admin/pages-intel.js                        SEOlity admin pages: Competitors, Experiments, Redirects, 404 Monitor,
  513  seolity/assets/admin/pages-map.js                          Site map explorer: every URL of the site — crawl, XML sitemap, WordPress and Search Console �
  295  seolity/assets/admin/pages-network.js                      SEOlity "Server & speed" page: where the host is (Iran / abroad), how AI and Google
  390  seolity/assets/admin/pages-ops.js                          Operations UI: "Do it" popup → (optional instructions) → AI prepares → preview with
  563  seolity/assets/admin/pages-roadmap.js                      SEO roadmap — the "What should I do today?" page.
 1286  seolity/assets/admin/pages-seo.js                          SEOlity admin pages: Site Audit + SEO Doctor + Health, Search Console,
  372  seolity/assets/admin/pages-tech.js                         Indexability & crawl budget: which URL groups are technically indexable vs. which should be,
 1808  seolity/assets/admin/ui.js                                 SEOlity UI toolkit (no build step, no framework).
  271  seolity/cloudflare/worker.js                               SEOlity relay — Cloudflare Worker (deployed automatically by the SEOlity plugin).
  618  seolity/docs/ARCHITECTURE.md                               SEOlity — Architecture
  896  seolity/docs/CONNECT.md                                    SEOlity — Connection guide / راهنمای اتصال
  940  seolity/docs/FEATURES.md                                   SEOlity — Feature Catalog
   49  seolity/knowledge/README.md                                SEOlity Knowledge Base
  217  seolity/readme.txt                                         Simpler navigation, like the ARIAGH AI Writer admin: every page is its own item in the WordPres
   15  seolity/runner/README.md                                   SEOlity – Subscription AI runner
  366  seolity/runner/runner.mjs                                  SEOlity – Subscription AI runner (GitHub Actions)
   66  seolity/runner/seolity-ai.yml                              SEOlity – Subscription AI runner
   80  seolity/runner/seolity-knowledge.yml                       SEOlity – SEO knowledge builder
   64  seolity/seolity.php                                        Plugin Name:       ARIAGH.SEOLITY
  131  seolity/src/AI/AbstractProvider.php                        Shared provider plumbing (settings, HTTP, error normalization).
   39  seolity/src/AI/Deferred.php                                Thrown when an AI request was handed to an asynchronous runner (subscription
  130  seolity/src/AI/Effort.php                                  Reasoning effort per AI connection, translated into each provider's own documented parameter:
  429  seolity/src/AI/Engine.php                                  The central AI SEO Engine used by every feature.
  455  seolity/src/AI/Gateway.php                                 Network gateway: how AI and Google requests leave this server.
   94  seolity/src/AI/Guardrails.php                              Quality & safety guardrails around every AI call.
   39  seolity/src/AI/ProviderInterface.php                       Contract every AI provider implements.
  510  seolity/src/AI/ProviderManager.php                         AI Provider Manager: the single place that knows which AI connections exist, whether they
   84  seolity/src/AI/ProviderRegistry.php                        AI provider registry. Add providers with the `seolity_ai_providers` filter:
  142  seolity/src/AI/Providers/Anthropic.php                     Anthropic Claude provider (Messages API, native structured outputs).
  405  seolity/src/AI/Providers/CustomApi.php                     Custom API: use any AI service with exactly the settings its documentation gives.
  108  seolity/src/AI/Providers/Gemini.php                        Google Gemini provider (generateContent with JSON schema output).
   65  seolity/src/AI/Providers/GitHubModels.php                  GitHub Models — AI models served by GitHub (models.github.ai), called directly from WordPress
   98  seolity/src/AI/Providers/Ollama.php                        Local LLM via Ollama (/api/chat with JSON-schema "format").
  152  seolity/src/AI/Providers/OpenAI.php                        OpenAI provider (Chat Completions + json_schema response format).
   74  seolity/src/AI/Providers/OpenRouter.php                    OpenRouter (OpenAI-compatible, hundreds of models behind one key).
   40  seolity/src/AI/Providers/Subscription.php                  Base for subscription providers: requests run on GitHub Actions with the
   43  seolity/src/AI/Providers/SubscriptionClaude.php            Claude Pro/Max subscription via Claude Code on GitHub Actions.
   39  seolity/src/AI/Providers/SubscriptionCodex.php             ChatGPT Plus/Pro subscription via OpenAI Codex CLI on GitHub Actions.
  355  seolity/src/AI/Remote/CliAuth.php                          Logins of the local CLIs, kept apart per engine (Claude working never depends on Codex and the
  191  seolity/src/AI/Remote/CliErrors.php                        Error codes for the local CLIs, the rules that recognise them in CLI output, and redaction.
  487  seolity/src/AI/Remote/CliSetup.php                         Diagnose and (where the host really allows it) install the official Claude Code / Codex CLI
  260  seolity/src/AI/Remote/CodexInstaller.php                   Installs the official Codex CLI without npm.
  362  seolity/src/AI/Remote/LocalCli.php                         Subscription AI on THIS server: runs the official Claude Code / OpenAI Codex CLI directly from
  199  seolity/src/AI/Remote/NodeLocator.php                      Finds every Node.js on this server that PHP can really run — not just what the PHP worker's
  308  seolity/src/AI/Remote/ProcessRunner.php                    The one place SEOlity starts programs (node, npm, the official Claude Code / Codex CLIs).
 1117  seolity/src/AI/Remote/SubscriptionRunner.php               Subscription AI through GitHub Actions.
  163  seolity/src/AI/Schema.php                                  JSON-Schema builder + validator for structured AI output.
  741  seolity/src/AI/Tasks.php                                   Versioned task catalog for the AI SEO Engine.
  667  seolity/src/Activity/Activity.php                          Live activity & timeline: everything SEOlity does on the site, as it happens.
  192  seolity/src/Activity/AiJobs.php                            AI edits that run on the server, not in the browser tab.
  300  seolity/src/Admin/Admin.php                                Admin shell: menu, SPA bootstrap, secure exports, admin bar badge,
  313  seolity/src/Admin/EditorIntegration.php                    Classic Editor (content + WooCommerce short description), Gutenberg sidebar,
 1964  seolity/src/Admin/I18n.php                                 GENERATED by tools/i18n/build.py — do not edit by hand.
   34  seolity/src/Admin/Icons.php                                Server-rendered line icons (same 24px / 1.75 stroke set as SL.ICONS in
  635  seolity/src/Agent/Agent.php                                Every content change goes through the change manager (versioned, undo in AI Center → AI
  317  seolity/src/Alerts/AlertService.php                        AI Alerts (section 29): traffic/CTR/ranking drops, lost pages & keywords,
  459  seolity/src/Analysis/ContentAnalyzer.php                   Deterministic, explainable on-page SEO scoring (no AI required).
  247  seolity/src/Analysis/FullAnalysis.php                      Full-site analysis: one button that refreshes every section from the same data — crawl,
  123  seolity/src/Analysis/ProblemAreas.php                      Where the site's SEO problems are: technical / indexing, on-page, internal links, content,
  242  seolity/src/Analysis/Text.php                              Unicode / Persian-aware text utilities used by every analyzer.
  141  seolity/src/Api/ContentList.php                            Content inventory for the Content screen (posts, pages, CPTs, terms) with
 1631  seolity/src/Api/Router.php                                 REST API (namespace seolity/v1).
   32  seolity/src/Autoloader.php                                 PSR-4 autoloader for the SEOlity namespace (no Composer required).
  142  seolity/src/Briefing/DailyBriefing.php                     health, competitor moves, new errors, experiments and top actions. Uses AI
  207  seolity/src/Chat/ChatActions.php                           Turns what the AI chat proposes into real items of the plugin:
  370  seolity/src/Chat/ChatService.php                           AI SEO Chat (section 31): answers grounded in the site's real data.
  372  seolity/src/Chat/Toolbox.php                               Everything the AI chat can look at: a compact snapshot of the whole site and plugin, a map of
  120  seolity/src/Cli/Command.php                                WP-CLI commands (ops-friendly; ideal with a real system cron).
  356  seolity/src/Competitors/CompetitorService.php              Competitor Intelligence + Content Gap (sections 10–11).
  519  seolity/src/Competitors/Intel.php                          Competitor intelligence built on real Google results: every tracked query is searched through
  337  seolity/src/Content/ChangeManager.php                      Change tracking, approval workflow, version history and undo (sections 12, 33).
  153  seolity/src/Content/ContentEvents.php                      WordPress content lifecycle hooks: incremental crawling, background scoring,
  810  seolity/src/Content/Duplicates.php                         Duplicate, near-duplicate and cannibalizing articles: detection (content overlap, titles,
  408  seolity/src/Content/MetaStore.php                          Reads/writes SEO meta (title, description, focus keyword, canonical, robots)
  316  seolity/src/Content/Objects.php                            Uniform read/write access to every editable WordPress object field
  498  seolity/src/Content/Optimizer.php                          AI Content Optimizer (section 2) + bulk AI (section 32).
  244  seolity/src/Content/PageImportance.php                     How much a page matters, before SEOlity (or its AI) suggests changing it.
   72  seolity/src/Core/AuditLog.php                              Append-only audit log of every sensitive action (settings, AI changes,
  104  seolity/src/Core/Capabilities.php                          Granular capabilities + the "SEOlity Client" role for agency client access.
   80  seolity/src/Core/Crypto.php                                Authenticated encryption for secrets at rest.
  908  seolity/src/Core/Database.php                              Database access layer: table registry, schema definition and query helpers.
  187  seolity/src/Core/Http.php                                  HTTP helpers: JSON requests, parallel fetches and SSRF protection.
  147  seolity/src/Core/Installer.php                             Activation, deactivation and schema upgrades.
  349  seolity/src/Core/Settings.php                              Typed settings with schema-driven sanitization.
  342  seolity/src/Crawler/Checks.php                             Audit check catalog + page-level evaluation + issue persistence.
  716  seolity/src/Crawler/Crawler.php                            Full-site / incremental crawler (section 7).
  309  seolity/src/Crawler/PageParser.php                         HTML → SEO facts extractor (DOMDocument, UTF-8/RTL safe).
  160  seolity/src/Doctor/Doctor.php                              SEO Doctor (section 8): turns raw findings into diagnoses —
  328  seolity/src/Experiments/ExperimentService.php              SEO Experiment System (section 13).
  114  seolity/src/Experiments/Learning.php                       AI learning from results (section 14): aggregates evaluated experiments into
   84  seolity/src/Frontend/Frontend.php                          Front-end integration. Deliberately tiny: no queries unless needed, no
  203  seolity/src/Frontend/Head.php                              Twitter Cards and JSON-LD — only when SEOlity owns SEO output (no other SEO
  388  seolity/src/GSC/Analytics.php                              Advanced Search Console analytics over the local warehouse (section 6).
  111  seolity/src/GSC/Client.php                                 Google Search Console API client (Webmasters v3 + URL Inspection v1).
  305  seolity/src/GSC/Insights.php                               Deep Search Console evidence for the AI: finds the queries and pages a question is about and
  234  seolity/src/GSC/OAuth.php                                  Google OAuth 2.0 for Search Console (Authorization Code + PKCE, offline access).
  160  seolity/src/GSC/Sync.php                                   Incremental Search Console sync into local tables (queue-driven).
  280  seolity/src/Health/HealthScore.php                         Explainable SEO Health Score (section 27).
  324  seolity/src/Images/ImageSeo.php                            AI Image SEO (section 4): crawls the media library, detects missing/bad/
  463  seolity/src/Integrations/Cloudflare.php                    Cloudflare relay: deploys and manages the SEOlity worker on the site
  272  seolity/src/Integrations/RankMath.php                      Rank Math bridge. SEOlity works *inside* Rank Math instead of competing
  659  seolity/src/Keywords/FocusKeywords.php                     GSC-driven focus keywords: picks the keyword set each page should target
  310  seolity/src/Keywords/KeywordService.php                    Keyword intelligence (sections 15–16): GSC-driven keyword universe, AI
  328  seolity/src/Knowledge/KnowledgeBase.php                    SEO Knowledge Base (RAG for the AI engine).
  364  seolity/src/Links/InternalLinker.php                       Internal Linking AI (section 17).
  568  seolity/src/Operations/Operations.php                      SEO operations: the one execution path for every correction, whoever starts it
  297  seolity/src/Operations/Verifier.php                        Proves that an applied change is really in effect — never trusts "the request was accepted".
  264  seolity/src/Planner/ContentPlanner.php                     Content Planner (section 19) + Topic Clusters (section 18).
  296  seolity/src/Plugin.php                                     Plugin kernel: a tiny lazy service container + module bootstrap.
   13  seolity/src/Queue/Cancelled.php                            Thrown at a safe point (Queue::checkpoint) when the owner cancelled the running task.
  322  seolity/src/Queue/Jobs.php                                 Registry of background job handlers.
  573  seolity/src/Queue/Queue.php                                Database-backed job queue with a complete task lifecycle.
  168  seolity/src/Queue/Scheduler.php                            WP-Cron integration: a 1-minute tick that drains the queue, plus the daily
 1092  seolity/src/Recommendations/Engine.php                     Smart SEO Recommendations (section 9) + AI Action Center data.
  363  seolity/src/Redirects/Manager.php                          Redirect manager (section 25): 301/302/307/410/451, regex, bulk import/export,
  157  seolity/src/Redirects/NotFoundMonitor.php                  filters bots & scanners, and suggests the best redirect target using slug
  226  seolity/src/Redirects/NotFoundTriage.php                   AI triage of logged 404s: for every URL the evidence that decides its value (Search Console
  235  seolity/src/Reports/ReportService.php                      SEO Reports (section 36) with white-label branding: daily/weekly/monthly,
  340  seolity/src/Roadmap/Collector.php                          Roadmap facts: one snapshot of everything SEOlity knows about the site, gathered
  261  seolity/src/Roadmap/Guides.php                             Step-by-step guides for roadmap items: exactly where to click in the WordPress
  502  seolity/src/Roadmap/Roadmap.php                            SEO roadmap ("What should I do today?").
  292  seolity/src/Rules/RulesEngine.php                          SEO Rules Engine (section 34): IF <conditions> THEN <actions>.
  364  seolity/src/Schema/SchemaService.php                       Schema intelligence (section 21): JSON-LD graph generation + validation of
  216  seolity/src/Serp/AiVisibility.php                          Visibility in AI search: asks AI answer engines a tracked query with live web search and
  190  seolity/src/Serp/SerpService.php                           Live SERP data (SerpApi) + AI SERP analysis.
  245  seolity/src/Serp/Sources.php                               Where Google results come from. Every source returns the same normalized shape
  300  seolity/src/Storage/GitHubArchive.php                      GitHub data storage: keeps the WordPress database small by moving cold data
  441  seolity/src/Storage/GitHubClient.php                       Minimal GitHub REST client (Contents + Git Data APIs).
  547  seolity/src/Storage/GitHubHub.php                          One place for everything SEOlity does on GitHub, with one-click setup:
  484  seolity/src/Technical/Indexability.php                     Indexability & crawl budget audit.
  124  seolity/src/Technical/PageSpeed.php                        Core Web Vitals via PageSpeed Insights API (lab + CrUX field data).
  265  seolity/src/Technical/RobotsService.php                    Robots.txt intelligence (section 24): Google-compatible rule matching,
  334  seolity/src/Technical/SiteMap.php                          Site map explorer: every URL of the site in one place — from the crawl, the XML sitemap,
  265  seolity/src/Technical/SitemapService.php                   Sitemap intelligence (section 23).
  215  seolity/src/Watch/FollowUps.php                            Follow-ups: "look at this again on date X and tell me what happened".
  258  seolity/src/Woo/ProductSeo.php                             AI Product SEO for WooCommerce (section 3).
   79  seolity/tools/docs/build_connect.mjs                       Build docs/CONNECT.md (Persian + English) from the in-app connection guide
   93  seolity/tools/docs/build_features.py                       
  805  seolity/tools/docs/features_data.py                        Feature catalog data for build_features.py. Edition "1.0" = implemented; "Pro"/"Ent" = roadmap.
  182  seolity/tools/i18n/build.py                                
  360  seolity/tools/i18n/fa-1.tsv                                
  120  seolity/tools/i18n/fa-10.tsv                               
  104  seolity/tools/i18n/fa-11.tsv                               
   44  seolity/tools/i18n/fa-12.tsv                               
   45  seolity/tools/i18n/fa-13.tsv                               
  282  seolity/tools/i18n/fa-14.tsv                               
  223  seolity/tools/i18n/fa-15.tsv                               
  864  seolity/tools/i18n/fa-16.tsv                               
  154  seolity/tools/i18n/fa-17.tsv                               
   87  seolity/tools/i18n/fa-18.tsv                               
   10  seolity/tools/i18n/fa-19.tsv                               
  360  seolity/tools/i18n/fa-2.tsv                                
  335  seolity/tools/i18n/fa-3.tsv                                
  260  seolity/tools/i18n/fa-4.tsv                                
  252  seolity/tools/i18n/fa-5.tsv                                
   38  seolity/tools/i18n/fa-6.tsv                                
   47  seolity/tools/i18n/fa-7.tsv                                
    7  seolity/tools/i18n/fa-8.tsv                                
    4  seolity/tools/i18n/fa-9.tsv                                
    2  seolity/tools/knowledge-builder/.gitignore                 
  391  seolity/tools/knowledge-builder/build.mjs                  SEOlity knowledge builder.
  165  seolity/tools/knowledge-builder/llm.mjs                    Model access for the SEOlity knowledge builder.
   10  seolity/tools/knowledge-builder/package.json               
   47  seolity/tools/knowledge-builder/sources.json               
   56  seolity/uninstall.php                                      SEOlity uninstall.
```

پوشه‌هایی که در فهرست بالا خلاصه شده‌اند:
- `seolity/knowledge/fa/` (25 فایل) و `seolity/knowledge/en/` (24 فایل): متن‌های دانش سئو برای RAG.
- `seolity/languages/`: فایل‌های ترجمه تولیدی (.pot/.po/.mo).
- `seolity/assets/img/`: لوگو و آیکون منو.
