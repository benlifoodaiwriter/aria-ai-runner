#!/usr/bin/env node
/**
 * Aria AI Writer – Subscription Runner (GitHub Actions)
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
import { mkdtemp, writeFile, readFile, rm, mkdir, appendFile } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const E = process.env;
const WP_API = (E.AAW_WP_API || '').replace(/\/+$/, '');
const WORKER = (E.AAW_WORKER_URL || '').replace(/\/+$/, '');
const SECRET = E.AAW_SHARED_SECRET || '';
const TRIGGER = E.AAW_TRIGGER || 'cron';
const TOPIC_ID = Number(E.AAW_TOPIC_ID || 0);
const ENGINE = (E.AAW_ENGINE || 'claude').toLowerCase() === 'codex' ? 'codex' : 'claude';
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
const mailbox = { results: [], logs: [], ideas: null, fatal: '', analysis: null };
async function reportDraft(topicId, article, meta) {
  if (PULL_ID) { mailbox.results.push({ topic_id: topicId, article, meta }); return { post_id: 0, title: article.title, edit_url: '(local site will collect it)' }; }
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
async function flushMailbox() {
  if (!PULL_ID) return;
  await worker('result_put', { id: PULL_ID, data: mailbox }, 60000);
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

const ask = (system, prompt, model, opts) => (ENGINE === 'codex' ? askCodex(system, prompt, model) : askClaude(system, prompt, model, opts));

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

/* ------------------------------ article pipeline ------------------------------ */
async function writeArticle(job, runtime) {
  const params = runtime.params || {};
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
      const img = await worker('image', { cfg: runtime.image, article }, 180000);
      if (img && img.image) article.image = img.image;
    } catch (e) { meta.image_error = e.message; }
  }
  meta.duration_ms = Date.now() - t0;
  meta.runner = 'github';
  return reportDraft(job.topic.id, article, meta);
}

/* ------------------------------ main ------------------------------ */
async function main() {
  if (!SECRET || (!WP_API && !PULL_ID)) throw new Error('AAW_WP_API / AAW_SHARED_SECRET secrets are missing. Reconnect GitHub from the plugin.');
  if (PULL_ID && !WORKER) throw new Error('AAW_WORKER_URL secret is missing (required for local-site mode).');
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
        try { await appendFile(E.GITHUB_STEP_SUMMARY, '## Aria AI Writer\n' + summaryLines.join('\n') + '\n'); } catch (_) { /* ignore */ }
      }
      if (workDir) await rm(workDir, { recursive: true, force: true }).catch(() => {});
    });
}
