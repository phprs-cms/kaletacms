// Kaleta – browser test (called by tools/test-browser.sh): walks the admin, the builder, the news editor, the menu
// editor and the public site in Chrome and fails on any uncaught script error or console error.
// Env: BASE, PASSWORD (admin), CHROME (browser binary), NODE_PATH (folder with playwright-core), SHOTS (optional folder
// for screenshots of new screens, to look at them), MYSQL_JSON and DB_NAME (the mysql command as a JSON list and the
// database – 3.18: a step changes the state of the Claude connection).
import { createRequire } from 'node:module';
import { createHash } from 'node:crypto';
import { execFileSync } from 'node:child_process';

const require = createRequire(`${process.env.NODE_PATH}/`);
const { chromium } = require('playwright-core');
const { BASE, PASSWORD, CHROME, SHOTS, DB_NAME } = process.env;
const MYSQL = JSON.parse(process.env.MYSQL_JSON || '[]');
const sql = (query) => execFileSync(MYSQL[0], [...MYSQL.slice(1), DB_NAME, '-N', '-e', query], { encoding: 'utf8' });

const browser = await chromium.launch({ executablePath: CHROME, headless: true });
const context = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'en-GB' });
await context.addInitScript(() => { try { localStorage.setItem('ka-st-prohlidka', '1'); } catch (e) { /* ignore */ } });
const page = await context.newPage();
const errors = [];
let where = '';
// Chrome 156 reports the Content Security Policy for an on* attribute as soon as it is parsed, also in an inert document that
// never runs it; the paste steps parse such payloads on purpose and check themselves that nothing ran
let inertHandlers = false;
const watch = (p) => {
  p.on('pageerror', (e) => errors.push(`${where}: ${e.message}`));
  p.on('console', (m) => {
    if (m.type() !== 'error' || /Failed to load resource/.test(m.text())) { return; }
    if (inertHandlers && /^Executing inline event handler violates the following Content Security Policy directive/.test(m.text())) { return; }
    errors.push(`${where}: ${m.text()}`);
  });
};
watch(page);
page.on('frameattached', () => {}); // builder canvases are iframes of the same page: their errors arrive through the page
const canvas = () => page.frameLocator('.st-platno iframe').first();
let steps = 0;

async function step(name, fn) {
  where = name;
  const before = errors.length;
  try {
    await fn();
    await page.waitForTimeout(300);
  } catch (e) {
    errors.push(`${name}: ${e.message.split('\n')[0]}`);
    if (SHOTS) { await page.screenshot({ path: `${SHOTS}/failed-${name.replace(/[^a-z0-9]+/gi, '-').slice(0, 60)}.png` }).catch(() => {}); } // 3.19.2: what the failing step saw
  }
  steps++;
  console.log(`  ${errors.length === before ? 'ok   ' : 'CHYBA'}  ${name}`);
}
const visit = (url) => page.goto(BASE + url, { waitUntil: 'networkidle' });

await step('sign in', async () => {
  await visit('/admin.php');
  await page.fill('input[name="user"]', 'admin');
  await page.fill('input[name="password"]', PASSWORD);
  await Promise.all([page.waitForNavigation(), page.press('input[name="password"]', 'Enter')]);
});

// 3.18: whether Claude is connected – a chip next to the account on every screen with its state in words (never the colour
// alone), the dot alone on a phone without sideways scrolling; Ask Claude warns and saves for later without a connection,
// Requests have a status line and a highlighted old request, Claude settings a status panel. Then a call over MCP: connected.
await step('3.18 header: the Claude connection state – not connected, then connected (1440 and 390 px, dark mode)', async () => {
  if (!MYSQL.length) { throw new Error('MYSQL_JSON is not set'); }
  sql("DELETE FROM ka_api_tokeny");
  const shot = async (name, target = page) => { if (SHOTS) { await target.screenshot({ path: `${SHOTS}/${name}.png`, ...(target === page ? { fullPage: false } : {}) }); } };
  const chip = async (expected, kind) => {
    const link = page.locator('.loginprouzek .claude-stav');
    if (!(await link.isVisible())) { throw new Error('no Claude state in the header'); }
    const state = await link.evaluate((el) => ({ cls: el.className, text: el.textContent.trim(), dot: getComputedStyle(el.querySelector('.claude-stav-tecka')).width }));
    if (!state.cls.includes(`claude-stav-${kind}`) || !state.text.startsWith(expected)) { throw new Error(`the header says ${JSON.stringify(state)}, expected ${expected}`); }
    if ((await page.getByRole('link', { name: new RegExp('^' + expected) }).count()) !== 1) { throw new Error(`no link named “${expected}” for screen readers`); }
  };
  const fits = async (where) => {
    const wide = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    if (wide > 0) { throw new Error(`${where}: ${wide} px of sideways scroll at 390 px`); }
    const box = await page.locator('.loginprouzek .claude-stav').boundingBox();
    if (!box || box.width > 44 || box.x < 0 || box.x + box.width > 390) { throw new Error(`${where}: the Claude dot does not sit in the header row: ${JSON.stringify(box)}`); }
    const logo = await page.locator('.hlavicka .kaleta-logo').boundingBox();
    const first = await page.locator('.loginprouzek > :first-child').boundingBox();
    if (!logo || !first || logo.x + logo.width > first.x) { throw new Error(`${where}: the header buttons cover the logo: ${JSON.stringify([logo, first])}`); }
  };
  for (const [label, width, height] of [['desktop', 1440, 900], ['phone', 390, 844]]) {
    await page.setViewportSize({ width, height });
    await visit('/admin.php');
    await chip('Claude not connected', 'none');
    if (width === 390) { await fits('dashboard'); }
    const box = page.locator('.ask-claude:not(.pripojit-claude)');
    if (!(await box.locator('.claude-upozorneni').textContent()).includes('Claude is not connected to this site.')) { throw new Error('Ask Claude does not warn'); }
    if ((await box.locator('button[type="submit"]').textContent()).trim() !== 'Save for later') { throw new Error('Ask Claude still offers to send'); }
    await shot(`dashboard-none-${label}`);
    await box.scrollIntoViewIfNeeded();
    await shot(`ask-claude-warning-${label}`, box);
    await visit('/admin.php?module=requests');
    if ((await page.locator('tr.ceka-dlouho').count()) < 1) { throw new Error('the request waiting for weeks is not highlighted'); }
    if (width === 390) { await fits('requests'); }
    await shot(`requests-status-none-${label}`);
    await visit('/admin.php?module=claude_settings');
    if (!(await page.locator('.claude-panel h2').textContent()).includes('Claude is not connected')) { throw new Error('Claude settings have no status panel'); }
    if (width === 390) { await fits('Claude settings'); }
    await shot(`claude-settings-none-${label}`, page.locator('.claude-panel'));
  }
  // a personal token and one call over MCP: connected
  const token = 'kaleta_' + 'e'.repeat(48);
  sql(`INSERT INTO ka_api_tokeny (idu, nazev, otisk, vytvoren) SELECT idu, 'Browser 318', '${createHash('sha256').update(token).digest('hex')}', NOW() FROM ka_uzivatele WHERE user = 'admin'`);
  const call = await fetch(`${BASE}/mcp`, { method: 'POST', headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' }, body: JSON.stringify({ jsonrpc: '2.0', id: 1, method: 'tools/list' }) });
  if (call.status !== 200) { throw new Error(`the MCP call answered ${call.status}`); }
  for (const [label, width, height] of [['desktop', 1440, 900], ['phone', 390, 844]]) {
    await page.setViewportSize({ width, height });
    await visit('/admin.php');
    await chip('Claude connected', 'active');
    if (width === 390) { await fits('dashboard'); }
    if (!(await page.locator('.claude-stav .claude-stav-kdy').textContent()).includes('last call')) { throw new Error('the last call is not in the chip'); }
    await shot(`dashboard-active-${label}`);
    await visit('/admin.php?module=requests');
    await shot(`requests-status-active-${label}`);
    await visit('/admin.php?module=claude_settings');
    if (!(await page.locator('.claude-panel h2').textContent()).includes('Claude is connected')) { throw new Error('the status panel does not say connected'); }
    await shot(`claude-settings-active-${label}`, page.locator('.claude-panel'));
  }
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.emulateMedia({ colorScheme: 'dark' });
  await visit('/admin.php');
  const dark = await page.locator('.claude-stav').evaluate((el) => [getComputedStyle(el).backgroundColor, getComputedStyle(el).color]);
  if (dark[0] === 'rgb(255, 255, 255)') { throw new Error('the chip stays white in dark mode'); }
  await shot('dashboard-active-dark');
  await page.emulateMedia({ colorScheme: 'light' });
  // the steps below see a site Claude never called, as before (the Connect Claude card leads until a first call)
  sql("DELETE FROM ka_api_tokeny WHERE nazev = 'Browser 318'; DELETE FROM ka_nastaveni WHERE promenna = 'claude_first_used'");
});

for (const url of ['/admin.php', '/admin.php?module=pages', '/admin.php?module=pages&action=new', '/admin.php?module=pages&action=edit&id=1',
  '/admin.php?module=news', '/admin.php?module=collections', '/admin.php?module=categories', '/admin.php?module=tags', '/admin.php?module=media',
  '/admin.php?module=appearance', '/admin.php?module=parts', '/admin.php?module=components', '/admin.php?module=popups', '/admin.php?module=users',
  '/admin.php?module=roles', '/admin.php?module=stats', '/admin.php?module=redirects', '/admin.php?module=changelog', '/admin.php?module=transfer',
  '/admin.php?module=extensions', '/admin.php?module=enquiries', '/admin.php?module=subscribers', '/admin.php?module=newsletters', '/admin.php?module=settings',
  '/admin.php?module=settings&tab=seo', '/admin.php?module=settings&tab=analytics', '/admin.php?module=settings&tab=backups', '/admin.php?module=status', '/admin.php?action=account',
  '/admin.php?module=bookings', '/admin.php?module=whistleblowing']) {
  await step(`open ${url}`, () => visit(url));
}

await step('3.19.2 a module whose feature is off says "switched off" with a link to Features, not "no access"', async () => {
  // every feature is on in this install (the fleet console too), so Redirects is switched off for the check and on again;
  // the administrator used to be sent to Users by a 403 "no access"
  sql("UPDATE ka_nastaveni SET hodnota = REPLACE(hodnota, 'presmerovani', '') WHERE promenna = 'extensions'");
  try {
    await visit('/admin.php?module=redirects');
    const text = (await page.locator('.hlaska-chyba').first().textContent({ timeout: 5000 }).catch(() => '')) || '';
    if (!/switched off/.test(text) || /do not have access/.test(text)) { throw new Error(`module=redirects with the feature off: "${text.trim()}"`); }
    if (!(await page.locator('.hlaska-chyba a[href*="module=extensions"]').count())) { throw new Error('"Features" in the message is not a link'); }
  } finally {
    sql("UPDATE ka_nastaveni SET hodnota = CONCAT(hodnota, ',presmerovani') WHERE promenna = 'extensions'");
  }
  // C3: the Statistics empty text names the screen Integrations and the path is a link
  await visit('/admin.php?module=stats');
  const hint = page.locator('p', { hasText: 'Search Console' }).first();
  if (!(await hint.count()) || /Connections/.test(await hint.textContent())) { throw new Error('the Search engines text still says Connections or is missing'); }
  if (!(await hint.locator('a[href*="module=connectors"]').count())) { throw new Error('"Administration → Integrations" in Statistics is not a link'); }
});

// 3.20 (C1, C11): the Pages list has status tabs with their counts (All / Published / Hidden / Unpublished changes / Trash,
// a tab only when it has rows), the dashboard tiles lead to the matching tab, and an Edited column (when and who) – on a
// phone a card line like every other column
await step('3.20 pages: status tabs with counts, the tile leads to Unpublished changes, the Edited column is a card line at 390 px', async () => {
  const id = sql("SELECT ids FROM ka_stranky WHERE stavba IS NOT NULL AND stavba_koncept IS NULL AND smazano IS NULL ORDER BY ids LIMIT 1").trim();
  if (!id) { throw new Error('no built starter page to give a draft'); }
  sql(`UPDATE ka_stranky SET stavba_koncept = stavba WHERE ids = ${id}`); // one page with unpublished changes
  try {
    await visit('/admin.php');
    if (!(await page.locator('a[href*="module=pages&status=published"]').count())) { throw new Error('the "Published pages" tile does not lead to status=published'); }
    const tile = page.locator('a[href*="module=pages&status=draft"]').first();
    if (!(await tile.count())) { throw new Error('the "Pages with unpublished changes" tile does not lead to status=draft'); }
    await Promise.all([page.waitForNavigation(), tile.click()]);
    const active = (await page.locator('nav.zalozky a.aktivni').textContent()) || '';
    if (!/Unpublished changes \(1\)/.test(active)) { throw new Error(`the open tab is "${active.trim()}", expected "Unpublished changes (1)"`); }
    const rows = await page.locator('table.vypis tbody tr').count();
    if (rows !== 1) { throw new Error(`${rows} rows under Unpublished changes, expected 1`); }
    const tabs = (await page.locator('nav.zalozky a').allTextContents()).map((t) => t.trim());
    for (const name of ['All', 'Published', 'Hidden']) {
      if (!tabs.some((t) => new RegExp(`^${name} \\([1-9]\\d*\\)$`).test(t))) { throw new Error(`no "${name} (n)" tab: ${tabs.join(' | ')}`); }
    }
    if (tabs.some((t) => /^Trash/.test(t))) { throw new Error('a Trash tab without pages in the trash'); }
    if (!(await page.locator('table.vypis th', { hasText: 'Edited' }).count())) { throw new Error('no Edited column'); }
    const edited = (await page.locator('table.vypis tbody tr td.cislo').first().textContent()) || '';
    if (!/\d{4}/.test(edited)) { throw new Error(`the Edited cell of a starter page is empty: "${edited.trim()}" – the installer did not stamp zmeneno`); }
    // 390 px: a row is a card, the Edited cell is a block with its column label, nothing scrolls sideways
    await page.setViewportSize({ width: 390, height: 844 });
    await visit('/admin.php?module=pages');
    const cell = page.locator('table.vypis-karty td[data-popisek="Edited"]').first();
    if (!(await cell.count())) { throw new Error('the Edited cell has no card label'); }
    if ((await cell.evaluate((td) => getComputedStyle(td).display)) !== 'block') { throw new Error('the Edited cell is not a card line at 390 px'); }
    if (await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1)) { throw new Error('the pages list scrolls sideways at 390 px'); }
  } finally {
    await page.setViewportSize({ width: 1440, height: 900 });
    sql(`UPDATE ka_stranky SET stavba_koncept = NULL WHERE ids = ${id}`);
  }
});

// 3.13.1: the consent screen stands in the bare frame; its access choices are option cards (a hidden radio, a border) and
// "recommended" / "new, not verified" are pills – both styled by editor.css, which the frame did not load
await step('OAuth consent: the access choices are cards, the labels are pills', async () => {
  const redirect = 'https://claude.ai/api/mcp/auth_callback';
  const client = await (await fetch(`${BASE}/oauth/register`, { method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ client_name: 'Claude', redirect_uris: [redirect], token_endpoint_auth_method: 'none' }) })).json();
  const challenge = createHash('sha256').update('v'.repeat(50)).digest('base64url');
  await visit(`/oauth/authorize?response_type=code&client_id=${client.client_id}&redirect_uri=${encodeURIComponent(redirect)}&code_challenge=${challenge}&code_challenge_method=S256&state=s&scope=mcp`);
  const look = await page.evaluate(() => {
    const card = document.querySelector('.oauth-souhlas .karta-volba');
    const pill = document.querySelector('.oauth-souhlas .stitek');
    return card && pill ? { radio: getComputedStyle(card.querySelector('input')).opacity, border: getComputedStyle(card).borderTopWidth,
      title: getComputedStyle(card.querySelector('strong')).display, pill: getComputedStyle(pill).borderTopWidth } : null;
  });
  if (!look) { throw new Error('no access choices or label on the consent screen'); }
  if (look.radio !== '0' || look.border !== '2px' || look.title !== 'block' || look.pill !== '1px') { throw new Error(`the consent screen is unstyled: ${JSON.stringify(look)}`); }
  if (SHOTS) { await page.screenshot({ path: `${SHOTS}/oauth-consent.png`, fullPage: true }); }
});

// 3.10 audit (N310-7): Enter in the name of a new token creates the token – also when tokens with their tool set switch
// and revoke buttons are listed above it (they belong to another form, so they are never the form's default button)
await step('account: Enter in the token name creates the token, with tokens listed too', async () => {
  for (const name of ['Enter token 1', 'Enter token 2']) {
    await visit('/admin.php?action=account');
    await page.fill('#token-nazev', name);
    await Promise.all([page.waitForNavigation(), page.press('#token-nazev', 'Enter')]);
    if (!((await page.locator('code.totp-klic').first().textContent()) || '').startsWith('kaleta_')) throw new Error(`no new token for ${name}`);
  }
  const listed = await page.locator('#claude .stitek', { hasText: 'Enter token' }).count();
  const switched = await page.locator('#claude .stitek', { hasText: /^essentials$/ }).count();
  if (listed !== 2 || switched !== 0) throw new Error(`${listed} tokens listed, ${switched} switched to the essentials`);
});

await step('appearance: change a colour and preview', async () => {
  await visit('/admin.php?module=appearance');
  const colour = page.locator('input[type="color"]:visible').first();
  if (await colour.count()) { await colour.fill('#335577'); await page.waitForTimeout(800); }
});

// 3.12 (UX310-24): the logo and the site icon show a preview with Change and Remove, not a path box; the media picker offers
// "Take a photo" only on touch devices
await step('3.12 appearance: logo field with a preview and Change, no Take a photo on a desktop', async () => {
  await visit('/admin.php?module=appearance');
  await page.locator('#zalozka-znacka').click();
  if (await page.locator('#logo').isVisible()) { throw new Error('the logo path box is still shown'); }
  const choose = page.locator('#logo-vybrat');
  if (!(await choose.isVisible()) || (await page.locator('label[for="logo-vybrat"]').count()) !== 1) { throw new Error('the logo has no labelled Choose button'); }
  await choose.click();
  await page.locator('dialog.galerie-okno[open]').waitFor();
  if (await page.locator('dialog.galerie-okno[open] .galerie-vyfotit').isVisible()) { throw new Error('"Take a photo" shows on a desktop'); }
  await page.locator('dialog.galerie-okno[open] [data-zavri]').click();
});

await step('appearance: save to the draft look, preview bar, publish', async () => {
  await visit('/admin.php?module=appearance');
  // the colour field may sit on a tab that is not open – set the value directly
  await page.evaluate(() => { document.querySelectorAll('[name="ds[barvy][primarni]"]').forEach((i) => { i.value = '#335577'; }); });
  await Promise.all([page.waitForNavigation(), page.locator('.vzhled-ulozit input[type="submit"]').click()]);
  await page.locator('#vzhled-koncept').waitFor();
  if (SHOTS) { await page.screenshot({ path: `${SHOTS}/look-draft-bar.png`, fullPage: false }); }
  await Promise.all([page.waitForNavigation(), page.locator('#vzhled-koncept button.tl').click()]);
  if (await page.locator('#vzhled-koncept').count()) { throw new Error('the draft look is still there after publishing'); }
});

await step('3.5 dashboard: Connect Claude leads until Claude is connected, its Copy button works', async () => {
  await visit('/admin.php');
  const card = page.locator('#pripojit-claude');
  await card.waitFor();
  const ordered = await page.evaluate(() => {
    const [a, b, c] = [document.getElementById('pripojit-claude'), document.querySelector('.pruvodce'), document.getElementById('ask-claude-nadpis')];
    return Boolean(a && b && c && a.compareDocumentPosition(b) & Node.DOCUMENT_POSITION_FOLLOWING && b.compareDocumentPosition(c) & Node.DOCUMENT_POSITION_FOLLOWING);
  });
  if (!ordered) { throw new Error('the dashboard does not show Connect Claude, then First steps, then Ask Claude'); }
  const copy = card.locator('[data-kopirovat]');
  const label = await copy.textContent();
  await copy.click();
  await page.waitForFunction(([el, before]) => el.textContent !== before, [await copy.elementHandle(), label]);
  if (SHOTS) { await page.screenshot({ path: `${SHOTS}/dashboard-connect-claude.png`, fullPage: false }); }
});

await step('3.24 N-U3/N-U4: New page starts in the builder – three choices, the text editor only for a text page, and the page title band is not offered twice', async () => {
  await visit('/admin.php?module=pages&action=new');
  const editor = page.locator('.radek.nova-jen-text');
  const create = page.locator('.tlacitka .nova-jen-stavitel button');
  const save = page.locator('.tlacitka .nova-jen-text button');
  const shown = async () => [await editor.isVisible(), await create.isVisible(), await save.isVisible(), await page.locator('#sablona').isVisible()].join('/');
  if (!(await page.locator('#start-builder').isChecked()) || await shown() !== 'false/true/false/false') { throw new Error(`the empty builder page is not the default start: ${await shown()}`); }
  await page.locator('label.karta-volba:has(#start-text)').click();
  if (await shown() !== 'true/false/true/false') { throw new Error(`a text page does not show the text editor and Save: ${await shown()}`); }
  await page.locator('label.karta-volba:has(#start-layout)').click();
  if (await shown() !== 'false/true/false/true') { throw new Error(`a ready-made layout does not show its list: ${await shown()}`); }
  if (SHOTS) { await page.screenshot({ path: `${SHOTS}/new-page-start.png`, fullPage: false }); }
  await page.locator('label.karta-volba:has(#start-builder)').click();
  await page.fill('#titulek', 'Browser builder page');
  await Promise.all([page.waitForNavigation(), create.click()]);
  await page.locator('.st-lista .st-skryta').waitFor();
  await page.waitForTimeout(1500);
  if (await canvas().locator('h1').count() !== 1 || !/Browser builder page/.test((await canvas().locator('h1').first().textContent()) || '')) { throw new Error('the empty builder page does not start with its title band'); }
  await page.locator('.st-zalozky [role="tab"]').first().click();
  const titleBand = page.locator('.st-knihovna button', { hasText: 'Page title' }).first();
  if (!/Already on this page/.test((await titleBand.textContent()) || '')) { throw new Error('"Page title" is offered on a page that has its title band'); }
  const sections = await canvas().locator('section').count();
  await titleBand.click();
  await page.waitForTimeout(800);
  if (await canvas().locator('section').count() !== sections || await canvas().locator('h1').count() !== 1 || await canvas().locator('h2', { hasText: 'Page title' }).count()) { throw new Error('"Page title" added a second title band'); }
  if (!/already starts with its title/.test((await page.locator('.st-stav').first().textContent()) || '')) { throw new Error('choosing "Page title" does not say the page has its title'); }
});

await step('3.5 a new page from a template stays hidden until its build is published, then goes live', async () => {
  await visit('/admin.php?module=pages&action=new');
  await page.fill('#titulek', 'Browser template page');
  await page.locator('label.karta-volba:has(#start-layout)').click(); // 3.24 (N-U3): the layout list belongs to its choice
  await page.selectOption('#sablona', 'landing');
  await Promise.all([page.waitForNavigation(), page.locator('form.formular button[type="submit"]:visible').first().click()]);
  await page.locator('.st-lista .st-skryta').waitFor();
  const visitor = await browser.newContext();
  if ((await visitor.request.get(`${BASE}/browser-template-page`)).status() !== 404) { throw new Error('the empty template page is live before its build is published'); }
  await page.locator('.st-lista button.st-tl-hlavni').click();
  // 3.11 (UX310-1): the template's placeholders and sample texts are in the pre-publish check, the summary first
  const anyway = page.locator('dialog[open] .st-tl-hlavni');
  await anyway.waitFor({ timeout: 5000 });
  const firstFinding = (await page.locator('dialog[open] .st-kontrola li').first().textContent()) || '';
  if (!/This page still has sample content from the starter site \(sample testimonials: 2, placeholder figures: 2, sample texts: \d+\)/.test(firstFinding)) {
    throw new Error(`the pre-publish check does not lead with the sample content: ${firstFinding}`);
  }
  if (SHOTS) { await page.screenshot({ path: `${SHOTS}/builder-sample-content-check.png`, fullPage: false }); }
  await anyway.click();
  await page.locator('.st-lista .st-skryta').waitFor({ state: 'detached' });
  if ((await visitor.request.get(`${BASE}/browser-template-page`)).status() !== 200) { throw new Error('the page is not live after its first publish'); }
  await visitor.close();
});

await step('builder: select, style, phone, edit text', async () => {
  await visit('/admin.php?module=pages&action=builder&id=1');
  await page.waitForTimeout(1500);
  await canvas().locator('h1').first().click();
  await page.waitForTimeout(500);
  await page.getByRole('tab', { name: 'Style' }).first().click().catch(() => page.getByText('Style', { exact: true }).first().click());
  await page.waitForTimeout(400);
  await page.getByRole('button', { name: 'Phone' }).first().click(); // 3.11: "Phone", as in Site appearance
  await page.waitForTimeout(1000);
  await page.getByRole('button', { name: 'Desktop' }).first().click().catch(() => {});
  await page.waitForTimeout(600);
  await canvas().locator('h1').first().click();
  await page.getByRole('tab', { name: 'Content' }).first().click().catch(() => page.getByText('Content', { exact: true }).first().click());
  const field = page.locator('.st-panel textarea, .st-panel input[type="text"]').first();
  if (await field.count()) { await field.fill('Browser test heading'); await page.waitForTimeout(2500); } // autosave of the draft
});

// 3.21 (U3, U4): a phone override is visible from Desktop (a dot on the Phone button, a device icon with the value next to the field,
// „×“ resets it); one Visibility row per device in Advanced – a hidden element is a dashed strip on the phone canvas (click „show“
// to bring it back) and carries the device icon in Structure; the public page gets no strip
await step('3.21 (U3, U4) builder: phone override seen from Desktop, reset, hide on phone – strip, tree mark, show', async () => {
  const device = (name) => page.locator('.st-lista .st-skupina button[aria-label="' + name + '"]');
  const pick = async (name) => { await device(name).click(); await page.waitForFunction((n) => document.querySelector('.st-lista .st-skupina button[aria-label="' + n + '"]').getAttribute('aria-pressed') === 'true', name); };
  await canvas().locator('h1').first().click();
  await page.getByRole('tab', { name: 'Style' }).first().click();
  await pick('Phone');
  await page.evaluate(() => document.querySelectorAll('.st-styl details').forEach((d) => { d.open = true; }));
  const colour = page.locator('#st-v-barva');
  await colour.selectOption('primarni'); // 3.26 (N-U2): a select of the site's colours
  await page.waitForFunction(() => document.querySelector('#st-v-barva').closest('.st-vlastnost').classList.contains('nastaveno'));
  if (!(await page.locator('#st-v-barva').locator('xpath=ancestor::*[contains(@class,"st-vlastnost")]').locator('.st-vynulovat').count())) { throw new Error('a field with an own value on Phone has no × to reset it'); }
  await pick('Desktop');
  await device('Phone').locator('.st-tecka').waitFor({ timeout: 3000 });
  if (await device('Desktop').locator('.st-tecka').count()) { throw new Error('the current device button (Desktop) shows a dot'); }
  if (!/own style values of the selected element here: 1/.test(await device('Phone').getAttribute('title') || '')) { throw new Error('the Phone button does not say how many own values the element has there'); }
  await page.evaluate(() => document.querySelectorAll('.st-styl details').forEach((d) => { d.open = true; }));
  const mark = page.locator('#st-v-barva').locator('xpath=ancestor::*[contains(@class,"st-vlastnost")]').locator('.st-znacka-bp');
  if ((await mark.getAttribute('title')) !== 'Set on Phone: primarni') { throw new Error('the colour field does not show the phone override: ' + await mark.getAttribute('title').catch(() => 'none')); }
  if ((await page.locator('#st-v-barva').inputValue()) !== '') { throw new Error('the Desktop field shows the phone value as its own'); }
  await mark.click(); // the device icon jumps to that breakpoint
  await page.waitForFunction(() => document.querySelector('.st-lista .st-skupina button[aria-label="Phone"]').getAttribute('aria-pressed') === 'true');
  await page.evaluate(() => document.querySelectorAll('.st-styl details').forEach((d) => { d.open = true; }));
  if ((await page.locator('#st-v-barva').inputValue()) !== 'primarni') { throw new Error('the Phone field lost its value'); }
  await page.locator('#st-v-barva').locator('xpath=ancestor::*[contains(@class,"st-vlastnost")]').locator('.st-vynulovat').click();
  await page.waitForFunction(() => document.querySelector('#st-v-barva') && document.querySelector('#st-v-barva').value === '' && !document.querySelector('#st-v-barva').closest('.st-vlastnost').querySelector('.st-vynulovat'));
  await pick('Desktop');
  if (await device('Phone').locator('.st-tecka').count()) { throw new Error('the Phone dot stays after the reset'); }
  // U4: hide on Phone from the Visibility row (Advanced), while editing on Desktop
  await page.getByRole('tab', { name: 'Advanced' }).first().click();
  const phoneToggle = page.locator('.st-viditelnost button[data-bp="mobil"]');
  if ((await phoneToggle.getAttribute('aria-pressed')) !== 'true') { throw new Error('the Visibility row shows the heading hidden on Phone before anything was hidden'); }
  if (await page.locator('.st-panel .st-zaskrt', { hasText: 'Hide on' }).count()) { throw new Error('the old Hide on mobile checkboxes are still there'); }
  await phoneToggle.click();
  await page.waitForFunction(() => document.querySelector('.st-viditelnost button[data-bp="mobil"]').getAttribute('aria-pressed') === 'false');
  if ((await page.locator('.st-viditelnost button[data-bp="zaklad"]').getAttribute('aria-pressed')) !== 'true') { throw new Error('hiding on Phone hid the Desktop too'); }
  if (await canvas().locator('.ka-st-skryty-prvek').count()) { throw new Error('a strip on the Desktop canvas for an element hidden on Phone only'); }
  await pick('Phone');
  const strip = canvas().locator('.ka-st-skryty-prvek').first();
  await strip.waitFor({ timeout: 5000 });
  const stripText = (await strip.textContent()) || '';
  if (!stripText.startsWith('Hidden on phone')) { throw new Error('the strip says: ' + stripText); }
  const stripBox = await strip.boundingBox();
  if (!stripBox || Math.round(stripBox.height) !== 24) { throw new Error('the strip is not 24 px high: ' + JSON.stringify(stripBox)); }
  await page.waitForFunction(() => { const f = document.querySelector('.st-platno iframe:not(.st-nacita)'); const h = f && f.contentDocument && f.contentDocument.querySelector('h1'); return h && getComputedStyle(h).display === 'none'; }, null, { timeout: 8000 }); // the refreshed canvas hides the heading itself
  // the Structure tree marks the hidden element with the device icon
  await page.getByRole('tab', { name: 'Structure' }).first().click();
  const marked = page.locator('.st-strom .st-uzel-zarizeni[title="Hidden on: Phone"]');
  await marked.first().waitFor({ timeout: 3000 });
  if (SHOTS) { await page.screenshot({ path: `${SHOTS}/builder-hidden-on-phone.png`, fullPage: false }); }
  // the public page (no editor) is the published version: never a strip, never an editor id
  const visitor = await browser.newContext();
  const html = await (await visitor.request.get(`${BASE}/`)).text();
  await visitor.close();
  if (/ka-st-skryty-prvek|data-ka-id/.test(html)) { throw new Error('the public page carries an editor strip or editor ids'); }
  // N321-1 (review): typing the hidden heading's text in Content goes to the heading, never into its strip (same data-ka-id)
  await strip.click();
  await page.getByRole('tab', { name: 'Content' }).first().click();
  const headingText = page.locator('.st-panel [data-pole="text"] input').first();
  await headingText.click();
  await headingText.press('End');
  await page.keyboard.type('Z');
  await page.waitForTimeout(300);
  if (!((await strip.textContent()) || '').startsWith('Hidden on phone')) { throw new Error('typing the hidden heading\'s text overwrote its strip: ' + await strip.textContent()); }
  await page.keyboard.press('Backspace');
  await page.waitForTimeout(2500);
  // „show“ on the strip brings the element back on the phone and keeps the other devices as they were
  await strip.locator('button').click();
  await canvas().locator('.ka-st-skryty-prvek').first().waitFor({ state: 'detached', timeout: 5000 });
  await page.waitForFunction(() => !document.querySelector('.st-strom .st-uzel-zarizeni'));
  await canvas().locator('h1').first().click();
  await page.getByRole('tab', { name: 'Advanced' }).first().click();
  if ((await page.locator('.st-viditelnost button[data-bp="mobil"]').getAttribute('aria-pressed')) !== 'true') { throw new Error('after „show“ the Visibility row still says hidden on Phone'); }
  await pick('Desktop');
  await page.waitForTimeout(2500); // autosave of the draft before the next step
});

await step('builder: select the parent element', async () => {
  // broken from 1.4.0 to 3.2.0: find() returned the parent under another key than its callers read (3.2.1)
  await canvas().locator('h1').first().click();
  await page.waitForTimeout(400);
  const parent = page.locator('button[title^="Select parent element"]').first();
  if (!(await parent.count())) { throw new Error('a nested element offers no "Select parent element" button'); }
  await parent.click();
  await page.waitForTimeout(300);
});

// 3.21 "Builder for non-designers" (Head-of-UX audit U2, U5, U9, U19): the Style tab shows only the groups the kind of element
// needs, the rest behind "Show all properties"; font size is a select of named steps with a custom value checked in the field;
// the count badge is a pill with a space before it; a heading's tag is set only as its Level in Content
await step('3.21 (U2, U5, U9, U19) builder: a heading shows Typography and Spacing but Layout only after "Show all properties"; font size is a select of steps, a custom "xl" is refused in the field; the count badge is a pill after a space ("Typography 2"); no HTML tag select on a heading', async () => {
  const groups = async () => page.locator('.st-panel .st-styl details').evaluateAll((els) => els.map((d) => {
    const summary = d.querySelector('summary');
    return { name: summary.firstChild.textContent.trim(), open: d.open, text: summary.textContent.trim() };
  }));
  const until = async (fn, what, ms = 8000) => {
    const end = Date.now() + ms;
    let last;
    while (Date.now() < end) { last = await fn(); if (last) { return last; } await page.waitForTimeout(200); }
    throw new Error(`${what} (last: ${JSON.stringify(last)})`);
  };
  const h1Size = () => canvas().locator('h1').first().evaluate((e) => getComputedStyle(e).fontSize).catch(() => '');
  // the section selected by the previous step: Layout is relevant, the toggle is offered, the active state is "Pressed"
  await page.getByRole('tab', { name: 'Style' }).first().click();
  await page.locator('.st-panel .st-styl').waitFor();
  let names = (await groups()).map((g) => g.name);
  if (!names.includes('Layout')) { throw new Error(`a section's style groups have no Layout: ${names.join(', ')}`); }
  if (!(await page.getByLabel('Show all properties').count())) { throw new Error('no "Show all properties" toggle under the style groups'); }
  if ((await page.getByRole('button', { name: 'Pressed', exact: true }).count()) !== 1) { throw new Error('the active state is not named "Pressed"'); }
  // the heading: Typography and Spacing open, no Layout (grid columns) until "Show all properties"
  await canvas().locator('h1').first().click();
  const size = page.locator('#st-v-velikost_pisma');
  await size.waitFor();
  const heading = await groups();
  names = heading.map((g) => g.name);
  if (!names.includes('Typography') || !names.includes('Spacing') || names.includes('Layout')) { throw new Error(`a heading's style groups: ${names.join(', ')}`); }
  if (await page.locator('#st-v-sloupce').count()) { throw new Error('Grid columns are offered on a heading'); }
  for (const name of ['Typography', 'Spacing']) { if (!heading.find((g) => g.name === name).open) { throw new Error(`${name} is not open on a heading`); } }
  const showAll = page.getByLabel('Show all properties');
  await showAll.check();
  await page.locator('#st-v-sloupce').waitFor({ state: 'attached' }); // in the Layout group, which opens only when clicked
  if (!(await groups()).map((g) => g.name).includes('Layout')) { throw new Error('"Show all properties" does not bring Layout'); }
  await showAll.uncheck();
  await page.locator('#st-v-sloupce').waitFor({ state: 'detached' });
  // font size: a select of the steps; a step renders on the canvas as the token
  if ((await size.evaluate((e) => e.tagName)) !== 'SELECT') { throw new Error('font size is not a select'); }
  const options = await size.locator('option').allTextContents();
  if (!options.some((o) => /^2 · Large$/.test(o)) || !options.includes('Custom…')) { throw new Error(`font size options: ${options.join(' | ')}`); }
  const originalSize = await h1Size();
  const originalStep = await size.inputValue(); // the starter's h1 has a step of its own; the step puts it back at the end
  await size.selectOption(originalStep === '1' ? '2' : '1');
  const stepSize = await until(async () => { const h = await h1Size(); return h && h !== originalSize ? h : null; }, 'the canvas heading did not take step 1');
  // U19: the badge reads "Typography 2" (a space, a pill), not "Typography2"
  await canvas().locator('h1').first().click();
  await size.waitFor();
  const typography = (await groups()).find((g) => g.name === 'Typography');
  if (!typography || !/^Typography \d+$/.test(typography.text)) { throw new Error(`the group header reads "${typography && typography.text}"`); }
  const radius = await page.locator('.st-styl summary small').first().evaluate((e) => getComputedStyle(e).borderRadius);
  if (radius === '0px') { throw new Error('the count badge is not a pill'); }
  // Custom… + "xl": refused right at the field, nothing saved (no warning from the server, the canvas keeps step 1)
  await size.selectOption('vlastni');
  const custom = page.locator('.st-krok-vlastni');
  await custom.waitFor();
  await custom.fill('xl');
  await custom.press('Tab');
  const row = page.locator('.st-vlastnost:has(#st-v-velikost_pisma)');
  await row.locator('.st-chyba-pole:visible').waitFor();
  if (!/Not a valid size/.test((await row.locator('.st-chyba-pole').textContent()) || '')) { throw new Error('no inline message for an invalid size'); }
  if (!/st-pole-chyba/.test((await row.getAttribute('class')) || '')) { throw new Error('the invalid size is not marked on the field'); }
  await page.waitForTimeout(2500);
  if (await page.locator('.st-panel .st-chyby').count()) { throw new Error('the invalid size reached the server'); }
  if (/warning/i.test((await page.locator('.st-stav').first().textContent()) || '')) { throw new Error('the top bar warns – the invalid size was saved'); }
  if ((await h1Size()) !== stepSize) { throw new Error('the canvas changed after an invalid size'); }
  // a valid custom length is saved and rendered; then the field is cleared so the later steps see the heading as before
  await custom.fill('40px');
  await custom.press('Tab');
  await until(async () => (await h1Size()) === '40px', 'the canvas heading did not take the custom 40px');
  // N321-5 (review): "×" (3.21 U3) on a custom size clears the value and the custom field with it – the row is drawn anew
  await row.locator('.st-vynulovat').click();
  await page.waitForFunction(() => { const s = document.querySelector('#st-v-velikost_pisma'); return s && s.value === '' && !s.closest('.st-vlastnost').querySelector('.st-krok-vlastni, .st-vynulovat'); });
  await size.selectOption(originalStep);
  await until(async () => (await h1Size()) === originalSize, 'the canvas heading did not return to its size');
  // U9: a heading's tag is its Level in Content – Advanced has no HTML tag select, only a note
  await page.getByRole('tab', { name: 'Advanced' }).first().click();
  await page.locator('.st-panel h3', { hasText: 'Display conditions' }).waitFor();
  if (await page.locator('.st-panel label.st-pole', { hasText: 'HTML tag' }).count()) { throw new Error('the Advanced tab of a heading offers an HTML tag select next to Level'); }
  if (!(await page.locator('.st-panel', { hasText: 'Level in the Content tab' }).count())) { throw new Error('the Advanced tab of a heading does not say where the tag is set'); }
  await page.getByRole('tab', { name: 'Content' }).first().click();
  if (!(await page.locator('.st-uroven button', { hasText: 'H1' }).count())) { throw new Error('Content has no Level buttons for the heading'); }
  if (SHOTS) { await page.screenshot({ path: `${SHOTS}/builder-321-heading-style.png`, fullPage: false }); }
});

// 3.26 (N-U2): spacing and colour are token choices like the font size – a select of the design system's steps or the site's
// colours (with swatches) and "Custom…" checked in the field; "primarni" typed into Margin bottom is refused there and never
// reaches the server; a step keeps its stored form (the token, not a length)
await step('3.26 (N-U2) builder: Margin bottom is a select of spacing steps, "primarni" as a custom value is refused in the field and not saved; colour is a select with swatches and Custom…', async () => {
  const until = async (fn, what, ms = 8000) => {
    const end = Date.now() + ms;
    let last;
    while (Date.now() < end) { last = await fn(); if (last) { return last; } await page.waitForTimeout(200); }
    throw new Error(`${what} (last: ${JSON.stringify(last)})`);
  };
  const openGroups = () => page.evaluate(() => document.querySelectorAll('.st-styl details').forEach((d) => { d.open = true; }));
  const h1 = () => canvas().locator('h1').first();
  const h1Margin = () => h1().evaluate((e) => getComputedStyle(e).marginBlockEnd).catch(() => '');
  const h1Colour = () => h1().evaluate((e) => getComputedStyle(e).color).catch(() => '');
  await h1().click();
  await page.getByRole('tab', { name: 'Style' }).first().click();
  await openGroups();
  const margin = page.locator('#st-v-okraj_dole');
  await margin.waitFor();
  if ((await margin.evaluate((e) => e.tagName)) !== 'SELECT') { throw new Error('Margin bottom is not a select'); }
  const options = await margin.locator('option').allTextContents();
  for (const o of ['0 · none', 'm · medium', '3xl · largest', 'Custom…']) { if (!options.includes(o)) { throw new Error(`Margin bottom options: ${options.join(' | ')}`); } }
  const row = page.locator('.st-vlastnost:has(#st-v-okraj_dole)');
  const before = await h1Margin();
  // Custom… + "primarni": refused right at the field – no request with it, no warning, the canvas unchanged
  const saves = [];
  const watch = (r) => { if (r.method() === 'POST' && /primarni/.test(r.postData() || '') && /okraj_dole/.test(r.postData() || '')) { saves.push(r.url()); } };
  page.on('request', watch);
  await margin.selectOption('vlastni');
  const custom = row.locator('.st-krok-vlastni');
  await custom.waitFor();
  await custom.fill('primarni');
  await custom.press('Tab');
  await row.locator('.st-chyba-pole:visible').waitFor();
  if (!/Not a valid spacing/.test((await row.locator('.st-chyba-pole').textContent()) || '')) { throw new Error('no inline message for an invalid spacing'); }
  if (!/st-pole-chyba/.test((await row.getAttribute('class')) || '')) { throw new Error('the invalid spacing is not marked on the field'); }
  await page.waitForTimeout(2500);
  page.off('request', watch);
  if (saves.length) { throw new Error('"primarni" in Margin bottom reached the server: ' + saves.join(', ')); }
  if (await page.locator('.st-panel .st-chyby').count()) { throw new Error('the server reported an invalid value'); }
  if (/warning/i.test((await page.locator('.st-stav').first().textContent()) || '')) { throw new Error('the top bar warns – the invalid spacing was saved'); }
  if ((await h1Margin()) !== before) { throw new Error('the canvas changed after an invalid spacing'); }
  // a step: saved, rendered, and stored as the token (the select shows it again after the element is selected anew)
  await margin.selectOption('3xl');
  await until(async () => { const m = await h1Margin(); return m && m !== before ? m : null; }, 'the canvas heading did not take the 3xl spacing');
  await page.waitForTimeout(2500); // the autosave answers with the cleaned build; the panel is drawn from it anew
  await page.getByRole('tab', { name: 'Content' }).first().click();
  await page.getByRole('tab', { name: 'Style' }).first().click();
  await openGroups();
  if ((await page.locator('#st-v-okraj_dole').inputValue()) !== '3xl') { throw new Error('the stored spacing lost its form (not the token 3xl)'); }
  if (await page.locator('.st-vlastnost:has(#st-v-okraj_dole) .st-krok-vlastni').count()) { throw new Error('a token spacing opened the custom field'); }
  await page.locator('.st-vlastnost:has(#st-v-okraj_dole) .st-vynulovat').click();
  await page.waitForFunction(() => { const s = document.querySelector('#st-v-okraj_dole'); return s && s.value === '' && !s.closest('.st-vlastnost').querySelector('.st-krok-vlastni, .st-vynulovat'); });
  await until(async () => (await h1Margin()) === before, 'the heading did not return to its margin');
  // colour: a select of the site's colours, swatches under it, Custom… checked like the server checks it
  await openGroups();
  const colour = page.locator('#st-v-barva');
  if ((await colour.evaluate((e) => e.tagName)) !== 'SELECT') { throw new Error('Text colour is not a select'); }
  const colourOptions = await colour.locator('option').allTextContents();
  if (!colourOptions.includes('Primary') || !colourOptions.includes('Custom…')) { throw new Error(`Text colour options: ${colourOptions.join(' | ')}`); }
  const colourRow = page.locator('.st-vlastnost:has(#st-v-barva)');
  if ((await colourRow.locator('.st-vzorky button').count()) < 5) { throw new Error('no swatches of the site\'s colours'); }
  const ink = await h1Colour();
  await colourRow.locator('.st-vzorky button[data-token="primarni"]').click();
  const primary = await until(async () => { const c = await h1Colour(); return c && c !== ink ? c : null; }, 'the canvas heading did not take the primary colour');
  if ((await colour.inputValue()) !== 'primarni' || (await colourRow.locator('.st-vzorky button[data-token="primarni"]').getAttribute('aria-pressed')) !== 'true') { throw new Error('the swatch did not pick the token'); }
  await colour.selectOption('vlastni');
  const customColour = colourRow.locator('.st-krok-vlastni');
  await customColour.fill('blue-ish');
  await customColour.press('Tab');
  await colourRow.locator('.st-chyba-pole:visible').waitFor();
  if (!/Not a valid colour/.test((await colourRow.locator('.st-chyba-pole').textContent()) || '')) { throw new Error('no inline message for an invalid colour'); }
  await page.waitForTimeout(2000);
  if ((await h1Colour()) !== primary) { throw new Error('the canvas changed after an invalid colour'); }
  await customColour.fill('#123456');
  await customColour.press('Tab');
  await until(async () => (await h1Colour()) === 'rgb(18, 52, 86)', 'the canvas heading did not take the custom #123456');
  if (SHOTS) { await page.screenshot({ path: `${SHOTS}/builder-326-token-fields.png`, fullPage: false }); }
  await page.locator('.st-vlastnost:has(#st-v-barva) .st-vynulovat').click();
  await page.waitForFunction(() => { const s = document.querySelector('#st-v-barva'); return s && s.value === '' && !s.closest('.st-vlastnost').querySelector('.st-krok-vlastni, .st-vynulovat'); });
  await until(async () => (await h1Colour()) === ink, 'the heading did not return to its colour');
});

await step('3.19.2 builder: Save as component, Save to my sections and a shared class reach the server', async () => {
  // broken from 1.4.0 to 3.19.1: the script sent the name as "name", the server read "nazev" – every save answered
  // "Name or element is missing." (the same for the shared-class endpoint and the writing assistant's prompt)
  const viaMore = async (label, name, expected) => {
    await page.getByRole('button', { name: 'More actions' }).first().click();
    await page.locator('#st-vice button', { hasText: label }).first().click();
    const dialog = page.locator('dialog.st-dialog[open]').last(); // the name dialog opens over the Versions dialog of an earlier step
    await dialog.waitFor();
    await dialog.locator('input[type="text"]').fill(name);
    await dialog.locator('button[type="submit"]').click();
    let status = '';
    for (let i = 0; i < 25; i++) { // the status line reports the result of the request (the autosave writes there too)
      status = (await page.locator('.st-stav').first().textContent()) || '';
      if (/missing|failed/i.test(status)) { throw new Error(`"${label}": ${status.trim()}`); }
      if (expected.test(status)) { return; }
      await page.waitForTimeout(200);
    }
    throw new Error(`"${label}": no confirmation in the status line, last: "${status.trim()}"`);
  };
  await canvas().locator('h1').first().click();
  await page.waitForTimeout(400);
  await viaMore('Save to my sections', 'Browser section', /My sections/);
  await page.locator('.st-zalozky [role="tab"]').first().click(); // the Add panel lists it under My sections
  await page.waitForTimeout(300);
  if (!(await page.locator('.st-moje-sekce strong', { hasText: 'Browser section' }).count())) { throw new Error('the saved section is not in the Add panel'); }
  // a visible paragraph (the first <p> of the page sits in the folded phone menu), so the h1 the later steps select stays a plain element
  await canvas().locator('p:visible').first().click();
  await page.waitForTimeout(400);
  await viaMore('Save as component', 'Browser component', /Component saved/);
  // the shared-class endpoint takes the same key; the panel's class editor posts it on every change
  const j = await page.evaluate(async () => {
    const D = JSON.parse(document.getElementById('stavitel-data').textContent);
    const f = new FormData();
    f.append('_csrf', document.querySelector('input[name="_csrf"]').value);
    f.append('name', 'browser-card'); f.append('styl', '{}'); f.append('css', '');
    return (await fetch(D.adresy.trida, { method: 'POST', body: f, credentials: 'same-origin' })).json();
  });
  if (!j.ok) { throw new Error(`saving a shared class failed: ${j.chyba || j.status}`); }
  await page.waitForTimeout(2500); // the draft autosaves before the next step reloads the builder
});

// 3.20 "Connected admin", part B (CPO audit C2, C5, C16, C19, C20)
await step('3.20 (C2, C16) enquiry → form → page: the detail names the form and the page with admin links, the builder opens with the form selected, Statistics link the page; a resolved enquiry offers a testimonial request, folded below the actions', async () => {
  // the starter's contact page carries a form; its hidden fields name the source and the element (Front\Forms)
  const visitor = await browser.newContext();
  const html = await (await visitor.request.get(`${BASE}/contact`)).text();
  const source = /name="zdroj" value="([^"]+)"/.exec(html);
  const element = /name="prvek" value="([^"]+)"/.exec(html);
  if (!source || !element) { throw new Error('the contact page has no form'); }
  const pageId = Number(source[1].replace('stranka:', ''));
  sql(`INSERT INTO ka_poptavky (datum, formular, zdroj, prvek, stranka, email, data, stav) VALUES (NOW(), 'Enquiry', '${source[1]}', '${element[1]}', '/contact', 'hannah@example.com', '[["Message","Where do I change the recipient?"]]', 0)`);
  // an older or imported row knows only the form name and the public path: the page by its address, the form by its name
  sql("INSERT INTO ka_poptavky (datum, formular, zdroj, prvek, stranka, email, data, stav) VALUES (NOW(), 'Enquiry', '', '', '/contact', 'old@example.com', '[]', 1)");
  const [newId, oldId] = sql("SELECT idp FROM ka_poptavky WHERE email IN ('hannah@example.com', 'old@example.com') ORDER BY email = 'old@example.com'").trim().split('\n').map(Number);
  await visit(`/admin.php?module=enquiries&action=detail&id=${newId}`);
  const origin = page.locator('dd[data-origin]');
  const line = ((await origin.textContent()) || '').replace(/\s+/g, ' ');
  if (!/From the form Enquiry on the page Contact – Open in the builder · Page settings/.test(line)) { throw new Error(`the origin line reads: ${line}`); }
  const builderHref = await origin.locator('a', { hasText: 'Open in the builder' }).getAttribute('href');
  if (!builderHref.endsWith(`module=pages&action=builder&id=${pageId}#element=${element[1]}`)) { throw new Error(`the builder link is ${builderHref}`); }
  if (!(await origin.locator('a', { hasText: 'Page settings' }).getAttribute('href')).includes(`module=pages&action=edit&id=${pageId}`)) { throw new Error('Page settings does not open the page form'); }
  // C16, 3.23.1 (N-C10 and the owner): asking for a testimonial is a folded step after the main actions – not on a new enquiry;
  // once it is marked resolved, the same buttons and one plain sentence for every role (the References collection comes with the first answer)
  if (await page.locator('[data-testimonial]').count() !== 0) { throw new Error('a new enquiry already offers to ask for a testimonial'); }
  await Promise.all([page.waitForNavigation(), page.locator('.tlacitka button', { hasText: 'Mark as resolved' }).click()]);
  if (!page.url().includes(`id=${newId}`)) { await visit(`/admin.php?module=enquiries&action=detail&id=${newId}`); }
  const ask = page.locator('details.reference-zadost[data-testimonial]');
  if (await ask.count() !== 1 || await ask.evaluate((d) => d.open)) { throw new Error('a resolved enquiry has no folded testimonial block'); }
  if (!(await ask.evaluate((d) => { const actions = document.querySelector('.tlacitka form[action*="action=status"]'); return !!actions && !!(actions.compareDocumentPosition(d) & Node.DOCUMENT_POSITION_FOLLOWING); }))) { throw new Error('the testimonial block is not below the main actions'); }
  if ((await ask.locator('summary').textContent()).trim() !== 'Ask the customer for a testimonial') { throw new Error('the summary is not “Ask the customer for a testimonial”'); }
  await ask.locator('summary').click();
  if (!(await ask.locator('button', { hasText: 'Ask for a testimonial by e-mail' }).isVisible()) || !(await ask.locator('button', { hasText: 'Only create the link' }).isVisible())) { throw new Error('the two request buttons are not in the block'); }
  if (!(await ask.textContent()).includes('Was the customer happy? Send them a link where they can write a few words about you for your website. Nothing is published until you publish it.')) { throw new Error('the plain sentence is missing'); }
  if (await page.locator('button[name="preset"][value="references"]').count() !== 0) { throw new Error('the testimonial block still asks to create the References collection first'); }
  if (SHOTS) { await page.screenshot({ path: `${SHOTS}/enquiry-origin.png`, fullPage: true }); }
  await visit(`/admin.php?module=enquiries&action=detail&id=${oldId}`);
  if ((await page.locator('dd[data-origin] a', { hasText: 'Open in the builder' }).getAttribute('href')) !== builderHref) { throw new Error('an older enquiry without the element id does not find the form by its name'); }
  // the builder: the form is selected, the inspector shows it, the canvas scrolled to it, and the hash is dropped from the address
  await page.goto(new URL(builderHref, BASE).href, { waitUntil: 'networkidle' });
  const selected = canvas().locator(`[data-ka-id="${element[1]}"].ka-st-vybrany`);
  await selected.waitFor({ timeout: 15000 });
  if (!/Form name/.test((await page.locator('.st-pravy').textContent()) || '')) { throw new Error('the inspector does not show the selected form'); }
  if (page.url().includes('#element=')) { throw new Error('the #element hash stays in the address'); }
  const inView = await selected.evaluate((el) => { const r = el.getBoundingClientRect(); return r.bottom > 0 && r.top < window.innerHeight; });
  if (!inView) { throw new Error('the canvas did not scroll to the form'); }
  // an unknown id is ignored silently: nothing selected, no script error (the step fails on console errors)
  await page.goto(new URL(builderHref.replace(/#element=.*$/, '#element=nothing'), BASE).href);
  await page.reload({ waitUntil: 'networkidle' }); // a change of the hash alone is no new page load
  await canvas().locator('[data-ka-id]').first().waitFor({ timeout: 15000 });
  if (await canvas().locator('.ka-st-vybrany').count() !== 0) { throw new Error('an unknown #element id selected something'); }
  await page.waitForTimeout(1500); // the draft autosave settles before the next screen
  // Statistics: "Pages that bring leads" gets a small "edit" link into the page form next to the public one
  await visit('/admin.php?module=stats');
  const leadRow = page.locator('.stat-siroka tr', { hasText: '/contact' }).first();
  const editHref = await leadRow.locator('a', { hasText: 'edit' }).getAttribute('href');
  if (!editHref || !editHref.includes(`module=pages&action=edit&id=${pageId}`)) { throw new Error(`the lead row has no edit link to the page: ${editHref}`); }
  await visitor.close();
  sql("DELETE FROM ka_poptavky WHERE email IN ('hannah@example.com', 'old@example.com')");
});

await step('3.20 (C5) a collection from a preset shows its listing page – hidden · Edit in the builder · Publish · Add to the menu – and Publish puts the page live', async () => {
  await visit('/admin.php?module=collections');
  await Promise.all([page.waitForNavigation(), page.locator('button[name="preset"][value="people"]').click()]);
  try {
  const strip = page.locator('[data-listing-page]');
  const text = ((await strip.textContent()) || '').replace(/\s+/g, ' ').trim();
  if (!/^Listing page: \/team – hidden · Edit in the builder · Publish · Add to the menu$/.test(text)) { throw new Error(`the listing page line reads: ${text}`); }
  if (!(await strip.locator('a', { hasText: 'Edit in the builder' }).getAttribute('href')).includes('module=pages&action=builder&id=')) { throw new Error('Edit in the builder does not open the page builder'); }
  if (!(await strip.locator('a', { hasText: 'Add to the menu' }).getAttribute('href')).includes('module=menu')) { throw new Error('Add to the menu does not open the Menu module'); }
  if (!/publish the page from the “Listing page” line below/.test((await page.locator('.hlaska').first().textContent()) || '')) { throw new Error('the creation message does not point to the listing page line'); }
  const empty = (await page.locator('.prazdny-stav').textContent()) || '';
  if (!/the page \/team lists the items/.test(empty) || /Collection list element/.test(empty)) { throw new Error(`the empty state contradicts the listing page: ${empty.trim()}`); }
  if (SHOTS) { await page.screenshot({ path: `${SHOTS}/collection-listing-page.png`, fullPage: false }); }
  const visitor = await browser.newContext();
  if ((await visitor.request.get(`${BASE}/team`)).status() !== 404) { throw new Error('the hidden listing page is live before Publish'); }
  await Promise.all([page.waitForNavigation(), strip.locator('button', { hasText: 'Publish' }).click()]);
  const after = ((await page.locator('[data-listing-page]').textContent()) || '').replace(/\s+/g, ' ');
  if (!/\/team – published · Edit in the builder · Add to the menu/.test(after)) { throw new Error(`after Publish the line reads: ${after.trim()}`); }
  if ((await visitor.request.get(`${BASE}/team`)).status() !== 200) { throw new Error('the listing page is not live after Publish'); }
  await visitor.close();
  } finally {
    // tidy up: the later steps start on a site without collections (the ready-made ones are offered unfolded)
    sql("DELETE FROM ka_kolekce WHERE seo_link = 'team'; DELETE FROM ka_stranky WHERE seo_link = 'team' AND jazyk = ''");
  }
});

await step('3.20 (C19, C20) the news form always names the category, with Manage categories; Broken links sits under Site care next to Redirects and opens the same screen', async () => {
  const categories = Number(sql('SELECT COUNT(*) FROM ka_kategorie').trim());
  await visit('/admin.php?module=news&action=new');
  if (categories < 2) {
    const shown = ((await page.locator('[data-category]').textContent()) || '').trim();
    if (!/\S/.test(shown) || await page.locator('input[type="hidden"][name="tema"]').count() !== 1) { throw new Error(`one category, yet the form does not name it: "${shown}"`); }
  } else if (await page.locator('select#tema').count() !== 1) { throw new Error('two or more categories, yet no category select'); }
  if (!(await page.locator('fieldset a', { hasText: 'Manage categories' }).first().getAttribute('href')).includes('module=categories')) { throw new Error('Manage categories does not open Categories'); }
  await visit('/admin.php?module=broken_links');
  if ((await page.locator('h1').textContent()) !== 'Broken links') { throw new Error('module=broken_links does not open the Broken links screen'); }
  // 3.25: a group is a <details> – its summary names it, its items follow in .menu-cast
  const items = await page.locator('.menu summary, .menu li:not(.menu-sekce)').evaluateAll((list) => list.map((li) => li.textContent.replace(/\s+/g, ' ').trim()));
  const at = items.indexOf('Broken links');
  // right after Redirects (which may carry a badge), inside the Site care group
  if (at < 1 || !items[at - 1].startsWith('Redirects') || items.lastIndexOf('Site care', at) < 0 || items.slice(items.lastIndexOf('Site care', at), at).some((x) => x === 'Content')) { throw new Error(`the menu order is ${items.join(' | ')}`); }
  if (!/Broken links/.test((await page.locator('.menu li.aktivni').textContent()) || '')) { throw new Error('the menu does not highlight Broken links'); }
  if (await page.locator('a', { hasText: 'Back to the news list' }).count() !== 0) { throw new Error('opened from Site care, the screen still says back to news'); }
  // the News shortcut still opens the same screen, there with its back link
  await visit('/admin.php?module=news');
  await Promise.all([page.waitForNavigation(), page.locator('.navigace-radek a', { hasText: 'Broken links' }).click()]);
  if ((await page.locator('h1').textContent()) !== 'Broken links' || await page.locator('a', { hasText: 'Back to the news list' }).count() !== 1) { throw new Error('News → Broken links lost its shortcut or back link'); }
  // the following steps continue in the builder of the home page, where the step before these left it
  await visit('/admin.php?module=pages&action=builder&id=1');
  await canvas().locator('[data-ka-id]').first().waitFor({ timeout: 15000 });
});

await step('3.18.1 builder dialogs: readable buttons when the admin theme differs from the system (light on dark, dark on light)', async () => {
  // the dialogs live on <body>, outside .st: with the system dark and the admin switched to light, Close was white on white
  for (const [system, admin] of [['dark', 'svetly'], ['light', 'tmavy']]) {
    await page.emulateMedia({ colorScheme: system });
    await page.evaluate((t) => document.documentElement.setAttribute('data-tema', t), admin);
    await page.evaluate(() => document.querySelector('#st-lista-vice button[title="Draft history and published versions"]').click()); // an item of the ⋯ menu
    const dialog = page.locator('dialog.st-dialog[open]');
    await dialog.waitFor();
    const bad = await dialog.locator('button').evaluateAll((list) => list.map((b) => {
      const s = getComputedStyle(b);
      return s.color === s.backgroundColor || !/Bricolage/.test(s.fontFamily) ? `${b.textContent.trim()}: ${s.color} on ${s.backgroundColor}, ${s.fontFamily}` : '';
    }).filter(Boolean));
    await page.keyboard.press('Escape');
    if (bad.length) { throw new Error(`system ${system}, admin ${admin}: ${bad.join('; ')}`); }
  }
  await page.evaluate(() => document.documentElement.removeAttribute('data-tema'));
  await page.emulateMedia({ colorScheme: 'light' });
});

await step('builder: insert elements from the Add panel by click and by drag', async () => {
  // broken from 1.4.0 to 3.4.0: newElement() built the element under English keys the builder does not read (3.4.1)
  const count = () => canvas().locator('[data-ka-id]').count();
  // the canvas redraws after the autosave – on a slow CI runner that can take a few seconds, so wait for the change
  const grew = async (before) => { for (let i = 0; i < 25; i++) { if ((await count()) > before) { return true; } await page.waitForTimeout(200); } return false; };
  await page.locator('.st-zalozky [role="tab"]').first().click();
  await page.waitForTimeout(300);
  for (const name of ['Heading', 'Image', 'Button']) {
    const before = await count();
    await page.locator('.st-prvky button', { hasText: new RegExp(`^${name}$`) }).first().click();
    if (!(await grew(before))) { throw new Error(`clicking "${name}" in the Add panel inserted nothing`); }
    await page.locator('.st-zalozky [role="tab"]').first().click();
    await page.waitForTimeout(300);
  }
  const before = await count();
  await page.locator('.st-prvky button', { hasText: /^Text$/ }).first().dragTo(canvas().locator('h1').first());
  if (!(await grew(before))) { throw new Error('dragging "Text" from the Add panel onto the canvas inserted nothing'); }
});

// 3.20 (U11): the panel of a Form links to Enquiries and to the spam protection; the links come from D.adresy, so an
// administrator (who may open everything) sees both
await step('3.20 U11: the Form panel links to Enquiries and to the spam protection', async () => {
  await visit('/admin.php?module=pages&action=builder&id=4'); // Contact of the Business starter: an enquiry form
  await page.waitForTimeout(1500);
  await canvas().locator('form').first().click({ position: { x: 4, y: 4 } });
  await page.getByRole('tab', { name: 'Content' }).first().click().catch(() => {});
  const links = page.locator('.st-panel .st-odkazy-dat a');
  await links.first().waitFor();
  const hrefs = await links.evaluateAll((list) => list.map((a) => a.textContent.trim() + ' → ' + a.getAttribute('href')));
  if (!hrefs.some((h) => /^Open Enquiries → .*module=enquiries$/.test(h)) || !hrefs.some((h) => /^Spam protection → .*module=settings&tab=cookies$/.test(h))) { throw new Error(`the form panel links: ${hrefs.join('; ')}`); }
  if (SHOTS) { await page.locator('.st-pravy').screenshot({ path: `${SHOTS}/form-panel-data-links.png` }); }
  await visit('/admin.php?module=pages&action=builder&id=1');
  await page.waitForTimeout(1500);
});

// 3.20 (C4, C6, C22): menu paths are links in the module hints too, every "How to connect Claude" leads to one place,
// the Site audit points to the Notebook, the cookie policy hint links the page
await step('3.20 C4/C6/C22: hints link their menu paths, one "connect Claude" destination, Notebook from the audit', async () => {
  await visit('/admin.php?module=newsletters');
  const mail = page.locator('.hlaska-varovani a', { hasText: 'Settings → Mail' });
  if (!(await mail.count())) { throw new Error('the newsletters warning does not link Settings → Mail'); }
  // the links are read from the DOM (the appearance hint sits in a folded section, so a role query would not see it)
  const hrefs = async (text) => page.locator('a', { hasText: text }).evaluateAll((list) => list.map((a) => a.getAttribute('href')));
  for (const screen of ['appearance', 'extensions']) {
    await visit(`/admin.php?module=${screen}`);
    const found = await hrefs('How to connect Claude');
    if (!found.length || found.some((href) => !/module=claude_settings#how-to-connect$/.test(href))) { throw new Error(`${screen}: "How to connect Claude" leads to ${JSON.stringify(found)}`); }
  }
  await visit('/admin.php?module=claude_settings');
  if (!(await page.locator('#how-to-connect').count())) { throw new Error('Claude settings have no #how-to-connect'); }
  if (!(await hrefs('Create a personal token – My account')).some((href) => /action=account#claude$/.test(href))) { throw new Error('the Connections box does not lead to My account for a token'); }
  await visit('/admin.php?module=audit');
  if (!(await hrefs('Decisions written down in the Notebook')).some((href) => /module=notebook$/.test(href))) { throw new Error('the Site audit does not link the Notebook'); }
  // 3.23 (C17): one hand-over checklist – the progress says "x of y done" and matches the ticked rows; no folded client questions
  const handover = await page.evaluate(() => {
    const rows = [...document.querySelectorAll('.predani-seznam > li')];
    const progress = document.querySelector('progress.predani-postup');
    return { rows: rows.length, done: rows.filter((li) => li.classList.contains('ok')).length, max: Number(progress?.max), value: Number(progress?.value),
      label: document.querySelector('#predani-nadpis')?.textContent ?? '', folded: document.querySelectorAll('details.pokrocile summary').length,
      wide: document.documentElement.scrollWidth > document.documentElement.clientWidth };
  });
  if (handover.rows < 12 || handover.max !== handover.rows || handover.value !== handover.done || !handover.label.includes(`${handover.done} of ${handover.rows} done`) || handover.folded || handover.wide) {
    throw new Error(`the hand-over checklist: ${JSON.stringify(handover)}`);
  }
  await visit('/admin.php?module=settings&tab=cookies');
  const policy = await page.locator('#cookies_policy_url ~ .napoveda a').evaluateAll((list) => list.map((a) => a.getAttribute('href')));
  if (!policy.some((href) => /module=pages/.test(href))) { throw new Error(`the cookie policy hint does not lead to Pages: ${JSON.stringify(policy)}`); }
});

await step('builder: element tree and search', async () => {
  const search = page.locator('input.st-hledat').first();
  if (await search.count()) { await search.fill('text'); await page.waitForTimeout(400); await search.fill(''); }
  const tree = page.locator('.st-strom [role="treeitem"], .st-strom li').nth(1);
  if (await tree.count()) { await tree.click(); await page.keyboard.press('ArrowDown'); await page.keyboard.press('ArrowUp'); }
});

// 3.21 (U8, U16, U18, U24): the builder for people who are not designers – the element menu, the nesting path, moving by
// keyboard, instant canvas text and the Countdown date picker. The canvas is the live iframe (never the one still loading).
const liveCanvas = () => page.frameLocator('.st-platno iframe:not(.st-nacita)').first();
// the draft is saved and the canvas redrawn: nothing queued, nothing loading
const builderIdle = async () => {
  await page.waitForFunction(() => {
    const status = ((document.querySelector('.st-stav') || {}).textContent || '').trim();
    return !document.querySelector('.st-platno iframe.st-nacita') && (status === '' || /^(Draft saved|Saved, but with warnings|Published|Draft in progress)/.test(status));
  }, null, { timeout: 20000 });
  await page.waitForTimeout(200);
};
const menuOpen = () => page.evaluate(() => !!document.querySelector('#st-kontext:popover-open'));

await step('3.21 (U8) the element menu by right-click and Shift+F10, and the nesting path under the panel header', async () => {
  await visit('/admin.php?module=pages&action=builder&id=1');
  await liveCanvas().locator('h1').first().waitFor();
  await builderIdle();
  const elements = () => liveCanvas().locator('[data-ka-id]').count();
  await liveCanvas().locator('h1').first().click({ button: 'right' });
  const menu = page.locator('#st-kontext[role="menu"]');
  await menu.waitFor();
  const items = await menu.locator('[role="menuitem"]').evaluateAll((list) => list.map((b) => b.querySelector('span').textContent + (b.getAttribute('aria-disabled') ? ' (disabled)' : '')));
  const names = items.map((t) => t.replace(' (disabled)', ''));
  if (JSON.stringify(names) !== JSON.stringify(['Duplicate', 'Delete', 'Copy', 'Paste', 'Copy style', 'Paste style', 'Save as component', 'Select parent element'])) { throw new Error(`the element menu offers ${JSON.stringify(items)}`); }
  if (items[7].includes('disabled')) { throw new Error('Select parent element is disabled on a nested heading'); }
  if ((await page.evaluate(() => document.activeElement && document.activeElement.getAttribute('role'))) !== 'menuitem') { throw new Error('the menu does not take the focus'); }
  await page.keyboard.press('ArrowDown');
  if ((await page.evaluate(() => document.activeElement.textContent)) !== 'DeleteDelete') { throw new Error('ArrowDown does not move to the second item'); }
  if (SHOTS) { await page.screenshot({ path: `${SHOTS}/321-element-menu.png` }); }
  await page.keyboard.press('Escape');
  if (await menuOpen()) { throw new Error('Escape does not close the element menu'); }
  // Duplicate adds an element (then Ctrl+Z takes it back)
  const before = await elements();
  await liveCanvas().locator('h1').first().click({ button: 'right' });
  await menu.locator('[role="menuitem"]', { hasText: 'Duplicate' }).click();
  await page.waitForFunction((n) => { const f = document.querySelector('.st-platno iframe:not(.st-nacita)'); return f && f.contentDocument && f.contentDocument.querySelectorAll('[data-ka-id]').length > n; }, before, { timeout: 15000 });
  if (await menuOpen()) { throw new Error('the menu stays open after Duplicate'); }
  await builderIdle();
  await page.locator('body').press('ControlOrMeta+z');
  await page.waitForFunction((n) => { const f = document.querySelector('.st-platno iframe:not(.st-nacita)'); return f && f.contentDocument && f.contentDocument.querySelectorAll('[data-ka-id]').length === n; }, before, { timeout: 15000 });
  await builderIdle();
  // a click outside closes it
  await liveCanvas().locator('h1').first().click({ button: 'right' });
  await menu.waitFor();
  await page.locator('.st-pravy').click({ position: { x: 10, y: 300 } });
  if (await menuOpen()) { throw new Error('a click outside does not close the element menu'); }
  // the nesting path: every ancestor a button that selects it, the element itself plain text
  await liveCanvas().locator('h1').first().click();
  const path = page.locator('.st-pravy nav.st-cesta');
  await path.waitFor();
  const crumbs = await path.locator('li > *').evaluateAll((list) => list.map((x) => x.tagName + ':' + x.textContent));
  if (crumbs.length < 2 || !crumbs[crumbs.length - 1].startsWith('SPAN:Heading') || !crumbs.slice(0, -1).every((c) => c.startsWith('BUTTON:'))) { throw new Error(`the nesting path is ${JSON.stringify(crumbs)}`); }
  const wide = await page.locator('.st-pravy').evaluate((p) => p.scrollWidth - p.clientWidth);
  if (wide > 1) { throw new Error(`the nesting path widens the panel by ${wide} px`); }
  if (SHOTS) { await page.locator('.st-pravy').screenshot({ path: `${SHOTS}/321-nesting-path.png` }); }
  await path.locator('li > button').first().click();
  if ((await page.locator('.st-hlava-prvku strong').textContent()).trim() !== 'Section') { throw new Error('the first crumb does not select the section'); }
  if (await page.locator('.st-pravy nav.st-cesta').count()) { throw new Error('a top-level section shows a nesting path'); }
  if (await page.locator('.st-pravy').evaluate((p) => Array.from(p.childNodes).some((n) => n.nodeType === 3 && n.textContent.trim() === 'null'))) { throw new Error('the panel shows the text "null"'); }
  // Structure: Shift+F10 on a focused row opens the menu, Escape gives the focus back to the row; right-click on a row too
  await page.locator('.st-levy .st-zalozky [role="tab"]', { hasText: 'Structure' }).click();
  const row = page.locator('.st-strom [role="treeitem"]').nth(1);
  await row.focus();
  await page.keyboard.press('Shift+F10');
  await menu.waitFor();
  if (!(await menuOpen())) { throw new Error('Shift+F10 on a Structure row opens no menu'); }
  await page.keyboard.press('Escape');
  if ((await page.evaluate(() => document.activeElement && document.activeElement.getAttribute('role'))) !== 'treeitem') { throw new Error('Escape does not give the focus back to the Structure row'); }
  await row.click({ button: 'right' });
  if (!(await menuOpen())) { throw new Error('right-click on a Structure row opens no menu'); }
  await page.keyboard.press('Escape');
  await page.locator('.st-levy .st-zalozky [role="tab"]').first().click();
});

await step('3.21 (U16, U18) Alt+arrows move the element, typing in Content reaches the canvas within 150 ms', async () => {
  await builderIdle();
  // a leaf element with a next sibling: Alt+ArrowDown puts it after that sibling, Alt+ArrowUp back
  const id = await liveCanvas().locator('body').evaluate((b) => {
    const leaf = Array.from(b.querySelectorAll('[data-ka-id]:not([data-ka-zamek])')).find((x) => !x.querySelector('[data-ka-id]') && x.nextElementSibling && x.nextElementSibling.hasAttribute('data-ka-id') && x.getBoundingClientRect().height > 8);
    return leaf ? leaf.getAttribute('data-ka-id') : '';
  });
  if (!id) { throw new Error('no element with a next sibling on the canvas'); }
  const position = () => page.evaluate((i) => {
    const f = document.querySelector('.st-platno iframe:not(.st-nacita)');
    const x = f && f.contentDocument.querySelector(`[data-ka-id="${i}"]`);
    return x ? Array.from(x.parentElement.children).filter((c) => c.hasAttribute('data-ka-id')).indexOf(x) : -1;
  }, id);
  const start = await position();
  await liveCanvas().locator(`[data-ka-id="${id}"]`).click({ position: { x: 4, y: 4 } });
  await page.keyboard.press('Alt+ArrowDown');
  await page.waitForFunction(([i, p]) => { const f = document.querySelector('.st-platno iframe:not(.st-nacita)'); const x = f && f.contentDocument.querySelector(`[data-ka-id="${i}"]`); return x && Array.from(x.parentElement.children).filter((c) => c.hasAttribute('data-ka-id')).indexOf(x) === p + 1; }, [id, start], { timeout: 15000 });
  await builderIdle();
  await liveCanvas().locator(`[data-ka-id="${id}"]`).click({ position: { x: 4, y: 4 } }); // the canvas was redrawn: the keys go to the new one
  await page.keyboard.press('Alt+ArrowUp');
  await page.waitForFunction(([i, p]) => { const f = document.querySelector('.st-platno iframe:not(.st-nacita)'); const x = f && f.contentDocument.querySelector(`[data-ka-id="${i}"]`); return x && Array.from(x.parentElement.children).filter((c) => c.hasAttribute('data-ka-id')).indexOf(x) === p; }, [id, start], { timeout: 15000 });
  await builderIdle();
  const help = async () => { await page.locator('body').press('?'); const d = page.locator('dialog[open] .st-zkratky'); await d.waitFor(); const t = await d.textContent(); await page.keyboard.press('Escape'); return t; };
  const shortcuts = await help();
  if (!/Alt\+↑ \/ Alt\+↓|⌥\+↑/.test(shortcuts) || !shortcuts.includes('Shift+F10')) { throw new Error('the keyboard shortcuts do not list Alt+arrows and Shift+F10'); }
  // U16: the audit measured 836 ms from a key to the canvas (the redraw after the save); the text is on the canvas at once now
  await liveCanvas().locator('h1').first().click();
  await page.getByRole('tab', { name: 'Content' }).first().click();
  const input = page.locator('.st-panel [data-pole="text"] input').first();
  await input.click();
  await input.press('End');
  const h1 = await liveCanvas().locator('h1').first().getAttribute('data-ka-id');
  await page.evaluate(() => { window.u16Start = performance.now(); });
  await page.keyboard.type('Q');
  const ms = await page.waitForFunction((i) => {
    const f = document.querySelector('.st-platno iframe:not(.st-nacita)');
    const x = f && f.contentDocument.querySelector(`[data-ka-id="${i}"]`);
    return x && x.textContent.endsWith('Q') ? performance.now() - window.u16Start : false;
  }, h1, { polling: 'raf', timeout: 5000 }).then((r) => r.jsonValue());
  console.log(`         U16: keystroke to canvas ${Math.round(ms)} ms`);
  if (ms > 150) { throw new Error(`typing reached the canvas after ${Math.round(ms)} ms`); }
  await page.keyboard.press('Backspace');
  await builderIdle();
});

await step('3.21 (U24) the Countdown has a date-and-time picker and saves "YYYY-MM-DD HH:MM"', async () => {
  await page.locator('.st-levy .st-zalozky [role="tab"]').first().click();
  await page.locator('.st-prvky button', { hasText: /^Countdown$/ }).first().click();
  const picker = page.locator('.st-panel input[type="datetime-local"]');
  await picker.waitFor();
  if (!/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(await picker.inputValue())) { throw new Error(`the new Countdown shows "${await picker.inputValue()}"`); }
  if (SHOTS) { await page.locator('.st-pravy').screenshot({ path: `${SHOTS}/321-countdown-picker.png` }); }
  await picker.fill('2031-05-06T07:08');
  await builderIdle();
  const draft = sql('SELECT stavba_koncept FROM ka_stranky WHERE ids = 1');
  if (!draft.includes('"cil":"2031-05-06 07:08"')) { throw new Error('the draft does not keep the date as "YYYY-MM-DD HH:MM"'); }
  // the picker shows the stored value after a reload, then the Countdown goes again
  await visit('/admin.php?module=pages&action=builder&id=1');
  await liveCanvas().locator('.ka-odpocet').first().waitFor();
  await builderIdle();
  await liveCanvas().locator('.ka-odpocet').first().click({ position: { x: 4, y: 4 } });
  if ((await picker.inputValue()) !== '2031-05-06T07:08') { throw new Error(`after a reload the picker shows "${await picker.inputValue()}"`); }
  await page.locator('body').press('Delete');
  await liveCanvas().locator('.ka-odpocet').waitFor({ state: 'detached', timeout: 15000 });
  await builderIdle();
});

await step('3.12 UX310-18: the Form inspector – a row per field, a contact form edited without the condition fields, a condition by keyboard', async () => {
  const selectForm = async () => {
    await visit('/admin.php?module=pages&action=builder&id=4'); // Contact of the Business starter: an enquiry form
    await page.waitForTimeout(1500);
    await canvas().locator('form').first().click({ position: { x: 4, y: 4 } });
    await page.getByRole('tab', { name: 'Content' }).first().click().catch(() => {});
    await page.locator('.st-panel details.st-polozka-radek').first().waitFor();
    return page.locator('.st-panel details.st-polozka-radek');
  };
  let rows = await selectForm();
  if ((await rows.count()) < 3) { throw new Error(`the form shows ${await rows.count()} field rows`); }
  if (await page.locator('.st-panel details.st-polozka-radek[open]').count()) { throw new Error('a field row is open before it is opened'); }
  if (await page.locator('.st-panel .st-podminka').count()) { throw new Error('the condition fields show without asking for them'); }
  const summary = (await rows.first().locator('summary').textContent()).trim();
  if (!/ · /.test(summary)) { throw new Error(`the first row says "${summary}", not "label · type · …"`); }
  const wider = await page.locator('.st-panel').first().evaluate((panel) => panel.scrollWidth - panel.clientWidth);
  if (wider > 1) { throw new Error(`a long field label widens the inspector by ${wider} px`); }
  if (SHOTS) { await page.locator('.st-pravy').screenshot({ path: `${SHOTS}/form-inspector-rows.png` }); }
  // the keyboard opens a row (Enter on its summary) and Tab goes on to its label box
  await rows.first().locator('summary').focus();
  await page.keyboard.press('Enter');
  if (!(await rows.first().evaluate((d) => d.open))) { throw new Error('Enter does not open a field row'); }
  await page.keyboard.press('Tab');
  if (await page.evaluate(() => (document.activeElement && document.activeElement.closest('.st-polozka-radek') ? document.activeElement.tagName : '')) !== 'INPUT') { throw new Error('Tab from the row summary does not reach its label'); }
  await page.keyboard.press('ControlOrMeta+A');
  await page.keyboard.type('Your name');
  if (!/^Your name · /.test((await rows.first().locator('summary').textContent()).trim())) { throw new Error('the row does not follow the label'); }
  if (await rows.first().locator('.st-podminka').count()) { throw new Error('the condition opened by itself'); }
  // the condition is still there: "Add a condition" by keyboard moves the focus into it – after the autosave of the label
  // has come back (on a slow runner its redraw of the canvas and the panel could land between the key and the check)
  await page.waitForTimeout(2500);
  await rows.first().locator('button.st-pridat-podminku').focus();
  await page.keyboard.press('Enter');
  // a slow runner may redraw the panel once more (a check coming back from the server) – the focus must survive it
  await page.waitForTimeout(600);
  if (!(await page.waitForFunction(() => !!(document.activeElement && document.activeElement.closest('.st-podminka')), null, { timeout: 3000 }).then(() => true, () => false))) { throw new Error('Add a condition does not move the focus into the condition'); }
  await page.keyboard.type('Email');
  await page.keyboard.press('Tab');
  await page.keyboard.type('*');
  await page.waitForTimeout(400);
  if (SHOTS) { await page.locator('.st-pravy').screenshot({ path: `${SHOTS}/form-inspector-condition.png` }); }
  await page.waitForTimeout(2500); // autosave of the draft
  rows = await selectForm();
  const saved = (await rows.first().locator('summary').textContent()).trim();
  if (!/^Your name · .* · with a condition$/.test(saved)) { throw new Error(`after a reload the first row says "${saved}"`); }
  // the saved build keeps its keys: remove the condition and the label again, then the row is as it was
  await rows.first().locator('summary').click();
  await rows.first().getByRole('button', { name: 'Remove the condition' }).click();
  await page.waitForTimeout(300);
  await page.locator('.st-panel details.st-polozka-radek').first().locator('input[type="text"]').first().fill(summary.split(' · ')[0]);
  await page.waitForTimeout(2500);
});

await step('3.6 UXA-09: the draft look bar is one line; "Save and publish menu" publishes the menu, the colours keep waiting', async () => {
  await visit('/admin.php?module=appearance');
  await page.evaluate(() => { document.querySelectorAll('[name="ds[barvy][primarni]"]').forEach((i) => { i.value = '#225588'; }); });
  await Promise.all([page.waitForNavigation(), page.locator('.vzhled-ulozit input[type="submit"]').click()]);
  await visit('/admin.php?module=menu');
  await Promise.all([page.waitForNavigation(), page.locator('form[data-menu] button[name="publikovat"]').click()]);
  const bar = page.locator('#vzhled-koncept');
  await bar.waitFor();
  const areas = await bar.locator('summary').textContent();
  if (!/colours/.test(areas) || /menu/.test(areas)) { throw new Error(`the bar should list only the colours after publishing the menu, it says "${areas.trim()}"`); }
  const tall = (await bar.boundingBox()).height;
  await page.setViewportSize({ width: 390, height: 844 });
  await visit('/admin.php?module=pages');
  const phone = (await page.locator('#vzhled-koncept').boundingBox()).height;
  if (SHOTS) { await page.screenshot({ path: `${SHOTS}/look-bar-phone.png` }); }
  await page.setViewportSize({ width: 1440, height: 900 });
  if (tall > 70 || phone > 120) { throw new Error(`the draft look bar is ${tall} px tall on a desktop and ${phone} px on a phone`); }
  await page.locator('#vzhled-koncept summary').click();
  if (!(await page.locator('#vzhled-koncept details[open] button.nebezpecne').isVisible())) { throw new Error('the details of the draft look do not open'); }
  await Promise.all([page.waitForNavigation(), page.locator('#vzhled-koncept button.tl').click()]);
});

await step('3.6 UXA-14, UXA-11, UXA-12: typing on the canvas updates the inspector; a one-line top bar; header options only in the header', async () => {
  await visit('/admin.php?module=pages&action=builder&id=1');
  await page.waitForTimeout(1500);
  await canvas().locator('h1').first().dblclick();
  await page.keyboard.type('Zq');
  await page.waitForTimeout(200);
  const typed = await page.locator('.st-panel [data-pole="text"] input').inputValue();
  if (!typed.includes('Zq')) { throw new Error(`the inspector still shows "${typed}" while typing on the canvas`); }
  if (!(await page.locator('.st-lista .st-cip-koncept').count())) { throw new Error('the top bar does not show the draft while typing on the canvas'); }
  await page.keyboard.press('Enter');
  await page.waitForTimeout(1200);
  const top = (await page.locator('.st-lista').boundingBox()).height;
  if (top > 60) { throw new Error(`the builder top bar with a draft is ${top} px tall at 1440 px`); }
  await page.locator('.st-lista .st-lista-vice').click();
  if (!(await page.locator('#st-lista-vice button', { hasText: 'Versions' }).isVisible())) { throw new Error('Versions are not in the ⋯ menu'); }
  await page.keyboard.press('Escape');
  await canvas().locator('section').first().click({ position: { x: 5, y: 5 } });
  await page.waitForTimeout(400);
  if (/Header on scroll/.test(await page.locator('.st-pravy').textContent())) { throw new Error('a page section offers the header-only options'); }
  await visit('/admin.php?module=parts&action=builder&typ=hlavicka&jazyk=');
  await page.waitForTimeout(1500);
  await canvas().locator('[data-ka-id]').first().click({ position: { x: 3, y: 3 } });
  await page.waitForTimeout(400);
  if (!/Header on scroll/.test(await page.locator('.st-pravy').textContent())) { throw new Error('the header part does not offer the header options'); }
});

// 3.18.2 N3182-2: a clipboard envelope used to be inserted without the server when it merely claimed this site, and the layer
// tree parsed its HTML in the admin page; now every envelope goes through the server and code stays only in this site's signed copy
await step('3.18.2 builder: a pasted envelope claiming this site runs nothing; the own copy (Ctrl+C, Ctrl+V) still pastes (N3182-2)', async () => {
  await visit('/admin.php?module=pages&action=builder&id=1');
  await page.waitForTimeout(1500);
  const key = (k) => page.evaluate((x) => document.body.dispatchEvent(new KeyboardEvent('keydown', { key: x, ctrlKey: true, bubbles: true, cancelable: true })), k);
  const paste = (text) => page.evaluate(async (t) => {
    const status = () => document.querySelector('.st-stav');
    status().textContent = '';
    const data = new DataTransfer();
    data.setData('text/plain', t);
    document.body.dispatchEvent(new ClipboardEvent('paste', { clipboardData: data, bubbles: true, cancelable: true }));
    for (let i = 0; i < 50 && !/Inserted|could not|None of/.test(status().textContent); i++) { await new Promise((r) => setTimeout(r, 100)); }
    return { text: status().textContent, error: status().classList.contains('chyba') };
  }, text);
  await page.evaluate(() => { window.__clipRan = 0; });
  const claim = JSON.stringify({ kaleta: 'elements', v: 1, site: new URL(BASE).origin, elements: [{ typ: 'text', obsah: { html: '<p>Clip 3182<img src="/media/missing-3182.png" alt="" onerror="window.__clipRan = 1"></p>' } }] });
  const claimed = await paste(claim);
  if (!/^Inserted/.test(claimed.text) || claimed.error) { throw new Error(`the envelope claiming this site was not inserted through the server: ${JSON.stringify(claimed)}`); }
  await canvas().locator('.ka-text', { hasText: 'Clip 3182' }).first().waitFor({ timeout: 8000 });
  const inserted = await canvas().locator('.ka-text', { hasText: 'Clip 3182' }).first().innerHTML();
  await page.waitForTimeout(500);
  if (await page.evaluate(() => window.__clipRan)) { throw new Error('the pasted envelope ran code in the admin'); }
  if (/onerror/i.test(inserted)) { throw new Error(`the server kept the handler: ${inserted}`); }
  await key('z');
  await page.waitForTimeout(800);
  // the administrator's own copy: Ctrl+C writes this site's signed envelope (captured here instead of the system clipboard)
  await canvas().locator('h1').first().click();
  await page.waitForTimeout(400);
  const copied = await page.evaluate(async () => {
    let captured = null;
    Object.defineProperty(navigator.clipboard, 'write', { configurable: true, value: async (items) => { captured = await (await items[0].getType('text/plain')).text(); } });
    Object.defineProperty(navigator.clipboard, 'writeText', { configurable: true, value: async (t) => { captured = t; } });
    document.body.dispatchEvent(new KeyboardEvent('keydown', { key: 'c', ctrlKey: true, bubbles: true, cancelable: true }));
    for (let i = 0; i < 50 && captured === null; i++) { await new Promise((r) => setTimeout(r, 100)); }
    return captured;
  });
  const envelope = JSON.parse(copied || '{}');
  if (!envelope.sig || envelope.site !== new URL(BASE).origin || envelope.elements?.[0]?.typ !== 'nadpis') { throw new Error(`Ctrl+C did not write a signed envelope of the heading: ${String(copied).slice(0, 200)}`); }
  const headings = await canvas().locator('h1').count();
  const own = await paste(copied);
  if (own.text !== 'Inserted' || own.error) { throw new Error(`the own copy did not paste: ${JSON.stringify(own)}`); }
  await page.waitForTimeout(1500);
  if ((await canvas().locator('h1').count()) !== headings + 1) { throw new Error('the pasted heading is not on the canvas'); }
  await key('z');
  await page.waitForTimeout(800);
});

// 3.18.2 N3182-1 (CodeQL #42): the inspector shows what is typed on the canvas through the editor's cleanup, as nodes
await step('3.18.2 builder inspector: text typed on the canvas is shown cleaned – the iframe bypass runs nothing (N3182-1)', async () => {
  await visit('/admin.php?module=pages&action=builder&id=1');
  await page.waitForTimeout(1500);
  const text = canvas().locator('.ka-text').first();
  await text.dblclick();
  const field = page.locator('.st-panel [data-pole="html"] .editor-plocha');
  await field.waitFor({ timeout: 5000 });
  await page.evaluate(() => { window.__inspRan = 0; });
  await text.evaluate((node) => {
    node.innerHTML = '<p>Typed 3182</p><iframe></ifr<p></p>ame><img src="/media/missing-3182.png" alt="" onerror="top.__inspRan = 1"></iframe>';
    node.dispatchEvent(new Event('input', { bubbles: true }));
  });
  await page.waitForTimeout(800);
  const shown = await field.innerHTML();
  if (await page.evaluate(() => window.__inspRan)) { throw new Error('the inspector ran the typed HTML'); }
  if (!/Typed 3182/.test(shown) || /<iframe|onerror/i.test(shown)) { throw new Error(`the inspector shows ${shown}`); }
  // ending the edit stores the value and draws the inspector's editor again from it – still nothing runs
  await text.evaluate((node) => node.blur());
  await page.waitForTimeout(1500);
  if (await page.evaluate(() => window.__inspRan)) { throw new Error('the redrawn inspector ran the typed HTML'); }
  await page.evaluate(() => document.body.dispatchEvent(new KeyboardEvent('keydown', { key: 'z', ctrlKey: true, bubbles: true, cancelable: true })));
  await page.waitForTimeout(800);
});

// 3.22 (U6, U7): the builder's Text has a numbered list and a quote (no subheadings – the Heading element has them), and its
// link dialog is the same picker, with the anchors of the page from the builder
await step('3.22 (U6, U7) builder Text: numbered list and quote, no H2; the link dialog suggests pages there too', async () => {
  await visit('/admin.php?module=pages&action=builder&id=1');
  await page.waitForTimeout(1500);
  await canvas().locator('.ka-text').first().dblclick();
  const tools = page.locator('.st-panel [data-pole="html"] .editor-nastroje');
  await tools.waitFor({ timeout: 5000 });
  const titles = await tools.locator('button').evaluateAll((all) => all.map((b) => b.textContent));
  if (!titles.includes('1. list') || !titles.includes('“quote”') || titles.includes('H2') || titles.includes('H3')) { throw new Error(`toolbar: ${titles.join(', ')}`); }
  if (!(await page.evaluate(() => Array.isArray(window.kaletaKotvy && window.kaletaKotvy())))) { throw new Error('the builder does not give the editor its anchors'); }
  const field = page.locator('.st-panel [data-pole="html"] .editor-plocha');
  await field.click();
  await page.keyboard.press('End');
  await page.keyboard.press('Shift+ArrowLeft');
  await page.keyboard.press('Control+k');
  const dialog = page.locator('dialog[open].odkaz-okno');
  await dialog.locator('[name=adresa]').fill('Conta');
  await dialog.locator('[role=option]', { hasText: 'Contact' }).first().waitFor({ timeout: 5000 });
  await page.keyboard.press('Escape');
  await page.keyboard.press('Escape');
  await page.waitForTimeout(300);
  if (await page.locator('dialog[open].odkaz-okno').count()) { throw new Error('the link dialog stayed open'); }
});

await step('3.6 UXA-10, UXA-13: a ready section below the first gets an H2, "Fix it" in the check, the image field and the media picker', async () => {
  await visit('/admin.php?module=pages&action=builder&id=1');
  await page.waitForTimeout(1500);
  await canvas().locator('section').first().click({ position: { x: 5, y: 5 } });
  await page.locator('.st-zalozky [role="tab"]').first().click();
  await page.locator('.st-knihovna button', { hasText: 'Hero with image' }).first().click();
  await page.waitForTimeout(1500);
  const hero = canvas().locator('section').nth(1);
  if (await hero.locator('h1').count() || !(await hero.locator('h2').count())) { throw new Error('the ready-made section below the first one kept its H1'); }
  await hero.locator('h2').first().click();
  await page.locator('.st-uroven button', { hasText: /^H1$/ }).click();
  await page.waitForTimeout(800);
  await page.locator('.st-lista .st-tl-hlavni').click();
  const fix = page.locator('dialog[open] button', { hasText: 'Fix it' });
  await fix.waitFor();
  await fix.click();
  if (/more than one main heading/.test(await page.locator('dialog[open]').textContent())) { throw new Error('"Fix it" left the second H1'); }
  await page.locator('dialog[open] button', { hasText: 'Back to editing' }).click();
  await hero.getByText('Choose an image').click();
  await page.waitForTimeout(400);
  if (await page.locator('.st-panel [data-pole="priorita"] select').inputValue() !== '') { throw new Error('the image of a ready-made section does not load automatically'); }
  await page.locator('.st-obrazek-vybrat').click();
  const picker = page.locator('dialog.galerie-okno[open]');
  await picker.waitFor();
  await page.waitForTimeout(500);
  const png = Buffer.from((await page.evaluate(() => { const c = document.createElement('canvas'); c.width = 64; c.height = 48; const g = c.getContext('2d'); g.fillStyle = '#3366aa'; g.fillRect(0, 0, 64, 48); return c.toDataURL('image/png'); })).split(',')[1], 'base64');
  await picker.locator('input[type=file]').first().setInputFiles({ name: 'team-photo.png', mimeType: 'image/png', buffer: png });
  // 3.12 (UX310-24): one uploaded image is the choice – the picker closes and the field shows it, no second click
  await page.locator('.st-obrazek-pole img.st-obrazek-nahled:visible').waitFor();
  if (await picker.count()) { throw new Error('the picker stays open after uploading the one image to choose'); }
  await page.locator('.st-obrazek-vybrat').click();
  await picker.locator('.galerie-polozka', { hasText: 'team-photo.png' }).waitFor();
  if (/No images here yet/.test(await picker.locator('.galerie-mrizka').textContent())) { throw new Error('"No images here yet." stays next to the uploaded image'); }
  await picker.locator('.galerie-polozka').first().click();
  await page.locator('.st-obrazek-pole img.st-obrazek-nahled:visible').waitFor();
  await page.locator('.st-obrazek-odebrat').click();
  if (await page.locator('.st-obrazek-pole img.st-obrazek-nahled:visible').count()) { throw new Error('Remove left the image in the field'); }
});

await step('builder: site header', async () => {
  await visit('/admin.php?module=parts&action=builder&type=hlavicka&language=');
  await page.waitForTimeout(1500);
  await canvas().locator('nav, header').first().click().catch(() => {});
});

await step('news editor: type and format', async () => {
  await visit('/admin.php?module=news&action=new');
  await page.fill('input[name="titulek"]', 'Browser test');
  const editor = page.locator('[contenteditable="true"]').first();
  if (await editor.count()) {
    await editor.click();
    await page.keyboard.type('Some text for the browser test.');
    await page.keyboard.press('Control+A');
    const bold = page.locator('button[data-prikaz="bold"], button[title*="Bold"]').first();
    if (await bold.count()) { await bold.click(); }
  }
});

await step('3.18.2 news editor: pasted HTML is cleaned without running anything (CodeQL #32)', async () => {
  // before 3.18.2 the paste cleanup parsed the HTML in the admin page itself: an <img onerror> ran before it was removed
  inertHandlers = true;
  try { await pasteChecks(); } finally { await page.waitForTimeout(300); inertHandlers = false; }
});
async function pasteChecks() {
  const editor = page.locator('[contenteditable="true"]').first();
  await editor.click();
  const result = await editor.evaluate(async (surface) => {
    window.__pasteRan = 0;
    const data = new DataTransfer();
    data.setData('text/html', '<p>Pasted <mark>word</mark></p><img src="/media/missing-3182.png" onerror="window.__pasteRan = 1"><a href="java&#9;script:window.__pasteRan=2">link</a>');
    surface.dispatchEvent(new ClipboardEvent('paste', { clipboardData: data, bubbles: true, cancelable: true }));
    await new Promise((r) => setTimeout(r, 600));
    return { ran: window.__pasteRan, html: surface.innerHTML };
  });
  if (result.ran) { throw new Error(`the pasted HTML ran code (${result.ran})`); }
  if (/onerror|script:/i.test(result.html)) { throw new Error(`the pasted HTML kept a handler or script link: ${result.html}`); }
  if (!/<mark>word<\/mark>/.test(result.html)) { throw new Error(`the highlighted word was lost: ${result.html}`); }
  // N3182-1: the frame's raw text held "</ifr<p></p>ame>"; the regular expression removing empty paragraphs joined it into a
  // real </iframe>, and the <img onerror> after it ran when the cleaned string was parsed again (also with <p><br></p>)
  const bypass = await editor.evaluate(async (surface) => {
    const payloads = [
      '<iframe></ifr<p></p>ame><img src="/media/missing-3182.png" alt="" onerror="window.__pasteRan = 3"></iframe>',
      '<iframe></ifr<p><br></p>ame><img src="/media/missing-3182.png" alt="" onerror="window.__pasteRan = 4"></iframe>',
      '<p>a</p><noscript><p title="</noscript><img src=/media/missing-3182.png alt=&quot;&quot; onerror=window.__pasteRan=5>"></p></noscript>',
      '<xmp></xmp><img src=/media/missing-3182.png alt="" onerror=window.__pasteRan=6></xmp><p>b&nbsp;c</p><p>&nbsp;</p>',
      '<iframe src="https://frames.example/x"></iframe><a href="https://e.example/1" target="_top" rel="opener">one</a> <a href="https://e.example/2" target="_blank" rel="opener nofollow">two</a>',
    ];
    for (const html of payloads) {
      const data = new DataTransfer();
      data.setData('text/html', html);
      surface.dispatchEvent(new ClipboardEvent('paste', { clipboardData: data, bubbles: true, cancelable: true }));
    }
    await new Promise((r) => setTimeout(r, 800));
    // the cleanup gives markup that comes out the same when cleaned again (it is parsed again by insertHTML and innerHTML)
    const clean = window.kaletaCistiHtml;
    const samples = [...payloads, '<div><p>x</p></div>', '<table><p>t</p><tr><td>c</td></tr></table>', '<a href="/a"><p><a href="/b">b</a></p></a>', '<svg><style><img src=x onerror=1></style></svg>'];
    const unstable = samples.filter((h) => clean(clean(h, true), true) !== clean(h, true));
    return { ran: window.__pasteRan, html: surface.innerHTML, unstable,
      youtube: clean('<iframe src="https://www.youtube.com/embed/abc">x</iframe>', true), other: clean('<iframe src="https://frames.example/x"></iframe>', true),
      kept: clean('<iframe src="https://frames.example/x">x</iframe>'), spaces: clean('<p>b&nbsp;c</p><p>&nbsp;</p><p><br></p>', true) };
  });
  if (bypass.ran) { throw new Error(`a pasted payload ran code (${bypass.ran})`); }
  if (/onerror|<iframe|frames\.example|rel="(?:[^"]*\s)?opener[\s"]|target="_top"/i.test(bypass.html)) { throw new Error(`the pasted HTML kept a handler, a frame, rel=opener or a target: ${bypass.html}`); }
  if (!/<a href="https:\/\/e\.example\/2" target="_blank" rel="noopener nofollow">two<\/a>/.test(bypass.html) || !/b c/.test(bypass.html)) { throw new Error(`a link or the text was lost: ${bypass.html}`); }
  if (bypass.unstable.length) { throw new Error(`the cleanup is not stable for: ${bypass.unstable.join(' | ')}`); }
  if (bypass.spaces !== '<p>b c</p>') { throw new Error(`empty paragraphs and non-breaking spaces: ${bypass.spaces}`); }
  if (bypass.youtube !== '<iframe src="https://www.youtube-nocookie.com/embed/abc"></iframe>' || bypass.other !== '' || bypass.kept !== '<iframe src="https://frames.example/x"></iframe>') {
    throw new Error(`frames: ${JSON.stringify([bypass.youtube, bypass.other, bypass.kept])}`);
  }
}

await step('3.18.2 news editor: the link dialog refuses java<TAB>script:, vbscript: and data: (N3182-6)', async () => {
  const editor = page.locator('[contenteditable="true"]').first();
  for (const address of ['java\tscript:window.__linkRan=1', 'vbscript:x', 'data:text/html,x']) {
    await editor.click();
    await page.keyboard.press('End');
    await page.keyboard.type(' linkme');
    await page.keyboard.press('Shift+ArrowLeft');
    await page.keyboard.press('Control+k');
    const dialog = page.locator('dialog[open]');
    await dialog.locator('[name=adresa]').fill(address);
    await dialog.locator('button[value="ok"]').click();
    await page.waitForTimeout(200);
    if (await page.locator('dialog[open]').count()) { await page.keyboard.press('Escape'); }
  }
  const html = await editor.innerHTML();
  if (/script:|vbscript|data:text/i.test(html)) { throw new Error(`the link dialog inserted an unsafe address: ${html}`); }
});

// 3.22 (U6): one field for an address or a search – the pages come as you type, arrows and Enter pick one, a second Enter
// inserts the link; an e-mail and a phone number are offered as mailto: and tel:; the first Esc closes only the list
await step('3.22 (U6) news editor: the link dialog finds a page as you type and by keyboard; mailto: and tel: for an e-mail and a phone', async () => {
  const editor = page.locator('[contenteditable="true"]').first();
  await editor.click();
  await page.keyboard.press('End');
  await page.keyboard.type(' contactus');
  for (let i = 0; i < 9; i++) { await page.keyboard.press('Shift+ArrowLeft'); }
  await page.keyboard.press('Control+k');
  const dialog = page.locator('dialog[open]');
  const field = dialog.locator('[name=adresa]');
  await dialog.locator('[role=option]').first().waitFor({ timeout: 5000 }); // an empty field offers the pages at once
  await field.fill('Conta');
  // the list of the empty field has Contact too – wait for the answer to "Conta", where Contact is the first suggestion
  await page.waitForFunction(() => { const all = [...document.querySelectorAll('dialog[open] [role=option]')]; return all.length > 0 && /Contact/.test(all[0].textContent) && all.every((o) => /conta/i.test(o.textContent)); }, null, { timeout: 5000 });
  await page.waitForTimeout(300);
  await page.keyboard.press('ArrowDown');
  if (!(await field.getAttribute('aria-activedescendant')) || (await field.getAttribute('aria-expanded')) !== 'true') { throw new Error('ArrowDown did not mark a suggestion'); }
  await page.keyboard.press('Enter');
  const chosen = await field.inputValue();
  if (!/\/contact\/?$/.test(chosen)) { throw new Error(`Enter picked ${chosen}`); }
  if (!(await page.locator('dialog[open]').count())) { throw new Error('Enter on a suggestion closed the dialog'); }
  await page.keyboard.press('Enter');
  await page.waitForTimeout(200);
  const html = await editor.innerHTML();
  if (!/<a href="[^"]*\/contact\/?">contactus<\/a>/.test(html)) { throw new Error(`the link was not inserted: ${html.slice(-300)}`); }
  await editor.click();
  await page.keyboard.press('End');
  await page.keyboard.type(' mail');
  for (let i = 0; i < 4; i++) { await page.keyboard.press('Shift+ArrowLeft'); }
  await page.keyboard.press('Control+k');
  await field.fill('info@example.com');
  await dialog.locator('[role=option][data-url="mailto:info@example.com"]').waitFor({ timeout: 3000 });
  await field.fill('+420 777 123 456');
  await dialog.locator('[role=option][data-url="tel:+420777123456"]').waitFor({ timeout: 3000 });
  await page.keyboard.press('ArrowDown');
  await page.keyboard.press('Escape');
  if (!(await page.locator('dialog[open]').count()) || !(await dialog.locator('.odkaz-vysledky').isHidden())) { throw new Error('the first Esc did not close only the list'); }
  if ((await field.inputValue()) !== '+420 777 123 456') { throw new Error('Esc changed the field'); }
  await page.keyboard.press('Escape');
  await page.waitForTimeout(200);
  if (await page.locator('dialog[open]').count()) { throw new Error('the second Esc did not close the dialog'); }
});

// 3.22 (U7): Word's list paragraphs (MsoListParagraph, mso-list levels, the bullet in a span marked mso-list:Ignore) become
// nested <ul>/<ol>; Google Docs lists stay; plain "·" paragraphs only as a run, and never in text that was not pasted
await step('3.22 (U7) news editor: lists pasted from Word become nested lists, Google Docs lists stay', async () => {
  const word = `<html xmlns:o="urn:schemas-microsoft-com:office:office"><head><style>p.MsoListParagraph{}</style></head><body lang=EN>
<p class=MsoNormal>Intro<o:p></o:p></p>
<p class=MsoListParagraphCxSpFirst style='text-indent:-18.0pt;mso-list:l0 level1 lfo1'><![if !supportLists]><span style='font-family:Symbol'><span style='mso-list:Ignore'>·<span style='font:7.0pt "Times New Roman"'>&nbsp;&nbsp;&nbsp; </span></span></span><![endif]>First item with <i>italic</i><o:p></o:p></p>
<p class=MsoListParagraphCxSpMiddle style='margin-left:72.0pt;text-indent:-18.0pt;mso-list:l0 level2 lfo1'><![if !supportLists]><span style='font-family:"Courier New"'><span style='mso-list:Ignore'>o<span>&nbsp;&nbsp; </span></span></span><![endif]>Nested<o:p></o:p></p>
<p class=MsoListParagraphCxSpLast style='text-indent:-18.0pt;mso-list:l0 level1 lfo1'><![if !supportLists]><span style='font-family:Symbol'><span style='mso-list:Ignore'>·<span>&nbsp;&nbsp; </span></span></span><![endif]>Second<o:p></o:p></p>
<p class=MsoNormal>Between</p>
<p class=MsoListParagraphCxSpFirst style='text-indent:-18.0pt;mso-list:l1 level1 lfo2'><![if !supportLists]><span><span style='mso-list:Ignore'>1.<span>&nbsp;&nbsp; </span></span></span><![endif]>One<o:p></o:p></p>
<p class=MsoListParagraphCxSpLast style='text-indent:-18.0pt;mso-list:l1 level2 lfo2'><![if !supportLists]><span><span style='mso-list:Ignore'>a)<span>&nbsp;&nbsp; </span></span></span><![endif]>Sub a<o:p></o:p></p>
</body></html>`;
  if (await page.locator('dialog[open]').count()) { await page.keyboard.press('Escape'); await page.keyboard.press('Escape'); }
  const editor = page.locator('[contenteditable="true"]').first();
  await editor.click();
  await page.keyboard.press('Control+End');
  const result = await editor.evaluate(async (surface, html) => {
    const data = new DataTransfer();
    data.setData('text/html', html);
    surface.dispatchEvent(new ClipboardEvent('paste', { clipboardData: data, bubbles: true, cancelable: true }));
    await new Promise((r) => setTimeout(r, 300));
    const clean = (h, mode) => window.kaletaCistiHtml(h, mode).replace(/>\s+</g, '><');
    return { html: surface.innerHTML, word: clean(html, 'paste'), docs: clean('<ul><li><p>Docs item</p></li></ul><ol><li>n</li></ol>', 'paste'),
      plain: clean('<p>· one</p><p>· two</p><p>1. Introduction</p>', 'paste'), typed: clean('<p>· one</p><p>· two</p>', true),
      // N322-1: paragraphs that start with dates are no list; numbers that count on are
      dates: clean('<p>15. May we opened.</p><p>20. June is next.</p>', 'paste'), counted: clean('<p>1. one</p><p>2. two</p>', 'paste') };
  }, word);
  const expected = '<p>Intro</p><ul><li>First item with <i>italic</i><ul><li>Nested</li></ul></li><li>Second</li></ul><p>Between</p><ol><li>One<ol><li>Sub a</li></ol></li></ol>';
  if (result.word !== expected) { throw new Error(`Word lists: ${result.word}`); }
  if (!/<ul><li>First item with <i>italic<\/i><ul><li>Nested<\/li><\/ul><\/li><li>Second<\/li><\/ul>/.test(result.html.replace(/>\s+</g, '><'))) { throw new Error(`the pasted Word list in the editor: ${result.html.slice(-400)}`); }
  if (result.docs !== '<ul><li><p>Docs item</p></li></ul><ol><li>n</li></ol>') { throw new Error(`Google Docs list: ${result.docs}`); }
  if (result.plain !== '<ul><li>one</li><li>two</li></ul><p>1. Introduction</p>' || result.typed !== '<p>· one</p><p>· two</p>') { throw new Error(`plain bullets: ${result.plain} | ${result.typed}`); }
  if (result.dates !== '<p>15. May we opened.</p><p>20. June is next.</p>' || result.counted !== '<ol><li>one</li><li>two</li></ol>') { throw new Error(`numbered paragraphs: ${result.dates} | ${result.counted}`); }
});

// 3.22 (C14): Media scales a large photo down in the browser before the upload (2000 px) and the message says so
await step('3.22 (C14) Media: a 3000 px photo is scaled to 2000 px in the browser, and the message says "resized from … to …"', async () => {
  await visit('/admin.php?module=media');
  await page.evaluate(async () => {
    const c = document.createElement('canvas');
    c.width = 3000; c.height = 2000;
    const g = c.getContext('2d');
    const gradient = g.createLinearGradient(0, 0, 3000, 2000);
    gradient.addColorStop(0, '#c33'); gradient.addColorStop(1, '#33c');
    g.fillStyle = gradient; g.fillRect(0, 0, 3000, 2000);
    const blob = await new Promise((r) => c.toBlob(r, 'image/jpeg', 0.95));
    const files = new DataTransfer();
    files.items.add(new File([blob], 'big-photo-322.jpg', { type: 'image/jpeg' }));
    document.querySelector('form[data-nahravani] input[type=file]').files = files.files;
  });
  await Promise.all([page.waitForNavigation(), page.evaluate(() => document.querySelector('form[data-nahravani]').requestSubmit())]);
  const text = await page.locator('main, body').first().innerText();
  if (!/big-photo-322\.jpg resized from 3000 × 2000 px \([^)]+\) to 2000 × 1333 px \([^)]+\)/.test(text)) { throw new Error(`no resize note: ${text.slice(0, 400)}`); }
  const width = sql("SELECT obr_width FROM ka_media WHERE obr_poloha LIKE '%big-photo-322%' ORDER BY ido DESC LIMIT 1").trim();
  if (width !== '2000') { throw new Error(`stored width ${width}`); }
});

await step('newsletter: draft and preview', async () => {
  await visit('/admin.php?module=newsletters&action=new');
  await page.fill('input[name="subject"]', 'Spring news from our workshop');
  await page.fill('input[name="preheader"]', 'Two new projects and a spring offer');
  await page.fill('textarea[name="intro"]', 'Hello,\n\nhere is what we have been working on this spring. The full offer is at https://example.com/offer');
  await page.fill('input[name="button_label"]', 'See all news');
  await page.fill('input[name="button_url"]', '/news');
  await Promise.all([page.waitForNavigation(), page.locator('form.formular input[type="submit"]').first().click()]);
  const preview = page.locator('iframe.rozesilka-nahled');
  await preview.waitFor();
  await page.frameLocator('iframe.rozesilka-nahled').locator('h1').waitFor({ timeout: 5000 });
  if (SHOTS) {
    await page.screenshot({ path: `${SHOTS}/newsletter-admin.png`, fullPage: true });
    const src = await preview.getAttribute('src');
    for (const [name, width] of [['newsletter-email', 700], ['newsletter-email-phone', 390]]) {
      await page.setViewportSize({ width, height: 900 });
      await page.goto(new URL(src, BASE).href, { waitUntil: 'networkidle' });
      await page.screenshot({ path: `${SHOTS}/${name}.png`, fullPage: true });
    }
    await page.setViewportSize({ width: 1440, height: 900 });
  }
});

// 3.16: the archived monthly report (Settings → Reports, Core\ReportPage) – the tab lists the month test-browser.sh stored,
// the printable page has its Print button, and in print media at the width of an A4 page (210 mm less the 15 mm margins)
// nothing is wider than the page: no table is cut at its right edge; a PDF of it is A4
await step('monthly report: the archive and the A4 print layout', async () => {
  await visit('/admin.php?module=settings&tab=reports');
  const view = page.locator('a[href*="action=report_view"]').first();
  await view.waitFor();
  if (SHOTS) { await page.screenshot({ path: `${SHOTS}/reports-tab.png`, fullPage: true }); }
  const href = new URL(await view.getAttribute('href'), BASE).href;
  const report = await context.newPage();
  watch(report);
  try {
    await report.goto(href, { waitUntil: 'networkidle' });
    await report.locator('[data-tisk]').waitFor();
    if ((await report.locator('section.sekce').count()) < 5) { throw new Error('the report has fewer than 5 sections'); }
    if (SHOTS) { await report.screenshot({ path: `${SHOTS}/report-screen.png`, fullPage: true }); }
    await report.emulateMedia({ media: 'print' });
    await report.setViewportSize({ width: 680, height: 1000 }); // 180 mm at 96 dpi: the printable width of A4
    const wide = await report.evaluate(() => {
      const page = document.querySelector('.listina').getBoundingClientRect();
      return [...document.querySelectorAll('.listina table, .listina dl, .listina h1, .listina h2, .listina p, .listina td')]
        .filter((el) => el.getBoundingClientRect().right > page.right + 1 || el.scrollWidth > el.clientWidth + 1).map((el) => el.tagName + ': ' + el.textContent.slice(0, 40));
    });
    if (wide.length) { throw new Error('wider than the A4 page in print: ' + wide.slice(0, 3).join(' | ')); }
    if (await report.locator('.lista').isVisible()) { throw new Error('the toolbar prints'); }
    if (SHOTS) {
      // print media in one tall viewport (a full-page screenshot repeats the first screen in print media); the PDF has the pages
      await report.setViewportSize({ width: 680, height: await report.evaluate(() => document.documentElement.scrollHeight) });
      await report.screenshot({ path: `${SHOTS}/report-print.png` });
      await report.pdf({ path: `${SHOTS}/report-print.pdf`, format: 'A4', preferCSSPageSize: true });
    }
    const pdf = await report.pdf({ format: 'A4', preferCSSPageSize: true });
    const pages = (pdf.toString('latin1').match(/\/Type\s*\/Page[^s]/g) || []).length;
    if (pages < 1 || pages > 6) { throw new Error(`the A4 PDF has ${pages} pages`); }
  } finally {
    await report.close();
  }
});

// 3.26.1: the report page and the e-mail share one look (Core\ReportVisual) – at 1440 and 390 px, light and dark: the tiles and
// the groups of "What it took to run the site" side by side on a computer and wrapped on a phone, no sideways scroll, the dark
// screen turns the paper dark (printing stays light – the step above); the e-mail preview reads at 390 px in both modes too
await step('3.26.1 monthly report: the report page and the e-mail at 1440 and 390 px, light and dark', async () => {
  await visit('/admin.php?module=settings&tab=reports');
  const href = new URL(await page.locator('a[href*="action=report_view"]').first().getAttribute('href'), BASE).href;
  const preview = new URL('/admin.php?module=settings&action=report_preview', BASE).href;
  for (const scheme of ['light', 'dark']) {
    for (const width of [1440, 390]) {
      const tab = await context.newPage();
      watch(tab);
      try {
        await tab.emulateMedia({ colorScheme: scheme });
        await tab.setViewportSize({ width, height: 900 });
        for (const [name, url] of [['report', href], ['email', preview]]) {
          await tab.goto(url, { waitUntil: 'networkidle' });
          const look = await tab.evaluate(() => {
            const tiles = [...document.querySelectorAll('.kr-tile')].map((t) => t.getBoundingClientRect());
            const card = document.querySelector('.kr-card, .listina');
            return { tiles: tiles.length, rows: new Set(tiles.map((r) => Math.round(r.top))).size, groups: document.querySelectorAll('.kr-box').length,
              wide: document.documentElement.scrollWidth > window.innerWidth + 1, background: card ? getComputedStyle(card).backgroundColor : '' };
          });
          if (look.tiles < 3 || look.groups < 2) { throw new Error(`${name} ${width} ${scheme}: ${look.tiles} tiles, ${look.groups} groups`); }
          if (look.wide) { throw new Error(`${name} ${width} ${scheme}: scrolls sideways`); }
          if (width === 1440 && look.rows !== 1) { throw new Error(`${name} 1440: the tiles are not in one row (${look.rows})`); }
          if (width === 390 && look.rows < 2) { throw new Error(`${name} 390: the tiles do not wrap`); }
          const dark = look.background === 'rgb(28, 28, 27)';
          if (dark !== (scheme === 'dark')) { throw new Error(`${name} ${width} ${scheme}: the card is ${look.background}`); }
          if (SHOTS) { await tab.screenshot({ path: `${SHOTS}/report3261-${name}-${width}-${scheme}.png`, fullPage: true }); }
        }
      } finally {
        await tab.close();
      }
    }
  }
});

await step('3.9 Settings → Mail: choosing a mail service fills the server, port and encryption, shows its hint, and leaves the user name alone', async () => {
  await visit('/admin.php?module=settings&tab=mail');
  await page.locator('label.karta-volba', { has: page.locator('input[name="mail_mode"][value="smtp"]') }).click();
  await page.fill('#smtp_user', 'kept@example.com');
  await page.selectOption('#smtp_provider', 'brevo');
  const brevo = [await page.inputValue('#smtp_host'), await page.inputValue('#smtp_port'), await page.inputValue('#smtp_encryption'), await page.inputValue('#smtp_user'),
    await page.locator('[data-smtp-tip="brevo"]').isVisible(), await page.locator('#smtp_ses_region').isVisible()].join('|');
  if (brevo !== 'smtp-relay.brevo.com|587|tls|kept@example.com|true|false') { throw new Error('Brevo: ' + brevo); }
  await page.selectOption('#smtp_provider', 'ses');
  await page.selectOption('#smtp_ses_region', 'eu-west-1');
  const ses = [await page.inputValue('#smtp_host'), await page.locator('[data-smtp-tip="brevo"]').isVisible(), await page.locator('#smtp_ses_region').isVisible()].join('|');
  if (ses !== 'email-smtp.eu-west-1.amazonaws.com|false|true') { throw new Error('SES: ' + ses); }
  // "Other server" keeps whatever is typed in – the free form as before
  await page.selectOption('#smtp_provider', 'other');
  if (await page.inputValue('#smtp_host') !== 'email-smtp.eu-west-1.amazonaws.com') { throw new Error('Other server changed the server'); }
});

// 3.23 (C12, C8): Settings → General split into General, Languages, Security and Agency – saving one tab leaves the fields of the
// others alone (the checkboxes too), an old link to a moved field goes on to its tab, and the console tab ("Reporting to a
// console") is in the row only once an agency is named
await step('3.23 Settings tabs: saving one keeps the others, old links move on, the console tab follows the agency', async () => {
  const save = () => Promise.all([page.waitForNavigation(), page.locator('p.tlacitka input[type="submit"]').click()]);
  const tabs = async () => page.locator('nav.zalozky a').evaluateAll((links) => links.map((a) => new URL(a.href).searchParams.get('tab')));
  await visit('/admin.php?module=settings&tab=security');
  const suspend = page.locator('input[name="auto_suspend[]"]').first();
  const suspendBefore = await suspend.isChecked();
  await suspend.setChecked(true);
  await save();
  if (!(await page.locator('a[href*="tab=firewall"]', { hasText: 'Settings → Firewall' }).count())) { throw new Error('Security does not link Settings → Firewall'); }
  await visit('/admin.php?module=settings&tab=general');
  if (await page.locator('[name="auto_suspend[]"], [name="agency_name"], [name="site_language"], [name="require_2fa"]').count()) { throw new Error('General still shows moved fields'); }
  const description = await page.inputValue('#site_description');
  await page.fill('#site_description', description + ' 323');
  await save();
  await visit('/admin.php?module=settings&tab=security');
  if (!(await page.locator('input[name="auto_suspend[]"]').first().isChecked())) { throw new Error('saving General cleared the automatic suspension'); }
  // the link of 3.22 to a field that moved (a bookmark, Features → Choose languages before the update)
  await page.goto(BASE + '/admin.php?module=settings&tab=general#require_2fa');
  await page.waitForURL(/tab=security#require_2fa$/, { timeout: 5000 });
  if (!(await page.locator('#require_2fa').isVisible())) { throw new Error('the old link did not reach Two-factor sign-in'); }
  // 3.31 (code scanning #44): a map naming another origin, a protocol-relative path or javascript: never moves the page;
  // a same-origin path with doubled slashes goes to the path on this origin
  const movedRoute = (map) => async (route) => {
    const response = await route.fetch();
    const html = (await response.text()).replace(/data-pole-jinde="[^"]*"/, `data-pole-jinde="${JSON.stringify(map).replaceAll('&', '&amp;').replaceAll('"', '&quot;')}"`);
    await route.fulfill({ response, body: html });
  };
  const movedPattern = /module=settings&tab=general$/;
  // each time from another page: a goto to the same address with the same #anchor is a jump within the page, no reload
  const openGeneral = async () => { await page.goto(BASE + '/admin.php?module=settings&tab=agency'); await page.goto(BASE + '/admin.php?module=settings&tab=general#require_2fa'); };
  for (const evil of ['https://evil.example/x', '//evil.example/x', '/\\evil.example/x', 'javascript:alert(1)']) {
    await page.route(movedPattern, movedRoute({ require_2fa: evil }));
    await openGeneral();
    await page.waitForTimeout(400);
    await page.unroute(movedPattern);
    if (!/module=settings&tab=general#require_2fa$/.test(page.url())) { throw new Error(`the moved-field map ${evil} moved the page to ${page.url()}`); }
  }
  await page.route(movedPattern, movedRoute({ require_2fa: new URL(BASE).origin + '//admin.php?module=settings&tab=security' }));
  await openGeneral();
  await page.waitForURL((url) => url.hash === '#require_2fa' && url.search === '?module=settings&tab=security', { timeout: 5000 });
  await page.unroute(movedPattern);
  if (new URL(page.url()).origin !== new URL(BASE).origin) { throw new Error(`a doubled slash left the site: ${page.url()}`); }
  // the console tab: not in the row without an agency, the last one with it
  await visit('/admin.php?module=settings&tab=agency');
  const agencyBefore = await page.inputValue('#agency_name');
  await page.fill('#agency_name', '');
  await save();
  if ((await tabs()).includes('console')) { throw new Error('the console tab shows without an agency'); }
  await page.fill('#agency_name', 'Browser Studio');
  await save();
  const withAgency = await tabs();
  if (withAgency.at(-1) !== 'console' || withAgency.at(-2) !== 'agency' || !(await page.locator('nav.zalozky a', { hasText: 'Reporting to a console' }).count())) { throw new Error(`the tabs with an agency: ${withAgency.join(', ')}`); }
  if (SHOTS) { await page.screenshot({ path: `${SHOTS}/settings-agency-tab.png`, fullPage: true }); }
  // put everything back
  await page.fill('#agency_name', agencyBefore);
  await save();
  await visit('/admin.php?module=settings&tab=general');
  await page.fill('#site_description', description);
  await save();
  await visit('/admin.php?module=settings&tab=security');
  await page.locator('input[name="auto_suspend[]"]').first().setChecked(suspendBefore);
  await save();
});

await step('site parts: every header and footer template renders', async () => {
  for (const [part, templates] of [['hlavicka', ['klasicka', 'na-stred', 's-listou', 'minimalni']], ['paticka', ['sloupce', 'kompaktni', 'tiraz', 'vyzva']]]) {
    for (const template of templates) {
      await visit(`/admin.php?module=parts&action=templates&type=${part}`);
      await Promise.all([page.waitForNavigation(), page.locator(`input[name="sablona"][value="${template}"] ~ button`).click()]);
      await visit(`/?part=${part}&build=koncept`);
      if (SHOTS) {
        const box = page.locator(part === 'hlavicka' ? 'header' : 'footer').last();
        await box.screenshot({ path: `${SHOTS}/part-${part}-${template}.png` });
      }
    }
    await visit('/admin.php?module=parts');
  }
});

await step('menu editor', async () => {
  await visit('/admin.php?module=menu');
  const add = page.getByRole('button', { name: /Add|Přidat/ }).first();
  if (await add.count()) { await add.click().catch(() => {}); }
});

await step('public site: home, phone menu, cookies', async () => {
  await visit('/');
  const accept = page.getByRole('button', { name: /Accept|Allow|Přijmout/ }).first();
  if (await accept.count()) { await accept.click().catch(() => {}); }
  await page.setViewportSize({ width: 390, height: 844 });
  await visit('/');
  const toggle = page.locator('.ka-nav-prepinac, button[popovertarget]').first();
  if (await toggle.count()) { await toggle.click().catch(() => {}); await page.waitForTimeout(300); }
  await page.setViewportSize({ width: 1440, height: 900 });
});

for (const url of ['/services', '/contact', '/news', '/search?q=test']) {
  await step(`site ${url}`, () => visit(url));
}

// 3.5: axe-core (installed next to playwright-core, injected from the local file – no CDN) on the starter's home page, contact
// page and a news item, light mode, the cookie bar showing (test-browser.sh switches on lead attribution, a policy link and
// the accessibility toolbar), at 1440 and 390 px; any WCAG 2.0/2.1/2.2 A or AA violation fails. Dark mode: below (3.6).
const AXE = require.resolve('axe-core/axe.min.js');
const WCAG = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];
const axeViolations = async (p, label) => {
  await p.addScriptTag({ path: AXE });
  const result = await p.evaluate((tags) => window.axe.run(document, { runOnly: { type: 'tag', values: tags }, resultTypes: ['violations'] }), WCAG);
  return result.violations.map((v) => `${label} ${v.id} (${v.impact}, ${v.nodes.length}×): ${v.nodes[0].target.join(' ')}`);
};
// the three starters (test-browser.sh): firemni on BASE, remeslo and poradenstvi on the next two ports
const STARTER_SITES = Object.fromEntries((process.env.STARTERS || `firemni=${BASE}`).split(',').map((s) => s.split('=')));
let newsItem = '';
for (const [label, width, height] of [['desktop', 1440, 900], ['phone', 390, 844]]) {
  await step(`accessibility (axe, ${label}): home, contact and a news item with the cookie bar`, async () => {
    const ctx = await browser.newContext({ viewport: { width, height }, locale: 'en-GB', colorScheme: 'light', isMobile: width < 768, hasTouch: width < 768 });
    const p = await ctx.newPage();
    watch(p);
    try {
      if (!newsItem) {
        await p.goto(BASE + '/news', { waitUntil: 'networkidle' });
        newsItem = await p.locator('main a[href*="/news/"]').evaluateAll((links) => links.map((a) => new URL(a.href).pathname).find((path) => /^\/news\/[^/]+$/.test(path) && !path.includes('/category/') && !path.includes('/tag/')) || '');
        if (!newsItem) { throw new Error('no news item linked from /news'); }
      }
      const found = [];
      for (const url of ['/', '/contact', newsItem]) {
        await p.goto(BASE + url, { waitUntil: 'networkidle' });
        if (await p.locator('#cookies-lista:not([hidden])').count() !== 1) { throw new Error(`${url}: the cookie bar is not showing`); }
        await p.addScriptTag({ path: AXE });
        const result = await p.evaluate((tags) => window.axe.run(document, { runOnly: { type: 'tag', values: tags }, resultTypes: ['violations'] }), WCAG);
        for (const v of result.violations) { found.push(`${url} ${v.id} (${v.impact}, ${v.nodes.length}×): ${v.nodes[0].target.join(' ')}`); }
      }
      if (found.length) { throw new Error(`axe found ${found.length} WCAG violation(s): ${found.join(' | ')}`); }
    } finally {
      await ctx.close();
    }
  });
}

// 3.6 (UXP-01): every starter in dark mode (dark_mode = auto, the browser prefers dark) – until 3.5 the dark palette kept the
// light primary and secondary, so links, buttons, the current menu item and focus rings failed (Consulting 1.14 : 1)
for (const [label, width, height] of [['desktop', 1440, 900], ['phone', 390, 844]]) {
  await step(`accessibility (axe, dark mode, ${label}): home, services and contact of all three starters`, async () => {
    const ctx = await browser.newContext({ viewport: { width, height }, locale: 'en-GB', colorScheme: 'dark', isMobile: width < 768, hasTouch: width < 768 });
    const p = await ctx.newPage();
    watch(p);
    try {
      const found = [];
      for (const [starter, base] of Object.entries(STARTER_SITES)) {
        for (const url of ['/', '/services', '/contact']) {
          await p.goto(base + url, { waitUntil: 'networkidle' });
          if (!(await p.evaluate(() => matchMedia('(prefers-color-scheme: dark)').matches && document.documentElement.hasAttribute('data-tmavy')))) { throw new Error(`${starter}${url}: not in dark mode`); }
          found.push(...await axeViolations(p, `${starter}${url}`));
        }
        if (SHOTS) { await p.goto(base + '/', { waitUntil: 'networkidle' }); await p.screenshot({ path: `${SHOTS}/dark-${starter}-${label}.png` }); }
      }
      if (found.length) { throw new Error(`axe found ${found.length} WCAG violation(s) in dark mode: ${found.join(' | ')}`); }
    } finally {
      await ctx.close();
    }
  });
}

// 3.6 (UXP-04, UXM-03): submenus are disclosures – a button with aria-expanded and aria-controls per submenu, opened by click,
// Enter or Space (on a touch tablet too), Esc closes it and returns focus; the phone menu is an accordion; without JavaScript
// the submenus are still reachable. Crafts = the built-in header, Consulting = the Navigation element as a mega menu.
for (const [starter, nav, opener] of [['remeslo', '.navigace', '.menu-tl'], ['poradenstvi', '.ka-nav', '.ka-nav-tl']]) {
  const base = STARTER_SITES[starter];
  if (!base) { continue; }
  await step(`submenus (${starter}): keyboard, touch tablet, phone accordion and no JavaScript; axe with a panel open`, async () => {
    const problems = [];
    const desktop = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'en-GB', colorScheme: 'light' });
    const p = await desktop.newPage();
    watch(p);
    try {
      await p.goto(base + '/', { waitUntil: 'networkidle' });
      const toggles = p.locator(`${nav} li.podmenu > button[aria-controls]`);
      if (await toggles.count() !== 2) { problems.push(`${await toggles.count()} submenu toggles, expected 2 (a linked parent and a group)`); }
      const states = await toggles.evaluateAll((list) => list.map((b) => [b.getAttribute('aria-expanded'), !!document.getElementById(b.getAttribute('aria-controls'))]));
      if (states.some(([expanded, target]) => expanded !== 'false' || !target)) { problems.push(`toggles not collapsed disclosures: ${JSON.stringify(states)}`); }
      // keyboard: Enter opens the linked parent's submenu, Esc closes it and keeps focus on the toggle; Space opens the group
      const first = toggles.first(), panel = p.locator(`${nav} li.podmenu`).first().locator(':scope > ul');
      await first.focus();
      if (await panel.isVisible()) { problems.push('a focused toggle opens its panel by itself (it should wait for Enter or Space)'); }
      await p.keyboard.press('Enter');
      if (await first.getAttribute('aria-expanded') !== 'true' || !(await panel.isVisible())) { problems.push('Enter does not open the submenu'); }
      await p.keyboard.press('Tab');
      if (!(await p.evaluate(() => !!document.activeElement.closest('li.podmenu > ul')))) { problems.push('Tab after opening does not move into the submenu'); }
      await p.keyboard.press('Escape');
      if (await first.getAttribute('aria-expanded') !== 'false' || await panel.isVisible() || !(await first.evaluate((b) => b === document.activeElement))) { problems.push('Esc does not close the submenu and return focus to its toggle'); }
      const group = toggles.nth(1);
      await group.focus();
      await p.keyboard.press(' ');
      if (await group.getAttribute('aria-expanded') !== 'true') { problems.push('Space does not open the group'); }
      problems.push(...await axeViolations(p, `${starter} desktop, group open`));
      if (SHOTS) { await p.screenshot({ path: `${SHOTS}/submenu-${starter}-open.png` }); }
      await p.mouse.click(5, 600);
      if (await group.getAttribute('aria-expanded') !== 'false') { problems.push('a click outside does not close the submenu'); }
      // a mouse still opens a panel on hover
      await p.locator(`${nav} li.podmenu`).nth(1).hover();
      if (!(await p.locator(`${nav} li.podmenu`).nth(1).locator(':scope > ul').isVisible())) { problems.push('hover does not open the submenu'); }
    } finally {
      await desktop.close();
    }
    // a touch tablet: a tap on the toggle opens the submenu (until 3.5 a tap followed the link and the submenu stayed hidden)
    const tablet = await browser.newContext({ viewport: { width: 1024, height: 768 }, locale: 'en-GB', isMobile: true, hasTouch: true });
    const t = await tablet.newPage();
    watch(t);
    try {
      await t.goto(base + '/', { waitUntil: 'networkidle' });
      const toggle = t.locator(`${nav} li.podmenu > button[aria-controls]`).first();
      await toggle.tap();
      if (await toggle.getAttribute('aria-expanded') !== 'true' || !(await t.locator(`${nav} li.podmenu`).first().locator(':scope > ul').isVisible())) { problems.push('a tap on a touch tablet does not open the submenu'); }
      if (new URL(t.url()).pathname !== '/') { problems.push('the tap navigated away'); }
    } finally {
      await tablet.close();
    }
    // the phone menu: groups collapsed, the one with the current page open; a tap opens another
    const phone = await browser.newContext({ viewport: { width: 390, height: 844 }, locale: 'en-GB', isMobile: true, hasTouch: true });
    const m = await phone.newPage();
    watch(m);
    try {
      await m.goto(base + '/services', { waitUntil: 'networkidle' });
      await m.locator(opener).first().tap();
      await m.waitForTimeout(300);
      const sheet = await m.locator(`${nav} li.podmenu`).evaluateAll((list) => list.map((li) => [li.classList.contains('aktivni'), li.querySelector(':scope > button[aria-controls]').getAttribute('aria-expanded'), getComputedStyle(li.querySelector(':scope > ul')).display !== 'none']));
      if (JSON.stringify(sheet) !== JSON.stringify([[true, 'true', true], [false, 'false', false]])) { problems.push(`phone menu: the current group open, the others closed – got ${JSON.stringify(sheet)}`); }
      if (SHOTS) { await m.screenshot({ path: `${SHOTS}/submenu-${starter}-phone.png` }); }
      await m.locator(`${nav} li.podmenu > button[aria-controls]`).nth(1).tap();
      if (!(await m.locator(`${nav} li.podmenu`).nth(1).locator(':scope > ul').isVisible())) { problems.push('phone menu: a tap does not open a group'); }
    } finally {
      await phone.close();
    }
    // without JavaScript: keyboard focus and hover still open the submenus, the phone menu shows them all
    for (const [w, h] of [[1440, 900], [390, 844]]) {
      const plain = await browser.newContext({ viewport: { width: w, height: h }, locale: 'en-GB', javaScriptEnabled: false });
      const n = await plain.newPage();
      try {
        await n.goto(base + '/');
        if (w > 768) {
          await n.locator(`${nav} li.podmenu`).first().locator(':scope > a').focus();
          await n.keyboard.press('Tab');
          if (!(await n.locator(`${nav} li.podmenu`).first().locator(':scope > ul').isVisible())) { problems.push('without JavaScript keyboard focus does not open the submenu'); }
        } else {
          await n.locator(opener).first().click();
          if (await n.locator(`${nav} li.podmenu > ul:visible`).count() !== 2) { problems.push('without JavaScript the phone menu does not show every submenu'); }
        }
      } finally {
        await plain.close();
      }
    }
    if (problems.length) { throw new Error(problems.join(' | ')); }
  });
}

await step('cookie bar (3.5): compact on a phone, reached right after the skip link, never hides keyboard focus', async () => {
  // until 3.4 the bar took 340 px of a 390×844 phone, came last in the tab order and covered focused links (WCAG 2.2 SC 2.4.11)
  const ctx = await browser.newContext({ viewport: { width: 390, height: 844 }, locale: 'en-GB', colorScheme: 'light', isMobile: true, hasTouch: true });
  const p = await ctx.newPage();
  watch(p);
  try {
    await p.goto(BASE + '/', { waitUntil: 'networkidle' });
    const bar = await p.evaluate(() => Math.round(document.getElementById('cookies-lista').getBoundingClientRect().height));
    if (bar > 180) { throw new Error(`the cookie bar is ${bar} px tall on a phone (at most 180)`); }
    if (SHOTS) { await p.screenshot({ path: `${SHOTS}/cookie-bar-phone.png` }); }
    await p.keyboard.press('Tab');
    await p.keyboard.press('Tab');
    if (!(await p.evaluate(() => !!document.activeElement.closest('#cookies-lista')))) { throw new Error('the second Tab does not reach the cookie bar'); }
    const hidden = [];
    for (let i = 0; i < 200; i++) {
      await p.keyboard.press('Tab');
      const r = await p.evaluate(() => {
        const e = document.activeElement;
        if (!e || e === document.body) { return null; }
        if (e.hasAttribute('data-test-seen')) { return { again: true }; }
        e.setAttribute('data-test-seen', '');
        const b = e.getBoundingClientRect(), c = document.getElementById('cookies-lista').getBoundingClientRect();
        const covered = !e.closest('#cookies-lista') && b.width > 0 && b.top >= c.top && b.bottom <= c.bottom && b.left >= c.left && b.right <= c.right;
        return { covered, text: (e.textContent || e.getAttribute('aria-label') || e.tagName).trim().slice(0, 40) };
      });
      if (r && r.again) { break; }
      if (r && r.covered) { hidden.push(r.text); }
    }
    if (hidden.length) { throw new Error(`the cookie bar hides keyboard focus on: ${hidden.join(', ')}`); }
    // the accessibility toolbar sits above the bar; its panel opens above its button, not at the top of the window (UXP-03)
    const toolbar = await p.evaluate(() => [document.querySelector('.ka-pristupnost-tl').getBoundingClientRect().bottom, document.getElementById('cookies-lista').getBoundingClientRect().top]);
    if (toolbar[0] > toolbar[1]) { throw new Error('the accessibility toolbar is behind the cookie bar'); }
    await p.locator('.ka-pristupnost-tl').click();
    const panel = await p.evaluate(() => document.getElementById('ka-pristupnost-panel').getBoundingClientRect().top);
    if (panel < 844 / 3) { throw new Error(`the accessibility panel opens at the top of the window (top ${Math.round(panel)} px)`); }
    await p.locator('[data-pristupnost-volba="text"]').click();
    if (!/112[.,]5\s?%/.test(await p.locator('[data-pristupnost-volba="text"]').innerText())) { throw new Error('"Larger text" does not show the size it set'); }
    await p.keyboard.press('Escape');
    // once the visitor chooses, the bar's scroll padding goes with it
    await p.locator('#cookies-lista [data-cookies="nic"]').click();
    const after = await p.evaluate(() => [document.documentElement.style.getPropertyValue('--ka-cookies-vyska'), getComputedStyle(document.documentElement).scrollPaddingBottom]);
    if (after[0] !== '' || after[1] !== 'auto') { throw new Error(`the bar's scroll padding stays after the choice (${after.join(', ')})`); }
  } finally {
    await ctx.close();
  }
});

await step('booking: pick a day, the month stays drawn and the free times load', async () => {
  // until 3.2.1 the month and the times shared one request counter: after a day click the month stayed on "Loading…"
  await visit('/booking-test');
  if (await page.locator('.ka-rezervace [data-bez-skriptu]:visible').count()) { throw new Error('the fallback field shows although the script runs'); }
  const free = page.locator('.ka-rezervace-dny button:not(:disabled)').first();
  await free.waitFor({ timeout: 5000 });
  await free.click();
  await page.locator('.ka-rezervace-casy button').first().waitFor({ timeout: 5000 });
  await page.waitForTimeout(500);
  if (!(await page.locator('.ka-rezervace-dny button[aria-pressed="true"]').count())) { throw new Error('after picking a day the month is not drawn (or the day is not marked)'); }
  await page.locator('.ka-rezervace-casy button').first().click();
  if (!(await page.locator('[data-vybrano]:visible').count())) { throw new Error('picking a time does not show the chosen time'); }
});

await step('3.6 redirects: CSV import shows a preview first, then saves only what passed', async () => {
  await visit('/admin.php?module=redirects');
  await page.locator('#import > summary').click();
  if (SHOTS) { await page.screenshot({ path: `${SHOTS}/redirects-import-form.png`, fullPage: true }); }
  await page.fill('#import textarea[name="csv"]', '/prohlizec-stara,/kontakt,301\n/prohlizec-blog/*,/novinky/*\n/prohlizec-kruh/*,/prohlizec-kruh/x/*\n/prohlizec-gone,,410');
  await Promise.all([page.waitForNavigation(), page.locator('#import input[type="submit"]').click()]);
  await page.locator('#nahled-importu').waitFor();
  const rows = await page.locator('table.vypis tbody tr').count();
  const refused = await page.locator('table.vypis tbody .stitek-chyba').count();
  if (rows !== 4 || refused !== 1) { throw new Error(`the preview shows ${rows} rows and ${refused} refused (expected 4 and 1)`); }
  if (SHOTS) {
    await page.screenshot({ path: `${SHOTS}/redirects-csv-preview.png`, fullPage: true });
    await page.setViewportSize({ width: 390, height: 844 });
    await page.screenshot({ path: `${SHOTS}/redirects-csv-preview-phone.png`, fullPage: true });
    await page.setViewportSize({ width: 1440, height: 900 });
  }
  await Promise.all([page.waitForNavigation(), page.locator('form[action*="import_save"] input[type="submit"]').click()]);
  await page.locator('.hlaska').first().waitFor();
  await visit('/admin.php?module=redirects&hledat=prohlizec');
  const saved = await page.locator('table.vypis').first().locator('tbody tr').count();
  if (saved !== 3) { throw new Error(`after saving the list has ${saved} rows (expected 3)`); }
});

await step('3.6 site parts: the variant form offers kinds of content', async () => {
  await visit('/admin.php?module=parts&action=variant&typ=paticka&jazyk=');
  await page.locator('input[name="vypis"]').waitFor();
  if (SHOTS) {
    await page.screenshot({ path: `${SHOTS}/part-variant-form.png`, fullPage: true });
    await page.setViewportSize({ width: 390, height: 844 });
    await page.screenshot({ path: `${SHOTS}/part-variant-form-phone.png`, fullPage: true });
    await page.setViewportSize({ width: 1440, height: 900 });
  }
});

await step('3.7 collections: CSV import of items – columns paired by themselves, a preview of every row, then saved hidden', async () => {
  await visit('/admin.php?module=collections');
  await Promise.all([page.waitForNavigation(), page.locator('button[name="preset"][value="references"]').click()]);
  await Promise.all([page.waitForNavigation(), page.locator('.navigace-radek a[href*="action=import&"]').first().click()]);
  if (SHOTS) { await page.screenshot({ path: `${SHOTS}/items-import-upload.png`, fullPage: true }); }
  await page.setInputFiles('#soubor', { name: 'references.csv', mimeType: 'text/csv',
    buffer: Buffer.from('Name;Client;Year;Whatever\nNew roof;Acme Ltd;2024;x\nOld barn;Farm Co;not a year;\n;Nobody;2020;\n') });
  await Promise.all([page.waitForNavigation(), page.locator('#import-polozek input[type="submit"]').click()]);
  const mapped = await page.locator('#import-mapovani select').evaluateAll((list) => list.map((s) => s.value));
  if (JSON.stringify(mapped) !== JSON.stringify(['_name', 'client', 'year', ''])) { throw new Error(`the columns were paired as ${JSON.stringify(mapped)}`); }
  const rows = await page.locator('#import-radky tbody tr').count();
  const refused = await page.locator('#import-radky .stitek-chyba').count();
  if (rows !== 3 || refused !== 1) { throw new Error(`the preview has ${rows} rows and ${refused} refused (expected 3 and 1)`); }
  if (SHOTS) {
    await page.screenshot({ path: `${SHOTS}/items-import-preview.png`, fullPage: true });
    await page.setViewportSize({ width: 390, height: 844 });
    await page.screenshot({ path: `${SHOTS}/items-import-preview-phone.png`, fullPage: true });
    await page.setViewportSize({ width: 1440, height: 900 });
  }
  await Promise.all([page.waitForNavigation(), page.locator('#import-mapovani button[name="ulozit"]').click()]);
  await page.locator('#import-hotovo').waitFor({ timeout: 30000 }); // the progress page submits itself batch by batch
  if (SHOTS) { await page.screenshot({ path: `${SHOTS}/items-import-done.png`, fullPage: true }); }
  await Promise.all([page.waitForNavigation(), page.locator('.navigace-radek a[href*="action=items"]').first().click()]);
  const hidden = await page.locator('tr', { hasText: 'New roof' }).count();
  if (hidden !== 1) { throw new Error('the imported item is not in the list'); }
});

// 3.12 (UX310-14, UX310-22): the Collection list inspector offers the chosen collection's fields by their labels and folds
// the filter options; searching "form" finds the Form element, not Embed (whose description mentions forms)
await step('3.12 builder: search "form" finds Form only; the Collection list inspector offers the collection fields', async () => {
  await visit('/admin.php?module=pages&action=builder&id=1');
  await page.waitForTimeout(1500);
  await page.locator('.st-zalozky [role="tab"]').first().click();
  const search = page.locator('input.st-hledat').first();
  await search.fill('form');
  await page.waitForTimeout(300);
  const found = (await page.locator('.st-prvky button').allTextContents()).map((n) => n.trim());
  if (!found.includes('Form') || found.some((n) => /Embed/.test(n))) { throw new Error(`searching "form" offers ${JSON.stringify(found)}`); }
  await search.fill('');
  await page.waitForTimeout(300);
  await page.locator('.st-prvky button', { hasText: /^Collection list$/ }).first().click();
  await page.waitForTimeout(900);
  await page.locator('.st-pole', { hasText: /^Collections/ }).locator('select').first().selectOption({ label: 'References' });
  await page.waitForTimeout(600);
  const sort = page.locator('.st-pole', { hasText: 'Field to sort by' }).locator('select');
  if (await sort.count() !== 1) { throw new Error('the sort field is not a choice of the collection fields'); }
  const labels = (await sort.locator('option').allTextContents()).map((n) => n.trim());
  if (!labels.includes('Client') || !labels.includes('Year')) { throw new Error(`the sort field offers ${JSON.stringify(labels)}`); }
  const more = page.locator('details.st-vice-voleb');
  if (await more.count() !== 1 || (await more.getAttribute('open')) !== null) { throw new Error('the filter options are not folded under More options'); }
  await more.locator('summary').click();
  const start = page.locator('.st-vice-voleb .st-pole', { hasText: 'Start date field' }).locator('select option');
  if (await start.count() !== 2) { throw new Error('the start date field offers fields that are not dates'); } // References has no date field: only "—" and "Custom…" (3.23)
  if (SHOTS) { await page.screenshot({ path: `${SHOTS}/collection-list-inspector.png` }); }
  await page.locator('.st-hlava-prvku button.nebezpecne').first().click(); // leave the page as it was
  await page.waitForTimeout(1500);
});

let equipment = 0;
await step('3.7 collection categories: a category and a subcategory in the admin, items ticked into them, the category page', async () => {
  await visit('/admin.php?module=collections&action=new');
  await page.fill('#nazev', 'Equipment');
  await page.fill('#seo_link', 'equipment');
  await page.check('input[name="detail"]');
  await Promise.all([page.waitForNavigation(), page.locator('form.formular input[type="submit"]').first().click()]);
  equipment = Number(new URL(page.url()).searchParams.get('id'));
  await Promise.all([page.waitForNavigation(), page.locator('.navigace-radek a', { hasText: /^Categories$/ }).click()]);
  await page.locator('.prazdny-stav').waitFor();
  await Promise.all([page.waitForNavigation(), page.locator('.navigace-radek a.tl', { hasText: 'Add category' }).click()]);
  await page.fill('#name', 'Treadmills');
  await Promise.all([page.waitForNavigation(), page.locator('form.formular input[type="submit"]').click()]);
  await Promise.all([page.waitForNavigation(), page.locator('table.vypis a', { hasText: 'Add subcategory' }).first().click()]);
  if (await page.locator('#parent_id').inputValue() === '0') { throw new Error('Add subcategory does not preselect the parent'); }
  await page.fill('#name', 'Medical');
  await Promise.all([page.waitForNavigation(), page.locator('form.formular input[type="submit"]').click()]);
  const rows = await page.locator('table.vypis tbody tr').allTextContents();
  if (rows.length !== 2 || !/\/equipment\/treadmills\/medical/.test(rows[1])) { throw new Error(`the category tree: ${rows.join(' | ')}`); }
  if (SHOTS) { await page.screenshot({ path: `${SHOTS}/collection-categories.png`, fullPage: true }); }
  for (const name of ['Belt A', 'Belt B', 'Belt C']) {
    await visit('/admin.php?module=collections&action=categories&id=' + equipment);
    await Promise.all([page.waitForNavigation(), page.locator('table.vypis tbody tr').nth(1).locator('a', { hasText: 'Add item' }).click()]);
    if (!(await page.locator('.kategorie-polozky label', { hasText: 'Medical' }).locator('input').isChecked())) { throw new Error('Add item from a category does not tick it'); }
    await page.fill('#nazev', name);
    await Promise.all([page.waitForNavigation(), page.locator('form.formular input[type="submit"]').first().click()]);
  }
  const visitor = await browser.newContext();
  const v = await visitor.newPage();
  watch(v);
  await v.goto(`${BASE}/equipment/treadmills`, { waitUntil: 'networkidle' });
  if (await v.locator('h1').textContent() !== 'Treadmills') { throw new Error('the category page has no heading'); }
  if (await v.locator('a[href="/equipment/treadmills/medical"]').count() !== 1 || await v.locator('a[href="/equipment/belt-b"]').count() !== 1) { throw new Error('the category page lists neither the subcategory nor the items'); }
  if (await v.locator('nav.ka-drobecky [aria-current="page"]').textContent() !== 'Treadmills') { throw new Error('the category page has no breadcrumbs'); }
  await visitor.close();
});

await step('3.7 builder: Previous / next item in the item template, a nav landmark with rel links on the item page', async () => {
  await visit('/admin.php?module=collections&action=builder&id=' + equipment);
  await page.waitForTimeout(1500);
  await page.locator('.st-zalozky [role="tab"]').first().click();
  await page.locator('.st-prvky button', { hasText: /^Previous \/ next item$/ }).first().click();
  await page.waitForTimeout(900);
  await canvas().locator('nav.ka-predchozi-dalsi').waitFor();
  await page.locator('.st-lista button.st-tl-hlavni').click();
  const anyway = page.locator('dialog[open] .st-tl-hlavni');
  if (await anyway.waitFor({ timeout: 1500 }).then(() => true, () => false)) { await anyway.click(); }
  await page.waitForTimeout(1200);
  const visitor = await browser.newContext();
  const v = await visitor.newPage();
  watch(v);
  await v.goto(`${BASE}/equipment/belt-b`, { waitUntil: 'networkidle' });
  const nav = v.locator('nav.ka-predchozi-dalsi');
  if (!(await nav.getAttribute('aria-label'))) { throw new Error('the previous / next navigation has no label'); }
  if (await nav.locator('a[rel="prev"][href="/equipment/belt-a"]').count() !== 1 || await nav.locator('a[rel="next"][href="/equipment/belt-c"]').count() !== 1) {
    throw new Error('the item page does not link its neighbours with rel="prev" and rel="next"');
  }
  await nav.locator('a[rel="next"]').focus();
  if (SHOTS) { await v.screenshot({ path: `${SHOTS}/previous-next-item.png`, fullPage: true }); }
  await visitor.close();
});

// 3.23 (U10): references to the site's data are choices by name – services and people of Bookings, collections, their
// fields and categories; "Custom…" keeps a free value, a stored one the site lacks shows as "not found" (never dropped)
await step('3.23 (U10) builder: Booking, Store locator, Collection list and Previous / next offer their data by name; Custom… and not found', async () => {
  const until = async (fn, what, ms = 6000) => { const end = Date.now() + ms; for (;;) { const v = await fn(); if (v) { return v; } if (Date.now() > end) { throw new Error(what); } await page.waitForTimeout(150); } };
  const draft = () => sql('SELECT stavba_koncept FROM ka_stranky WHERE ids = 1');
  const choice = (label) => page.locator('.st-pravy label.st-volba', { has: page.locator('span', { hasText: new RegExp('^' + label + '$') }) });
  const optionTexts = async (label) => (await choice(label).locator('select option').allTextContents()).map((n) => n.trim());
  const insertElement = async (name) => {
    await page.locator('.st-zalozky [role="tab"]').first().click();
    await page.locator('.st-prvky button', { hasText: new RegExp('^' + name + '$') }).first().click();
    await page.waitForTimeout(900);
  };
  await visit('/admin.php?module=pages&action=builder&id=1');
  await page.waitForTimeout(1500);
  await insertElement('Booking');
  const services = await optionTexts('Service');
  const people = await optionTexts('Person');
  if (services[0] !== 'The visitor chooses' || !services.includes('Browser consultation') || services.at(-1) !== 'Custom…') { throw new Error(`the Service choice offers ${JSON.stringify(services)}`); }
  if (!people.includes('Browser Staff') || people.some((n) => n.includes('@'))) { throw new Error(`the Person choice offers ${JSON.stringify(people)}`); }
  await choice('Service').locator('select').selectOption({ label: 'Browser consultation' });
  await until(() => /"sluzba":900[,}]/.test(draft()), 'choosing the service did not save its id');
  await choice('Person').locator('select').selectOption({ label: 'Custom…' });
  const free = choice('Person').locator('input[type="number"]');
  if (!(await free.isVisible()) || !(await free.evaluate((e) => e === document.activeElement))) { throw new Error('Custom… does not open a focused free field'); }
  await free.fill('4242');
  await until(() => /"osoba":4242[,}]/.test(draft()), 'the custom person id was not saved');
  if (SHOTS) { await page.locator('.st-pravy').screenshot({ path: `${SHOTS}/u10-booking-choices.png` }); }
  // the stored id the site does not have: after a reload it is shown, marked, and still saved as it was
  await visit('/admin.php?module=pages&action=builder&id=1');
  await page.waitForTimeout(1500);
  await canvas().locator('.ka-rezervace').last().click({ position: { x: 6, y: 6 } });
  await page.getByRole('tab', { name: 'Content' }).first().click().catch(() => {});
  await choice('Person').waitFor();
  const shown = (await choice('Person').locator('select option:checked').textContent()).trim();
  if (shown !== '4242 (not found)') { throw new Error(`a missing person shows as ${JSON.stringify(shown)}`); }
  await page.locator('.st-hlava-prvku button.nebezpecne').first().click(); // leave the page as it was
  await page.waitForTimeout(900);
  // Store locator: without a collection it means the first Branches one; the location field lists location fields only
  await insertElement('Store locator');
  const collections = await optionTexts('Collection');
  if (collections[0] !== 'The first Branches collection' || !collections.includes('Equipment')) { throw new Error(`the Store locator collection offers ${JSON.stringify(collections)}`); }
  await choice('Collection').locator('select').selectOption({ label: 'Equipment' });
  await page.waitForTimeout(400);
  const location = await optionTexts('Location field');
  if (location.join('|') !== 'The first location field|location (not found)|Custom…') { throw new Error(`Equipment (no location field) offers ${JSON.stringify(location)}`); }
  await page.locator('.st-hlava-prvku button.nebezpecne').first().click();
  await page.waitForTimeout(900);
  // Collection list: the categories of the chosen collection, a subcategory under its parent, and * for the category shown
  await insertElement('Collection list');
  await page.locator('.st-pravy .st-pole', { hasText: /^Collections/ }).locator('select').first().selectOption({ label: 'Equipment' });
  await page.waitForTimeout(600);
  const categories = await optionTexts('Category');
  if (!categories.includes('Treadmills') || !categories.includes('Treadmills › Medical') || !categories.some((n) => /^The category shown/.test(n))) { throw new Error(`the Category choice offers ${JSON.stringify(categories)}`); }
  await choice('Category').locator('select').selectOption({ label: 'Treadmills › Medical' });
  await until(() => /"kategorie":"medical"/.test(draft()), 'choosing the category did not save its address');
  if (SHOTS) { await page.locator('.st-pravy').screenshot({ path: `${SHOTS}/u10-collection-list-category.png` }); }
  await page.locator('.st-hlava-prvku button.nebezpecne').first().click();
  await page.waitForTimeout(1500);
  // Previous / next in the item template: the thumbnail field is a choice of the collection's image fields
  await visit('/admin.php?module=collections&action=builder&id=' + equipment);
  await page.waitForTimeout(1500);
  await canvas().locator('nav.ka-predchozi-dalsi').first().click({ position: { x: 4, y: 4 } });
  await page.getByRole('tab', { name: 'Content' }).first().click().catch(() => {});
  const thumbnails = await optionTexts('Thumbnail field');
  if (thumbnails[0] !== 'The first image field' || !thumbnails.includes('Image') || thumbnails.includes('Description')) { throw new Error(`the thumbnail field offers ${JSON.stringify(thumbnails)}`); }
});

// 3.23 (U15): at tablet width the panels fold into icon rails and open over the canvas; a desktop keeps its three columns
await step('3.23 (U15) builder at 820 px: rails, canvas ≥ 70 %, the Add panel over it in two columns without clipped names, Esc and a tap close it; 1440 px unchanged', async () => {
  await page.setViewportSize({ width: 820, height: 1000 });
  try {
    await visit('/admin.php?module=pages&action=builder&id=1');
    await page.waitForTimeout(1500);
    const layout = await page.evaluate(() => ({ canvas: document.querySelector('.st-platno').getBoundingClientRect().width / innerWidth,
      left: !!document.querySelector('.st-levy').offsetParent, right: !!document.querySelector('.st-pravy').offsetParent,
      publish: document.querySelector('.st-lista .st-tl-hlavni').getBoundingClientRect().right <= innerWidth, scroll: document.documentElement.scrollWidth <= innerWidth }));
    if (layout.canvas < 0.7 || layout.left || layout.right || !layout.publish || !layout.scroll) { throw new Error(`the tablet layout: ${JSON.stringify(layout)}`); }
    const add = page.locator('.st-pruh-levy button[aria-label="Add"]');
    await add.click();
    await page.locator('.st-levy .st-prvky').first().waitFor();
    const grid = await page.evaluate(() => ({ columns: getComputedStyle(document.querySelector('.st-prvky')).gridTemplateColumns.split(' ').length,
      clipped: [...document.querySelectorAll('.st-prvky button')].filter((b) => b.scrollWidth > b.clientWidth + 1).map((b) => b.textContent.trim()),
      over: document.querySelector('.st-levy').getBoundingClientRect().left < document.querySelector('.st-platno').getBoundingClientRect().left + 4 }));
    if (grid.columns !== 2 || grid.clipped.length || !grid.over || (await add.getAttribute('aria-expanded')) !== 'true') { throw new Error(`the Add panel: ${JSON.stringify(grid)}`); }
    if (SHOTS) { await page.screenshot({ path: `${SHOTS}/u15-builder-820-add.png` }); }
    await page.keyboard.press('Escape');
    if (await page.locator('.st-levy').isVisible() || !(await add.evaluate((b) => b === document.activeElement))) { throw new Error('Esc does not close the panel and give the focus back to its rail button'); }
    await page.locator('.st-pruh-levy button[aria-label="Structure"]').click();
    if ((await page.locator('.st-levy [role="tab"][aria-selected="true"]').textContent()).trim() !== 'Structure') { throw new Error('the Structure rail button does not open the Structure tab'); }
    await canvas().locator('body').click({ position: { x: 500, y: 200 } }); // right of the open panel (the position is in the scaled page's pixels)
    await page.waitForTimeout(300);
    if (await page.locator('.st-levy').isVisible()) { throw new Error('a tap on the page does not close the panel'); }
    // 3.23.1 (N-U13): when the tap selected an element, that first selection opened Properties – closed here to try the rail button
    if (await page.locator('.st-pravy').isVisible()) { await page.keyboard.press('Escape'); }
    await page.locator('.st-pruh-pravy button').click();
    await page.locator('.st-pravy').waitFor({ state: 'visible' });
    if (SHOTS) { await page.screenshot({ path: `${SHOTS}/u15-builder-820-properties.png` }); }
    await page.keyboard.press('Escape');
    if (await page.locator('.st-pravy').isVisible()) { throw new Error('Esc does not close the Properties panel'); }
  } finally {
    await page.setViewportSize({ width: 1440, height: 900 });
  }
  await page.waitForTimeout(300);
  const desktop = await page.evaluate(() => ({ rails: [...document.querySelectorAll('.st-pruh')].some((r) => r.offsetParent), left: !!document.querySelector('.st-levy').offsetParent,
    right: !!document.querySelector('.st-pravy').offsetParent }));
  if (desktop.rails || !desktop.left || !desktop.right) { throw new Error(`1440 px is not the desktop layout: ${JSON.stringify(desktop)}`); }
});

// 3.12 (UX310-28): sending a form on a phone and coming back to the page leaves no script error ("Transition was aborted
// because of invalid state") – the watch() of the page fails the step on any console error
await step('3.12 contact form sent on a phone: no console error after the answer page', async () => {
  const visitor = await browser.newContext({ viewport: { width: 390, height: 844 }, locale: 'en-GB', isMobile: true, hasTouch: true });
  const v = await visitor.newPage();
  watch(v);
  try {
    await v.goto(`${BASE}/contact`, { waitUntil: 'networkidle' });
    const form = v.locator('form.ka-formular, main form[method="post"]').first();
    if (!(await form.count())) { throw new Error('the contact page has no form'); }
    await v.waitForTimeout(3500); // the antispam signature refuses a form sent within seconds of loading
    for (const input of await form.locator('input:visible:not([tabindex="-1"]), textarea:visible, select:visible').all()) {
      if ((await input.evaluate((n) => n.tagName)) === 'SELECT') { continue; }
      const type = await input.getAttribute('type');
      if (type === 'checkbox') { await input.check(); } else if (type === 'email') { await input.fill('visitor@example.com'); } else if (type === 'tel') { await input.fill('+420 123 456 789'); }
      else if (!['submit', 'hidden', 'radio', 'file'].includes(type || '')) { await input.fill('Browser test 3.12'); }
    }
    await Promise.all([v.waitForNavigation(), form.locator('button[type="submit"], input[type="submit"]').first().click()]);
    await v.waitForTimeout(1500);
    if (!/[?&]result=/.test(v.url())) { throw new Error(`the form answer has no result in the address: ${v.url()}`); }
  } finally {
    await visitor.close();
  }
});

// 3.13: the Spanish admin – My account switches the language; the dashboard, the pages list, Settings and My account are
// Spanish (with the Spanish script texts) and, with the longer Spanish strings, none scrolls sideways on a 390 px phone
// 3.14: every employee copies their own e-mail signature – an administrator links the account to a Team record, My account
// shows the signature with a working Copy button, on a phone too without sideways scroll
await step('3.14 My e-mail signature: a Team record linked in Users, the signature and its Copy button in My account', async () => {
  await visit('/admin.php?module=collections');
  // the ready-made collections are folded once the site has a collection (an earlier step made one)
  await page.locator('details.predvolby-kolekci').evaluate((d) => { d.open = true; });
  await Promise.all([page.waitForNavigation(), page.locator('button[name="preset"][value="people"]').click()]);
  await Promise.all([page.waitForNavigation(), page.locator('.navigace-radek a.tl[href*="action=item"]').first().click()]);
  await page.fill('#nazev', 'Tess Tester');
  await page.locator('input[id^="pole-"][id*="mail"]').first().fill('tess@example.com');
  await Promise.all([page.waitForNavigation(), page.locator('form.formular input[type="submit"]').first().click()]);
  await visit('/admin.php?module=users&action=edit&id=1');
  const option = await page.locator('#team_item_id option', { hasText: 'Tess Tester' }).getAttribute('value');
  await page.selectOption('#team_item_id', option);
  await Promise.all([page.waitForNavigation(), page.locator('form.formular input[type="submit"]').first().click()]);
  await visit('/admin.php?module=users');
  if (!(await page.locator('td', { hasText: 'Team record: Tess Tester (chosen)' }).count())) { throw new Error('the Users list does not show the link'); }
  await visit('/admin.php?action=account&tab=signature');
  if (!(await page.locator('[data-podpis-nahled]', { hasText: 'Tess Tester' }).count())) { throw new Error('My account shows no signature of the linked record'); }
  const button = page.locator('[data-kopirovat-podpis]');
  await button.click();
  await page.waitForFunction(() => document.querySelector('[data-kopirovat-podpis]').textContent !== 'Copy signature');
  try {
    await page.setViewportSize({ width: 390, height: 844 });
    await visit('/admin.php?action=account&tab=signature');
    const wider = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    if (wider > 1) { throw new Error(`My e-mail signature scrolls sideways by ${wider} px on a phone`); }
    if (SHOTS) { await page.screenshot({ path: `${SHOTS}/account-signature-phone.png`, fullPage: true }); }
  } finally {
    await page.setViewportSize({ width: 1440, height: 900 });
  }
});

// 3.17: the inbox of the fleet console (Fleet\Inbox) – every row with a plain link out, no sideways scroll; on a phone the rows are cards
await step('3.17 fleet inbox: rows with plain links, the filters, no sideways scroll at 1440 and 390 px (cards on a phone)', async () => {
  try {
    for (const [width, height, name] of [[1440, 900, 'desktop'], [390, 844, 'phone']]) {
      await page.setViewportSize({ width, height });
      for (const url of ['/admin.php?module=fleet&action=inbox', '/admin.php?module=fleet&action=sites&show=all', '/admin.php?module=fleet&action=customers']) {
        await visit(url);
        const wider = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
        if (wider > 1) { throw new Error(`${url} scrolls sideways by ${wider} px at ${width} px`); }
        if (SHOTS) { await page.screenshot({ path: `${SHOTS}/fleet-${url.replace(/.*module=fleet&?/, '').replace(/\W+/g, '-') || 'inbox'}-${name}.png`, fullPage: true }); }
      }
      await visit('/admin.php?module=fleet&action=inbox');
      const rows = page.locator('table.vypis-karty tbody tr');
      if (await rows.count() < 10) { throw new Error(`the inbox lists ${await rows.count()} rows`); }
      const out = await page.locator('table.vypis-karty a[target="_blank"]').evaluateAll((links) => links.map((a) => [a.href, a.rel]));
      if (!out.some(([href]) => href === 'https://smile-dental-clinic-with-a-long-domain.example/admin.php?module=requests&action=detail&id=12')) { throw new Error('no plain link to the request'); }
      if (out.some(([href, rel]) => rel !== 'noopener' || /token|password|login/i.test(href))) { throw new Error('a link out is not plain'); }
      const first = await rows.first().locator('.stitek').last().textContent();
      if (!/urgent/.test(first)) { throw new Error(`the first row is not urgent: ${first}`); }
      if (width === 390 && await rows.first().evaluate((tr) => getComputedStyle(tr).display) !== 'block') { throw new Error('the rows are not cards on a phone'); }
    }
    await page.setViewportSize({ width: 1440, height: 900 });
    await visit('/admin.php?module=fleet&action=inbox&urgent=1');
    if (await page.locator('table.vypis-karty .stitek-chyba').count() !== await page.locator('table.vypis-karty tbody tr').count()) { throw new Error('"only urgent" lists other rows'); }
  } finally {
    await page.setViewportSize({ width: 1440, height: 900 });
  }
});

// 3.29: the console opens on Today (ranked items with plain links out), the health matrix (a chip per check, the site column
// fixed while the table scrolls inside its frame, a card per site on a phone) and the workload (sortable, a totals row) – at
// 1440 and 390 px, light and dark, none scrolls the page sideways; axe finds nothing in the three screens
await step('3.29 fleet console: Today, Health and Workload at 1440 and 390 px, light and dark, no sideways scroll, axe clean', async () => {
  const known = ['https://smile-dental-clinic-with-a-long-domain.example/', 'https://booking.smile.example/', 'https://bakery.example/', 'https://shop.smile.example/', 'https://northwind-architects.example/'];
  try {
    for (const scheme of ['light', 'dark']) {
      await page.emulateMedia({ colorScheme: scheme });
      for (const [width, height, name] of [[1440, 900, 'desktop'], [390, 844, 'phone']]) {
        await page.setViewportSize({ width, height });
        for (const [url, shot] of [['/admin.php?module=fleet', 'today'], ['/admin.php?module=fleet&action=health', 'health'], ['/admin.php?module=fleet&action=workload', 'workload']]) {
          await visit(url);
          const wider = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
          if (wider > 1) { throw new Error(`${url} scrolls sideways by ${wider} px at ${width} px (${scheme})`); }
          if (SHOTS) { await page.screenshot({ path: `${SHOTS}/fleet-329-${shot}-${name}-${scheme}.png`, fullPage: true }); }
        }
        await visit('/admin.php?module=fleet');
        if (!(await page.locator('nav.zalozky a.aktivni', { hasText: 'Today' }).count())) { throw new Error('the console does not open on Today'); }
        const rows = page.locator('table.flotila-dnes tbody tr');
        if (await rows.count() < 5) { throw new Error(`Today lists ${await rows.count()} items`); }
        if (!/flotila-bad/.test(await rows.first().getAttribute('class'))) { throw new Error('the first item of Today is not red'); }
        const out = await page.locator('table.flotila-dnes a[target="_blank"]').evaluateAll((links) => links.map((a) => [a.href, a.rel]));
        if (out.some(([href, rel]) => rel !== 'noopener' || !known.some((k) => href.startsWith(k)) || /token|password|login|session/i.test(href))) { throw new Error('an Open link is not a plain link to a known site'); }
        if (!out.some(([href]) => href === 'https://northwind-architects.example/admin.php?module=settings&tab=security')) { throw new Error('no link to fix the two-step sign-in'); }
        if (width === 390 && await rows.first().evaluate((tr) => getComputedStyle(tr).display) !== 'block') { throw new Error('Today is not cards on a phone'); }
        await visit('/admin.php?module=fleet&action=health');
        const site = page.locator('table.flotila-matice tbody tr:not(.flotila-skupina)');
        if (await site.count() !== 5) { throw new Error(`the matrix has ${await site.count()} sites`); }
        if (await page.locator('table.flotila-matice thead th[title]').count() !== 18) { throw new Error('the matrix has not 18 check columns'); }
        const chips = await site.first().locator('.flotila-stav').evaluateAll((c) => c.map((x) => [x.textContent.trim().length > 0, x.querySelector('.navod-skryte')?.textContent || '']));
        if (chips.length !== 18 || chips.some(([text, sr]) => !text || !/: /.test(sr))) { throw new Error('a matrix cell has no symbol or no text for screen readers'); }
        if (!(await page.locator('table.flotila-matice th[scope="row"] .stitek', { hasText: 'Update the site to see' }).count())) { throw new Error('the sites before 3.29 are not marked'); }
        if (width === 1440 && await site.first().locator('th').evaluate((th) => getComputedStyle(th).position) !== 'sticky') { throw new Error('the site column is not fixed'); }
        if (width === 390 && await site.first().evaluate((tr) => getComputedStyle(tr).display) !== 'flex') { throw new Error('the matrix is not a card per site on a phone'); }
        await visit('/admin.php?module=fleet&action=workload&sort=tasks_open&dir=desc');
        const first = await page.locator('table.flotila-prace tbody tr').first().locator('th').textContent();
        if (!/Northwind/.test(first)) { throw new Error(`the workload sorted by open tasks starts with ${first}`); }
        if (!(await page.locator('table.flotila-prace tfoot').count()) || !(await page.locator('table.flotila-prace tbody td', { hasText: '–' }).count())) { throw new Error('the workload has no totals or no "–" for the older sites'); }
      }
    }
    // between a phone and a computer the matrix scrolls inside its frame, never the page, and the site column stays put
    await page.emulateMedia({ colorScheme: 'light' });
    await page.setViewportSize({ width: 820, height: 900 });
    await visit('/admin.php?module=fleet&action=health');
    const frame = await page.locator('.flotila-matice-obal').evaluate((f) => [f.scrollWidth - f.clientWidth, getComputedStyle(f).overflowX]);
    const page820 = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    if (frame[0] < 1 || frame[1] !== 'auto' || page820 > 1) { throw new Error(`at 820 px the matrix frame scrolls ${frame[0]} px (${frame[1]}), the page ${page820} px`); }
    const before = await page.locator('table.flotila-matice tbody th[scope="row"]').first().boundingBox();
    await page.locator('.flotila-matice-obal').evaluate((f) => { f.scrollLeft = 300; });
    const after = await page.locator('table.flotila-matice tbody th[scope="row"]').first().boundingBox();
    if (!before || !after || Math.abs(before.x - after.x) > 1) { throw new Error('the site column moves with the scrolled matrix'); }
    if (SHOTS) { await page.screenshot({ path: `${SHOTS}/fleet-329-health-820.png`, fullPage: true }); }
  } finally {
    await page.emulateMedia({ colorScheme: 'light' });
    await page.setViewportSize({ width: 1440, height: 900 });
  }
  const axeContext = await browser.newContext({ storageState: await context.storageState(), bypassCSP: true, viewport: { width: 1440, height: 900 } });
  try {
    const axePage = await axeContext.newPage();
    for (const [url, include] of [['/admin.php?module=fleet', ['.flotila-souhrn', 'table.flotila-dnes']], ['/admin.php?module=fleet&action=health', ['.flotila-legenda', 'table.flotila-matice']],
      ['/admin.php?module=fleet&action=workload', ['table.flotila-prace']]]) {
      for (const scheme of ['light', 'dark']) {
        await axePage.emulateMedia({ colorScheme: scheme });
        await axePage.goto(BASE + url, { waitUntil: 'networkidle' });
        await axePage.addScriptTag({ path: AXE });
        const found = await axePage.evaluate(([inc, tags]) => window.axe.run({ include: inc.map((s) => [s]) }, { runOnly: { type: 'tag', values: tags }, resultTypes: ['violations'] }), [include, WCAG]);
        if (found.violations.length) { throw new Error(`axe ${url} (${scheme}): ` + found.violations.map((v) => `${v.id} ${v.nodes[0].target.join(' ')}`).join(', ')); }
      }
    }
  } finally {
    await axeContext.close();
  }
});

// 3.30 (agent Y): console tasks – the task panel on a site's screen (with the site's own Claude connector and Copy) and on a
// customer's screen, the Tasks counts of Sites and Customers, the overdue task on Today (amber, opening the task on the console)
// and in My work – at 1440 and 390 px, light and dark, no sideways scroll, axe clean in the panel, the connector and the sites
await step('3.30 console tasks: the panels on the site and customer screens, the connector, Sites, Customers, Today and My work (1440 and 390 px, light/dark, axe)', async () => {
  if (!MYSQL.length) { throw new Error('MYSQL_JSON is not set'); }
  const submit = async (locator) => { await Promise.all([page.waitForNavigation(), locator.click()]); };
  const customer = Number(sql('SELECT customer_id FROM ka_fleet_sites WHERE id = 900').trim());
  const past = new Date(Date.now() - 3 * 86400000).toISOString().slice(0, 10);
  try {
    await page.setViewportSize({ width: 1440, height: 900 });
    // the site: its connector (the known address + /mcp, no token) and a task overdue for me
    await visit('/admin.php?module=fleet&action=detail&id=900');
    if ((await page.locator('[data-fleet-connector] code').innerText()) !== 'https://shop.smile.example/mcp') { throw new Error('the connector of the site is not its /mcp address'); }
    if (!(await page.locator('[data-fleet-connector] button[data-kopirovat="#fleet-connector"]').count())) { throw new Error('the connector has no Copy button'); }
    const panel = page.locator('#tasks[data-tasks="fleet_site:900"]');
    await panel.locator('.ukol-pridat > summary').click();
    const form = panel.locator('form[data-task-add]');
    await form.locator('input[name="title"]').fill('Renew the domain of the Smile shop');
    await form.locator('input[name="due_date"]').fill(past);
    await submit(form.locator('button[type="submit"].tl'));
    await page.locator('.hlaska-ok', { hasText: 'The task was added.' }).waitFor();
    const row = panel.locator('[data-tasks-open] .ukol', { hasText: 'Renew the domain of the Smile shop' });
    if (!(await row.locator('.stitek-chyba', { hasText: 'overdue' }).count())) { throw new Error('the overdue site task is not marked overdue'); }
    // the customer: its screen from Customers, a task for tomorrow by the quick button
    await visit('/admin.php?module=fleet&action=customers');
    const counted = await page.locator(`a[href*="action=customer_detail&id=${customer}"]`).count();
    if (counted < 1) { throw new Error('Customers do not link to the customer\'s screen'); }
    await submit(page.locator(`a[href$="action=customer_detail&id=${customer}"]`).first());
    if (!(await page.locator('[data-customer-sites] a[href*="action=detail&id=900"]').count())) { throw new Error('the customer\'s screen does not list its sites'); }
    const cpanel = page.locator(`#tasks[data-tasks="fleet_customer:${customer}"]`);
    await cpanel.locator('.ukol-pridat > summary').click();
    await cpanel.locator('form[data-task-add] input[name="title"]').fill('Check the prices before January');
    await submit(cpanel.locator('form[data-task-add] .ukol-rychle button', { hasText: 'Tomorrow' }));
    if (!(await cpanel.locator('[data-tasks-open] .ukol-nazev', { hasText: 'Check the prices before January' }).count())) { throw new Error('the customer task is not listed'); }
    // Today: the overdue task among the checks – amber, the first item still red, its Open stays on the console
    await visit('/admin.php?module=fleet');
    const item = page.locator('table.flotila-dnes tr[data-fleet-task]');
    if (await item.count() !== 1 || !/flotila-warn/.test(await item.getAttribute('class'))) { throw new Error('Today does not show the overdue console task as one amber item'); }
    if (!/flotila-bad/.test(await page.locator('table.flotila-dnes tbody tr').first().getAttribute('class'))) { throw new Error('the first item of Today is not red'); }
    const open = item.locator('a.navigace');
    if (!(await open.getAttribute('href')).endsWith('action=detail&id=900#tasks') || await open.getAttribute('target')) { throw new Error('the task on Today does not open on the console'); }
    if (!(await page.locator('[data-fleet-overdue-tasks]').count())) { throw new Error('the summary does not count the overdue tasks'); }
    // My work: both, with what they are about
    await visit('/admin.php?module=my_work');
    for (const [title, about] of [['Renew the domain of the Smile shop', 'Site “Smile Dental – shop”'], ['Check the prices before January', 'Console customer “Dental Clinic Smile and Partners International Ltd”']]) {
      if (!(await page.locator('[data-list="tasks"] tr[data-task]', { hasText: title }).locator('a', { hasText: about }).count())) { throw new Error(`My work does not show “${title}” about ${about}`); }
    }
    // light and dark, desktop and phone: the screens fit; the task text is readable on its card
    const state = await context.storageState();
    for (const scheme of ['light', 'dark']) {
      for (const [label, width, height] of [['desktop', 1440, 900], ['phone', 390, 844]]) {
        const other = await browser.newContext({ viewport: { width, height }, locale: 'en-GB', colorScheme: scheme, storageState: state, ...(width < 500 ? { isMobile: true, hasTouch: true } : {}) });
        const p2 = await other.newPage(); watch(p2);
        try {
          for (const [url, shot] of [['/admin.php?module=fleet&action=detail&id=900', 'site'], [`/admin.php?module=fleet&action=customer_detail&id=${customer}`, 'customer'],
            ['/admin.php?module=fleet&action=sites&show=all', 'sites'], ['/admin.php?module=fleet&action=customers', 'customers'], ['/admin.php?module=fleet', 'today']]) {
            await p2.goto(BASE + url, { waitUntil: 'networkidle' });
            if (await p2.locator('#tasks .ukol-pridat').count()) { await p2.locator('#tasks .ukol-pridat').evaluate((d) => { d.open = true; }); }
            const wide = await p2.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
            if (wide > 0) { throw new Error(`${url}: ${wide} px of sideways scroll (${label}, ${scheme})`); }
            if (await p2.locator('#tasks .ukol').count()) {
              const colours = await p2.locator('#tasks .ukol').first().evaluate((el) => [getComputedStyle(el).backgroundColor, getComputedStyle(el.querySelector('.ukol-nazev')).color]);
              if (colours[0] === colours[1]) { throw new Error(`${url}: the task text has the colour of its card (${label}, ${scheme})`); }
            }
            if (SHOTS) { await p2.screenshot({ path: `${SHOTS}/fleet-330-${shot}-${label}-${scheme}.png`, fullPage: true }); }
          }
        } finally {
          await other.close();
        }
      }
    }
    const axeContext = await browser.newContext({ storageState: state, bypassCSP: true, viewport: { width: 1440, height: 900 } });
    try {
      const axePage = await axeContext.newPage();
      for (const [url, include] of [['/admin.php?module=fleet&action=detail&id=900', ['#tasks', '[data-fleet-connector]']], [`/admin.php?module=fleet&action=customer_detail&id=${customer}`, ['#tasks', '[data-customer-sites]']],
        ['/admin.php?module=fleet', ['table.flotila-dnes']]]) {
        for (const scheme of ['light', 'dark']) {
          await axePage.emulateMedia({ colorScheme: scheme });
          await axePage.goto(BASE + url, { waitUntil: 'networkidle' });
          await axePage.addScriptTag({ path: AXE });
          const found = await axePage.evaluate(([inc, tags]) => window.axe.run({ include: inc.map((s) => [s]) }, { runOnly: { type: 'tag', values: tags }, resultTypes: ['violations'] }), [include, WCAG]);
          if (found.violations.length) { throw new Error(`axe ${url} (${scheme}): ` + found.violations.map((v) => `${v.id} ${v.nodes[0].target.join(' ')}`).join(', ')); }
        }
      }
    } finally {
      await axeContext.close();
    }
    // done from the site's panel: Today has no task item any more
    await visit('/admin.php?module=fleet&action=detail&id=900');
    await submit(page.locator('#tasks [data-tasks-open] .ukol', { hasText: 'Renew the domain of the Smile shop' }).locator('button.ukol-hotovo'));
    await visit('/admin.php?module=fleet');
    if (await page.locator('tr[data-fleet-task]').count()) { throw new Error('a done console task is still on Today'); }
  } finally {
    await page.setViewportSize({ width: 1440, height: 900 });
    sql("DELETE FROM ka_notifications WHERE target LIKE 'fleet%'; DELETE FROM ka_tasks WHERE target LIKE 'fleet%'");
  }
});

// 3.30: the inventory (a row per site, a filter per column, the CSV export), the expiry calendar (a month of dates – on a phone only the
// days with a date – the list, the recovery keys) and the rollout (the rings, the sites, a window per customer) – at 1440 and 390 px,
// light and dark, none scrolls the page sideways; axe finds nothing in the three screens
await step('3.30 fleet console: Inventory, Calendar and Rollout at 1440 and 390 px, light and dark, no sideways scroll, axe clean', async () => {
  const soon = new Date(Date.now() + 5 * 86400000);
  const month = `${soon.getFullYear()}-${String(soon.getMonth() + 1).padStart(2, '0')}`;
  const screens = [['/admin.php?module=fleet&action=inventory', 'inventory'], [`/admin.php?module=fleet&action=calendar&month=${month}`, 'calendar'], ['/admin.php?module=fleet&action=rollout', 'rollout']];
  try {
    for (const scheme of ['light', 'dark']) {
      await page.emulateMedia({ colorScheme: scheme });
      for (const [width, height, name] of [[1440, 900, 'desktop'], [390, 844, 'phone']]) {
        await page.setViewportSize({ width, height });
        for (const [url, shot] of screens) {
          await visit(url);
          const wider = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
          if (wider > 1) { throw new Error(`${url} scrolls sideways by ${wider} px at ${width} px (${scheme})`); }
          if (SHOTS) { await page.screenshot({ path: `${SHOTS}/fleet-330-${shot}-${name}-${scheme}.png`, fullPage: true }); }
        }
        await visit(screens[0][0]);
        if (!(await page.locator('nav.zalozky a.aktivni', { hasText: 'Inventory' }).count())) { throw new Error('the Inventory tab is not current'); }
        const rows = page.locator('table.flotila-inventar tbody tr');
        if (await rows.count() < 5) { throw new Error(`the inventory lists ${await rows.count()} sites`); }
        if (!(await page.locator('table.flotila-inventar tbody td', { hasText: 'MariaDB 10.11.6' }).count())) { throw new Error('the inventory does not show the database'); }
        if (!(await page.locator('form.flotila-filtry select[name="php"] option[value="8.3"]').count())) { throw new Error('no PHP 8.3 in the PHP filter'); }
        if (!/action=inventory_csv/.test(await page.locator('form.flotila-filtry a[download]').getAttribute('href'))) { throw new Error('no CSV export link'); }
        if (width === 390 && await rows.first().evaluate((tr) => getComputedStyle(tr).display) !== 'block') { throw new Error('the inventory is not cards on a phone'); }
        await visit(screens[1][0]);
        const bad = page.locator('table.flotila-kalendar a.flotila-stav-bad', { hasText: 'Northwind' });
        if (!(await bad.count())) { throw new Error('the month has no red chip for the certificate of Northwind'); }
        if (!(await bad.first().locator('.navod-skryte').textContent()).includes('Certificate')) { throw new Error('a calendar chip has no text for screen readers'); }
        if (width === 390) {
          const shown = await page.locator('table.flotila-kalendar td').evaluateAll((tds) => tds.filter((td) => getComputedStyle(td).display !== 'none').map((td) => td.classList.contains('flotila-ma')));
          if (!shown.length || shown.some((has) => !has)) { throw new Error('on a phone the month shows days without a date'); }
        }
        if (!(await page.locator('table.flotila-terminy tbody tr').count()) || !(await page.locator('table.flotila-klice').count())) { throw new Error('no list of dates or recovery keys'); }
        await visit(screens[2][0]);
        if (await page.locator('table.flotila-okruhy tbody tr').count() !== 3) { throw new Error('the rollout has not three rings'); }
        if (!(await page.locator('table.flotila-rollout tbody tr', { hasText: 'Smile Dental – shop' }).locator('td', { hasText: 'Pilot' }).count())) { throw new Error('the pilot site is not shown in its ring'); }
        if (!(await page.locator('form.flotila-okno input[name="window_days[]"][value="1"]:checked').count())) { throw new Error('the customer\'s window is not ticked'); }
      }
    }
  } finally {
    await page.emulateMedia({ colorScheme: 'light' });
    await page.setViewportSize({ width: 1440, height: 900 });
  }
  const axeContext = await browser.newContext({ storageState: await context.storageState(), bypassCSP: true, viewport: { width: 1440, height: 900 } });
  try {
    const axePage = await axeContext.newPage();
    for (const [url, include] of [[screens[0][0], ['form.flotila-filtry', 'table.flotila-inventar']], [screens[1][0], ['.flotila-mesice', 'table.flotila-kalendar', 'table.flotila-terminy', 'table.flotila-klice']],
      [screens[2][0], ['table.flotila-okruhy', 'table.flotila-rollout', 'form.flotila-okno']]]) {
      for (const scheme of ['light', 'dark']) {
        await axePage.emulateMedia({ colorScheme: scheme });
        await axePage.goto(BASE + url, { waitUntil: 'networkidle' });
        await axePage.addScriptTag({ path: AXE });
        const found = await axePage.evaluate(([inc, tags]) => window.axe.run({ include: inc.map((s) => [s]) }, { runOnly: { type: 'tag', values: tags }, resultTypes: ['violations'] }), [include, WCAG]);
        if (found.violations.length) { throw new Error(`axe ${url} (${scheme}): ` + found.violations.map((v) => `${v.id} ${v.nodes[0].target.join(' ')}`).join(', ')); }
      }
    }
  } finally {
    await axeContext.close();
  }
});

await step('3.13 Spanish admin: switched in My account, Spanish screens without sideways scroll at 390 px', async () => {
  const adminLanguage = async (code) => {
    await visit('/admin.php?action=account');
    await page.selectOption('#jazyk', code);
    await Promise.all([page.waitForNavigation(), page.locator('form:has(#jazyk) input[type="submit"]').click()]);
  };
  await adminLanguage('es');
  try {
    for (const [width, height] of [[1440, 900], [390, 844]]) {
      await page.setViewportSize({ width, height });
      for (const url of ['/admin.php', '/admin.php?module=pages', '/admin.php?module=settings', '/admin.php?action=account']) {
        await visit(url);
        if ((await page.locator('html').getAttribute('lang')) !== 'es') { throw new Error(`${url} is not in Spanish`); }
        if (!(await page.evaluate(() => typeof window.KALETA_PREKLAD === 'object' && Object.keys(window.KALETA_PREKLAD).length > 2500))) { throw new Error(`${url} has no Spanish script texts`); }
        const wider = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
        if (wider > 1) { throw new Error(`${url} scrolls sideways by ${wider} px at ${width} px`); }
        if (SHOTS && width === 390) { await page.screenshot({ path: `${SHOTS}/spanish-${url.replace(/\W+/g, '-')}.png`, fullPage: true }); }
      }
    }
    await visit('/admin.php?module=settings');
    if (!(await page.locator('h1', { hasText: 'Ajustes' }).count())) { throw new Error('the Settings heading is not Spanish'); }
  } finally {
    await page.setViewportSize({ width: 1440, height: 900 });
    await adminLanguage('en');
  }
});

// right-to-left (data model §5): the Arabic page of the Crafts starter (tools/test-browser.sh) as a visitor sees it on a computer and
// on a phone, and its English twin – the carousel shows its first slide whole at load and Next brings the second, in both directions
for (const [language, path] of [['ar', '/ar/rtl-test'], ['en', '/ltr-test']]) {
  for (const [width, height, name] of [[1440, 900, 'desktop'], [390, 844, 'phone']]) {
    await step(`RTL: the ${language === 'ar' ? 'Arabic' : 'English'} page at ${width} px – direction, no sideways scroll, the carousel${language === 'ar' ? ' and the layout mirrored' : ''}`, async () => {
      const visitor = await browser.newContext({ viewport: { width, height }, locale: language });
      const v = await visitor.newPage();
      watch(v);
      try {
        await v.goto(`${STARTER_SITES.remeslo}${path}`, { waitUntil: 'networkidle' });
        const root = await v.evaluate(() => [document.documentElement.lang, document.documentElement.dir].join());
        if (root !== (language === 'ar' ? 'ar,rtl' : 'en,')) { throw new Error(`<html> is ${root}`); }
        const overflow = () => v.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
        if (await overflow() > 1) { throw new Error(`the page scrolls sideways by ${await overflow()} px`); }
        // the cookie bar sits at the start side – the right edge of a right-to-left page
        const bar = await v.locator('#cookies-lista').boundingBox();
        if (language === 'ar' && bar && width - (bar.x + bar.width) > 24) { throw new Error(`the cookie bar is ${Math.round(width - bar.x - bar.width)} px from the right edge`); }
        if (await v.locator('#cookies-lista button').count()) { await v.locator('#cookies-lista button').first().click(); }
        // the carousel at load: the first slide and its heading whole inside the strip; Previous is off
        const carousel = v.locator('[data-karusel]').first();
        const strip = carousel.locator('.ka-karusel-pas');
        await carousel.scrollIntoViewIfNeeded();
        const inside = (i) => strip.evaluate((s, n) => { const box = s.getBoundingClientRect(); const slide = s.children[n].getBoundingClientRect(); const heading = s.children[n].querySelector('h3').getBoundingClientRect();
          return [slide, heading].every((r) => r.width > 0 && r.left >= box.left - 1 && r.right <= box.right + 1); }, i);
        if (!(await inside(0))) { throw new Error('the first slide is not whole inside the carousel at load'); }
        if (!(await carousel.locator('[data-krok="-1"]').isDisabled())) { throw new Error('Previous is not off at the first slide'); }
        if (SHOTS && language === 'ar') { await v.screenshot({ path: `${SHOTS}/rtl-arabic-${name}.png`, fullPage: true }); }
        const [previous, next] = [await carousel.locator('[data-krok="-1"]').boundingBox(), await carousel.locator('[data-krok="1"]').boundingBox()];
        if (!previous || !next || (language === 'ar' ? previous.x <= next.x : previous.x >= next.x)) { throw new Error('Previous and Next are in the wrong order'); }
        if (await carousel.locator('[data-krok="-1"]').evaluate((b) => getComputedStyle(b).direction) !== (language === 'ar' ? 'rtl' : 'ltr')) { throw new Error('the arrow buttons do not follow the direction (their arrows mirror with it)'); }
        await carousel.locator('[data-krok="1"]').click();
        await v.waitForTimeout(900);
        if (!(await inside(1)) || await inside(0)) { throw new Error('Next did not bring the second slide'); }
        const scrolled = await strip.evaluate((s) => s.scrollLeft);
        if (language === 'ar' ? !(scrolled < -10) : !(scrolled > 10)) { throw new Error(`Next moved the strip to ${scrolled}`); }
        await carousel.locator('[data-krok="-1"]').click();
        await v.waitForTimeout(900);
        if (!(await inside(0))) { throw new Error('Previous did not bring the first slide back'); }
        if (name === 'phone' && language === 'ar') {
          // the phone menu: its button at the left (the end side), the logo at the right; the sheet opens inside the window, its items from the right
          const toggle = v.locator('.ka-nav-tl:visible, .menu-tl:visible').first();
          const logo = v.locator('.ka-logo:visible, .logo:visible').first();
          const [t, l] = [await toggle.boundingBox(), await logo.boundingBox()];
          if (!t || !l || t.x >= l.x) { throw new Error('the menu button is not left of the logo'); }
          await toggle.click();
          const sheet = v.locator('.ka-nav-menu[popover]:popover-open, .navigace[popover]:popover-open').first();
          await sheet.waitFor();
          const box = await sheet.boundingBox();
          const item = await sheet.locator('a:visible').first().boundingBox();
          if (!box || box.x < 0 || box.x + box.width > width + 1) { throw new Error('the menu sheet is outside the window'); }
          if (!item || (box.x + box.width) - (item.x + item.width) > 48) { throw new Error('the menu items do not start at the right'); }
          if (await overflow() > 1) { throw new Error('the open menu scrolls the page sideways'); }
          if (SHOTS) { await v.screenshot({ path: `${SHOTS}/rtl-arabic-phone-menu.png` }); }
        }
      } finally {
        await visitor.close();
      }
    });
  }
}

// 3.17: the fleet console (test-browser.sh switches the extension on and stores a site with 95 days of history and last month's
// fleet report): on the site's page the trends – 90 bars with a summary for screen readers, the days as a table that opens,
// no WCAG violation in the block, nothing wider than a phone – and the fleet report in print at the width of an A4 page
await step('3.17 fleet console: the trends of a site and the monthly fleet report in print', async () => {
  await visit('/admin.php?module=fleet&action=detail&id=900');
  // 3.26.1: the bars are Core\UptimeStrip – the e-mail-safe table the site's monthly report draws too
  const strip = page.locator('figure.trend table[role="img"]');
  await strip.waitFor();
  if ((await strip.locator('td[title]').count()) !== 90) { throw new Error('the trend has not 90 bars'); }
  if (!/90/.test(await strip.getAttribute('aria-label') || '')) { throw new Error('the trend has no summary for screen readers'); }
  if ((await strip.locator('td[bgcolor="#D92D20"]').count()) < 1 || (await strip.locator('td[bgcolor="#C97F00"]').count()) < 1) { throw new Error('the bad days are not marked'); }
  const stripBox = await strip.boundingBox();
  if (!stripBox || stripBox.height < 36 || stripBox.width < 300) { throw new Error('the strip is not drawn at its size'); }
  await page.locator('details.trend-tabulka summary').click();
  if ((await page.locator('details.trend-tabulka tbody tr').count()) < 85) { throw new Error('the table of the days is short'); }
  // axe in a context that lets the test inject it – the administration allows no inline script (CSP), rightly
  const axeContext = await browser.newContext({ storageState: await context.storageState(), bypassCSP: true, viewport: { width: 1440, height: 900 } });
  try {
    const axePage = await axeContext.newPage();
    await axePage.goto(BASE + '/admin.php?module=fleet&action=detail&id=900', { waitUntil: 'networkidle' });
    await axePage.locator('details.trend-tabulka summary').click();
    await axePage.addScriptTag({ path: AXE });
    const found = await axePage.evaluate((tags) => window.axe.run({ include: [['figure.trend'], ['details.trend-tabulka']] }, { runOnly: { type: 'tag', values: tags }, resultTypes: ['violations'] }), WCAG);
    if (found.violations.length) { throw new Error('axe: ' + found.violations.map((v) => `${v.id} ${v.nodes[0].target.join(' ')}`).join(', ')); }
  } finally {
    await axeContext.close();
  }
  if (SHOTS) {
    await page.locator('figure.trend').scrollIntoViewIfNeeded();
    await page.screenshot({ path: `${SHOTS}/fleet-trends.png`, fullPage: true });
    await page.emulateMedia({ colorScheme: 'dark' });
    await page.screenshot({ path: `${SHOTS}/fleet-trends-dark.png`, fullPage: true });
    await page.emulateMedia({ colorScheme: 'light' });
  }
  await page.setViewportSize({ width: 390, height: 844 });
  try {
    const right = await page.evaluate(() => document.querySelector('figure.trend').getBoundingClientRect().right);
    if (right > 391) { throw new Error(`the trend is wider than a phone (${right} px)`); }
    if (SHOTS) { await page.screenshot({ path: `${SHOTS}/fleet-trends-phone.png`, fullPage: false }); }
  } finally {
    await page.setViewportSize({ width: 1440, height: 900 });
  }
  await visit('/admin.php?module=fleet&action=reports');
  const view = page.locator('a[href*="action=report_view"]').first();
  await view.waitFor();
  if (SHOTS) { await page.screenshot({ path: `${SHOTS}/fleet-reports.png`, fullPage: true }); }
  const report = await context.newPage();
  watch(report);
  try {
    await report.goto(new URL(await view.getAttribute('href'), BASE).href, { waitUntil: 'networkidle' });
    await report.locator('[data-tisk]').waitFor();
    if ((await report.locator('section.sekce').count()) < 3) { throw new Error('the fleet report has fewer than 3 sections'); }
    if ((await report.locator('p.druh').textContent()) !== 'Fleet report') { throw new Error('the page does not say Fleet report'); }
    // 3.26.1: the site's month as the same uptime strip as on its page (Core\UptimeStrip), one bar a day
    const monthStrip = report.locator('.uptime table[role="img"]').first();
    if ((await monthStrip.locator('td[title]').count()) < 28) { throw new Error('the fleet report has no uptime strip for the month'); }
    if (SHOTS) { await report.screenshot({ path: `${SHOTS}/fleet-report-screen.png`, fullPage: true }); }
    await report.emulateMedia({ media: 'print' });
    await report.setViewportSize({ width: 680, height: 1000 });
    const wide = await report.evaluate(() => {
      const sheet = document.querySelector('.listina').getBoundingClientRect();
      return [...document.querySelectorAll('.listina table, .listina dl, .listina h1, .listina h2, .listina p, .listina td')]
        .filter((el) => el.getBoundingClientRect().right > sheet.right + 1 || el.scrollWidth > el.clientWidth + 1).map((el) => el.tagName + ': ' + el.textContent.slice(0, 40));
    });
    if (wide.length) { throw new Error('wider than the A4 page in print: ' + wide.slice(0, 3).join(' | ')); }
    if (await report.locator('.lista').isVisible()) { throw new Error('the toolbar prints'); }
    if (SHOTS) {
      await report.setViewportSize({ width: 680, height: await report.evaluate(() => document.documentElement.scrollHeight) });
      await report.screenshot({ path: `${SHOTS}/fleet-report-print.png` });
      await report.pdf({ path: `${SHOTS}/fleet-report-print.pdf`, format: 'A4', preferCSSPageSize: true });
    }
    const pdf = await report.pdf({ format: 'A4', preferCSSPageSize: true });
    const pages = (pdf.toString('latin1').match(/\/Type\s*\/Page[^s]/g) || []).length;
    if (pages < 1 || pages > 4) { throw new Error(`the A4 PDF has ${pages} pages`); }
  } finally {
    await report.close();
  }
});

// 3.18.3 (owner's request): a collection's own item in the left menu – right under Collections, a direct link to its items,
// current there instead of Collections, reachable by keyboard; on a phone a tile of the menu sheet without sideways scroll.
// Two collections in the menu and one left out (the site's other collections step aside for the screenshots).
await step('3.18.3 menu: collections straight from the left menu (1440 and 390 px, dark mode, keyboard)', async () => {
  if (!MYSQL.length) { throw new Error('MYSQL_JSON is not set'); }
  const before = sql('SELECT GROUP_CONCAT(idk) FROM ka_kolekce WHERE admin_menu = 1').trim();
  sql("UPDATE ka_kolekce SET admin_menu = 0; INSERT INTO ka_kolekce (nazev, seo_link, pole, admin_menu) VALUES ('Products', 'products-3183', '[]', 1), ('Job openings', 'job-openings-3183', '[]', 1), ('Archive of old offers', 'archive-3183', '[]', 0)");
  const products = sql("SELECT idk FROM ka_kolekce WHERE seo_link = 'products-3183'").trim();
  try {
    await page.setViewportSize({ width: 1440, height: 900 });
    await visit('/admin.php');
    const names = await page.locator('.menu .menu-kolekce a').allTextContents();
    if (JSON.stringify(names.map((n) => n.trim())) !== JSON.stringify(['Job openings', 'Products'])) { throw new Error(`the menu lists ${JSON.stringify(names)}`); }
    const after = await page.locator('.menu .menu-kolekce').first().evaluate((li) => li.previousElementSibling.querySelector('a').getAttribute('href'));
    if (!/module=collections$/.test(after)) { throw new Error(`the collections do not follow Collections: ${after}`); }
    // keyboard: focus the item and press Enter – the items list of Products opens, its menu item is the current page
    await page.locator('.menu .menu-kolekce a', { hasText: 'Products' }).focus();
    await Promise.all([page.waitForNavigation(), page.keyboard.press('Enter')]);
    if (!page.url().includes(`module=collections&action=items&id=${products}`)) { throw new Error(`Enter led to ${page.url()}`); }
    if ((await page.locator('h1').textContent()).trim() !== 'Products') { throw new Error('the items list of Products did not open'); }
    const current = await page.locator('.menu [aria-current="page"]').allTextContents();
    if (current.length !== 1 || current[0].trim() !== 'Products') { throw new Error(`current in the menu: ${JSON.stringify(current)}`); }
    if (await page.getByRole('link', { name: 'Products', exact: true }).count() < 1) { throw new Error('no link named Products for screen readers'); }
    if (SHOTS) { await page.screenshot({ path: `${SHOTS}/collections-menu-desktop.png` }); }
    await page.emulateMedia({ colorScheme: 'dark' });
    await visit(`/admin.php?module=collections&action=items&id=${products}`);
    const dark = await page.locator('.menu .menu-kolekce.aktivni a').evaluate((a) => [getComputedStyle(a).color, getComputedStyle(a).backgroundColor]);
    if (dark[0] === dark[1]) { throw new Error(`the current collection is unreadable in dark mode: ${dark}`); }
    if (SHOTS) { await page.screenshot({ path: `${SHOTS}/collections-menu-desktop-dark.png` }); }
    await page.emulateMedia({ colorScheme: 'light' });
    // phone: the menu sheet has the collections as tiles, nothing scrolls sideways, the tiles are big enough to tap
    await page.setViewportSize({ width: 390, height: 844 });
    await visit(`/admin.php?module=collections&action=items&id=${products}`);
    const wide = async (where) => { const w = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth); if (w > 0) { throw new Error(`${where}: ${w} px of sideways scroll at 390 px`); } };
    await wide('items list');
    await page.click('.menu-prepinac');
    const tiles = page.locator('.menu .menu-kolekce a');
    await tiles.first().scrollIntoViewIfNeeded();
    if (!(await tiles.first().isVisible())) { throw new Error('the collections are not in the phone menu'); }
    await wide('open menu');
    for (const box of await Promise.all((await tiles.all()).map((t) => t.boundingBox()))) {
      if (!box || box.x < 0 || box.x + box.width > 390 || box.height < 44) { throw new Error(`a collection tile does not fit: ${JSON.stringify(box)}`); }
    }
    if (SHOTS) { await page.screenshot({ path: `${SHOTS}/collections-menu-phone.png` }); }
    await page.keyboard.press('Escape');
  } finally {
    await page.emulateMedia({ colorScheme: 'light' });
    await page.setViewportSize({ width: 1440, height: 900 });
    sql("DELETE FROM ka_kolekce WHERE seo_link IN ('products-3183', 'job-openings-3183', 'archive-3183')" + (before !== '' && before !== 'NULL' ? `; UPDATE ka_kolekce SET admin_menu = 1 WHERE idk IN (${before})` : ''));
  }
});

// 3.19 (owner, 22 October 2026): an icon per collection – picked in the collection's settings from the built-in set (radio
// tiles, by mouse and by keyboard), shown by the collection's item in the left menu and in the list of collections; the
// picker fits a phone without sideways scroll. Screenshots: the settings with the picker and the menu with icons.
// The owner's follow-up: a short selection first (12 tiles), the rest under "More icons" (<details>, keyboard-friendly),
// open by itself when the chosen icon is one of those.
await step('3.19 collections: the icon picker in the settings, the icons in the menu and the list (1440 and 390 px, keyboard)', async () => {
  if (!MYSQL.length) { throw new Error('MYSQL_JSON is not set'); }
  const before = sql('SELECT GROUP_CONCAT(idk) FROM ka_kolekce WHERE admin_menu = 1').trim();
  sql("UPDATE ka_kolekce SET admin_menu = 0; INSERT INTO ka_kolekce (nazev, seo_link, pole, admin_menu, icon) VALUES ('Products', 'products-319', '[]', 1, NULL), ('Job openings', 'job-openings-319', '[]', 1, 'kufr'), ('Our team', 'team-319', '[]', 1, 'lide')");
  const products = sql("SELECT idk FROM ka_kolekce WHERE seo_link = 'products-319'").trim();
  try {
    await page.setViewportSize({ width: 1440, height: 900 });
    await visit(`/admin.php?module=collections&action=edit&id=${products}`);
    const picker = page.locator('.ikony-volba');
    if (await picker.locator('input[type="radio"][name="icon"]').count() < 40) { throw new Error('the picker does not offer the icon set'); }
    if (!(await picker.locator('input[value=""]').isChecked())) { throw new Error('a collection without an icon does not have the list icon checked'); }
    const more = picker.locator('details.ikony-dalsi');
    const visibleTiles = await picker.locator('label').evaluateAll((labels) => labels.filter((l) => l.checkVisibility()).length);
    const openAtStart = await more.evaluate((d) => d.open);
    if (visibleTiles !== 12 || openAtStart) { throw new Error(`the short selection shows ${visibleTiles} tiles (expected 12), "More icons" open: ${openAtStart}`); }
    // "More icons" opens from the keyboard (Enter on the summary) and shows the rest; it closes again the same way
    await more.locator('summary').focus();
    await page.keyboard.press('Enter');
    if (!(await more.evaluate((d) => d.open)) || !(await picker.locator('label:has(input[value="auto"])').isVisible())) { throw new Error('Enter on "More icons" did not show the rest of the set'); }
    if (SHOTS) { await picker.scrollIntoViewIfNeeded(); await page.screenshot({ path: `${SHOTS}/collection-icon-picker-more-desktop.png` }); }
    await page.keyboard.press('Enter');
    if (await more.evaluate((d) => d.open)) { throw new Error('"More icons" did not close again'); }
    // the mouse picks the house, the keyboard moves on to the next tile (arrow keys of a radio group)
    await picker.locator('label', { hasText: 'House' }).click();
    if (!(await picker.locator('input[value="dum"]').isChecked())) { throw new Error('a click on the House tile did not choose it'); }
    const outline = await picker.locator('label:has(input[value="dum"])').evaluate((l) => getComputedStyle(l).borderColor);
    const plain = await picker.locator('label:has(input[value="auto"])').evaluate((l) => getComputedStyle(l).borderColor);
    if (outline === plain) { throw new Error('the chosen tile is not marked'); }
    await picker.locator('input[value="dum"]').focus();
    await page.keyboard.press('ArrowRight');
    const moved = await picker.locator('input:checked').getAttribute('value');
    if (moved === 'dum') { throw new Error('the arrow key did not move the choice'); }
    await picker.locator('label', { hasText: 'House' }).click();
    if (SHOTS) { await picker.scrollIntoViewIfNeeded(); await page.screenshot({ path: `${SHOTS}/collection-icon-picker-desktop.png` }); }
    await Promise.all([page.waitForNavigation(), page.locator('form.formular input[type="submit"], form.formular button[type="submit"]').first().click()]);
    if (sql(`SELECT icon FROM ka_kolekce WHERE idk = ${products}`).trim() !== 'dum') { throw new Error('the chosen icon was not saved'); }
    // the menu: each collection with its own icon (the house for Products), the list of collections too
    await visit('/admin.php');
    const icons = await page.locator('.menu .menu-kolekce a').evaluateAll((links) => links.map((a) => [a.textContent.trim(), a.querySelector('svg').innerHTML.slice(0, 24)]));
    const productsIcon = icons.find(([name]) => name === 'Products');
    if (!productsIcon || !productsIcon[1].includes('M4 11 12 4l8 7')) { throw new Error(`the menu icons: ${JSON.stringify(icons)}`); }
    if (new Set(icons.map(([, svg]) => svg)).size !== icons.length) { throw new Error('two collections share an icon in the menu'); }
    if (SHOTS) { await page.screenshot({ path: `${SHOTS}/collections-menu-icons-desktop.png` }); }
    await visit('/admin.php?module=collections');
    if (await page.locator('.kolekce-nazev', { hasText: 'Products' }).locator('svg').count() !== 1) { throw new Error('no icon next to Products in the list of collections'); }
    // phone: the picker wraps into the width, the menu sheet shows the icons
    await page.setViewportSize({ width: 390, height: 844 });
    await visit(`/admin.php?module=collections&action=edit&id=${products}`);
    const wide = async (where) => { const w = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth); if (w > 0) { throw new Error(`${where}: ${w} px of sideways scroll at 390 px`); } };
    await wide('collection settings');
    await page.locator('.ikony-volba').scrollIntoViewIfNeeded();
    if (SHOTS) { await page.screenshot({ path: `${SHOTS}/collection-icon-picker-phone.png` }); }
    // a chosen icon from the rest of the set: shown in the short selection, and "More icons" opens by itself
    sql(`UPDATE ka_kolekce SET icon = 'auto' WHERE idk = ${products}`);
    await visit(`/admin.php?module=collections&action=edit&id=${products}`);
    const openMore = await page.locator('details.ikony-dalsi').evaluate((d) => d.open);
    const inShort = await page.locator('.ikony-volba > .ikony-vyber input[value="auto"]').isChecked();
    if (!openMore || !inShort) { throw new Error(`a chosen icon outside the selection: "More icons" open ${openMore}, shown first ${inShort}`); }
    await wide('collection settings with "More icons" open');
    sql(`UPDATE ka_kolekce SET icon = 'dum' WHERE idk = ${products}`);
    await visit('/admin.php');
    await page.click('.menu-prepinac');
    // 3.25: on a phone only the group of the current screen is open – on the Dashboard none, so open Content
    if (!(await page.locator('details[data-menu-skupina="content"]').evaluate((d) => d.open))) { await page.locator('details[data-menu-skupina="content"] > summary').click(); }
    await page.locator('.menu .menu-kolekce a').first().scrollIntoViewIfNeeded();
    await wide('open menu');
    if (SHOTS) { await page.screenshot({ path: `${SHOTS}/collections-menu-icons-phone.png` }); }
    await page.keyboard.press('Escape');
  } finally {
    await page.setViewportSize({ width: 1440, height: 900 });
    sql("DELETE FROM ka_kolekce WHERE seo_link IN ('products-319', 'job-openings-319', 'team-319')" + (before !== '' && before !== 'NULL' ? `; UPDATE ka_kolekce SET admin_menu = 1 WHERE idk IN (${before})` : ''));
  }
});

// 3.22 (C15): the Form's own confirmation e-mail text is offered only while the confirmation e-mail is on
await step('3.22 (C15) builder: the Form shows "Confirmation e-mail text" only with the confirmation e-mail on', async () => {
  await visit('/admin.php?module=pages&action=builder&id=4'); // Contact of the Business starter: an enquiry form
  await page.waitForTimeout(1500);
  await canvas().locator('form').first().click({ position: { x: 4, y: 4 } });
  await page.getByRole('tab', { name: 'Content' }).first().click().catch(() => {});
  const until = async (fn, what, ms = 5000) => { const end = Date.now() + ms; for (;;) { const v = await fn(); if (v) { return v; } if (Date.now() > end) { throw new Error(what); } await page.waitForTimeout(100); } };
  const toggle = page.locator('.st-pravy label.st-zaskrt', { hasText: 'Send the sender a confirmation e-mail' }).locator('input');
  await toggle.waitFor();
  const own = () => page.locator('.st-pravy label.st-pole', { hasText: 'Confirmation e-mail text' }).count();
  const wasOn = await toggle.isChecked();
  if ((await own()) !== (wasOn ? 1 : 0)) { throw new Error(`the text field with the confirmation ${wasOn ? 'on' : 'off'}: ${await own()}`); }
  await toggle.click();
  await until(async () => (await own()) === (wasOn ? 0 : 1) || null, 'the text field did not follow the confirmation switch');
  if (!wasOn) {
    await page.locator('.st-pravy label.st-pole', { hasText: 'Confirmation e-mail text' }).locator('textarea').fill('Hello,\nwe reply within a day.');
    if (SHOTS) { await page.locator('.st-pravy').screenshot({ path: `${SHOTS}/form-confirmation-text.png` }); }
    await page.locator('.st-pravy label.st-zaskrt', { hasText: 'Send the sender a confirmation e-mail' }).locator('input').click();
  } else {
    await page.locator('.st-pravy label.st-zaskrt', { hasText: 'Send the sender a confirmation e-mail' }).locator('input').click();
  }
  await until(async () => (await own()) === (wasOn ? 1 : 0) || null, 'the text field did not come back with the switch');
  await visit('/admin.php?module=pages&action=builder&id=1');
  await page.waitForTimeout(1500);
});

// 3.22 (C10): the reply on the enquiry detail – the draft in a textarea, Send asks first, an undelivered reply is said to
// wait in the queue and shows in the history; at 390 px nothing scrolls sideways (the SMTP port is closed: no e-mail leaves)
await step('3.22 (C10) enquiry detail: reply with the draft, confirm, the queued reply in the history (1440 and 390 px)', async () => {
  const setting = (key) => `IFNULL((SELECT hodnota FROM ka_nastaveni WHERE promenna = '${key}'), '')`;
  const saved = sql(`SELECT CONCAT_WS('|', ${['mail_mode', 'smtp_host', 'smtp_port', 'mail_from'].map(setting).join(', ')})`).trim();
  sql("INSERT INTO ka_poptavky (datum, formular, stranka, email, data, stav, navrh_odpovedi) VALUES (NOW(), 'Enquiry 322', '/contact', 'hannah322@example.com', '[[\"Message\",\"Do you deliver on Saturdays?\"]]', 0, 'Hello Hannah, yes, on Saturday mornings.')");
  const id = sql("SELECT MAX(idp) FROM ka_poptavky WHERE email = 'hannah322@example.com'").trim();
  try {
    await visit(`/admin.php?module=enquiries&action=detail&id=${id}`);
    if (await page.locator('[data-reply-blocked]').count() !== 1 || await page.locator('[data-reply-form]').count() !== 0) { throw new Error('without SMTP the detail does not explain it'); }
    if (!/^mailto:hannah322@example\.com\?subject=Re%3A%20Do%20you%20deliver%20on%20Saturdays%3F&body=Hello/.test(await page.locator('a.tl[href^="mailto:"]').first().getAttribute('href') || '')) { throw new Error('the mailto: fallback'); }
    sql("REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('mail_mode', 'smtp'), ('smtp_host', '127.0.0.1'), ('smtp_port', '1'), ('mail_from', 'web@example.com')");
    await visit(`/admin.php?module=enquiries&action=detail&id=${id}`);
    const form = page.locator('form[data-reply-form]');
    if (await form.locator('textarea[name="text"]').inputValue() !== 'Hello Hannah, yes, on Saturday mornings.' || await form.locator('input[name="subject"]').inputValue() !== 'Re: Do you deliver on Saturdays?') { throw new Error('the draft and the subject are not offered'); }
    if (SHOTS) { await page.locator('#reply').scrollIntoViewIfNeeded(); await page.screenshot({ path: `${SHOTS}/enquiry-reply.png`, fullPage: true }); }
    await form.locator('textarea[name="text"]').fill('Hello Hannah,\nyes, on Saturday mornings until noon.');
    await form.locator('button[type="submit"]', { hasText: 'Send reply' }).click();
    const dialog = page.locator('dialog.potvrzeni[open]');
    await dialog.waitFor();
    if (!(await dialog.textContent() || '').includes('hannah322@example.com')) { throw new Error('the confirmation does not name the recipient'); }
    await Promise.all([page.waitForNavigation(), dialog.locator('[data-ano]').click()]);
    await page.locator('.hlaska-chyba', { hasText: 'waits in the mail queue' }).waitFor();
    if (await page.locator('[data-replies] [data-reply-status="queued"]').count() !== 1) { throw new Error('the queued reply is not in the history'); }
    if (await page.locator('[data-replies] li', { hasText: 'Replied by' }).count() !== 1) { throw new Error('the history does not say who replied'); }
    await page.setViewportSize({ width: 390, height: 844 });
    await visit(`/admin.php?module=enquiries&action=detail&id=${id}`);
    const sideways = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    if (sideways > 0) { throw new Error(`${sideways} px of sideways scroll at 390 px`); }
    if (SHOTS) { await page.locator('#reply').scrollIntoViewIfNeeded(); await page.screenshot({ path: `${SHOTS}/enquiry-reply-phone.png`, fullPage: true }); }
  } finally {
    await page.setViewportSize({ width: 1440, height: 900 });
    const [mode, host, port, from] = saved.split('|');
    sql(`REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('mail_mode', '${mode || 'mail'}'), ('smtp_host', '${host || ''}'), ('smtp_port', '${port || ''}'), ('mail_from', '${from || ''}'); DELETE FROM ka_posta WHERE komu = 'hannah322@example.com'; DELETE FROM ka_poptavky WHERE email = 'hannah322@example.com'`);
  }
});

// 3.25 (A): internal notes as a history – the composer adds a note to the conversation (author, time, marked internal), a
// second one keeps the first; the conversation is in the order of time; tinted in light and dark mode; no sideways scroll at 390 px
await step('3.25 enquiry notes: add two internal notes, the conversation in order, light/dark, 390 px', async () => {
  sql("INSERT INTO ka_poptavky (datum, formular, stranka, email, data, stav, poznamka) VALUES (NOW() - INTERVAL 1 DAY, 'Enquiry 325', '/contact', 'notes325@example.com', '[[\"Message\",\"Can you fit a new door next week?\"]]', 1, 'Old summary 325')");
  const id = sql("SELECT MAX(idp) FROM ka_poptavky WHERE email = 'notes325@example.com'").trim();
  try {
    await page.setViewportSize({ width: 1440, height: 900 });
    await visit(`/admin.php?module=enquiries&action=detail&id=${id}`);
    for (const text of ['Called her, she wants oak.', 'Quote sent by phone.']) {
      await page.locator('form[data-note-form] textarea[name="add_note"]').fill(text);
      await Promise.all([page.waitForNavigation(), page.locator('form[data-note-form] button[type="submit"]', { hasText: 'Add internal note' }).click()]);
      await page.locator('.hlaska', { hasText: 'The note was added.' }).waitFor();
    }
    const items = await page.locator('[data-thread] > li').evaluateAll((els) => els.map((el) => el.dataset.threadItem));
    if (items.join(',') !== 'summary,customer,note,note') { throw new Error(`the conversation is ${items.join(',')}`); }
    const notes = page.locator('[data-thread-item="note"]');
    if (!/Called her, she wants oak\./.test(await notes.nth(0).textContent()) || !/Quote sent by phone\./.test(await notes.nth(1).textContent())) { throw new Error('the notes are not in the order they were written'); }
    if (!/Note by .+ on /.test(await notes.nth(0).textContent()) || !/Internal – the customer does not see this/.test(await notes.nth(0).textContent())) { throw new Error('a note does not say who wrote it, when, and that it is internal'); }
    if (!/Can you fit a new door next week\?/.test(await page.locator('[data-thread-item="customer"]').textContent())) { throw new Error('the customer\'s message is not in the conversation'); }
    if ((await page.locator('#poznamka').inputValue()) !== 'Old summary 325') { throw new Error('the summary is not kept'); }
    const light = await notes.nth(0).evaluate((el) => getComputedStyle(el).backgroundColor);
    if (SHOTS) { await page.locator('#thread').scrollIntoViewIfNeeded(); await page.screenshot({ path: `${SHOTS}/enquiry-notes.png`, fullPage: true }); }
    await page.emulateMedia({ colorScheme: 'dark' });
    const dark = await notes.nth(0).evaluate((el) => getComputedStyle(el).backgroundColor);
    if (dark === light) { throw new Error(`the note keeps its light background in dark mode (${dark})`); }
    if (SHOTS) { await page.screenshot({ path: `${SHOTS}/enquiry-notes-dark.png`, fullPage: true }); }
    await page.emulateMedia({ colorScheme: 'light' });
    await page.setViewportSize({ width: 390, height: 844 });
    await visit(`/admin.php?module=enquiries&action=detail&id=${id}`);
    const sideways = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    if (sideways > 0) { throw new Error(`${sideways} px of sideways scroll at 390 px`); }
  } finally {
    await page.emulateMedia({ colorScheme: 'light' });
    await page.setViewportSize({ width: 1440, height: 900 });
    sql(`DELETE FROM ka_comments WHERE target = 'enquiry:${Number(id)}'; DELETE FROM ka_poptavky WHERE idp = ${Number(id)}`);
  }
});

// 3.22 (C9): the role form offers the optional Appearance area, never ticked, and says what stays with the administrator
await step('3.22 C9: the Appearance area in the role form', async () => {
  await visit('/admin.php?module=roles&action=new');
  const area = page.locator('input[name="moduly[]"][value="appearance_area"]');
  if (await area.count() !== 1 || await area.isChecked()) { throw new Error('no unticked Appearance area in the role form'); }
  const label = (await page.locator('label', { has: area }).textContent()).trim();
  if (label !== 'Appearance: menu, site parts, pop-ups and components') { throw new Error(`the label reads "${label}"`); }
  if (!(await page.locator('.napoveda', { hasText: 'Claude settings stay with the administrator' }).isVisible())) { throw new Error('the hint does not say what stays with the administrator'); }
  // 3.23.1 (N-C1, owner decision D1): the ready-made Client role ticks it, and Business details with it
  await visit('/admin.php?module=roles&action=new&preset=client');
  if (!(await page.locator('input[value="appearance_area"]').isChecked()) || !(await page.locator('input[value="business"]').isChecked())) { throw new Error('the Client preset does not tick the Appearance area and Business details'); }
  if (SHOTS) { await page.locator('input[value="appearance_area"]').scrollIntoViewIfNeeded(); await page.screenshot({ path: `${SHOTS}/role-appearance-area-desktop.png` }); }
});

// 3.23.1 (N-C1): the role saved from the Client preset, a user with it (the hand-over link "New user" picks the role), signed in:
// Menu, Site parts and Business details open (200) – the footer and the opening hours are the client's to change
await step('3.23.1 N-C1: a Client-role user opens Menu and Business details', async () => {
  const client = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'en-GB' });
  try {
    await visit('/admin.php?module=roles&action=new&preset=client');
    await page.fill('input[name="nazev"]', 'Client N-C1');
    await Promise.all([page.waitForNavigation(), page.click('form.formular input[type="submit"]')]);
    const idr = sql("SELECT idr FROM ka_role WHERE nazev = 'Client N-C1'").trim();
    if (!idr) { throw new Error('the role from the preset was not saved'); }
    await visit(`/admin.php?module=users&action=new&role=${idr}`);
    if (!(await page.locator(`input[name="admin"][value="r${idr}"]`).isChecked())) { throw new Error('New user with ?role= does not pick the role'); }
    await page.fill('input[name="user"]', 'client-nc1');
    await page.fill('input[name="password"]', PASSWORD);
    await Promise.all([page.waitForNavigation(), page.click('form.formular input[type="submit"]')]);
    const [language, sections] = sql("SELECT u.jazyk, GROUP_CONCAT(p.ident_modulu ORDER BY p.ident_modulu) FROM ka_uzivatele u JOIN ka_uzivatele_prava p ON p.fk_id_user = u.idu WHERE u.user = 'client-nc1' GROUP BY u.idu").trim().split('\t');
    if (language !== 'en' || !sections.split(',').includes('appearance_area') || !sections.split(',').includes('business')) { throw new Error(`the new user has language "${language}" and sections ${sections}`); }
    const tab = await client.newPage();
    await tab.goto(BASE + '/admin.php', { waitUntil: 'networkidle' });
    await tab.fill('input[name="user"]', 'client-nc1');
    await tab.fill('input[name="password"]', PASSWORD);
    await Promise.all([tab.waitForNavigation(), tab.press('input[name="password"]', 'Enter')]);
    const codes = [];
    for (const url of ['/admin.php?module=menu', '/admin.php?module=parts', '/admin.php?module=business', '/admin.php?module=appearance']) {
      codes.push((await tab.goto(BASE + url, { waitUntil: 'networkidle' })).status());
    }
    if (codes.join('|') !== '200|200|200|403') { throw new Error(`Menu, Site parts, Business details, Site appearance answer ${codes.join('|')}`); }
    if (SHOTS) { await tab.goto(BASE + '/admin.php?module=business', { waitUntil: 'networkidle' }); await tab.screenshot({ path: `${SHOTS}/client-role-business-desktop.png` }); }
  } finally {
    await client.close();
    sql("DELETE p FROM ka_uzivatele_prava p JOIN ka_uzivatele u ON u.idu = p.fk_id_user WHERE u.user = 'client-nc1'; DELETE FROM ka_uzivatele WHERE user = 'client-nc1'; DELETE FROM ka_role WHERE nazev = 'Client N-C1'");
  }
});

// 3.22 (C13): the canonical address of a page – an invalid one is refused in the open section, a path is printed on the site
await step('3.22 C13: the canonical address of a page', async () => {
  const [pageId, slug] = sql("SELECT ids, seo_link FROM ka_stranky WHERE zobrazit = 1 AND smazano IS NULL AND jazyk = '' AND ids <> COALESCE((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'home_page'), 0) ORDER BY ids LIMIT 1").trim().split('\t');
  try {
    await visit(`/admin.php?module=pages&action=edit&id=${pageId}`);
    // the field lives in the section "Search engines and sharing" (opened here; it opens by itself when a value or an error is in it)
    const summary = page.locator('details > summary', { hasText: 'Search engines and sharing' }).first();
    await summary.evaluate((s) => { s.parentElement.open = true; });
    const field = page.locator('#canonical_url');
    if (!(await field.evaluate((f, s) => s.parentElement.contains(f), await summary.elementHandle()))) { throw new Error('the field is not under Search engines and sharing'); }
    if (!(await field.isVisible())) { throw new Error('no Canonical address field under Search engines and sharing'); }
    await field.fill('javascript:alert(1)');
    await Promise.all([page.waitForNavigation(), page.locator('form.formular p.tlacitka button.tl').first().click()]);
    const error = page.locator('#canonical_url ~ .chyba-pole, .chyba-pole', { hasText: 'The canonical address must start with https://' });
    if (!(await error.first().isVisible())) { throw new Error('the refusal is not shown next to the field (is the section open?)'); }
    if (sql(`SELECT canonical_url FROM ka_stranky WHERE ids = ${pageId}`).trim() !== '') { throw new Error('the invalid address was saved'); }
    if (SHOTS) { await error.first().scrollIntoViewIfNeeded(); await page.screenshot({ path: `${SHOTS}/page-canonical-error-desktop.png` }); }
    await page.locator('input[name="canonical_url"]').fill('/contact');
    await Promise.all([page.waitForNavigation(), page.locator('form.formular p.tlacitka button.tl').first().click()]);
    if (sql(`SELECT canonical_url FROM ka_stranky WHERE ids = ${pageId}`).trim() !== '/contact') { throw new Error('the path was not saved'); }
    const site = await context.newPage(); watch(site);
    await site.goto(`${BASE}/${slug}`, { waitUntil: 'networkidle' });
    const links = await site.locator('link[rel="canonical"]').evaluateAll((l) => l.map((x) => x.getAttribute('href')));
    await site.close();
    if (links.length !== 1 || links[0] !== `${BASE}/contact`) { throw new Error(`canonical links on the site: ${JSON.stringify(links)}`); }
  } finally {
    sql(`UPDATE ka_stranky SET canonical_url = '' WHERE ids = ${pageId}`);
  }
});

// 3.23.1 (N-U1): a Word list pasted from the real clipboard while double-click editing a Text on the canvas becomes a list
// (the cleanup of the panel editor); a Heading and a Button take plain text
await step('3.23.1 (N-U1) builder canvas: Word paste with Ctrl+V gives <ul> in a Text, plain text in a Heading and a Button', async () => {
  const pageId = sql("SELECT ids FROM ka_stranky WHERE seo_link = 'services' AND jazyk = '' AND smazano IS NULL LIMIT 1").trim();
  await context.grantPermissions(['clipboard-read', 'clipboard-write'], { origin: BASE });
  await visit(`/admin.php?module=pages&action=builder&id=${pageId}`);
  await builderIdle();
  const word = `<html xmlns:o="urn:schemas-microsoft-com:office:office"><head><style>p.MsoListParagraph{}</style></head><body lang=EN>
<p class=MsoNormal style='font-family:"Comic Sans MS";color:red;font-size:20.0pt'><b>Our maintenance package</b><o:p></o:p></p>
<p class=MsoListParagraphCxSpFirst style='text-indent:-18.0pt;mso-list:l0 level1 lfo1'><![if !supportLists]><span style='font-family:Symbol'><span style='mso-list:Ignore'>·<span style='font:7.0pt "Times New Roman"'>&nbsp;&nbsp;&nbsp; </span></span></span><![endif]>Annual inspection<o:p></o:p></p>
<p class=MsoListParagraphCxSpLast style='text-indent:-18.0pt;mso-list:l0 level1 lfo1'><![if !supportLists]><span style='font-family:Symbol'><span style='mso-list:Ignore'>·<span>&nbsp;&nbsp; </span></span></span><![endif]>24/7 call-out<o:p></o:p></p>
</body></html>`;
  const plain = 'Our maintenance package\n· Annual inspection\n· 24/7 call-out';
  // the clipboard is written by the builder page itself (it needs the focus), as a copy in Word would leave it
  const copy = async () => { await page.locator('.st-stav').click(); await page.evaluate(async ([h, t]) => {
    await navigator.clipboard.write([new ClipboardItem({ 'text/html': new Blob([h], { type: 'text/html' }), 'text/plain': new Blob([t], { type: 'text/plain' }) })]);
  }, [word, plain]); };
  const pasteInto = async (target) => {
    await copy();
    await target.dblclick();
    await liveCanvas().locator('[contenteditable]').first().waitFor();
    await page.keyboard.press('ControlOrMeta+A');
    await page.keyboard.press('ControlOrMeta+V');
    await page.waitForTimeout(300);
    const html = await liveCanvas().locator('[contenteditable]').first().evaluate((e) => e.innerHTML);
    await page.keyboard.press('Escape');
    await builderIdle();
    return html.replace(/\s+/g, ' ');
  };
  const text = await pasteInto(liveCanvas().locator('.ka-text').first());
  if (!/<ul><li>Annual inspection<\/li><li>24\/7 call-out<\/li><\/ul>/.test(text) || /·|Comic|color|style=|Mso|mso-/i.test(text)) { throw new Error(`the Text after the paste: ${text}`); }
  const saved = await liveCanvas().locator('.ka-text').first().evaluate((e) => e.innerHTML.replace(/\s+/g, ' '));
  if (!saved.includes('<ul><li>Annual inspection</li><li>24/7 call-out</li></ul>')) { throw new Error(`the saved Text: ${saved}`); }
  const heading = await pasteInto(liveCanvas().locator('h2').first());
  if (heading !== 'Our maintenance package · Annual inspection · 24/7 call-out') { throw new Error(`the Heading after the paste: ${heading}`); }
  const button = await pasteInto(liveCanvas().locator('.ka-tlacitko').first());
  if (/[<>]/.test(button) || !button.includes('Annual inspection')) { throw new Error(`the Button after the paste: ${button}`); }
  for (let i = 0; i < 3; i++) { await page.keyboard.press('ControlOrMeta+Z'); await page.waitForTimeout(150); } // the page as it was
  await builderIdle();
});

// 3.23.1 (N-U5, N-U13): iPad landscape and small laptops (1024–1179 px) get the rails – nothing in the Add panel or the top bar
// is clipped and the canvas keeps most of the width; the first element selected on the canvas opens Properties; 1366 px is
// the desktop layout
await step('3.23.1 (N-U5, N-U13) builder at 1024 and 1179 px: rails, nothing clipped, the first selection opens Properties; 1366 px unchanged', async () => {
  const clipped = () => page.evaluate(() => {
    const cut = (sel) => [...document.querySelectorAll(sel)].filter((b) => b.offsetParent && b.scrollWidth > b.clientWidth + 1).map((b) => `${b.className}: ${b.textContent.trim().slice(0, 30)}`);
    return { canvas: document.querySelector('.st-platno').getBoundingClientRect().width / innerWidth, rails: [...document.querySelectorAll('.st-pruh')].filter((r) => r.offsetParent).length,
      tiles: cut('.st-prvky button'), bar: cut('.st-lista *'), off: [...document.querySelectorAll('.st-lista *')].filter((b) => b.offsetParent && b.getBoundingClientRect().right > innerWidth + 0.5).map((b) => b.className),
      scroll: document.documentElement.scrollWidth > innerWidth };
  });
  try {
    for (const [width, height] of [[1024, 768], [1179, 820]]) {
      await page.setViewportSize({ width, height });
      await visit('/admin.php?module=pages&action=builder&id=1');
      await builderIdle();
      await page.locator('.st-pruh-levy button[aria-label="Add"]').click();
      await page.locator('.st-levy .st-prvky').first().waitFor();
      const m = await clipped();
      if (m.rails !== 2 || m.canvas < 0.6 || m.tiles.length || m.bar.length || m.off.length || m.scroll) { throw new Error(`${width} px: ${JSON.stringify(m)}`); }
      if (SHOTS) { await page.screenshot({ path: `${SHOTS}/nu5-builder-${width}-add.png` }); }
      await page.keyboard.press('Escape');
      if (await page.locator('.st-pravy').isVisible()) { throw new Error(`${width} px: Properties open before anything is selected`); }
      await liveCanvas().locator('h1').first().click();
      await page.locator('.st-pravy').waitFor({ state: 'visible', timeout: 3000 });
      if ((await page.locator('.st-pruh-pravy button').getAttribute('aria-expanded')) !== 'true') { throw new Error(`${width} px: the Properties rail button does not say it is open`); }
      if (!(await page.locator('.st-pravy').evaluate((p) => p.scrollWidth <= p.clientWidth))) { throw new Error(`${width} px: Properties scroll sideways`); }
      if (SHOTS) { await page.screenshot({ path: `${SHOTS}/nu13-builder-${width}-properties.png` }); }
      await page.keyboard.press('Escape');
    }
    await page.setViewportSize({ width: 1366, height: 1024 });
    await visit('/admin.php?module=pages&action=builder&id=1');
    await builderIdle();
    const desktop = await page.evaluate(() => ({ rails: [...document.querySelectorAll('.st-pruh')].some((r) => r.offsetParent), left: !!document.querySelector('.st-levy').offsetParent,
      right: !!document.querySelector('.st-pravy').offsetParent, columns: getComputedStyle(document.querySelector('.st')).gridTemplateColumns.split(' ').length }));
    if (desktop.rails || !desktop.left || !desktop.right || desktop.columns !== 3) { throw new Error(`1366 px is not the desktop layout: ${JSON.stringify(desktop)}`); }
  } finally {
    await page.setViewportSize({ width: 1440, height: 900 });
  }
});

// 3.23.1 (CPO 1 Nov, item 12): the 3.20–3.23 flows end to end in the browser, mail caught by a local SMTP sink (tools/fake-smtp.php,
// started by tools/test-browser.sh – nothing leaves the machine): an enquiry reply, an Appearance-area member, every Settings tab
const { SMTP_DIR, SMTP_PORT, WEB_DIR } = process.env;
const fs = await import('node:fs');
const latestMail = (to) => {
  const files = fs.readdirSync(SMTP_DIR).filter((f) => f.endsWith('.eml')).sort((a, b) => parseInt(a, 10) - parseInt(b, 10));
  for (const f of files.reverse()) { const raw = fs.readFileSync(`${SMTP_DIR}/${f}`, 'utf8'); if (raw.startsWith(`X-Rcpt-To: ${to}`)) { return raw; } }
  return null;
};
// the decoded parts of a message: headers, then every base64 or quoted-printable part as text
const decodeMail = (raw) => {
  const [head, body] = raw.split('\r\n\r\n', 2).length === 2 ? [raw.slice(0, raw.indexOf('\r\n\r\n')), raw.slice(raw.indexOf('\r\n\r\n') + 4)] : [raw, ''];
  const parts = [...body.matchAll(/base64\r\n\r\n([A-Za-z0-9+/=\r\n]+)/g)].map((m) => Buffer.from(m[1].replace(/\s+/g, ''), 'base64').toString('utf8'));
  if (!parts.length && /^Content-Transfer-Encoding: base64/mi.test(head)) { parts.push(Buffer.from(body.replace(/\s+/g, ''), 'base64').toString('utf8')); }
  return { head, text: parts.length ? parts.join('\n') : body };
};

await step('3.23.1 item 12: a reply sent with the enquiry form reaches the SMTP sink – From the site, Reply-To the user, the signature', async () => {
  if (!SMTP_DIR || !SMTP_PORT || !WEB_DIR) { throw new Error('SMTP_DIR, SMTP_PORT and WEB_DIR are not set (tools/test-browser.sh)'); }
  sql(`REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('mail_mode', 'smtp'), ('smtp_host', '127.0.0.1'), ('smtp_port', '${Number(SMTP_PORT)}'), ('smtp_encryption', 'zadne'), ('smtp_user', ''), ('mail_from', 'web@browser.example')`);
  // the admin's own Team record (a people collection, chosen for the account) gives the signature; an earlier step may have chosen another
  const earlierRecord = sql("SELECT IFNULL(team_item_id, 'NULL') FROM ka_uzivatele WHERE user = 'admin'").trim();
  execFileSync('php', ['-r', `require "system/bootstrap.php"; $app = Kaleta\\Core\\App::boot(); $db = $app->db();
    $idk = (int) Kaleta\\Builder\\Presets::create($app, "people", "Team 3231"); $f = Kaleta\\Builder\\EmailSignature::fields((array) Kaleta\\Builder\\Collections::byId($db, $idk));
    $item = $db->insert("kolekce_polozky", ["idk" => $idk, "nazev" => "Alex Signature", "seo_link" => "alex-signature", "data" => (string) json_encode(array_filter([$f["email"] => "alex@browser.example", $f["role"] => "Head of sales"], fn ($v, $k) => $k !== "", ARRAY_FILTER_USE_BOTH)), "zobrazit" => 1, "jazyk" => "", "datum" => date("Y-m-d H:i:s"), "poradi" => 0]);
    $db->update("uzivatele", ["email" => "alex@browser.example", "team_item_id" => $item], ["user" => "admin"]);`], { cwd: WEB_DIR, encoding: 'utf8' });
  sql("INSERT INTO ka_poptavky (datum, formular, zdroj, stranka, email, data, stav, navrh_odpovedi) VALUES (NOW(), 'Quote 3231', '', '/contact', 'grace@customer.example', '[[\"Message\",\"How much is a kitchen?\"]]', 0, 'Hello Grace, we will send a quote.')");
  const id = Number(sql("SELECT idp FROM ka_poptavky WHERE email = 'grace@customer.example' ORDER BY idp DESC LIMIT 1").trim());
  try {
    await page.setViewportSize({ width: 1440, height: 900 });
    await visit(`/admin.php?module=enquiries&action=detail&id=${id}`);
    const form = page.locator('form[data-reply-form]');
    if (await form.count() !== 1) { throw new Error('no reply form with SMTP set up'); }
    // N-C5: with a signature the hint says it is added; no "no signature" note
    if (await form.locator('[data-reply-no-signature]').count() !== 0 || !/e-mail signature from My account is added/.test(await form.textContent())) { throw new Error('the hint does not promise the signature the user has'); }
    if ((await form.locator('#odpoved-text').inputValue()) !== 'Hello Grace, we will send a quote.') { throw new Error('the drafted reply is not prefilled'); }
    await form.locator('#odpoved-text').fill('Hello Grace,\nthe quote follows by Friday.');
    await form.locator('button[type="submit"]', { hasText: 'Send reply' }).click();
    const confirm = page.locator('dialog.potvrzeni[open]');
    await confirm.waitFor();
    if (!(await confirm.textContent()).includes('grace@customer.example')) { throw new Error('the confirmation does not name the recipient'); }
    await Promise.all([page.waitForNavigation(), confirm.locator('[data-ano]').click()]);
    const raw = latestMail('grace@customer.example');
    if (!raw) { throw new Error('nothing reached the SMTP sink for grace@customer.example'); }
    const { head, text } = decodeMail(raw);
    if (!/^Reply-To: alex@browser\.example\r?$/m.test(head)) { throw new Error(`Reply-To is not the user: ${(/^Reply-To:.*$/m.exec(head) || ['none'])[0]}`); }
    if (!/^From: .*web@browser\.example/m.test(head)) { throw new Error('From is not the site sender'); }
    if ((raw.match(/^X-Rcpt-To:/gm) || []).length !== 1) { throw new Error('more than one recipient'); }
    const missing = [['the text', text.includes('the quote follows by Friday.')], ['the -- line', /\n-- \r?\n/.test(text)], ['the name', text.includes('Alex Signature')], ['the role', text.includes('Head of sales')]].filter(([, ok]) => !ok).map(([what]) => what);
    if (missing.length) { throw new Error(`missing in the sent mail: ${missing.join(', ')}`); }
    const logged = sql(`SELECT CONCAT_WS('|', stav, JSON_UNQUOTE(JSON_EXTRACT(replies, '$[0].status')), JSON_UNQUOTE(JSON_EXTRACT(replies, '$[0].reply_to'))) FROM ka_poptavky WHERE idp = ${id}`).trim();
    if (logged !== '2|sent|alex@browser.example') { throw new Error(`the enquiry logged: ${logged}`); }
    if (await page.locator('[data-reply-status="sent"]').count() !== 1) { throw new Error('the detail does not show the sent reply'); }
    // N-C5: without a Team record the form says the reply goes without a signature and links My e-mail signature
    sql("UPDATE ka_uzivatele SET email = 'nobody-3231@browser.example', team_item_id = NULL WHERE user = 'admin'");
    await visit(`/admin.php?module=enquiries&action=detail&id=${id}`);
    const hint = page.locator('form[data-reply-form] [data-reply-no-signature]');
    if (await hint.count() !== 1 || (await hint.locator('a', { hasText: 'My account → My e-mail signature' }).getAttribute('href')) !== '/admin.php?action=account&tab=signature') { throw new Error('without a signature the hint does not say so or does not link My e-mail signature'); }
    if (SHOTS) { await hint.scrollIntoViewIfNeeded(); await page.screenshot({ path: `${SHOTS}/enquiry-reply-no-signature-desktop.png` }); }
  } finally {
    sql(`UPDATE ka_uzivatele SET email = '', team_item_id = ${earlierRecord === 'NULL' ? 'NULL' : Number(earlierRecord)} WHERE user = 'admin'; DELETE FROM ka_poptavky WHERE idp = ${id}; UPDATE ka_nastaveni SET hodnota = 'mail' WHERE promenna = 'mail_mode'`);
  }
});

await step('3.23.1 item 12: a custom role with the Appearance area – its member signs in and gets Menu (200), not Site appearance (403)', async () => {
  // the role made with the role form (a custom role, not the Client preset, which 3.23.1 changes elsewhere)
  await visit('/admin.php?module=roles&action=new');
  await page.fill('#nazev', 'Appearance 3231');
  await page.locator('input[name="uroven"][value="1"]').check();
  await page.locator('input[name="moduly[]"][value="pages"]').check();
  await page.locator('input[name="moduly[]"][value="appearance_area"]').check();
  await Promise.all([page.waitForNavigation(), page.locator('form p.tlacitka input.tl').click()]);
  const idr = sql("SELECT idr FROM ka_role WHERE nazev = 'Appearance 3231'").trim();
  if (!idr) { throw new Error('the role was not saved'); }
  const secret = `Look-${createHash('sha256').update(String(Date.now())).digest('hex').slice(0, 12)}`;
  await visit('/admin.php?module=users&action=new');
  await page.fill('#user', 'look3231');
  await page.fill('#jmeno', 'Lou Look');
  await page.fill('#password', secret);
  await page.locator(`input[name="admin"][value="r${idr}"]`).check();
  await Promise.all([page.waitForNavigation(), page.locator('form p.tlacitka input.tl').first().click()]);
  if (sql("SELECT COUNT(*) FROM ka_uzivatele WHERE user = 'look3231'").trim() !== '1') { throw new Error('the user was not saved'); }
  const member = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'en-GB' });
  const p2 = await member.newPage(); watch(p2);
  try {
    sql("DELETE FROM ka_kontrola_ip WHERE typ = 'login'");
    await p2.goto(`${BASE}/admin.php`, { waitUntil: 'networkidle' });
    await p2.fill('input[name="user"]', 'look3231');
    await p2.fill('input[name="password"]', secret);
    await Promise.all([p2.waitForNavigation(), p2.press('input[name="password"]', 'Enter')]);
    const codes = {};
    for (const [name, url] of [['menu', '/admin.php?module=menu'], ['parts', '/admin.php?module=parts'], ['appearance', '/admin.php?module=appearance'], ['settings', '/admin.php?module=settings&tab=general']]) {
      codes[name] = (await p2.goto(`${BASE}${url}`, { waitUntil: 'networkidle' })).status();
      if (name === 'menu' && !(await p2.locator('h1').first().textContent()).includes('Menu')) { throw new Error('the Menu screen does not open for the member'); }
    }
    if (JSON.stringify(codes) !== JSON.stringify({ menu: 200, parts: 200, appearance: 403, settings: 403 })) { throw new Error(`the member got ${JSON.stringify(codes)}`); }
  } finally {
    await member.close();
    sql(`DELETE p FROM ka_uzivatele_prava p JOIN ka_uzivatele u ON u.idu = p.fk_id_user WHERE u.user = 'look3231'; DELETE FROM ka_uzivatele WHERE user = 'look3231'; DELETE FROM ka_role WHERE idr = ${Number(idr) || 0}`);
  }
});

await step('3.23.1 item 12: each Settings tab saved with its Save button keeps the values of every other tab', async () => {
  await page.setViewportSize({ width: 1440, height: 900 });
  sql("REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('agency_name', 'Studio 3231')"); // the console tab is in the row too
  await visit('/admin.php?module=settings&tab=general');
  const tabs = await page.locator('nav.zalozky a').evaluateAll((links) => links.map((a) => new URL(a.href).searchParams.get('tab')));
  if (tabs.length !== 13) { throw new Error(`the row has ${tabs.length} tabs: ${tabs.join(', ')}`); }
  // mysql -N escapes line breaks and tabs inside a value, so one line is one setting
  // 3.24 (T11): the admin runs the light background jobs after its answer, so their bookkeeping (notification_check and the
  // other *_check/_time/_at stamps – Core\AgentJournal::BOOKKEEPING) moves on its own while the tabs are saved; it is no setting
  const bookkeeping = /^(db_version|data_migrations|notification_check)$|_(check|time|checked|seen|ts|at)$/;
  const snapshot = () => Object.fromEntries(sql('SELECT promenna, hodnota FROM ka_nastaveni').split('\n').filter(Boolean).map((l) => [l.slice(0, l.indexOf('\t')), l.slice(l.indexOf('\t') + 1)]).filter(([k]) => !bookkeeping.test(k)));
  const saved = [];
  const saveAll = async () => {
    for (const tab of tabs) {
      await visit(`/admin.php?module=settings&tab=${tab}`);
      const save = page.locator('form.formular > p.tlacitka input.tl[type="submit"]'); // the console tab has only its own buttons
      if (await save.count() === 0) { continue; }
      saved.push(tab);
      await Promise.all([page.waitForNavigation(), save.last().click()]);
      if (new URL(page.url()).searchParams.get('tab') !== tab) { throw new Error(`saving ${tab} went to ${page.url()}`); }
    }
  };
  // the first round may only add the default of a key never saved before; the second round must change nothing at all
  const before = snapshot(); await saveAll(); const middle = snapshot();
  const changed = Object.keys(before).filter((k) => middle[k] !== before[k]);
  await saveAll(); const after = snapshot();
  const drift = [...new Set([...Object.keys(middle), ...Object.keys(after)])].filter((k) => middle[k] !== after[k]);
  sql("UPDATE ka_nastaveni SET hodnota = '' WHERE promenna = 'agency_name'");
  if (changed.length || drift.length) { throw new Error(`changed by saving other tabs: ${changed.join(', ')} | second round: ${drift.join(', ')}`); }
  if (saved.length < 24) { throw new Error(`only ${saved.length / 2} tabs had a Save button: ${saved.join(', ')}`); }
});

await step('3.23.1 (N-C4, N-C12): at 390 px System status clips nothing and Settings offers its tabs as a select', async () => {
  try {
    await page.setViewportSize({ width: 390, height: 844 });
    await visit('/admin.php?module=status');
    const clipped = await page.evaluate(() => [...document.querySelectorAll('main .tab-obal, main fieldset')].filter((c) => c.scrollWidth > c.clientWidth + 1).map((c) => `${c.className || c.tagName} ${c.scrollWidth}>${c.clientWidth}`));
    if (clipped.length) { throw new Error(`clipped on System status: ${clipped.join(', ')}`); }
    if (await page.evaluate(() => document.documentElement.scrollWidth > innerWidth)) { throw new Error('System status scrolls sideways'); }
    if (SHOTS) { await page.screenshot({ path: `${SHOTS}/status-phone.png`, fullPage: true }); }
    await visit('/admin.php?module=settings&tab=general');
    if (await page.locator('nav.zalozky').isVisible()) { throw new Error('the row of tabs still shows on a phone'); }
    const select = page.locator('select#zalozka-vyber');
    if (!(await select.isVisible()) || (await select.locator('option').count()) < 12 || (await select.inputValue()) !== 'general') { throw new Error('no select of the Settings tabs on a phone'); }
    await Promise.all([page.waitForNavigation(), select.selectOption('security')]);
    if (!page.url().includes('module=settings&tab=security') || !(await page.locator('[name="require_2fa"]').count())) { throw new Error(`choosing Security opened ${page.url()}`); }
    if (SHOTS) { await page.screenshot({ path: `${SHOTS}/settings-select-phone.png` }); }
    await page.setViewportSize({ width: 1440, height: 900 });
    await visit('/admin.php?module=settings&tab=general');
    if (!(await page.locator('nav.zalozky').isVisible()) || (await page.locator('select#zalozka-vyber').isVisible())) { throw new Error('the desktop lost its row of tabs or shows the select'); }
  } finally {
    await page.setViewportSize({ width: 1440, height: 900 });
  }
});

// 3.23.1 (N-U15): a move by Alt+arrows is read out by a live region – "Moved after …"
await step('3.23.1 (N-U15) builder: Alt+ArrowDown is announced in a live region', async () => {
  await visit('/admin.php?module=pages&action=builder&id=1');
  await builderIdle();
  const id = await liveCanvas().locator('body').evaluate((b) => {
    const leaf = Array.from(b.querySelectorAll('[data-ka-id]:not([data-ka-zamek])')).find((x) => !x.querySelector('[data-ka-id]') && x.nextElementSibling && x.nextElementSibling.hasAttribute('data-ka-id') && x.getBoundingClientRect().height > 8);
    return leaf ? leaf.getAttribute('data-ka-id') : '';
  });
  if (!id) { throw new Error('no element with a next sibling on the canvas'); }
  await liveCanvas().locator(`[data-ka-id="${id}"]`).click({ position: { x: 4, y: 4 } });
  await page.keyboard.press('Alt+ArrowDown');
  const region = page.locator('.st-ohlas[aria-live="polite"]');
  await page.waitForFunction(() => /^Moved after .+\.$/.test((document.querySelector('.st-ohlas') || {}).textContent || ''), null, { timeout: 5000 });
  if (!(await region.evaluate((r) => r.getBoundingClientRect().width <= 1))) { throw new Error('the live region is visible'); }
  const first = await region.textContent();
  await builderIdle();
  await liveCanvas().locator(`[data-ka-id="${id}"]`).click({ position: { x: 4, y: 4 } });
  await page.keyboard.press('Alt+ArrowUp'); // back where it was
  await page.waitForFunction((before) => { const t = (document.querySelector('.st-ohlas') || {}).textContent || ''; return t !== before && /^Moved (after|to the start of) .+\.$/.test(t); }, first, { timeout: 5000 });
  await builderIdle();
});

// 3.23.1 (N-U16): "Edit here" on the public site leads to the builder without a console error – the site page skips its page
// transition into the administration (before, Chrome aborted it with "Transition was aborted because of invalid state")
await step('3.23.1 (N-U16) "Edit here" from the home page and from Contact: no console error', async () => {
  for (const path of ['/', '/contact']) {
    await visit(path);
    await Promise.all([page.waitForURL(/action=builder/), page.locator('a.ka-upravit-zde').first().click()]);
    await page.waitForLoadState('networkidle');
    await builderIdle();
  }
});

// 3.24 (agent B): the responsible person in the page settings and the item form, the Mine tabs, the assignment e-mail queued,
// the dashboard tile and "Draft by …" (N-U6) in the Pages list and the builder's top bar – at 1440 px and on a phone
await step('3.24 (B): responsible person, Mine, Assigned to me, Draft by – Pages, an item, the dashboard, the builder', async () => {
  if (!MYSQL.length) { throw new Error('MYSQL_JSON is not set'); }
  sql(`DELETE FROM ka_uzivatele WHERE user = 'b324-colleague'; INSERT INTO ka_uzivatele (user, password, jmeno, email, jazyk, admin, posledni_login, potvrzeno) VALUES ('b324-colleague', '', 'Browser Colleague', 'colleague-b324@example.com', 'cs', 1, NOW(), NOW());
    INSERT INTO ka_uzivatele_prava (fk_id_user, ident_modulu) SELECT idu, m.ident FROM ka_uzivatele JOIN (SELECT 'pages' AS ident UNION SELECT 'collections' UNION SELECT 'enquiries') m WHERE user = 'b324-colleague'`);
  const colleague = sql("SELECT idu FROM ka_uzivatele WHERE user = 'b324-colleague'").trim();
  const admin = sql("SELECT idu FROM ka_uzivatele WHERE user = 'admin'").trim();
  const pageId = sql("SELECT ids FROM ka_stranky WHERE smazano IS NULL AND ids <> 1 ORDER BY ids LIMIT 1").trim();
  await visit(`/admin.php?module=pages&action=edit&id=${pageId}`);
  const select = page.locator('[data-owner-field] select[name="owner_id"]');
  if (!(await select.isVisible())) { throw new Error('no Responsible person in the page settings'); }
  if ((await page.locator('[data-owner-field] label').textContent()).trim() !== 'Responsible person') { throw new Error('the label is not English'); }
  await select.selectOption(colleague);
  await Promise.all([page.waitForNavigation(), page.locator('form.formular button.tl[type="submit"]').first().click()]);
  if (sql(`SELECT owner_id FROM ka_stranky WHERE ids = ${pageId}`).trim() !== colleague) { throw new Error('the responsible person was not saved'); }
  // the colleague's admin language is Czech: the queued e-mail is Czech, names the page and never goes to the administrator
  const mail = sql(`SELECT COUNT(*) FROM ka_posta WHERE komu = 'colleague-b324@example.com' AND predmet LIKE 'Odpov%'`).trim();
  if (mail !== '1') { throw new Error(`the assignment e-mail: ${mail} rows`); }
  sql(`UPDATE ka_stranky SET owner_id = ${admin} WHERE ids = ${pageId}`);
  await visit('/admin.php?module=pages');
  const mine = page.locator('nav.zalozky a[href*="status=mine"]');
  if (!/^Mine \(\d+\)$/.test((await mine.textContent()).trim())) { throw new Error(`the Mine tab says “${await mine.textContent()}”`); }
  await Promise.all([page.waitForNavigation(), mine.click()]);
  if ((await page.locator('table.vypis tbody tr').count()) !== Number(sql(`SELECT COUNT(*) FROM ka_stranky WHERE owner_id = ${admin} AND smazano IS NULL`).trim())) { throw new Error('Mine lists other pages'); }
  // an item: the same select in the item form
  const item = sql('SELECT CONCAT(idk, ",", idp) FROM ka_kolekce_polozky WHERE smazano IS NULL ORDER BY idp LIMIT 1').trim().split(',');
  await visit(`/admin.php?module=collections&action=item&id=${item[0]}&item=${item[1]}`);
  if ((await page.locator(`[data-owner-field] select option[value="${colleague}"]`).textContent()).trim() !== 'Browser Colleague') { throw new Error('the item form does not offer the colleague'); }
  // the dashboard tile for a user with Enquiries
  sql(`INSERT INTO ka_poptavky (datum, formular, email, data, stav, prirazeno) VALUES (NOW(), 'B324 form', 'x-b324@example.com', '[]', 1, ${admin})`);
  await visit('/admin.php');
  const tile = page.locator('a.dlazdice-polozka[href*="status=moje"]');
  if ((await tile.locator('span').textContent()).trim() !== 'Assigned to me' || !/^\d+$/.test((await tile.locator('strong').textContent()).trim())) { throw new Error(`the tile says “${await tile.textContent()}”`); }
  // Draft by: a draft whose last autosave was the colleague's – the list and the builder name her, the top bar stays one line
  sql(`UPDATE ka_stranky SET stavba_koncept = COALESCE(stavba, '{"v":1,"deti":[]}'), zmeneno = NOW() - INTERVAL 2 HOUR WHERE ids = ${pageId};
    INSERT INTO ka_protokol (cas, kdo, jmeno, modul, akce, popis) VALUES (NOW() - INTERVAL 5 MINUTE, ${colleague}, 'Browser Colleague', 'pages', 'draft edited', '#${pageId} B324')`);
  await visit('/admin.php?module=pages');
  const label = (await page.locator(`tr:has(a[href$="action=edit&id=${pageId}"]) [data-draft-by]`).first().textContent()).trim();
  if (!/^Draft by Browser Colleague, \d\d:\d\d$/.test(label)) { throw new Error(`the Pages list says “${label}”`); }
  await visit(`/admin.php?module=pages&action=builder&id=${pageId}`);
  await builderIdle();
  const bar = page.locator('.st-lista [data-draft-by]');
  if (!/^Draft by Browser Colleague, \d\d:\d\d$/.test((await bar.textContent()).trim())) { throw new Error(`the builder top bar says “${await bar.textContent()}”`); }
  const top = (await page.locator('.st-lista').boundingBox()).height;
  if (top > 60) { throw new Error(`the builder top bar with Draft by is ${top} px tall at 1440 px`); }
  if (SHOTS) { await page.screenshot({ path: `${SHOTS}/324-builder-draft-by.png` }); }
  await page.setViewportSize({ width: 390, height: 844 });
  await visit(`/admin.php?module=pages&action=edit&id=${pageId}`);
  const wide = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
  if (wide > 0) { throw new Error(`the page settings scroll ${wide} px sideways at 390 px`); }
  if (SHOTS) { await page.screenshot({ path: `${SHOTS}/324-owner-phone.png`, fullPage: true }); }
  await page.setViewportSize({ width: 1440, height: 900 });
  sql(`UPDATE ka_stranky SET stavba_koncept = NULL, owner_id = NULL WHERE ids = ${pageId}; DELETE FROM ka_poptavky WHERE formular = 'B324 form';
    DELETE FROM ka_uzivatele_prava WHERE fk_id_user = ${colleague}; DELETE FROM ka_uzivatele WHERE idu = ${colleague}`);
});

// 3.24: My work – the first menu item of a non-administrator, the rows that wait for them, a phone without sideways scroll and
// dark mode; then the administrator hands that person's work over and blocks the account in one step from Users
await step('3.24 My work: first in an editor\'s menu, the waiting rows (1440 and 390 px, dark mode); Users hands the work over and blocks', async () => {
  const secret = `Work-${createHash('sha256').update(String(Date.now())).digest('hex').slice(0, 12)}`;
  await visit('/admin.php?module=users&action=new');
  await page.fill('#user', 'work324');
  await page.fill('#jmeno', 'Wendy Work');
  await page.fill('#email', 'work324@browser.example');
  await page.fill('#password', secret);
  await page.locator('input[name="admin"][value="1"]').check(); // an editor: every content section by default
  await Promise.all([page.waitForNavigation(), page.locator('form p.tlacitka input.tl').first().click()]);
  const idu = sql("SELECT idu FROM ka_uzivatele WHERE user = 'work324'").trim();
  if (!idu) { throw new Error('the user was not saved'); }
  const today = new Date(); const soon = new Date(today.getTime() + 3 * 86400000).toISOString().slice(0, 10);
  sql(`INSERT INTO ka_stranky (seo_link, titulek, text, zobrazit, v_menu, review_by, stavba_koncept, owner_id) VALUES ('work-324', 'Price list 324', '', 0, 0, '${soon}', '{"v":1,"deti":[]}', ${Number(idu)});
    INSERT INTO ka_poptavky (datum, formular, email, data, stav, prirazeno) VALUES (NOW(), 'Quote 324', 'q324@visitor.example', '[]', 0, ${Number(idu)})`);
  const member = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'en-GB' });
  const p2 = await member.newPage(); watch(p2);
  try {
    sql("DELETE FROM ka_kontrola_ip WHERE typ = 'login'");
    await p2.goto(`${BASE}/admin.php`, { waitUntil: 'networkidle' });
    await p2.fill('input[name="user"]', 'work324');
    await p2.fill('input[name="password"]', secret);
    await Promise.all([p2.waitForNavigation(), p2.press('input[name="password"]', 'Enter')]);
    const first = await p2.locator('#menu > li').first().evaluate((li) => ({ cls: li.className, text: li.textContent.trim() }));
    if (!first.cls.includes('menu-moje') || !first.text.startsWith('My work')) { throw new Error(`the first menu item is ${JSON.stringify(first)}`); }
    await Promise.all([p2.waitForNavigation(), p2.locator('#menu > li.menu-moje a').click()]);
    for (const [list, text] of [['assigned', 'Quote 324'], ['pages', 'Price list 324']]) {
      if (!(await p2.locator(`[data-list="${list}"] a`, { hasText: text }).isVisible())) { throw new Error(`My work does not show ${text} in ${list}`); }
    }
    if (!(await p2.locator('[data-list="pages"] .stitek', { hasText: 'Unpublished changes' }).isVisible())) { throw new Error('the page row does not say why it waits'); }
    if (SHOTS) { await p2.screenshot({ path: `${SHOTS}/my-work-desktop.png`, fullPage: true }); }
    await Promise.all([p2.waitForNavigation(), p2.locator('[data-list="pages"] a', { hasText: 'Price list 324' }).click()]);
    if (!p2.url().includes('module=pages&action=edit')) { throw new Error(`the page row leads to ${p2.url()}`); }
    for (const scheme of ['light', 'dark']) {
      const phone = await browser.newContext({ viewport: { width: 390, height: 844 }, locale: 'en-GB', isMobile: true, hasTouch: true, colorScheme: scheme, storageState: await member.storageState() });
      const p3 = await phone.newPage(); watch(p3);
      await p3.goto(`${BASE}/admin.php?module=my_work`, { waitUntil: 'networkidle' });
      const wide = await p3.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
      if (wide > 0) { throw new Error(`${wide} px of sideways scroll at 390 px (${scheme})`); }
      if (SHOTS) { await p3.screenshot({ path: `${SHOTS}/my-work-phone-${scheme}.png`, fullPage: true }); }
      await phone.close();
    }
  } finally {
    await member.close();
  }
  // the administrator: Users says what the person is responsible for and hands it over with the block in one step
  await visit('/admin.php?module=users');
  await Promise.all([page.waitForNavigation(), page.locator(`a[href*="action=handover&id=${Number(idu)}"]`).first().click()]);
  const options = await page.locator('#hand_over_to option').allTextContents();
  if (!options.includes('Tester')) { throw new Error(`the recipients are ${JSON.stringify(options)}`); }
  await page.selectOption('#hand_over_to', { label: 'Tester' });
  if (SHOTS) { await page.screenshot({ path: `${SHOTS}/users-handover-desktop.png` }); }
  await Promise.all([page.waitForNavigation(), page.locator('button[name="then"][value="block"]').click()]);
  const after = sql(`SELECT CONCAT_WS('|', (SELECT owner_id FROM ka_stranky WHERE seo_link = 'work-324'), (SELECT prirazeno FROM ka_poptavky WHERE formular = 'Quote 324'), (SELECT blokovat FROM ka_uzivatele WHERE idu = ${Number(idu)}), (SELECT idu FROM ka_uzivatele WHERE user = 'admin'))`).trim().split('|');
  if (after[0] !== after[3] || after[1] !== after[3] || after[2] !== '1') { throw new Error(`after the hand-over: ${after.join('|')}`); }
  if (!(await page.locator('.hlaska-ok', { hasText: 'Now responsible: Tester' }).isVisible())) { throw new Error('no message about the hand-over'); }
  sql(`DELETE FROM ka_stranky WHERE seo_link = 'work-324'; DELETE FROM ka_poptavky WHERE formular = 'Quote 324'; DELETE FROM ka_uzivatele_prava WHERE fk_id_user = ${Number(idu)}; DELETE FROM ka_uzivatele WHERE idu = ${Number(idu)}`);
});

// 3.25 (C21, U17): the Versions dialog lists the draft history and the published versions; Compare shows a per-section text
// diff against the current draft; loading an autosave makes it the draft and Undo takes it back
await step('3.25 builder Versions: draft history, per-section compare, load an autosave and undo it (1440 and 390 px, dark mode)', async () => {
  if (!MYSQL.length) { throw new Error('MYSQL_JSON is not set'); }
  const section = (id, heading, text) => ({ id, typ: 'sekce', znacka: 'section', obsah: {}, deti: [{ id: id + 'h', typ: 'nadpis', znacka: 'h2', obsah: { text: heading } }, { id: id + 't', typ: 'text', znacka: 'div', obsah: { html: `<p>${text}</p>` } }] });
  const now = JSON.stringify({ v: 1, deti: [section('b325a', 'Prices', 'From 120 EUR a month.'), section('b325c', 'Reviews', 'Clients love it.')] }).replace(/'/g, "''");
  const old = JSON.stringify({ v: 1, deti: [section('b325a', 'Prices', 'From 100 EUR a month.'), section('b325b', 'Old offer', 'Gone soon.')] }).replace(/'/g, "''");
  sql(`DELETE FROM ka_stranky WHERE seo_link = 'versions-325'; INSERT INTO ka_stranky (seo_link, titulek, text, v_menu, stavba) VALUES ('versions-325', 'Versions 325', '', 0, '${now}')`);
  const id = sql("SELECT ids FROM ka_stranky WHERE seo_link = 'versions-325'").trim();
  sql(`INSERT INTO ka_stavba_revize (ids, cast, datum, kdo, stavba) SELECT ${id}, 'draft', NOW() - INTERVAL 20 MINUTE, idu, '${old}' FROM ka_uzivatele WHERE user = 'admin';
    INSERT INTO ka_stavba_revize (ids, cast, datum, kdo, stavba) VALUES (${id}, NULL, NOW() - INTERVAL 1 DAY, NULL, '${old}')`);
  await visit(`/admin.php?module=pages&action=builder&id=${id}`);
  await builderIdle();
  await page.evaluate(() => document.querySelector('#st-lista-vice button[title="Draft history and published versions"]').click());
  const dialog = page.locator('dialog.st-verze[open]');
  await dialog.waitFor();
  const draftRow = dialog.locator('.st-verze-koncepty li').first();
  if (!/^Draft – today \d\d:\d\d \(by Tester\)$/.test((await draftRow.locator('span').first().textContent()).trim())) { throw new Error(`the draft entry says “${await draftRow.textContent()}”`); }
  if ((await dialog.locator('.st-verze-publikovane li').count()) !== 1) { throw new Error('the published version is not listed'); }
  await draftRow.locator('.st-verze-porovnej').click();
  await dialog.locator('.st-porovnani-sekce').first().waitFor();
  const blocks = await dialog.locator('.st-porovnani-sekce h4').allTextContents();
  const expected = ['Prices Changed', 'Reviews Only in the current draft', 'Old offer Not in the current draft'];
  if (JSON.stringify(blocks.map((b) => b.trim())) !== JSON.stringify(expected)) { throw new Error(`the compare shows ${JSON.stringify(blocks)}`); }
  if ((await dialog.locator('.st-porovnani-sekce').first().locator('del').first().textContent()).trim() !== '100' || (await dialog.locator('.st-porovnani-sekce').first().locator('ins').first().textContent()).trim() !== '120') {
    throw new Error('the changed section does not strike 100 and underline 120');
  }
  if (SHOTS) { await page.screenshot({ path: `${SHOTS}/325-versions-compare.png` }); }
  for (const scheme of ['dark']) {
    await page.emulateMedia({ colorScheme: scheme });
    const colours = await dialog.locator('.st-diff ins').first().evaluate((n) => { const s = getComputedStyle(n); return [s.color, s.backgroundColor]; });
    if (colours[0] === colours[1]) { throw new Error(`the inserted text is unreadable in ${scheme} mode`); }
    if (SHOTS) { await page.screenshot({ path: `${SHOTS}/325-versions-compare-${scheme}.png` }); }
  }
  await page.emulateMedia({ colorScheme: 'light' });
  await dialog.locator('footer button', { hasText: 'Close' }).click();
  // load the autosave into the draft: the canvas shows the old text, Undo brings the current one back
  await page.evaluate(() => document.querySelector('#st-lista-vice button[title="Draft history and published versions"]').click());
  await page.locator('dialog.st-verze[open] .st-verze-koncepty li .st-verze-obnov').first().click();
  await canvas().locator('text=Old offer').waitFor({ timeout: 15000 });
  if (!(sql(`SELECT stavba_koncept LIKE '%Old offer%' FROM ka_stranky WHERE ids = ${id}`).trim() === '1')) { throw new Error('the autosave is not the draft on the server'); }
  if (sql(`SELECT COUNT(*) FROM ka_stavba_revize WHERE ids = ${id} AND cast = 'draft' AND stavba LIKE '%Reviews%'`).trim() !== '1') { throw new Error('the replaced draft is not kept in the draft history'); }
  await page.locator('.st-lista button[title="Undo (Ctrl+Z)"]').click();
  await canvas().locator('text=Clients love it.').waitFor({ timeout: 15000 });
  await builderIdle();
  // a phone: the dialog fits without sideways scroll
  await page.setViewportSize({ width: 390, height: 844 });
  await page.evaluate(() => document.querySelector('#st-lista-vice button[title="Draft history and published versions"]').click());
  // the newest autosave is the current draft again after Undo ("the text is the same") – the oldest one differs
  await page.locator('dialog.st-verze[open] .st-verze-koncepty li .st-verze-porovnej').last().click();
  await page.locator('dialog.st-verze[open] .st-porovnani-sekce').first().waitFor();
  const overflow = await page.locator('dialog.st-verze[open]').evaluate((d) => d.scrollWidth - d.clientWidth);
  if (overflow > 1) { throw new Error(`the Versions dialog scrolls ${overflow} px sideways at 390 px`); }
  if (SHOTS) { await page.screenshot({ path: `${SHOTS}/325-versions-phone.png` }); }
  await page.keyboard.press('Escape');
  await page.setViewportSize({ width: 1440, height: 900 });
  sql(`DELETE FROM ka_stranky WHERE ids = ${id}`);
});



// 3.25 (C): the left menu – Dashboard, My work, then the groups Content, Workspace, Appearance, Claude, Site care,
// Administration; a group folds (details/summary, by keyboard too), the group of the current screen is always open, the
// browser remembers the others; at first only Content and the current group are open (3.29.1), on a phone too; nothing scrolls sideways
await step('3.25 menu: groups in order, folding by keyboard, remembered per browser, the current one open (1440 and 390 px, dark mode)', async () => {
  await page.evaluate(() => { try { localStorage.removeItem('kaleta-menu'); } catch (e) { /* nothing */ } });
  await page.setViewportSize({ width: 1440, height: 900 });
  await visit('/admin.php?module=bookings');
  const names = (await page.locator('#menu summary.menu-skupina > span:first-child').allTextContents()).map((n) => n.trim());
  if (JSON.stringify(names) !== JSON.stringify(['Content', 'Workspace', 'Appearance', 'Claude', 'Site care', 'Administration'])) { throw new Error(`the groups are ${names.join(' | ')}`); }
  const first = await page.locator('#menu > li').evaluateAll((list) => list.slice(0, 2).map((li) => li.textContent.trim()));
  if (first[0] !== 'Dashboard' || !first[1].startsWith('My work')) { throw new Error(`the menu starts with ${first.join(' | ')}`); }
  const workspace = page.locator('details[data-menu-skupina="workspace"]');
  if (!(await workspace.evaluate((d) => d.open && d.hasAttribute('data-aktualni'))) || (await workspace.locator('[aria-current="page"]').textContent()).trim() !== 'Bookings') { throw new Error('Bookings is not the current item of an open Workspace'); }
  const statsGroup = await page.locator('.menu-cast a[href$="module=stats"]').evaluate((a) => a.closest('details').getAttribute('data-menu-skupina'));
  if (statsGroup !== 'site-care') { throw new Error(`Statistics is in ${statsGroup}`); }
  if (await page.locator('.menu-cast a[href$="module=subscribers"]').count() !== 0) { throw new Error('Subscribers still has its own menu item next to the Newsletter hub'); }
  // the keyboard opens and folds Appearance (3.29.1: folded at first – only Content and the current group are open);
  // the next screen keeps it folded, its own screen opens it, and it stays remembered
  const appearance = page.locator('details[data-menu-skupina="appearance"]');
  if (await appearance.evaluate((d) => d.open)) { throw new Error('Appearance is open before anyone opened it'); }
  await appearance.locator('summary').focus();
  await page.keyboard.press('Enter');
  if (!(await appearance.evaluate((d) => d.open))) { throw new Error('Enter on the summary did not open Appearance'); }
  await page.keyboard.press('Enter');
  if (await appearance.evaluate((d) => d.open)) { throw new Error('Enter on the summary did not fold Appearance'); }
  if (!(await page.locator('details[data-menu-skupina="appearance"] a').first().isHidden())) { throw new Error('a folded group still shows its items'); }
  await visit('/admin.php?module=pages');
  if (await appearance.evaluate((d) => d.open) || !(await page.locator('details[data-menu-skupina="content"]').evaluate((d) => d.open))) { throw new Error('the folded group did not stay folded, or the current group is not open'); }
  if (SHOTS) { await page.screenshot({ path: `${SHOTS}/menu-groups-desktop.png` }); }
  await visit('/admin.php?module=menu');
  if (!(await appearance.evaluate((d) => d.open))) { throw new Error('the group of the current screen is not open'); }
  await visit('/admin.php?module=subscribers');
  const tabs = (await page.locator('.zalozky-hub a').allTextContents()).map((t) => t.trim());
  if (JSON.stringify(tabs) !== JSON.stringify(['Newsletters', 'Subscribers']) || (await page.locator('#menu [aria-current="page"]').textContent()).trim() !== 'Newsletter') { throw new Error(`Subscribers: tabs ${tabs.join(' | ')}, current menu item not Newsletter`); }
  if (await appearance.evaluate((d) => d.open)) { throw new Error('Appearance was not remembered as folded'); }
  await page.emulateMedia({ colorScheme: 'dark' });
  await visit('/admin.php?module=bookings');
  const colours = await page.locator('details[data-menu-skupina="workspace"] > summary').evaluate((s) => [getComputedStyle(s).color, getComputedStyle(document.body).backgroundColor]);
  if (colours[0] === colours[1]) { throw new Error(`a group name is unreadable in dark mode: ${colours}`); }
  if (SHOTS) { await page.screenshot({ path: `${SHOTS}/menu-groups-desktop-dark.png` }); }
  await page.emulateMedia({ colorScheme: 'light' });
  // a phone with nothing remembered: only Content (here also the current group) is open, the summaries are big enough to tap, no sideways scroll
  const phone = await browser.newContext({ viewport: { width: 390, height: 844 }, locale: 'en-GB', isMobile: true, hasTouch: true, storageState: await page.context().storageState() });
  const p3 = await phone.newPage(); watch(p3);
  try {
    await p3.goto(`${BASE}/admin.php?module=pages`, { waitUntil: 'networkidle' });
    await p3.evaluate(() => { try { localStorage.removeItem('kaleta-menu'); } catch (e) { /* nothing */ } });
    await p3.goto(`${BASE}/admin.php?module=pages`, { waitUntil: 'networkidle' });
    await p3.click('.menu-prepinac');
    const open = await p3.locator('details[data-menu-skupina]').evaluateAll((all) => all.filter((d) => d.open).map((d) => d.getAttribute('data-menu-skupina')));
    if (JSON.stringify(open) !== JSON.stringify(['content'])) { throw new Error(`open on a phone: ${open.join(', ')}`); }
    for (const box of await Promise.all((await p3.locator('#menu summary').all()).map((x) => x.boundingBox()))) {
      if (!box || box.height < 44 || box.x < 0 || box.x + box.width > 390) { throw new Error(`a group summary does not fit or is too small to tap: ${JSON.stringify(box)}`); }
    }
    const wide = await p3.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    if (wide > 0) { throw new Error(`${wide} px of sideways scroll at 390 px`); }
    await p3.locator('details[data-menu-skupina="workspace"] > summary').tap();
    if (!(await p3.locator('details[data-menu-skupina="workspace"] a[href$="module=bookings"]').isVisible())) { throw new Error('a tap on Workspace did not open it'); }
    if (SHOTS) { await p3.screenshot({ path: `${SHOTS}/menu-groups-phone.png` }); }
  } finally {
    await phone.close();
  }
  await page.evaluate(() => { try { localStorage.removeItem('kaleta-menu'); } catch (e) { /* nothing */ } });
});

// 3.29.1 (owner, 3 Nov): after signing in only Content is open in the left menu – whatever this browser remembered from
// before; within the sign-in the person's own folding is kept; signing out and in starts again; a phone follows the same rule
await step('3.29.1 menu: after a sign-in only Content is open; opening Appearance lasts while signed in; a new sign-in starts again (1440 and 390 px)', async () => {
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'en-GB' });
  const openGroups = (p) => p.locator('details[data-menu-skupina]').evaluateAll((all) => all.filter((d) => d.open).map((d) => d.getAttribute('data-menu-skupina')));
  const signIn = async (p) => {
    await p.goto(BASE + '/admin.php', { waitUntil: 'networkidle' });
    await p.fill('input[name="user"]', 'admin');
    await p.fill('input[name="password"]', PASSWORD);
    await Promise.all([p.waitForNavigation(), p.press('input[name="password"]', 'Enter')]);
  };
  try {
    const p = await ctx.newPage(); watch(p);
    await p.goto(BASE + '/admin.php', { waitUntil: 'networkidle' });
    // an earlier sign-in of this browser left every group open
    await p.evaluate(() => { localStorage.setItem('kaleta-menu', JSON.stringify({ _signin: '1', content: 1, workspace: 1, appearance: 1, claude: 1, 'site-care': 1, administration: 1 })); });
    await signIn(p);
    let open = await openGroups(p);
    if (JSON.stringify(open) !== JSON.stringify(['content'])) { throw new Error(`open after signing in: ${open.join(', ')}`); }
    if (SHOTS) { await p.screenshot({ path: `${SHOTS}/3291-menu-after-sign-in.png` }); }
    await p.locator('details[data-menu-skupina="appearance"] > summary').click();
    await p.goto(BASE + '/admin.php?module=pages', { waitUntil: 'networkidle' });
    open = await openGroups(p);
    if (JSON.stringify(open) !== JSON.stringify(['content', 'appearance'])) { throw new Error(`open on the next screen: ${open.join(', ')}`); }
    await p.goto(BASE + '/admin.php?module=stats', { waitUntil: 'networkidle' });
    open = await openGroups(p);
    if (JSON.stringify(open) !== JSON.stringify(['content', 'appearance', 'site-care'])) { throw new Error(`the current group is not open as well: ${open.join(', ')}`); }
    await p.waitForTimeout(1100); // the next sign-in starts in another second
    await Promise.all([p.waitForNavigation(), p.click('.menu-logout button')]);
    await signIn(p);
    open = await openGroups(p);
    if (JSON.stringify(open) !== JSON.stringify(['content'])) { throw new Error(`open after signing in again: ${open.join(', ')}`); }
    // a phone: the same rule in the menu sheet
    await p.setViewportSize({ width: 390, height: 844 });
    await p.goto(BASE + '/admin.php?module=bookings', { waitUntil: 'networkidle' });
    await p.click('.menu-prepinac');
    open = await openGroups(p);
    if (JSON.stringify(open) !== JSON.stringify(['content', 'workspace'])) { throw new Error(`open on a phone: ${open.join(', ')}`); }
  } finally {
    await ctx.close();
  }
});

// 3.25 (N-U9): one link picker everywhere – the builder's Button link and the menu editor's custom link use the same search
// as the Text link dialog: pages as you type (hidden ones marked), mailto: for an e-mail, tel: for a phone, arrows + Enter
await step('3.25 (N-U9) one link picker: the builder\'s Button link and the menu editor\'s custom link – pages, hidden marker, mailto:, tel:, keyboard', async () => {
  await page.setViewportSize({ width: 1440, height: 900 });
  const before = sql('SELECT IFNULL(HEX(stavba_koncept), \'NULL\') FROM ka_stranky WHERE ids = 1').trim(); // the draft as it was, restored at the end
  try {
    await visit('/admin.php?module=pages&action=builder&id=1');
    await page.waitForTimeout(1500);
    await canvas().locator('.ka-tlacitko').first().click();
    const field = page.locator('.st-panel input[data-odkaz-vyber]').first();
    await field.waitFor({ timeout: 5000 });
    if (await page.locator('#st-dl-odkazy').count() !== 0 || await field.getAttribute('list') !== null) { throw new Error('the Button link still uses the old datalist'); }
    await field.click();
    await field.fill('');
    await field.pressSequentially('Servi');
    const list = page.locator('.odkaz-plovouci:popover-open');
    await list.locator('[role=option]', { hasText: 'Services' }).first().waitFor({ timeout: 5000 });
    const box = await list.boundingBox();
    if (!box || box.x < 0 || box.x + box.width > 1440 || box.y < (await field.boundingBox()).y) { throw new Error(`the suggestions are not under the field: ${JSON.stringify(box)}`); }
    if (SHOTS) { await page.screenshot({ path: `${SHOTS}/link-picker-button.png` }); }
    await page.keyboard.press('ArrowDown');
    if (!(await field.getAttribute('aria-activedescendant')) || (await field.getAttribute('aria-expanded')) !== 'true') { throw new Error('ArrowDown did not mark a suggestion'); }
    await page.keyboard.press('Enter');
    if (!/\/services\/?$/.test(await field.inputValue()) || (await field.getAttribute('aria-expanded')) !== 'false') { throw new Error(`Enter picked ${await field.inputValue()}`); }
    await page.waitForFunction(() => !/Unsaved/.test(document.body.textContent || ''), null, { timeout: 10000 }).catch(() => {});
    await page.waitForTimeout(1500);
    if (!sql('SELECT stavba_koncept FROM ka_stranky WHERE ids = 1').includes('/services')) { throw new Error('the picked link was not saved to the draft'); }
    await field.fill('Priva');
    await list.locator('[role=option]', { hasText: 'Privacy policy' }).first().waitFor({ timeout: 5000 });
    if (!/hidden/.test(await list.locator('[role=option]', { hasText: 'Privacy policy' }).first().textContent())) { throw new Error('the hidden page is not marked'); }
    await field.fill('info@example.com');
    await page.locator('.odkaz-plovouci [role=option][data-url="mailto:info@example.com"]').waitFor({ timeout: 3000 });
    await field.fill('+420 777 123 456');
    await page.locator('.odkaz-plovouci [role=option][data-url="tel:+420777123456"]').waitFor({ timeout: 3000 });
    await page.keyboard.press('Escape');
    if ((await field.getAttribute('aria-expanded')) !== 'false' || await page.locator('.st-panel input[data-odkaz-vyber]').count() < 1) { throw new Error('the first Esc did more than close the list'); }
    // the menu editor: a custom link finds a page the same way (not saved)
    await visit('/admin.php?module=menu');
    await page.locator('[data-menu-pridej="odkaz"]').click();
    const menuField = page.locator('form[data-menu] input[data-odkaz-vyber]').last();
    await menuField.click();
    await menuField.pressSequentially('Conta');
    await page.locator('.odkaz-plovouci:popover-open [role=option]', { hasText: 'Contact' }).first().waitFor({ timeout: 5000 });
    if (SHOTS) { await page.screenshot({ path: `${SHOTS}/link-picker-menu.png` }); }
    await page.keyboard.press('ArrowDown');
    await page.keyboard.press('Enter');
    if (!/\/contact\/?$/.test(await menuField.inputValue())) { throw new Error(`the menu editor picked ${await menuField.inputValue()}`); }
  } finally {
    sql(`UPDATE ka_stranky SET stavba_koncept = ${before === 'NULL' || !/^[0-9A-F]*$/.test(before) ? 'NULL' : `CONVERT(UNHEX('${before}') USING utf8mb4)`} WHERE ids = 1`);
  }
});
// 3.26 (A): the team's thread in the page settings – "@" lists the colleagues who may open Pages (arrows and Enter insert the
// sign-in name), the comment shows the name, Edit in place, Delete through the confirmation and a placeholder line; the mention
// tinted in light and dark mode, no sideways scroll at 390 px; the builder's Team tab next to the preview-link comments
await step('3.26 comments: page thread with an @mention, edit, delete; the builder Team tab (1440 and 390 px, light/dark)', async () => {
  if (!MYSQL.length) { throw new Error('MYSQL_JSON is not set'); }
  sql(`DELETE FROM ka_stranky WHERE seo_link = 'comments-326'; INSERT INTO ka_stranky (seo_link, titulek, text, v_menu, zobrazit) VALUES ('comments-326', 'Comments 326', '<p>Text</p>', 0, 0);
    DELETE FROM ka_uzivatele WHERE user = 'jana-326'; INSERT INTO ka_uzivatele (user, password, jmeno, email, admin, posledni_login, potvrzeno) VALUES ('jana-326', '', 'Jana Nowak', '', 2, NOW(), NOW())`);
  const id = sql("SELECT ids FROM ka_stranky WHERE seo_link = 'comments-326'").trim();
  try {
    await page.setViewportSize({ width: 1440, height: 900 });
    await visit(`/admin.php?module=pages&action=edit&id=${id}`);
    const field = page.locator('#novy-komentar');
    await field.click();
    await field.pressSequentially('Please check this, @now');
    const option = page.locator('.zminky-navrhy [role=option]', { hasText: 'Jana Nowak' });
    await option.waitFor({ timeout: 3000 });
    if ((await field.getAttribute('aria-activedescendant')) !== await option.getAttribute('id')) { throw new Error('the first match is not the active option'); }
    if (SHOTS) { await page.screenshot({ path: `${SHOTS}/326-mention-list.png` }); }
    await page.keyboard.press('Enter');
    if ((await field.inputValue()) !== 'Please check this, @jana-326 ') { throw new Error(`Enter inserted “${await field.inputValue()}”`); }
    if (await page.locator('.zminky-navrhy [role=option]').count()) { throw new Error('the list stays open after the pick'); }
    await field.pressSequentially('thanks.');
    await Promise.all([page.waitForNavigation(), page.locator('form[data-comment-form] button[type="submit"]').click()]);
    await page.locator('.hlaska', { hasText: 'The comment was added.' }).waitFor();
    const mention = page.locator('#comments .zminka').first();
    if ((await mention.textContent()) !== '@Jana Nowak') { throw new Error(`the mention reads “${await mention.textContent()}”`); }
    const light = await mention.evaluate((n) => getComputedStyle(n).backgroundColor);
    await page.emulateMedia({ colorScheme: 'dark' });
    const dark = await mention.evaluate((n) => [getComputedStyle(n).backgroundColor, getComputedStyle(n).color]);
    if (dark[0] === light || dark[0] === dark[1]) { throw new Error(`the mention is not tinted for dark mode (${dark.join(' on ')})`); }
    if (SHOTS) { await page.locator('#comments').scrollIntoViewIfNeeded(); await page.screenshot({ path: `${SHOTS}/326-page-thread-dark.png` }); }
    await page.emulateMedia({ colorScheme: 'light' });
    // edit in place
    await page.locator('#comments .komentar-upravit summary').first().click();
    await page.locator('#comments form[data-comment-edit] textarea').first().fill('Prices checked, @jana-326.');
    await Promise.all([page.waitForNavigation(), page.locator('#comments form[data-comment-edit] button[type="submit"]').first().click()]);
    await page.locator('.hlaska', { hasText: 'The comment was saved.' }).waitFor();
    if (!/Prices checked, @Jana Nowak\./.test(await page.locator('#comments .vlakno-komentar').first().textContent()) || !(await page.locator('#comments .vlakno-komentar', { hasText: 'edited' }).count())) { throw new Error('the edit is not shown with its mark'); }
    // a phone: the thread and the composer fit
    await page.setViewportSize({ width: 390, height: 844 });
    await visit(`/admin.php?module=pages&action=edit&id=${id}`);
    const sideways = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    if (sideways > 0) { throw new Error(`${sideways} px of sideways scroll at 390 px`); }
    if (SHOTS) { await page.locator('#comments').scrollIntoViewIfNeeded(); await page.screenshot({ path: `${SHOTS}/326-page-thread-phone.png` }); }
    await page.setViewportSize({ width: 1440, height: 900 });
    await visit(`/admin.php?module=pages&action=edit&id=${id}`);
    // delete: the confirmation, then a quiet line
    await page.locator('#comments form[data-comment-delete] button').first().click();
    await page.locator('dialog.potvrzeni[open]').waitFor();
    await Promise.all([page.waitForNavigation(), page.locator('dialog.potvrzeni [data-ano]').click()]);
    if ((await page.locator('#comments [data-comment-deleted]').count()) !== 1 || (await page.locator('#comments .zminka').count()) !== 0) { throw new Error('the deleted comment is not a placeholder'); }
    // the builder: Comments in the ⋯ menu, the Team tab with the thread and a composer with the same "@" list
    await visit(`/admin.php?module=pages&action=builder&id=${id}`);
    await builderIdle();
    await page.locator('.st-lista .st-lista-vice').click();
    await page.locator('#st-lista-vice button', { hasText: 'Comments' }).click();
    const dialog = page.locator('dialog.st-komentare-dialog[open]');
    await dialog.waitFor();
    if ((await dialog.locator('[role=tab][aria-selected="true"]').textContent()) !== 'Team') { throw new Error('the Team tab is not the open one without preview-link comments'); }
    if ((await dialog.locator('.st-komentare-tym [data-tym-komentar]').count()) !== 1) { throw new Error('the Team tab does not list the deleted comment'); }
    const composer = dialog.locator('.st-tym-novy textarea');
    await composer.click();
    await composer.pressSequentially('From the builder @jan');
    await dialog.locator('.zminky-navrhy [role=option]', { hasText: 'Jana Nowak' }).waitFor({ timeout: 3000 });
    await page.keyboard.press('Enter');
    await dialog.locator('.st-tym .st-tl-hlavni', { hasText: 'Add comment' }).click();
    await dialog.locator('.st-komentare-tym .zminka', { hasText: '@Jana Nowak' }).waitFor({ timeout: 5000 });
    if ((await composer.inputValue()) !== '') { throw new Error('the composer keeps the sent text'); }
    if (SHOTS) { await page.screenshot({ path: `${SHOTS}/326-builder-team.png` }); }
    await page.emulateMedia({ colorScheme: 'dark' });
    if (SHOTS) { await page.screenshot({ path: `${SHOTS}/326-builder-team-dark.png` }); }
    await page.emulateMedia({ colorScheme: 'light' });
    await dialog.locator('[role=tab]', { hasText: 'Preview link' }).click();
    if (!(await dialog.locator('#st-komentare-host').isVisible()) || await dialog.locator('#st-komentare-tym').isVisible()) { throw new Error('the tabs do not switch'); }
    await dialog.locator('footer button', { hasText: 'Close' }).click();
  } finally {
    await page.emulateMedia({ colorScheme: 'light' });
    await page.setViewportSize({ width: 1440, height: 900 });
    sql(`DELETE FROM ka_comments WHERE target = 'page:${Number(id)}'; DELETE FROM ka_stranky WHERE ids = ${Number(id)}; DELETE FROM ka_uzivatele WHERE user = 'jana-326'`);
  }
});
// 3.26 (agent B): notifications – the bell with the unread count in the header bar (never in the left menu), the
// Notifications screen (who, what, where, when; the unread marked), opening one marks it read and leads to its place,
// Mark all as read empties the bell; My account offers the e-mail choice. 1440 and 390 px, light and dark.
await step('3.26 notifications: the bell, the Notifications screen, open and mark all read, the e-mail choice (1440 and 390 px, light and dark)', async () => {
  if (!MYSQL.length) { throw new Error('MYSQL_JSON is not set'); }
  await page.setViewportSize({ width: 1440, height: 900 });
  const admin = Number(sql("SELECT idu FROM ka_uzivatele WHERE user = 'admin'").trim());
  const pageId = Number(sql("INSERT INTO ka_stranky (seo_link, titulek, text, zobrazit, v_menu) VALUES ('bell-326', 'Price list 326', '', 0, 0); SELECT LAST_INSERT_ID();").trim());
  sql(`DELETE FROM ka_notifications WHERE user_id = ${admin};
    INSERT INTO ka_notifications (user_id, kind, target, actor_id, actor_name, excerpt, created_at) VALUES
    (${admin}, 'comment', 'enquiry:999999', NULL, 'Wendy Work', 'This one is gone', NOW() - INTERVAL 3 DAY),
    (${admin}, 'review_due', 'page:${pageId}', NULL, '', '', NOW() - INTERVAL 1 DAY),
    (${admin}, 'assigned', 'page:${pageId}', NULL, 'Wendy Work', '', NOW() - INTERVAL 2 HOUR),
    (${admin}, 'mention', 'page:${pageId}', NULL, 'Wendy Work', 'Please check the prices before Friday, the new supplier list is in the shared folder.', NOW() - INTERVAL 5 MINUTE)`); // newest last: the screen lists by id, newest first
  try {
    await visit('/admin.php');
    const bell = page.locator('.loginprouzek a.zvonek');
    if (!(await bell.isVisible())) { throw new Error('no bell in the header bar'); }
    if ((await bell.locator('.zvonek-pocet').textContent()).trim() !== '4') { throw new Error(`the bell counts ${await bell.locator('.zvonek-pocet').textContent()}`); }
    if ((await bell.getAttribute('aria-label')) !== 'Notifications – 4 unread') { throw new Error(`the bell is named ${await bell.getAttribute('aria-label')}`); }
    if (await page.locator('#menu .zvonek, #menu a[href*="action=inbox"]').count() !== 0) { throw new Error('the notifications are in the left menu'); }
    await Promise.all([page.waitForNavigation(), bell.click()]);
    if ((await page.locator('main h1').textContent()).trim() !== 'Notifications') { throw new Error('the bell does not open the Notifications screen'); }
    if ((await bell.getAttribute('aria-current')) !== 'page') { throw new Error('the bell is not marked as the current screen'); }
    const rows = page.locator('[data-inbox] > li');
    if (await rows.count() !== 4 || await page.locator('[data-inbox] .upozorneni-neprectene').count() !== 4) { throw new Error(`the screen lists ${await rows.count()} notifications`); }
    const first = await rows.first().innerText();
    if (!first.includes('Wendy Work mentioned you') || !first.includes('Page “Price list 326”') || !first.includes('Please check the prices')) { throw new Error(`the first row says ${first}`); }
    if (!(await rows.nth(1).innerText()).includes('Wendy Work assigned it to you') || !(await rows.nth(2).innerText()).includes('The review date has come')) { throw new Error('the assignment or the review row is wrong'); }
    if (!(await rows.nth(3).innerText()).includes('(no longer available)') || await rows.nth(3).locator('a').count() !== 0) { throw new Error('a place that is gone still has a link'); }
    if (SHOTS) { await page.screenshot({ path: `${SHOTS}/notifications-desktop.png`, fullPage: true }); }
    // light and dark, desktop and phone: no sideways scroll; on the phone the bell sits in the header row without covering the logo
    const state = await context.storageState();
    for (const scheme of ['light', 'dark']) {
      for (const [label, width, height] of [['desktop', 1440, 900], ['phone', 390, 844]]) {
        const other = await browser.newContext({ viewport: { width, height }, locale: 'en-GB', colorScheme: scheme, storageState: state, ...(width < 500 ? { isMobile: true, hasTouch: true } : {}) });
        const p2 = await other.newPage(); watch(p2);
        try {
          await p2.goto(`${BASE}/admin.php?action=inbox`, { waitUntil: 'networkidle' });
          const wide = await p2.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
          if (wide > 0) { throw new Error(`${wide} px of sideways scroll (${label}, ${scheme})`); }
          const box = await p2.locator('.loginprouzek a.zvonek').boundingBox();
          if (!box || box.x < 0 || box.x + box.width > width || box.y > 64) { throw new Error(`the bell is not in the header row (${label}, ${scheme}): ${JSON.stringify(box)}`); }
          if (width < 500) {
            const logo = await p2.locator('.hlavicka .kaleta-logo').boundingBox();
            const firstButton = await p2.locator('.loginprouzek > :first-child').boundingBox();
            if (!logo || !firstButton || logo.x + logo.width > firstButton.x) { throw new Error(`the header buttons cover the logo (${scheme}): ${JSON.stringify([logo, firstButton])}`); }
          }
          const colours = await p2.locator('.upozorneni-neprectene').first().evaluate((el) => [getComputedStyle(el).backgroundColor, getComputedStyle(el.querySelector('.upozorneni-co')).color]);
          if (colours[0] === colours[1]) { throw new Error(`the text has the colour of its card (${label}, ${scheme})`); }
          if (SHOTS) { await p2.screenshot({ path: `${SHOTS}/notifications-${label}-${scheme}.png`, fullPage: true }); }
        } finally {
          await other.close();
        }
      }
    }
    // opening one marks it read and leads to its place
    const mention = sql(`SELECT id FROM ka_notifications WHERE user_id = ${admin} AND kind = 'mention'`).trim();
    await Promise.all([page.waitForNavigation(), rows.first().locator('a').click()]);
    if (!page.url().includes(`module=pages&action=edit&id=${pageId}`)) { throw new Error(`the notification leads to ${page.url()}`); }
    if (sql(`SELECT read_at IS NOT NULL FROM ka_notifications WHERE id = ${Number(mention)}`).trim() !== '1') { throw new Error('opening did not mark it read'); }
    if ((await page.locator('.loginprouzek .zvonek-pocet').textContent()).trim() !== '3') { throw new Error('the bell did not count one less'); }
    await visit('/admin.php?action=inbox');
    await Promise.all([page.waitForNavigation(), page.locator('button[name="read_all"]').click()]);
    if (await page.locator('.loginprouzek .zvonek-pocet').count() !== 0 || await page.locator('[data-inbox] .upozorneni-neprectene').count() !== 0) { throw new Error('Mark all as read left something unread'); }
    if (await page.locator('button[name="read_all"]').count() !== 0) { throw new Error('Mark all as read is offered with nothing unread'); }
    if (!(await page.locator('.hlaska-ok', { hasText: 'All notifications are marked as read.' }).isVisible())) { throw new Error('no message after Mark all as read'); }
    // My account: the e-mail choice
    await visit('/admin.php?action=account#notifications');
    const choice = page.locator('#notifications');
    if (await choice.locator('input[name="notify_email"]').count() !== 3 || !(await choice.locator('input[value="instant"]').isChecked())) { throw new Error('My account does not offer the three choices with Right away chosen'); }
    await choice.locator('label.karta-volba', { hasText: 'Once a day' }).click();
    if (SHOTS) { await choice.screenshot({ path: `${SHOTS}/notifications-account.png` }); }
    await Promise.all([page.waitForNavigation(), choice.locator('input[type="submit"]').click()]);
    if (sql(`SELECT notify_email FROM ka_uzivatele WHERE idu = ${admin}`).trim() !== 'daily') { throw new Error('the choice was not saved'); }
    if (!(await page.locator('#notifications input[value="daily"]').isChecked())) { throw new Error('the saved choice is not shown'); }
  } finally {
    sql(`DELETE FROM ka_notifications WHERE user_id = ${admin}; DELETE FROM ka_stranky WHERE ids = ${pageId}; UPDATE ka_uzivatele SET notify_email = 'instant' WHERE idu = ${admin}`);
  }
});
// 3.27 (agent A): tasks in My work – the person's tasks come first, grouped overdue / today / upcoming / later, each row with
// Done, Tomorrow and In 3 days; Done and Tomorrow work; the administrator has the Team tab, whose "Not assigned" lists a task
// handed to nobody (the nobody row links there) with Reassign to… and Save on screen; reassigning it works. 1440 and 390 px,
// light and dark.
await step('3.27 My work: the tasks first by group, Done and snooze, the Team tab with Not assigned and Reassign (1440 and 390 px, light and dark)', async () => {
  if (!MYSQL.length) { throw new Error('MYSQL_JSON is not set'); }
  await page.setViewportSize({ width: 1440, height: 900 });
  const admin = Number(sql("SELECT idu FROM ka_uzivatele WHERE user = 'admin'").trim());
  const pageId = Number(sql("INSERT INTO ka_stranky (seo_link, titulek, text, zobrazit, v_menu) VALUES ('tasks-327', 'Price list 327', '', 0, 0); SELECT LAST_INSERT_ID();").trim());
  const day = (offset) => `CONCAT(CURDATE() + INTERVAL ${offset} DAY, ' 09:00:00')`;
  sql(`DELETE FROM ka_tasks WHERE assignee_id = ${admin};
    INSERT INTO ka_tasks (title, target, assignee_id, created_by, due_at, created_at, updated_at) VALUES
    ('Call the supplier back about the delayed delivery of the oak boards', NULL, ${admin}, ${admin}, ${day(-2)}, NOW(), NOW()),
    ('Review the price list before the newsletter goes out', 'page:${pageId}', ${admin}, ${admin}, CONCAT(CURDATE(), ' 23:30:00'), NOW(), NOW()),
    ('Send the offer', NULL, ${admin}, ${admin}, ${day(3)}, NOW(), NOW()),
    ('Plan the spring campaign', NULL, ${admin}, ${admin}, NULL, NOW(), NOW()),
    ('Order the trade fair banner left by a colleague who has gone', NULL, NULL, ${admin}, ${day(1)}, NOW(), NOW())`);
  try {
    await visit('/admin.php?module=my_work');
    const first = page.locator('main section.moje-prace').first();
    if ((await first.getAttribute('data-list')) !== 'tasks') { throw new Error('the tasks are not the first list of My work'); }
    const groups = await page.locator('[data-list="tasks"] h3[data-group]').evaluateAll((els) => els.map((el) => el.dataset.group));
    if (groups.join(',') !== 'overdue,today,upcoming,later') { throw new Error(`the groups are ${groups.join(',')}`); }
    const today = page.locator('[data-list="tasks"] tr[data-task]', { hasText: 'Review the price list' });
    if (!(await today.locator('a', { hasText: 'Page “Price list 327”' }).isVisible())) { throw new Error('the task does not link to its page'); }
    if (await page.locator('[data-list="tasks"] tr[data-task] button').count() !== 12) { throw new Error('not every task has Done, Tomorrow and In 3 days'); }
    if (await page.locator('.zalozky a', { hasText: 'Team' }).count() !== 1) { throw new Error('no Team tab for the administrator'); }
    if (SHOTS) { await page.screenshot({ path: `${SHOTS}/my-work-tasks-desktop.png`, fullPage: true }); }
    const state = await context.storageState();
    for (const scheme of ['light', 'dark']) {
      for (const [label, width, height] of [['desktop', 1440, 900], ['phone', 390, 844]]) {
        const other = await browser.newContext({ viewport: { width, height }, locale: 'en-GB', colorScheme: scheme, storageState: state, ...(width < 500 ? { isMobile: true, hasTouch: true } : {}) });
        const p2 = await other.newPage(); watch(p2);
        try {
          for (const url of ['/admin.php?module=my_work', '/admin.php?module=my_work&action=team']) {
            await p2.goto(`${BASE}${url}`, { waitUntil: 'networkidle' });
            const wide = await p2.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
            if (wide > 0) { throw new Error(`${wide} px of sideways scroll (${url}, ${label}, ${scheme})`); }
          }
          // the Team tab (the last URL above): the task handed to nobody under Not assigned, its person list and Save on screen
          const orphan = p2.locator('[data-list="unassigned"] tr[data-task]', { hasText: 'Order the trade fair banner' });
          if (!(await orphan.isVisible()) || await p2.locator('[data-list="team"] a[href="#unassigned"]').count() < 1) { throw new Error(`Not assigned or its link is missing (${label}, ${scheme})`); }
          for (const control of [orphan.locator('select[name="assignee_id"]'), orphan.getByRole('button', { name: 'Save', exact: true })]) {
            const box = await control.boundingBox();
            if (!box || box.x < 0 || box.x + box.width > width) { throw new Error(`Reassign to… is off the screen (${label}, ${scheme}): ${JSON.stringify(box)}`); }
          }
          if (SHOTS) { await p2.screenshot({ path: `${SHOTS}/my-work-team-unassigned-${label}-${scheme}.png`, fullPage: true }); }
          await p2.goto(`${BASE}/admin.php?module=my_work`, { waitUntil: 'networkidle' });
          for (const name of ['Done', 'Tomorrow', 'In 3 days']) {
            const box = await p2.locator('[data-list="tasks"] tr[data-task]').first().getByRole('button', { name, exact: true }).boundingBox();
            if (!box || box.x < 0 || box.x + box.width > width) { throw new Error(`the ${name} button is off the screen (${label}, ${scheme}): ${JSON.stringify(box)}`); }
          }
          const colours = await p2.locator('[data-list="tasks"] h3').first().evaluate((el) => [getComputedStyle(document.body).backgroundColor, getComputedStyle(el).color]);
          if (colours[0] === colours[1]) { throw new Error(`the group heading has the colour of the page (${label}, ${scheme})`); }
          if (SHOTS) { await p2.screenshot({ path: `${SHOTS}/my-work-tasks-${label}-${scheme}.png`, fullPage: true }); }
        } finally {
          await other.close();
        }
      }
    }
    // Done on the overdue one, Tomorrow on today's
    const overdue = page.locator('[data-list="tasks"] tr[data-task]', { hasText: 'Call the supplier back' });
    await Promise.all([page.waitForNavigation(), overdue.getByRole('button', { name: 'Done', exact: true }).click()]);
    if (!(await page.locator('.hlaska-ok', { hasText: 'Task done: Call the supplier back' }).isVisible())) { throw new Error('no message after Done'); }
    if (await page.locator('[data-list="tasks"] h3[data-group="overdue"]').count() !== 0) { throw new Error('the done task is still listed'); }
    await Promise.all([page.waitForNavigation(), page.locator('[data-list="tasks"] tr[data-task]', { hasText: 'Review the price list' }).getByRole('button', { name: 'Tomorrow', exact: true }).click()]);
    if (!(await page.locator('.hlaska-ok', { hasText: 'Task moved to' }).isVisible())) { throw new Error('no message after Tomorrow'); }
    if (sql(`SELECT DATE(due_at) = CURDATE() + INTERVAL 1 DAY AND TIME(due_at) = '23:30:00' FROM ka_tasks WHERE title LIKE 'Review the price list%'`).trim() !== '1') { throw new Error('Tomorrow did not move the due day'); }
    await Promise.all([page.waitForNavigation(), page.locator('.zalozky a', { hasText: 'Team' }).click()]);
    if (await page.locator('[data-list="team"] tbody tr').count() < 1 || (await page.locator('.zalozky a.aktivni').textContent()).trim() !== 'Team') { throw new Error('the Team tab lists nobody'); }
    if (SHOTS) { await page.screenshot({ path: `${SHOTS}/my-work-team.png`, fullPage: true }); }
    // the nobody row jumps to Not assigned; Reassign to… me takes the task
    await page.locator('[data-list="team"] a[href="#unassigned"]', { hasText: 'nobody' }).click();
    if (!(await page.evaluate(() => location.hash === '#unassigned'))) { throw new Error('the nobody row does not link to Not assigned'); }
    const orphan = page.locator('[data-list="unassigned"] tr[data-task]', { hasText: 'Order the trade fair banner' });
    await orphan.locator('select[name="assignee_id"]').selectOption(String(admin));
    await Promise.all([page.waitForNavigation(), orphan.getByRole('button', { name: 'Save', exact: true }).click()]);
    if (!(await page.locator('.hlaska-ok', { hasText: 'The task is yours now.' }).isVisible())) { throw new Error('no message after Reassign'); }
    if (sql("SELECT IFNULL(assignee_id, 'null') FROM ka_tasks WHERE title LIKE 'Order the trade fair banner%'").trim() !== String(admin)) { throw new Error('Reassign did not give the task'); }
    if (await page.locator('[data-list="unassigned"] tr[data-task]', { hasText: 'Order the trade fair banner' }).count() !== 0) { throw new Error('the reassigned task is still under Not assigned'); }
  } finally {
    sql(`DELETE FROM ka_tasks WHERE assignee_id = ${admin} OR title LIKE 'Order the trade fair banner%'; DELETE FROM ka_stranky WHERE ids = ${pageId}`);
  }
});
// 3.27 (agent B): the tasks of a record – the follow-up buttons of an enquiry, Mark done and Reopen, Snooze, Edit in place,
// Hand to Claude through the confirmation (a link to the request), the page's task for its review date, "Add a task" for a
// colleague with a quick day, Delete through the confirmation; 1440 and 390 px, light and dark, no sideways scroll
await step('3.27 tasks: enquiry follow-up, done, snooze, edit, hand to Claude; the page review task, add for a colleague, delete; New task in My work (1440 and 390 px, light/dark)', async () => {
  if (!MYSQL.length) { throw new Error('MYSQL_JSON is not set'); }
  const enquiry = Number(sql(`INSERT INTO ka_poptavky (datum, formular, email, data, stav) VALUES (NOW(), 'Contact B327', 'b327@visitor.example', '[["Message","Secret message B327"]]', 1); SELECT LAST_INSERT_ID();`).trim());
  const pageId = Number(sql("INSERT INTO ka_stranky (seo_link, titulek, text, zobrazit, v_menu, review_by) VALUES ('tasks-327', 'Price list 327', '<p>x</p>', 0, 0, CURDATE() + INTERVAL 12 DAY); SELECT LAST_INSERT_ID();").trim());
  sql(`DELETE FROM ka_uzivatele WHERE user = 'jana-327'; INSERT INTO ka_uzivatele (user, password, jmeno, email, admin, posledni_login, potvrzeno) VALUES ('jana-327', '', 'Jana Tasks', '', 1, NOW(), NOW());
    INSERT INTO ka_uzivatele_prava (fk_id_user, ident_modulu) SELECT idu, 'pages' FROM ka_uzivatele WHERE user = 'jana-327'`);
  const jana = Number(sql("SELECT idu FROM ka_uzivatele WHERE user = 'jana-327'").trim());
  const submit = async (locator) => { await Promise.all([page.waitForNavigation(), locator.click()]); };
  const confirmed = async (locator) => { await locator.click(); await page.locator('dialog.potvrzeni[open]').waitFor(); await submit(page.locator('dialog.potvrzeni [data-ano]')); };
  try {
    await page.setViewportSize({ width: 1440, height: 900 });
    await visit(`/admin.php?module=enquiries&action=detail&id=${enquiry}`);
    const panel = page.locator('#tasks');
    await submit(panel.locator('form[data-task-follow-up] button', { hasText: 'In 3 days' }));
    await page.locator('.hlaska-ok', { hasText: 'is in My work.' }).waitFor();
    const row = panel.locator('[data-tasks-open] .ukol').first();
    if ((await row.locator('.ukol-nazev').innerText()) !== 'Follow up: Contact B327') { throw new Error(`the follow-up is titled “${await row.locator('.ukol-nazev').innerText()}”`); }
    if (!(await row.locator('.ukol-meta').innerText()).startsWith('For me · due ')) { throw new Error(`the follow-up says “${await row.locator('.ukol-meta').innerText()}”`); }
    if (SHOTS) { await panel.scrollIntoViewIfNeeded(); await page.screenshot({ path: `${SHOTS}/327-enquiry-tasks.png` }); }
    // done, then open again from the folded Done list
    await submit(row.locator('button.ukol-hotovo'));
    await page.locator('.hlaska-ok', { hasText: 'The task is done.' }).waitFor();
    await panel.locator('.ukoly-hotove summary').click();
    if (!(await panel.locator('[data-tasks-done] .ukol').count())) { throw new Error('the done task is not under Done'); }
    await submit(panel.locator('[data-tasks-done] button', { hasText: 'Reopen' }));
    // snooze to tomorrow, then edit in place
    await submit(panel.locator('[data-tasks-open] form[data-task-snooze] button', { hasText: 'Tomorrow' }));
    await page.locator('.hlaska-ok', { hasText: 'The task comes back on' }).waitFor();
    await panel.locator('.ukol-upravit summary').first().click();
    await panel.locator('form[data-task-edit] input[name="title"]').fill('Call Mrs Novak back');
    await panel.locator('form[data-task-edit] input[name="due_time"]').fill('15:45');
    await submit(panel.locator('form[data-task-edit] button[type="submit"]'));
    const edited = await panel.locator('[data-tasks-open] .ukol').first().innerText();
    if (!edited.includes('Call Mrs Novak back') || !edited.includes('15:45')) { throw new Error(`the edit is not shown: ${edited}`); }
    // hand to Claude: the confirmation, then the task is done with a link to its request
    await confirmed(panel.locator('form[data-task-claude] button'));
    await page.locator('.hlaska-ok', { hasText: 'was handed to Claude as request #' }).waitFor();
    await panel.locator('.ukoly-hotove summary').click();
    const link = panel.locator('[data-task-request] a');
    if (!(await link.getAttribute('href')).includes('module=requests&action=detail&id=')) { throw new Error('the handed task has no link to its request'); }
    const requestText = sql(`SELECT text FROM ka_requests WHERE title = 'Call Mrs Novak back'`);
    if (!requestText.includes(`module=enquiries&action=detail&id=${enquiry}`) || requestText.includes('Secret message')) { throw new Error(`the request text is wrong: ${requestText}`); }
    // the page: one click for the review date, then a task for Jana tomorrow from the Add a task form
    await visit(`/admin.php?module=pages&action=edit&id=${pageId}`);
    await submit(panel.locator('form[data-task-review] button'));
    if (!(await panel.locator('[data-tasks-open] .ukol-nazev', { hasText: 'Review: Price list 327' }).count()) || await panel.locator('form[data-task-review]').count()) { throw new Error('the review task is not added or still offered'); }
    await panel.locator('.ukol-pridat > summary').click();
    const form = panel.locator('form[data-task-add]');
    await form.locator('input[name="title"]').fill('Update the prices');
    await form.locator('select[name="assignee_id"]').selectOption(String(jana));
    await submit(form.locator('.ukol-rychle button', { hasText: 'Tomorrow' }));
    await page.locator('.hlaska-ok', { hasText: 'The task was added for Jana Tasks' }).waitFor();
    const janaRow = panel.locator('[data-tasks-open] .ukol', { hasText: 'Update the prices' });
    if (!(await janaRow.locator('.ukol-meta').innerText()).startsWith('For Jana Tasks · due ')) { throw new Error(`Jana's task says ${await janaRow.locator('.ukol-meta').innerText()}`); }
    if (sql(`SELECT DATEDIFF(due_at, CURDATE()) FROM ka_tasks WHERE title = 'Update the prices'`).trim() !== '1') { throw new Error('the quick day is not tomorrow'); }
    // light and dark, desktop and phone: the panel fits, its text is readable
    const state = await context.storageState();
    for (const scheme of ['light', 'dark']) {
      for (const [label, width, height] of [['desktop', 1440, 900], ['phone', 390, 844]]) {
        const other = await browser.newContext({ viewport: { width, height }, locale: 'en-GB', colorScheme: scheme, storageState: state, ...(width < 500 ? { isMobile: true, hasTouch: true } : {}) });
        const p2 = await other.newPage(); watch(p2);
        try {
          await p2.goto(`${BASE}/admin.php?module=pages&action=edit&id=${pageId}`, { waitUntil: 'networkidle' });
          await p2.locator('#tasks .ukol-pridat').evaluate((d) => { d.open = true; });
          const wide = await p2.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
          if (wide > 0) { throw new Error(`${wide} px of sideways scroll (${label}, ${scheme})`); }
          const colours = await p2.locator('#tasks .ukol').first().evaluate((el) => [getComputedStyle(el).backgroundColor, getComputedStyle(el.querySelector('.ukol-nazev')).color]);
          if (colours[0] === colours[1]) { throw new Error(`the task text has the colour of its card (${label}, ${scheme})`); }
          if (SHOTS) { await p2.locator('#tasks').scrollIntoViewIfNeeded(); await p2.screenshot({ path: `${SHOTS}/327-page-tasks-${label}-${scheme}.png`, fullPage: true }); }
        } finally {
          await other.close();
        }
      }
    }
    // delete through the confirmation
    await confirmed(janaRow.locator('form[data-task-delete] button'));
    await page.locator('.hlaska-ok', { hasText: 'The task was deleted.' }).waitFor();
    if (await panel.locator('.ukol', { hasText: 'Update the prices' }).count()) { throw new Error('the deleted task is still listed'); }
    // My work: New task (the same form) – a task about nothing for today lands in the Today group
    await visit('/admin.php?module=my_work');
    await page.locator('[data-new-task] > summary').click();
    await page.locator('[data-new-task] input[name="title"]').fill('Order paper 327');
    await submit(page.locator('[data-new-task] .ukol-rychle button', { hasText: 'Today' }));
    await page.locator('.hlaska-ok', { hasText: 'The task was added.' }).waitFor();
    if (!(await page.locator('[data-list="tasks"] tr[data-task]', { hasText: 'Order paper 327' }).count())) { throw new Error('the new task is not in My work'); }
    if (sql("SELECT CONCAT(IFNULL(target, 'none'), '/', DATE(due_at) = CURDATE()) FROM ka_tasks WHERE title = 'Order paper 327'").trim() !== 'none/1') { throw new Error('the new task is not about nothing, due today'); }
  } finally {
    await page.setViewportSize({ width: 1440, height: 900 });
    sql(`DELETE FROM ka_notifications WHERE target LIKE 'task:%'; DELETE FROM ka_tasks WHERE target IN ('enquiry:${enquiry}', 'page:${pageId}') OR title = 'Order paper 327';
      DELETE FROM ka_request_messages WHERE request_id IN (SELECT id FROM ka_requests WHERE title = 'Call Mrs Novak back'); DELETE FROM ka_requests WHERE title = 'Call Mrs Novak back';
      DELETE FROM ka_poptavky WHERE idp = ${enquiry}; DELETE FROM ka_stranky WHERE ids = ${pageId}; DELETE FROM ka_uzivatele_prava WHERE fk_id_user = ${jana}; DELETE FROM ka_uzivatele WHERE idu = ${jana}`);
  }
});
// 3.28 (agent B): Customers – the list (cards on a phone), a customer's detail with the timeline, "Log a call / meeting" from
// the chips, the company suggestion, the merge screen side by side, the box on an enquiry and the palette search; 1440 and
// 390 px, light and dark, no sideways scroll
await step('3.28 customers: list, detail, log a meeting, company suggestion, merge screen, enquiry box, palette (1440 and 390 px, light/dark)', async () => {
  if (!MYSQL.length) { throw new Error('MYSQL_JSON is not set'); }
  sql("DELETE FROM ka_contacts WHERE email_norm LIKE '%b328%'; DELETE FROM ka_companies WHERE name LIKE '%B328%'");
  const eva = Number(sql(`INSERT INTO ka_contacts (email, email_norm, name, phone, language, source, summary, first_seen_at, last_activity_at, created_at, updated_at)
    VALUES ('eva.b328@northwind-b328.example', 'eva.b328@northwind-b328.example', 'Eva Longname-Doubleservice B328', '+44 20 7946 0328', 'en', 'enquiry', 'Prefers e-mail. Asked about the yearly service plan for all three offices.', NOW() - INTERVAL 20 DAY, NOW() - INTERVAL 2 DAY, NOW(), NOW()); SELECT LAST_INSERT_ID();`).trim());
  const other = Number(sql(`INSERT INTO ka_contacts (email, email_norm, name, phone, language, source, first_seen_at, last_activity_at, created_at, updated_at)
    VALUES ('eva.private.b328@gmail.com', 'eva.private.b328@gmail.com', 'Eva B328', '', '', 'subscriber', NOW() - INTERVAL 5 DAY, NOW() - INTERVAL 5 DAY, NOW(), NOW()); SELECT LAST_INSERT_ID();`).trim());
  const enquiry = Number(sql(`INSERT INTO ka_poptavky (datum, formular, email, data, stav, contact_id) VALUES (NOW() - INTERVAL 2 DAY, 'Contact B328', 'eva.b328@northwind-b328.example', '[["Message","Secret message B328"]]', 1, ${eva}); SELECT LAST_INSERT_ID();`).trim());
  const submit = async (locator) => { await Promise.all([page.waitForNavigation(), locator.click()]); };
  try {
    await page.setViewportSize({ width: 1440, height: 900 });
    await visit('/admin.php?module=contacts&search=B328');
    if ((await page.locator('[data-contacts-list] tr[data-contact]').count()) !== 2) { throw new Error('the search does not list both customers'); }
    await submit(page.locator(`tr[data-contact="${eva}"] td:first-child a`));
    if (!(await page.locator('h1').innerText()).includes('Eva Longname-Doubleservice B328')) { throw new Error('the detail is not titled after the customer'); }
    if ((await page.locator('[data-timeline-entry="enquiry"]').count()) !== 1) { throw new Error('the enquiry is not on the timeline'); }
    // the company of the e-mail domain is suggested; one click creates it and adds her
    await submit(page.locator('form[data-company-suggestion] button'));
    await page.locator('.hlaska-ok', { hasText: 'The contact was saved.' }).waitFor();
    if (!(await page.locator('[data-contact-card] dd a', { hasText: 'Northwind' }).count())) { throw new Error('the suggested company was not added'); }
    // log a meeting from the chips
    await page.locator('.zakaznik-druh label', { hasText: 'Meeting' }).click();
    await page.locator('#zapis-text').fill('Met at the trade fair – sends the floor plans by Friday.');
    await submit(page.locator('form[data-activity-form] button[type="submit"]'));
    await page.locator('.hlaska-ok', { hasText: 'Logged.' }).waitFor();
    const first = page.locator('[data-timeline-list] [data-timeline-entry]').first();
    if ((await first.getAttribute('data-timeline-entry')) !== 'meeting' || !(await first.innerText()).includes('floor plans')) { throw new Error('the meeting is not first on the timeline'); }
    if (SHOTS) { await page.screenshot({ path: `${SHOTS}/328-contact-detail.png`, fullPage: true }); }
    // the merge screen: both side by side
    await visit(`/admin.php?module=contacts&action=merge&id=${eva}&with=${other}`);
    const sides = await page.locator('[data-merge-side]').evaluateAll((els) => els.map((el) => el.getBoundingClientRect().top));
    if (sides.length !== 2 || Math.abs(sides[0] - sides[1]) > 2) { throw new Error(`the two contacts are not side by side at 1440 px: ${JSON.stringify(sides)}`); }
    if (SHOTS) { await page.screenshot({ path: `${SHOTS}/328-merge.png` }); }
    // the box on the enquiry
    await visit(`/admin.php?module=enquiries&action=detail&id=${enquiry}`);
    const box = page.locator(`[data-contact-box="${eva}"]`);
    if (!(await box.innerText()).includes('Eva Longname-Doubleservice B328')) { throw new Error('the enquiry does not show its customer'); }
    // the palette finds her by the company
    await page.keyboard.press('Control+k');
    await page.locator('#paleta .paleta-pole').fill('Northwind');
    await page.locator('#paleta .paleta-seznam a', { hasText: 'Eva Longname-Doubleservice B328' }).waitFor({ timeout: 5000 });
    await page.keyboard.press('Escape');
    // light and dark, desktop and phone: no sideways scroll, readable text; the list becomes cards on a phone
    const state = await context.storageState();
    for (const scheme of ['light', 'dark']) {
      for (const [label, width, height] of [['desktop', 1440, 900], ['phone', 390, 844]]) {
        const other2 = await browser.newContext({ viewport: { width, height }, locale: 'en-GB', colorScheme: scheme, storageState: state, ...(width < 500 ? { isMobile: true, hasTouch: true } : {}) });
        const p2 = await other2.newPage(); watch(p2);
        try {
          for (const url of ['/admin.php?module=contacts&search=B328', `/admin.php?module=contacts&action=detail&id=${eva}`, `/admin.php?module=contacts&action=merge&id=${eva}&with=${other}`]) {
            await p2.goto(`${BASE}${url}`, { waitUntil: 'networkidle' });
            const wide = await p2.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
            if (wide > 0) { throw new Error(`${wide} px of sideways scroll (${url}, ${label}, ${scheme})`); }
            if (url.includes('search=B328')) {
              const display = await p2.locator('[data-contacts-list] tbody tr').first().evaluate((el) => getComputedStyle(el).display);
              if ((label === 'phone') !== (display === 'block')) { throw new Error(`the list rows are ${display} on the ${label}`); }
            }
            if (url.includes('action=detail')) {
              const colours = await p2.locator('.zakaznik-karta').evaluate((el) => [getComputedStyle(el).backgroundColor, getComputedStyle(el.querySelector('dd')).color]);
              if (colours[0] === colours[1]) { throw new Error(`the card text has the colour of the card (${label}, ${scheme})`); }
            }
            if (SHOTS) { await p2.screenshot({ path: `${SHOTS}/328-${url.includes('merge') ? 'merge' : url.includes('detail') ? 'detail' : 'list'}-${label}-${scheme}.png`, fullPage: true }); }
          }
        } finally {
          await other2.close();
        }
      }
    }
  } finally {
    await page.setViewportSize({ width: 1440, height: 900 });
    sql(`DELETE FROM ka_contact_activities WHERE contact_id IN (${eva}, ${other}); DELETE FROM ka_poptavky WHERE idp = ${enquiry};
      DELETE FROM ka_contacts WHERE id IN (${eva}, ${other}); DELETE FROM ka_companies WHERE domain = 'northwind-b328.example'`);
  }
});

// 3.28 (D-W12): off-site backups are encrypted at rest – until the recovery key is saved the copies wait and the Backups tab
// says so plainly; the key is shown on a page of its own (readable at 1440 and 390 px, light and dark), confirmed with a tick,
// and never shown again
await step('3.28 Backups: the recovery key of the encrypted off-site copies – paused until saved, shown once, confirmed (1440 and 390 px, light/dark)', async () => {
  const keyFile = `${process.env.WEB_DIR}/storage/backup-key.php`;
  sql(`REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('remote_backup', 's3'), ('backup_host', 's3.example.com'), ('backup_folder', 'kaleta-zalohy'), ('backup_key_confirmed', '')`);
  const submit = async (locator) => { await Promise.all([page.waitForNavigation(), locator.click()]); };
  try {
    await visit('/admin.php?module=settings&tab=backups');
    const state = page.locator('#recovery-key-state');
    if (!(await state.evaluate((el) => el.classList.contains('hlaska-chyba'))) || !(await state.innerText()).includes('Off-site copies are paused')) { throw new Error(`the Backups tab does not say the copies wait: ${await state.innerText()}`); }
    await submit(state.locator('button', { hasText: 'Show and save the recovery key' }));
    const shown = (await page.locator('#recovery-key').innerText()).trim();
    if (!/^KBK1(-[A-Z2-7]{4}){13}$/.test(shown)) { throw new Error(`the recovery key looks wrong: ${shown}`); }
    if (!(await page.locator('button[data-kopirovat="#recovery-key"]').count()) || !(await page.locator('form[action*="backup_key_download"] button').count())) { throw new Error('no Copy or Download for the key'); }
    // light and dark, desktop and phone: the key page fits without sideways scrolling and the key is readable
    const stored = await context.storageState();
    for (const scheme of ['light', 'dark']) {
      for (const [label, width, height] of [['desktop', 1440, 900], ['phone', 390, 844]]) {
        const other = await browser.newContext({ viewport: { width, height }, locale: 'en-GB', colorScheme: scheme, storageState: stored, ...(width < 500 ? { isMobile: true, hasTouch: true } : {}) });
        const p2 = await other.newPage(); watch(p2);
        try {
          await p2.goto(`${BASE}/admin.php?module=settings&tab=backups`, { waitUntil: 'networkidle' });
          await Promise.all([p2.waitForNavigation(), p2.locator('#recovery-key-state button', { hasText: 'Show and save the recovery key' }).click()]);
          if ((await p2.locator('#recovery-key').innerText()).trim() !== shown) { throw new Error(`another key was shown (${label}, ${scheme})`); }
          const wide = await p2.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
          if (wide > 0) { throw new Error(`${wide} px of sideways scroll (${label}, ${scheme})`); }
          const colours = await p2.locator('#recovery-key').evaluate((el) => [getComputedStyle(el.closest('.hlaska')).backgroundColor, getComputedStyle(el).color]);
          if (colours[0] === colours[1]) { throw new Error(`the key has the colour of its box (${label}, ${scheme})`); }
          if (SHOTS) { await p2.screenshot({ path: `${SHOTS}/328-recovery-key-${label}-${scheme}.png`, fullPage: true }); }
        } finally {
          await other.close();
        }
      }
    }
    // the tick is required, then the copies start and the key is not offered again
    await page.locator('form[action*="backup_key_confirm"] input[name="saved"]').check();
    await submit(page.locator('form[action*="backup_key_confirm"] button[type="submit"]'));
    await page.locator('.hlaska-ok', { hasText: 'The recovery key is saved.' }).waitFor();
    const after = page.locator('#recovery-key-state');
    if (!(await after.evaluate((el) => el.classList.contains('hlaska-ok'))) || !(await after.innerText()).includes('Recovery key saved') || await after.locator('button', { hasText: 'Show and save the recovery key' }).count()) { throw new Error(`the confirmed state is wrong: ${await after.innerText()}`); }
    if ((await page.content()).includes(shown)) { throw new Error('the key is shown again after the confirmation'); }
    if (SHOTS) { await after.scrollIntoViewIfNeeded(); await page.screenshot({ path: `${SHOTS}/328-backups-confirmed.png`, fullPage: true }); }
  } finally {
    sql(`UPDATE ka_nastaveni SET hodnota = 'vypnuto' WHERE promenna = 'remote_backup'; UPDATE ka_nastaveni SET hodnota = '' WHERE promenna IN ('backup_host', 'backup_folder', 'backup_key_confirmed')`);
    try { (await import('node:fs')).unlinkSync(keyFile); } catch (e) { /* none was made */ }
  }
});
// 3.29: visits confirmed by a browser (Front\Stats::hit). Playwright's Chrome reports navigator.webdriver, so on a page as it is
// the beacon must not go out at all; a context set up like a visitor's browser (a normal user agent, webdriver hidden – on the
// test side only, the site has no switch for it) sends exactly one per page view, which counts exactly one view
await step('3.29 visits confirmed by a browser: none under automation, exactly one per page view in a visitor’s browser, the note in Statistics', async () => {
  const total = () => sql("SELECT CONCAT(COALESCE(SUM(navstevy), 0), '/', COALESCE(SUM(zobrazeni), 0)) FROM ka_stat_dny").trim();
  const settle = async (before, expected) => {
    for (let i = 0; i < 20 && total() === before; i++) { await page.waitForTimeout(150); }
    await page.waitForTimeout(300); // a second beacon would arrive by now
    const [v0, p0] = before.split('/').map(Number);
    const [v1, p1] = total().split('/').map(Number);
    if (`${v1 - v0}/${p1 - p0}` !== expected) { throw new Error(`visits/views changed by ${v1 - v0}/${p1 - p0}, expected ${expected}`); }
  };
  const beacons = (p) => { const sent = []; p.on('request', (r) => { if (new URL(r.url()).pathname === '/hit') { sent.push(r.method()); } }); return sent; };
  const robot = await browser.newContext({ locale: 'en-GB' });
  try {
    const p = await robot.newPage();
    watch(p);
    const sent = beacons(p);
    const before = total();
    await p.goto(BASE + '/', { waitUntil: 'networkidle' });
    if (!(await p.evaluate(() => navigator.webdriver))) { throw new Error('the automated browser does not report navigator.webdriver'); }
    if (!(await p.locator('script[src*="/image/vitals.js"][data-hit="/hit"][data-hit-page="/"][data-hit-key]').count())) { throw new Error('the page carries no page-view beacon'); }
    await settle(before, '0/0');
    if (sent.length) { throw new Error(`${sent.length} page-view beacon(s) sent under browser automation`); }
  } finally {
    await robot.close();
  }
  const person = await browser.newContext({ locale: 'en-GB', userAgent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36' });
  await person.addInitScript(() => { Object.defineProperty(Navigator.prototype, 'webdriver', { get: () => false, configurable: true }); });
  try {
    const p = await person.newPage();
    watch(p);
    const sent = beacons(p);
    let before = total();
    await p.goto(BASE + '/', { waitUntil: 'networkidle' });
    await settle(before, '1/1');
    before = total();
    await p.reload({ waitUntil: 'networkidle' });
    await settle(before, '0/1'); // the same visitor again: one more page view, not another visit
    if (sent.length > 2) { throw new Error(`${sent.length} beacons for two page views`); }
  } finally {
    await person.close();
  }
  // the honest history: an older day with numbers counted the old way, and the Statistics screen says from when they are browser-confirmed
  sql("INSERT IGNORE INTO ka_stat_dny (den, navstevy, zobrazeni) VALUES (CURDATE() - INTERVAL 5 DAY, 0, 0)");
  await visit('/admin.php?module=stats&days=7');
  const note = page.locator('[data-stats-confirmed]');
  await note.waitFor();
  if (!/only visits confirmed by a visitor’s browser are counted/.test(await note.innerText())) { throw new Error(`the note says: ${await note.innerText()}`); }
  if (await page.locator('.graf-sloupec.graf-zacatek').count() !== 1) { throw new Error('the first day of browser-confirmed counting is not marked on the chart'); }
  if (SHOTS) { await page.screenshot({ path: `${SHOTS}/329-stats-confirmed.png`, fullPage: true }); }
});
// 3.29.1: the dashboard's chart of 14 days says it too – the day marked with a line, its bar's label and the note of Statistics
await step('3.29.1 the dashboard chart marks the day browser-confirmed counting started and says why the visits dropped', async () => {
  await visit('/admin.php');
  const chart = page.locator('.prehled-graf');
  await chart.waitFor();
  const note = chart.locator('[data-stats-confirmed]');
  if (!/only visits confirmed by a visitor’s browser are counted/.test(await note.innerText())) { throw new Error(`the dashboard note says: ${await note.innerText()}`); }
  const line = chart.locator('line.prehled-zacatek');
  if (await line.count() !== 1) { throw new Error(`${await line.count()} marked days on the dashboard chart`); }
  const day = await line.getAttribute('data-stats-confirmed-day');
  if (day !== sql("SELECT hodnota FROM ka_nastaveni WHERE promenna = 'stats_confirmed_since'").trim()) { throw new Error(`the chart marks ${day}`); }
  if (await chart.locator('rect[aria-label*="only visits confirmed by a browser count from this day"]').count() !== 1) { throw new Error('the marked day\'s bar does not say it'); }
  const stroke = await line.evaluate((el) => getComputedStyle(el).stroke);
  if (!stroke || stroke === 'none') { throw new Error('the marker line is not drawn'); }
  if (SHOTS) { await chart.screenshot({ path: `${SHOTS}/3291-dashboard-confirmed.png` }); }
});
// 3.31 (agent B): the Pipeline board – a column per stage; a card moves with the keyboard (Space, an arrow, Space – the live
// region announces it) and by dragging; a lost column asks for the reason in the card's own form; no sideways scroll at 1440
// and 390 px (the columns stack on a phone), light and dark; axe clean on the board and a deal
await step('3.31 pipeline: the board – keyboard move with an announcement, drag to Lost asks the reason; 1440 and 390 px, light/dark, axe', async () => {
  if (!MYSQL.length) { throw new Error('MYSQL_JSON is not set'); }
  sql("DELETE FROM ka_deal_stage_history WHERE deal_id IN (SELECT id FROM ka_deals WHERE title LIKE '%B331%'); DELETE FROM ka_deals WHERE title LIKE '%B331%'; DELETE FROM ka_contacts WHERE email_norm LIKE '%b331%'");
  const eva = Number(sql(`INSERT INTO ka_contacts (email, email_norm, name, phone, language, source, first_seen_at, last_activity_at, created_at, updated_at)
    VALUES ('eva.b331@northwind-b331.example', 'eva.b331@northwind-b331.example', 'Eva Longname-Doubleservice B331', '', 'en', 'manual', NOW(), NOW(), NOW(), NOW()); SELECT LAST_INSERT_ID();`).trim());
  const deal = (title, stage, value, currency, close) => Number(sql(`INSERT INTO ka_deals (title, contact_id, stage, value, currency, expected_close, created_at, updated_at)
    VALUES ('${title}', ${eva}, '${stage}', ${value === null ? 'NULL' : `'${value}'`}, ${currency === null ? 'NULL' : `'${currency}'`}, ${close === null ? 'NULL' : `'${close}'`}, NOW(), NOW()); SELECT LAST_INSERT_ID();`).trim());
  const a = deal('Website redesign B331', 'new', '12500.50', 'EUR', '2026-01-15');
  const b = deal('Yearly service plan for all three offices B331', 'new', '480.00', 'EUR', null);
  const c = deal('Logo B331', 'qualified', null, null, null);
  sql(`INSERT INTO ka_deal_stage_history (deal_id, from_stage, to_stage, at) VALUES (${a}, NULL, 'new', NOW()), (${b}, NULL, 'new', NOW()), (${c}, NULL, 'qualified', NOW())`);
  try {
    await page.setViewportSize({ width: 1440, height: 900 });
    await visit('/admin.php?module=pipeline');
    if ((await page.locator('[data-pipeline-board] section[data-stage]').count()) !== 5) { throw new Error('the board does not have the five default stages'); }
    if (!(await page.locator('section[data-stage="new"] [data-stage-sums]').innerText()).includes('12980.50 EUR')) { throw new Error('the sum of New in EUR is not 12980.50'); }
    // the keyboard: the handle, Space, ArrowRight, Space – posted as JSON, the card moves, the counts and sums follow, announced
    const before = (await page.locator('[data-stage-count]').allInnerTexts()).map(Number);
    await page.locator(`#deal-${a} .obchod-uchyt`).focus();
    await page.keyboard.press('Space');
    if (!/Picked up/.test(await page.locator('[data-pipeline-announce]').innerText({ timeout: 2000 }).catch(() => ''))) { await page.waitForTimeout(200); }
    await page.keyboard.press('ArrowRight');
    await page.keyboard.press('Space');
    await page.locator(`section[data-stage="qualified"] #deal-${a}`).waitFor({ timeout: 5000 });
    await page.waitForFunction(() => /Qualified/.test(document.querySelector('[data-pipeline-announce]').textContent));
    if (sql(`SELECT stage FROM ka_deals WHERE id = ${a}`).trim() !== 'qualified') { throw new Error('the keyboard move was not saved'); }
    const counts = (await page.locator('[data-stage-count]').allInnerTexts()).map(Number);
    if (counts[0] !== before[0] - 1 || counts[1] !== before[1] + 1 || !(await page.locator('section[data-stage="new"] [data-stage-sums]').innerText()).includes('480.00 EUR')) { throw new Error(`the counts and sums did not follow: ${before} → ${counts}`); }
    if (await page.evaluate(() => !document.activeElement.classList.contains('obchod-uchyt'))) { throw new Error('the focus did not stay on the moved card'); }
    // dragging to Lost: the card's form shows the reason, focused; nothing is saved yet
    await page.locator(`#deal-${b}`).dragTo(page.locator('section[data-stage="lost"]'));
    await page.locator(`#deal-${b} select[name="lost_reason"]`).waitFor({ state: 'visible', timeout: 3000 });
    if ((await page.evaluate(() => document.activeElement.name)) !== 'lost_reason') { throw new Error('the reason is not focused after a drop on Lost'); }
    if (sql(`SELECT stage FROM ka_deals WHERE id = ${b}`).trim() !== 'new') { throw new Error('a drop on Lost saved without a reason'); }
    await page.selectOption(`#deal-${b} select[name="lost_reason"]`, 'timing');
    await Promise.all([page.waitForNavigation(), page.locator(`#deal-${b} form button[type="submit"]`).click()]);
    if (sql(`SELECT CONCAT(stage, '/', lost_reason) FROM ka_deals WHERE id = ${b}`).trim() !== 'lost/timing') { throw new Error('Move with the reason did not lose the deal'); }
    if (SHOTS) { await page.screenshot({ path: `${SHOTS}/331-board.png`, fullPage: true }); }
    const state = await context.storageState();
    for (const scheme of ['light', 'dark']) {
      for (const [label, width, height] of [['desktop', 1440, 900], ['phone', 390, 844]]) {
        const other = await browser.newContext({ viewport: { width, height }, locale: 'en-GB', colorScheme: scheme, storageState: state, ...(width < 500 ? { isMobile: true, hasTouch: true } : {}) });
        const p2 = await other.newPage(); watch(p2);
        try {
          for (const url of ['/admin.php?module=pipeline', `/admin.php?module=pipeline&action=detail&id=${a}`, '/admin.php?module=pipeline&view=list&status=all']) {
            await p2.goto(`${BASE}${url}`, { waitUntil: 'networkidle' });
            const wide = await p2.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
            if (wide > 0) { throw new Error(`${wide} px of sideways scroll (${url}, ${label}, ${scheme})`); }
            if (url.endsWith('pipeline')) {
              const tops = await p2.locator('section[data-stage]').evaluateAll((els) => els.map((el) => Math.round(el.getBoundingClientRect().top)));
              if ((label === 'phone') !== (tops[1] > tops[0])) { throw new Error(`the columns ${label === 'phone' ? 'do not stack' : 'are not side by side'} (${label}, ${scheme})`); }
            }
            if (SHOTS) { await p2.screenshot({ path: `${SHOTS}/331-${url.includes('detail') ? 'detail' : url.includes('list') ? 'list' : 'board'}-${label}-${scheme}.png`, fullPage: true }); }
          }
        } finally {
          await other.close();
        }
      }
    }
    const axeContext = await browser.newContext({ storageState: state, bypassCSP: true, viewport: { width: 1440, height: 900 } });
    try {
      const axePage = await axeContext.newPage();
      for (const [url, include] of [['/admin.php?module=pipeline', ['[data-pipeline-filter]', '[data-pipeline-board]']], [`/admin.php?module=pipeline&action=detail&id=${a}`, ['[data-deal-card]', '#move', '[data-deal-history]']]]) {
        for (const scheme of ['light', 'dark']) {
          await axePage.emulateMedia({ colorScheme: scheme });
          await axePage.goto(BASE + url, { waitUntil: 'networkidle' });
          await axePage.addScriptTag({ path: AXE });
          const found = await axePage.evaluate(([inc, tags]) => window.axe.run({ include: inc.map((s) => [s]) }, { runOnly: { type: 'tag', values: tags }, resultTypes: ['violations'] }), [include, WCAG]);
          if (found.violations.length) { throw new Error(`axe ${url} (${scheme}): ` + found.violations.map((v) => `${v.id} ${v.nodes[0].target.join(' ')}`).join(', ')); }
        }
      }
    } finally {
      await axeContext.close();
    }
  } finally {
    await page.setViewportSize({ width: 1440, height: 900 });
    sql(`DELETE FROM ka_deal_stage_history WHERE deal_id IN (${a}, ${b}, ${c}); DELETE FROM ka_deals WHERE id IN (${a}, ${b}, ${c}); DELETE FROM ka_contacts WHERE id = ${eva}`);
  }
});

await browser.close();
if (errors.length) {
  console.log(`\nSCRIPT ERRORS: ${errors.length}\n  ` + [...new Set(errors)].join('\n  '));
  process.exit(1);
}
console.log(`\nBROWSER TEST OK (${steps} steps)`);
