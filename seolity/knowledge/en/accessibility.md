---
title: Accessibility practices that also help SEO
lang: en
topic: accessibility
tags: accessibility, images, wcag, headings, links
sources: https://www.w3.org/WAI/standards-guidelines/wcag/, https://www.w3.org/WAI/tutorials/images/decision-tree/
updated: 2026-10-01
---

# Accessibility practices that also help SEO

## Overlap between accessibility and SEO
- Text alternatives for images, logical heading structure, descriptive link text, language attributes, captions/transcripts for media and semantic HTML help both assistive technologies and search engines.

## Checklist
- Set the document language (lang="fa" dir="rtl" for Persian pages).
- One H1; headings in order; don't use headings only for styling.
- Link text that makes sense out of context ("Espresso machine buying guide", not "click here").
- Color contrast at least 4.5:1 for body text (WCAG AA).
- Keyboard-accessible menus, filters and dialogs; visible focus states.
- Form inputs with labels; error messages in text.
- Video captions and transcripts (also indexable text).

## Alt text decision tree (WCAG)
- Image contains text → alt repeats the text (unless the text is also nearby).
- Image is a link or button → alt describes the destination/action.
- Image adds information → short description of the information.
- Image is purely decorative → alt="".
- Complex image (chart) → short alt plus a longer description in the page.
