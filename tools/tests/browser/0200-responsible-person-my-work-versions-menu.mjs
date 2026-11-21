// Kaleta browser steps 0200 (tools/test-browser.mjs runs the files of this folder in number order on one site – the order is
// the contract; tools/tests/README.md). Moved verbatim from the single file in 3.49 P1.
export default async (t) => {
  const { BASE, PASSWORD, SHOTS, MYSQL, sql, browser, page, watch, canvas, step, visit, builderIdle, createHash } = t;

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
      // 3.32: an enquiry row links by who wrote it (the form and number under it)
      for (const [list, text] of [['assigned', 'q324@visitor.example'], ['pages', 'Price list 324']]) {
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
    if (!/^Draft – (today|yesterday|\d{1,2} [A-Z][a-z]{2} \d{4}) \d\d:\d\d \(by Tester\)$/.test((await draftRow.locator('span').first().textContent()).trim())) { throw new Error(`the draft entry says “${await draftRow.textContent()}”`); }
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
      // the menu stores the click a moment later (setTimeout 0) – wait for it before leaving the page
      await p.waitForFunction(() => { try { return (JSON.parse(localStorage.getItem('kaleta-menu') || '{}') || {}).appearance === 1; } catch (e) { return false; } });
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
};
