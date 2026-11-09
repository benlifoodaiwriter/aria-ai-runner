# CLAUDE.md — instructions for Claude working on SEOlity

This repository contains **ARIAGH.SEOLITY (SEOlity)**, an AI SEO plugin for WordPress (Persian/RTL), in `seolity/`.
The owner is not a programmer: **always answer in Persian (فارسی), simply and honestly** (say what was tested and what was not).

## First steps in every new chat
1. Read `PROJECT_MAP.md` (short, current map). Follow its section «راهنمای کار با این نقشه».
2. Read `backup/00-START-HERE.md`. Before any important change read `backup/03-LESSONS.md` (mistakes not to repeat, fixed bugs, rules).
3. For "where is X?" use `backup/02-CODE-MAP.md`; for architecture/diagrams `backup/01-PROJECT-OVERVIEW.md`; deep detail `seolity/docs/ARCHITECTURE.md`.
4. Read code only when needed and only the needed lines (grep a symbol, then Read with offset/limit). Large files: `src/Api/Router.php`, `src/AI/Remote/SubscriptionRunner.php`, `src/Recommendations/Engine.php`, `src/AI/Tasks.php`, `assets/admin/ui.js`, `assets/admin/pages-seo.js`.

## Branch & release rules
- Work on the branch you were given for the session (in this repository the complete line of work is `seolity-complete`; in the original repository it was `claude/beautiful-ride-7ilxpf`). Create no PR unless asked.
- After each PHP edit `php -l <file>`; after each JS edit `node --check <file>`.
- Every release: bump `seolity/seolity.php` (`Version:` and `SEOLITY_VERSION`), `seolity/readme.txt` (`Stable tag` + changelog), Persian translations (`seolity/tools/i18n/fa-NN.tsv`, then `python3 tools/i18n/build.py` from `seolity/` until `missing: 0`), then build the ZIP
  `git archive --format=zip --prefix=seolity/ -o <scratchpad>/seolity-X.Y.Z.zip HEAD:seolity` and send it to the owner. **Never commit ZIPs.**
- After every important change update the matching section of `PROJECT_MAP.md` (and `backup/03-LESSONS.md` for new lessons/bugs).
- Never put model identifiers in code, commits or docs. Never commit secrets, e-mails or licensed files.

## Product rules (from the owner)
- Look & feel = copy of the sister plugin **ARIAGH AI Writer**: blue `#1D4ED8`, Vazirmatn, 16px cards, line icons, RTL, mobile. Big horizontal cards (`.sl-mapcard`).
- Navigation: every page is its own menu item (WordPress menu + sidebar both from `Admin::nav()` in `seolity/src/Admin/Admin.php`). **No tabs inside tabs.**
- Nothing is applied to the site without the owner's approval (chat proposals go through «بررسی و اعمال»). Never collect passwords/cookies, never scrape Google, never bypass network limits.

## Optional tool: code-review-graph
`.mcp.json` and `.claude/settings.json` configure the optional `code-review-graph` MCP server (`uvx code-review-graph serve`); the hooks do nothing if it is not installed. If its tools are available, use them to narrow scope (`semantic_search_nodes_tool`, `query_graph_tool` callers_of/callees_of/tests_for, `get_impact_radius_tool`, `detect_changes_tool`), then verify in the source — the source always wins. If they are not available, use Grep/Read.
