/**
 * SEOlity admin page: Connection guide — step-by-step instructions with
 * direct links for every API key / account SEOlity can use (AI providers,
 * AI subscriptions via GitHub Actions, Google Search Console OAuth,
 * PageSpeed, SerpApi, GitHub). Bilingual content (fa / en) lives here as
 * data so long texts stay readable; UI chrome goes through t().
 */
( function ( w, d ) {
	'use strict';
	var SL = w.SL;
	var App = SL.app;
	var C = SL.config || {};
	var t = SL.t;
	var h = SL.h;
	var FA = !! C.rtl;

	/** Pick the text for the current admin language. */
	function L( x ) {
		if ( x === null || x === undefined || typeof x === 'string' ) {
			return x;
		}
		return FA ? x.fa : x.en;
	}

	/* Step helpers: { text, links: [[label, url]], code, go: [label, route] } */
	function S( fa, en, extra ) {
		return Object.assign( { text: { fa: fa, en: en } }, extra || {} );
	}
	function link( fa, en, url ) {
		return [ { fa: fa, en: en }, url ];
	}

	var SERVICES = [
		/* ------------------------------------------------------------ AI */
		{
			id: 'anthropic', group: 'ai', icon: 'cpu', title: 'Anthropic Claude API', secret: 'anthropic_api_key',
			badges: [ [ { fa: 'هوش مصنوعی', en: 'AI' }, 'info' ], [ { fa: 'پولی (پیش‌پرداخت)', en: 'Paid (prepaid credits)' }, 'warn' ], [ { fa: '۵ دقیقه', en: '5 min' }, 'neutral' ] ],
			why: { fa: 'بهترین کیفیت برای بازنویسی محتوا، متا و تحلیل سئو (مدل‌های Claude). هزینه بر اساس مصرف از اعتبار پیش‌پرداخت کم می‌شود.', en: 'Top quality for content rewriting, meta and SEO analysis (Claude models). You pay per use from prepaid credits.' },
			need: [ { fa: 'ایمیل و شماره موبایل برای ساخت حساب', en: 'An email address and a phone number for the account' }, { fa: 'کارت بانکی بین‌المللی برای خرید اعتبار', en: 'A card for buying API credits' } ],
			steps: [
				S( 'وارد کنسول Anthropic شوید و حساب بسازید (یا با گوگل وارد شوید).', 'Open the Anthropic Console and create an account (or sign in with Google).', { links: [ link( 'باز کردن کنسول', 'Open the Console', 'https://console.anthropic.com/' ) ] } ),
				S( 'از بخش Billing اعتبار (Credits) بخرید. بدون اعتبار، کلید کار نمی‌کند و خطای credit balance می‌گیرید. ۵ تا ۱۰ دلار برای شروع کافی است.', 'Buy credits under Billing. Without credits the key fails with a “credit balance” error. $5–10 is enough to start.', { links: [ link( 'صفحه Billing', 'Billing page', 'https://console.anthropic.com/settings/billing' ) ] } ),
				S( 'به صفحه API Keys بروید و «Create Key» را بزنید؛ یک نام مثل SEOlity بدهید.', 'Go to API Keys and click “Create Key”; name it e.g. SEOlity.', { links: [ link( 'ساخت کلید API (لینک مستقیم)', 'Create API key (direct link)', 'https://console.anthropic.com/settings/keys' ) ] } ),
				S( 'کلید را که با sk-ant- شروع می‌شود همان لحظه کپی کنید؛ دوباره نمایش داده نمی‌شود.', 'Copy the key (starts with sk-ant-) right away — it is shown only once.', { code: 'sk-ant-api03-…' } ),
				S( 'در SEOlity: تنظیمات ← هوش مصنوعی ← «Anthropic API key» را بچسبانید و Save بزنید. سپس «ارائه‌دهنده» را Anthropic بگذارید، تنظیمات را ذخیره و «تست اتصال» را بزنید.', 'In SEOlity: Settings → AI → paste into “Anthropic API key” and press Save. Then set Provider to Anthropic, save settings and press “Test connection”.', { go: [ { fa: 'رفتن به تنظیمات هوش مصنوعی', en: 'Open AI settings' }, 'settings?tab=ai' ] } ),
			],
			trouble: [
				[ { fa: 'خطای credit balance is too low', en: '“credit balance is too low”' }, { fa: 'اعتبار حساب تمام شده یا نخریده‌اید. از صفحه Billing اعتبار اضافه کنید.', en: 'No credits on the account. Add credits on the Billing page.' } ],
				[ { fa: 'خطای 401 / invalid x-api-key', en: '401 / invalid x-api-key' }, { fa: 'کلید ناقص کپی شده یا حذف شده. یک کلید جدید بسازید و دوباره ذخیره کنید.', en: 'The key was copied incompletely or was deleted. Create a new key and save it again.' } ],
				[ { fa: 'خطای 429 (rate limit)', en: '429 (rate limit)' }, { fa: 'حساب‌های تازه سقف درخواست کمی دارند. چند دقیقه صبر کنید؛ با افزایش خرید، سطح حساب (tier) بالا می‌رود.', en: 'New accounts have low limits. Wait a few minutes; limits rise with your usage tier.' } ],
			],
			docs: [ link( 'مستندات شروع API', 'API getting started', 'https://docs.claude.com/en/api/overview' ), link( 'کشورهای پشتیبانی‌شده', 'Supported countries', 'https://www.anthropic.com/supported-countries' ), link( 'قیمت‌ها', 'Pricing', 'https://www.anthropic.com/pricing#api' ) ],
		},
		{
			id: 'openai', group: 'ai', icon: 'cpu', title: 'OpenAI API (GPT)', secret: 'openai_api_key',
			badges: [ [ { fa: 'هوش مصنوعی', en: 'AI' }, 'info' ], [ { fa: 'پولی (پیش‌پرداخت)', en: 'Paid (prepaid credits)' }, 'warn' ], [ { fa: '۵ دقیقه', en: '5 min' }, 'neutral' ] ],
			why: { fa: 'مدل‌های GPT از طریق API. توجه: اشتراک ChatGPT Plus/Pro شامل اعتبار API نیست؛ برای استفاده از اشتراک، راهنمای «اشتراک ChatGPT» را ببینید.', en: 'GPT models through the API. Note: a ChatGPT Plus/Pro subscription does NOT include API credits — to use your subscription see the “ChatGPT subscription” guide.' },
			need: [ { fa: 'حساب OpenAI و کارت برای خرید اعتبار', en: 'An OpenAI account and a card for credits' } ],
			steps: [
				S( 'وارد پلتفرم OpenAI شوید یا ثبت‌نام کنید.', 'Sign in or sign up on the OpenAI Platform.', { links: [ link( 'ورود به پلتفرم', 'Open the Platform', 'https://platform.openai.com/' ) ] } ),
				S( 'در Billing یک روش پرداخت اضافه کنید و اعتبار بخرید (حداقل ۵ دلار).', 'Add a payment method and buy credits under Billing (minimum $5).', { links: [ link( 'صفحه Billing', 'Billing page', 'https://platform.openai.com/settings/organization/billing/overview' ) ] } ),
				S( 'در صفحه API keys روی «Create new secret key» بزنید. Permissions را All بگذارید (یا حداقل Model capabilities: Write).', 'On API keys click “Create new secret key”. Set Permissions to All (or at least Model capabilities: Write).', { links: [ link( 'ساخت کلید API (لینک مستقیم)', 'Create API key (direct link)', 'https://platform.openai.com/api-keys' ) ] } ),
				S( 'کلید (sk-…) را فوراً کپی کنید.', 'Copy the key (sk-…) immediately.', { code: 'sk-proj-…' } ),
				S( 'در SEOlity: تنظیمات ← هوش مصنوعی ← «OpenAI API key» ← Save؛ ارائه‌دهنده را OpenAI بگذارید و تست بزنید.', 'In SEOlity: Settings → AI → “OpenAI API key” → Save; set Provider to OpenAI and test.', { go: [ { fa: 'رفتن به تنظیمات هوش مصنوعی', en: 'Open AI settings' }, 'settings?tab=ai' ] } ),
			],
			trouble: [
				[ { fa: 'خطای insufficient_quota', en: 'insufficient_quota' }, { fa: 'اعتبار ندارید یا تمام شده. اشتراک ChatGPT برای API حساب نمی‌شود.', en: 'No credits left. A ChatGPT subscription does not count as API credit.' } ],
				[ { fa: 'model_not_found', en: 'model_not_found' }, { fa: 'نام مدل را در تنظیمات بررسی کنید یا خالی بگذارید تا پیش‌فرض استفاده شود. برخی مدل‌ها نیاز به تأیید سازمان دارند.', en: 'Check the model name in settings or leave it empty for the default. Some models require organization verification.' } ],
			],
			docs: [ link( 'شروع سریع', 'Quickstart', 'https://platform.openai.com/docs/quickstart' ), link( 'کشورهای پشتیبانی‌شده', 'Supported countries', 'https://platform.openai.com/docs/supported-countries' ), link( 'قیمت‌ها', 'Pricing', 'https://openai.com/api/pricing/' ) ],
		},
		{
			id: 'gemini', group: 'ai', icon: 'cpu', title: 'Google Gemini API', secret: 'gemini_api_key',
			badges: [ [ { fa: 'هوش مصنوعی', en: 'AI' }, 'info' ], [ { fa: 'سطح رایگان دارد', en: 'Free tier' }, 'good' ], [ { fa: '۳ دقیقه', en: '3 min' }, 'neutral' ] ],
			why: { fa: 'ساده‌ترین راه شروع: کلید رایگان با سقف استفاده روزانه از Google AI Studio.', en: 'Easiest start: a free key with daily limits from Google AI Studio.' },
			need: [ { fa: 'یک حساب گوگل', en: 'A Google account' } ],
			steps: [
				S( 'Google AI Studio را باز کنید و با حساب گوگل وارد شوید (شرایط را بپذیرید).', 'Open Google AI Studio and sign in with Google (accept the terms).', { links: [ link( 'باز کردن AI Studio', 'Open AI Studio', 'https://aistudio.google.com/' ) ] } ),
				S( 'روی «Get API key» و سپس «Create API key» بزنید. اگر پروژه نداشتید، گزینه ساخت پروژه جدید را انتخاب کنید.', 'Click “Get API key” → “Create API key”. If you have no project, choose to create a new one.', { links: [ link( 'صفحه کلیدها (لینک مستقیم)', 'API keys page (direct link)', 'https://aistudio.google.com/app/apikey' ) ] } ),
				S( 'کلید (AIza…) را کپی کنید.', 'Copy the key (AIza…).', { code: 'AIzaSy…' } ),
				S( 'در SEOlity: تنظیمات ← هوش مصنوعی ← «Google Gemini API key» ← Save؛ ارائه‌دهنده را Gemini بگذارید و تست بزنید.', 'In SEOlity: Settings → AI → “Google Gemini API key” → Save; set Provider to Gemini and test.', { go: [ { fa: 'رفتن به تنظیمات هوش مصنوعی', en: 'Open AI settings' }, 'settings?tab=ai' ] } ),
			],
			trouble: [
				[ { fa: 'User location is not supported', en: '“User location is not supported”' }, { fa: 'Gemini فقط در کشورهای مشخصی ارائه می‌شود و درخواست از محل سرور هاست شما ارسال می‌شود. فهرست مناطق را ببینید یا ارائه‌دهنده دیگری انتخاب کنید.', en: 'Gemini is only offered in listed regions and the request comes from your hosting server’s location. Check the region list or choose another provider.' } ],
				[ { fa: 'خطای 429 / RESOURCE_EXHAUSTED', en: '429 / RESOURCE_EXHAUSTED' }, { fa: 'سقف رایگان پر شده. صبر کنید یا Billing را در پروژه فعال کنید.', en: 'Free quota used up. Wait, or enable billing on the project.' } ],
			],
			docs: [ link( 'راهنمای کلید API', 'API key guide', 'https://ai.google.dev/gemini-api/docs/api-key' ), link( 'مناطق در دسترس', 'Available regions', 'https://ai.google.dev/gemini-api/docs/available-regions' ), link( 'سقف‌ها و قیمت', 'Limits & pricing', 'https://ai.google.dev/gemini-api/docs/pricing' ) ],
		},
		{
			id: 'openrouter', group: 'ai', icon: 'cpu', title: 'OpenRouter', secret: 'openrouter_api_key',
			badges: [ [ { fa: 'هوش مصنوعی', en: 'AI' }, 'info' ], [ { fa: 'پیش‌پرداخت + مدل‌های رایگان', en: 'Prepaid + free models' }, 'good' ], [ { fa: '۵ دقیقه', en: '5 min' }, 'neutral' ] ],
			why: { fa: 'یک کلید برای صدها مدل (Claude، GPT، Gemini، Llama، DeepSeek، Qwen…). مدل‌هایی که آخرشان :free است رایگان‌اند (با سقف).', en: 'One key for hundreds of models (Claude, GPT, Gemini, Llama, DeepSeek, Qwen…). Models ending in :free cost nothing (rate limited).' },
			need: [ { fa: 'حساب OpenRouter (گوگل/گیت‌هاب/ایمیل)', en: 'An OpenRouter account (Google/GitHub/email)' } ],
			steps: [
				S( 'در OpenRouter ثبت‌نام کنید.', 'Sign up at OpenRouter.', { links: [ link( 'باز کردن OpenRouter', 'Open OpenRouter', 'https://openrouter.ai/' ) ] } ),
				S( 'برای مدل‌های غیررایگان از صفحه Credits اعتبار بخرید.', 'For paid models, buy credits on the Credits page.', { links: [ link( 'صفحه Credits', 'Credits page', 'https://openrouter.ai/settings/credits' ) ] } ),
				S( 'در صفحه Keys روی «Create Key» بزنید و کلید (sk-or-…) را کپی کنید.', 'On Keys click “Create Key” and copy it (sk-or-…).', { links: [ link( 'ساخت کلید (لینک مستقیم)', 'Create key (direct link)', 'https://openrouter.ai/settings/keys' ) ], code: 'sk-or-v1-…' } ),
				S( 'شناسه مدل دلخواه را از فهرست مدل‌ها کپی کنید (مثلاً anthropic/claude-sonnet-4.5 یا یک مدل :free).', 'Copy a model id from the models list (e.g. anthropic/claude-sonnet-4.5 or a :free model).', { links: [ link( 'فهرست مدل‌ها', 'Models list', 'https://openrouter.ai/models' ) ] } ),
				S( 'در SEOlity: تنظیمات ← هوش مصنوعی ← «OpenRouter API key» ← Save؛ ارائه‌دهنده را OpenRouter و مدل را وارد کنید و تست بزنید.', 'In SEOlity: Settings → AI → “OpenRouter API key” → Save; set Provider to OpenRouter, enter the model and test.', { go: [ { fa: 'رفتن به تنظیمات هوش مصنوعی', en: 'Open AI settings' }, 'settings?tab=ai' ] } ),
			],
			trouble: [
				[ { fa: 'خطای 402', en: '402 error' }, { fa: 'اعتبار کافی نیست؛ اعتبار بخرید یا یک مدل :free انتخاب کنید.', en: 'Not enough credits; buy credits or pick a :free model.' } ],
				[ { fa: 'پاسخ JSON نامعتبر', en: 'Invalid JSON answers' }, { fa: 'مدل‌های خیلی کوچک گاهی خروجی ساختاریافته را خراب می‌کنند. یک مدل قوی‌تر انتخاب کنید.', en: 'Very small models sometimes break structured output. Choose a stronger model.' } ],
			],
			docs: [ link( 'شروع سریع', 'Quickstart', 'https://openrouter.ai/docs/quickstart' ), link( 'مدل‌ها و قیمت‌ها', 'Models & prices', 'https://openrouter.ai/models' ) ],
		},
		{
			id: 'ollama', group: 'ai', icon: 'server', title: 'Ollama (local / self-hosted)',
			badges: [ [ { fa: 'هوش مصنوعی', en: 'AI' }, 'info' ], [ { fa: 'رایگان، بدون حساب', en: 'Free, no account' }, 'good' ], [ { fa: '۱۵ دقیقه', en: '15 min' }, 'neutral' ] ],
			why: { fa: 'مدل‌های متن‌باز روی سرور خودتان؛ بدون کلید، بدون ارسال داده به بیرون. نیاز به سرور با رم کافی (حداقل ۸ تا ۱۶ گیگ برای مدل‌های ۷ تا ۸ میلیارد پارامتری).', en: 'Open models on your own server: no key, no data leaves your machine. Needs enough RAM (8–16 GB for 7–8B models).' },
			need: [ { fa: 'یک سرور یا کامپیوتر که وردپرس به آن دسترسی شبکه‌ای دارد', en: 'A server or computer reachable from your WordPress host' } ],
			steps: [
				S( 'Ollama را نصب کنید (ویندوز/مک/لینوکس). روی لینوکس:', 'Install Ollama (Windows/macOS/Linux). On Linux:', { links: [ link( 'دانلود Ollama', 'Download Ollama', 'https://ollama.com/download' ) ], code: 'curl -fsSL https://ollama.com/install.sh | sh' } ),
				S( 'یک مدل دریافت کنید. برای فارسی مدل‌های Qwen یا Gemma گزینه‌های خوبی هستند:', 'Pull a model. Qwen or Gemma models handle Persian well:', { links: [ link( 'کتابخانه مدل‌ها', 'Model library', 'https://ollama.com/library' ) ], code: 'ollama pull qwen2.5:7b' } ),
				S( 'اگر وردپرس روی سرور دیگری است، Ollama را روی همه رابط‌ها در دسترس کنید و پورت 11434 را فقط برای IP سرور وردپرس باز کنید:', 'If WordPress runs on another server, make Ollama listen on all interfaces and open port 11434 only to the WordPress server IP:', { code: 'OLLAMA_HOST=0.0.0.0:11434 ollama serve' } ),
				S( 'در SEOlity: تنظیمات ← هوش مصنوعی ← ارائه‌دهنده Ollama، آدرس (مثلاً http://127.0.0.1:11434) و نام مدل (qwen2.5:7b) را وارد کنید، ذخیره و تست کنید.', 'In SEOlity: Settings → AI → Provider Ollama, base URL (e.g. http://127.0.0.1:11434) and model (qwen2.5:7b); save and test.', { go: [ { fa: 'رفتن به تنظیمات هوش مصنوعی', en: 'Open AI settings' }, 'settings?tab=ai' ] } ),
			],
			trouble: [
				[ { fa: 'Connection refused / timeout', en: 'Connection refused / timeout' }, { fa: 'وردپرس به آدرس Ollama دسترسی ندارد. روی هاست اشتراکی معمولاً امکان‌پذیر نیست؛ از VPS استفاده کنید یا آدرس عمومی امن بدهید.', en: 'WordPress cannot reach Ollama. Shared hosting usually can’t; use a VPS or a secured public address.' } ],
				[ { fa: 'کند بودن پاسخ', en: 'Slow answers' }, { fa: 'مدل کوچک‌تر یا سرور با GPU انتخاب کنید و «مهلت زمانی هوش مصنوعی» را در تنظیمات بیشتر کنید.', en: 'Use a smaller model or a GPU server and raise “AI timeout” in settings.' } ],
			],
			docs: [ link( 'پرسش‌های متداول Ollama', 'Ollama FAQ', 'https://github.com/ollama/ollama/blob/main/docs/faq.md' ) ],
		},
		{
			id: 'custom', group: 'ai', icon: 'braces', title: { fa: 'API سفارشی (هر سرویس دلخواه)', en: 'Custom API (any provider)' }, secret: 'custom_api_key',
			badges: [ [ { fa: 'هوش مصنوعی', en: 'AI' }, 'info' ], [ { fa: 'هر سرویس سازگار', en: 'Any compatible service' }, 'good' ], [ { fa: '۵ دقیقه', en: '5 min' }, 'neutral' ] ],
			why: { fa: 'هر سرویس هوش مصنوعی که API سازگار با OpenAI یا Anthropic دارد را با همان تنظیماتی که در مستنداتش آمده وصل کنید: DeepSeek، Groq، Mistral، Together، xAI، Perplexity، Azure OpenAI، Cloudflare Workers AI، LM Studio، vLLM، LiteLLM و درگاه‌های مشابه.', en: 'Connect any AI service with an OpenAI- or Anthropic-compatible API using exactly the settings from its documentation: DeepSeek, Groq, Mistral, Together, xAI, Perplexity, Azure OpenAI, Cloudflare Workers AI, LM Studio, vLLM, LiteLLM and similar gateways.' },
			need: [ { fa: 'کلید API و آدرس پایه (Base URL) از مستندات سرویس', en: 'An API key and the base URL from the service documentation' } ],
			steps: [
				S( 'در «تنظیمات ← هوش مصنوعی» به کارت «API سفارشی» بروید و از فهرست «سرویس» سرویس خود را انتخاب کنید؛ آدرس، قالب، روش ارسال کلید و حالت JSON خودکار پر می‌شوند و لینک گرفتن کلید نمایش داده می‌شود.', 'In Settings → AI open the “Custom API” card and pick your service in “Service”; the URL, format, key method and JSON mode are filled in and a link to get the key appears.', { go: [ { fa: 'رفتن به تنظیمات هوش مصنوعی', en: 'Open AI settings' }, 'settings?tab=ai' ] } ),
				S( 'سرویس در فهرست نیست؟ «Custom» را انتخاب کنید و Base URL را از مستندات سرویس بنویسید. نمونه‌ها:', 'Service not listed? Choose “Custom” and enter the base URL from the service documentation. Examples:', { code: 'https://api.deepseek.com/v1\nhttps://api.groq.com/openai/v1\nhttps://generativelanguage.googleapis.com/v1beta/openai\nhttps://api.cloudflare.com/client/v4/accounts/{ACCOUNT_ID}/ai/v1\nhttps://{RESOURCE}.openai.azure.com/openai/deployments/{DEPLOYMENT}\nhttp://localhost:1234/v1   (LM Studio)' } ),
				S( '«API format»: اگر مستندات از /chat/completions می‌گوید OpenAI-compatible، اگر از /v1/messages می‌گوید Anthropic-compatible. اگر آدرس کامل endpoint را دارید همان را بچسبانید؛ SEOlity تشخیص می‌دهد.', '“API format”: OpenAI-compatible if the docs mention /chat/completions, Anthropic-compatible for /v1/messages. A full endpoint URL also works; SEOlity detects it.' ),
				S( '«How the key is sent»: بیشتر سرویس‌ها Bearer هستند؛ Azure از api-key، درگاه‌های Claude از x-api-key، و برخی از پارامتر URL (مثلاً key) استفاده می‌کنند. کلید را در فیلد API key ذخیره کنید.', '“How the key is sent”: most services use Bearer; Azure uses api-key, Claude-style gateways x-api-key, and some a URL parameter (e.g. key). Save the key in the API key field.' ),
				S( '«Fetch models» فهرست مدل‌های سرویس را می‌آورد؛ یکی را انتخاب کنید (برای Azure نام Deployment).', '“Fetch models” lists the service’s models; pick one (for Azure, the deployment name).' ),
				S( 'هدرهای اضافه (مثلاً HTTP-Referer)، پارامترهای کوئری (مثلاً api-version=2024-10-21 برای Azure)، پارامتر محدودیت توکن و دما در بخش «پیشرفته» هستند. پیش‌نمایش، درخواست دقیق را نشان می‌دهد.', 'Extra headers (e.g. HTTP-Referer), query parameters (e.g. api-version=2024-10-21 for Azure), the token-limit parameter and temperature are under “Advanced”. The preview shows the exact request.' ),
				S( '«Test connection» و سپس «Use as AI engine» را بزنید. «Structured output» روی خودکار بماند: SEOlity به‌ترتیب json_schema، JSON mode و دستور در متن را امتحان می‌کند و حالت موفق را به خاطر می‌سپارد.', 'Press “Test connection”, then “Use as AI engine”. Keep “Structured output” on Automatic: SEOlity tries json_schema, JSON mode and prompt instructions in that order and remembers what works.' ),
			],
			trouble: [
				[ { fa: 'خطای 404', en: '404 error' }, { fa: 'Base URL اشتباه است؛ معمولاً /v1 جا افتاده یا اضافه است. پیش‌نمایش «Request URL» را با مستندات مقایسه کنید.', en: 'Wrong base URL — usually /v1 is missing or doubled. Compare the “Request URL” preview with the documentation.' } ],
				[ { fa: 'خطای 401 یا 403', en: '401 or 403 error' }, { fa: 'روش ارسال کلید اشتباه است (مثلاً Bearer به‌جای api-key) یا کلید اعتبار ندارد.', en: 'Wrong key method (e.g. Bearer instead of api-key) or the key has no credit/access.' } ],
				[ { fa: 'خطای response_format یا json_schema', en: 'response_format / json_schema error' }, { fa: 'در حالت خودکار خودش حالت بعدی را امتحان می‌کند؛ اگر حالت را دستی گذاشته‌اید «JSON mode» یا «Instructions in the prompt only» را انتخاب کنید.', en: 'Automatic mode tries the next option itself; if you set it by hand choose “JSON mode” or “Instructions in the prompt only”.' } ],
				[ { fa: 'خطای max_tokens', en: 'max_tokens error' }, { fa: 'پارامتر محدودیت توکن را روی max_completion_tokens یا «ارسال نشود» بگذارید.', en: 'Set the token-limit parameter to max_completion_tokens or “Do not send”.' } ],
				[ { fa: 'مدل‌های استدلالی پاسخ را با <think> برمی‌گردانند', en: 'Reasoning models return <think> text' }, { fa: 'SEOlity بخش <think> را خودکار حذف می‌کند؛ دما را خالی بگذارید.', en: 'SEOlity strips the <think> part automatically; leave temperature empty.' } ],
				[ { fa: 'سرور محلی در دسترس نیست', en: 'Local server unreachable' }, { fa: 'localhost یعنی همان سروری که وردپرس روی آن است؛ برای کامپیوتر دیگر آدرس شبکه آن را بدهید.', en: 'localhost means the machine WordPress runs on; for another computer use its network address.' } ],
			],
			docs: [ link( 'سازگاری Gemini با OpenAI', 'Gemini OpenAI compatibility', 'https://ai.google.dev/gemini-api/docs/openai' ), link( 'Workers AI سازگار با OpenAI', 'Workers AI OpenAI compatibility', 'https://developers.cloudflare.com/workers-ai/configuration/open-ai-compatibility/' ), link( 'مرجع Azure OpenAI', 'Azure OpenAI reference', 'https://learn.microsoft.com/azure/ai-services/openai/reference' ) ],
		},
		/* ------------------------------------------------------------ Subscriptions */
		{
			id: 'sub_claude', group: 'sub', icon: 'sparkles', title: { fa: 'اشتراک Claude Pro / Max', en: 'Claude Pro / Max subscription' }, secret: 'claude_oauth_token',
			badges: [ [ { fa: 'هوش مصنوعی اشتراکی', en: 'Subscription AI' }, 'info' ], [ { fa: 'بدون هزینه API', en: 'No API cost' }, 'good' ], [ { fa: '۱۰ دقیقه', en: '10 min' }, 'neutral' ] ],
			why: { fa: 'از اشتراک Claude خودتان استفاده می‌کند (بدون خرید اعتبار API). درخواست‌ها روی GitHub Actions در مخزن خصوصی شما با Claude Code اجرا می‌شوند. صفحه «اکانت اشتراکی» همه مراحل را به ترتیب و با بررسی خودکار انجام می‌دهد.', en: 'Uses your own Claude plan (no API credits). Requests run with Claude Code on GitHub Actions in your private repository. The “Subscription AI” page walks through every step in order and checks each one automatically.' },
			need: [ { fa: 'اشتراک فعال Claude Pro یا Max', en: 'An active Claude Pro or Max plan' }, { fa: 'حساب GitHub رایگان', en: 'A free GitHub account' }, { fa: 'Node.js روی کامپیوتر خودتان (فقط یک‌بار برای گرفتن توکن)', en: 'Node.js on your own computer (once, to get the token)' } ],
			steps: [
				S( 'صفحه «اکانت اشتراکی» را باز کنید و «Claude Pro / Max» را انتخاب کنید. مرحله ۰ (بررسی پیش‌نیازها) نشان می‌دهد چه چیزی مانده است.', 'Open the “Subscription AI” page and pick “Claude Pro / Max”. Step 0 (prerequisites check) shows what is left.', { go: [ { fa: 'باز کردن اکانت اشتراکی', en: 'Open Subscription AI' }, 'subscription' ] } ),
				S( 'مرحله ۱: GitHub را با یک توکن وصل کنید (لینک آماده با دسترسی‌های لازم) و «ساخت مخزن و نصب اجراکننده» را بزنید.', 'Step 1: connect GitHub with one token (a ready link with the right permissions) and press “Create repository & install runner”.', { links: [ link( 'ساخت توکن GitHub (دسترسی‌ها از قبل انتخاب شده)', 'Create a GitHub token (permissions preselected)', 'https://github.com/settings/tokens/new?scopes=repo,workflow&description=SEOlity' ) ] } ),
				S( 'روی کامپیوتر خودتان Node.js و Claude Code را نصب کنید:', 'On your own computer install Node.js and Claude Code:', { links: [ link( 'دانلود Node.js', 'Download Node.js', 'https://nodejs.org/en/download' ) ], code: 'npm install -g @anthropic-ai/claude-code' } ),
				S( 'این دستور را بزنید؛ مرورگر باز می‌شود، با اکانت Claude Pro/Max وارد شوید و Authorize بزنید. توکن یک‌ساله (sk-ant-oat01-…) در ترمینال چاپ می‌شود:', 'Run this; a browser opens — sign in with your Claude Pro/Max account and press Authorize. A one-year token (sk-ant-oat01-…) is printed in the terminal:', { code: 'claude setup-token' } ),
				S( 'مرحله ۳: توکن را بچسبانید و «ذخیره امن در GitHub Secrets» را بزنید. مرحله ۴: «ارسال درخواست تست» و سپس «استفاده به‌عنوان موتور هوش مصنوعی».', 'Step 3: paste the token and press “Save securely to GitHub Secrets”. Step 4: “Send a test request”, then “Use as AI engine”.', { go: [ { fa: 'رفتن به مرحله ثبت اکانت', en: 'Go to the account step' }, 'subscription?step=account' ] } ),
			],
			trouble: [
				[ { fa: 'claude: command not found', en: 'claude: command not found' }, { fa: 'ترمینال را ببندید و باز کنید یا مسیر npm global را به PATH اضافه کنید.', en: 'Reopen the terminal or add the npm global bin folder to PATH.' } ],
				[ { fa: 'Invalid API key / 401 در اجرای Actions', en: 'Invalid API key / 401 in the Actions run' }, { fa: 'توکن منقضی یا ناقص است؛ دوباره claude setup-token را اجرا و در مرحله ۳ ذخیره کنید.', en: 'The token expired or is incomplete; run claude setup-token again and save it in step 3.' } ],
				[ { fa: 'پاسخ‌ها چند دقیقه طول می‌کشد', en: 'Answers take a few minutes' }, { fa: 'طبیعی است (روشن شدن ماشین GitHub). با «کلادفلر» سریع‌تر و مطمئن‌تر می‌رسند.', en: 'Normal (the GitHub machine starts up). With the Cloudflare relay they arrive faster and more reliably.' } ],
			],
			docs: [ link( 'توکن یک‌ساله claude setup-token', 'claude setup-token (one-year token)', 'https://code.claude.com/docs/en/authentication#generate-a-long-lived-token' ), link( 'Claude Code در GitHub Actions', 'Claude Code GitHub Actions', 'https://code.claude.com/docs/en/github-actions' ) ],
		},
		{
			id: 'sub_codex', group: 'sub', icon: 'sparkles', title: { fa: 'اشتراک ChatGPT Plus / Pro (Codex)', en: 'ChatGPT Plus / Pro subscription (Codex)' }, secret: 'codex_auth_json',
			badges: [ [ { fa: 'هوش مصنوعی اشتراکی', en: 'Subscription AI' }, 'info' ], [ { fa: 'بدون هزینه API', en: 'No API cost' }, 'good' ], [ { fa: '۱۰ دقیقه', en: '10 min' }, 'neutral' ] ],
			why: { fa: 'از اشتراک ChatGPT شما با Codex CLI روی GitHub Actions استفاده می‌کند. ورود به‌طور خودکار تمدید و نسخه جدید ذخیره می‌شود.', en: 'Uses your ChatGPT plan through the Codex CLI on GitHub Actions. The login is refreshed and re-saved automatically.' },
			need: [ { fa: 'اشتراک ChatGPT Plus، Pro، Team یا Business', en: 'A ChatGPT Plus, Pro, Team or Business plan' }, { fa: 'حساب GitHub رایگان', en: 'A free GitHub account' }, { fa: 'Node.js روی کامپیوتر خودتان', en: 'Node.js on your own computer' } ],
			steps: [
				S( 'صفحه «اکانت اشتراکی» را باز و «ChatGPT Plus / Pro» را انتخاب کنید؛ مرحله ۱ (GitHub و مخزن) را با یک کلیک انجام دهید.', 'Open “Subscription AI”, pick “ChatGPT Plus / Pro” and do step 1 (GitHub and repository) in one click.', { go: [ { fa: 'باز کردن اکانت اشتراکی', en: 'Open Subscription AI' }, 'subscription?engine=codex' ] } ),
				S( 'Codex CLI را نصب کنید:', 'Install the Codex CLI:', { links: [ link( 'دانلود Node.js', 'Download Node.js', 'https://nodejs.org/en/download' ) ], code: 'npm install -g @openai/codex' } ),
				S( 'یک‌بار تنظیم کنید که ورود در فایل ذخیره شود (مک/لینوکس؛ دستور ویندوز در خود صفحه است):', 'Once, make Codex store the login in a file (macOS/Linux; the Windows command is on the page):', { code: "mkdir -p ~/.codex && echo 'cli_auth_credentials_store = \"file\"' >> ~/.codex/config.toml" } ),
				S( 'وارد شوید و «Sign in with ChatGPT» را انتخاب کنید:', 'Sign in and choose “Sign in with ChatGPT”:', { code: 'codex login' } ),
				S( 'کل محتوای auth.json را کپی کنید (ویندوز: %USERPROFILE%\\.codex\\auth.json) و در مرحله ۳ بچسبانید، سپس تست کنید.', 'Copy the whole auth.json (Windows: %USERPROFILE%\\.codex\\auth.json), paste it in step 3 and run the test.', { code: 'cat ~/.codex/auth.json', go: [ { fa: 'رفتن به مرحله ثبت اکانت', en: 'Go to the account step' }, 'subscription?engine=codex&step=account' ] } ),
			],
			trouble: [
				[ { fa: 'not valid JSON', en: '“not valid JSON”' }, { fa: 'کل فایل را از { تا } کپی کنید، نه فقط یک بخش.', en: 'Copy the whole file from { to }, not a part of it.' } ],
				[ { fa: 'فایل auth.json ساخته نشد', en: 'auth.json was not created' }, { fa: 'تنظیم cli_auth_credentials_store = "file" را انجام ندادید و ورود در Keychain سیستم ذخیره شد؛ تنظیم را اضافه و دوباره codex login کنید.', en: 'The cli_auth_credentials_store = "file" setting is missing so the login went to the system keychain; add it and run codex login again.' } ],
				[ { fa: 'خطای ورود بعد از چند هفته', en: 'Login errors after some weeks' }, { fa: 'SEOlity نسخه تمدیدشده را خودکار ذخیره می‌کند؛ اگر باز خطا داد، codex login را دوباره اجرا و فایل را دوباره ذخیره کنید.', en: 'SEOlity stores refreshed logins automatically; if it still fails, run codex login again and re-save the file.' } ],
			],
			docs: [ link( 'احراز هویت Codex', 'Codex authentication', 'https://github.com/openai/codex/blob/main/docs/authentication.md' ), link( 'مخزن Codex CLI', 'Codex CLI repository', 'https://github.com/openai/codex' ) ],
		},
		{
			id: 'cli_server', group: 'sub', icon: 'server', title: { fa: 'نصب CLI رسمی روی سرور خودتان (حالت مستقیم)', en: 'Official CLI on your own server (Direct mode)' },
			badges: [ [ { fa: 'اختیاری', en: 'Optional' }, 'neutral' ], [ { fa: 'VPS / سرور اختصاصی', en: 'VPS / dedicated server' }, 'info' ] ],
			why: { fa: 'اگر Claude Code یا Codex روی همان سروری نصب باشد که وردپرس روی آن است، اشتراک شما بدون GitHub و با سرعت کامل جواب می‌دهد. SEOlity سرور را بررسی می‌کند و فقط وقتی همه شرایط فراهم باشد، بسته رسمی را با یک فرمان ثابت npm نصب می‌کند (بدون اجرای هیچ دستور دلخواهی).', en: 'When Claude Code or Codex is installed on the same server as WordPress, your subscription answers without GitHub and at full speed. SEOlity checks the server and, only when everything is in place, installs the official package with one fixed npm command (it never runs arbitrary commands).' },
			need: [
				{ fa: 'اجازه اجرای برنامه در PHP (تابع proc_open فعال باشد) — در بیشتر هاست‌های اشتراکی بسته است', en: 'PHP may start programs (proc_open enabled) — blocked on most shared hosting' },
				{ fa: 'لینوکس یا مک روی پردازنده x64 یا arm64', en: 'Linux or macOS on x64 or arm64' },
				{ fa: 'Node.js نسخه ۲۲ یا بالاتر برای Claude Code (برای Codex نسخه ۱۶+) همراه npm', en: 'Node.js 22+ for Claude Code (16+ for Codex) with npm' },
				{ fa: 'فضای خالی دیسک: Claude Code حدود ۲۹۲ مگابایت (۲.۱.۳۱۰، لینوکس x64) و Codex حدود ۴۴۴ مگابایت (۰.۱۶۴.۰) — با حافظه موقت npm حداقل ۷۰۰ مگابایت و ۱ گیگابایت خالی', en: 'Free disk: Claude Code ≈ 292 MB (2.1.310, linux x64), Codex ≈ 444 MB (0.164.0) — with npm’s temporary cache at least 700 MB and 1 GB free' },
				{ fa: 'حافظه آزاد حداقل ۵۱۲ مگابایت برای هر درخواست', en: 'At least 512 MB of free memory per request' },
			],
			steps: [
				S( 'صفحه «سرور و سرعت» را باز کنید و در کارت «اشتراک روی همین سرور» دکمه «بررسی سرور و نصب خودکار» را بزنید. هر شرط با مقدار واقعی سرور و راه‌حل نشان داده می‌شود.', 'Open “Server & speed” and press “Check this server & install” in the “Subscription on this server” card. Every requirement is shown with the real value of the server and the fix.', { go: [ { fa: 'باز کردن سرور و سرعت', en: 'Open Server & speed' }, 'network' ] } ),
				S( 'در cPanel (هاست‌های CloudLinux) با «Setup Node.js App» یک برنامه با جدیدترین Node.js بسازید؛ SEOlity همان Node را پیدا می‌کند.', 'On cPanel (CloudLinux hosts) create an app with the newest Node.js in “Setup Node.js App”; SEOlity finds that Node automatically.' ),
				S( 'اگر همه موارد سبز بود «نصب خودکار» را بزنید. نصب چند دقیقه در پس‌زمینه طول می‌کشد، در «وظایف» دیده می‌شود و در پایان با اجرای «--version» تأیید می‌شود.', 'If every item is green press “Install automatically”. It runs for a few minutes in the background, appears in Tasks and is verified at the end by running “--version”.' ),
				S( 'برای ورود، روی رایانه خودتان claude setup-token (یا codex login) را اجرا و نتیجه را در «تنظیمات ← هوش مصنوعی ← اشتراکی» ذخیره کنید؛ سپس «آزمایش» را بزنید.', 'To sign in, run claude setup-token (or codex login) on your own computer and save the result in Settings → AI → Subscription; then press “Test”.', { code: 'claude setup-token' } ),
				S( 'یا با SSH خودتان نصب کنید و مسیر کامل را وارد کنید:', 'Or install it yourself over SSH and enter the full path:', { code: 'npm install -g @anthropic-ai/claude-code' } ),
			],
			trouble: [
				[ { fa: 'proc_open غیرفعال است', en: 'proc_open is disabled' }, { fa: 'هاست اشتراکی اجازه اجرای برنامه نمی‌دهد و راه دور زدن مجاز نیست؛ از کلید API یا مسیر GitHub/کلادفلر استفاده کنید یا به VPS مهاجرت کنید.', en: 'Shared hosting does not allow starting programs and there is no legitimate way around it; use an API key, the GitHub/Cloudflare route or move to a VPS.' } ],
				[ { fa: 'نصب به‌خاطر حافظه یا زمان قطع شد', en: 'The install stopped for memory or time limits' }, { fa: 'هاست فرایندهای طولانی را می‌بندد؛ نصب را با SSH انجام دهید یا از مسیر GitHub استفاده کنید.', en: 'The host kills long processes; install over SSH or use the GitHub route.' } ],
				[ { fa: 'نصب شد ولی پاسخ نمی‌دهد', en: 'Installed but it does not answer' }, { fa: 'توکن/ورود اشتراک را در تنظیمات هوش مصنوعی ذخیره کنید؛ CLI بدون ورود رسمی کار نمی‌کند.', en: 'Save the subscription token/login in the AI settings; the CLI does not work without the official sign-in.' } ],
			],
			docs: [ link( 'نصب Claude Code', 'Claude Code setup', 'https://code.claude.com/docs/en/setup' ), link( 'مخزن Codex CLI', 'Codex CLI repository', 'https://github.com/openai/codex' ) ],
		},
		/* ------------------------------------------------------------ GitHub, Cloudflare & data */
		{
			id: 'github', group: 'infra', icon: 'github', title: 'GitHub', secret: 'github_token',
			badges: [ [ { fa: 'لازم برای اشتراک، دانش و بایگانی', en: 'For subscriptions, knowledge & archive' }, 'info' ], [ { fa: 'رایگان', en: 'Free' }, 'good' ], [ { fa: '۳ دقیقه', en: '3 min' }, 'neutral' ] ],
			why: { fa: 'یک توکن کافی است: SEOlity مخزن‌های خصوصی اجراکننده، دانش و داده را خودش با یک کلیک می‌سازد و تنظیم می‌کند.', en: 'One token is all it takes: SEOlity creates and configures the private runner, knowledge and data repositories itself in one click.' },
			need: [ { fa: 'حساب GitHub (رایگان)', en: 'A GitHub account (free)' } ],
			steps: [
				S( 'حساب GitHub ندارید؟ رایگان بسازید.', 'No GitHub account? Create one for free.', { links: [ link( 'ثبت‌نام در GitHub', 'Sign up for GitHub', 'https://github.com/signup' ) ] } ),
				S( 'این لینک را باز کنید؛ دسترسی‌های repo و workflow از قبل انتخاب شده‌اند. مدت اعتبار را انتخاب و «Generate token» را بزنید.', 'Open this link; the repo and workflow scopes are preselected. Choose an expiration and press “Generate token”.', { links: [ link( 'ساخت توکن (لینک مستقیم)', 'Create token (direct link)', 'https://github.com/settings/tokens/new?scopes=repo,workflow&description=SEOlity' ) ] } ),
				S( 'توکن ghp_… را کپی کنید و در «GitHub و داده» ← مرحله ۱ بچسبانید و «اتصال و بررسی توکن» را بزنید.', 'Copy the ghp_… token, paste it in “GitHub & data” → step 1 and press “Connect & verify token”.', { code: 'ghp_…', go: [ { fa: 'رفتن به GitHub و داده', en: 'Open GitHub & data' }, 'github' ] } ),
				S( 'در مرحله ۲ «ساخت و راه‌اندازی همه‌چیز» را بزنید: یک مخزن خصوصی seolity-ai ساخته می‌شود که اجراکننده اشتراکی، دانش اختصاصی و بایگانی داده (شاخه seolity-data) را با هم دارد.', 'In step 2 press “Create & set up everything”: one private seolity-ai repository is created that holds the subscription runner, your own knowledge and the data archive (branch seolity-data).' ),
				S( 'توکن محدودتر (Fine-grained) می‌خواهید؟ مخزن‌های خصوصی را اول خودتان بسازید و دسترسی Contents، Actions، Secrets و Workflows را Read and write بدهید.', 'Want a more limited fine-grained token? Create the private repositories yourself first and grant Contents, Actions, Secrets and Workflows: Read and write.', { links: [ link( 'ساخت توکن Fine-grained', 'New fine-grained token', 'https://github.com/settings/personal-access-tokens/new' ), link( 'ساخت مخزن جدید', 'Create a repository', 'https://github.com/new' ) ] } ),
			],
			trouble: [
				[ { fa: 'Bad credentials', en: 'Bad credentials' }, { fa: 'توکن ناقص کپی شده یا باطل/منقضی شده است. یک توکن جدید بسازید.', en: 'The token was copied incompletely or was revoked/expired. Create a new one.' } ],
				[ { fa: '403 Resource not accessible by personal access token', en: '403 Resource not accessible by personal access token' }, { fa: 'توکن Fine-grained یکی از دسترسی‌ها (معمولاً Secrets یا Workflows) را ندارد یا مخزن در فهرست آن نیست.', en: 'The fine-grained token lacks a permission (usually Secrets or Workflows) or the repository is not selected.' } ],
				[ { fa: 'دقایق Actions تمام شده', en: 'Actions minutes used up' }, { fa: 'مخازن خصوصی سهمیه ماهانه دارند. مصرف را در Billing گیت‌هاب ببینید.', en: 'Private repositories have a monthly quota. Check usage in GitHub Billing.' } ],
			],
			docs: [ link( 'مستندات توکن‌های دسترسی', 'Personal access tokens docs', 'https://docs.github.com/en/authentication/keeping-your-account-and-data-secure/managing-your-personal-access-tokens' ), link( 'هزینه GitHub Actions', 'GitHub Actions billing', 'https://docs.github.com/en/billing/managing-billing-for-your-products/managing-billing-for-github-actions/about-billing-for-github-actions' ) ],
		},
		{
			id: 'knowledge', group: 'infra', icon: 'book', title: { fa: 'پایگاه دانش سئو', en: 'SEO knowledge base' },
			badges: [ [ { fa: 'اختیاری', en: 'Optional' }, 'neutral' ], [ { fa: 'رایگان', en: 'Free' }, 'good' ], [ { fa: '۱ دقیقه', en: '1 min' }, 'neutral' ] ],
			why: { fa: 'به‌طور پیش‌فرض SEOlity از دانش مشترک و رایگان استفاده می‌کند. با مخزن اختصاصی، دکمه «جمع‌آوری دانش الان» یک Workflow در گیت‌هاب اجرا می‌کند که منابع سئو، کوئری‌های سرچ کنسول، صفحات سایت و رقبای شما را با هوش مصنوعی (اشتراک Claude/ChatGPT یا کلید Anthropic) تحلیل می‌کند و یک «پلی‌بوک اختصاصی سایت» (استراتژی، موضوعات، کلمات کلیدی، رقبا) در گیت‌هاب می‌نویسد؛ هوش مصنوعی در همه تولیدها از آن استفاده می‌کند.', en: 'By default SEOlity uses the shared, free knowledge base. With your own repository, “Collect knowledge now” runs a GitHub workflow that analyses SEO sources, your Search Console queries, your pages and your competitors with AI (Claude/ChatGPT subscription or an Anthropic key) and writes a site playbook (strategy, topics, keywords, competitors) to GitHub; the AI uses it in every generation.' },
			steps: [
				S( 'در «GitHub و داده» کارت «پایگاه دانش سئو» را پیدا کنید و «ساخت مخزن دانش اختصاصی» را بزنید؛ دانش فعلی، سازنده دانش و Workflow هفتگی در آن قرار می‌گیرد و SEOlity به آن وصل می‌شود.', 'In “GitHub & data” find the “SEO knowledge base” card and press “Create my knowledge repository”; the current knowledge, the builder and a weekly workflow are added and SEOlity switches to it.', { go: [ { fa: 'رفتن به GitHub و داده', en: 'Open GitHub & data' }, 'github' ] } ),
				S( 'هوش مصنوعی اشتراکی را مستقر کنید (یا کلید Anthropic را ذخیره کنید)؛ سازنده دانش از همان حساب استفاده می‌کند: CLAUDE_CODE_OAUTH_TOKEN، CODEX_AUTH_JSON یا ANTHROPIC_API_KEY.', 'Deploy subscription AI (or store an Anthropic key); the knowledge builder uses the same account: CLAUDE_CODE_OAUTH_TOKEN, CODEX_AUTH_JSON or ANTHROPIC_API_KEY.', { code: 'CLAUDE_CODE_OAUTH_TOKEN', go: [ { fa: 'هوش مصنوعی اشتراکی', en: 'Subscription AI' }, 'subscription' ] } ),
				S( 'در صفحه «پایگاه دانش» دکمه «جمع‌آوری دانش الان» را بزنید. وضعیت اجرا هر ۱۵ ثانیه به‌روز می‌شود و در پایان گزارش (منابع، اسناد ساخته‌شده، پلی‌بوک سایت) نمایش داده و دانش همگام می‌شود.', 'On the “Knowledge Base” page press “Collect knowledge now”. The run status refreshes every 15 seconds; at the end the report (sources, documents written, site playbook) is shown and the knowledge syncs.', { go: [ { fa: 'رفتن به پایگاه دانش', en: 'Open Knowledge Base' }, 'knowledge' ] } ),
				S( 'منابع دلخواه را در فایل sources.json مخزن اضافه کنید؛ «اجرای سازنده دانش» آن‌ها را خلاصه می‌کند و SEOlity همگام می‌شود.', 'Add your own sources to sources.json in the repository; “Run knowledge builder” distils them and SEOlity syncs.', { code: 'seolity/tools/knowledge-builder/sources.json' } ),
			],
			trouble: [
				[ { fa: 'همگام‌سازی خطا می‌دهد', en: 'Sync fails' }, { fa: 'توکن GitHub به مخزن دانش دسترسی ندارد یا نام شاخه/پوشه در تنظیمات پیشرفته اشتباه است.', en: 'The GitHub token cannot read the knowledge repository or the branch/folder in advanced settings is wrong.' } ],
				[ { fa: 'سازنده دانش اجرا نمی‌شود', en: 'The knowledge builder does not run' }, { fa: 'هیچ‌کدام از Secretهای CLAUDE_CODE_OAUTH_TOKEN، CODEX_AUTH_JSON یا ANTHROPIC_API_KEY در مخزن نیست یا Actions غیرفعال است. اول هوش مصنوعی اشتراکی را مستقر کنید.', en: 'None of the CLAUDE_CODE_OAUTH_TOKEN, CODEX_AUTH_JSON or ANTHROPIC_API_KEY secrets exist in the repository, or Actions is disabled. Deploy subscription AI first.' } ],
			],
			docs: [ link( 'اسرار رمزنگاری‌شده در GitHub Actions', 'Encrypted secrets in GitHub Actions', 'https://docs.github.com/en/actions/security-for-github-actions/security-guides/using-secrets-in-github-actions' ) ],
		},
		{
			id: 'storage', group: 'infra', icon: 'database', title: { fa: 'بایگانی داده در GitHub', en: 'Data archive on GitHub' },
			badges: [ [ { fa: 'اختیاری', en: 'Optional' }, 'neutral' ], [ { fa: 'رایگان', en: 'Free' }, 'good' ], [ { fa: '۱ دقیقه', en: '1 min' }, 'neutral' ] ],
			why: { fa: 'ردیف‌های قدیمی سرچ کنسول، گزارش‌ها، لاگ‌ها و نسخه پشتیبان تاریخچه تغییرات به مخزن خصوصی می‌روند تا دیتابیس هاست کوچک بماند؛ هر وقت لازم باشد بازیابی می‌شوند.', en: 'Old Search Console rows, reports, logs and a backup of the change history move to a private repository so the hosting database stays small; they can be restored whenever needed.' },
			steps: [
				S( 'در «GitHub و داده» کارت «مخزن داده» را پیدا کنید و «ساخت مخزن و فعال‌سازی بایگانی» را بزنید.', 'In “GitHub & data” find the “Data repository” card and press “Create repository & enable archive”.', { go: [ { fa: 'رفتن به GitHub و داده', en: 'Open GitHub & data' }, 'github' ] } ),
				S( 'در تنظیمات پیشرفته مشخص کنید داده‌های قدیمی‌تر از چند روز بایگانی شوند و آیا از دیتابیس وردپرس حذف شوند.', 'In advanced settings choose after how many days data is archived and whether archived rows are removed from the WordPress database.' ),
				S( 'برای بازیابی داده سرچ کنسول یک بازه تاریخ انتخاب و «بازیابی» را بزنید.', 'To restore Search Console data choose a date range and press “Restore”.' ),
			],
			trouble: [
				[ { fa: 'مخزن عمومی است', en: 'The repository is public' }, { fa: 'SEOlity در مخزن عمومی داده نمی‌نویسد. در GitHub ← Settings ← Change visibility آن را خصوصی کنید.', en: 'SEOlity does not write data to a public repository. Make it private on GitHub (Settings → Change visibility).' } ],
			],
			docs: [ link( 'محدودیت حجم مخزن‌ها', 'Repository size limits', 'https://docs.github.com/en/repositories/working-with-files/managing-large-files/about-large-files-on-github' ) ],
		},
		{
			id: 'cloudflare', group: 'infra', icon: 'cloud', title: { fa: 'کلادفلر (رله سریع و زمان‌بند)', en: 'Cloudflare (fast relay & scheduler)' }, secret: 'cloudflare_api_token',
			badges: [ [ { fa: 'اختیاری، پیشنهادی', en: 'Optional, recommended' }, 'info' ], [ { fa: 'پلن رایگان', en: 'Free plan' }, 'good' ], [ { fa: '۳ دقیقه', en: '3 min' }, 'neutral' ] ],
			why: { fa: 'یک ورکر رایگان روی حساب کلادفلر خودتان که SEOlity خودش مستقر می‌کند: پاسخ‌های هوش مصنوعی اشتراکی سریع‌تر می‌رسند (حتی برای سایت لوکال) و صف پس‌زمینه SEOlity هر چند دقیقه سر وقت اجرا می‌شود.', en: 'A free worker on your own Cloudflare account that SEOlity deploys itself: subscription AI answers arrive faster (even on a local site) and SEOlity’s background queue runs on time every few minutes.' },
			need: [ { fa: 'حساب رایگان کلادفلر (دامنه لازم نیست)', en: 'A free Cloudflare account (no domain needed)' } ],
			steps: [
				S( 'در کلادفلر ثبت‌نام کنید.', 'Sign up at Cloudflare.', { links: [ link( 'ثبت‌نام رایگان', 'Free sign-up', 'https://dash.cloudflare.com/sign-up' ) ] } ),
				S( 'صفحه API Tokens ← Create Token ← کنار «Edit Cloudflare Workers» دکمه «Use template». حساب خود را در Account Resources و «All zones» را در Zone Resources انتخاب و توکن را بسازید.', 'API Tokens → Create Token → “Use template” next to “Edit Cloudflare Workers”. Pick your account in Account Resources and “All zones” in Zone Resources, then create the token.', { links: [ link( 'صفحه API Tokens (لینک مستقیم)', 'API Tokens page (direct link)', 'https://dash.cloudflare.com/profile/api-tokens' ) ] } ),
				S( 'در صفحه «کلادفلر» توکن را بچسبانید و «ذخیره و بررسی توکن» را بزنید؛ Account ID خودکار پیدا می‌شود.', 'On the “Cloudflare relay” page paste the token and press “Save & verify token”; the Account ID is found automatically.', { go: [ { fa: 'رفتن به صفحه کلادفلر', en: 'Open Cloudflare relay' }, 'cloudflare' ] } ),
				S( '«استقرار ورکر» را بزنید. KV، کلید امضا، زمان‌بند و آدرس workers.dev ساخته می‌شوند و آدرس ورکر در Secrets اجراکننده ثبت می‌شود.', 'Press “Deploy worker”. The KV namespace, signing key, scheduler and workers.dev address are created and the worker address is stored in the runner’s secrets.' ),
				S( '«عیب‌یابی اتصال» را بزنید تا مطمئن شوید کلادفلر به سایت شما دسترسی دارد (برای سایت لوکال حالت «معکوس» خودکار انتخاب می‌شود).', 'Press “Diagnose connection” to confirm Cloudflare can reach your site (local sites use “pull” mode automatically).' ),
			],
			trouble: [
				[ { fa: 'Authentication error (10000)', en: 'Authentication error (10000)' }, { fa: 'توکن دسترسی Workers Scripts یا KV را ندارد؛ از قالب «Edit Cloudflare Workers» استفاده کنید.', en: 'The token lacks Workers Scripts or KV permissions; use the “Edit Cloudflare Workers” template.' } ],
				[ { fa: 'workers.dev subdomain', en: 'workers.dev subdomain' }, { fa: 'یک‌بار بخش Workers & Pages را در داشبورد کلادفلر باز کنید تا زیردامنه workers.dev ساخته شود، سپس دوباره مستقر کنید.', en: 'Open Workers & Pages once in the Cloudflare dashboard so your workers.dev subdomain is created, then deploy again.' } ],
				[ { fa: 'workers.dev از سرور در دسترس نیست', en: 'workers.dev is unreachable from the server' }, { fa: 'در کلادفلر یک Custom Domain به ورکر وصل کنید و آن را در «دامنه اختصاصی ورکر» وارد کنید.', en: 'Attach a Custom Domain to the worker in Cloudflare and enter it under “Custom worker domain”.' } ],
			],
			docs: [ link( 'ساخت توکن API', 'Create an API token', 'https://developers.cloudflare.com/fundamentals/api/get-started/create-token/' ), link( 'پیدا کردن Account ID', 'Find your Account ID', 'https://developers.cloudflare.com/fundamentals/account/find-account-and-zone-ids/' ), link( 'محدودیت پلن رایگان Workers', 'Workers free plan limits', 'https://developers.cloudflare.com/workers/platform/pricing/' ) ],
		},
		/* ------------------------------------------------------------ Google */
		{
			id: 'gsc', group: 'google', icon: 'chart', title: 'Google Search Console',
			badges: [ [ { fa: 'بسیار مهم', en: 'Most important' }, 'bad' ], [ { fa: 'رایگان', en: 'Free' }, 'good' ], [ { fa: '۱۵ دقیقه', en: '15 min' }, 'neutral' ] ],
			why: { fa: 'قلب هوش SEOlity: کلمات کلیدی واقعی، کلیک، نمایش، جایگاه، کنیبالیزیشن و کلمات کلیدی کانونی از همین داده انتخاب می‌شوند. دسترسی فقط خواندنی (webmasters.readonly) است.', en: 'The heart of SEOlity: real queries, clicks, impressions, positions, cannibalization and focus-keyword picks all come from this. Access is read-only (webmasters.readonly).' },
			need: [ { fa: 'حساب گوگلی که در Search Console به سایت دسترسی دارد', en: 'A Google account with access to the site in Search Console' } ],
			steps: [
				S( 'مطمئن شوید سایت در Search Console ثبت و تأیید شده است (Domain یا URL prefix).', 'Make sure the site is added and verified in Search Console (Domain or URL prefix).', { links: [ link( 'باز کردن Search Console', 'Open Search Console', 'https://search.google.com/search-console' ) ] } ),
				S( 'در Google Cloud یک پروژه جدید بسازید (نام دلخواه مثلاً SEOlity).', 'Create a new Google Cloud project (any name, e.g. SEOlity).', { links: [ link( 'ساخت پروژه (لینک مستقیم)', 'Create project (direct link)', 'https://console.cloud.google.com/projectcreate' ) ] } ),
				S( 'Google Search Console API را برای همین پروژه فعال کنید (Enable). بالای صفحه مطمئن شوید پروژه درست انتخاب شده است.', 'Enable the Google Search Console API for this project (check the project picker at the top).', { links: [ link( 'فعال‌سازی Search Console API', 'Enable Search Console API', 'https://console.cloud.google.com/apis/library/searchconsole.googleapis.com' ) ] } ),
				S( 'صفحه رضایت OAuth را بسازید: Google Auth Platform ← Branding ← Get started. نام برنامه و ایمیل پشتیبانی را وارد کنید، Audience را External بگذارید، ایمیل تماس را بدهید و Create بزنید.', 'Configure the OAuth consent screen: Google Auth Platform → Branding → Get started. Enter app name and support email, Audience = External, contact email, then Create.', { links: [ link( 'Branding (صفحه رضایت)', 'Branding (consent screen)', 'https://console.cloud.google.com/auth/branding' ) ] } ),
				S( 'مهم: در Audience روی «Publish app» بزنید تا وضعیت In production شود. در حالت Testing، اتصال هر ۷ روز قطع می‌شود. (در حالت Testing باید ایمیل خود را در Test users اضافه کنید.)', 'Important: in Audience click “Publish app” (In production). In Testing mode the connection expires every 7 days. (In Testing you must add your email under Test users.)', { links: [ link( 'Audience', 'Audience', 'https://console.cloud.google.com/auth/audience' ) ] } ),
				S( 'یک OAuth client بسازید: Clients ← Create client ← Application type: Web application.', 'Create an OAuth client: Clients → Create client → Application type: Web application.', { links: [ link( 'ساخت OAuth client (لینک مستقیم)', 'Create OAuth client (direct link)', 'https://console.cloud.google.com/auth/clients/create' ), link( 'یا از صفحه Credentials: Create credentials ← OAuth client ID', 'Or via Credentials: Create credentials → OAuth client ID', 'https://console.cloud.google.com/apis/credentials' ) ] } ),
				S( 'در «Authorized redirect URIs» دقیقاً آدرس زیر را اضافه کنید (همان که در صفحه سرچ کنسول SEOlity نمایش داده می‌شود):', 'Under “Authorized redirect URIs” add exactly this address (also shown on SEOlity’s Search Console page):', { redirect: true } ),
				S( 'Create را بزنید و Client ID و Client secret را کپی کنید (Client secret با GOCSPX- شروع می‌شود).', 'Click Create and copy the Client ID and Client secret (the secret starts with GOCSPX-).', { code: 'xxxxxxxx.apps.googleusercontent.com  /  GOCSPX-…' } ),
				S( 'در SEOlity به صفحه «سرچ کنسول» بروید، Client ID و Secret را ذخیره کنید، «اتصال حساب گوگل» را بزنید، حساب را انتخاب و اجازه دهید، سپس Property سایت را انتخاب کنید. داده‌ها در پس‌زمینه همگام می‌شوند.', 'In SEOlity open “Search Console”, save the Client ID and secret, click “Connect Google account”, choose the account and allow, then pick the site property. Data syncs in the background.', { go: [ { fa: 'رفتن به سرچ کنسول', en: 'Open Search Console page' }, 'gsc' ] } ),
			],
			trouble: [
				[ { fa: 'Error 400: redirect_uri_mismatch', en: 'Error 400: redirect_uri_mismatch' }, { fa: 'آدرس Redirect در Google Cloud دقیقاً با آدرس SEOlity یکی نیست (http/https، www، اسلش آخر). آن را از همین صفحه کپی کنید. تغییرات تا چند دقیقه طول می‌کشد.', en: 'The redirect URI in Google Cloud does not exactly match SEOlity’s (http/https, www, trailing slash). Copy it from this page. Changes can take a few minutes.' } ],
				[ { fa: 'Google hasn’t verified this app', en: '“Google hasn’t verified this app”' }, { fa: 'برای برنامه شخصی خودتان طبیعی است: Advanced ← Go to … (unsafe) را بزنید. برنامه فقط به داده‌های شما با دسترسی خواندنی وصل می‌شود.', en: 'Normal for your own app: click Advanced → Go to … (unsafe). It only reads your own data.' } ],
				[ { fa: 'Error 403: access_denied', en: 'Error 403: access_denied' }, { fa: 'برنامه در حالت Testing است و ایمیل شما Test user نیست؛ آن را اضافه کنید یا Publish app بزنید.', en: 'The app is in Testing and your email is not a test user; add it or click Publish app.' } ],
				[ { fa: 'هیچ Property نمایش داده نمی‌شود', en: 'No properties are listed' }, { fa: 'حساب گوگلی که وصل کردید به سایت در Search Console دسترسی ندارد. در Search Console ← Settings ← Users and permissions آن را اضافه کنید.', en: 'The connected Google account has no access in Search Console. Add it under Settings → Users and permissions.' } ],
				[ { fa: 'اتصال بعد از یک هفته قطع شد', en: 'Disconnected after a week' }, { fa: 'برنامه در حالت Testing مانده است. Publish app بزنید و دوباره وصل شوید.', en: 'The app is still in Testing. Publish it and reconnect.' } ],
				[ { fa: 'داده‌ها امروز را نشان نمی‌دهند', en: 'No data for today' }, { fa: 'داده‌های Search Console حدود ۲ تا ۳ روز تأخیر دارند.', en: 'Search Console data lags about 2–3 days.' } ],
			],
			docs: [ link( 'راهنمای Search Console API', 'Search Console API guide', 'https://developers.google.com/webmaster-tools/v1/how-tos/authorizing' ), link( 'تنظیم صفحه رضایت OAuth', 'Configure OAuth consent', 'https://developers.google.com/workspace/guides/configure-oauth-consent' ), link( 'افزودن سایت به Search Console', 'Add a property', 'https://support.google.com/webmasters/answer/34592' ) ],
		},
		{
			id: 'pagespeed', group: 'google', icon: 'zap', title: 'PageSpeed Insights API', secret: 'pagespeed_api_key',
			badges: [ [ { fa: 'اختیاری', en: 'Optional' }, 'neutral' ], [ { fa: 'رایگان', en: 'Free' }, 'good' ], [ { fa: '۵ دقیقه', en: '5 min' }, 'neutral' ] ],
			why: { fa: 'برای Core Web Vitals و امتیاز سرعت. بدون کلید هم کار می‌کند اما سهمیه بسیار کم است.', en: 'For Core Web Vitals and speed scores. Works without a key but with a very small quota.' },
			need: [ { fa: 'همان پروژه Google Cloud', en: 'The same Google Cloud project' } ],
			steps: [
				S( 'PageSpeed Insights API را در پروژه فعال کنید.', 'Enable the PageSpeed Insights API in your project.', { links: [ link( 'فعال‌سازی PageSpeed API', 'Enable PageSpeed API', 'https://console.cloud.google.com/apis/library/pagespeedonline.googleapis.com' ) ] } ),
				S( 'در Credentials روی Create credentials ← API key بزنید.', 'In Credentials click Create credentials → API key.', { links: [ link( 'صفحه Credentials', 'Credentials page', 'https://console.cloud.google.com/apis/credentials' ) ] } ),
				S( 'برای امنیت، کلید را محدود کنید: API restrictions ← فقط PageSpeed Insights API.', 'For safety restrict the key: API restrictions → PageSpeed Insights API only.' ),
				S( 'در SEOlity: تنظیمات ← خزنده و ممیزی ← «PageSpeed Insights API key» ← Save.', 'In SEOlity: Settings → Crawler & audits → “PageSpeed Insights API key” → Save.', { go: [ { fa: 'رفتن به تنظیمات خزنده', en: 'Open crawler settings' }, 'settings?tab=crawler' ] } ),
			],
			trouble: [ [ { fa: 'API key not valid', en: 'API key not valid' }, { fa: 'API در همان پروژه کلید فعال نیست یا محدودیت کلید اشتباه است.', en: 'The API is not enabled in the key’s project, or the key restriction is wrong.' } ] ],
			docs: [ link( 'شروع PageSpeed API', 'PageSpeed API get started', 'https://developers.google.com/speed/docs/insights/v5/get-started' ) ],
		},
		/* ------------------------------------------------------------ SERP */
		{
			id: 'serpapi', group: 'other', icon: 'search', title: 'SerpApi (live SERP)', secret: 'serp_api_key',
			badges: [ [ { fa: 'اختیاری', en: 'Optional' }, 'neutral' ], [ { fa: 'پلن رایگان محدود', en: 'Limited free plan' }, 'good' ], [ { fa: '۳ دقیقه', en: '3 min' }, 'neutral' ] ],
			why: { fa: 'تحلیل زنده نتایج گوگل (رقبا، ویژگی‌های SERP، People Also Ask) برای هر کلمه کلیدی.', en: 'Live Google results analysis (competitors, SERP features, People Also Ask) for any keyword.' },
			need: [ { fa: 'ایمیل برای ثبت‌نام', en: 'An email to sign up' } ],
			steps: [
				S( 'در SerpApi ثبت‌نام کنید و ایمیل را تأیید کنید.', 'Sign up at SerpApi and verify your email.', { links: [ link( 'ثبت‌نام', 'Sign up', 'https://serpapi.com/users/sign_up' ) ] } ),
				S( 'کلید را از صفحه Your Private API Key کپی کنید.', 'Copy the key from “Your Private API Key”.', { links: [ link( 'صفحه کلید (لینک مستقیم)', 'API key page (direct link)', 'https://serpapi.com/manage-api-key' ) ] } ),
				S( 'در SEOlity: تنظیمات ← خزنده و ممیزی ← «SERP data API key» ← Save.', 'In SEOlity: Settings → Crawler & audits → “SERP data API key” → Save.', { go: [ { fa: 'رفتن به تنظیمات خزنده', en: 'Open crawler settings' }, 'settings?tab=crawler' ] } ),
			],
			trouble: [ [ { fa: 'Your account has run out of searches', en: '“Your account has run out of searches”' }, { fa: 'سهمیه ماهانه تمام شده؛ ماه بعد یا پلن بالاتر.', en: 'Monthly quota reached; wait for next month or upgrade.' } ] ],
			docs: [ link( 'قیمت‌ها و پلن رایگان', 'Pricing & free plan', 'https://serpapi.com/pricing' ), link( 'مستندات Google Search API', 'Google Search API docs', 'https://serpapi.com/search-api' ) ],
		},
	];

	var GROUPS = [
		[ 'ai', { fa: 'هوش مصنوعی با کلید API', en: 'AI with an API key' }, 'cpu' ],
		[ 'sub', { fa: 'هوش مصنوعی با اشتراک (Claude / ChatGPT) از طریق GitHub', en: 'AI with your subscription (Claude / ChatGPT) via GitHub' }, 'sparkles' ],
		[ 'infra', { fa: 'GitHub، کلادفلر، دانش و داده', en: 'GitHub, Cloudflare, knowledge & data' }, 'github' ],
		[ 'google', { fa: 'گوگل (سرچ کنسول و سرعت)', en: 'Google (Search Console & speed)' }, 'chart' ],
		[ 'other', { fa: 'سایر سرویس‌ها', en: 'Other services' }, 'search' ],
	];

	function copyBtn( text ) {
		return h( 'button', { type: 'button', class: 'sl-btn sl-btn--sm sl-btn--icon', title: t( 'Copy' ), 'aria-label': t( 'Copy' ), onClick: function () {
			( navigator.clipboard ? navigator.clipboard.writeText( text ) : Promise.reject() ).then( function () {
				SL.toast( t( 'Copied.' ) );
			} ).catch( function () {
				SL.toast( text );
			} );
		} }, SL.icon( 'copy', 15 ) );
	}
	function codeBlock( text ) {
		return h( 'div', { class: 'sl-guide__code', dir: 'ltr' }, h( 'code', { text: text } ), copyBtn( text ) );
	}
	function extLink( l ) {
		return h( 'a', { class: 'sl-guide__link', href: l[ 1 ], target: '_blank', rel: 'noopener noreferrer' }, SL.icon( 'external', 14 ), h( 'span', { text: L( l[ 0 ] ) } ), h( 'small', { dir: 'ltr', text: l[ 1 ].replace( /^https:\/\//, '' ).slice( 0, 60 ) } ) );
	}

	function serviceCard( s, status, open ) {
		var body = h( 'div', { class: 'sl-guide__body' },
			h( 'p', { class: 'sl-guide__why', text: L( s.why ) } ),
			s.need && s.need.length ? h( 'div', { class: 'sl-guide__need' }, h( 'strong', { text: t( 'You need' ) } ), h( 'ul', null, s.need.map( function ( n ) {
				return h( 'li', { class: 'sl-iconline' }, SL.icon( 'check', 14, 'is-good' ), h( 'span', { text: L( n ) } ) );
			} ) ) ) : null,
			h( 'ol', { class: 'sl-guide__steps' }, s.steps.map( function ( st ) {
				return h( 'li', null,
					h( 'div', { text: L( st.text ) } ),
					st.links ? h( 'div', { class: 'sl-guide__links' }, st.links.map( extLink ) ) : null,
					st.code ? codeBlock( st.code ) : null,
					st.redirect ? codeBlock( status.redirect || ( C.adminUrl || '' ) + 'admin-post.php' ) : null,
					st.go ? h( 'a', { class: 'sl-btn sl-btn--primary sl-btn--sm sl-guide__go', href: '#/' + st.go[ 1 ] }, SL.icon( 'arrow-' + ( C.rtl ? 'left' : 'right' ), 15 ), h( 'span', { text: L( st.go[ 0 ] ) } ) ) : null
				);
			} ) ),
			s.trouble && s.trouble.length ? h( 'div', { class: 'sl-guide__trouble' }, h( 'strong', { class: 'sl-iconline' }, SL.icon( 'wrench', 16 ), h( 'span', { text: t( 'Troubleshooting' ) } ) ), s.trouble.map( function ( x ) {
				return h( 'details', null, h( 'summary', { dir: 'auto', text: L( x[ 0 ] ) } ), h( 'p', { text: L( x[ 1 ] ) } ) );
			} ) ) : null,
			s.docs && s.docs.length ? h( 'div', { class: 'sl-guide__docs' }, h( 'strong', { class: 'sl-iconline' }, SL.icon( 'book', 16 ), h( 'span', { text: t( 'Official documentation' ) } ) ), h( 'div', { class: 'sl-guide__links' }, s.docs.map( extLink ) ) ) : null
		);
		var state = status[ s.id ];
		var det = h( 'details', { class: 'sl-guide__svc', id: 'guide-' + s.id },
			h( 'summary', null,
				h( 'span', { class: 'sl-card__ic' }, SL.icon( s.icon, 18 ) ),
				h( 'span', { class: 'sl-guide__title' }, h( 'strong', { text: L( s.title ) } ), h( 'span', { class: 'sl-guide__badges' }, s.badges.map( function ( b ) {
					return SL.badge( L( b[ 0 ] ), b[ 1 ] );
				} ) ) ),
				state === true ? h( 'span', { class: 'sl-badge sl-badge--good sl-iconline' }, SL.icon( 'check', 13 ), h( 'span', { text: t( 'Connected' ) } ) ) : state === false ? SL.badge( t( 'Not set' ), 'neutral' ) : null,
				SL.icon( 'chevron-down', 18, 'sl-guide__chev' )
			),
			body
		);
		if ( open ) {
			det.open = true;
		}
		return det;
	}

	App.pages.guide = function ( v, q ) {
		v.appendChild( App.header( t( 'Connection guide' ), t( 'Step-by-step instructions with direct links for every key and account SEOlity can use. Open a service, follow the numbered steps, paste the key where shown and press Test.' ) ) );
		var body = h( 'div' );
		v.appendChild( body );
		var boot = App.state.boot || {};
		var statusP = App.cap( 'manage' ) ? SL.api( 'settings' ).catch( function () {
			return null;
		} ) : Promise.resolve( null );
		App.load( body, statusP, function ( st ) {
			var sec = st && st.secrets ? st.secrets : {};
			var status = { redirect: st ? st.gsc_redirect_uri : '' };
			SERVICES.forEach( function ( s ) {
				if ( s.secret && st ) {
					status[ s.id ] = !! sec[ s.secret ];
				}
			} );
			if ( st ) {
				status.gsc = !! ( boot.gsc && boot.gsc_property );
				status.ollama = st.settings && st.settings.ai_provider === 'ollama' ? true : undefined;
			}

			// Quick chooser.
			var rows = [
				[ { fa: 'فقط می‌خواهم سریع شروع کنم (رایگان)', en: 'I just want to start quickly (free)' }, 'gemini' ],
				[ { fa: 'بهترین کیفیت محتوای فارسی و انگلیسی', en: 'Best quality for Persian & English content' }, 'anthropic' ],
				[ { fa: 'اشتراک Claude یا ChatGPT دارم و نمی‌خواهم هزینه API بدهم', en: 'I have a Claude or ChatGPT plan and don’t want API costs' }, 'sub_claude' ],
				[ { fa: 'یک کلید برای همه مدل‌ها، با مدل‌های رایگان', en: 'One key for all models, including free ones' }, 'openrouter' ],
				[ { fa: 'هیچ داده‌ای نباید از سرور من خارج شود', en: 'No data may leave my server' }, 'ollama' ],
				[ { fa: 'سرویس دیگری دارم (DeepSeek، Groq، Azure…)', en: 'I use another service (DeepSeek, Groq, Azure…)' }, 'custom' ],
				[ { fa: 'داده واقعی گوگل (ضروری برای همه تحلیل‌ها)', en: 'Real Google data (needed for every analysis)' }, 'gsc' ],
			];
			body.appendChild( App.card( t( 'Which one do I need?' ), h( 'div', { class: 'sl-guide__chooser' }, rows.map( function ( r ) {
				var s = SERVICES.filter( function ( x ) {
					return x.id === r[ 1 ];
				} )[ 0 ];
				return h( 'button', { type: 'button', class: 'sl-guide__choice', onClick: function () {
					var el = d.getElementById( 'guide-' + r[ 1 ] );
					if ( el ) {
						el.open = true;
						el.scrollIntoView( { behavior: 'smooth', block: 'start' } );
					}
				} }, h( 'span', { text: L( r[ 0 ] ) } ), h( 'strong', { class: 'sl-iconline' }, SL.icon( s.icon, 15 ), h( 'span', { text: L( s.title ) } ) ) );
			} ) ), { icon: 'lifebuoy', sub: t( 'You need one AI option plus Google Search Console. Everything else is optional.' ) } ) );

			body.appendChild( h( 'div', { class: 'sl-alert sl-alert--info sl-iconline' }, SL.icon( 'info', 18 ), h( 'span', { text: L( { fa: 'کلیدها رمزنگاری‌شده در دیتابیس ذخیره می‌شوند و هرگز دوباره به مرورگر ارسال نمی‌شوند. درخواست‌های API از سرور هاست شما ارسال می‌شوند؛ هر ارائه‌دهنده فقط در کشورهای مشخصی سرویس می‌دهد (لینک «کشورهای پشتیبانی‌شده» در هر راهنما).', en: 'Keys are stored encrypted and never sent back to the browser. API requests are made from your hosting server; each provider only serves its supported countries (see “Supported countries” in each guide).' } ) } ) ) );

			GROUPS.forEach( function ( g ) {
				var list = SERVICES.filter( function ( s ) {
					return s.group === g[ 0 ];
				} );
				body.appendChild( h( 'h2', { class: 'sl-guide__group sl-iconline' }, SL.icon( g[ 2 ], 20 ), h( 'span', { text: L( g[ 1 ] ) } ) ) );
				list.forEach( function ( s ) {
					body.appendChild( serviceCard( s, status, q.service === s.id ) );
				} );
			} );
			if ( q.service ) {
				setTimeout( function () {
					var el = d.getElementById( 'guide-' + q.service );
					if ( el ) {
						el.scrollIntoView( { behavior: 'smooth', block: 'start' } );
					}
				}, 50 );
			}
		} );
	};

	App.guideData = { services: SERVICES, groups: GROUPS }; // Also read by tools/docs/build_connect.mjs.

	/** Secret key → guide id (used by the Settings page "Guide" links). */
	App.guideFor = function ( secret ) {
		var s = SERVICES.filter( function ( x ) {
			return x.secret === secret;
		} )[ 0 ];
		return s ? s.id : null;
	};
} )( window, document );
