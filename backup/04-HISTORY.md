# تاریخچه پروژه (بازسازی‌شده از کامیت‌ها و changelog)

> متن کامل گفتگوهای قبلی در دسترس نیست. این تاریخچه از ۵۲ کامیت گیت، changelog هر نسخه (`05-CHANGELOG.md`) و خلاصه‌هایی که در پایان گفتگوها نوشته شده‌اند (`/PROJECT_MAP.md` و `/docs/SESSION-ROADMAP.md`) ساخته شده است. هر تاریخ تقریباً یک یا چند گفتگو است.

## خلاصه مسیر

| تاریخ | نسخه‌ها | کار اصلی |
|---|---|---|
| 2026-10-04 | 1.0.0, 1.1.0 (و AI Writer 1.8.0) | شروع پروژه: هسته افزونه، موتور AI، سرچ کنسول، خزنده، REST، رابط SPA، ترجمه فارسی، پایگاه دانش، کاتالوگ ۵۶۷ قابلیت، هوش مصنوعی اشتراکی از راه GitHub Actions (1.0 تا 1.1). |
| 2026-10-05 | 1.2.0, 1.3.0 | طراحی دوباره رابط به زبان بصری AI Writer، آیکون‌های خطی به جای ایموجی، ادغام Rank Math، کلمات کلیدی کانونی از GSC، راهنمای اتصال (1.2 تا 1.3). |
| 2026-10-08 | 1.4.0, 1.5.0 | ویزارد اشتراک، هاب GitHub، رله Cloudflare، API سفارشی، نام تجاری ARIAGH.SEOLITY، ناوبری ۹ بخشی با تب (1.4 تا 1.5). |
| 2026-10-10 | 1.6.0, 1.7.0 | منطقه هاست (ایران/خارج)، مسیرهای شبکه، اجراکننده گرم GitHub، مسیر راه با حافظه، راهنمای گام‌به‌گام و dock کارهای AI (1.6 تا 1.7). |
| 2026-10-19 | 1.8.0 | عامل AI، فعالیت زنده، چت ماندگار، حالت Direct واقعی (1.8.0). |
| 2026-10-25 | 1.9.0 | عملیات با پیش‌نمایش، تأیید، راستی‌آزمایی و برگشت؛ مدیر اتصال‌های AI؛ ایندکس‌پذیری؛ مقالات تکراری؛ تحلیل کامل سایت (1.9.0). |
| 2026-10-26 | 1.9.1, 1.9.2, 1.10.0 | رفع ریدایرکت‌هایی که ۴۰۴ می‌دادند، رفع خطای ایندکس‌پذیری، بررسی ۴۰۴ با AI (1.9.1 تا 1.10.0). |
| 2026-10-29 | 1.11.0, 1.12.0, 1.12.1 | داده کامل GSC برای AI، اثر ویرایش‌ها، فضای کار رقبا، قدرت استدلال برای هر اتصال، منابع نتایج گوگل، دیده‌شدن در جست‌وجوی AI، چت با دسترسی کامل، نواحی مشکل، هم‌نوع‌خواری پیشرفته، نقشه سایت، کارت‌های بزرگ؛ نقشه جلسه و code-review-graph (1.11 تا 1.12.1). |
| 2026-10-30 | 1.13.0, 1.13.1 | CLIهای محلی (Claude Code و Codex) روی هاست: پیدا کردن Node.js، اجراکننده مرکزی، خطاهای طبقه‌بندی‌شده، ورود، نصب Codex بدون npm (1.13.0 تا 1.13.1). |
| 2026-10-31 | 1.13.2, 1.13.3 | auto فقط با CLI آزموده‌شده، قفل محدود، اجراکننده عمومی با داده خصوصی (1.13.2 تا 1.13.3). |
| 2026-11-02 | 1.13.4, 1.14.0 | چت با داده سطری GSC، برچسب درست مسیر، چتی که کار انجام می‌دهد (کار، تغییر، پیگیری، اعلان)، مرکز اعلان‌ها با ایمیل، اهمیت صفحه (1.13.4 تا 1.14.0). |
| 2026-11-07 | 1.15.0 | نقشه پروژه برای گفتگوی جدید؛ منو مثل AI Writer: هر صفحه آیتم جدا و بدون تب داخل تب، و تنظیمات در ۷ صفحه (1.15.0). |

## همه کامیت‌ها به ترتیب زمان

### 2026-10-04
_شروع پروژه: هسته افزونه، موتور AI، سرچ کنسول، خزنده، REST، رابط SPA، ترجمه فارسی، پایگاه دانش، کاتالوگ ۵۶۷ قابلیت، هوش مصنوعی اشتراکی از راه GitHub Actions (1.0 تا 1.1)._

- `332e3e6` ARIAGH.AIWRITER 1.8.0: update runner/runner.mjs
- `6583e36` SEOlity: core platform, AI engine, GSC, crawler, intelligence services and REST API
- `c587da6` SEOlity: admin SPA, editor integrations, design system and Persian translations
- `cc34743` SEOlity: canonical URL identity, crawler/health accuracy fixes, extensible AI providers
- `e55eff6` SEOlity: REST error statuses, readable knowledge note paths, mobile drawer fixes
- `8c26284` SEOlity: SEO knowledge base, GitHub knowledge builder, E-E-A-T/schema/SERP AI, fixes
- `5cd2db6` SEOlity: 567-feature catalog, readme.txt and README
- `420b833` SEOlity: subscription AI (Claude Pro/Max, ChatGPT Plus/Pro) through GitHub Actions
- `8a190d0` SEOlity 1.1.0: version bump, changelog, ignore Python caches

### 2026-10-05
_طراحی دوباره رابط به زبان بصری AI Writer، آیکون‌های خطی به جای ایموجی، ادغام Rank Math، کلمات کلیدی کانونی از GSC، راهنمای اتصال (1.2 تا 1.3)._

- `1a62283` SEOlity 1.2.0: admin redesign in the ARIAGH AI Writer visual language
- `2b402ed` Replace emoji glyphs with line icons across the admin and editors
- `cfcdfc8` SEOlity 1.3.0: Rank Math integration, GSC focus keywords, connection guide

### 2026-10-08
_ویزارد اشتراک، هاب GitHub، رله Cloudflare، API سفارشی، نام تجاری ARIAGH.SEOLITY، ناوبری ۹ بخشی با تب (1.4 تا 1.5)._

- `85f7766` SEOlity 1.4.0: subscription wizard, GitHub hub, Cloudflare relay, custom API
- `5620ae4` SEOlity 1.5.0: ARIAGH.SEOLITY branding, simpler navigation, real subscription deploy, knowledge collection, AI for media

### 2026-10-10
_منطقه هاست (ایران/خارج)، مسیرهای شبکه، اجراکننده گرم GitHub، مسیر راه با حافظه، راهنمای گام‌به‌گام و dock کارهای AI (1.6 تا 1.7)._

- `640c795` SEOlity 1.6.0: host region, Iran/abroad routing, Cloudflare proxy, warm GitHub runner, GitHub storage for all
- `3ec728d` SEOlity 1.7.0: on-demand SEO roadmap with memory, step-by-step guides, non-intrusive AI task dock

### 2026-10-19
_عامل AI، فعالیت زنده، چت ماندگار، حالت Direct واقعی (1.8.0)._

- `c31e9ea` SEOlity 1.8.0: AI agent, live activity, persistent chat, strict Direct mode

### 2026-10-25
_عملیات با پیش‌نمایش، تأیید، راستی‌آزمایی و برگشت؛ مدیر اتصال‌های AI؛ ایندکس‌پذیری؛ مقالات تکراری؛ تحلیل کامل سایت (1.9.0)._

- `7d4d420` SEOlity 1.9 (WIP): reliable task lifecycle and AI provider manager
- `6a53e38` SEOlity 1.9 (WIP): operations with preview/approval/verify/rollback, canonical issues, Rank Math authoritative writes, bulk 404, navigation
- `1e4e586` Indexability audit: scan, Rank Math-aware findings, verified fixes via operations
- `3ed66ca` Indexability page: findings with live state, mechanism explainer, previewed fixes; compact dock on phones
- `e68f957` Duplicate & cannibalizing articles: detection, standing + per-merge instructions, AI consolidation with verified merge → noindex → 301 → internal links, dependency guard
- `42f5133` Full-site analysis orchestrator, roadmap with ordered categories and verified checkmarks, CLI diagnose/install, queue kicks on REST polling, fix /issues route collision
- `3f53485` i18n: regenerate catalog for new strings
- `36ebd01` 1.9.0: Persian translations for all new strings, background jobs write in the admin's language, docs and changelog

### 2026-10-26
_رفع ریدایرکت‌هایی که ۴۰۴ می‌دادند، رفع خطای ایندکس‌پذیری، بررسی ۴۰۴ با AI (1.9.1 تا 1.10.0)._

- `ae246c2` 1.9.1: diagnose redirects that still answer 404 (cache purge + recheck, owner match, switched-off redirects), duplicates limited to blog articles
- `6dd1f10` 1.9.2: indexability audit no longer fails on taxonomies without a front-end link
- `fd5f5b7` 1.10.0: AI review of 404s — evidence-based 301 / 410 / keep decisions with crawl-budget verdict, verified and undoable

### 2026-10-29
_داده کامل GSC برای AI، اثر ویرایش‌ها، فضای کار رقبا، قدرت استدلال برای هر اتصال، منابع نتایج گوگل، دیده‌شدن در جست‌وجوی AI، چت با دسترسی کامل، نواحی مشکل، هم‌نوع‌خواری پیشرفته، نقشه سایت، کارت‌های بزرگ؛ نقشه جلسه و code-review-graph (1.11 تا 1.12.1)._

- `ae7b9df` AI gets query- and page-level Search Console history: weekly series, page swaps, sharpest drop, edits near the drop, home page numbers
- `239f7b2` Edit impact: WordPress-editor edits and past revisions become measured experiments, per-query before/after, outcomes in the AI's query context
- `85afee8` Competitor workspace: Google results database (SERP API snapshots with history), per-competitor panel, per-query AI comparison with steps on the roadmap, estimated Search Console
- `4222a31` AI power level per connection in each provider's real parameter (effort, reasoning_effort, reasoning, thinking, think, CLI env/config); models chosen in one place only
- `2f2fac5` 1.11.0: Search Console detail for the AI, edit-history impact, competitor workspace on a Google results database, AI power per connection; translations and docs
- `05fd192` Google results sources (Serper, SearchApi, SerpApi, DataForSEO, Google Programmable Search, no-key browser bookmarklet) and AI-search visibility (Gemini grounding, Claude/OpenAI web search, Perplexity)
- `f581df4` Chat with full access: plugin snapshot, navigation map with deep links, 19 data tools chosen by an AI planning step and keywords
- `2209d81` Where the problems are: weighted breakdown by SEO area on the roadmap, AI ranks the areas in its diagnosis
- `5b973bb` Advanced cannibalization: TF-IDF topic similarity, Search Console overlap and weekly page swaps, search intent, severity score, strategy (merge / differentiate / canonical / internal links) with AI retargeting and canonical operations
- `746bece` Site map explorer: every URL from crawl, XML sitemap, WordPress and Search Console coloured by indexability, pixel map, folder tree, list with CSV export, daily trend, URL detail with live re-check; rebuilt daily. Phones keep room above the task dock
- `5c590ce` 1.12.0: Google results sources incl. no-key browser import, AI-search visibility, chat with full access, problem areas, advanced cannibalization, site map explorer; translations and docs
- `49b6289` Session roadmap for continuing with minimal context
- `cc25400` Add code-review-graph config, skills and project instructions
- `3a4a730` Site map and competitor UI: large horizontal cards in the dashboard style (1.12.1)

### 2026-10-30
_CLIهای محلی (Claude Code و Codex) روی هاست: پیدا کردن Node.js، اجراکننده مرکزی، خطاهای طبقه‌بندی‌شده، ورود، نصب Codex بدون npm (1.13.0 تا 1.13.1)._

- `c122f6a` Local AI CLIs: Node.js discovery, central process runner, classified errors, login management, diagnostics wizard (1.13.0)
- `c0a6e80` Codex install without npm (fixes memory limit on LiteSpeed/CloudLinux), light install polling, model choice on AI Engines, more card layouts (1.13.1)
- `ff1b729` Document CodexInstaller

### 2026-10-31
_auto فقط با CLI آزموده‌شده، قفل محدود، اجراکننده عمومی با داده خصوصی (1.13.2 تا 1.13.3)._

- `7ceaf01` Auto routing uses a local CLI only after a passed test, falls back to GitHub; bounded CLI lock; sync refreshed Codex login to runner (1.13.2)
- `c3a108b` Public runner repository (free Actions minutes), private data repository; fail-safe visibility switch (1.13.3)

### 2026-11-02
_چت با داده سطری GSC، برچسب درست مسیر، چتی که کار انجام می‌دهد (کار، تغییر، پیگیری، اعلان)، مرکز اعلان‌ها با ایمیل، اهمیت صفحه (1.13.4 تا 1.14.0)._

- `b063734` Chat gets per-page/per-query Search Console rows on every route; route label reflects direct CLI (1.13.4)
- `2d11724` AI chat acts (roadmap tasks, changes for review & apply, follow-ups, notifications), follow-ups, notifications center with e-mail, page importance (1.14.0)

### 2026-11-07
_نقشه پروژه برای گفتگوی جدید؛ منو مثل AI Writer: هر صفحه آیتم جدا و بدون تب داخل تب، و تنظیمات در ۷ صفحه (1.15.0)._

- `7a8dbc4` Project map for continuing in a new chat
- `b77cf0d` Navigation like ARIAGH AI Writer: every page its own menu item, no section tabs; settings split into pages (1.15.0)

## گفتگوی 2026-11-07 تا 2026-11-09 (همین گفتگو)

1. **«آماده باش برای ادامه پروژه»:** `PROJECT_MAP.md` خوانده و وضعیت گزارش شد (نسخه 1.14.0 و کارهای باز).
2. **«بخش‌ها و تب‌ها همه مثل AI Writer جدا باشند، تب داخل تب نباشد، گرافیک کپی همین باشد، همه بخش‌ها در منوی اصلی باشند»** (کاربر فایل `ARIAGH-AIWRITER-1.9.1-OWNER-Unlimited.zip` را به عنوان مرجع فرستاد):
   - بررسی شد: رنگ‌ها، فونت و کارت‌های SEOlity از قبل کپی AI Writer بودند. تفاوت در منو بود: AI Writer همه صفحه‌ها را تک‌تک در منو دارد (`class-aaw-admin.php::tabs()` با گروه‌ها) و دو کاشی بزرگ بالای سایدبار؛ SEOlity ۸ بخش داشت و هر بخش یک نوار زیرتب داشت.
   - نسخه **1.15.0**: `Admin::nav()` (منبع واحد)، منوی وردپرس با همه صفحه‌ها، سایدبار تخت با عنوان گروه، دو کاشی (چت و مسیر راه)، حذف نوار زیرتب، تقسیم تنظیمات به ۷ صفحه، وضعیت GSC و GitHub در نوار بالا، ترجمه `fa-19.tsv`.
   - آزمایش در مرورگر شبیه‌سازی‌شده (دسکتاپ و موبایل، بدون خطا). روی وردپرس واقعی آزمایش نشد. کامیت `b77cf0d`.
   - سؤال باز از کاربر: آیا تب‌های فیلتر داخل صفحه‌ها هم آیتم جدای منو شوند؟
3. **«بکاپ کامل از چت‌ها، فایل‌ها، اشکالات، اشتباهات، باگ‌ها، ساختار و نمودار برای گفتگوی جدید»:** همین پوشه `backup/` ساخته شد و یک ZIP بکاپ کامل برای کاربر فرستاده شد.
