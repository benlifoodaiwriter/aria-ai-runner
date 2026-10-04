---
title: Internal linking best practices
lang: en
topic: internal-linking
tags: internal-linking, site-architecture, anchors, orphan-pages
sources: https://developers.google.com/search/docs/crawling-indexing/links-crawlable
updated: 2026-10-01
---

# Internal linking best practices

## Why it matters
- Internal links help crawlers discover pages, pass signals (PageRank) between pages and tell search engines and users what the target page is about through anchor text.

## Anchor text
- Descriptive and concise; describes the target page ("espresso machine cleaning guide"), not "click here" or "read more".
- Vary anchors naturally; exact-match anchors on every link look unnatural. Use the text that already exists in the sentence when possible instead of inserting new keyword phrases.
- Don't link the same target many times from one page; the first contextual link carries the clearest signal.

## Where to add links
- From pages with authority/traffic to important pages that need a boost (striking-distance pages, new pages, money pages).
- Between topically related pages (same cluster); supporting articles → category/product pages; pillar ↔ supporting pages.
- Fix orphan pages (no inlinks) and deep pages (more than 3–4 clicks from home).
- Keep a reasonable number of links per page; prioritize usefulness for the reader.

## Technical rules
- Use crawlable <a href="…"> links (not JS click handlers without href). Link to canonical, 200-status URLs — update links that point to redirects or 404s.
- Use rel="nofollow" for internal links rarely (e.g., login/cart); it doesn't save crawl budget for indexable pages.

## Rules for AI link suggestions
- Suggest links only where the target genuinely helps the reader at that point.
- Prefer existing phrases in the text as anchors; never change the meaning of a sentence to fit a link.
- Avoid adding links inside headings, image captions with existing links, or existing anchors.
