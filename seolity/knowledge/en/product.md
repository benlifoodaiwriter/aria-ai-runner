---
title: E-commerce and WooCommerce product SEO
lang: en
topic: product
tags: product, ecommerce, woocommerce, schema, images, category
sources: https://developers.google.com/search/docs/specialty/ecommerce, https://developers.google.com/search/docs/appearance/structured-data/product, https://developers.google.com/search/docs/specialty/ecommerce/write-high-quality-reviews
updated: 2026-10-01
---

# E-commerce and WooCommerce product SEO

## Product titles
- Pattern: Brand + Model/Product type + key differentiator (size, capacity, color, material). Keep model numbers exact. Avoid stuffing several keywords or promotional words ("best", "cheapest") into the product name.
- The SEO title can add a short benefit or "Buy … | Store" when it fits within ≈ 600 px.

## Product descriptions
- Write unique descriptions; do not copy manufacturer text (it appears on hundreds of sites).
- Structure: one-sentence value summary → who it's for → key features as benefits → full specifications (table) → what's in the box → usage/care → warranty, shipping and returns → FAQs.
- Short description (WooCommerce excerpt): 2–4 lines with the core benefit and 3–5 key specs; it appears near the price and add-to-cart button.
- Include real attributes customers filter by (size, compatibility, material) and natural variations of the product name.

## Category pages
- Categories usually target the most valuable transactional keywords. Add a concise, useful intro (what to consider when choosing), keep products visible above the fold, and add a buying guide or FAQ below the grid.
- Avoid indexing thin filter/sort/parameter combinations; canonicalize or noindex faceted URLs that don't have search demand.

## Structured data for products
- Product snippets (review/price stars) and merchant listings (Shopping surfaces) need Product markup with name, image, and offers (price, priceCurrency in ISO 4217, availability, url). Add brand, sku, gtin/mpn, aggregateRating/review only if real reviews are shown on the page.
- Merchant listing extras: shippingDetails, hasMerchantReturnPolicy, priceValidUntil, itemCondition.
- Variable products: use ProductGroup with hasVariant/variesBy or one Offer per variant URL.
- Prices in structured data must match the visible price; out-of-stock products should say OutOfStock, not be removed abruptly.

## Out-of-stock and discontinued products
- Temporarily out of stock: keep the page, mark availability, offer alternatives and back-in-stock notification.
- Permanently discontinued: 301 to the closest replacement or category if a clear equivalent exists; otherwise keep an informative page (specs, successor) or return 410 if it has no value.

## Product images
- Multiple high-quality images (angles, scale, in use), descriptive file names and alt text (brand, model, view/color), compressed WebP/AVIF, width/height attributes, the main image not lazy-loaded.

## Reviews
- Encourage genuine customer reviews; never write fake reviews or mark up reviews not visible on the page. Self-serving reviews of your own business are not eligible for review stars on Organization/LocalBusiness.
