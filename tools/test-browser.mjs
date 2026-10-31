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

await step('3.5 a new page from a template stays hidden until its build is published, then goes live', async () => {
  await visit('/admin.php?module=pages&action=new');
  await page.fill('#titulek', 'Browser template page');
  await page.selectOption('#sablona', 'landing');
  await Promise.all([page.waitForNavigation(), page.locator('form.formular button[type="submit"]').first().click()]);
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

await step('builder: select the parent element', async () => {
  // broken from 1.4.0 to 3.2.0: find() returned the parent under another key than its callers read (3.2.1)
  await canvas().locator('h1').first().click();
  await page.waitForTimeout(400);
  const parent = page.locator('button[title^="Select parent element"]').first();
  if (!(await parent.count())) { throw new Error('a nested element offers no "Select parent element" button'); }
  await parent.click();
  await page.waitForTimeout(300);
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
await step('3.20 (C2, C16) enquiry → form → page: the detail names the form and the page with admin links, the builder opens with the form selected, Statistics link the page; without a References collection the testimonial offers to create it', async () => {
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
  // C16: no References collection yet – an administrator sees the one-click preset instead of the request buttons
  if (await page.locator('form[data-references-missing] button[name="preset"][value="references"]').count() !== 1) { throw new Error('the testimonial block does not offer to create the References collection'); }
  if (await page.locator('button', { hasText: 'Ask for a testimonial by e-mail' }).count() !== 0) { throw new Error('the request button shows although the References collection is missing'); }
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
  const items = await page.locator('.menu > li').evaluateAll((list) => list.map((li) => li.textContent.replace(/\s+/g, ' ').trim()));
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
    await page.evaluate(() => document.querySelector('#st-lista-vice button[title="Published versions"]').click()); // an item of the ⋯ menu
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

await step('builder: element tree and search', async () => {
  const search = page.locator('input.st-hledat').first();
  if (await search.count()) { await search.fill('text'); await page.waitForTimeout(400); await search.fill(''); }
  const tree = page.locator('.st-strom [role="treeitem"], .st-strom li').nth(1);
  if (await tree.count()) { await tree.click(); await page.keyboard.press('ArrowDown'); await page.keyboard.press('ArrowUp'); }
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
  if (await start.count() !== 1) { throw new Error('the start date field offers fields that are not dates'); } // References has no date field: only "—"
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
      for (const url of ['/admin.php?module=fleet', '/admin.php?module=fleet&action=sites&show=all', '/admin.php?module=fleet&action=customers']) {
        await visit(url);
        const wider = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
        if (wider > 1) { throw new Error(`${url} scrolls sideways by ${wider} px at ${width} px`); }
        if (SHOTS) { await page.screenshot({ path: `${SHOTS}/fleet-${url.replace(/.*module=fleet&?/, '').replace(/\W+/g, '-') || 'inbox'}-${name}.png`, fullPage: true }); }
      }
      await visit('/admin.php?module=fleet');
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
    await visit('/admin.php?module=fleet&urgent=1');
    if (await page.locator('table.vypis-karty .stitek-chyba').count() !== await page.locator('table.vypis-karty tbody tr').count()) { throw new Error('"only urgent" lists other rows'); }
  } finally {
    await page.setViewportSize({ width: 1440, height: 900 });
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
  const svg = page.locator('figure.trend svg[role="img"]');
  await svg.waitFor();
  if ((await svg.locator('rect').count()) !== 90) { throw new Error('the trend has not 90 bars'); }
  if (!/90/.test(await svg.getAttribute('aria-label') || '')) { throw new Error('the trend has no summary for screen readers'); }
  if ((await svg.locator('rect.chyba').count()) < 1 || (await svg.locator('rect.slabe').count()) < 1) { throw new Error('the bad days are not marked'); }
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
    await page.locator('.menu .menu-kolekce a').first().scrollIntoViewIfNeeded();
    await wide('open menu');
    if (SHOTS) { await page.screenshot({ path: `${SHOTS}/collections-menu-icons-phone.png` }); }
    await page.keyboard.press('Escape');
  } finally {
    await page.setViewportSize({ width: 1440, height: 900 });
    sql("DELETE FROM ka_kolekce WHERE seo_link IN ('products-319', 'job-openings-319', 'team-319')" + (before !== '' && before !== 'NULL' ? `; UPDATE ka_kolekce SET admin_menu = 1 WHERE idk IN (${before})` : ''));
  }
});

await browser.close();
if (errors.length) {
  console.log(`\nSCRIPT ERRORS: ${errors.length}\n  ` + [...new Set(errors)].join('\n  '));
  process.exit(1);
}
console.log(`\nBROWSER TEST OK (${steps} steps)`);
