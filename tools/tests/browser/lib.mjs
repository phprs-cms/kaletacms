// Kaleta – browser test library (3.49 P1): the browser, the page, step(), proof(), visit(), sql() and the helpers the step
// files in this folder share. tools/test-browser.mjs imports it once and hands it to every step file as t; what changes
// during the run (the step's name, the counters, values one step leaves for a later one) lives in t.state, never in a
// module variable of a step file.
import { createRequire } from 'node:module';
import { createHash } from 'node:crypto';
import { execFileSync } from 'node:child_process';
import { readFileSync, writeFileSync, existsSync, appendFileSync } from 'node:fs';

export const require = createRequire(`${process.env.NODE_PATH}/`);
const { chromium } = require('playwright-core');
export const { BASE, PASSWORD, CHROME, SHOTS, DB_NAME } = process.env;
// STEPS_FILE=<path>: every step's name and the number of proof() calls it made, one per line (3.49 P1: the list before and
// after a change of the layout must be equal)
const { STEPS_FILE } = process.env;
export const MYSQL = JSON.parse(process.env.MYSQL_JSON || '[]');
export const sql = (query) => execFileSync(MYSQL[0], [...MYSQL.slice(1), DB_NAME, '-N', '-e', query], { encoding: 'utf8' });

export const browser = await chromium.launch({ executablePath: CHROME, headless: true });
export const context = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'en-GB' });
await context.addInitScript(() => { try { localStorage.setItem('ka-st-prohlidka', '1'); } catch (e) { /* ignore */ } });
export const page = await context.newPage();
export const errors = [];
// where = the step that runs (for the error lines), steps = how many ran; newsItem and equipment are found by one step and
// used by a later one
export const state = { where: '', inertHandlers: false, steps: 0, newsItem: '', equipment: 0 };
// Chrome 156 reports the Content Security Policy for an on* attribute as soon as it is parsed, also in an inert document that
// never runs it; the paste steps parse such payloads on purpose and check themselves that nothing ran (state.inertHandlers)
export const watch = (p) => {
  p.on('pageerror', (e) => errors.push(`${state.where}: ${e.message}`));
  p.on('console', (m) => {
    if (m.type() !== 'error' || /Failed to load resource/.test(m.text())) { return; }
    if (state.inertHandlers && /^Executing inline event handler violates the following Content Security Policy directive/.test(m.text())) { return; }
    errors.push(`${state.where}: ${m.text()}`);
  });
};
watch(page);
page.on('frameattached', () => {}); // builder canvases are iframes of the same page: their errors arrive through the page
export const canvas = () => page.frameLocator('.st-platno iframe').first();
// T4 (Q4): conditions instead of fixed waits in the builder. Ready = the canvas shows the page (no preview still loading);
// saved = also every change is saved as the draft – a change sets "Unsaved…" at once, so "Draft saved" (or "Saved, but
// with warnings") with no canvas loading means the server has it and the canvas was drawn from it
export const builderCanvasReady = (needSaved) => {
  const frames = [...document.querySelectorAll('.st-platno iframe')];
  const f = frames[frames.length - 1];
  const state = document.querySelector('.st-lista .st-stav') || document.querySelector('.st-stav');
  return !!f && !document.querySelector('.st-platno iframe.st-nacita') && f.contentWindow?.location.href !== 'about:blank' && f.contentDocument?.readyState === 'complete'
    && (!needSaved || (!!state && /^(Draft saved|Saved, but with warnings)/.test(state.textContent.trim())));
};
export const builderReady = (timeout = 15000) => page.waitForFunction(builderCanvasReady, false, { timeout });
export const builderSaved = (timeout = 20000) => page.waitForFunction(builderCanvasReady, true, { timeout });
// 3.46 (Admin\FreshProof, N127): every POST of an administrator's section needs a proof of who is at the keyboard within 10
// minutes, and the walk takes longer. Before every step the signed-in person confirms, as they would type the password on
// "Confirm it is you": proof() moves verified_at of the page's session to now; proof(true) pushes it back (the step of 3.46)
const SESSION_DIR = execFileSync('php', ['-r', 'echo session_save_path() ?: sys_get_temp_dir();'], { encoding: 'utf8' });
let proofCalls = 0;
export async function proof(aged = false) {
  proofCalls++;
  const cookie = (await context.cookies(BASE)).find((c) => c.name === 'kaleta');
  const file = cookie ? `${SESSION_DIR}/sess_${cookie.value}` : '';
  if (!file || !existsSync(file)) { return; }
  const data = readFileSync(file, 'utf8');
  if (data.includes('verified_at|i:')) { writeFileSync(file, data.replace(/verified_at\|i:\d+;/, `verified_at|i:${aged ? 1 : Math.floor(Date.now() / 1000)};`)); }
}

export async function step(name, fn) {
  state.where = name;
  const proofsBefore = proofCalls;
  const before = errors.length;
  try {
    await proof();
    await fn();
    await page.waitForTimeout(300);
  } catch (e) {
    errors.push(`${name}: ${e.message.split('\n')[0]}`);
    if (SHOTS) { await page.screenshot({ path: `${SHOTS}/failed-${name.replace(/[^a-z0-9]+/gi, '-').slice(0, 60)}.png` }).catch(() => {}); } // 3.19.2: what the failing step saw
  }
  state.steps++;
  if (STEPS_FILE) { appendFileSync(STEPS_FILE, `${name}\t${proofCalls - proofsBefore}\n`); }
  console.log(`  ${errors.length === before ? 'ok   ' : 'CHYBA'}  ${name}`);
}
export const visit = (url) => page.goto(BASE + url, { waitUntil: 'networkidle' });

// the builder (3.21): the live canvas (never the one still loading), the draft saved and drawn, the element menu open
export const liveCanvas = () => page.frameLocator('.st-platno iframe:not(.st-nacita)').first();
// the draft is saved and the canvas redrawn: nothing queued, nothing loading
export const builderIdle = async () => {
  await page.waitForFunction(() => {
    const status = ((document.querySelector('.st-stav') || {}).textContent || '').trim();
    return !document.querySelector('.st-platno iframe.st-nacita') && (status === '' || /^(Draft saved|Saved, but with warnings|Published|Draft in progress)/.test(status));
  }, null, { timeout: 20000 });
  await page.waitForTimeout(200);
};
export const menuOpen = () => page.evaluate(() => !!document.querySelector('#st-kontext:popover-open'));

// accessibility (3.5): axe-core from the local copy next to playwright-core (never a CDN), the WCAG 2.0–2.2 A and AA rules
export const AXE = require.resolve('axe-core/axe.min.js');
export const WCAG = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];
export const axeViolations = async (p, label) => {
  await p.addScriptTag({ path: AXE });
  const result = await p.evaluate((tags) => window.axe.run(document, { runOnly: { type: 'tag', values: tags }, resultTypes: ['violations'] }), WCAG);
  return result.violations.map((v) => `${label} ${v.id} (${v.impact}, ${v.nodes.length}×): ${v.nodes[0].target.join(' ')}`);
};
// the three starters (test-browser.sh): firemni on BASE, remeslo and poradenstvi on the next two ports
export const STARTER_SITES = Object.fromEntries((process.env.STARTERS || `firemni=${BASE}`).split(',').map((s) => s.split('=')));

// the local SMTP sink of tools/test-browser.sh (3.23.1, item 12): the newest message to an address and its decoded parts
export const { SMTP_DIR, SMTP_PORT, WEB_DIR } = process.env;
export const fs = await import('node:fs');
export const latestMail = (to) => {
  const files = fs.readdirSync(SMTP_DIR).filter((f) => f.endsWith('.eml')).sort((a, b) => parseInt(a, 10) - parseInt(b, 10));
  for (const f of files.reverse()) { const raw = fs.readFileSync(`${SMTP_DIR}/${f}`, 'utf8'); if (raw.startsWith(`X-Rcpt-To: ${to}`)) { return raw; } }
  return null;
};
// the decoded parts of a message: headers, then every base64 or quoted-printable part as text
export const decodeMail = (raw) => {
  const [head, body] = raw.split('\r\n\r\n', 2).length === 2 ? [raw.slice(0, raw.indexOf('\r\n\r\n')), raw.slice(raw.indexOf('\r\n\r\n') + 4)] : [raw, ''];
  const parts = [...body.matchAll(/base64\r\n\r\n([A-Za-z0-9+/=\r\n]+)/g)].map((m) => Buffer.from(m[1].replace(/\s+/g, ''), 'base64').toString('utf8'));
  if (!parts.length && /^Content-Transfer-Encoding: base64/mi.test(head)) { parts.push(Buffer.from(body.replace(/\s+/g, ''), 'base64').toString('utf8')); }
  return { head, text: parts.length ? parts.join('\n') : body };
};
export { createHash, execFileSync, readFileSync, writeFileSync, existsSync };
