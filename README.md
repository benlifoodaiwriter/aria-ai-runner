# ARIAGH.SEOLITY — AI SEO plugin for WordPress (SEOlity)

**فارسی:** افزونه سئوی هوش مصنوعی برای وردپرس؛ فارسی و راست‌به‌چپ. هر روز به صاحب سایت می‌گوید «امروز چه کار کنم؟»، مشکلات سئو را با داده واقعی (Search Console، خزش سایت، رقبا) پیدا می‌کند و اصلاح‌ها را **فقط با تأیید کاربر** انجام می‌دهد: پیش‌نمایش، تأیید، اجرا، راستی‌آزمایی و امکان برگشت.

**English:** An AI SEO plugin for WordPress (Persian/RTL first). Daily SEO roadmap, AI chat grounded in the site's real data, Search Console warehouse, crawler/audit, competitors, Rank Math integration, and every change goes through preview → approval → apply → verify → undo.

- Version: **1.15.0** · DB schema: **1.7.0** · PHP ≥ 7.4 · WordPress ≥ 6.2
- Plugin code: [`seolity/`](seolity/) · Install package: `git archive --format=zip --prefix=seolity/ -o seolity-1.15.0.zip HEAD:seolity`

## Start here / از اینجا شروع کنید

| Read | What |
|---|---|
| [`CLAUDE.md`](CLAUDE.md) | Instructions for Claude (Claude Code reads it automatically) |
| [`PROJECT_MAP.md`](PROJECT_MAP.md) | Short, always-current project map (Persian) |
| [`backup/00-START-HERE.md`](backup/00-START-HERE.md) | Full handoff: reading order, restore, prompt for a new chat |
| [`backup/01-PROJECT-OVERVIEW.md`](backup/01-PROJECT-OVERVIEW.md) | Architecture with diagrams |
| [`backup/02-CODE-MAP.md`](backup/02-CODE-MAP.md) | Where to look for each feature + full file tree |
| [`backup/03-LESSONS.md`](backup/03-LESSONS.md) | Mistakes not to repeat, fixed bugs, decisions, user rules |
| [`backup/04-HISTORY.md`](backup/04-HISTORY.md) | History of all work sessions/versions |
| [`seolity/docs/ARCHITECTURE.md`](seolity/docs/ARCHITECTURE.md) | Detailed architecture (English) |
| [`seolity/docs/FEATURES.md`](seolity/docs/FEATURES.md) · [`seolity/docs/CONNECT.md`](seolity/docs/CONNECT.md) | Features · connection guide |
| [`seolity/readme.txt`](seolity/readme.txt) | WordPress readme + changelog |

## Repository layout

```text
seolity/            The WordPress plugin (everything that ships)
  seolity.php       Entry point, version
  src/              PHP (namespace SEOlity\, PSR-4, own autoloader)
  assets/admin/     No-build admin SPA (JS + CSS)
  runner/           GitHub Actions runner the plugin deploys for subscription AI
  cloudflare/       Cloudflare Worker relay the plugin deploys
  knowledge/        Curated SEO knowledge (fa/en) for the AI (RAG)
  languages/        Generated translations (fa_IR)
  tools/            i18n build, docs build, knowledge builder
  docs/             ARCHITECTURE / FEATURES / CONNECT
backup/             Complete handoff & backup documentation
extras/             Files that are NOT part of SEOlity (see extras/README.md)
CLAUDE.md, PROJECT_MAP.md, .claude/, .mcp.json   Instructions/tooling for Claude Code
```

## Origin
This branch (`seolity-complete`) is a self-contained copy of the SEOlity work that was developed on branch `claude/beautiful-ride-7ilxpf` of `benlifoodaiwriter/aria-ai-runner` (that repository was originally the GitHub runner of the sister plugin ARIAGH.AIWRITER). Full git history is kept.
