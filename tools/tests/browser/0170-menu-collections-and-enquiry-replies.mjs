// Kaleta browser steps 0170 (tools/test-browser.mjs runs the files of this folder in number order on one site – the order is
// the contract; tools/tests/README.md). Moved verbatim from the single file in 3.49 P1.
export default async (t) => {
  const { SHOTS, MYSQL, sql, page, canvas, step, visit } = t;

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
};
