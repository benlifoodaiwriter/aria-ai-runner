---
title: Redirects, 404s and URL changes
lang: en
topic: redirects
tags: redirects, 404, technical, migrations, site-architecture
sources: https://developers.google.com/search/docs/crawling-indexing/301-redirects, https://developers.google.com/search/docs/crawling-indexing/site-move-with-url-changes
updated: 2026-10-01
---

# Redirects, 404s and URL changes

## Choosing a redirect type
- 301/308 (permanent): content moved for good — Google transfers signals and indexes the target.
- 302/307 (temporary): the original URL should stay indexed (A/B tests, short promotions). A long-lived 302 is eventually treated like a permanent one.
- 410 (gone): content removed on purpose with no replacement; drops from the index a bit faster than 404.
- Meta refresh and JavaScript redirects work but server-side redirects are preferred.

## Choosing the target
- Redirect to the most relevant equivalent page (same product's successor, same topic article, parent category). Redirecting everything to the homepage is treated as a soft 404.
- If nothing relevant exists, a 404/410 with a helpful page (search, popular categories) is better than an irrelevant redirect.

## Hygiene
- Avoid chains (A → B → C) and loops; point every redirect directly to the final URL. Googlebot follows up to 10 hops but each hop wastes crawl and time.
- Update internal links to the final URL instead of relying on redirects.
- Keep redirects for at least a year after a URL change (ideally permanently).

## 404 monitoring
- Prioritize 404s by hits, referrers (external backlinks are most valuable) and whether the URL used to have traffic. Ignore junk bot probes (wp-login variants, .env, random strings).
- Fix the source of internal broken links rather than only redirecting.

## Changing slugs and migrations
- Changing a URL resets nothing if a 301 is in place, but expect temporary fluctuation. Map old → new 1:1, update sitemap and internal links, monitor 404s and Search Console coverage.
