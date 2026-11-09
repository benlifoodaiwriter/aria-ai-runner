---
title: Search snippets and click-through rate
lang: en
topic: ctr
tags: ctr, meta, serp, rich-results, search-console
sources: https://developers.google.com/search/docs/appearance/visual-elements-gallery, https://support.google.com/webmasters/answer/7042828
updated: 2026-10-01
---

# Search snippets and click-through rate

## What influences CTR
- Position (the dominant factor), SERP features above the result (ads, AI overviews, featured snippets, local packs, shopping units), brand familiarity, and the snippet itself (title link, URL/breadcrumb, description, rich result enhancements).
- Rich results (review stars, price/availability, breadcrumbs, video thumbnails) can improve CTR. They require valid structured data that matches visible content, and Google decides whether to show them.

## Diagnosing low CTR
- High impressions + good position + low CTR → snippet problem or intent mismatch. Check the actual queries: if most impressions come from queries the page doesn't answer, change targeting rather than the title.
- Low CTR with position > 10 is normal; improve rankings first.
- Branded queries have much higher CTR than generic ones; analyze them separately.

## Search Console metric definitions
- Clicks: clicks from Google results that land on your property.
- Impressions: times a URL appeared in results the user saw (or could see).
- CTR = clicks / impressions.
- Average position: the average topmost position of your site in results; aggregate it weighted by impressions, never as a simple average of rows.
- Data is usually delayed by 2–3 days; anonymized (rare) queries are omitted from query-level reports, so query totals are lower than page totals.
