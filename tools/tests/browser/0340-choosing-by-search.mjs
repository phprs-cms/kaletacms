// Kaleta browser steps 0340 (tools/test-browser.mjs runs the files of this folder in number order on one site – the order is
// the contract; tools/tests/README.md). 3.49 C: choosing by search – the combobox over select[data-pick] (views/admin/pick.php,
// image/admin.js "choosing by search") on the deal form and in the quote editor, the product search, the special options,
// New customer…, the page without script; 1440 and 390 px, light and dark, axe. Written from part C's notes
// (plan/c349-browser-steps.md); step 9 (a required picker) waits for a screen that has one.
export default async (t) => {
  const { BASE, SHOTS, MYSQL, sql, browser, context, page, watch, step, visit, AXE, WCAG } = t;

  // the records part C's smoke section uses, made here: a company, a customer of it, 300 newer customers and an old one
  // outside the first 300 options; the deal form and the quote editor need Customers, the Pipeline and Quotes
  const fixtures = () => {
    const extensions = sql("SELECT IFNULL((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'extensions'), '')").trim();
    sql(`REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('extensions', '${extensions === '' ? 'novinky,poptavky,statistika,presmerovani,claude' : extensions},customers,pipeline,quotes')`);
    sql("DELETE FROM ka_deals WHERE title LIKE '%C449%'; DELETE FROM ka_contacts WHERE email_norm LIKE '%-c449@example.com'; DELETE FROM ka_companies WHERE name = 'Firma C449 Zubr'");
    const company = Number(sql("INSERT INTO ka_companies (name, domain, created_at, updated_at) VALUES ('Firma C449 Zubr', 'zubr-c449.example', NOW(), NOW()); SELECT LAST_INSERT_ID();").trim());
    const zora = Number(sql(`INSERT INTO ka_contacts (email, email_norm, name, company_id, source, created_at, updated_at, last_activity_at) VALUES ('zora-c449@example.com', 'zora-c449@example.com', 'Zora C449', ${company}, 'manual', NOW(), NOW(), NOW()); SELECT LAST_INSERT_ID();`).trim());
    const olaf = Number(sql("INSERT INTO ka_contacts (email, email_norm, name, source, created_at, updated_at, last_activity_at) VALUES ('old-c449@example.com', 'old-c449@example.com', 'Olaf C449', 'manual', '2001-01-01 10:00:00', '2001-01-01 10:00:00', '2001-01-01 10:00:00'); SELECT LAST_INSERT_ID();").trim());
    sql(`INSERT INTO ka_contacts (email, email_norm, name, source, created_at, updated_at, last_activity_at) VALUES ${Array.from({ length: 300 }, (_, i) => `('bulk${i + 1}-c449@example.com', 'bulk${i + 1}-c449@example.com', 'Bulk C449 ${i + 1}', 'manual', NOW(), NOW(), NOW())`).join(', ')}`);
    return { extensions, company, zora, olaf };
  };
  const tidy = (f) => sql(`DELETE FROM ka_quotes WHERE title LIKE '%C449%'; DELETE FROM ka_deals WHERE title LIKE '%C449%'; DELETE FROM ka_poptavky WHERE formular = 'C449'; DELETE FROM ka_contacts WHERE email_norm LIKE '%-c449@example.com';
    DELETE FROM ka_companies WHERE id = ${f.company}; REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('extensions', '${f.extensions}'); DELETE FROM ka_nastaveni WHERE promenna = 'extensions' AND hodnota = ''`);
  // types into a combobox, waits for a row and moves with the arrows until the active row matches; Enter chooses it
  const choose = async (p, field, text, match) => {
    await field.fill('');
    await field.type(text, { delay: 20 });
    await p.waitForFunction(([id, m]) => [...document.querySelectorAll(`#${id} [role="option"]`)].some((o) => o.textContent.includes(m)), [await field.getAttribute('aria-controls'), match], { timeout: 6000 });
    await p.waitForTimeout(300);
    for (let i = 0; i < 25; i++) {
      await field.press('ArrowDown');
      const active = await field.getAttribute('aria-activedescendant');
      if (active && (await p.locator(`#${active}`).innerText()).includes(match)) { await field.press('Enter'); return; }
    }
    throw new Error(`no row "${match}" reached by the arrows after typing "${text}"`);
  };
  const arrowTo = async (p, field, label) => {
    for (let i = 0; i < 10; i++) {
      await field.press('ArrowDown');
      const active = await field.getAttribute('aria-activedescendant');
      if (active && (await p.locator(`#${active}`).innerText()).trim() === label) { await field.press('Enter'); return; }
    }
    throw new Error(`no special row "${label}"`);
  };
  const createDeal = async (title) => {
    await page.fill('#obchod-novy-titulek', title);
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.locator('button[type="submit"]', { hasText: 'Create the deal' }).click()]);
    return sql(`SELECT IFNULL(contact_id, 'null') FROM ka_deals WHERE title = '${title}'`).trim();
  };

  // steps 1–6 of part C's notes: the deal form by keyboard, the special options, clearing, Escape, back, New customer…
  await step('3.49 C: the deal form – a customer outside the 300 options by keyboard, the special options, clearing, Escape, back and New customer…', async () => {
    if (!MYSQL.length) { throw new Error('MYSQL_JSON is not set'); }
    const f = fixtures();
    try {
      await page.setViewportSize({ width: 1440, height: 900 });
      await visit('/admin.php?module=pipeline&action=new');
      const field = page.locator('#obchod-novy-zakaznik');
      if ((await field.getAttribute('role')) !== 'combobox' || !(await field.isVisible()) || !(await page.locator('#obchod-novy-zakaznik-hodnota').isHidden())) { throw new Error('the label does not point at a visible combobox over a hidden select'); }
      if ((await page.locator(`#obchod-novy-zakaznik-hodnota option[value="${f.olaf}"]`).count()) !== 0) { throw new Error('Olaf is among the first options – the fixture is wrong'); }
      await choose(page, field, 'Olaf', 'Olaf C449');
      if ((await page.locator('#obchod-novy-zakaznik-hodnota').inputValue()) !== String(f.olaf) || (await field.inputValue()) !== 'Olaf C449' || !page.url().includes('action=new')) { throw new Error('the chosen row is not the select\'s value, or the form was sent'); }
      if ((await createDeal('Keyboard C449')) !== String(f.olaf)) { throw new Error('the deal was not saved with Olaf'); }

      // the special options on a deal from an enquiry with a linked customer: "The enquiry's customer" (0) and "—" (none)
      const enquiry = (n) => Number(sql(`INSERT INTO ka_poptavky (datum, formular, stranka, tema, email, data, stav, contact_id) VALUES (NOW(), 'C449', '/kontakt', 'Shelves ${n}', 'zora-c449@example.com', '[]', 0, ${f.zora}); SELECT LAST_INSERT_ID();`).trim());
      await visit(`/admin.php?module=pipeline&action=new&enquiry=${enquiry(1)}`);
      // the enquiry's customer is chosen already (its name in the field); "The enquiry's customer" and "—" are the special rows
      if ((await field.inputValue()) === '') { throw new Error('the field of a deal from an enquiry is empty'); }
      await arrowTo(page, field, '—');
      if ((await createDeal('None C449')) !== 'null') { throw new Error('"—" kept a customer'); }
      await visit(`/admin.php?module=pipeline&action=new&enquiry=${enquiry(2)}`);
      if ((await createDeal('Enquiry customer C449')) !== String(f.zora)) { throw new Error('"The enquiry\'s customer" did not give the enquiry\'s contact'); }

      // clearing on an edited deal: the field emptied and left – the select on "—", saved without a customer
      const edited = Number(sql(`INSERT INTO ka_deals (title, contact_id, stage, created_at, updated_at) VALUES ('Edited C449', ${f.zora}, 'new', NOW(), NOW()); SELECT LAST_INSERT_ID();`).trim());
      await visit(`/admin.php?module=pipeline&action=detail&id=${edited}`);
      await page.locator('details.zakaznik-upravit > summary').first().click();
      const own = page.locator(`#obchod-${edited}-zakaznik`);
      // Escape and leaving without a choice give the chosen customer back, the select unchanged
      const shown = await own.inputValue();
      if (!shown.startsWith('Zora C449')) { throw new Error('the edited deal shows its customer as: ' + shown); }
      await own.fill('Bulk');
      await page.waitForTimeout(400);
      await own.press('Escape');
      if ((await own.inputValue()) !== shown || (await page.locator(`#obchod-${edited}-zakaznik-hodnota`).inputValue()) !== String(f.zora)) { throw new Error('Escape did not give the chosen customer back'); }
      await own.fill('Bulk');
      await page.waitForTimeout(400);
      await page.locator('h1').first().click();
      if ((await own.inputValue()) !== shown) { throw new Error('leaving the field without a choice changed it'); }
      await own.fill('');
      await own.press('Tab');
      if ((await page.locator(`#obchod-${edited}-zakaznik-hodnota`).inputValue()) !== '0') { throw new Error('a cleared field did not choose "—"'); }
      await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.locator(`#obchod-${edited}-zakaznik`).locator('xpath=ancestor::form').locator('button[type="submit"]').click()]);
      if (sql(`SELECT IFNULL(contact_id, 'null') FROM ka_deals WHERE id = ${edited}`).trim() !== 'null') { throw new Error('the cleared customer was saved'); }

      // back from the next page: the field and the select agree
      await visit('/admin.php?module=pipeline&action=new');
      await choose(page, field, 'Zora', 'Zora C449');
      await createDeal('Back C449');
      await page.goBack({ waitUntil: 'networkidle' });
      const agree = await page.evaluate(() => { const s = document.getElementById('obchod-novy-zakaznik-hodnota'); const o = s.options[s.selectedIndex]; const l = o ? o.textContent.trim() : ''; return [document.getElementById('obchod-novy-zakaznik').value, l === '—' ? '' : l]; });
      if (agree[0] !== agree[1]) { throw new Error(`after going back the field says "${agree[0]}", the select "${agree[1]}"`); }

      // New customer…: the last row opens the details with the focus in the name; a second deal with the address reuses the contact
      for (const n of [1, 2]) {
        await visit('/admin.php?module=pipeline&action=new');
        await field.fill('x');
        await page.waitForTimeout(300);
        await field.press('ArrowUp');
        if (!(await page.locator(`#${await field.getAttribute('aria-activedescendant')}`).innerText()).includes('New customer')) { throw new Error('the last row is not New customer…'); }
        await field.press('Enter');
        if (!(await page.locator('details[data-pick-new]').evaluate((d) => d.open && d.contains(document.activeElement) && document.activeElement.name === 'contact_new_name'))) { throw new Error('New customer… did not open with the focus in the name'); }
        await page.fill('input[name="contact_new_name"]', 'Nela B449');
        await page.fill('input[name="contact_new_email"]', 'nela-b449-c449@example.com');
        await createDeal(`New customer C449 ${n}`);
      }
      if (sql("SELECT COUNT(*) FROM ka_contacts WHERE email_norm = 'nela-b449-c449@example.com'").trim() !== '1') { throw new Error('New customer… made more than one contact for one address'); }
    } finally { tidy(f); }
  });

  // steps 7, 8: the quote editor – the deal chosen by typing marks the editor changed, a search alone does not; the product search adds a line
  await step('3.49 C: the quote editor – customer, company and deal comboboxes; a typed search alone changes nothing; the product search adds a line', async () => {
    const f = fixtures();
    try {
      let list = sql('SELECT IFNULL((SELECT id FROM ka_price_lists WHERE is_default = 1), 0)').trim();
      if (list === '0') { list = sql("INSERT INTO ka_price_lists (name, currency, is_default, created_at, updated_at) VALUES ('C449 list', 'CZK', 1, NOW(), NOW()); SELECT LAST_INSERT_ID();").trim(); }
      sql("INSERT INTO ka_deals (title, contact_id, stage, created_at, updated_at) VALUES ('Kanoe C449 open', NULL, 'new', NOW(), NOW())");
      const quote = Number(sql(`INSERT INTO ka_quotes (title, status, price_list_id, currency, language, vat_mode, customer_name, created_by, created_at, updated_at) VALUES ('Quote C449', 'draft', ${list}, 'CZK', 'en', 'standard', '', (SELECT idu FROM ka_uzivatele WHERE user = 'admin'), NOW(), NOW()); SELECT LAST_INSERT_ID();`).trim());
      await page.setViewportSize({ width: 1440, height: 900 });
      await visit(`/admin.php?module=quotes&action=edit&id=${quote}`);
      for (const id of ['nabidka-kontakt', 'nabidka-firma', 'nabidka-obchod']) {
        if ((await page.locator(`#${id}`).getAttribute('role')) !== 'combobox') { throw new Error(`#${id} is not a combobox`); }
      }
      const deal = page.locator('#nabidka-obchod');
      await deal.fill('Ka');
      await page.waitForTimeout(500);
      await deal.press('Escape');
      if ((await page.locator('#quote-form').getAttribute('data-unsaved-changed')) !== null) { throw new Error('typing a search marked the quote changed'); }
      await choose(page, deal, 'Kanoe', 'Kanoe C449 open');
      const dealId = sql("SELECT id FROM ka_deals WHERE title = 'Kanoe C449 open'").trim();
      if ((await page.locator('#nabidka-obchod-hodnota').inputValue()) !== dealId || (await page.locator('#quote-form').getAttribute('data-unsaved-changed')) === null) { throw new Error('the chosen deal is not the select\'s value, or the editor is not marked changed'); }
      // the product search: the internal price is the row's note; Enter writes the code (or #idp) into the field; the line is added
      const product = page.locator('#nabidka-produkt');
      const first = (await page.locator('#nabidka-produkty option').count()) ? await page.locator('#nabidka-produkty option').first().evaluate((o) => [o.value, o.textContent.trim()]) : null;
      if (first === null) { console.log('  --     3.49 C: the price list of this site offers no product – the product search is covered by smoke 4460'); return; }
      const word = first[1].replace(/[^\p{L}\p{N} ]/gu, ' ').trim().split(/\s+/)[0].slice(0, 4);
      await product.type(word, { delay: 20 });
      await page.waitForFunction(() => [...document.querySelectorAll('[data-quote-add] [role="option"] small')].length > 0, null, { timeout: 6000 });
      await product.press('ArrowDown');
      await product.press('Enter');
      const value = await product.inputValue();
      if (!value || value === word) { throw new Error('Enter wrote nothing into the product field'); }
      const before = Number(sql(`SELECT COUNT(*) FROM ka_quote_lines WHERE quote_id = ${quote}`).trim());
      await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.locator('[data-quote-add-line]').click()]);
      if (Number(sql(`SELECT COUNT(*) FROM ka_quote_lines WHERE quote_id = ${quote}`).trim()) !== before + 1) { throw new Error('the searched product was not added as a line'); }
    } finally { tidy(f); }
  });

  // step 10: without script the plain selects and the datalist work, New customer… opens natively and a deal saves with a customer
  await step('3.49 C: without JavaScript – plain selects, the datalist, New customer… as a native details; a deal saves with the chosen customer', async () => {
    const f = fixtures();
    const other = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'en-GB', javaScriptEnabled: false, storageState: await context.storageState() });
    const p2 = await other.newPage();
    try {
      await p2.goto(`${BASE}/admin.php?module=pipeline&action=new`, { waitUntil: 'networkidle' });
      if (!(await p2.locator('select#obchod-novy-zakaznik').isVisible()) || (await p2.locator('[role="combobox"]').count()) !== 0) { throw new Error('without script the customer is not a plain select'); }
      await p2.locator('details[data-pick-new] > summary').click();
      if (!(await p2.locator('details[data-pick-new]').evaluate((d) => d.open))) { throw new Error('New customer… does not open without script'); }
      await p2.locator('details[data-pick-new] > summary').click();
      const chosen = await p2.locator('select#obchod-novy-zakaznik option:not([data-pick-special])').first().getAttribute('value'); // a customer among the first 300
      await p2.selectOption('select#obchod-novy-zakaznik', chosen);
      await p2.fill('#obchod-novy-titulek', 'No script C449');
      await Promise.all([p2.waitForNavigation({ waitUntil: 'networkidle' }), p2.locator('button[type="submit"]', { hasText: 'Create the deal' }).click()]);
      if (sql("SELECT IFNULL(contact_id, 'null') FROM ka_deals WHERE title = 'No script C449'").trim() !== chosen) { throw new Error('without script the deal was not saved with the customer'); }
    } finally { await other.close(); tidy(f); }
  });

  // steps 11, 12: the open list at 1440 and 390 px, light and dark – rows ≥ 44 px on a phone, inside the viewport, no sideways
  // scroll; axe on the form with the list open (combobox → listbox → option)
  await step('3.49 C: the open list on the deal form – 1440 and 390 px, light/dark, rows of 44 px on a phone, no sideways scroll, axe with the list open', async () => {
    const f = fixtures();
    const state = await context.storageState();
    try {
      for (const scheme of ['light', 'dark']) {
        for (const [label, width, height] of [['desktop', 1440, 900], ['phone', 390, 844]]) {
          const other = await browser.newContext({ viewport: { width, height }, locale: 'en-GB', colorScheme: scheme, storageState: state, bypassCSP: true, ...(width < 500 ? { isMobile: true, hasTouch: true } : {}) });
          const p2 = await other.newPage(); watch(p2);
          try {
            await p2.goto(`${BASE}/admin.php?module=pipeline&action=new`, { waitUntil: 'networkidle' });
            const field = p2.locator('#obchod-novy-zakaznik');
            await field.click();
            await field.type('Bulk', { delay: 20 });
            await p2.waitForFunction(() => document.querySelectorAll('.vyber-seznam:not([hidden]) [role="option"]').length > 3, null, { timeout: 6000 });
            await p2.waitForTimeout(400);
            const wide = await p2.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
            if (wide > 0) { throw new Error(`${wide} px of sideways scroll with the list open (${label}, ${scheme})`); }
            const rows = await p2.evaluate(() => [...document.querySelectorAll('.vyber-seznam:not([hidden]) [role="option"]')].map((o) => o.getBoundingClientRect()).map((r) => [Math.round(r.height), Math.round(r.left), Math.round(r.right)]));
            if (label === 'phone' && rows.some(([h]) => h < 44)) { throw new Error('a row of the list is lower than 44 px on a phone'); }
            if (rows.some(([, l, r]) => l < 0 || r > width)) { throw new Error(`the list leaves the viewport (${label})`); }
            if (SHOTS) { await p2.screenshot({ path: `${SHOTS}/349c-deal-form-list-${label}-${scheme}.png`, fullPage: false }); }
            if (label === 'desktop') {
              await p2.addScriptTag({ path: AXE });
              const found = await p2.evaluate((tags) => window.axe.run({ include: [['.vyber']] }, { runOnly: { type: 'tag', values: tags }, resultTypes: ['violations'] }), WCAG);
              if (found.violations.length) { throw new Error(`axe with the list open (${scheme}): ` + found.violations.map((v) => `${v.id} ${v.nodes[0].target.join(' ')}`).join(', ')); }
            }
          } finally { await other.close(); }
        }
      }
    } finally { tidy(f); }
  });
};
