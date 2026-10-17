#!/usr/bin/env node
/**
 * ARIAGH.AIWRITER – Subscription Runner (GitHub Actions)
 * ---------------------------------------------------------------
 * Writes articles with the AI account you already pay for (chat subscription),
 * using the vendors' OFFICIAL command-line tools in headless mode:
 *   - engine "claude": Claude Code  (`claude -p`)  + CLAUDE_CODE_OAUTH_TOKEN (Claude Pro/Max)
 *   - engine "codex" : OpenAI Codex (`codex exec`) + ChatGPT auth.json      (ChatGPT Plus/Pro)
 *
 * Flow: WordPress (/job) → engine → [Workers AI image via your Cloudflare worker] → WordPress (/draft)
 * All calls to WordPress and the Cloudflare worker are HMAC-signed with AAW_SHARED_SECRET.
 * Node 18+ – no npm dependencies.
 */
import { spawn } from 'node:child_process';
import { createHmac, randomBytes } from 'node:crypto';
import { mkdtemp, writeFile, readFile, rm, mkdir, appendFile, readdir, stat } from 'node:fs/promises';
import { dirname } from 'node:path';
import { existsSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const E = process.env;
const WP_API = (E.AAW_WP_API || '').replace(/\/+$/, '');
const WORKER = (E.AAW_WORKER_URL || '').replace(/\/+$/, '');
const SECRET = E.AAW_SHARED_SECRET || '';
const TRIGGER = E.AAW_TRIGGER || 'cron';
const TOPIC_ID = Number(E.AAW_TOPIC_ID || 0);
const ENGINE_IN = (E.AAW_ENGINE || 'claude').toLowerCase();
// 'none' = no subscription CLI (site backup / SEO database jobs that use the API engines on Cloudflare).
const ENGINE = ENGINE_IN === 'codex' ? 'codex' : ENGINE_IN === 'none' ? 'none' : 'claude';
const MODEL = /^[a-z0-9.\-\[\]:]{0,80}$/i.test(E.AAW_MODEL || '') ? (E.AAW_MODEL || '') : '';
const PULL_ID = String(E.AAW_PULL_ID || '').replace(/[^a-z0-9]/gi, '');
const CLAUDE_BIN = E.CLAUDE_BIN || 'claude';
const CODEX_BIN = E.CODEX_BIN || 'codex';
const ENGINE_TIMEOUT = Math.min(Math.max(Number(E.AAW_ENGINE_TIMEOUT || 900), 60), 1800) * 1000;

const log = (...a) => console.log('[aria]', ...a);
const summaryLines = [];
const summary = (l) => summaryLines.push(l);

/* ------------------------------ signing ------------------------------ */
function signHeaders(route, body) {
  const ts = Math.floor(Date.now() / 1000);
  const nonce = randomBytes(12).toString('hex');
  const sig = createHmac('sha256', SECRET).update(`${ts}.${nonce}.${route}.${body}`).digest('hex');
  return { 'X-AAW-Timestamp': String(ts), 'X-AAW-Nonce': nonce, 'X-AAW-Signature': sig };
}

async function signedPost(base, route, payload, timeoutMs = 120000) {
  const body = JSON.stringify(payload || {});
  const ctrl = new AbortController();
  const t = setTimeout(() => ctrl.abort(), timeoutMs);
  try {
    const res = await fetch(`${base}/${route}`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'User-Agent': 'AriaAIWriter-Runner/1.2', ...signHeaders(route, body) },
      body,
      signal: ctrl.signal,
    });
    const text = await res.text();
    let data = null;
    try { data = JSON.parse(text); } catch (_) { /* ignore */ }
    if (!res.ok || !data) throw new Error(`${route}: ${(data && (data.error || data.message)) || text.slice(0, 200) || 'HTTP ' + res.status}`);
    return data;
  } finally {
    clearTimeout(t);
  }
}
const wp = (route, p) => signedPost(WP_API, route, p);
const worker = (route, p, ms) => {
  if (!WORKER) throw new Error('AAW_WORKER_URL is not set');
  return signedPost(WORKER, route, p, ms || 180000);
};

/* ------------------------------ reporting (direct or via Cloudflare KV for local sites) ------------------------------ */
const mailbox = { results: [], logs: [], ideas: null, fatal: '', analysis: null, images: [], revisions: [], site_data: null, seo_playbook: null };
async function reportDraft(topicId, article, meta) {
  if (PULL_ID) { mailbox.results.push({ topic_id: topicId, article, meta }); return { post_id: 0, title: article.title, edit_url: '(local site will collect it)' }; }
  if (article.image) article.image = await compressImage(article.image);
  if (Array.isArray(article.inline_images)) article.inline_images = await Promise.all(article.inline_images.map(compressImage));
  return wp('draft', { topic_id: topicId, article, meta });
}
async function reportFail(topicId, error, meta) {
  if (PULL_ID) { mailbox.results.push({ topic_id: topicId, error, meta }); return; }
  return wp('fail', { topic_id: topicId, error, meta });
}
async function reportLog(level, event, message) {
  if (PULL_ID) { mailbox.logs.push({ level, event, message }); return; }
  return wp('log', { level, event, message });
}
/** Shrink big images (PNG 1–3 MB → WebP ~150 KB) so slow hosts download them quickly. */
export async function compressImage(img) {
  if (!img || !img.base64 || img.base64.length < 250000) return img;
  try {
    const sharp = (await import('sharp')).default;
    const buf = Buffer.from(img.base64, 'base64');
    const out = await sharp(buf).resize({ width: 1600, withoutEnlargement: true }).webp({ quality: 82 }).toBuffer();
    if (out.length > 1000 && out.length < buf.length) return { ...img, base64: out.toString('base64'), mime: 'image/webp', shrunk: true };
  } catch (_) { /* sharp not installed – keep original */ }
  return img;
}
async function compressMailbox(m) {
  for (const r of m.results || []) {
    if (r.article && r.article.image) r.article.image = await compressImage(r.article.image);
    if (r.article && Array.isArray(r.article.inline_images)) r.article.inline_images = await Promise.all(r.article.inline_images.map(compressImage));
  }
  for (const im of m.images || []) {
    im.image = await compressImage(im.image);
    if (Array.isArray(im.inline)) im.inline = await Promise.all(im.inline.map(compressImage));
  }
}
async function flushMailbox() {
  if (!PULL_ID) return;
  await compressMailbox(mailbox);
  await worker('result_put', { id: PULL_ID, data: mailbox }, 120000);
  log('Result stored in Cloudflare KV for the local site.');
}

/* ------------------------------ process helper ------------------------------ */
function run(bin, args, input, env, timeoutMs = ENGINE_TIMEOUT) {
  return new Promise((resolve) => {
    let out = '';
    let err = '';
    let killed = false;
    const child = spawn(bin, args, { env, stdio: ['pipe', 'pipe', 'pipe'] });
    const timer = setTimeout(() => { killed = true; child.kill('SIGKILL'); }, timeoutMs);
    child.stdout.on('data', (d) => { out += d; });
    child.stderr.on('data', (d) => { err += d; });
    child.on('error', (e) => { clearTimeout(timer); resolve({ code: -1, out, err: String(e.message), killed }); });
    child.on('close', (code) => { clearTimeout(timer); resolve({ code, out, err, killed }); });
    if (input) child.stdin.write(input);
    child.stdin.end();
  });
}

/* ------------------------------ engines ------------------------------ */
let workDir = '';
let codexHome = '';
let codexAuthBefore = '';

async function prepare() {
  workDir = await mkdtemp(join(tmpdir(), 'aria-'));
  if (ENGINE === 'none') return;
  if (ENGINE === 'claude') {
    if (!E.CLAUDE_CODE_OAUTH_TOKEN) throw new Error('Secret CLAUDE_CODE_OAUTH_TOKEN is missing. Save your `claude setup-token` token from the plugin.');
    return;
  }
  // Codex: prefer the freshest auth.json stored in Cloudflare KV, fall back to the GitHub secret.
  codexHome = E.CODEX_HOME || join(workDir, 'codex');
  await mkdir(codexHome, { recursive: true });
  let auth = '';
  if (WORKER) {
    try {
      const r = await worker('codex_auth', { action: 'get' }, 30000);
      if (r && r.data) auth = r.data;
    } catch (e) { log('KV auth fetch skipped:', e.message); }
  }
  if (!auth) auth = E.CODEX_AUTH_JSON || '';
  if (!auth) throw new Error('ChatGPT auth.json is missing. Paste it in the plugin (Subscription tab).');
  try { JSON.parse(auth); } catch (_) { throw new Error('ChatGPT auth.json is not valid JSON.'); }
  codexAuthBefore = auth;
  await writeFile(join(codexHome, 'auth.json'), auth, { mode: 0o600 });
  await writeFile(join(codexHome, 'config.toml'), 'cli_auth_credentials_store = "file"\n');
}

async function persistCodexAuth() {
  if (ENGINE !== 'codex' || !codexHome || !WORKER) return;
  try {
    const now = await readFile(join(codexHome, 'auth.json'), 'utf8');
    if (now && now !== codexAuthBefore) {
      await worker('codex_auth', { action: 'put', data: now }, 30000);
      codexAuthBefore = now;
      log('Refreshed ChatGPT token saved to Cloudflare KV.');
    }
  } catch (e) { log('Could not persist refreshed auth:', e.message); }
}

async function askClaude(system, prompt, model, opts = {}) {
  const sysFile = join(workDir, `sys-${Date.now()}.txt`);
  await writeFile(sysFile, system || 'You are a helpful writer.', 'utf8');
  const args = opts.web
    ? ['-p', '--output-format', 'json', '--model', model || 'sonnet', '--tools', 'WebSearch,WebFetch', '--allowedTools', 'WebSearch,WebFetch', '--max-turns', '40', '--no-session-persistence', '--system-prompt-file', sysFile]
    : ['-p', '--output-format', 'json', '--model', model || 'sonnet', '--tools', '', '--max-turns', '2', '--no-session-persistence', '--system-prompt-file', sysFile];
  const env = { ...E, DISABLE_AUTOUPDATER: '1' };
  delete env.ANTHROPIC_API_KEY; // never bill the API – use the subscription token
  delete env.ANTHROPIC_AUTH_TOKEN;
  const r = await run(CLAUDE_BIN, args, prompt, env);
  if (r.killed) throw new Error('Claude Code timeout');
  let data = null;
  try { data = JSON.parse(r.out.trim().split('\n').filter(Boolean).pop() || ''); } catch (_) { /* ignore */ }
  if (!data) throw new Error('Claude Code: ' + (r.err || r.out || 'no output').slice(0, 500));
  if (data.is_error || (data.subtype && data.subtype !== 'success')) {
    const msg = String(data.result || data.subtype || 'error').slice(0, 500);
    throw Object.assign(new Error((/limit|quota|usage/i.test(msg) ? 'سهمیه اشتراک Claude: ' : 'Claude Code: ') + msg), { quota: /limit|quota|usage/i.test(msg) });
  }
  const u = data.usage || {};
  return { text: String(data.result || ''), usage: { in: (u.input_tokens || 0) + (u.cache_read_input_tokens || 0) + (u.cache_creation_input_tokens || 0), out: u.output_tokens || 0 } };
}

async function askCodex(system, prompt, model) {
  const outFile = join(workDir, `out-${Date.now()}.txt`);
  const input = (system ? `# Instructions\n${system}\n\n# Task\n` : '') + prompt + '\n\nReturn only the requested output. Do not run commands or edit files.';
  const args = ['exec', '--skip-git-repo-check', '--sandbox', 'read-only', '--ephemeral', '--json', '-o', outFile];
  if (model) args.push('-m', model);
  args.push('-');
  const env = { ...E, CODEX_HOME: codexHome };
  delete env.OPENAI_API_KEY; // never bill the API – use the ChatGPT plan
  delete env.CODEX_API_KEY;
  const r = await run(CODEX_BIN, args, input, env);
  if (r.killed) throw new Error('Codex timeout');
  let text = '';
  if (existsSync(outFile)) text = (await readFile(outFile, 'utf8')).trim();
  let usage = { in: 0, out: 0 };
  let errorMsg = '';
  for (const line of r.out.split('\n')) {
    if (!line.trim().startsWith('{')) continue;
    try {
      const ev = JSON.parse(line);
      const u = ev.usage || (ev.msg && ev.msg.usage) || (ev.info && ev.info.total_token_usage);
      if (u) usage = { in: u.input_tokens || usage.in, out: u.output_tokens || usage.out };
      if (ev.type === 'error' || ev.type === 'turn.failed') errorMsg = (ev.message || (ev.error && ev.error.message) || errorMsg);
    } catch (_) { /* ignore */ }
  }
  await persistCodexAuth();
  if (!text) {
    const msg = (errorMsg || r.err || 'no output').slice(0, 500);
    throw Object.assign(new Error((/limit|quota|usage/i.test(msg) ? 'سهمیه اشتراک ChatGPT: ' : 'Codex: ') + msg), { quota: /limit|quota|usage/i.test(msg) });
  }
  return { text, usage };
}

/**
 * Image with the ChatGPT plan: Codex CLI's official built-in image generation ($imagegen,
 * gpt-image) – counts against the ChatGPT subscription, no Images API key.
 * Reference images (product photos + site theme samples) are passed with -i.
 */
async function codexImage(prompt, refFiles, outName) {
  const outDir = join(workDir, 'out');
  await mkdir(outDir, { recursive: true });
  const target = join(outDir, outName);
  const genDir = join(codexHome, 'generated_images');
  const before = Date.now();
  const task = `Use your built-in image generation ($imagegen skill / image_gen tool) to create exactly ONE photorealistic image.
${refFiles.length ? `The attached images are references: product photos first (keep the exact product, packaging, label and colors), then example images from the website (match their color palette, lighting and mood).\n` : ''}Image description: ${prompt}
No text, letters or watermark in the image. Save the final image as ${target} (PNG). Do not create any other files and do not run unrelated commands.`;
  const args = ['exec', '--skip-git-repo-check', '--sandbox', 'workspace-write', '--ephemeral', '--json', '-C', workDir];
  if (refFiles.length) args.push('-i', refFiles.join(','));
  args.push('-');
  const env = { ...E, CODEX_HOME: codexHome };
  delete env.OPENAI_API_KEY; // use the ChatGPT plan, never the Images API
  delete env.CODEX_API_KEY;
  const r = await run(CODEX_BIN, args, task, env);
  await persistCodexAuth();
  let file = existsSync(target) ? target : '';
  if (!file && existsSync(genDir)) {
    // Fall back to the newest image Codex saved in its generated_images folder.
    const walk = async (d) => {
      let best = null;
      for (const n of await readdir(d)) {
        const f = join(d, n); const st = await stat(f);
        if (st.isDirectory()) { const b = await walk(f); if (b && (!best || b.t > best.t)) best = b; }
        else if (/\.(png|jpe?g|webp)$/i.test(n) && st.mtimeMs >= before - 1000 && (!best || st.mtimeMs > best.t)) best = { f, t: st.mtimeMs };
      }
      return best;
    };
    const b = await walk(genDir);
    if (b) file = b.f;
  }
  if (!file) {
    const msg = (r.err || r.out || 'no image produced').slice(-600);
    throw Object.assign(new Error((/limit|quota|usage/i.test(msg) ? 'سهمیه تصویر ChatGPT: ' : 'Codex image: ') + msg), { quota: /limit|quota|usage/i.test(msg) });
  }
  const buf = await readFile(file);
  const mime = /\.jpe?g$/i.test(file) ? 'image/jpeg' : /\.webp$/i.test(file) ? 'image/webp' : 'image/png';
  return { base64: buf.toString('base64'), mime, engine: 'sub_codex' };
}

async function refsToFiles(refs) {
  const files = [];
  let i = 0;
  for (const r of refs || []) {
    try {
      let buf = null;
      if (r.b64) buf = Buffer.from(r.b64, 'base64');
      else if (r.url) { const res = await fetch(r.url); if (res.ok) buf = Buffer.from(await res.arrayBuffer()); }
      if (buf && buf.length > 100) { const f = join(workDir, `ref-${i++}.jpg`); await writeFile(f, buf); files.push(f); }
    } catch (_) { /* skip unreachable reference */ }
  }
  return files.slice(0, 8);
}

const ask = (system, prompt, model, opts) => {
  if (ENGINE === 'none') throw new Error('No subscription engine for this task.');
  return ENGINE === 'codex' ? askCodex(system, prompt, model) : askClaude(system, prompt, model, opts);
};
/** Subscription engine when present, otherwise the API engines on your Cloudflare worker. */
async function askAny(system, prompt, runtime, params = {}, opts = {}) {
  if (ENGINE !== 'none') return ask(system, prompt, MODEL, opts);
  const r = await worker('complete', { runtime: runtime || {}, system, prompt, params }, 600000);
  return { text: r.text || '', usage: r.usage || { in: 0, out: 0 }, provider: r.provider, model: r.model };
}

/* ------------------------------ JSON parsing (same as worker) ------------------------------ */
function escapeControl(src) {
  let out = ''; let inStr = false; let esc = false;
  for (const ch of src) {
    if (inStr) {
      if (esc) { out += ch; esc = false; continue; }
      if (ch === '\\') { out += ch; esc = true; continue; }
      if (ch === '"') { inStr = false; out += ch; continue; }
      if (ch.charCodeAt(0) < 0x20) { out += ch === '\n' ? '\\n' : ch === '\t' ? '\\t' : ch === '\r' ? '' : ' '; continue; }
      out += ch;
    } else { if (ch === '"') inStr = true; out += ch; }
  }
  return out;
}
export function parseJSON(text) {
  if (!text) return null;
  let t = String(text).trim().replace(/^﻿/, '').replace(/<think>[\s\S]*?<\/think>/gi, '').trim();
  const fence = t.match(/```(?:json)?\s*([\s\S]*?)```/i);
  if (fence) t = fence[1].trim();
  const a = t.indexOf('{'); const b = t.lastIndexOf('}');
  if (a === -1 || b <= a) return null;
  const c = t.slice(a, b + 1);
  try { return JSON.parse(c); } catch (_) { /* repair */ }
  try { return JSON.parse(escapeControl(c).replace(/,\s*([}\]])/g, '$1')); } catch (_) { return null; }
}

/* ------------------------------ site backup & knowledge database (GitHub branch "aaw-data") ------------------------------
 * The site is read from GitHub (public WordPress REST API / WooCommerce Store API, sitemap fallback),
 * so your host only sends one tiny request. Everything is stored in the private repository on the
 * branch "aaw-data" (site/ = full text backup + compact index, seo/ = SEO knowledge). Every new
 * analysis replaces the previous backup (single orphan commit, force-pushed → old data deleted).
 */
export const DATA_BRANCH = 'aaw-data';
const SITE_UA = 'Mozilla/5.0 (compatible; ARIAGH-AIWRITER-Crawler/1.8)';
const CRAWL_TIMEOUT = 25000;

export function htmlToText(html) {
  return String(html || '')
    .replace(/<(script|style|noscript|svg|iframe)[\s\S]*?<\/\1>/gi, ' ')
    .replace(/<h([1-6])[^>]*>/gi, (m, n) => '\n\n' + '#'.repeat(Math.min(6, Number(n) + 1)) + ' ')
    .replace(/<\/h[1-6]>/gi, '\n')
    .replace(/<li[^>]*>/gi, '\n- ')
    .replace(/<(br|\/p|\/div|\/tr|\/ul|\/ol|\/table|\/section|\/article)[^>]*>/gi, '\n')
    .replace(/<[^>]+>/g, ' ')
    .replace(/&nbsp;/g, ' ').replace(/&zwnj;|&#8204;/g, '‌').replace(/&amp;/g, '&').replace(/&quot;|&#8221;|&#8220;/g, '"').replace(/&#039;|&#8217;|&#8216;/g, "'").replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&#8211;|&#8212;/g, '–').replace(/&#\d+;/g, ' ')
    .replace(/[ \t ]+/g, ' ').replace(/ *\n */g, '\n').replace(/\n{3,}/g, '\n\n').trim();
}
const oneLine = (s, n) => htmlToText(s).replace(/\s+/g, ' ').trim().slice(0, n);

async function getRaw(url, accept = 'application/json') {
  const ctrl = new AbortController();
  const t = setTimeout(() => ctrl.abort(), CRAWL_TIMEOUT);
  try {
    const res = await fetch(url, { headers: { 'User-Agent': SITE_UA, Accept: accept }, redirect: 'follow', signal: ctrl.signal });
    const text = await res.text();
    return { ok: res.ok, status: res.status, text, headers: res.headers };
  } catch (e) {
    return { ok: false, status: 0, text: '', error: e.name === 'AbortError' ? 'timeout' : e.message };
  } finally { clearTimeout(t); }
}
async function getJSON(url) {
  const r = await getRaw(url);
  let data = null;
  if (r.ok) { try { data = JSON.parse(r.text.replace(/^﻿/, '')); } catch (_) { data = null; } }
  return { ...r, data };
}

/** Find the REST base: pretty permalinks (/wp-json/) or plain (?rest_route=). */
async function restBase(site) {
  for (const base of [`${site}/wp-json`, `${site}/?rest_route=`]) {
    const r = await getJSON(`${base}/`);
    if (r.data && (r.data.namespaces || r.data.routes)) {
      const ns = r.data.namespaces || [];
      return { base, wc: ns.includes('wc/store/v1') || ns.includes('wc/store'), error: '' };
    }
    if (r.status === 401 || r.status === 403) return { base: '', error: `REST API از بیرون بسته است (HTTP ${r.status}). معمولاً افزونه امنیتی یا فایروال هاست درخواست‌های GitHub را مسدود کرده است.` };
  }
  return { base: '', error: 'REST API وردپرس از بیرون در دسترس نبود.' };
}
const qs = (base, route, params) => {
  const sep = base.includes('?rest_route=') ? '&' : '?';
  return `${base}${route}${sep}${params}`;
};

async function paged(base, route, fields, cap, log) {
  const out = [];
  for (let page = 1; page <= 60 && out.length < cap; page++) {
    const r = await getJSON(qs(base, route, `per_page=100&page=${page}${fields ? '&_fields=' + fields : ''}`));
    if (!r.ok || !Array.isArray(r.data)) {
      if (page === 1 && r.status !== 400) log && log(`${route}: HTTP ${r.status || r.error}`);
      break;
    }
    out.push(...r.data);
    const total = Number(r.headers && r.headers.get && r.headers.get('x-wp-totalpages')) || 0;
    if (r.data.length < 100 || (total && page >= total)) break;
  }
  return out.slice(0, cap);
}

function priceText(p) {
  if (!p) return '';
  const minor = Number(p.currency_minor_unit || 0);
  const fmt = (v) => {
    if (v === undefined || v === null || v === '') return '';
    const n = Number(v) / Math.pow(10, minor);
    return Number.isFinite(n) ? n.toLocaleString('en-US') : String(v);
  };
  const cur = p.currency_symbol || p.currency_code || '';
  const range = p.price_range && p.price_range.min_amount ? `${fmt(p.price_range.min_amount)} تا ${fmt(p.price_range.max_amount)}` : '';
  const main = range || fmt(p.sale_price && p.sale_price !== p.regular_price ? p.sale_price : p.price);
  return main ? `${main} ${cur}`.trim() : '';
}

async function crawlRest(site, rb, log) {
  const { base } = rb;
  const cats = await paged(base, '/wp/v2/categories', 'id,name,link,count,description', 500, log);
  const tags = await paged(base, '/wp/v2/tags', 'id,name,count', 500, log);
  const catName = new Map(cats.map((c) => [c.id, c.name]));
  const tagName = new Map(tags.map((t) => [t.id, t.name]));
  const posts = (await paged(base, '/wp/v2/posts', 'id,link,slug,date,modified,title,excerpt,content,categories,tags', 3000, log)).map((p) => ({
    id: p.id, slug: p.slug || '', u: p.link, t: oneLine(p.title && p.title.rendered, 200), d: String(p.date || '').slice(0, 10), m: String(p.modified || '').slice(0, 10),
    c: (p.categories || []).map((i) => catName.get(i)).filter(Boolean), tg: (p.tags || []).map((i) => tagName.get(i)).filter(Boolean).slice(0, 12),
    ex: oneLine(p.excerpt && p.excerpt.rendered, 300), body: htmlToText(p.content && p.content.rendered),
  }));
  const pages = (await paged(base, '/wp/v2/pages', 'id,link,slug,title,content', 400, log)).map((p) => ({ id: p.id, slug: p.slug || '', u: p.link, t: oneLine(p.title && p.title.rendered, 200), body: htmlToText(p.content && p.content.rendered) }));
  let products = []; let pcats = [];
  if (rb.wc) {
    products = (await paged(base, '/wc/store/v1/products', '', 3000, log)).map((p) => ({
      id: p.id, slug: p.slug || '', u: p.permalink, n: oneLine(p.name, 200), p: priceText(p.prices), s: p.is_in_stock !== false,
      c: (p.categories || []).map((c) => c.name).filter(Boolean), ex: oneLine(p.short_description || p.description, 300),
      img: (p.images && p.images[0] && p.images[0].src) || '', sku: p.sku || '', body: htmlToText(p.description || p.short_description),
    }));
    pcats = (await getJSON(qs(base, '/wc/store/v1/products/categories', 'per_page=100'))).data || [];
    pcats = Array.isArray(pcats) ? pcats.map((c) => ({ n: oneLine(c.name, 120), u: c.permalink || '', count: c.count || 0, type: 'product' })) : [];
  }
  const categories = cats.map((c) => ({ n: oneLine(c.name, 120), u: c.link, count: c.count || 0, type: 'post', d: oneLine(c.description, 200) })).concat(pcats);
  return { source: 'rest', posts, pages, products, categories, tags: tags.map((t) => t.name).slice(0, 200) };
}

/** Fallback when the REST API is closed: read the sitemap and the public pages. */
async function crawlSitemap(site, log) {
  const seen = new Set(); const urls = []; const queue = [];
  for (const p of ['/wp-sitemap.xml', '/sitemap_index.xml', '/sitemap.xml', '/product-sitemap.xml']) queue.push({ u: site + p, kind: '' });
  let maps = 0;
  while (queue.length && maps < 40 && urls.length < 1500) {
    const { u, kind } = queue.shift();
    if (seen.has(u)) continue;
    seen.add(u);
    const r = await getRaw(u, 'application/xml,text/xml,*/*');
    if (!r.ok || !/<(urlset|sitemapindex)/i.test(r.text)) continue;
    maps++;
    const k = /product[-_]?cat|product_cat/i.test(u) ? 'pcat' : /product/i.test(u) ? 'product' : /categor/i.test(u) ? 'cat' : /page/i.test(u) ? 'page' : /tag|author|user/i.test(u) ? 'skip' : (kind || 'post');
    if (/<sitemapindex/i.test(r.text)) {
      for (const m of r.text.matchAll(/<loc>\s*([^<\s]+)\s*<\/loc>/gi)) queue.push({ u: m[1].replace(/&amp;/g, '&'), kind: '' });
    } else if (k !== 'skip') {
      for (const m of r.text.matchAll(/<loc>\s*([^<\s]+)\s*<\/loc>/gi)) urls.push({ u: m[1].replace(/&amp;/g, '&'), k });
    }
  }
  if (!urls.length) throw new Error('نقشه سایت پیدا نشد و REST API هم بسته بود؛ GitHub نتوانست سایت را بخواند.');
  const posts = []; const pages = []; const products = []; const categories = [];
  const list = urls.filter((x) => x.u.startsWith(site) || x.u.includes(new URL(site).host)).slice(0, 700);
  let i = 0;
  const worker = async () => {
    while (i < list.length) {
      const x = list[i++];
      const r = await getRaw(x.u, 'text/html,*/*');
      if (!r.ok) continue;
      const h = r.text;
      const meta = (name) => ((h.match(new RegExp(`<meta[^>]+(?:property|name)=["']${name}["'][^>]*content=["']([^"']*)`, 'i')) || [])[1] || '');
      const title = oneLine(meta('og:title') || (h.match(/<title[^>]*>([\s\S]*?)<\/title>/i) || [])[1] || '', 200);
      const main = (h.match(/<(article|main)[^>]*>([\s\S]*?)<\/\1>/i) || [])[2] || h;
      const body = htmlToText(main).slice(0, 40000);
      const ex = oneLine(meta('description') || meta('og:description'), 300);
      const isProduct = x.k === 'product' || /og:type["'][^>]*content=["']product/i.test(h);
      if (x.k === 'cat' || x.k === 'pcat') categories.push({ n: title.replace(/\s*[-–|].*$/, ''), u: x.u, count: 0, type: x.k === 'pcat' ? 'product' : 'post' });
      else if (isProduct) {
        const amount = meta('product:price:amount') || oneLine((h.match(/class="[^"]*woocommerce-Price-amount[^"]*"[^>]*>([\s\S]*?)<\/span>\s*<\/(?:bdi|span)>/i) || [])[1] || '', 60);
        products.push({ id: 0, u: x.u, n: title, p: amount ? `${amount} ${meta('product:price:currency')}`.trim() : '', s: !/out-of-stock|ناموجود/i.test(h.slice(0, 200000)), c: [], ex, img: meta('og:image'), body });
      } else if (x.k === 'page') pages.push({ id: 0, u: x.u, t: title, body });
      else posts.push({ id: 0, u: x.u, t: title, d: (meta('article:published_time') || '').slice(0, 10), c: [meta('article:section')].filter(Boolean), tg: [], ex, body });
    }
  };
  await Promise.all(Array.from({ length: 6 }, worker));
  log && log(`sitemap: ${urls.length} URLs, ${posts.length} posts, ${products.length} products`);
  return { source: 'sitemap', posts, pages, products, categories, tags: [] };
}

export async function crawlSite(site, log = () => {}) {
  site = String(site || '').replace(/\/+$/, '');
  if (!/^https?:\/\//i.test(site)) throw new Error('آدرس سایت نامعتبر است.');
  const rb = await restBase(site);
  let data; const notes = [];
  if (rb.base) {
    data = await crawlRest(site, rb, (m) => notes.push(m));
    if (!data.posts.length && !data.products.length) {
      notes.push('REST API خالی برگشت؛ از نقشه سایت خوانده شد.');
      data = await crawlSitemap(site, (m) => notes.push(m));
    }
  } else {
    notes.push(rb.error + ' از نقشه سایت خوانده شد.');
    data = await crawlSitemap(site, (m) => notes.push(m));
  }
  data.site = site; data.notes = notes; data.crawled_at = new Date().toISOString();
  log(`crawl: ${data.source} posts=${data.posts.length} products=${data.products.length} pages=${data.pages.length} categories=${data.categories.length}`);
  return data;
}

/** Compact index used by the AI (links + dedupe); full text stays in the backup files. */
export function buildIndex(d) {
  return {
    v: 1, site: d.site, crawled_at: d.crawled_at, source: d.source,
    counts: { posts: d.posts.length, products: d.products.length, pages: d.pages.length, categories: d.categories.length, tags: (d.tags || []).length },
    posts: d.posts.map(({ id, t, u, c, tg, d: dt, ex }) => ({ id, t, u, c, tg, d: dt, ex: (ex || '').slice(0, 160) })),
    products: d.products.map(({ id, n, u, p, s, c, ex, img }) => ({ id, n, u, p, s, c, ex: (ex || '').slice(0, 160), img })),
    pages: d.pages.map(({ t, u }) => ({ t, u })),
    categories: d.categories, tags: d.tags || [],
  };
}

const slugify = (s, i) => (String(s || '').toLowerCase().replace(/[^\p{L}\p{N}]+/gu, '-').replace(/^-+|-+$/g, '').slice(0, 60) || 'item') + (i ? '-' + i : '');
export function backupFiles(d) {
  const idx = buildIndex(d);
  const f = {};
  const md = (title, fields, body) => `# ${title}\n\n${Object.entries(fields).filter(([, v]) => v && (!Array.isArray(v) || v.length)).map(([k, v]) => `- **${k}:** ${Array.isArray(v) ? v.join('، ') : v}`).join('\n')}\n\n${body || ''}\n`;
  d.posts.forEach((p, i) => { f[`site/posts/${String(i + 1).padStart(4, '0')}-${slugify(p.slug || p.t)}.md`] = md(p.t, { url: p.u, date: p.d, modified: p.m, categories: p.c, tags: p.tg }, p.body); });
  d.products.forEach((p, i) => { f[`site/products/${String(i + 1).padStart(4, '0')}-${slugify(p.slug || p.n)}.md`] = md(p.n, { url: p.u, price: p.p, stock: p.s ? 'موجود' : 'ناموجود', sku: p.sku, categories: p.c, image: p.img }, p.body); });
  d.pages.forEach((p, i) => { f[`site/pages/${String(i + 1).padStart(3, '0')}-${slugify(p.slug || p.t)}.md`] = md(p.t, { url: p.u }, p.body); });
  f['site/categories.md'] = '# دسته‌بندی‌ها\n\n' + d.categories.map((c) => `- [${c.n}](${c.u}) — ${c.type === 'product' ? 'محصولات' : 'مقالات'}${c.count ? ' (' + c.count + ')' : ''}`).join('\n') + '\n';
  f['site/index.json'] = JSON.stringify(idx);
  f['site/meta.json'] = JSON.stringify({ site: d.site, crawled_at: d.crawled_at, source: d.source, counts: idx.counts, notes: d.notes || [] }, null, 2);
  f['site/README.md'] = `# نسخه پشتیبان متنی سایت\n\n- سایت: ${d.site}\n- تاریخ: ${d.crawled_at}\n- روش خواندن: ${d.source === 'rest' ? 'REST API وردپرس/ووکامرس' : 'نقشه سایت'}\n- مقالات: ${idx.counts.posts} · محصولات: ${idx.counts.products} · برگه‌ها: ${idx.counts.pages} · دسته‌ها: ${idx.counts.categories}\n\nهر بار «تحلیل سایت» زده شود، این پوشه کامل حذف و با اطلاعات جدید جایگزین می‌شود.\n`;
  return { files: f, index: idx };
}

/** Text version of the backup for the strategy analysis prompt. */
export function inventoryFromIndex(idx, max = 60000) {
  const o = [`# داده‌های کامل سایت (نسخه پشتیبان GitHub، ${String(idx.crawled_at || '').slice(0, 10)})`];
  const pc = idx.categories.filter((c) => c.type !== 'product'); const prc = idx.categories.filter((c) => c.type === 'product');
  if (pc.length) o.push('\n## دسته‌بندی‌های وبلاگ (نام: تعداد)\n' + pc.map((c) => `${c.n}: ${c.count}`).join(' | '));
  if (prc.length) o.push('\n## دسته‌بندی‌های محصولات\n' + prc.map((c) => `${c.n}: ${c.count}`).join(' | '));
  if (idx.products.length) o.push(`\n## محصولات (${idx.products.length})\n` + idx.products.map((p) => `- ${p.n}${p.c && p.c.length ? ' [' + p.c.slice(0, 2).join('، ') + ']' : ''}${p.p ? ' — قیمت: ' + p.p : ''}${p.s ? '' : ' (ناموجود)'} — ${p.u}`).join('\n'));
  if (idx.tags && idx.tags.length) o.push('\n## برچسب‌ها\n' + idx.tags.slice(0, 60).join('، '));
  if (idx.pages.length) o.push('\n## برگه‌ها\n' + idx.pages.map((p) => p.t).join('، '));
  o.push(`\n## مقالات منتشرشده (${idx.posts.length}) — این موضوعات را تکرار نکن`);
  o.push(idx.posts.map((p) => `- ${p.t}${p.c && p.c.length ? ' [' + p.c.slice(0, 2).join('، ') + ']' : ''} — ${p.u}`).join('\n'));
  return o.join('\n').slice(0, max);
}

/* ---------- related content for one article (same logic as the worker) ---------- */
const STOP = new Set(['و', 'در', 'به', 'از', 'که', 'با', 'را', 'این', 'برای', 'آن', 'یا', 'تا', 'هم', 'چه', 'چی', 'چگونه', 'چطور', 'های', 'ها', 'می', 'است', 'کنیم', 'کرد', 'بر', 'یک', 'هر', 'بهترین', 'راهنمای', 'کامل', 'the', 'a', 'an', 'of', 'to', 'in', 'for', 'and', 'how', 'is', 'on', 'with', 'best']);
const toks = (s) => String(s || '').toLowerCase().replace(/[‌]/g, ' ').replace(/[^\p{L}\p{N}\s]/gu, ' ').split(/\s+/).filter((w) => w.length > 1 && !STOP.has(w));
export const CTX_MARK = '## داده‌های واقعی سایت';
export function siteContext(idx, title, keywords, existing = '') {
  if (!idx || !Array.isArray(idx.posts)) return '';
  const q = new Set(toks(title + ' ' + (Array.isArray(keywords) ? keywords.join(' ') : keywords || '')));
  if (!q.size) return '';
  const sc = (text) => { const s = new Set(toks(text)); let n = 0; for (const w of s) if (q.has(w)) n++; return s.size ? n / Math.sqrt(s.size * q.size) : 0; };
  const tq = new Set(toks(title));
  const titleSim = (t) => { const s = new Set(toks(t)); let n = 0; for (const w of s) if (tq.has(w)) n++; return s.size && tq.size ? (2 * n) / (s.size + tq.size) : 0; };
  const posts = idx.posts.map((p) => ({ p, s: sc(`${p.t} ${p.t} ${(p.c || []).join(' ')} ${(p.tg || []).join(' ')}`) })).filter((x) => x.s > 0.08).sort((a, b) => b.s - a.s);
  const dups = idx.posts.filter((p) => titleSim(p.t) >= 0.7).slice(0, 3);
  const cats = (idx.categories || []).map((c) => ({ c, s: sc(c.n) })).filter((x) => x.s > 0 && x.c.u).sort((a, b) => b.s - a.s).slice(0, 3);
  const lines = [`\n${CTX_MARK} (نسخه پشتیبان GitHub، ${String(idx.crawled_at || '').slice(0, 10)})`];
  if (dups.length) lines.push('هشدار تکرار: این مقاله‌ها موضوع بسیار نزدیکی دارند. مقاله تکراری ننویس؛ زاویه و نیت جستجوی متفاوتی انتخاب کن و به آن‌ها لینک بده:\n' + dups.map((p) => `- ${p.t} => ${p.u}`).join('\n'));
  const rel = posts.filter((x) => !dups.includes(x.p)).slice(0, 6);
  if (rel.length) lines.push('### مقالات مرتبط موجود برای لینک داخلی (۲ تا ۴ لینک با انکر طبیعی و دقیقاً همین آدرس‌ها)\n' + rel.map((x) => `- ${x.p.t} => ${x.p.u}`).join('\n'));
  if (cats.length) lines.push('### دسته‌بندی‌های مرتبط\n' + cats.map((x) => `- ${x.c.n} => ${x.c.u}`).join('\n'));
  if (!/محصولات واقعی فروشگاه/.test(existing) && Array.isArray(idx.products)) {
    const pr = idx.products.map((p) => ({ p, s: sc(`${p.n} ${p.n} ${(p.c || []).join(' ')}`) + (p.s ? 0.01 : 0) })).filter((x) => x.s > 0.09).sort((a, b) => b.s - a.s).slice(0, 4);
    if (pr.length) lines.push('### محصولات مرتبط فروشگاه (با همین آدرس لینک بده؛ قیمت دیگری ننویس؛ برای ناموجود دعوت به خرید نکن)\n' + pr.map((x) => `- ${x.p.n} => ${x.p.u}${x.p.p ? ' | قیمت: ' + x.p.p : ''}${x.p.s ? '' : ' | ناموجود'}`).join('\n'));
  }
  if (lines.length === 1) return '';
  lines.push('هیچ آدرسی از خودت نساز؛ فقط از آدرس‌های بالا استفاده کن.');
  return lines.join('\n') + '\n';
}

/* ---------- git (private repo branch) ---------- */
function dataRemote() {
  if (E.AAW_DATA_REMOTE) return E.AAW_DATA_REMOTE;
  if (E.GITHUB_TOKEN && E.GITHUB_REPOSITORY) return `https://x-access-token:${E.GITHUB_TOKEN}@github.com/${E.GITHUB_REPOSITORY}.git`;
  return '';
}
const mask = (s) => String(s || '').replace(/x-access-token:[^@]+@/g, 'x-access-token:***@');
async function git(args, timeout = 300000) {
  const r = await run('git', args, '', { ...E, GIT_TERMINAL_PROMPT: '0' }, timeout);
  return { ...r, err: mask(r.err), out: mask(r.out) };
}

/** Read the database branch (index + SEO knowledge). Returns null when it does not exist yet. */
export async function loadData() {
  const remote = dataRemote();
  if (!remote || !workDir) return null;
  const dir = join(workDir, 'data-ro');
  const args = ['clone', '-q', '--depth', '1', '--branch', DATA_BRANCH, '--single-branch', '--no-checkout'];
  if (/^https:/.test(remote)) args.push('--filter=blob:none');
  const c = await git([...args, remote, dir]);
  if (c.code !== 0) return null;
  const show = async (p) => { const r = await git(['-C', dir, 'show', `HEAD:${p}`]); return r.code === 0 ? r.out : ''; };
  const out = { index: null, seo: null };
  try { out.index = JSON.parse(await show('site/index.json') || 'null'); } catch (_) { /* ignore */ }
  try { out.seo = JSON.parse(await show('seo/knowledge.json') || 'null'); } catch (_) { /* ignore */ }
  return out;
}

/** Replace one section (site/ or seo/) of the database branch; history is squashed (old data removed). */
export async function saveSection(section, files, message) {
  const remote = dataRemote();
  if (!remote) throw new Error('GITHUB_TOKEN در Workflow نیست؛ از تب اکانت اشتراکی «به‌روزرسانی فایل‌ها» را بزنید.');
  const dir = join(workDir, 'data-rw');
  await rm(dir, { recursive: true, force: true });
  let c = await git(['clone', '-q', '--depth', '1', '--branch', DATA_BRANCH, '--single-branch', remote, dir]);
  if (c.code !== 0) {
    c = await git(['init', '-q', dir]);
    if (c.code !== 0) throw new Error('git init: ' + c.err.slice(0, 300));
    await git(['-C', dir, 'remote', 'add', 'origin', remote]);
  }
  await rm(join(dir, section), { recursive: true, force: true });
  for (const [p, content] of Object.entries(files)) {
    const f = join(dir, p);
    await mkdir(dirname(f), { recursive: true });
    await writeFile(f, content, 'utf8');
  }
  await writeFile(join(dir, 'README.md'), '# ARIAGH.AIWRITER data\n\nدیتابیس هوش مصنوعی افزونه (ساخته‌شده خودکار):\n\n- `site/` نسخه پشتیبان متنی کامل سایت + `site/index.json` برای لینک‌دهی و جلوگیری از تکرار\n- `seo/` دانش به‌روز سئو\n\nهر بار به‌روزرسانی، اطلاعات قبلی همان بخش حذف و جایگزین می‌شود. این شاخه را دستی ویرایش نکنید.\n', 'utf8');
  await git(['-C', dir, 'checkout', '-q', '--orphan', 'aaw-tmp']);
  await git(['-C', dir, 'add', '-A']);
  c = await git(['-C', dir, '-c', 'user.name=ARIAGH.AIWRITER', '-c', 'user.email=bot@ariagh.local', 'commit', '-q', '-m', message]);
  if (c.code !== 0) throw new Error('git commit: ' + (c.err || c.out).slice(0, 300));
  c = await git(['-C', dir, 'push', '-q', '-f', 'origin', `HEAD:refs/heads/${DATA_BRANCH}`]);
  if (c.code !== 0) throw new Error('ذخیره در GitHub ناموفق بود (git push): ' + c.err.slice(0, 400) + (/403|denied|permission/i.test(c.err) ? ' — Workflow اجازه نوشتن ندارد؛ از تب اکانت اشتراکی «به‌روزرسانی فایل‌ها» را بزنید.' : ''));
  const files2 = Object.keys(files).length;
  log(`data branch updated: ${section}/ (${files2} files)`);
  return files2;
}

/* ---------- SEO knowledge ---------- */
export async function fetchSources(urls) {
  const notes = [];
  for (const u of (urls || []).filter((x) => /^https?:\/\//i.test(x)).slice(0, 14)) {
    const r = await getRaw(u, 'text/html,*/*');
    if (!r.ok) continue;
    const title = oneLine((r.text.match(/<title[^>]*>([\s\S]*?)<\/title>/i) || [])[1] || '', 140);
    const main = (r.text.match(/<(article|main)[^>]*>([\s\S]*?)<\/\1>/i) || [])[2] || r.text;
    const text = htmlToText(main).slice(0, 12000);
    if (text.length > 400) notes.push({ url: u, title: title || u, text });
  }
  return notes;
}
export function seoPrompt(p, notes) {
  const today = new Date().toISOString().slice(0, 10);
  return `امروز ${today} است. تو یک متخصص ارشد سئو هستی. از منابع زیر (راهنماهای رسمی گوگل، مقالات معتبر بین‌المللی و ایرانی)${p.web ? ' و جستجوی زنده وب برای آخرین به‌روزرسانی‌های الگوریتم گوگل' : ''} یک «دستورالعمل فشرده و عملی نوشتن مقاله سئو» برای سایت «${p.site || ''}» در حوزه «${p.niche || ''}» به زبان ${p.language === 'en' ? 'انگلیسی' : 'فارسی'} استخراج کن.
فقط نکاتی که در نوشتن و ساختار مقاله قابل اجراست؛ خلاصه، بدون تکرار، حداکثر ۹۰۰ کلمه. بخش‌ها: ۱) اصول محتوای مفید و E-E-A-T ۲) ساختار، عنوان، متا، سرفصل‌ها و طول ۳) کلمه کلیدی، نیت جستجو و موضوعات مرتبط (بدون Keyword stuffing) ۴) بهینه‌سازی برای AI Overviews و موتورهای پاسخ (GEO): پاسخ مستقیم، تعریف‌ها، جدول، فهرست ۵) اسکیما و FAQ ۶) لینک داخلی/خارجی و لینک به محصولات ۷) نکات خاص زبان فارسی و جستجوی کاربران ایرانی ۸) کارهای ممنوع طبق سیاست‌های اسپم گوگل ۹) چک‌لیست نهایی.
فقط JSON: {"playbook":"متن markdown","highlights":["۵ تغییر/نکته مهم اخیر"],"version":"${today}"}

منابع:
${notes.map((n) => `### ${n.title}\n${n.url}\n${n.text.slice(0, 5500)}`).join('\n\n').slice(0, 60000) || '(منبعی دریافت نشد؛ از دانش به‌روز خود استفاده کن و این را در highlights ذکر کن)'}`;
}
export function seoFiles(k, notes) {
  const f = {
    'seo/knowledge.json': JSON.stringify(k, null, 2),
    'seo/playbook.md': `# دستورالعمل سئو (نسخه ${k.version})\n\n${k.playbook}\n\n## نکات مهم اخیر\n${(k.highlights || []).map((h) => '- ' + h).join('\n')}\n`,
  };
  notes.forEach((n, i) => { f[`seo/sources/${String(i + 1).padStart(2, '0')}-${slugify(new URL(n.url).host)}.md`] = `# ${n.title}\n\n${n.url}\n\n${n.text}\n`; });
  return f;
}

/* ------------------------------ article pipeline ------------------------------ */
let siteData = null;
async function writeArticle(job, runtime) {
  const params = runtime.params || {};
  if (siteData && siteData.index && !String(job.prompts.user).includes(CTX_MARK)) {
    job.prompts.user += siteContext(siteData.index, job.topic.title, job.topic.keywords, job.prompts.system + job.prompts.user);
  }
  const sub = (runtime.providers || [])[0] || {};
  const model = MODEL || sub.model || '';
  const meta = { tokens_in: 0, tokens_out: 0, fallbacks: [], provider: sub.id || ('sub_' + ENGINE), model: model || (ENGINE === 'claude' ? 'sonnet' : 'default') };
  let user = job.prompts.user;
  if (params.two_pass && job.prompts.outline) {
    try {
      const o = await ask(job.prompts.system, job.prompts.outline, model);
      meta.tokens_in += o.usage.in; meta.tokens_out += o.usage.out;
      const outline = parseJSON(o.text);
      if (outline && (outline.outline || outline.title)) {
        user += '\n\nطرح کلی (Outline) مصوب که باید دقیقاً بر اساس آن بنویسی:\n' + JSON.stringify(outline);
        meta.outline = true;
      }
    } catch (e) {
      if (e.quota) throw e;
      meta.outline_error = e.message;
    }
  }
  const r = await ask(job.prompts.system, user, model);
  meta.tokens_in += r.usage.in; meta.tokens_out += r.usage.out;
  let article = parseJSON(r.text);
  if (!article || !article.content_html) {
    const fix = await ask('Convert the article into ONE valid JSON object with keys: title, seo_title, meta_description, slug, focus_keyword, secondary_keywords, tags, category, excerpt, content_html, faq, image_prompt, image_alt. Return JSON only.', r.text.slice(0, 60000), model);
    article = parseJSON(fix.text);
  }
  if (!article || !article.content_html) {
    if (!/<(p|h2|ul)\b/i.test(r.text)) throw new Error('خروجی مدل قابل تبدیل به مقاله نبود.');
    article = { title: job.topic.title, content_html: r.text };
  }
  return { article, meta };
}

async function fallbackViaWorker(job, runtime, reason) {
  const others = (runtime.providers || []).filter((p) => p.format !== 'github_runner');
  if (!others.length || !WORKER) throw reason;
  log('Subscription failed, using fallback providers on Cloudflare:', others.map((p) => p.id).join(', '));
  const r = await worker('generate', { runtime: { ...runtime, providers: others, image: { enabled: false } }, job }, 600000);
  r.meta = r.meta || {};
  r.meta.fallbacks = [{ provider: 'sub_' + ENGINE, error: String(reason.message).slice(0, 300) }].concat(r.meta.fallbacks || []);
  return { article: r.article, meta: r.meta };
}

async function processJob(job, runtime) {
  const t0 = Date.now();
  let out;
  try {
    out = await writeArticle(job, runtime);
  } catch (e) {
    out = await fallbackViaWorker(job, runtime, e);
  }
  const { article, meta } = out;
  if (runtime.image && runtime.image.enabled && WORKER) {
    try {
      const img = await worker('image', { cfg: runtime.image, article, refs: job.images || {} }, 600000);
      if (img && img.image) {
        if (img.image.inline) { article.inline_images = img.image.inline; delete img.image.inline; }
        article.image = img.image;
      }
    } catch (e) { meta.image_error = e.message; }
  }
  meta.duration_ms = Date.now() - t0;
  meta.runner = 'github';
  return reportDraft(job.topic.id, article, meta);
}


/* ------------------------------ ChatGPT sign-in with a one-time code (no install on the user's PC) ------------------------------
 * Runs the official `codex login --device-auth` here on GitHub. The URL + one-time code are shown in
 * WordPress; the user opens the link, signs in with the ChatGPT account and types the code. The
 * resulting ChatGPT credentials are stored in the user's own Cloudflare KV (never in WordPress).
 */
const stripAnsi = (s) => String(s || '').replace(/\x1b\[[0-9;?]*[A-Za-z]/g, '').replace(/\x1b\][^\x07]*\x07/g, '');
export function parseDeviceCode(text) {
  const t = stripAnsi(text);
  const url = (t.match(/https:\/\/[^\s"'<>]*(?:device|activate|codex)[^\s"'<>]*/i) || t.match(/https:\/\/auth\.openai\.com[^\s"'<>]*/i) || [])[0] || '';
  const code = (t.match(/\b([A-Z0-9]{4,5}-[A-Z0-9]{4,5})\b/) || [])[1] || '';
  return url && code ? { url: url.replace(/[).,]+$/, ''), code } : null;
}
async function loginStatus(data) {
  try { await worker('login_status', { id: PULL_ID, action: 'put', data }, 30000); } catch (e) { log('login status update failed:', e.message); }
}
async function deviceLogin() {
  if (!PULL_ID) throw new Error('login requires a job id');
  workDir = await mkdtemp(join(tmpdir(), 'aria-'));
  codexHome = join(workDir, 'codex');
  await mkdir(codexHome, { recursive: true });
  await writeFile(join(codexHome, 'config.toml'), 'cli_auth_credentials_store = "file"\n');
  await loginStatus({ state: 'starting', at: Date.now() });
  const env = { ...E, CODEX_HOME: codexHome, NO_COLOR: '1', TERM: 'dumb' };
  delete env.OPENAI_API_KEY; delete env.CODEX_API_KEY;
  const child = spawn(CODEX_BIN, ['login', '--device-auth'], { env, stdio: ['pipe', 'pipe', 'pipe'] });
  let out = ''; let sent = false;
  const onData = async (d) => {
    out += d;
    if (!sent) {
      const dc = parseDeviceCode(out);
      if (dc) { sent = true; log('device code ready'); await loginStatus({ state: 'code', url: dc.url, code: dc.code, at: Date.now() }); }
    }
  };
  child.stdout.on('data', onData); child.stderr.on('data', onData);
  const limit = Math.min(Math.max(Number(E.AAW_LOGIN_TIMEOUT || 900), 30), 1800) * 1000;
  const code = await new Promise((resolve) => {
    const timer = setTimeout(() => { child.kill('SIGKILL'); resolve(-2); }, limit);
    child.on('error', (e) => { clearTimeout(timer); out += '\n' + e.message; resolve(-1); });
    child.on('close', (c) => { clearTimeout(timer); resolve(c); });
  });
  const authFile = join(codexHome, 'auth.json');
  if (code === 0 && existsSync(authFile)) {
    const auth = await readFile(authFile, 'utf8');
    let j = null; try { j = JSON.parse(auth); } catch (_) { /* invalid */ }
    if (!j || !j.tokens) throw new Error('ورود انجام شد ولی اعتبارنامه ChatGPT ذخیره نشد.');
    await worker('codex_auth', { action: 'put', data: auth }, 30000);
    await loginStatus({ state: 'done', at: Date.now(), plan: (j.tokens && j.tokens.id_token) ? 'chatgpt' : '' });
    summary('### اکانت ChatGPT با کد تأیید وصل شد');
    return;
  }
  const tail = stripAnsi(out).trim().split('\n').slice(-6).join(' | ').slice(0, 600);
  let reason = 'ورود ChatGPT کامل نشد.';
  if (code === -2) reason = 'مهلت واردکردن کد تمام شد (کد را در زمان نمایش وارد نکردید).';
  else if (/device code|not enabled|admin|disabled|enable/i.test(tail)) reason = 'ورود با کد در حساب ChatGPT شما فعال نیست. در ChatGPT → Settings → Security گزینه «Allow device code login» را روشن کنید (در حساب‌های تیمی، مدیر Workspace باید آن را فعال کند) و دوباره «ورود با اکانت ChatGPT» را بزنید.';
  else if (/denied|cancel/i.test(tail)) reason = 'ورود در صفحه ChatGPT لغو یا رد شد.';
  await loginStatus({ state: 'error', error: reason, detail: tail, at: Date.now() });
  throw new Error(reason + (tail ? ' — ' + tail : ''));
}

/* ------------------------------ main ------------------------------ */
async function main() {
  if (!SECRET || (!WP_API && !PULL_ID)) throw new Error('AAW_WP_API / AAW_SHARED_SECRET secrets are missing. Reconnect GitHub from the plugin.');
  if (PULL_ID && !WORKER) throw new Error('AAW_WORKER_URL secret is missing (required for local-site mode).');
  if (TRIGGER === 'codex_login') { await deviceLogin(); return; }
  await prepare();
  log(`engine=${ENGINE} trigger=${TRIGGER} topic=${TOPIC_ID || '-'}`);

  if (TRIGGER === 'test') {
    const t0 = Date.now();
    const r = await ask('You are a connectivity test.', 'Reply with exactly: OK', MODEL);
    const msg = `اتصال اکانت اشتراکی (${ENGINE === 'claude' ? 'Claude' : 'ChatGPT'}) از GitHub موفق بود: «${r.text.trim().slice(0, 60)}» در ${Math.round((Date.now() - t0) / 1000)} ثانیه`;
    await reportLog('success', 'test', msg);
    summary('### ' + msg);
    return;
  }

  if (TRIGGER === 'crawl') {
    if (!PULL_ID) throw new Error('crawl requires a job id');
    const q = await worker('queue_get', { id: PULL_ID }, 30000);
    const t = q.payload || {};
    const t0 = Date.now();
    const d = await crawlSite(t.site, log);
    const { files, index } = backupFiles(d);
    await saveSection('site', files, `site backup ${d.crawled_at}`);
    let kv = false;
    try { await worker('site_put', { index }, 120000); kv = true; } catch (e) { log('site index → Cloudflare skipped:', e.message); }
    mailbox.site_data = { crawled_at: d.crawled_at, source: d.source, counts: index.counts, notes: d.notes, branch: DATA_BRANCH, files: Object.keys(files).length, kv, ms: Date.now() - t0 };
    summary(`### نسخه پشتیبان سایت در GitHub ذخیره شد: ${index.counts.posts} مقاله، ${index.counts.products} محصول، ${index.counts.pages} برگه، ${index.counts.categories} دسته (${d.source === 'rest' ? 'REST API' : 'نقشه سایت'})`);
    if (t.analyze) {
      const a = t.analyze;
      const prompt = String(a.prompt || '') + '\n\n' + inventoryFromIndex(index);
      const stats = { ...(a.stats || {}), posts: index.counts.posts + (a.stats && a.stats.drafts || 0), products: index.counts.products, pages: index.counts.pages, categories: index.counts.categories, source: 'github' };
      if (ENGINE !== 'none') {
        let fetched = 0; let p2 = prompt;
        if (Array.isArray(a.competitors) && a.competitors.length) {
          try { const c = await worker('competitors', { urls: a.competitors }, 120000); fetched = c.fetched || 0; if (c.text) p2 += '\n\n# داده‌های واقعی جمع‌آوری‌شده از سایت رقبا' + c.text; } catch (e) { log('competitor fetch failed:', e.message); }
        }
        const r = await ask(a.system || '', p2, MODEL, { web: ENGINE === 'claude' });
        let analysis = parseJSON(r.text);
        if (!analysis || !analysis.topics) {
          const fix = await ask('Return the same content as ONE valid JSON object with keys summary, audience, business_type, pillars, gaps, competitors, trends, seo_notes, topics. JSON only.', r.text.slice(0, 60000), MODEL);
          analysis = parseJSON(fix.text);
        }
        if (!analysis || !analysis.topics) throw new Error('خروجی تحلیل قابل پردازش نبود.');
        mailbox.analysis = { result: analysis, meta: { provider: 'sub_' + ENGINE, model: MODEL || (ENGINE === 'claude' ? 'sonnet' : 'default'), web: ENGINE === 'claude', competitors_fetched: fetched, runner: 'github', ms: Date.now() - t0, stats } };
      } else {
        const r = await worker('analyze', { runtime: t.runtime || {}, system: a.system || '', prompt, competitors: a.competitors || [], web: !!a.web }, 900000);
        mailbox.analysis = { result: r.analysis, meta: { ...(r.meta || {}), runner: 'github+cloudflare', ms: Date.now() - t0, stats } };
      }
      summary(`### تحلیل هوشمند سایت انجام شد: ${mailbox.analysis.result.topics.length} موضوع پیشنهادی`);
    }
    return;
  }

  if (TRIGGER === 'seo_learn') {
    if (!PULL_ID) throw new Error('seo_learn requires a job id');
    const q = await worker('queue_get', { id: PULL_ID }, 30000);
    const p = q.payload || {};
    const notes = await fetchSources(p.sources || []);
    const r = await askAny('You are a senior SEO strategist. Return JSON only.', seoPrompt(p, notes), p.runtime, { max_tokens: 4000, temperature: 0.3 }, { web: ENGINE === 'claude' && !!p.web });
    let data = parseJSON(r.text) || {};
    let playbook = String(data.playbook || '').trim();
    if (playbook.length < 300 && r.text && r.text.length > 300 && !/^\s*\{/.test(r.text)) playbook = r.text.trim();
    if (playbook.length < 300) throw new Error('دستورالعمل سئو ساخته نشد (خروجی کوتاه بود).');
    const k = { playbook: playbook.slice(0, 9000), highlights: (data.highlights || []).slice(0, 8), version: data.version || new Date().toISOString().slice(0, 10), sources: notes.map((n) => n.url), engine: r.provider || ('sub_' + ENGINE), model: r.model || MODEL || '', at: new Date().toISOString() };
    await saveSection('seo', seoFiles(k, notes), `seo knowledge ${k.version}`);
    mailbox.seo_playbook = { ...k, store: 'github' };
    summary(`### دانش سئو ساخته و در GitHub ذخیره شد (${notes.length} منبع)`);
    return;
  }

  // Article / analysis jobs read the site database (links to products, categories and old articles; no duplicates).
  try { siteData = await loadData(); if (siteData && siteData.index) log(`site data: ${siteData.index.counts.posts} posts, ${siteData.index.counts.products} products (${siteData.index.crawled_at})`); } catch (e) { log('site data not loaded:', e.message); }

  if (TRIGGER === 'image') {
    if (!PULL_ID) throw new Error('image requires a job id');
    const q = await worker('queue_get', { id: PULL_ID }, 30000);
    const t = q.payload || {};
    const refs = await refsToFiles([...(t.refs && t.refs.products || []), ...(t.refs && t.refs.styles || [])]);
    const image = await codexImage(t.prompt || 'editorial product photo', refs, 'featured.png');
    image.refs_used = refs.length;
    const inline = [];
    for (const [k, it] of (t.inline || []).entries()) {
      try { inline.push({ ...(await codexImage(it.prompt, refs, `inline-${k}.png`)), alt: it.alt || '', after_h2: it.after_h2 || 0 }); } catch (e) { if (e.quota) break; }
    }
    mailbox.images.push({ post_id: t.post_id || 0, topic_id: t.topic_id || 0, image, inline, prompt: t.prompt || '', replace_inline: !!t.replace_inline });
    summary(`### تصویر با اشتراک ChatGPT ساخته شد (${refs.length} مرجع${inline.length ? '، ' + inline.length + ' تصویر داخل متن' : ''})`);
    return;
  }

  if (TRIGGER === 'revise') {
    if (!PULL_ID) throw new Error('revise requires a job id');
    const q = await worker('queue_get', { id: PULL_ID }, 30000);
    const t = q.payload || {};
    const r = await ask(t.system || '', t.prompt || '', MODEL);
    let article = parseJSON(r.text);
    if (!article || !article.content_html) {
      const fix = await ask('Convert into ONE valid JSON object with keys: title, seo_title, meta_description, focus_keyword, excerpt, content_html, faq. JSON only.', r.text.slice(0, 60000), MODEL);
      article = parseJSON(fix.text);
    }
    if (!article || !article.content_html) throw new Error('خروجی اصلاح قابل پردازش نبود.');
    article._meta = { provider: 'sub_' + ENGINE, usage: r.usage };
    mailbox.revisions.push({ post_id: t.post_id || 0, article, request_id: t.request_id || '' });
    summary('### اصلاحات روی مقاله اعمال شد: ' + (article.title || ''));
    return;
  }

  if (TRIGGER === 'analyze') {
    if (!PULL_ID) throw new Error('analyze requires a job id');
    const q = await worker('queue_get', { id: PULL_ID }, 30000);
    const a = q.payload || {};
    let prompt = a.prompt || '';
    let fetched = 0;
    if (Array.isArray(a.competitors) && a.competitors.length) {
      try {
        const c = await worker('competitors', { urls: a.competitors }, 120000);
        fetched = c.fetched || 0;
        if (c.text) prompt += '\n\n# داده‌های واقعی جمع‌آوری‌شده از سایت رقبا' + c.text;
      } catch (e) { log('competitor fetch failed:', e.message); }
    }
    const t0 = Date.now();
    const r = await ask(a.system || '', prompt, MODEL, { web: ENGINE === 'claude' });
    let analysis = parseJSON(r.text);
    if (!analysis || !analysis.topics) {
      const fix = await ask('Return the same content as ONE valid JSON object with keys summary, audience, business_type, pillars, gaps, competitors, trends, seo_notes, topics. JSON only.', r.text.slice(0, 60000), MODEL);
      analysis = parseJSON(fix.text);
    }
    if (!analysis || !analysis.topics) throw new Error('خروجی تحلیل قابل پردازش نبود.');
    mailbox.analysis = { result: analysis, meta: { provider: 'sub_' + ENGINE, model: MODEL || (ENGINE === 'claude' ? 'sonnet' : 'default'), web: ENGINE === 'claude', competitors_fetched: fetched, runner: 'github', ms: Date.now() - t0, stats: a.stats || {} } };
    summary(`### تحلیل هوشمند سایت انجام شد: ${analysis.topics.length} موضوع پیشنهادی`);
    return;
  }

  let job;
  if (PULL_ID) {
    const q = await worker('queue_get', { id: PULL_ID }, 30000);
    job = q.payload || {};
  } else {
    job = await wp('job', { trigger: 'runner', origin: TRIGGER, topic_id: TOPIC_ID });
  }
  if (job.status === 'need_topics' && job.ideas) {
    const r = await ask('You are an SEO content strategist. Return JSON only.', job.ideas.prompt, MODEL);
    const data = parseJSON(r.text);
    const ideas = ((data && (data.topics || data.ideas)) || []).map((t) => (typeof t === 'string' ? { title: t } : t)).filter((t) => t && t.title);
    if (PULL_ID) {
      mailbox.ideas = ideas;
      summary(`- ${ideas.length} ایده موضوع برای سایت لوکال آماده شد`);
      return;
    }
    if (ideas.length) {
      await wp('topics', { topics: ideas });
      summary(`- ${ideas.length} ایده موضوع جدید اضافه شد`);
    }
    job = await wp('job', { trigger: 'runner', origin: TRIGGER, no_ideas: true });
  }
  if (job.status !== 'ok') {
    log('Nothing to do:', job.status, job.reason || '');
    summary(`- کاری برای انجام نبود: ${job.reason || job.status}`);
    return;
  }
  let okCount = 0;
  for (const j of job.jobs) {
    try {
      const res = await processJob(j, job.runtime);
      okCount++;
      log('Draft created:', res.post_id, res.title);
      summary(`- پیش‌نویس ساخته شد: **${res.title}** ([ویرایش](${res.edit_url}))`);
    } catch (e) {
      log('Failed:', j.topic.title, e.message);
      summary(`- خطا در «${j.topic.title}»: ${e.message}`);
      try { await reportFail(j.topic.id, e.message, { provider: 'sub_' + ENGINE, runner: 'github' }); } catch (_) { /* ignore */ }
    }
  }
  if (!okCount && job.jobs.length) process.exitCode = 1;
}

const isMain = import.meta.url === `file://${process.argv[1]}`;
if (isMain) {
  main()
    .catch(async (e) => {
      console.error('[aria] ERROR:', e.message);
      summary('### خطا: ' + e.message);
      mailbox.fatal = e.message;
      try { await reportLog('error', 'run', 'GitHub Runner: ' + e.message); } catch (_) { /* ignore */ }
      process.exitCode = 1;
    })
    .finally(async () => {
      await persistCodexAuth();
      try { await flushMailbox(); } catch (e) { console.error('[aria] could not store result:', e.message); process.exitCode = 1; }
      if (E.GITHUB_STEP_SUMMARY && summaryLines.length) {
        try { await appendFile(E.GITHUB_STEP_SUMMARY, '## ARIAGH.AIWRITER\n' + summaryLines.join('\n') + '\n'); } catch (_) { /* ignore */ }
      }
      if (workDir) await rm(workDir, { recursive: true, force: true }).catch(() => {});
    });
}
