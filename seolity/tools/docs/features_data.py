# Feature catalog data for build_features.py. Edition "1.0" = implemented; "Pro"/"Ent" = roadmap.
CATEGORIES = []


def cat(name, features, intro='', deps='—', ui='—', auto='Manual', risk='None', db='—', api='—', ed='1.0'):
    CATEGORIES.append(dict(name=name, features=features, intro=intro, deps=deps, ui=ui, auto=auto, risk=risk, db=db, api=api, ed=ed))


cat('AI SEO Engine & Providers', intro='The provider-agnostic AI layer every intelligent feature runs on.',
    deps='AI provider key or local LLM', ui='Settings → AI', auto='Auto', db='ai_requests, ai_cache', api='Provider APIs', features='''
Provider abstraction | One interface for all LLM vendors | Switch vendors without losing features | Provider id, model | Normalized completion | db=—
Anthropic Claude provider | Messages API with structured JSON output and effort control | Highest-quality SEO writing and reasoning | API key, model (default claude-opus-5-5) | Schema-valid JSON | api=api.anthropic.com
Refusal fallback | Re-runs a declined request on Anthropic's recommended model server-side | Fewer failed AI jobs | Anthropic response stop_reason | Completed answer or clear refusal error | api=api.anthropic.com (server-side-fallback beta)
OpenAI provider | Chat Completions with strict json_schema | Use existing OpenAI contracts | API key, model | Schema-valid JSON | api=api.openai.com
Google Gemini provider | generateContent with responseJsonSchema | Low-cost option | API key, model | Schema-valid JSON | api=generativelanguage.googleapis.com
OpenRouter provider | Access hundreds of models through one key | Model choice and price flexibility | API key, model slug | Schema-valid JSON | api=openrouter.ai
Local LLM (Ollama) | Run models on your own server | Privacy, zero per-token cost | Base URL, model | Schema-valid JSON | api=Ollama /api/chat
Custom OpenAI-compatible API | Any compatible endpoint (vLLM, LM Studio, Azure-style gateways) | Bring your own infrastructure | Base URL, key, model | Schema-valid JSON | api=Custom endpoint
Provider extension filter | Register new providers from other plugins | Extensible without forking | PHP class | New provider in Settings | ui=Code (seolity_ai_providers)
Versioned task catalog | 22 SEO tasks each with prompt version, schema and topics | Reproducible, comparable AI behavior | Task id, input JSON | Structured result + prompt_version | db=changes.prompt_version
Strict JSON schema builder | All properties required, no extra keys | Output the code can trust | Schema definition | Provider-ready schema | db=—
Schema validation + self-repair | Validates output and asks the model once to fix errors | Robust results from any model | Raw model output | Valid JSON or error | db=—
White-hat guardrails | Policy prompt + inspection for stuffing, hidden text, removed links, invented figures | Protects rankings and brand | Original + AI output | Warnings / rejection | risk=None
Response cache | Reuses identical AI answers for a configurable time | Lower cost, faster UI | Task + input hash | Cached result | db=ai_cache
Usage & cost logging | Tokens, cost, duration, status per request | Cost transparency | Every AI call | Usage report | ui=Settings → AI → Usage; api=GET /ai/usage
Monthly budget cap | Stops AI calls when the monthly budget is reached | No surprise bills | Budget (USD) | Blocked calls with clear message | db=ai_requests
Effort control | low…max reasoning effort for supported models | Trade quality vs. speed/cost | Effort setting | Provider parameter | db=—
Brand voice | Injects your tone of voice into every prompt | On-brand output | Brand voice text | Prompt context | db=—
Content language control | Force output language or auto-detect from content | Correct language for multilingual sites | Language setting | Prompt instruction | db=—
Knowledge-grounded prompts (RAG) | Injects best-practice chunks relevant to the task | Expert-level, up-to-date guidance | Task topics + query | Knowledge context in prompt | db=knowledge
Site-learning injection | Adds what worked on this site (experiment results) to prompts | AI improves with your own data | Learnings | Prompt context | db=learnings
Connection test | Sends a tiny structured request to verify the provider | Fast setup troubleshooting | Provider settings | OK + model + sample | api=POST /settings/test-ai
Encrypted API keys | Keys encrypted at rest, never sent to browser | Security | Key input | Encrypted secret | db=wp_options (seolity_secrets)
Claude subscription provider (Pro/Max) | Runs every AI feature with Claude Code on GitHub Actions using your Claude plan's token | No pay-per-token API bill | claude setup-token token | Schema-valid JSON | deps=GitHub token, Claude Pro/Max; api=GitHub API, GitHub Actions; ui=Settings → Subscription AI
ChatGPT subscription provider (Plus/Pro) | Runs AI features with the official OpenAI Codex CLI using your ChatGPT login | Use the plan you already pay for | ~/.codex/auth.json | Schema-valid JSON | deps=GitHub token, ChatGPT Plus/Pro; api=GitHub API, GitHub Actions; ui=Settings → Subscription AI
One-click runner setup | Creates the private runner repo, pushes the workflow/runner, queue branch and Actions secrets | Setup in one click | Repo name, tokens | Ready runner | deps=GitHub token; api=GitHub API; ui=Settings → Subscription AI; auto=Manual
SEO roadmap | On-demand, knowledge-grounded analysis of Search Console, audit, content, new posts and competitors into a phased plan with diagnosis, focus, strengths and risks | Know exactly what to do, in which order | "Analyze" button | Versioned roadmap with tasks, targets and guides | db=roadmaps; api=GET /roadmap, POST /roadmap/analyze; ui=AI Center → SEO roadmap
Roadmap memory & stability | Previous plan and owner statuses go into the next analysis; priorities change only on material data changes; diff of new / solved / changed tasks | A plan you can follow over weeks | Previous version | Stable priorities + change log | db=roadmaps, wp_options (seolity_roadmap_status)
Step-by-step task guides | Each task: why (numbers), affected pages with edit/view/AI actions, AI plan for the site, exact dashboard steps for the active SEO plugin, done criteria | Anyone can execute SEO work correctly | Task | Guide popup | ui=SEO roadmap → task
Indexing watch for new content | New posts with no Search Console impressions, with noindex / internal-link / sitemap checks and a URL-inspection link per page | New articles start earning traffic sooner | Recent posts + GSC | "Get new pages indexed" task | api=GET /roadmap
Task dock | Small non-blocking cards for AI edits (optimizer incl. Elementor, product optimizer, titles) that stay with the report until closed | Keep working while AI edits; see what was changed | AI job | Progress + report card | ui=everywhere in wp-admin
AI agent "Do it" | Popup with exact manual steps and the AI plan; on approval the agent executes (titles, content, FAQ, redirects, redirect chains, internal links written from related posts across the site) with live steps | SEO work done for you, transparently | Recommendation | Applied, versioned changes + live log | api=GET /recommendations/{id}/guide, POST /agent/run; ui=Dashboard / SEO roadmap → Do it
Activity: live & completed | Live view of running work (steps, route, progress, queue) and an exact timeline of everything done on the site (AI answers with route and errors, edits, data collection) | Full transparency | All plugin actions | Activity log | db=activity; api=GET /activity/live, GET /activity; ui=AI Center → Activity
Persistent AI job cards | AI edits run on the server and their cards follow you across admin pages and reloads until reviewed | Never lose an AI result | AI job | Dock card + report | api=POST /ai-jobs, GET /activity/dock
Persistent chat | Conversations, page-specific questions, answers that arrive after a reload, retry, route/model/time per answer | Reliable AI assistant | Question | Answer | api=POST /chat, GET /chat/{cid}; ui=AI Center → AI SEO chat
Subscription CLI on own server | Runs the official Claude Code / Codex CLI on the host for Direct mode, never GitHub | Direct subscription answers | CLI path | Synchronous answer | api=POST /network/local-test; ui=Connections → Server & speed
Host region & route choice | Iran or abroad; Auto, Direct, Cloudflare or GitHub route for AI and Google requests, with automatic fall-through | AI works from sanctioned / filtered hosts, direct speed abroad | Host country, route mode | Route used per request | api=GET/POST /network; ui=Connections → Server & speed
Encrypted Cloudflare proxy | The owner's worker calls the AI / Google API; request and answer AES-256-GCM sealed and HMAC-signed | Synchronous relay with ~1 s overhead | Sealed request | Sealed answer | deps=Cloudflare account (free); api=worker /proxy
Route speed test | Measures direct, Cloudflare and GitHub against the active AI API and orders relays by speed | Always the fastest working route | Active provider | Ping + expected extra time per answer | api=POST /network/probe
Warm GitHub runner | Runner polls the queue every 2 s and stays warm N minutes; running runs are reused; chat and optimizer pre-start it | Answers in seconds instead of ~40 s | Warm minutes | Fast subscription / GitHub answers | api=POST /ai/warm
One-shot subscription deployment | Validates the token, creates/repairs the private repo, enables Actions and the workflow, stores and verifies secrets, activates the provider — with a step checklist | Subscription AI really ready in one click, no "not configured" surprises | Claude token or ChatGPT auth.json, GitHub token | Verified, active provider | deps=GitHub token; api=POST /subscription/deploy, GET /subscription/verify; ui=Connections → Subscription AI
Market evidence in prompts | Content-writing tasks receive cached SERP top results and People-Also-Ask, matching competitor pages (length, FAQ, schema) and this site's Search Console standing for the focus keyword | Content built to beat what ranks, not generic text | Focus keyword / title | Evidence block in the system prompt | db=competitor_pages, gsc_page_query
Media AI (ALT, title, caption, description) | Button in the media modal, Media Library and attachment screen; optional uploader hint; uses the parent page, focus keyword, knowledge and competitor data; bulk action and optional auto-ALT on upload | Every image optimized where it is uploaded | Attachment + context | Filled fields + suggested file name | api=POST /images/suggest; ui=Media Library, media modal
Site knowledge collection | "Collect knowledge now" runs the knowledge workflow on GitHub with the subscription engine (Claude Code / Codex) or an API key and writes a site playbook (strategy, topics, keywords, competitors) from GSC queries, pages and competitors | AI that knows this site and market | Site brief (GSC, pages, competitors) | knowledge/site/<host>/*.md + run report | deps=GitHub token, AI account; api=POST/GET /github/knowledge/collect; ui=Knowledge Base
Encrypted job queue on GitHub | Requests/answers stored AES-256-GCM encrypted on a queue branch; many requests batched in one commit | Privacy, no readable content in the repo | AI requests | Encrypted jobs/results | deps=GitHub token; db=remote_jobs; api=GitHub API
Transparent waiting (UI + queue) | REST answers 202 and the admin UI waits and re-sends; queue jobs re-schedule without using retries | Every AI button works with subscriptions unchanged | Deferred request | Final answer | db=remote_jobs, jobs; api=GET /ai/remote/{id}
Signed result callback | Runner posts results to the site with an HMAC signature | Answers in seconds when the site is public | Encrypted result | Stored answer | api=POST /remote/callback
Local-site mode | Sites behind localhost/NAT collect results from GitHub themselves | Works without a public URL or Cloudflare | Results branch | Stored answers | db=remote_jobs; api=GitHub API
ChatGPT login auto-refresh | Refreshed Codex auth.json returns encrypted and updates the local copy and the GitHub secret | No manual re-login | Runner result | Updated secret | deps=ChatGPT Plus/Pro
Subscription quota detection | Usage-limit errors are reported as such | Clear next step (wait or switch provider) | CLI error | Clear message
Per-task model routing | Use a cheaper model for simple tasks and a stronger one for writing | Lower cost | Task → model map | Routed requests | ed=Pro
Batch API mode | Send bulk jobs through provider batch APIs at lower price | ~50% cheaper bulk AI | Bulk queue | Batched results | ed=Pro
''')

cat('AI Content Optimizer', intro='"Optimize with SEOlity AI" everywhere content is edited.',
    deps='AI provider', ui='Editors (Gutenberg, Classic, Elementor), Content page', auto='Assisted', risk='High', db='changes, content_analysis', api='POST /optimize', features='''
Optimize post content | Rewrites content for intent, coverage and readability while keeping facts | Better rankings with less editing time | Post content, focus keyword, goal | Optimized HTML + explanation
Optimize page content | Same optimizer for pages | Consistent quality site-wide | Page content | Optimized HTML
Optimize custom post types | Works for any public post type | No content left behind | CPT content | Optimized HTML
Optimize product description | Product-aware rewrite (specs, benefits, FAQs) | Higher conversions and rankings | Product data | Optimized description | deps=AI provider, WooCommerce
Optimize short description | Concise WooCommerce excerpt with key specs | Better above-the-fold persuasion | Product data | Short description | deps=AI provider, WooCommerce; risk=Medium
Optimize excerpt | Post excerpt rewrite | Better snippets and archive pages | Post | Excerpt | risk=Medium
Optimize category / tag descriptions | Term descriptions with useful buying/intro text | Stronger category pages | Term | Term description | ui=Term edit screen; risk=Medium
Optimize author bio | E-E-A-T-friendly author descriptions | Trust signals | User profile | Bio | ui=User profile; risk=Medium
Gutenberg sidebar | Optimizer, live score and quick actions in the block editor | Optimize without leaving the editor | Editor content | Applied blocks | ui=Gutenberg → SEOlity sidebar
Classic editor button | Button above the editor + meta box | Works on classic sites | Editor content | Applied content | ui=Classic editor
Elementor panel integration | Optimize Elementor widgets' text in place | Page-builder support | Elementor document | Updated widgets | ui=Elementor editor; deps=AI provider, Elementor
Original / Suggested / Compare / HTML tabs | Side-by-side review modes | Confident decisions | Original + suggestion | Visual review | risk=None
Word-level diff | Myers diff (word level, line fallback for large texts) | See exactly what changed | Two texts | Highlighted diff | risk=None; auto=Auto; db=—; api=—
Sandboxed HTML preview | Renders suggestions in a script-free iframe | Safe previews | HTML | Preview | risk=None; db=—; api=—
Score before / after | Explainable SEO score of original vs suggestion | Measurable improvement | Both versions | Two scores + deltas | risk=None
Keyword coverage metric | Presence of focus keyword and variants in key places | Fix gaps quickly | Content, keyword | 0–100 metric | risk=None; auto=Auto
Intent match metric | Content format vs detected search intent | Avoid intent mismatch | Content, keyword | Metric + hint | risk=None; auto=Auto
Readability metric | Sentence/paragraph length, structure (Persian-aware) | Easier reading | Content | Metric | risk=None; auto=Auto
Semantic & entity coverage | Coverage of AI-identified related terms and entities | Topical depth | Content + AI terms | Metrics + chips | risk=None
Content depth metric | Words, headings, lists, media relative to intent | Right-sized content | Content | Metric | risk=None; auto=Auto
Change explanation | Summary and per-area reasons for each change | Understand and learn | AI output | "What changed and why" list | risk=None
Apply / Reject / Copy | Apply into editor, reject, or copy HTML | Full control | Suggestion | Editor update or log | ui=Optimizer modal
Apply to editor with version on save | Applied text becomes a versioned change when the post is saved | Undo any time | Editor apply + save_post | Applied change version | api=POST /changes/{id}/editor-applied
Custom field optimization | Optimize registered text meta fields | Works with ACF/meta-based themes | Field key | Optimized value | ed=Pro
Tone / length presets | One-click "shorter", "more persuasive", "simpler" variants | Faster iteration | Preset | New suggestion | ed=Pro
''')

cat('Product & WooCommerce SEO', intro='A complete product package from one click, built only from facts in the product data.',
    deps='WooCommerce, AI provider', ui='Products page, product edit screen', auto='Assisted', risk='Medium', db='changes', api='POST /products/{id}/optimize', features='''
AI product optimizer | One request produces title, descriptions, meta, keywords, FAQ, ALT and links | Hours of product copywriting in minutes | Product data, GSC queries, similar products | Product SEO package
Product title optimization | Brand + model + differentiator pattern, no stuffing | Clear, clickable titles | Product title, attributes | Optimized title | risk=High
Full description rewrite | Benefits, specs table, usage/care, FAQs from real attributes | Higher conversion and rankings | Description, attributes | HTML description | risk=High
Short description | 2–4 benefit bullets near add-to-cart | Faster purchase decisions | Product data | HTML short description
Product meta title & description | Snippet copy matched to purchase intent | Higher CTR | Product data | Meta tags | risk=Low
Focus keyword detection | Picks the primary keyword from data + GSC | Correct targeting | Product, GSC queries | Focus keyword | risk=Low
Secondary keywords | Variants and attributes users search | Wider query coverage | Product data | Keyword list | risk=None
Long-tail keywords | Specific model/use-case phrases | Easier wins, higher conversion | Product data | Keyword list | risk=None
Semantic keywords | Related terms for topical depth | Better relevance | Product data | Term list | risk=None
Search intent classification | Informational / commercial / transactional / navigational | Right page format | Product data | Intent | risk=None
Benefits extraction | Turns features into customer benefits | Persuasive copy | Attributes | Benefit list | risk=None
Feature → benefit analysis | Pairs every feature with its benefit | Clear value communication | Attributes | Table | risk=None
Content weaknesses | Lists what's missing or weak in current copy | Prioritized fixes | Current copy | Weakness list | risk=None
Product FAQ generation | Questions buyers ask, answered from real data | Fewer support questions, richer pages | Product data | FAQ list
CTA suggestions | Call-to-action ideas matched to intent | More conversions | Product data | CTA list | risk=None
Missing attributes report | Lists facts the AI refused to invent (dimensions, warranty…) | Data quality, no hallucinations | Product data | Missing attribute list | risk=None
Category suggestions | Suggests better/extra categories | Better architecture | Product + category tree | Category list | risk=None
Tag suggestions | Suggests product tags | Better filtering and internal linking | Product data | Tag list
Product image ALT | Descriptive ALT/caption per gallery image | Image search + accessibility | Images, product data | ALT + caption per image | risk=Low
Product internal links | Anchors to related categories/guides/products | Stronger site graph | Product + candidate URLs | Link suggestions
Product schema issues | Checks Product/Offer markup completeness | Rich result eligibility | Product + schema | Issue list | risk=None
Competitor gaps for product | Notes what competitor snippets cover that you don't | Competitive copy | Competitor snippets | Gap list | risk=None
Products without short description | Filter to find gaps quickly | Fast triage | Product catalog | Filtered list | auto=Manual; risk=None; api=GET /products
Product SEO list with scores | Catalog view with SEO score and issues | See weakest products | Products | Table | auto=Auto; risk=None; api=GET /products
Bulk product optimization | Queue AI optimization for many products | Scale to large catalogs | Product ids | Pending changes per product | auto=Assisted; api=POST /bulk
Product schema output | Product/Offer/AggregateRating JSON-LD from real data (IRR currency handling) | Rich results | Product data | JSON-LD | auto=Auto; risk=Low; ui=Front end; api=—
Optimize-product recommendations | Action center suggests weak products to optimize | Focus on revenue pages | Scores + GSC | Recommendations | auto=Scheduled; risk=None; db=recommendations; api=GET /recommendations
Strengthen-category recommendations | Finds categories with traffic potential and thin content | Better money pages | Terms + GSC | Recommendations | auto=Scheduled; risk=None; db=recommendations
Variant-level SEO | Per-variation titles/ALT for variable products | Long-tail variant traffic | Variations | Suggestions | ed=Pro
Merchant Center feed checks | Validates price/availability consistency for Shopping | Fewer feed disapprovals | Products + feed | Issue list | ed=Pro; api=Merchant API
''')

cat('Image SEO', intro='Media library intelligence: detect weak ALT text and fix it safely in bulk.',
    deps='—', ui='Images page', auto='Auto', risk='None', db='images', api='GET /images', features='''
Media library scan | Indexes every image with ALT, size, dimensions, usage | Complete visibility | Attachments | Image inventory | api=POST /images/scan
Missing ALT detection | Finds images without ALT | Accessibility + image SEO | Inventory | Issue flags
Generic ALT detection | Flags "image", "photo", "untitled" style ALT | Meaningful descriptions | ALT text | Issue flags
Keyword-stuffed ALT detection | Flags repeated keywords and lists | Avoid spam signals | ALT text | Issue flags
Duplicate ALT detection | Same ALT reused across images | Unique descriptions | ALT texts | Issue flags
Filename-as-ALT detection | ALT equals the file name | Real descriptions | ALT + filename | Issue flags
Camera filename detection | IMG_1234 / DSC0001 style file names | Descriptive filenames | Filenames | Issue flags
Over-long ALT detection | ALT longer than screen readers handle well | Better accessibility | ALT text | Issue flags
AI ALT generation | Context-aware ALT from page topic, nearby text and filename | Fast, accurate ALT at scale | Image + context | ALT, title, caption | deps=AI provider; auto=Semi; risk=Low; api=POST /images/generate
Bulk ALT generation | Queue ALT for hundreds of images | Fix the whole library | Image ids | Pending/auto-applied changes | deps=AI provider; auto=Semi; risk=Low; api=POST /bulk
ALT review before apply | Changes go through approval (or auto-apply in Semi mode) | Safety | Changes | Applied ALT | auto=Semi; risk=Low; db=changes; api=POST /changes/{id}/apply
Image usage count | Where each image is used | Prioritize visible images | Posts content | Usage count
Broken image detection (crawl) | Images returning errors on pages | Fix broken visuals | Crawl | Issues | db=issues; api=GET /issues
Images without dimensions (crawl) | Missing width/height causing CLS | Better Core Web Vitals | Crawl | Issues | db=issues
Rule: new upload → generate ALT | Rules engine action on image upload | Never forget ALT again | Upload event | ALT change | deps=AI provider; auto=Semi; risk=Low; db=rules, changes; api=POST /rules
Image file rename | Rename files to descriptive slugs with redirects | Image search relevance | Image + AI name | Renamed file + redirect | ed=Pro; risk=High
WebP/AVIF conversion advice | Detects heavy legacy formats and estimates savings | Faster pages | Inventory | Recommendations | ed=Pro
Image sitemap | Adds image entries to the XML sitemap | Better image indexing | Inventory | Sitemap entries | ed=Pro
''')

cat('Google Search Console Integration', intro='Secure OAuth connection with the site owner\'s own Google Cloud client.',
    deps='Google Cloud OAuth client', ui='Search Console page, Settings → Search Console', auto='Manual', risk='None', db='wp_options (encrypted)', api='Google Search Console API', features='''
OAuth 2.0 + PKCE connect | Authorization code flow with PKCE and single-use state | Secure, standards-based connection | Client ID/secret | Encrypted tokens | api=GET /gsc/auth-url
Own OAuth client | Uses the site owner's credentials (no shared secret) | Data ownership, no vendor lock | Client ID/secret | Stored credentials | api=POST /gsc/credentials
Redirect URI helper | Shows the exact redirect URI to register | Painless setup | — | Copyable URI | api=GET /settings
Property list | Lists properties available to the account | Pick the right site | Tokens | Property list | api=GET /gsc/sites
Property selection | Validated against the account's properties | Prevents misconfiguration | Property | Saved property | api=POST /gsc/property
Change property | Switch property any time | Flexibility | Property | Re-sync | api=POST /gsc/property
Disconnect & revoke | Revokes tokens at Google and deletes them | Privacy | — | Disconnected state | api=POST /gsc/disconnect
Token refresh | Automatic access-token refresh | No re-login | Refresh token | Fresh access token | auto=Auto
Invalid-grant detection | Detects revoked access, raises an alert | No silent data gaps | Token error | Alert | auto=Auto; db=alerts
Read-only scope | Requests webmasters.readonly only | Least privilege | — | — 
Initial back-fill | Imports the last N days (default 90, up to 480) | Instant history | Days setting | Daily rows | auto=Auto; db=gsc_daily, gsc_page_query, gsc_breakdown; api=POST /gsc/sync
Daily incremental sync | Re-syncs the last 5 days for late data | Always current | Schedule | Updated rows | auto=Scheduled; db=gsc_daily, gsc_page_query
Page × query × day storage | Full-granularity rows with pagination (25k/request) | Deep analysis impossible in GSC UI | API rows | gsc_page_query rows | auto=Auto; db=gsc_page_query
Country / device / appearance breakdowns | Daily dimension breakdowns | Segment insights | API rows | Breakdown rows | auto=Auto; db=gsc_breakdown
URL inspection | Index status, coverage, canonical and crawl info for a URL | Debug indexing | URL | Inspection result | api=POST /gsc/inspect
''')

cat('Search Console Analytics', intro='Deeper analysis of real Google data than the Search Console UI offers, with plain-language insights.',
    deps='Search Console connected', ui='Search Console page, Dashboard', auto='Auto', risk='None', db='gsc_daily, gsc_page_query, gsc_breakdown', api='GET /gsc/*', features='''
Overview KPIs | Clicks, impressions, CTR, impression-weighted position | Accurate headline numbers | Date range | KPI tiles with deltas | api=GET /gsc/overview
Period comparison | Current vs previous period for every metric | See direction instantly | Range | Deltas | api=GET /gsc/overview
Daily trend charts | Clicks/impressions/CTR/position over time | Spot changes and seasonality | Range | SVG charts
Top queries | Queries table with all metrics and deltas | Know what drives traffic | Range, filters | Table | api=GET /gsc/table
Top pages | Pages table with metrics and deltas | Find best/worst pages | Range, filters | Table | api=GET /gsc/table
Query → pages drilldown | Which pages rank for a query | Detect conflicts | Query | Page list | api=GET /gsc/page
Page → queries drilldown | All queries for a page with trend | Optimize a page precisely | Page | Query list + series | api=GET /gsc/page
Winners & losers | Biggest gains and drops (pages and queries) | Act on what changed | Range | Two ranked lists | api=GET /gsc/insights
New keywords | Queries that started getting impressions | Catch emerging demand | Periods | List | api=GET /gsc/insights
Lost keywords | Queries that disappeared | Stop silent losses | Periods | List | api=GET /gsc/insights
Rising & declining pages | Pages trending up or down | Prioritize refreshes | Periods | Lists | api=GET /gsc/insights
New pages | Pages that started appearing | Validate new content | Periods | List | api=GET /gsc/insights
CTR opportunities | Pages below expected CTR for their position | Quick traffic wins via snippet rewrites | Page metrics + CTR curve | Ranked list with potential clicks | api=GET /gsc/insights
Position opportunities (striking distance) | Pages/queries ranking 4–20 with demand | Biggest ranking upside | Page metrics | Ranked list | api=GET /gsc/insights
Ranking distribution | Share of queries in 1–3, 4–10, 11–20, 21–50, 50+ | Track visibility quality | Range | Distribution chart
Country breakdown | Metrics by country | International insights | Range | Table/chart | api=GET /gsc/breakdown
Device breakdown | Desktop vs mobile vs tablet | Mobile priorities | Range | Table/chart | api=GET /gsc/breakdown
Search appearance breakdown | Rich results, video, etc. | Measure rich result impact | Range | Table | api=GET /gsc/breakdown
Insight sentences | Human-readable explanations of each finding | No analyst needed | Analytics results | Sentences (fa/en)
Text filters | Query/page "contains" filters on tables | Focused analysis | Filter text | Filtered tables | api=GET /gsc/table
Brand vs non-brand split | Separates branded queries | True SEO growth picture | Brand terms | Split metrics | ed=Pro
Year-over-year comparison | Same period last year | Seasonality-aware decisions | Range | YoY deltas | ed=Pro
Query clustering by intent | Groups queries into intent/topic clusters | Strategic view | Queries + AI | Clusters | ed=Pro; deps=Search Console, AI provider
Anomaly detection | Statistical anomaly flags per page/query | Early warnings | Daily series | Anomaly list | ed=Pro
CSV export of any table | Export analytics tables | Share and analyze | Table | CSV | ed=Pro
''')

cat('Site Audit — Crawling & Indexability', intro='A real crawler with ~60 checks; every issue is explained by the SEO Doctor.',
    deps='—', ui='Site Audit page', auto='Scheduled', risk='None', db='audits, urls, issues', api='POST /audit/start, GET /audit, GET /issues', features='''
Full site crawl | Seeds from WordPress objects + sitemap, crawls in parallel batches | Complete, fast audits without SaaS | Site | URL inventory + issues
Incremental crawl | Re-crawls changed/dirty URLs only | Fresh data, low load | Changed objects | Updated issues
Scheduled crawls | Daily/weekly/monthly | Always-current health | Frequency setting | Audit runs | ui=Settings → Crawler
Crawl budget controls | Max URLs, batch size, timeout | Safe on shared hosting | Settings | Throttled crawl | ui=Settings → Crawler
HTTP 4xx detection | Pages returning client errors | Fix broken pages | Crawl | Issues
HTTP 5xx detection | Server errors | Fix outages | Crawl | Issues
302 redirect detection | Temporary redirects that should be permanent | Signal consolidation | Crawl | Issues
Redirect chain detection | Multi-hop redirects | Faster crawling, preserved signals | Crawl | Issues + fix action
Redirect loop detection | Circular redirects | Fix inaccessible URLs | Crawl | Issues
Noindex detection | Pages excluded from the index | Avoid accidental deindexing | Meta/X-Robots | Issues
Noindex with traffic | Noindexed pages that get Search Console traffic | Stop losing traffic | Crawl + GSC | Critical issues | deps=Search Console (optional)
Noindex in sitemap | Conflicting signals | Clean sitemaps | Crawl + sitemap | Issues
Robots.txt blocked | Important URLs blocked from crawling | Restore crawlability | robots.txt + URLs | Issues
Nofollow meta | Pages telling crawlers not to follow links | Restore link flow | Meta robots | Issues
Missing canonical | No canonical tag | Consolidation clarity | HTML | Issues
Multiple canonicals | Conflicting canonical tags | Remove ambiguity | HTML | Issues
Invalid canonical | Malformed or relative canonical | Valid signals | HTML | Issues
Canonical to error | Canonical target returns 3xx/4xx/5xx/noindex | Fix broken consolidation | Crawl graph | Issues
Canonicalized pages | Pages pointing canonical elsewhere | Understand index coverage | HTML | Informational issues
Missing sitemap | No sitemap discovered | Better discovery | robots.txt + common paths | Site issue
Sitemap error URLs | Sitemap lists 3xx/4xx/noindex/non-canonical URLs | Clean sitemaps | Sitemap + crawl | Issues
Robots without sitemap | robots.txt lacks a Sitemap line | Better discovery | robots.txt | Site issue
JS-dependent content | Little server-rendered text | Indexable content | HTML | Issues
Orphan pages | Indexable pages without internal links | Get them discovered and ranked | Link graph | Issues
Deep pages | Pages > 4 clicks from home | Better crawl priority | BFS depth | Issues
Too many links | Pages with excessive links | Clear link equity | HTML | Issues
HTTPS check | Pages served over HTTP | Security + ranking signal | URL | Issues
Mixed content | HTTPS pages loading HTTP assets | Browser trust | HTML | Issues
URL issues | Uppercase, spaces, parameters, very long or encoded URLs | Clean URLs | URL | Issues
Log-file crawl analysis | Real Googlebot hits from server logs | See what Google actually crawls | Access logs | Crawl stats | ed=Pro
''')

cat('Site Audit — On-page & Content', intro='Content, metadata and markup checks with severity and fixability.',
    deps='—', ui='Site Audit page', auto='Scheduled', risk='None', db='issues, urls', api='GET /issues', features='''
Missing title | Pages without a title tag | Basic ranking element | HTML | Issues + AI fix
Title too long | Titles truncated in results (pixel-based, Persian-aware) | Complete snippets | Title | Issues
Title too short | Titles missing context | Better relevance | Title | Issues
Multiple titles | More than one title tag | Valid markup | HTML | Issues
Duplicate titles | Same title on several pages | Distinct pages | All titles | Issues
Missing meta description | No description | Better snippets | HTML | Issues + bulk AI fix
Meta description length | Too long/short descriptions | Complete snippets | Description | Issues
Duplicate meta descriptions | Same description reused | Unique snippets | All descriptions | Issues
Open Graph missing | No og: tags | Better social sharing | HTML | Issues
Twitter card missing | No twitter: tags | Better social previews | HTML | Issues
Missing H1 | No H1 | Clear topic | HTML | Issues
Multiple H1 | Several H1s | Clear hierarchy | HTML | Issues
Missing H2 | Long content without subheadings | Scannability | HTML | Issues
Heading level skips | H2 → H4 jumps | Accessibility + structure | HTML | Issues
Thin content | Pages under the word threshold | Avoid low-quality signals | Word count | Issues + AI expand
Exact duplicate content | Identical content hashes | Consolidation | Content hash | Issues
Near-duplicate content | SimHash + LSH similarity | Find hidden duplicates at scale | SimHash | Issues
Keyword cannibalization (crawl) | Pages with same title/H1 targets | Fix competing pages | Titles/H1 | Issues
Broken internal links | Links to 4xx/5xx URLs | Better UX and crawl | Link graph | Issues
Links to redirects | Internal links pointing to redirects | Save a hop | Link graph | Issues
Broken external links | Outbound links to dead pages (optional) | Trust and UX | External checks | Issues | ui=Settings → Crawler (enable)
Missing image ALT (crawl) | Images without ALT on pages | Accessibility | HTML | Issues
Missing structured data | Pages without JSON-LD; products without Product schema | Rich result eligibility | HTML | Issues
Invalid JSON-LD | Unparseable or invalid schema | Fix silent errors | HTML | Issues
Missing breadcrumbs / viewport / lang / hreflang errors | Structural checks | Mobile, i18n and navigation basics | HTML | Issues
''')

cat('SEO Doctor & Smart Recommendations', intro='Diagnoses in plain language and a prioritized, explainable to-do list.',
    deps='Audit and/or Search Console data', ui='Site Audit → Doctor, AI Action Center', auto='Scheduled', risk='None', db='recommendations, issues', api='GET /doctor, GET /recommendations', features='''
SEO Doctor diagnoses | Groups issues into diagnoses: what, why it matters, how to fix | Understand problems without SEO expertise | Issues | Diagnosis cards
Severity & fixability | Critical/high/medium/low and whether SEOlity can fix it | Know what to do first | Checks | Labels
Affected URL lists | Every diagnosis links to the affected pages | Fast navigation | Issues | URL lists
Recommendation engine | Generators turn data into actions with priority score | A focused plan | Audit, GSC, content, links | Recommendations
Priority / impact / effort / confidence | Four explicit dimensions per recommendation | Transparent prioritization | Evidence | Scores
Reason & evidence | Each recommendation explains its data | Trust | Data | Reason + evidence JSON
"Why?" explainability | Modal showing evidence, numbers and logic | Never a black box | Recommendation | Explanation | api=GET /recommendations/{id}/why
Execute recommendation | One click opens the right fix (optimizer, redirect, links…) | From insight to action fast | Recommendation | Preview / change | auto=Assisted; risk=Medium; api=POST /recommendations/{id}/execute
Dismiss / done states | Track what was handled | Clean list | Status | Updated status | api=POST /recommendations/{id}/status
Deduplication | Stable dedupe keys avoid repeated items | No noise | Generators | Unique items
Striking-distance recommendations | Queries at positions 4–20 worth optimizing | Biggest upside | GSC | Recommendations | deps=Search Console
CTR-opportunity recommendations | Snippet rewrites for under-performing pages | Quick wins | GSC | Recommendations | deps=Search Console
Declining-page recommendations | Pages losing clicks | Stop decay | GSC | Recommendations | deps=Search Console
Orphan / internal-link recommendations | Pages needing inlinks | Better discovery | Link graph | Recommendations
Redirect recommendations | Create redirects for broken URLs with traffic; fix chains/loops | Recover lost value | 404s, crawl | Recommendations
Remove-noindex recommendations | Noindexed pages that have demand | Recover traffic | Crawl + GSC | Recommendations
FAQ / content-gap / cannibalization recommendations | Content opportunities | Strategic content work | GSC, competitors | Recommendations
Recommendation impact tracking | Measures results of executed recommendations | Learn what works | Changes + experiments | Outcome per type | ed=Pro
''')

cat('Competitor Intelligence & Content Gap', intro='Polite competitor monitoring through sitemaps and public pages.',
    deps='Competitor domains', ui='Competitors page', auto='Scheduled', risk='None', db='competitors, competitor_pages', api='GET /competitors', features='''
Add competitors | Domain + optional sitemap URL, frequency | Track who you compete with | Domain | Competitor record | auto=Manual; api=POST /competitors
Sitemap discovery | Finds competitor sitemaps (robots.txt, common paths) | Zero setup | Domain | Sitemap URLs
Polite crawling | Respects robots.txt, rate limits, SSRF-safe | Ethical, safe monitoring | URLs | Fetched pages
Page inventory | Title, meta, H1, headings, word count, schema, FAQ count | Know their content | Pages | competitor_pages rows | api=GET /competitors/{id}/pages
New page detection | First-seen tracking | Spot new content fast | Crawls | New pages list
Changed page detection | Re-crawls pages whose sitemap lastmod changed | See what they update | Sitemap lastmod | Updated page records
Topic extraction | Topics per competitor page | Topical comparison | Headings/text | Topics
Competitor stats | Pages, avg words, schema usage, FAQ usage | Benchmark | Pages | Stats
AI competitor summary | Strategy summary: strengths, focus, patterns | Executive understanding | Stats + samples | Summary | deps=Competitor domains, AI provider
AI content gap analysis | Topics they cover that you don't, with priority | Content roadmap from evidence | Their topics + your URLs | Gap opportunities | deps=Competitor domains, AI provider; api=POST /competitors/{id}/gap
Gap → content plan | Turn gaps into planned briefs | Faster execution | Gap | Plan items | deps=AI provider; db=content_plan
Competitor growth alerts | Alerts when a competitor publishes many pages | Stay ahead | Crawls | Alert | db=alerts
Content-gap recommendations | Gaps appear in the Action Center | Prioritized | Gaps | Recommendations | db=recommendations
Schema usage comparison | Which structured data competitors use | Rich result parity | Pages | Comparison
Remove / pause competitor | Manage list | Control cost | Competitor | Updated list | auto=Manual; api=DELETE /competitors/{id}
SERP overlap | Queries where you and competitors both rank | Real search competitors | SERP API | Overlap report | ed=Pro; api=SERP API
Backlink gap | Domains linking to competitors but not you | Outreach targets | Backlink API | Gap list | ed=Pro; api=Backlink provider API
Competitor price monitoring | Product price changes (e-commerce) | Pricing intelligence | Product pages | Price history | ed=Ent
''')

cat('AI Change Tracking, Versions & Approval', intro='Every change is previewed, approved, versioned and reversible.',
    deps='—', ui='AI Changes page, editors (History)', auto='Manual', risk='None', db='changes, audit_log', api='GET /changes', features='''
Change proposals | Every AI or rule change starts as a pending proposal | Nothing happens behind your back | Before/after | Pending change
Risk classification | Low / medium / high per field | Right level of caution | Field | Risk label
Approval modes | Manual, Semi-automatic (low auto), Autonomous (low+medium auto) | Choose your comfort level | Setting | Gated applies | ui=Settings → General
High-risk always manual | Content, titles, slugs, canonical, noindex never auto-apply | Safety | Risk | Approval required
Preview & diff | See the exact change before applying | Confidence | Change | Diff view
Apply change | Writes through the right storage (SEO plugin or own meta) | Works with your stack | Change | Applied value | risk=High; api=POST /changes/{id}/apply
Reject change | Decline with optional note | Feedback loop | Change | Rejected status | api=POST /changes/{id}/reject
Revert | Restores the previous value | Instant undo | Applied change | Reverted value | risk=Medium; api=POST /changes/{id}/revert
Restore version | Re-applies any earlier version | Time travel | Version | New applied version | risk=Medium; api=POST /changes/{id}/restore
Version history per field | All versions for an object | Full traceability | Object | History list | api=GET /history
Batch apply / reject | Act on a whole optimization batch | Speed | Batch id | Results | risk=High; api=POST /changes/batch/{batch}/{action}
Provenance | Provider, model, prompt version, user, source stored | Accountability | Change | Metadata
Score delta | SEO score before/after stored | Measure quality | Change | Scores
GSC snapshot | Search metrics before change captured | Measure impact later | Change | gsc_before | deps=Search Console
Editor-applied finalization | Changes applied in editors become versions on save | Undo even editor edits | save_post | Applied change | auto=Auto
Audit log | Who did what and when (hashed IP) | Compliance | Every action | Log rows | ui=Automation → Audit log; db=audit_log; api=GET /audit-log
Pending badge | Count of changes awaiting review | Never miss approvals | Changes | Badge | auto=Auto
Filter by status/source | Pending, applied, reverted, AI vs rule | Fast review | Filters | Lists
Multi-step approval (reviewer → approver) | Two-person rule for teams | Governance | Roles | Workflow | ed=Ent
Change scheduling | Apply at a set time | Coordinated launches | Date/time | Scheduled apply | ed=Pro
''')

cat('SEO Experiments & Learning', intro='Applied changes become measured experiments; results teach the AI what works on your site.',
    deps='Search Console connected', ui='Experiments page', auto='Scheduled', risk='None', db='experiments, learnings', api='GET /experiments', features='''
Auto-start experiments | Applying a change starts a before/after experiment | Measurement by default | Applied change | Experiment
Equal windows | Same-length windows before and after (configurable 7–90 days) | Fair comparison | Window setting | Metrics before/after | ui=Settings → General
Site-wide control adjustment | Adjusts for overall site movement | Avoid false wins | Site totals | Adjusted deltas
Outcome classification | Positive / negative / inconclusive with confidence | Clear verdicts | Deltas | Outcome
AI verdict explanation | Explains results considering noise and confounders | Understand why | Metrics | Explanation | deps=Search Console, AI provider
Revert losing experiments | One-click revert of negative changes | Limit damage | Experiment | Reverted change | auto=Manual; risk=Medium; api=POST /experiments/{id}/revert
Scheduled evaluation | Daily job evaluates finished windows | Hands-off | Schedule | Updated experiments | api=POST /experiments/evaluate
Learnings by change type | Aggregates results per change type | Know what works here | Experiments | Learning stats
Learning injection into AI | Feeds site learnings into prompts | Smarter suggestions over time | Learnings | Prompt context
Learnings contribution (opt-in) | Shares anonymized aggregated results to the GitHub knowledge base | Community knowledge | Learnings | Markdown in repo | deps=GitHub token; api=GitHub API
Experiment events | Fires seolity_experiment_evaluated for notifications/integrations | Connect results to your tools | Evaluation | Action hook | ui=Code (hook)
CTR experiments | Title/description changes measured by CTR at comparable position | Snippet optimization science | GSC | CTR deltas
Experiment list & filters | Running/finished, outcome filters | Overview | Filters | Table
Split testing across page groups | Statistical A/B across similar pages | Causal results | Page groups | Test report | ed=Pro
Bayesian significance | Probability of improvement | Better decisions with small data | Daily series | Probability | ed=Pro
''')

cat('Keyword Intelligence & Cannibalization', intro='Keyword set, intent, mapping and conflict detection from real data plus AI research.',
    deps='Search Console (recommended)', ui='Keywords page', auto='Auto', risk='None', db='keywords, gsc_page_query', api='GET /keywords', features='''
Keyword set | Central list of tracked keywords | One source of truth | Keywords | Keyword table
Import from Search Console | Pulls queries with demand into the keyword set | Real keywords, zero typing | GSC queries | Keywords | auto=Scheduled; api=POST /keywords/sync
Manual keyword add | Add keywords by hand | Track business terms | Keywords | Rows | auto=Manual; api=POST /keywords
Edit / delete keywords | Maintain the list | Clean data | Keyword | Updated rows | auto=Manual; api=PATCH/DELETE /keywords/{id}
AI keyword research | Seeds → related, long-tail, questions with intent and opportunity | Discover demand | Seeds, market | Keyword ideas | deps=AI provider; auto=Manual; api=POST /keywords/research
Search intent classification | Informational / commercial / transactional / navigational | Right page per keyword | Keyword | Intent | deps=AI provider
Keyword type | Head / body / long-tail | Balanced strategy | Keyword | Type
Difficulty & opportunity scores | Qualitative difficulty and opportunity (no invented volumes) | Prioritization | Keyword + data | Scores
Target URL mapping | Assign each keyword to one target page | Clear ownership | Keyword + URL | Mapping | auto=Manual; api=GET /keywords/mapping
Auto-mapping from GSC | Suggests the URL already ranking best | Fast mapping | GSC | Suggested target
Position & clicks per keyword | Current metrics from GSC | Track progress | GSC | Metrics | deps=Search Console
Cannibalization detection | Queries where several of your URLs split impressions | Fix ranking conflicts | GSC page×query | Conflict list | deps=Search Console; api=GET /keywords/cannibalization
Cannibalization severity | Share split, position spread, flip-flopping | Fix the worst first | GSC | Severity
AI cannibalization advice | Merge / differentiate / canonical / de-optimize plan | Expert resolution | Conflict data | Plan | deps=Search Console, AI provider; api=POST /keywords/cannibalization/advice
Cannibalization recommendations | Conflicts appear in the Action Center | Prioritized | Conflicts | Recommendations | db=recommendations
New/lost keyword alerts | Alerts when important queries appear/disappear | Early signals | GSC | Alerts | db=alerts; auto=Scheduled
Ranking-drop alerts | Alerts on significant position drops | Act fast | GSC | Alerts | db=alerts; auto=Scheduled
Keyword → content brief | Generate a brief for a keyword | Faster content | Keyword | Brief | deps=AI provider; db=content_plan
Question keywords | Extract question-form queries for FAQs | People Also Ask coverage | GSC/AI | Question list
Daily rank tracking | Exact daily positions via SERP API | Precise tracking | Keywords | Rank history | ed=Pro; api=SERP API
Search volume data | Volumes from a keyword data provider | Demand sizing | Keywords | Volumes | ed=Pro; api=Keyword data API
Keyword groups / tags | Organize keywords by product line or campaign | Reporting by segment | Tags | Groups | ed=Pro
''')

cat('Rank Math Integration & GSC Focus Keywords', intro='Works inside Rank Math (or Yoast / SEOPress / AIOSEO) and goes beyond its Content AI: keywords come from the queries Google already shows each page for.',
    deps='Search Console; Rank Math (optional)', ui='Rank Math & focus keywords page, editors, Content page', auto='Assisted', risk='Low', db='changes, gsc_page_query', api='GET /focus/insights, GET /rankmath', features='''
Multiple focus keywords | Primary + up to 4 secondary keywords written to Rank Math's comma list, Yoast related keyphrases or SEOPress target keywords | Native checklists show every keyword | Keyword list | Plugin meta | risk=Low
Primary-safe single edits | Changing the primary keyword keeps Rank Math's secondary keywords | No silent data loss | Primary keyword | Updated list | risk=Low
GSC keyword insights per page | All queries of a URL with impressions, clicks, position and owner page | See what the page really ranks for | URL | Query table | api=GET /focus/insights
Coverage map per query | Whether each query appears in title, meta description, headings and body | Know exactly what to add where | Page text + queries | T/D/H/C flags
Suggested keyword set | Scores owned queries by demand, clicks, position, shape and brand, removes near-duplicates | Best keywords picked from data, not guesses | GSC queries | Primary + secondary
Cannibalization-safe assignment | A query belongs to the URL Google prefers and is never given to two pages | No self-competition | Site-wide query owners | Conflict-free keyword map
Focus keyword audit | Flags keywords with no impressions or won by another URL | Fix wrong targets | Current keywords | Status per keyword
Not-covered queries | High-impression queries the content barely mentions | Concrete content gaps | Queries + content | Gap list
Question queries for FAQ | Detects English and Persian questions (start or end question words) | FAQ that matches real searches | Queries | Question list
Striking-distance queries | Positions 4–20 sorted by impressions | Fastest ranking wins | Queries | Opportunity list
CTR gap check | Page CTR vs. expected CTR for its position | Know when to rewrite the snippet | Page metrics | Advice
AI keyword strategy | AI combines GSC queries with the content: keywords, intent, title, description, gaps, FAQ, anchors, pillar | Beyond generic content AI | Insights + content | Strategy | deps=AI provider; api=POST /focus/plan
One-click apply with undo | Keywords (and AI title/description) applied through the change log | Safe, reversible | Keyword set | Applied change | api=POST /focus/apply
Focus keyword autopilot | Site-wide, cannibalization-safe bulk assignment with preview, approval or auto-apply (Semi/Autonomous) | Every page targeted correctly at once | Filters | Change batch | api=POST /focus/bulk; auto=Semi
Rank Math editor sync | Updates Rank Math / Yoast editor stores so their save never overwrites SEOlity and sidebars update live | No conflicts between plugins | Applied values | Store updates | ui=Gutenberg, Classic editor
Read-only keyword box under other SEO plugins | SEOlity's meta box shows keywords read-only when Rank Math owns them | Two editors never fight | — | Read-only field | ui=Classic editor
Rank Math status & score triage | Version, modules, average score, low scores ordered by search demand | Fix the pages that matter first | Rank Math meta + GSC | Dashboard | api=GET /rankmath
Rank Math score in content list | Score badge next to SEOlity's own score | One overview | Rank Math meta | Badge | ui=Content page
Pillar content sync | Marks topic-cluster pillars as Rank Math Pillar Content / Yoast cornerstone (reversible) | Rank Math link suggestions follow your architecture | Clusters | Changes | api=POST /rankmath/sync-pillars
Rank Math redirection import | Converts exact, regex, contains, starts-with and ends-with rules into SEOlity redirects | Consolidate redirects safely | Rank Math table | Redirects | api=POST /rankmath/import-redirects; risk=Medium
Social title & description | Writes Rank Math / Yoast / SEOPress Open Graph fields; SEOlity's own head uses them | Better social snippets | Values | Plugin meta | risk=Low
''')

cat('Internal Linking & Site Architecture', intro='Link graph analysis and AI link suggestions that reuse text already on the page.',
    deps='Audit data', ui='Internal Links page, editors', auto='Assisted', risk='Medium', db='internal_links, urls', api='GET /links', features='''
Link graph | Inlinks/outlinks per URL from the crawl | See how authority flows | Crawl | Graph stats | auto=Auto; risk=None
Orphan detection | Pages with zero internal inlinks | Get pages discovered | Graph | Orphans | auto=Auto; risk=None
Click depth | BFS depth from the homepage | Fix buried pages | Graph | Depth per URL | auto=Auto; risk=None
Top-linked pages | Most linked URLs | Understand priorities | Graph | List | auto=Auto; risk=None
AI link suggestions for a post | Outgoing link opportunities with anchors from existing text | Relevant links in seconds | Post + candidates | Suggestions | deps=Audit data, AI provider; api=POST /links/suggest
Links to a target page | Find pages that should link to an important page | Boost key pages | Target | Suggestions | deps=Audit data, AI provider
Site-wide link building | Batch suggestions for orphan and striking-distance pages | Systematic linking | Graph + GSC | Suggestions | deps=Audit data, AI provider; api=POST /links/build
Relevance score & reason | Each suggestion explains why | Trust | Content similarity | Score + reason
Link preview | Shows the sentence with the inserted link | Safe review | Suggestion | Preview | risk=None; api=GET /links/{id}/preview
Apply link | Inserts the link without changing the text | Fast execution | Suggestion | Updated content (versioned) | api=POST /links/{id}/apply
Dismiss suggestion | Remove irrelevant ideas | Clean list | Suggestion | Dismissed | risk=None; api=POST /links/{id}/dismiss
Anchor safety rules | Never inside headings/existing links; no meaning changes | Natural links | Content | Valid insertions | risk=None
Broken & redirected internal links | Detected by the crawler | Clean graph | Crawl | Issues | auto=Auto; risk=None; db=issues
Internal-link recommendations | Orphans and weak pages in the Action Center | Prioritized | Graph | Recommendations | auto=Scheduled; risk=None; db=recommendations
Rule action: suggest links on publish | Automatically suggest links for new content | Never publish orphans | Publish event | Suggestions | auto=Auto; db=rules, internal_links
Link equity simulation | Internal PageRank estimate | Smarter architecture | Graph | Scores | ed=Pro
Anchor text distribution | Anchor diversity per target | Avoid over-optimization | Graph | Report | ed=Pro
Navigation/menu analysis | Evaluate menus and footers | Better hierarchy | Menus | Report | ed=Pro
''')

cat('Topic Clusters & Content Planning', intro='From queries and gaps to clusters, briefs, a calendar and drafts.',
    deps='AI provider', ui='Content page → Planner / Clusters', auto='Assisted', risk='None', db='clusters, content_plan', api='GET /planner, GET /clusters', features='''
AI topic clustering | Groups your pages and queries into topical clusters | Topical authority strategy | URLs + queries | Clusters | api=POST /clusters/build
Pillar page selection | Picks the best pillar per cluster | Clear hierarchy | Cluster | Pillar URL
Cluster coverage score | How completely a cluster covers its subtopics | Find weak clusters | Cluster | Coverage %
Cluster gaps | Missing subtopics per cluster | Content roadmap | Cluster | Gap list
AI content plan | Generates dated plan items with keyword, intent, title, H1 | Ready-made calendar | Focus, gaps, GSC | Plan items | api=POST /planner/generate
Content briefs | Outline, entities, questions, links, evidence needed | Writers start fast | Plan item | Brief JSON
Priority & status | Plan items with priority and workflow status | Manage production | Item | Updated item | auto=Manual; api=PATCH /planner/{id}
Calendar dates | Scheduled dates per item | Consistent publishing | Item | Date
AI draft creation | Creates a WordPress draft from a brief | Faster first drafts (never auto-published) | Brief | Draft post | risk=Low; api=POST /planner/{id}/draft
Plan from opportunity | Turn a recommendation/gap into a plan item | One-click planning | Opportunity | Plan item | api=POST /recommendations/{id}/execute
Delete plan item | Housekeeping | Clean plan | Item | Removed | auto=Manual; api=DELETE /planner/{id}
Content refresh candidates | Declining or outdated pages to update | Recover traffic | GSC + age | Candidate list | deps=Search Console; auto=Scheduled
E-E-A-T analysis | Checks experience/expertise/trust signals of a page | Quality improvements | Page | Findings | deps=AI provider
Weekly cluster rebuild | Clusters refreshed in the weekly pipeline | Always current | Schedule | Updated clusters | auto=Scheduled
Editorial workflow (assignees, due dates) | Assign writers and deadlines | Team production | Users | Workflow | ed=Pro
Content calendar view | Month/week calendar UI | Visual planning | Plan | Calendar | ed=Pro
Content decay forecasting | Predict which pages will decline | Proactive refreshes | GSC series | Forecast | ed=Pro
Multi-language plans | Plans per language with hreflang pairs | International growth | Languages | Plans | ed=Ent
''')

cat('SERP & Schema Intelligence', intro='Understand result pages and structured data — with live data where you provide a SERP API key.',
    deps='—', ui='Keywords → SERP analysis, Schema page', auto='Manual', risk='None', db='—', api='POST /serp/analyze, POST /schema/*', features='''
Live SERP fetch | Real Google results via the owner's SerpApi key (never scraping Google) | Ground truth for decisions | Keyword, market | Normalized results | deps=SerpApi key; api=SerpApi
SERP cache | Results cached 24 h per keyword/market | Lower API cost | Keyword | Cached SERP | deps=SerpApi key; db=wp_options (transients)
SERP feature detection | Featured snippet, PAA, shopping, local pack, videos, AI overview, ads | Know what you compete with | SERP | Feature list | deps=SerpApi key
People Also Ask extraction | Questions shown in the SERP | FAQ and content ideas | SERP | Question list | deps=SerpApi key
Related searches | Related queries from the SERP | Semantic coverage | SERP | Query list | deps=SerpApi key
AI SERP analysis | Dominant intent, winning format, title patterns, topics, recommendations | Know what it takes to rank | SERP + your rankings | Analysis | deps=SerpApi key, AI provider
Your position in SERP | Highlights your URL in the live top 10 + GSC position | Context for the analysis | SERP + GSC | Position badge | deps=SerpApi key
Market selection | Iran/Persian, US, UK, DE, TR, UAE or automatic | Correct local results | Market | gl/hl parameters | deps=SerpApi key
Search intent detection (AI) | Classifies intent for optimizations without SERP data | Correct format even without SERP API | Keyword + content | Intent | deps=AI provider; auto=Auto
Structured data validator | Validates JSON-LD of any page of your site against required/recommended properties | Catch errors before Google does | URL | Errors, warnings, types | api=POST /schema/check
Rule-based schema opportunities | Missing Product/Article/FAQ/Breadcrumb/Organization detection | Rich result eligibility | Page | Opportunities | api=POST /schema/check
AI schema suggestions | Eligible, truthful types and issues for a page | Expert schema review | Page + JSON-LD | Eligible types, issues, opportunities | deps=AI provider; api=POST /schema/suggest
Schema coverage report | Types used across crawled pages, pages without schema | Site-wide view | Crawl | Coverage chart | deps=Audit data; db=urls; api=GET /schema
Organization / LocalBusiness / Person entity | Site entity from settings (name, logo, phone, address, sameAs) | Knowledge panel & E-E-A-T | Settings | JSON-LD | auto=Auto; risk=Low; ui=Settings → Schema & entity
WebSite + WebPage + Breadcrumb graph | Connected @graph output when SEOlity owns SEO | Clean, linked entities | Page | JSON-LD | auto=Auto; risk=Low; ui=Front end
Article / BlogPosting schema | Posts get Article markup with author and dates | Article rich results | Post | JSON-LD | auto=Auto; risk=Low; ui=Front end
FAQ schema + visible FAQ block | FAQPage JSON-LD matching a visible FAQ section/shortcode | Policy-compliant FAQs | Approved FAQ change | JSON-LD + HTML | auto=Semi; risk=Medium; ui=Front end; db=postmeta
Schema graph filter | Developers can modify the graph | Extensibility | Graph | Filtered graph | ui=Code (seolity_schema_graph)
Review/Recipe/Event/Video schema builders | Guided builders for more types | More rich results | Forms | JSON-LD | ed=Pro
Rich result monitoring | Tracks Search Console enhancement reports | Catch markup regressions | GSC | Status history | ed=Pro
''')

cat('Technical SEO: Sitemap, Robots, Redirects & 404', intro='Technical automation with safe defaults and full undo.',
    deps='—', ui='Sitemap, Robots.txt, Redirects, 404 Monitor pages', auto='Manual', risk='None', db='redirects, not_found', api='/sitemap, /robots, /redirects, /404', features='''
Sitemap discovery & analysis | Finds all sitemaps and checks every URL against crawl data | Clean, trustworthy sitemaps | Sitemaps + crawl | Problem list | api=GET /sitemap
Sitemap problem URLs | 404, redirected, noindex, canonicalized URLs in sitemaps | Better crawl efficiency | Sitemap + crawl | List
Missing important pages | Indexable pages with traffic missing from sitemaps | Better discovery | Crawl + GSC | List
lastmod quality | Share of entries with lastmod | Freshness signals | Sitemaps | Stats
Core sitemap enhancements | Excludes noindexed objects and chosen post types from WordPress core sitemaps | Clean sitemaps without another plugin | Settings | Filtered wp-sitemap.xml | auto=Auto; risk=Medium; ui=Settings → Technical
Submit sitemap to Google | Submits through the Search Console API | One-click submission | Sitemap URL | Submission | deps=Search Console; risk=Low; api=POST /sitemap/submit
Search Console sitemap status | Errors/warnings per sitemap from GSC | Spot processing issues | GSC | Status table | deps=Search Console
Robots.txt analysis | Parses rules, finds blocked important URLs, missing sitemap line, crawl waste | Avoid accidental blocking | robots.txt + crawl | Findings | api=GET /robots
Robots.txt tester | Google-compatible matching for Googlebot/Bingbot/any agent | Test before deploying | URL + rules | Allowed/blocked | api=POST /robots/test
Suggested robots.txt | Safe recommended rules for WordPress/WooCommerce | Best-practice config | Site | Suggested file
Robots.txt editor with override | Edit the virtual robots.txt; every save is audit-logged and re-analyzed | Full control | Content | Updated robots.txt + analysis | risk=High; db=wp_options, audit_log; api=POST /robots
Redirect manager | 301/302/307/410/451 with hits and notes | Preserve value of changed URLs | Source, target, type | Redirect | risk=Medium; api=POST /redirects
Regex redirects | Pattern-based redirects with captured groups (cached) | Bulk URL migrations | Pattern | Redirect rules | risk=High
Loop & self-redirect protection | Rejects redirects that would loop | Safety | Source/target | Validation error
Redirect chain fixer | Points chained redirects directly to the final URL | Faster, cleaner redirects | Redirect table | Fixed rules | risk=Medium; api=POST /redirects/fix-chains
Redirect import/export | CSV import and export | Migrations | CSV | Rules / file | risk=Medium; api=POST /redirects/import
Automatic slug-change redirects | Creates a 301 when a published slug changes (when SEOlity is the SEO engine) | Never break URLs | save_post | Redirect | auto=Auto; risk=Low
Redirect hit tracking | Hits and last hit per rule | Clean up unused rules | Requests | Counters | auto=Auto
404 monitor | Logs 404s with referrer, hits, first/last seen (IP never stored raw) | Find broken URLs | Requests | 404 log | auto=Auto
Bot filtering | Ignore or include bot 404s | Signal over noise | User agent | Flag | ui=Settings → Technical
AI redirect target suggestions | Suggests the best target for each 404 with a score | Fast fixes | 404 + site URLs | Suggested target | deps=AI provider; auto=Scheduled; api=POST /404/suggest
One-click 404 → redirect | Create a redirect from the log | Fix in seconds | 404 + target | Redirect | risk=Medium; api=POST /404/{id}/redirect
404 status workflow | Ignore / fixed states | Clean log | Entry | Status | api=POST /404/{id}/status
Redirect recommendations | 404s with traffic or backlinks in the Action Center | Prioritized | 404 log | Recommendations | auto=Scheduled; db=recommendations
Front-end redirect handler | Matches by hash index before WordPress templates | Fast redirects | Request | 301/302/410 response | auto=Auto
Robots meta per object | noindex/nofollow per post/term (high risk, versioned) | Index control | Object | Meta robots | risk=High; db=postmeta, changes
Canonical override per object | Custom canonical URL (high risk, versioned) | Consolidation control | Object | Canonical tag | risk=High; db=postmeta, changes
.htaccess / Nginx export | Export redirects as server rules | Max performance | Redirects | Config snippet | ed=Pro
''')

cat('Performance & Core Web Vitals', intro='Speed data from the crawler and PageSpeed Insights.',
    deps='—', ui='Site Audit → Performance', auto='Scheduled', risk='None', db='urls, issues', api='GET /pagespeed', features='''
Server response time | TTFB per URL from the crawler | Find slow templates | Crawl | ms per URL
Slow page detection | Pages over the TTFB threshold | Prioritize performance work | Crawl | Issues
Large page detection | Heavy HTML documents | Faster loading | Crawl | Issues
PageSpeed Insights test | Lab + field data for any URL of your site | Core Web Vitals in one click | URL | LCP, INP, CLS, score | deps=PageSpeed API key (optional); auto=Manual; api=POST /pagespeed/test
Batch PageSpeed | Tests top pages in the background | Site-wide CWV view | Top URLs | Results | deps=PageSpeed API key (optional); api=PSI API
Poor CWV issues | Pages failing LCP/INP/CLS thresholds become issues | Visibility in the audit | PSI results | Issues
Performance category in health | Response time and CWV feed the health score | Explainable impact | Data | Category score
Image weight insights | Large images in the media inventory | Quick wins | Images | List | db=images
Missing image dimensions | Images causing layout shifts | Better CLS | Crawl | Issues
Lazy-load and LCP advice | Detects lazy-loaded LCP images | Faster LCP | HTML | Advice | ed=Pro
CrUX history | 28-day field data trends from the CrUX API | Track real users over time | Origin | Trend | ed=Pro; api=CrUX API
Performance budgets | Alerts when pages exceed size/time budgets | Prevent regressions | Budgets | Alerts | ed=Pro
''')

cat('Health Score, Dashboard & AI Action Center', intro='One explainable score and a clear "What should I do today?" list.',
    deps='—', ui='Dashboard, AI Action Center', auto='Auto', risk='None', db='reports, recommendations', api='GET /dashboard, GET /health, GET /action-center', features='''
Explainable SEO health score | Weighted categories, each made of named factors with deltas | Trust the number | All data | 0–100 + grade
Category scores | Technical, content, indexation, links, schema, performance, images, Search Console, keywords, competitors | See where you lose points | Data | Per-category scores
Missing-data handling | Categories without data are excluded, not guessed | Honest score | Data availability | Note
"Why this score?" | Breakdown modal with factors and points | Learn what to fix | Score | Explanation
Health history | Daily snapshots and trend | See progress | Snapshots | Chart | auto=Scheduled; db=reports
Dashboard KPIs | Health, clicks, impressions, CTR, position, indexable pages | Overview at a glance | Data | KPI tiles
Onboarding checklist | Connect AI, connect GSC, first audit, add competitors | Fast setup | State | Checklist
Top opportunities | Highest-scoring recommendations on the dashboard | Focus | Recommendations | List
AI activity | Recent AI changes and their status | Transparency | Changes | Feed
"What should I do today?" | Prioritized daily action list | No analysis paralysis | Recommendations | Ordered list
🔴🟠🟡🟢 priorities | Critical/high/medium/low counts and filters | Triage | Recommendations | Badges
Impact / effort / confidence chips | Visible on every action | Smart choices | Recommendation | Chips
Execute from Action Center | Opens the right fix flow | Fast execution | Action | Fix flow | auto=Assisted; risk=Medium
Status bar | Approval mode, AI status, GSC status, pending changes | Always know system state | Bootstrap | Badges
WordPress dashboard widget | Health and top actions on the WP dashboard | Visibility for all admins | Data | Widget | ui=WP Dashboard
Admin bar menu | Quick access with pending-change count | Convenience | Context | Menu | ui=Admin bar
Custom dashboard layouts | Drag-and-drop widgets | Personalization | Layout | Saved layout | ed=Pro
Goal tracking | Set targets (clicks, health) and track progress | Accountability | Goals | Progress | ed=Pro
''')

cat('Alerts, Daily Briefing & Reports', intro='Proactive monitoring and reporting in Persian or English.',
    deps='Search Console (most alerts)', ui='Alerts, Reports, Dashboard', auto='Scheduled', risk='None', db='alerts, reports', api='/alerts, /briefing, /reports', features='''
Traffic drop alerts | Significant click drops site-wide or per important page | React before it's too late | GSC daily | Alert
CTR drop alerts | CTR falling at stable positions | Snippet problems detected | GSC | Alert
Ranking drop alerts | Important queries losing positions | Fast response | GSC | Alert
Page lost alerts | Pages that stopped getting impressions | Detect deindexing | GSC | Alert
New / lost keyword alerts | Emerging and disappearing queries | Opportunities and risks | GSC | Alert
Indexing drop alerts | Indexable pages decreasing between audits | Catch technical accidents | Audits | Alert | deps=Audit data
Competitor growth alerts | Competitor publishes many new pages | Stay ahead | Competitor crawls | Alert | deps=Competitor domains
Search Console disconnected alert | Revoked/expired access | No silent data loss | OAuth | Alert | deps=Search Console
Configurable drop threshold | % threshold for drop alerts | Fewer false alarms | Setting | Threshold | ui=Settings → Alerts & reports
Alert deduplication | One alert per issue per period | No alert fatigue | Dedupe key | Unique alerts
Email notifications | Optional email for high-severity alerts | Never miss critical events | Alert | Email | ui=Settings → Alerts & reports
Read / dismiss / read-all | Alert inbox workflow | Clean inbox | Alerts | Status | auto=Manual; api=POST /alerts/{id}
Daily SEO briefing | AI-written daily summary: headline, highlights, risks, next actions | Your daily SEO analyst | Signals from all modules | Briefing | deps=AI provider; api=POST /briefing/generate
Briefing grounded in data | Built from real signals; states missing data | No invented numbers | Signals | Briefing
Weekly / monthly reports | Traffic, positions, issues, improvements, AI changes, experiments | Stakeholder reporting | Period | Report | api=POST /reports/generate
Report export CSV / JSON / printable HTML (PDF via browser) | Share anywhere | Flexible formats | Report | File | auto=Manual; api=admin-post seolity_export
Email reports | Send reports to recipients on schedule or on demand | Automated stakeholder updates | Recipients | Email | api=POST /reports/{id}/email
White-label reports | Brand name/logo/color applied to reports | Agency-ready | Brand settings | Branded report | ui=Settings → General
Slack / Telegram alerts | Push alerts to chat tools | Team awareness | Webhook | Messages | ed=Pro
Server-side PDF | Pixel-perfect PDF generation | Professional reports | Report | PDF | ed=Pro
''')

cat('AI SEO Chat', intro='Ask questions in plain Persian or English; answers use your real site data.',
    deps='AI provider', ui='AI SEO Chat page', auto='Manual', risk='None', db='chat', api='POST /chat, GET /chat/*', features='''
Data-grounded answers | Context from health, GSC, issues, recommendations, changes | Answers about *your* site | Question + context | Answer
Data used disclosure | Lists which data each answer used | Trust and verification | Answer | Data list
Suggested actions | Answers can include links to concrete fixes | From question to action | Answer | Action links
Conversations | Multi-turn chats with history | Natural follow-ups | Messages | Conversation
Conversation list | Reopen previous chats | Continuity | — | List | api=GET /chat/conversations
Markdown rendering (safe) | Lists, tables, code rendered without HTML injection | Readable answers | Markdown | Safe HTML
Persian & English | Replies in the user's language | Natural use | Question | Answer
Example prompts | Starter questions ("Why did my sales drop?") | Easy start | — | Prompts
Knowledge-grounded advice | Best-practice knowledge injected | Expert-quality answers | Topics | Answer
Voice input | Speak questions | Hands-free | Audio | Question | ed=Pro
''')

cat('Bulk AI & Automation Rules', intro='Scale safely: bulk jobs and if-this-then-that rules that respect approval modes.',
    deps='AI provider', ui='Content page (bulk), Automation page', auto='Semi', risk='Medium', db='jobs, rules, changes', api='POST /bulk, /rules', features='''
Bulk meta titles | Generate titles for many objects | Fix site-wide gaps fast | Object ids | Pending changes | api=POST /bulk
Bulk meta descriptions | Generate descriptions for many objects | Better snippets everywhere | Object ids | Pending/auto-applied changes | risk=Low; api=POST /bulk
Bulk missing-meta fixer | Only objects missing meta | Targeted fixes | Filter | Changes | risk=Low; api=POST /bulk
Bulk ALT text | ALT for many images | Accessibility at scale | Image ids | Changes | risk=Low; api=POST /bulk
Bulk product optimization | Products in batch | Catalog-wide improvements | Product ids | Changes | deps=AI provider, WooCommerce; api=POST /bulk
Bulk category descriptions | Term descriptions in batch | Stronger categories | Term ids | Changes | api=POST /bulk
Bulk post optimization | Content optimization in batch (always reviewed) | Scale content work | Post ids | Pending changes | risk=High; api=POST /bulk
Bulk FAQ generation | FAQs for many pages | Richer pages | Post ids | Changes | api=POST /bulk
Bulk internal links | Link suggestions for many posts | Stronger graph | Post ids | Suggestions | api=POST /bulk
Bulk progress | Batch status, counts and errors | Visibility | Batch id | Progress | risk=None; api=GET /bulk/{batch}
Batch review | Approve/reject a whole batch | Efficient review | Batch | Results | risk=High; api=POST /changes/batch/{batch}/{action}
Rules engine | Trigger + conditions + actions | Automation without code | Rule definition | Rule | auto=Manual; risk=None; api=POST /rules
Triggers | Content published/updated, image uploaded, daily | Event-driven SEO | Events | Rule runs | risk=None
Conditions | Post type, missing ALT/meta, title length, score, words, CTR, impressions, position, FAQ, short description, inlinks | Precise targeting | Object data | Match result | risk=None
Actions | Generate ALT, optimize meta/content, FAQ, internal links, noindex, alert, recommend | Real automation | Matched objects | Changes/alerts | risk=Medium
Suggest vs auto mode | Rules can only propose, or auto-apply within approval limits | Safety | Mode | Behavior | risk=None
Rule presets | Ready-made rules (ALT on upload, meta on publish, CTR rewrite…) | One-click setup | Preset | Rule | risk=None
Per-run safety cap | Max actions per run | No runaway automation | Cap | Limited actions | risk=None
''')

cat('Connections: Subscription Wizard, GitHub, Cloudflare & Custom API', intro='Everything outside WordPress, set up from WordPress with checks and guides at every step.',
    deps='—', ui='Subscription AI, GitHub & data, Cloudflare relay pages; Settings → AI', auto='Manual', risk='None', db='wp_options', api='/subscription/*, /github/*, /cloudflare/*', features='''
Subscription prerequisites check | Checks PHP extensions, GitHub token scopes, private runner repo, saved account, relay and active provider with Fix buttons | Know exactly what is left | Current setup | Checklist | api=GET /subscription/check
Subscription wizard | Claude Pro/Max or ChatGPT Plus/Pro, numbered steps with done states | Setup without reading docs | Choices | Working subscription AI
OS-aware commands | Copyable Windows PowerShell / macOS-Linux commands for claude setup-token and codex login (file credential store) | No command-line guesswork | OS | Commands
Token format validation | Rejects text that is not a Claude token or not the whole auth.json | Fewer failed runs | Pasted value | Clear error | api=POST /subscription/account
One-click test & activate | Real test request through the runner, then make it the AI engine | Confidence before use | Engine | Test result | api=POST /subscription/test
GitHub token verification | Owner, token type, scopes and expiry with warnings | Catch bad tokens early | Token | Account card | api=POST /github/connect
One-click runner repository | Private repo + workflow + runner + queue branch + secrets | No manual GitHub work | Name | Ready runner | api=POST /github/runner
One-click own knowledge repository | Private repo seeded with bundled knowledge, builder and weekly workflow; optional Anthropic key secret | Your own learning SEO brain | Name | Synced knowledge | api=POST /github/knowledge
One-click data archive repository | Private repo, write test, archiving enabled | Smaller hosting database | Name | Active archive | api=POST /github/storage
Cloudflare one-click deploy | Token check, account discovery, KV, module upload, secret, workers.dev, cron | Free relay in minutes | Token | Deployed worker | api=POST /cloudflare/deploy
Relay mailbox | Encrypted, signed results via Cloudflare KV; push to public sites, pull for local ones | Faster answers, local sites supported | Runner results | Stored answers | api=POST /cloudflare/pull
Reliable cron heartbeat | Signed Cloudflare cron call runs the SEOlity queue every N minutes | Tasks on time on low-traffic sites | Schedule | Queue run | api=POST /remote/heartbeat; auto=Scheduled
Relay diagnostics | Checks from Cloudflare that the site REST API is reachable and the heartbeat works | Fix connectivity fast | — | Report | api=POST /cloudflare/diagnose
Custom API presets | 15 ready-made services incl. Azure OpenAI, Workers AI, LM Studio, vLLM | Correct settings in one click | Service | Filled settings
Custom API formats & auth | OpenAI or Anthropic format; Bearer, x-api-key, api-key, any header, query, none; extra headers and query params | Works with any provider | Docs values | Requests
Custom API structured-output fallback | json_schema → JSON mode → prompt, remembered per endpoint and model | Reliable JSON from any model | Responses | Valid output
Custom API model list & preview | Fetches /models and shows the exact request URL and auth | No guessing | Endpoint | Model ids, preview | api=POST /settings/custom-models
''')

cat('Knowledge Base & GitHub Integration', intro='An SEO brain that learns from top documentation, stored in GitHub; cold data archived to GitHub to keep the host DB small.',
    deps='—', ui='Knowledge Base page, Settings → Knowledge base / Storage', auto='Scheduled', risk='None', db='knowledge', api='/knowledge, /storage', features='''
Bundled SEO knowledge | 49 curated documents (fa + en) covering all AI task topics | Expert guidance out of the box | Markdown files | Knowledge chunks
Iran-specific guidance | Persian writing, IRR currency in schema, trust badges, market intent | Locally correct advice | Docs | Chunks
GitHub knowledge sync | Syncs a GitHub folder (default: this plugin's repo) via tree/blob SHA diff | Always-fresh knowledge | Repo, branch, path | Updated chunks | api=GitHub API, POST /knowledge/sync
Supersede & dedupe | Newer GitHub copies replace bundled files; identical files skipped | No duplicates | SHAs | Clean index
Knowledge builder (GitHub Action) | Weekly distillation of authoritative Persian/English SEO docs with Claude | Keeps learning automatically | sources.json | knowledge/distilled/*.md | deps=GitHub Actions, Anthropic API key (repo secret); ui=GitHub Actions; api=Anthropic API
Custom sources | Add trusted sources in extra-sources.json | Your preferred experts | URLs | Distilled docs | deps=GitHub Actions; ui=Repository file
Prompt-injection-safe distillation | Sources treated as untrusted data | Safe automation | Source text | Clean rules
Chunked retrieval (RAG) | Heading-based chunks, topic + term + language scoring | Relevant context per task | Query + topics | Top chunks
Knowledge browser | Browse/search documents and read them | Transparency | — | Document list | api=GET /knowledge, GET /knowledge/doc
User notes | Add your own rules (brand, legal, niche facts) | Personalized AI | Title, content, topic | Note | auto=Manual; api=POST /knowledge/note
Push notes to GitHub | Optionally commit notes to the knowledge repo | Share across sites | Note | Commit | deps=GitHub token; api=GitHub API
GitHub data storage | Archive cold data (old GSC rows, logs, reports) as JSON to a private repo, one commit per run | Small host database | Settings + token | Archive commits | deps=GitHub token; api=GitHub API; ui=Settings → Storage
Local pruning | Deletes archived rows locally (optional) | Hosting stays fast | Archive result | Smaller tables | deps=GitHub token
Transparent read-through | Reports read archived data from GitHub | No data loss | Report request | Data | deps=GitHub token
Restore from archive | Re-import archived data | Reversible | Archive ref | Restored rows | deps=GitHub token; api=POST /storage/restore
Storage connection test | Validates token and repository access | Easy setup | Token, repo | OK/error | deps=GitHub token; api=POST /storage/test
''')

cat('Security, Privacy & Compliance', intro='Least privilege, encrypted secrets, full audit trail.',
    deps='—', ui='Settings → Privacy, Automation → Audit log', auto='Auto', risk='None', db='audit_log', api='—', features='''
Granular capabilities | seolity_view / ai / apply / manage | Least privilege per role | Roles | Capability checks
Client role | Read-only seolity_client role for agency clients | Safe client access | User | Role
Object-level permission checks | Users can only optimize what they can edit | No privilege escalation | User + object | Allow/deny
REST nonce protection | All API calls require a valid WordPress nonce | CSRF protection | Request | Verified request
Input sanitization | Typed sanitizers per parameter; schema-validated settings | Injection protection | Input | Clean values
URL-safe sanitization | URLs keep percent-encoding (Persian-safe) while being validated | Correct and safe | URL | Valid URL
Output escaping | esc_* in PHP; textContent DOM building in JS | XSS protection | Data | Escaped output
Sandboxed previews | AI HTML previews in script-free iframes | Safe previews of untrusted HTML | HTML | Isolated render
Encrypted secrets | libsodium secretbox (AES-256-GCM fallback) for API keys, OAuth tokens, GitHub token | Secrets safe at rest | Secret | Ciphertext | db=wp_options (seolity_secrets)
wp-config overrides | Define SEOLITY_<SECRET> constants instead of storing secrets | Infra-managed secrets | Constants | Secret source | ui=wp-config.php
Secrets never sent to browser | API exposes only "is set" flags | No leakage | Secrets | Flags
SSRF guard | Blocks private/reserved IPs for user-supplied URLs | Protects internal network | URL | Allow/deny
Polite outbound crawling | robots.txt respect, rate limits, timeouts, size limits | Good citizen, stable host | Requests | Throttled requests
Audit log | Every change, setting, connection and destructive action with user and hashed IP | Accountability | Actions | Log | api=GET /audit-log
IP anonymization | IPs hashed/anonymized by default | Privacy (GDPR-friendly) | IP | Hash | ui=Settings → Privacy
Data retention | Configurable retention for logs and analytics | Data minimization | Days | Pruned tables | auto=Scheduled; ui=Settings → Privacy
Clean uninstall (opt-in) | Removes tables, options, meta, caps, cron only when enabled | Control over your data | Setting | Clean database | auto=Manual; ui=Settings → Privacy
Two-factor approval for high-risk changes | Require a second approver | Governance | Roles | Workflow | ed=Ent
''')

cat('Performance & Scalability Engineering', intro='Built to run on shared hosting and to scale to large catalogs.',
    deps='—', ui='Automation → Queue', auto='Auto', risk='None', db='jobs', api='GET /queue', features='''
DB-backed job queue | Priorities, unique keys, groups, delays, retries with backoff | Reliable background work | Jobs | Processed jobs
1-minute cron tick with time budget | Drains the queue within a budget under an atomic lock | Never blocks page loads | Cron | Processed jobs
Signed loopback kick | Starts the queue immediately after user actions | Snappy UI | User action | Queue run
Stale job recovery | Releases jobs stuck after crashes | Self-healing | Jobs | Recovered jobs
Memory guard | Stops a tick before memory limits | Stability on small hosts | Memory | Safe stop
Parallel crawling | Batched parallel HTTP requests | Fast audits | URLs | Responses
Bulk upserts | Efficient inserts for GSC and crawl data | Low DB load | Rows | Upserted rows
Lazy service container | Only needed services load per request | Minimal overhead | Request | Loaded services
No front-end AI or remote calls | Front end reads cached meta only | Zero impact on page speed | Request | Fast response
Indexed hash lookups | sha1 hashes for URLs, queries, redirects | Fast queries at scale | Values | Indexed lookups
WP-CLI + system cron | Run the queue from real cron for large sites | Predictable processing | CLI | Processed jobs | ui=WP-CLI
External worker mode | Offload jobs to a separate worker service | Enterprise scale | Jobs | Remote processing | ed=Ent
''')

cat('UI/UX, Accessibility & Internationalization', intro='A modern SaaS-style interface that is RTL-native and translatable.',
    deps='—', ui='All SEOlity screens', auto='Auto', risk='None', db='—', api='—', features='''
Single-page admin app | Fast hash-routed SPA (23 sections) without a build step | Smooth, app-like experience | — | UI
ARIAGH visual language | Same top bar, grouped icon sidebar, icon cards, toggle rows, save bar and palette as ARIAGH AI Writer | One familiar interface across your plugins | — | Consistent UI
Line icon set | 80+ 24px stroke icons for navigation, cards, stats, status marks and editor buttons (no emoji glyphs) | Consistent on every OS | Icon name | Inline SVG
Connection guide | Step-by-step bilingual guide with direct links for every API key and account (AI providers, subscriptions, GitHub, Search Console OAuth, PageSpeed, SerpApi), copy buttons, where-to-paste links and troubleshooting | Setup without searching the web | Service | Guide page + docs/CONNECT.md | ui=Connection guide page, Settings
Vazirmatn webfont (optional) | Persian-first typeface loaded from Google Fonts | Beautiful Persian typography | Setting | Webfont | deps=Google Fonts; ui=Settings → General
Persian (fa_IR) complete | 1,600 strings translated | Native experience for Persian users | — | Translated UI
Translatable (.pot) | Standard gettext workflow for new languages | English and more | — | POT file
UI language switch | Auto / فارسی / English independent of site locale | Work in your language | Setting | UI language | ui=Settings → General
RTL-native layout | Logical CSS properties, explicit direction | Correct RTL and LTR | Language | Layout
Dark / light mode | Toggle + system preference | Comfort | Preference | Theme
Responsive design | Mobile drawer navigation, wrapping cards, scrollable tables | Use on phones/tablets | Viewport | Layout
Keyboard & screen-reader support | Focus states, aria labels, modal focus management, Esc to close | Accessibility | — | Accessible UI
Reduced motion | Honors prefers-reduced-motion | Accessibility | Preference | No animations
Persian digits & dates | Locale-aware numbers and dates | Natural reading | Values | Formatted values
SVG charts | Dependency-free, RTL-aware charts | Fast, crisp visuals | Data | Charts
Toasts & confirmations | Clear feedback and confirmation for risky actions | Confidence | Actions | Messages
Empty states with guidance | Every empty view explains what to do next | Faster onboarding | State | Guidance
"Why?" explainability everywhere | Explanations for scores and recommendations | Trust | Data | Explanations
Safe mode previews | Preview every change before applying | Safety | Change | Preview
Version history UI | Browse and restore versions per object | Undo anything | Object | History
Persian-aware SERP preview | Pixel-width title/description preview in editors | Accurate snippet lengths | Meta | Preview | ui=Editors
Live SEO score in editors | Score updates while typing | Instant feedback | Editor content | Score | ui=Editors
Onboarding wizard | Guided first-run setup | Faster time to value | — | Steps | ed=Pro
Command palette | Keyboard command search | Power-user speed | Keys | Navigation | ed=Pro
''')

cat('Editor Integrations', intro='SEOlity works wherever content is edited.',
    deps='—', ui='Editors', auto='Assisted', risk='Medium', db='changes', api='/optimize, /meta, /faq, /links, /eeat', features='''
Gutenberg plugin sidebar | Optimizer, GSC keywords, meta, excerpt, FAQ, links, E-E-A-T, history, live analysis | Everything in the block editor | Editor | Actions | ui=Gutenberg
Classic editor meta box | Same tools plus SEO fields and SERP preview | Classic sites supported | Editor | Actions | ui=Classic editor
Classic media-row button | "✨ Optimize with SEOlity AI" above the editor | One-click access | Editor | Optimizer | ui=Classic editor
Elementor integration | Optimize widget text via Elementor commands | Page builder support | Elementor document | Updated widgets | deps=Elementor; ui=Elementor
Elementor-aware analysis | Reads Elementor data for scoring | Accurate scores for builder pages | _elementor_data | Analysis | deps=Elementor; risk=None
Term edit fields | Meta title/description/keyword + optimizer on categories/tags/product categories | Term SEO | Term | Fields | ui=Term edit
Author profile optimizer | Bio optimization on user profiles | E-E-A-T | User | Bio | ui=User profile
WooCommerce product screen | Product optimizer and short-description optimizer | Product SEO in place | Product | Package | deps=WooCommerce; ui=Product edit
SEO fields (standalone) | Title, description, focus keyword, canonical, robots when no other SEO plugin | Full SEO without another plugin | Fields | Meta | risk=High; ui=Editors
Works with Yoast / Rank Math / SEOPress / AIOSEO | Reads/writes their fields; disables duplicate head output | Keep your current SEO plugin | Engine detection | Synced meta | risk=Medium
Editor change versioning | Changes applied in editors become versions on save | Undo editor edits | save_post | Versions | auto=Auto
Page-builder support for Divi/Bricks | Optimize other builders' content | Wider compatibility | Builder data | Updates | ed=Pro
''')

cat('Agency, White Label & SaaS', intro='Run SEOlity for clients today; scale to many sites tomorrow.',
    deps='—', ui='Settings → General', auto='Manual', risk='None', db='projects', api='/settings', features='''
Brand name | Replace "SEOlity" in the admin UI, menus and reports | Agency branding | Name | Branded UI
Brand logo | Custom logo in the app and reports | Professional look | Logo URL | Branded UI
Brand color | Accent color for the interface | Match agency identity | Color | Themed UI
Client role | Read-only access for clients | Share results safely | User | seolity_client role
Branded reports | Reports carry your branding | Client-ready deliverables | Brand settings | Branded reports
Project-scoped data model | Every table carries project_id | Ready for multi-site management | — | Scoped data
Project filter hook | seolity_current_project_id for multi-project setups | Extensible multi-tenancy | Filter | Active project | ui=Code
REST API for integrations | ~120 authenticated routes | Build dashboards or connectors | Requests | JSON
Approval workflow for teams | Pending changes reviewed by managers | Quality control | Changes | Approvals
Audit trail per user | Who changed what | Client transparency | Actions | Log
Multi-site agency console | Manage many WordPress sites from one dashboard | Scale an agency | Sites | Console | ed=Ent
Client portal | Clients see reports and approve changes | Less back-and-forth | Users | Portal | ed=Ent
Per-client billing & AI quotas | Track and cap AI spend per client | Profitability | Usage | Quotas | ed=Ent
SSO (SAML/OIDC) | Single sign-on for teams | Enterprise security | IdP | Login | ed=Ent
SaaS control plane | Hosted crawlers/AI/GSC workers with a thin connector plugin | No load on client hosting | Sites | Hosted service | ed=Ent
''')

cat('Developer & Extensibility', intro='Hooks, CLI and a clean architecture for customization.',
    deps='—', ui='Code / WP-CLI', auto='Manual', risk='None', db='—', api='—', features='''
Service container filter | Replace or add services (seolity_register_services) | Customize any module | Callback | Services
AI provider filter | Register providers (seolity_ai_providers) | Use any model | Class | Provider
AI task filter | Add or override tasks (seolity_ai_tasks) | Custom AI workflows | Task definition | Task
Job registration | Register queue handlers (seolity_register_jobs) | Custom background work | Callback | Jobs
REST route registration | Extend seolity/v1 (seolity_rest_routes) | Custom endpoints | Callback | Routes
Recommendation generators | Add generators (seolity_generate_recommendations) | Domain-specific advice | Callback | Recommendations
Event actions | change_applied, audit_completed, gsc_synced, experiment_evaluated, alert_raised, job_failed | Integrate with anything | Events | Callbacks
Schema graph filter | Modify JSON-LD output | Custom markup | Graph | Graph
Autonomous risk filter | Decide which risk levels may auto-apply | Policy control | Levels | Policy
Queue budget filter | Seconds per tick | Tune for your host | Seconds | Budget
WP-CLI commands | queue run/stats/retry, audit, gsc-sync, daily, health, knowledge-sync, archive | Ops automation | CLI | Output
No-build front end | Plain JS/CSS, easy to read and patch | Low maintenance | — | —
Generated i18n pipeline | One script builds .pot/.po/.mo and the JS dictionary | Easy translations | TSV files | Language files | ui=tools/i18n/build.py
Feature catalog generator | This document is generated from structured data | Docs stay in sync | Data file | FEATURES.md | ui=tools/docs/build_features.py
''')

cat('Local & International SEO', intro='Persian-first, international-ready.',
    deps='—', ui='Settings, Site Audit, Keywords', auto='Auto', risk='None', db='—', api='—', features='''
Persian text engine | Normalizes ي/ك, ZWNJ, Persian digits; Persian stop words and tokenization | Accurate analysis of Persian content | Text | Normalized tokens
Persian URL handling | Canonical percent-encoding for Persian slugs everywhere | No duplicate URLs, correct GSC matching | URLs | Normalized URLs
Content language setting | Force AI output language or auto-detect | Multilingual sites | Setting | Language | ui=Settings → AI
Market-aware keyword research | Research for a given market/language | Local demand | Market | Keywords | deps=AI provider
Market-aware SERP analysis | Local Google results (gl/hl) | Local competition | Market | SERP | deps=SerpApi key, AI provider
IRR currency handling in product schema | ISO-correct currency for Iranian stores (Toman → Rial) | Valid merchant markup | Price | Offer markup | deps=WooCommerce
Language attribute check | Missing html lang detection | Correct language signals | Crawl | Issues | db=issues
Hreflang validation | Invalid hreflang language codes and missing self-references | International targeting | Crawl | Issues | db=issues
LocalBusiness entity | Organization type, address and phone in schema | Local search signals | Settings | JSON-LD | ui=Settings → Schema & entity
Country breakdown | Search Console metrics per country | International insights | GSC | Table | deps=Search Console
Google Business Profile insights | Calls, directions, reviews from GBP | Local performance | GBP API | Metrics | ed=Pro
Hreflang generator | Builds hreflang sets for multilingual plugins | Correct international setup | Translations | Tags | ed=Pro
''')
