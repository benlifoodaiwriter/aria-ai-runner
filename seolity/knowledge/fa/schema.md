---
title: بهترین روش‌های داده ساختاریافته (اسکیما)
lang: fa
topic: schema
tags: schema, structured-data, json-ld, rich-results, faq, product
sources: https://developers.google.com/search/docs/appearance/structured-data/sd-policies, https://developers.google.com/search/blog/2023/08/howto-faq-changes
updated: 2026-10-01
---

# بهترین روش‌های داده ساختاریافته (اسکیما)

## قواعد کلی
- JSON-LD را ترجیح دهید. نشانه‌گذاری باید محتوای قابل مشاهده صفحه را توصیف کند؛ نشانه‌گذاری اطلاعات پنهان یا گمراه‌کننده خلاف سیاست‌هاست و ممکن است جریمه دستی داشته باشد.
- اسکیمای معتبر صفحه را واجد ریچ‌ریزالت می‌کند اما نمایش را تضمین نمی‌کند.
- دقیق‌ترین نوع را انتخاب کنید (Product، Article/BlogPosting، زیرنوع LocalBusiness، Recipe، Event، VideoObject، BreadcrumbList، Organization، Person).
- موجودیت‌ها را با @id در یک @graph به هم وصل کنید.
- از چند افزونه که اسکیمای متناقض تولید می‌کنند پرهیز کنید؛ یک منبع واحد.

## وضعیت قابلیت‌ها (تا ۲۰۲۶)
- ریچ‌ریزالت FAQ از اوت ۲۰۲۳ فقط برای سایت‌های دولتی و سلامت معتبر نمایش داده می‌شود. FAQPage همچنان اسکیمای معتبر و بی‌ضرر است، اما برای سایت‌های عادی وعده نمایش FAQ در نتایج ندهید؛ پرسش‌های متداول قابل مشاهده همچنان به کاربر کمک می‌کنند.
- ریچ‌ریزالت HowTo در ۲۰۲۳ منسوخ شد.
- جعبه جست‌وجوی سایت‌لینک (Sitelinks search box) در نوامبر ۲۰۲۴ حذف شد.
- Product، Review snippet، Breadcrumb، Article، Video، Organization (لوگو)، LocalBusiness، Event، Recipe و Merchant listing همچنان مهم‌اند.

## ویژگی‌های لازم و توصیه‌شده
- Product: name به‌علاوه offers یا review یا aggregateRating. توصیه‌شده: image، description، brand، sku، gtin/mpn، offers.price، offers.priceCurrency، offers.availability.
- Article: headline، image، datePublished، dateModified، author (با url).
- BreadcrumbList: itemListElement با position، name، item.
- Organization: name، url، logo، sameAs؛ LocalBusiness: address، telephone، openingHours، geo.

## اعتبارسنجی
- با ابزار Rich Results Test تست کنید و گزارش‌های Enhancements سرچ کنسول را پایش کنید. ابتدا موارد «نامعتبر» (مانع ریچ‌ریزالت) را رفع کنید، سپس «هشدارها».
