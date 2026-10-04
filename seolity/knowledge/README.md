# SEOlity Knowledge Base

Curated, white-hat SEO knowledge that SEOlity injects into AI prompts (retrieval-augmented generation).
Every AI task declares the topics it needs (see `src/AI/Tasks.php`); the plugin retrieves the most
relevant chunks from these files and from the GitHub copy of this folder.

## Layout

```
knowledge/
  en/<topic>.md        English documents
  fa/<topic>.md        Persian documents (Iran-specific guidance included)
  distilled/<lang>/…   Written by the knowledge-builder GitHub Action (tools/knowledge-builder)
  user/<topic>/…       Notes added from the plugin (Knowledge Base → Add note)
  learnings/…          Opt-in, anonymized experiment results contributed by sites
```

## Document format

```markdown
---
title: Human readable title
lang: en            # en | fa
topic: meta         # primary topic used for retrieval (see topics below)
tags: meta, ctr, serp
sources: https://developers.google.com/search/docs/…
updated: 2026-10-01
---

# Title

## Section heading   <- documents are chunked by "##" headings (≈1800 chars per chunk)
```

Topics used by AI tasks: `content`, `on-page`, `eeat`, `semantic`, `meta`, `ctr`, `ecommerce`, `product`,
`schema`, `images`, `accessibility`, `keywords`, `intent`, `competitors`, `content-strategy`,
`topic-clusters`, `cannibalization`, `site-architecture`, `internal-linking`, `faq`, `redirects`,
`experiments`, `reporting`, `serp`. A document is retrieved when its `topic` matches or when one of
its `tags` matches.

## Rules for contributions

1. White-hat only: nothing that recommends keyword stuffing, cloaking, doorway pages, link schemes,
   fake reviews or hidden text.
2. Prefer primary sources (Google Search Central, Bing Webmaster Guidelines, schema.org, W3C/WCAG,
   web.dev) and cite them in `sources`.
3. State guidance as rules an AI can follow ("Do… / Avoid… / Because…"), with concrete thresholds
   only when a primary source supports them.
4. Keep each `##` section self-contained: it may be retrieved alone.
