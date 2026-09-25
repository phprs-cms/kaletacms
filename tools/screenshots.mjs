// Kaleta – screenshots of one install (called by tools/screenshots.sh for each starter site).
// Env: BASE (http://127.0.0.1:8097), SITE (firemni | remeslo | poradenstvi), PASSWORD (admin), OUT (folder), CHROME (browser binary),
// NODE_PATH (folder with playwright-core).
import { createRequire } from 'node:module';
import { createHash, randomBytes } from 'node:crypto';

const require = createRequire(`${process.env.NODE_PATH}/`);
const { chromium } = require('playwright-core');
const { BASE, SITE, PASSWORD, OUT, CHROME } = process.env;
const DESKTOP = { width: 1440, height: 900 };
const PHONE = { width: 390, height: 844 };

const browser = await chromium.launch({ executablePath: CHROME, headless: true });
const context = await browser.newContext({ viewport: DESKTOP, deviceScaleFactor: 2, colorScheme: 'light', locale: 'en-GB' });
// the builder's first-run tour would cover the canvas
await context.addInitScript(() => { try { localStorage.setItem('ka-st-prohlidka', '1'); } catch (e) { /* ignore */ } });
const page = await context.newPage();
const canvas = () => page.frameLocator('.st-platno iframe').first();

async function shot(name, url, { viewport = DESKTOP, dark = false, full = false, before = null } = {}) {
  await page.setViewportSize(viewport);
  await page.emulateMedia({ colorScheme: dark ? 'dark' : 'light', reducedMotion: 'reduce' });
  if (url) await page.goto(BASE + url, { waitUntil: 'networkidle' });
  if (before) await before();
  await page.evaluate(() => document.fonts.ready);
  await page.waitForTimeout(400);
  await page.screenshot({ path: `${OUT}/${name}.png`, fullPage: full });
  console.log('  ' + name);
}

// the public site of every starter
await shot(`site-${SITE}`, '/');
await shot(`site-${SITE}-phone`, '/', { viewport: PHONE });

if (SITE === 'firemni') {
  await shot('site-firemni-full', '/', { full: true });

  // admin: sign in with the throwaway account of this install
  await page.goto(`${BASE}/admin.php`);
  await page.fill('input[name="user"]', 'admin');
  await page.fill('input[name="password"]', PASSWORD);
  await Promise.all([page.waitForNavigation(), page.press('input[name="password"]', 'Enter')]);

  await shot('admin-dashboard', '/admin.php');
  await shot('admin-dashboard-dark', '/admin.php', { dark: true });
  await shot('admin-pages', '/admin.php?modul=stranky');
  // builder: the hero heading selected, its content on the right; then its style on a phone
  await shot('admin-builder', '/admin.php?modul=stranky&akce=stavitel&id=1', {
    before: async () => { await page.waitForTimeout(1500); await canvas().locator('h1').first().click(); await page.waitForTimeout(600); },
  });
  await shot('admin-builder-dark', null, { dark: true });
  await shot('admin-builder-style', null, {
    before: async () => {
      await page.getByRole('button', { name: 'Mobile' }).first().click();
      await page.waitForTimeout(1200);
      await canvas().locator('h1').first().click();
      await page.getByRole('tab', { name: 'Style' }).first().click().catch(() => page.getByText('Style', { exact: true }).first().click());
      await page.waitForTimeout(600);
    },
  });
  await shot('admin-site-parts', '/admin.php?modul=casti&akce=stavitel&typ=hlavicka&jazyk=', { before: async () => page.waitForTimeout(1500) });
  await shot('admin-appearance', '/admin.php?modul=vzhled');
  await shot('admin-enquiries', '/admin.php?modul=poptavky');
  await shot('admin-extensions', '/admin.php?modul=rozsireni');

  // Claude connector: the consent screen a site owner sees when connecting Claude (OAuth dynamic registration + PKCE)
  const client = await (await fetch(`${BASE}/oauth/register`, {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ client_name: 'Claude', redirect_uris: ['https://claude.ai/api/mcp/auth_callback'], token_endpoint_auth_method: 'none' }),
  })).json();
  const challenge = createHash('sha256').update(randomBytes(32).toString('base64url')).digest('base64url');
  const q = new URLSearchParams({ response_type: 'code', client_id: client.client_id, redirect_uri: 'https://claude.ai/api/mcp/auth_callback',
    code_challenge: challenge, code_challenge_method: 'S256', state: 'screens', scope: 'mcp' });
  await shot('admin-claude-consent', `/oauth/authorize?${q}`);
}

await browser.close();
