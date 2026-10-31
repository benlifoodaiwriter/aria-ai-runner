---
title: SEO experiments and measuring the impact of changes
lang: en
topic: experiments
tags: experiments, measurement, reporting, search-console, ctr
sources: https://support.google.com/webmasters/answer/7576553
updated: 2026-10-01
---

# SEO experiments and measuring the impact of changes

## Before/after measurement
- Record the change date and exactly what changed (title, description, content, links, schema).
- Compare equal-length windows before and after (e.g., 14 or 28 days), excluding the first few days after the change while Google re-crawls. Search Console data lags ~2–3 days.
- Use the same weekdays in both windows to neutralize weekly patterns.

## Controls and noise
- Adjust for site-wide movement: compare the page's change with the rest of the site (or a control group of similar unchanged pages) over the same windows. If the whole site rose 15%, a page that rose 15% didn't improve because of the change.
- Watch for confounders: Google core updates, seasonality, promotions, outages, tracking changes, ranking volatility.
- Small pages need longer windows: with fewer than ~100 clicks per window, results are mostly noise. Prefer impressions/position for low-traffic pages.

## Metrics to evaluate
- Title/description changes: CTR at comparable position, then clicks.
- Content changes: impressions, number of ranking queries, average position, clicks.
- Internal links/technical fixes: crawl/indexing status, impressions.

## Verdicts
- Positive: clear improvement beyond site-wide/control change and noise → keep.
- Negative: clear drop beyond noise → revert and record the learning.
- Inconclusive: extend the window or accept as neutral; don't over-interpret.

## Learning loop
- Aggregate results by change type (e.g., "adding FAQ sections", "benefit-led titles") to learn what works for this specific site, and feed those learnings into future recommendations.
