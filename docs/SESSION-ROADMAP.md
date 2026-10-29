# SEOlity — session roadmap (read this first; replaces the old chat history)

## State
- Plugin: `seolity/` (WordPress, PHP PSR-4 `SEOlity\`, no-build JS SPA in `assets/admin/`). Version **1.12.0**, DB schema **1.7.0**.
- Branch: `claude/beautiful-ride-7ilxpf` (push with `git push -u origin claude/beautiful-ride-7ilxpf`). No PR unless asked. `main` belongs to another tool (ARIAGH.AIWRITER) — do not touch.
- Release ZIP: `git archive --format=zip --prefix=seolity/ -o <scratch>/seolity-X.zip HEAD:seolity` → send to user; never commit ZIPs.
- Commits end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>` and `Claude-Session: …`; never put model ids in code/commits.
- User writes Persian; answer in Persian. Uses exact UI terms: «مسیر راه», «مقالات تکراری و شبه تکراری», «رفع هم نوع خواری», «هوش مصنوعی با API», «اشتراکی», «جمع آوری اطلاعات», «راهنمایی».
- Open decision: user has NOT decided whether to commit `.mcp.json`, `.claude/`, `CLAUDE.md`, `.gitignore` (code-review-graph config). Leave them untracked; the stop hook warns about them — just remind the user of the two options.

## Where things are (load only what a task needs)
- Services map: `src/Plugin.php` (lazy `seolity()->x`). REST: `src/Api/Router.php` (`seolity/v1`, tiers view/ai/apply/manage). Jobs: `src/Queue/Jobs.php` (+ `daily_pipeline`). Settings/secrets: `src/Core/Settings.php`. Schema + migrations: `src/Core/Database.php`, `src/Core/Installer.php`.
- AI: `src/AI/Engine.php`, tasks/prompts `src/AI/Tasks.php`, providers `src/AI/Providers/*`, connections `src/AI/ProviderManager.php`, effort `src/AI/Effort.php`, subscription runner `src/AI/Remote/*` + `runner/runner.mjs`.
- Operations (preview→approve→apply→verify→undo): `src/Operations/Operations.php`, `Verifier.php`. Canonical issues/roadmap: `src/Recommendations/Engine.php`, `src/Roadmap/*`, `src/Analysis/FullAnalysis.php`, `ProblemAreas.php`.
- GSC deep data for AI: `src/GSC/Insights.php`. Chat tools: `src/Chat/Toolbox.php`, `ChatService.php`.
- Competitors/SERP: `src/Competitors/Intel.php`, `src/Serp/Sources.php`, `SerpService.php`, `AiVisibility.php`.
- Duplicates/cannibalization: `src/Content/Duplicates.php`. Indexability: `src/Technical/Indexability.php`. Site map explorer: `src/Technical/SiteMap.php`. 404 AI review: `src/Redirects/NotFoundTriage.php`. Experiments/edit history: `src/Experiments/ExperimentService.php`.
- UI modules: `assets/admin/pages-*.js` (registered in `src/Admin/Admin.php`), styles `app.css`.
- i18n: add Persian lines to `tools/i18n/fa-16.tsv`, run `SEOLITY_I18N_MISSING=<file> python3 tools/i18n/build.py` until `missing: 0`. Docs: `readme.txt` changelog, `tools/docs/features_data.py` → `build_features.py`, `docs/ARCHITECTURE.md`.

## Test environment (sandbox scratchpad, recreate if gone)
- `$S/up.sh` starts MySQL + WP (127.0.0.1:8899, admin/admin123) — run it backgrounded: `(bash $S/up.sh > $S/up.log 2>&1 &)`.
- Real Rank Math + Elementor installed; fixtures mu-plugin answers Anthropic (`sk-ant-api03-TESTVALID`), SerpApi, Serper, Gemini grounding and task fixtures.
- Regression: `wp eval-file $S/{ops-test,lifecycle-test,provider-test,rest}.php --allow-root` (11/12/11 PASS, ~78×200). Browser smoke: `node $S/smoke.mjs` (Playwright, Chromium at /opt/pw-browsers/chromium).

## Token-saving workflow
1. `rtk` wraps shell output automatically (hook). 2. Structure questions → code-review-graph (`code-review-graph update` / MCP tools). 3. Read files with offsets / Grep, never whole large files (Router.php, Tasks.php, Engine.php are big). 4. Edit with small python replace scripts or Edit; `php -l` / `node --check` after each change. 5. One commit per feature, push at release.

## Ideas not done yet (only if the user asks)
- Real-world validation with the user's own keys (Serper/SerpApi, Gemini, Claude) — all external calls were tested only with documented-shape fixtures.
- Backlink data source (none integrated; off-site area is marked "not measurable").
