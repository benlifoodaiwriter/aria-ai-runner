---
title: Site architecture, crawling and indexing
lang: en
topic: site-architecture
tags: site-architecture, technical, crawling, indexing, canonical, sitemap, robots
sources: https://developers.google.com/search/docs/crawling-indexing, https://developers.google.com/search/docs/crawling-indexing/robots/intro, https://developers.google.com/search/docs/crawling-indexing/sitemaps/overview
updated: 2026-10-01
---

# Site architecture, crawling and indexing

## Architecture
- Shallow and logical: important pages within ~3 clicks of the homepage; hierarchical categories; breadcrumbs that reflect the hierarchy.
- Every indexable page should have internal links pointing to it (no orphans) and be included in the XML sitemap.
- Consistent URL patterns, lowercase, hyphens, no session IDs; one canonical URL per piece of content (choose https, one host www/non-www, trailing-slash convention).

## Indexing controls
- robots.txt controls crawling, not indexing. A URL blocked in robots.txt can still be indexed (without content) if linked. To keep a page out of the index, allow crawling and use noindex (meta robots or X-Robots-Tag). Google doesn't support noindex in robots.txt.
- Don't block CSS/JS needed to render pages.
- rel=canonical is a strong hint; the canonical target must be indexable (200, not noindex, not redirected) and self-canonicals are recommended.
- Noindex pages should not be in the sitemap; sitemap URLs should return 200 and be canonical.

## Sitemaps
- Up to 50,000 URLs or 50 MB uncompressed per sitemap file; use a sitemap index for more. Keep lastmod accurate (only change it when content changes significantly). Submit in Search Console and reference in robots.txt.

## Crawl efficiency
- Matters mostly for large sites (tens of thousands of URLs+). Reduce duplicate parameter URLs, infinite calendars/facets, redirect chains and soft 404s; keep server fast and stable.

## HTTP status codes
- 200 OK for live pages; 301/308 for permanent moves; 302/307 for temporary; 404 for not found; 410 for permanently removed; 5xx errors reduce crawling if persistent. Soft 404s (200 with "not found" content) should return a real 404/410.

## JavaScript and rendering
- Ensure important content and links are in the rendered HTML; use real <a href> links; avoid content that appears only after user interaction.

## Pagination and facets
- Google no longer uses rel=next/prev. Make paginated pages crawlable with links, give each page a self-canonical, and avoid canonicalizing all pages to page 1.
