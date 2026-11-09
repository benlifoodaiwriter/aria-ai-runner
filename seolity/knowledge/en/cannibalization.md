---
title: Keyword cannibalization and consolidation
lang: en
topic: cannibalization
tags: cannibalization, site-architecture, canonical, redirects, keywords
sources: https://developers.google.com/search/docs/crawling-indexing/consolidate-duplicate-urls
updated: 2026-10-01
---

# Keyword cannibalization and consolidation

## Definition
- Two or more of your URLs compete for the same query and intent, splitting signals, causing ranking fluctuations (Google alternates URLs) and lower positions than one strong page would achieve.
- Not every overlap is a problem: a category and a guide can both rank for a query with mixed intent; it's a problem when they fluctuate or both rank poorly.

## Detecting it
- In Search Console, the same query receives impressions for several of your pages, with the ranking URL changing over time, and clicks split between them.
- Similar titles/H1s targeting the same keyword; near-duplicate content.

## Fixes (choose per case)
1. Merge: combine the best content into the strongest URL (most links/traffic/best match), 301 redirect the others, update internal links.
2. Differentiate: re-target one page to a different intent/subtopic (change title, H1, content focus) and interlink them.
3. Canonicalize: for necessary near-duplicates (variants, parameters), set rel=canonical to the preferred URL.
4. De-optimize: remove the competing keyword focus from a secondary page; point internal anchors for that keyword to the primary page.
5. Noindex: only for pages that must exist for users but have no search value.

## After the fix
- Monitor the query's position and the single ranking URL for 2–6 weeks.
