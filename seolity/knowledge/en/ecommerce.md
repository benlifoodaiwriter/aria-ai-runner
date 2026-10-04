---
title: E-commerce site structure, faceted navigation and trust
lang: en
topic: ecommerce
tags: ecommerce, product, category, faceted-navigation, trust, site-architecture
sources: https://developers.google.com/search/docs/specialty/ecommerce, https://developers.google.com/search/docs/crawling-indexing/crawling-managing-faceted-navigation
updated: 2026-10-01
---

# E-commerce site structure, faceted navigation and trust

## Structure
- Home → categories → subcategories → products, with breadcrumbs. Link to top categories from the main navigation; link to products from categories with crawlable pagination.
- Each product should have one canonical URL regardless of the category path used to reach it.

## Faceted navigation
- Filters (color, size, price, sort) can create millions of URLs. Index only facet combinations with real search demand (e.g., "red running shoes"), give them unique titles/content; block or canonicalize the rest. Avoid indexing sort orders and session parameters.

## Trust and conversion signals that also support SEO
- Clear prices, shipping costs and delivery times, return policy, warranty, secure checkout, real contact details, genuine reviews, business registration details.

## Seasonal and promotional pages
- Keep evergreen URLs for recurring events (e.g., /black-friday/) and update them yearly instead of creating new URLs each year.
