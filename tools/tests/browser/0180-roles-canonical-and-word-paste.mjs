// Kaleta browser steps 0180 (tools/test-browser.mjs runs the files of this folder in number order on one site – the order is
// the contract; tools/tests/README.md). Moved verbatim from the single file in 3.49 P1.
export default async (t) => {
  const { BASE, PASSWORD, SHOTS, sql, browser, context, page, watch, canvas, step, visit, liveCanvas, builderIdle } = t;

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
};
