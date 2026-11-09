---
title: Structured data (schema.org) best practices
lang: en
topic: schema
tags: schema, structured-data, json-ld, rich-results, faq, product
sources: https://developers.google.com/search/docs/appearance/structured-data/intro-structured-data, https://developers.google.com/search/docs/appearance/structured-data/sd-policies, https://developers.google.com/search/blog/2023/08/howto-faq-changes
updated: 2026-10-01
---

# Structured data (schema.org) best practices

## General rules
- Prefer JSON-LD. Markup must describe content that is visible on the page; marking up hidden or misleading information violates structured data policies and can cause manual actions.
- Valid markup makes a page eligible for rich results but does not guarantee them.
- Use the most specific type (Product, Article/BlogPosting/NewsArticle, LocalBusiness subtype, Recipe, Event, VideoObject, BreadcrumbList, Organization, Person).
- Connect entities with @id references in one @graph (WebSite → WebPage → Article → author Person → publisher Organization).
- Avoid duplicate conflicting markup from several plugins; one source of truth.

## Feature status notes (as of 2026)
- FAQ rich results are shown only for well-known, authoritative government and health websites (since August 2023). FAQPage markup remains valid schema and harmless, but don't promise FAQ stars to ordinary sites; visible FAQs still help users and can be cited by AI answers.
- HowTo rich results were deprecated (2023).
- The sitelinks search box was retired (November 2024); WebSite + SearchAction markup is no longer needed for it.
- Product, Review snippet, Breadcrumb, Article, Video, Organization (logo), LocalBusiness, Event, Recipe and Merchant listing features remain important.

## Required and recommended properties (common types)
- Product: name; plus offers or review or aggregateRating. Recommended: image, description, brand, sku, gtin/mpn, offers.price, offers.priceCurrency, offers.availability.
- Article: headline, image, datePublished, dateModified, author (Person/Organization with url).
- BreadcrumbList: itemListElement with position, name, item.
- Organization: name, url, logo, sameAs, contactPoint; LocalBusiness: address, telephone, openingHours, geo.

## Validation
- Test with the Rich Results Test and monitor Search Console enhancement reports. Fix "invalid" items first (they block rich results), then "warnings" for missing recommended properties.
