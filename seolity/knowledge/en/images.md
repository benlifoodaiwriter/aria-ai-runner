---
title: Image SEO and accessible alternative text
lang: en
topic: images
tags: images, accessibility, alt-text, performance, core-web-vitals
sources: https://developers.google.com/search/docs/appearance/google-images, https://www.w3.org/WAI/tutorials/images/, https://web.dev/articles/optimize-lcp
updated: 2026-10-01
---

# Image SEO and accessible alternative text

## Alt text
- Describe what the image shows and why it's there, in the page language, concisely (usually under ~125 characters). Example: "Silver Breville espresso machine steaming milk in a stainless jug".
- Include the product name/model when the image shows that product; include the keyword only if it truly describes the image. Never stuff keywords or repeat the same alt on every image.
- Decorative images (spacers, purely visual flourishes) get an empty alt (alt="") so screen readers skip them.
- Functional images (icon links/buttons) describe the action ("Search", "Open cart"), not the picture.
- Avoid "image of"/"picture of" prefixes and file names like "IMG_1234" or "DSC0001" as alt text.

## File names, titles and captions
- Descriptive, hyphenated file names (breville-barista-express-front.webp) help Google understand images.
- Captions are read by users and search engines; use them when context is useful. The title attribute has little SEO value.

## Performance
- Serve modern formats (WebP/AVIF), correctly sized responsive images (srcset/sizes), compressed.
- Always set width and height (or aspect-ratio) to prevent layout shift (CLS).
- Lazy-load below-the-fold images, but never the LCP/hero image; give it fetchpriority="high".
- Use an image sitemap or ensure images are discoverable in HTML (not only via CSS backgrounds).

## Image indexing
- Put important images near relevant text, on pages that are indexable. Use structured data image properties (Product.image, Article.image) with large, crawlable images (≥ 1200 px wide recommended for Discover/articles).
