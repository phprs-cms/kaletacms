// Kaleta – browser test (called by tools/test-browser.sh): walks the admin, the builder, the news editor, the menu
// editor and the public site in Chrome and fails on any uncaught script error or console error.
// Env: BASE, PASSWORD (admin), CHROME (browser binary), NODE_PATH (folder with playwright-core), SHOTS (optional folder
// for screenshots of new screens, to look at them).
import { createRequire } from 'node:module';

const require = createRequire(`${process.env.NODE_PATH}/`);
const { chromium } = require('playwright-core');
const { BASE, PASSWORD, CHROME, SHOTS } = process.env;

const browser = await chromium.launch({ executablePath: CHROME, headless: true });
const context = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'en-GB' });
await context.addInitScript(() => { try { localStorage.setItem('ka-st-prohlidka', '1'); } catch (e) { /* ignore */ } });
const page = await context.newPage();
const errors = [];
let where = '';
const watch = (p) => {
  p.on('pageerror', (e) => errors.push(`${where}: ${e.message}`));
  p.on('console', (m) => { if (m.type() === 'error' && !/Failed to load resource/.test(m.text())) errors.push(`${where}: ${m.text()}`); });
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

for (const url of ['/admin.php', '/admin.php?module=pages', '/admin.php?module=pages&action=new', '/admin.php?module=pages&action=edit&id=1',
  '/admin.php?module=news', '/admin.php?module=collections', '/admin.php?module=categories', '/admin.php?module=tags', '/admin.php?module=media',
  '/admin.php?module=appearance', '/admin.php?module=parts', '/admin.php?module=components', '/admin.php?module=popups', '/admin.php?module=users',
  '/admin.php?module=roles', '/admin.php?module=stats', '/admin.php?module=redirects', '/admin.php?module=changelog', '/admin.php?module=transfer',
  '/admin.php?module=extensions', '/admin.php?module=enquiries', '/admin.php?module=subscribers', '/admin.php?module=newsletters', '/admin.php?module=settings',
  '/admin.php?module=settings&tab=seo', '/admin.php?module=settings&tab=analytics', '/admin.php?module=settings&tab=backups', '/admin.php?module=status', '/admin.php?action=account',
  '/admin.php?module=bookings', '/admin.php?module=whistleblowing']) {
  await step(`open ${url}`, () => visit(url));
}

await step('appearance: change a colour and preview', async () => {
  await visit('/admin.php?module=appearance');
  const colour = page.locator('input[type="color"]:visible').first();
  if (await colour.count()) { await colour.fill('#335577'); await page.waitForTimeout(800); }
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
  const anyway = page.locator('dialog[open] .st-tl-hlavni');
  if (await anyway.waitFor({ timeout: 1500 }).then(() => true, () => false)) { await anyway.click(); }
  await page.locator('.st-lista .st-skryta').waitFor({ state: 'detached' });
  if ((await visitor.request.get(`${BASE}/browser-template-page`)).status() !== 200) { throw new Error('the page is not live after its first publish'); }
  await visitor.close();
});

await step('builder: select, style, mobile, edit text', async () => {
  await visit('/admin.php?module=pages&action=builder&id=1');
  await page.waitForTimeout(1500);
  await canvas().locator('h1').first().click();
  await page.waitForTimeout(500);
  await page.getByRole('tab', { name: 'Style' }).first().click().catch(() => page.getByText('Style', { exact: true }).first().click());
  await page.waitForTimeout(400);
  await page.getByRole('button', { name: 'Mobile' }).first().click();
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
  await picker.locator('.galerie-polozka', { hasText: 'team-photo.png' }).waitFor();
  if (/No images here yet/.test(await picker.locator('.galerie-mrizka').textContent())) { throw new Error('"No images here yet." stays next to the uploaded image'); }
  await picker.locator('.galerie-polozka').first().click();
  await page.locator('.st-obrazek-pole img.st-obrazek-nahled:visible').waitFor();
  await page.locator('.st-obrazek-odebrat').click();
  if (await page.locator('.st-obrazek-pole img.st-obrazek-nahled:visible').count()) { throw new Error('Remove left the image in the field'); }
});

await step('builder: site header', async () => {
  await visit('/admin.php?module=parts&action=builder&typ=hlavicka&jazyk=');
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
      await visit(`/admin.php?module=parts&action=templates&typ=${part}`);
      await Promise.all([page.waitForNavigation(), page.locator(`input[name="sablona"][value="${template}"] ~ button`).click()]);
      await visit(`/?cast=${part}&stavba=koncept`);
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

await browser.close();
if (errors.length) {
  console.log(`\nSCRIPT ERRORS: ${errors.length}\n  ` + [...new Set(errors)].join('\n  '));
  process.exit(1);
}
console.log(`\nBROWSER TEST OK (${steps} steps)`);
