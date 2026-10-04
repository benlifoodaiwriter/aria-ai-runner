# SEOlity — AI SEO Intelligence for WordPress

<div dir="rtl">

**سئولیتی** یک «سیستم‌عامل سئو» مبتنی بر هوش مصنوعی برای وردپرس و ووکامرس است: سایت را می‌سنجد، تصمیم می‌گیرد، با پیش‌نمایش و تأیید و امکان بازگشت تغییر می‌دهد و از نتایج یاد می‌گیرد.

## امکانات اصلی

- **ممیزی کامل سایت** با خزنده واقعی و حدود ۶۰ بررسی + **دکتر سئو** که هر مشکل را به زبان ساده توضیح می‌دهد.
- **سرچ کنسول گوگل** (OAuth امن با کلاینت خود شما): برنده‌ها و بازنده‌ها، کلمات تازه و ازدست‌رفته، فرصت‌های CTR و جایگاه، تحلیل صفحه × کوئری.
- **«✨ بهینه‌سازی با هوش مصنوعی سئولیتی»** در گوتنبرگ، ویرایشگر کلاسیک، المنتور، دسته‌ها، پروفایل نویسنده و محصولات ووکامرس — با نمایش اصلی/پیشنهادی/مقایسه و امتیاز قبل و بعد.
- **سئوی محصولات**: عنوان، توضیحات، توضیح کوتاه، متا، کلمات کلیدی، FAQ، ALT تصاویر و لینک داخلی فقط بر اساس داده‌های واقعی محصول.
- **مرکز اقدام هوشمند** («امروز چه کار کنم؟») با اولویت، تأثیر، زحمت، اطمینان و دکمه «چرا؟».
- **تغییرات امن**: سه حالت تأیید (دستی، نیمه‌خودکار، خودکار)، نسخه‌بندی، بازگشت و بازیابی، گزارش کامل رویدادها.
- **آزمایش‌های سئو و یادگیری**: هر تغییر اعمال‌شده اندازه‌گیری می‌شود و نتیجه‌اش به پیشنهادهای بعدی هوش مصنوعی می‌رسد.
- کلمات کلیدی، هم‌نوع‌خواری، لینک‌سازی داخلی، خوشه‌های موضوعی، برنامه‌ریز محتوا، تحلیل رقبا و شکاف محتوایی، اسکیما، نقشه سایت، robots.txt، ریدایرکت، پایشگر ۴۰۴، هشدارها، خلاصه روزانه، چت سئو، عملیات گروهی و موتور قوانین.
- **پایگاه دانش سئو** (فارسی و انگلیسی، شامل نکات ویژه بازار ایران) که پشتوانه همه پاسخ‌های هوش مصنوعی است و با یک GitHub Action هر هفته از منابع معتبر به‌روز می‌شود.
- **ذخیره‌سازی در گیت‌هاب**: داده‌های قدیمی در یک مخزن خصوصی بایگانی می‌شوند تا دیتابیس هاست سبک بماند (قابل بازیابی).
- رابط کاربری فارسی، راست‌به‌چپ، حالت تیره، واکنش‌گرا و دسترس‌پذیر. فقط روش‌های کلاه‌سفید.

## نصب سریع

۱. پوشه `seolity` را در `wp-content/plugins` قرار دهید و فعال کنید.
۲. سئولیتی ← تنظیمات ← هوش مصنوعی: ارائه‌دهنده (Claude، OpenAI، Gemini، OpenRouter، Ollama محلی یا API سفارشی) و کلید را وارد کنید.
۳. سئولیتی ← تنظیمات ← سرچ کنسول: کلاینت OAuth خود را از Google Cloud بسازید، آدرس بازگشت نمایش‌داده‌شده را ثبت کنید و متصل شوید.
۴. سئولیتی ← ممیزی سایت ← شروع ممیزی.

## پایگاه دانش و گیت‌هاب

- فایل‌های دانش در `knowledge/fa` و `knowledge/en` هستند.
- برای یادگیری خودکار هفتگی، در مخزن گیت‌هاب یک Secret به نام `ANTHROPIC_API_KEY` بسازید؛ گردش‌کار `SEOlity knowledge builder` منابع معتبر (از جمله ترجمه‌های فارسی رسمی Google Search Central) را خلاصه و در `knowledge/distilled` ذخیره می‌کند. منابع دلخواه خود را در `tools/knowledge-builder/extra-sources.json` اضافه کنید.
- برای ذخیره داده‌ها در گیت‌هاب، یک توکن fine-grained با دسترسی Contents (خواندن/نوشتن) به یک مخزن خصوصی بسازید و در تنظیمات ← ذخیره‌سازی وارد کنید.

</div>

## English

SEOlity is an AI-powered SEO operating system for WordPress and WooCommerce. It **measures** (crawler with ~60 checks, Search Console page × query × day analytics, content analysis, competitors), **decides** (SEO Doctor, prioritized Action Center with "Why?" explanations, rules, daily briefing, data-grounded chat), **acts safely** (AI optimizer in every editor with preview, approval modes, versions and undo) and **learns** (every applied change becomes a measured experiment; results feed future suggestions).

- Architecture, database schema, API, AI, security, queue, OAuth and roadmap: [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md)
- Feature catalog (567 features, 511 implemented in 1.0, the rest marked as roadmap): [`docs/FEATURES.md`](docs/FEATURES.md)
- Installation, external services and FAQ: [`readme.txt`](readme.txt)
- Knowledge base format and contribution rules: [`knowledge/README.md`](knowledge/README.md)

### Development

- No build step: PHP (PSR-4 autoloader, no Composer) and plain JS/CSS.
- Translations: edit `tools/i18n/fa-*.tsv`, then run `python3 tools/i18n/build.py`.
- Feature catalog: edit `tools/docs/features_data.py`, then run `python3 tools/docs/build_features.py`.
- WP-CLI: `wp seolity queue run|stats|retry`, `audit`, `gsc-sync`, `daily`, `health`, `knowledge-sync`, `archive`.

License: GPL-2.0-or-later.
