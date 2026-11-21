// Kaleta browser steps 0270 (tools/test-browser.mjs runs the files of this folder in number order on one site – the order is
// the contract; tools/tests/README.md). Moved verbatim from the single file in 3.49 P1.
export default async (t) => {
  const { BASE, SHOTS, MYSQL, sql, browser, context, page, watch, step, visit, AXE, WCAG, execFileSync } = t;

  // 3.38 (agent A): Quotes → Internal prices – the grid of a price list with a product and its variants, saving a price, at 1440 and
  // 390 px in light and dark, no sideways scroll, axe on the grid; the price never reaches the product's page
  await step('3.38 internal prices: the grid at 1440 and 390 px (light/dark, axe), saving a price, never on the site', async () => {
    if (!MYSQL.length) { throw new Error('MYSQL_JSON is not set'); }
    sql("UPDATE ka_nastaveni SET hodnota = CONCAT(hodnota, ',quotes') WHERE promenna = 'extensions' AND NOT FIND_IN_SET('quotes', hodnota);"
      + " INSERT INTO ka_kolekce (nazev, seo_link, pole, detail, preset) VALUES ('Machines B338', 'machines-b338', '[{\"klic\":\"code\",\"popisek\":\"Code\",\"typ\":\"text\"},{\"klic\":\"variants\",\"popisek\":\"Variants\",\"typ\":\"varianty\"}]', 1, 'products');"
      + " INSERT INTO ka_kolekce_polozky (idk, nazev, seo_link, data, datum, zobrazit) SELECT idk, 'Shock-wave unit with a rather long product name B338', 'shock-wave-b338', '{\"code\":\"SW-B338\",\"variants\":\"Basic | SW-B338-B\\\\nPro with a long variant name | SW-B338-P\"}', NOW(), 1 FROM ka_kolekce WHERE seo_link = 'machines-b338'");
    const idk = sql("SELECT idk FROM ka_kolekce WHERE seo_link = 'machines-b338'").trim();
    const idp = sql(`SELECT idp FROM ka_kolekce_polozky WHERE idk = ${Number(idk)} AND seo_link = 'shock-wave-b338'`).trim();
    try {
      const state = await context.storageState();
      for (const scheme of ['light', 'dark']) {
        for (const [label, width, height] of [['desktop', 1440, 900], ['phone', 390, 844]]) {
          const other = await browser.newContext({ viewport: { width, height }, locale: 'en-GB', colorScheme: scheme, storageState: state, ...(width < 500 ? { isMobile: true, hasTouch: true } : {}) });
          const p2 = await other.newPage(); watch(p2);
          try {
            await p2.goto(`${BASE}/admin.php?module=quotes&action=prices&collection=${idk}`, { waitUntil: 'networkidle' });
            if ((await p2.locator(`[data-price-row^="${idp}:"]`).count()) !== 3) { throw new Error(`the product and its two variants are not in the grid (${label}, ${scheme})`); }
            if (!(await p2.locator('nav.zalozky-hub a[aria-current="page"]', { hasText: 'Internal prices' }).count())) { throw new Error('the Internal prices tab is not current'); }
            const wide = await p2.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
            if (wide > 1) { throw new Error(`${wide} px of sideways scroll on Internal prices (${label}, ${scheme})`); }
            if (SHOTS) { await p2.screenshot({ path: `${SHOTS}/338a-prices-${label}-${scheme}.png`, fullPage: true }); }
          } finally {
            await other.close();
          }
        }
      }
      const axeContext = await browser.newContext({ storageState: state, bypassCSP: true, viewport: { width: 1440, height: 900 } });
      try {
        const axePage = await axeContext.newPage();
        for (const scheme of ['light', 'dark']) {
          await axePage.emulateMedia({ colorScheme: scheme });
          await axePage.goto(`${BASE}/admin.php?module=quotes&action=prices&collection=${idk}`, { waitUntil: 'networkidle' });
          await axePage.addScriptTag({ path: AXE });
          const found = await axePage.evaluate(([inc, tags]) => window.axe.run({ include: inc.map((x) => [x]) }, { runOnly: { type: 'tag', values: tags }, resultTypes: ['violations'] }), [['nav.zalozky', '[data-prices-filter]', '[data-prices-grid]'], WCAG]);
          if (found.violations.length) { throw new Error(`axe Internal prices (${scheme}): ` + found.violations.map((v) => `${v.id} ${v.nodes[0].target.join(' ')}`).join(', ')); }
        }
      } finally {
        await axeContext.close();
      }
      // a price typed the Czech way is saved as a number; the product's page on the site never shows it
      await visit(`/admin.php?module=quotes&action=prices&collection=${idk}`);
      await page.locator(`[data-price-row="${idp}:SW-B338-P"] input[name="price[]"]`).fill('76 543,21');
      await Promise.all([page.waitForNavigation(), page.locator('[data-prices-grid] button[type="submit"]').click()]);
      if (sql(`SELECT unit_price FROM ka_prices WHERE item_id = ${Number(idp)} AND variant_code = 'SW-B338-P'`).trim() !== '76543.21') { throw new Error('the price was not saved'); }
      if ((await page.locator(`[data-price-row="${idp}:SW-B338-P"] input[name="price[]"]`).inputValue()) !== '76543.21') { throw new Error('the grid does not show the saved price'); }
      await visit('/machines-b338/shock-wave-b338');
      if (/76543|76 543/.test(await page.content())) { throw new Error('the internal price is on the product page'); }
    } finally {
      sql(`DELETE FROM ka_prices WHERE item_id = ${Number(idp) || 0}; DELETE FROM ka_kolekce WHERE seo_link = 'machines-b338'; UPDATE ka_nastaveni SET hodnota = TRIM(BOTH ',' FROM REPLACE(CONCAT(',', hodnota, ','), ',quotes,', ',')) WHERE promenna = 'extensions'`);
    }
  });

  // 3.38 (agent C): the customer's quote – a sent quote's signed link opens the document in the site's look at 1440 and 390 px
  // (no sideways scroll, the Print button, axe), the printed page is A4 with the button hidden and the table header repeated,
  // a wrong token is the 404; the send dialog of a draft fits a phone in light and dark
  await step('3.38 C the quote document: 1440 and 390 px, axe, print to A4 (one or two pages), a wrong token 404; the send dialog on a phone', async () => {
    if (!MYSQL.length) { throw new Error('MYSQL_JSON is not set'); }
    sql("UPDATE ka_nastaveni SET hodnota = CONCAT(hodnota, ',quotes') WHERE promenna = 'extensions' AND NOT FIND_IN_SET('quotes', hodnota); REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('quote_vat_rate', '21'), ('quote_terms', 'Delivery within four weeks of the order. Prices include installation where stated.')");
    const ids = execFileSync('php', ['-r', `require "system/bootstrap.php"; $app = Kaleta\\Core\\App::boot(); $db = $app->db();
    $lines = []; foreach (range(1, 9) as $i) { $lines[] = ["name" => "Shock-wave therapy unit with a rather long product name C338 no. " . $i, "code" => "SW-C338-" . $i, "description" => "Applicator set, trolley and two years of service", "quantity" => (string) $i, "unit" => "pc", "unit_price" => (string) (12500 + $i * 1000), "discount_percent" => $i === 2 ? "5" : "0", "vat_rate" => "21"]; }
    $customer = ["name" => "Grace Customer", "company" => "Physio Clinic C338 Ltd", "address" => "12 Long Street\\nLondon E1 6AN", "reg_no" => "12345678", "vat_id" => "GB123456789", "email" => "grace.c338@customer.example"];
    $sent = Kaleta\\Core\\Quotes::finalise($app, Kaleta\\Core\\Quotes::save($app, null, ["customer" => $customer, "title" => "Shock-wave units for the clinic", "lines" => $lines, "discount_percent" => "3", "valid_days" => 21], 0)["id"], 0);
    $draft = Kaleta\\Core\\Quotes::save($app, null, ["customer" => $customer, "title" => "A draft to send", "lines" => array_slice($lines, 0, 2)], 0)["id"];
    echo $sent["id"], " ", $draft, " ", Kaleta\\Core\\QuoteDelivery::issueLink($app, $sent["id"], 0);`], { cwd: process.env.WEB_DIR, encoding: 'utf8' }).trim().split(' ');
    const [quoteId, draftId, link] = ids;
    try {
      if (!/\/quote\/[a-f0-9]{64}$/.test(link || '')) { throw new Error(`no link: ${ids.join(' ')}`); }
      for (const [label, width, height] of [['desktop', 1440, 900], ['phone', 390, 844]]) {
        // axe is injected into the desktop page – the document's own CSP allows only the site's scripts, so that context bypasses it
        const other = await browser.newContext({ viewport: { width, height }, locale: 'en-GB', ...(width < 500 ? { isMobile: true, hasTouch: true } : { bypassCSP: true }) });
        const p2 = await other.newPage(); watch(p2);
        try {
          const response = await p2.goto(link, { waitUntil: 'networkidle' });
          if (response.status() !== 200 || !/no-store/.test(response.headers()['cache-control'] || '')) { throw new Error(`the link answers ${response.status()} ${response.headers()['cache-control']}`); }
          if (!/^Quotation \S+/.test(await p2.locator('h1').innerText())) { throw new Error(`the heading is ${await p2.locator('h1').innerText()}`); }
          if (!(await p2.locator('[data-quote-print]').isVisible())) { throw new Error('the Print button is not shown'); }
          if ((await p2.locator('.quote-lines tbody tr').count()) !== 9) { throw new Error('not every line is in the document'); }
          const wide = await p2.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
          if (wide > 1) { throw new Error(`${wide} px of sideways scroll on the quote (${label})`); }
          if (SHOTS) { await p2.screenshot({ path: `${SHOTS}/338c-quote-${label}.png`, fullPage: true }); }
          if (label === 'desktop') {
            await p2.addScriptTag({ path: AXE });
            const found = await p2.evaluate(([inc, tags]) => window.axe.run({ include: inc.map((x) => [x]) }, { runOnly: { type: 'tag', values: tags }, resultTypes: ['violations'] }), [['.quote', '.quote-tools'], WCAG]);
            if (found.violations.length) { throw new Error('axe quote: ' + found.violations.map((v) => `${v.id} ${v.nodes[0].target.join(' ')}`).join(', ')); }
            await p2.emulateMedia({ media: 'print' });
            const printed = await p2.evaluate(() => [getComputedStyle(document.querySelector('.quote-tools')).display, getComputedStyle(document.querySelector('.quote-lines thead')).display]);
            if (printed.join() !== 'none,table-header-group') { throw new Error(`print: ${printed.join()}`); }
            const pdf = (await p2.pdf({ preferCSSPageSize: true, printBackground: true })).toString('latin1');
            const pages = (pdf.match(/\/Type\s*\/Page[^s]/g) || []).length;
            const box = (pdf.match(/\/MediaBox\s*\[\s*0\s+0\s+([\d.]+)\s+([\d.]+)/) || []).slice(1).map(Number);
            if (!pdf.startsWith('%PDF') || pages < 1 || pages > 2 || Math.round(box[0]) !== 595 || Math.round(box[1]) !== 842) { throw new Error(`the printed quote: ${pages} pages, page box ${box.join('×')}`); }
          }
        } finally {
          await other.close();
        }
      }
      const wrong = await page.goto(link.replace(/[a-f0-9]{64}$/, 'f'.repeat(64)));
      if (wrong.status() !== 404 || /Quotation|Grace Customer/.test(await page.content())) { throw new Error(`a wrong token answers ${wrong.status()}`); }
      // the send dialog of a draft (no SMTP on this site: finalise and hand over the link) on a phone, light and dark
      const state = await context.storageState();
      for (const scheme of ['light', 'dark']) {
        const other = await browser.newContext({ viewport: { width: 390, height: 844 }, locale: 'en-GB', colorScheme: scheme, storageState: state, isMobile: true, hasTouch: true });
        const p2 = await other.newPage(); watch(p2);
        try {
          await p2.goto(`${BASE}/admin.php?module=quotes&action=send&id=${Number(draftId)}`, { waitUntil: 'networkidle' });
          if (!(await p2.locator('[data-quote-no-smtp], [data-quote-send]').count())) { throw new Error(`the send dialog is not shown (${scheme})`); }
          const wide = await p2.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
          if (wide > 1) { throw new Error(`${wide} px of sideways scroll on the send dialog (${scheme})`); }
          if (SHOTS) { await p2.screenshot({ path: `${SHOTS}/338c-send-phone-${scheme}.png`, fullPage: true }); }
        } finally {
          await other.close();
        }
      }
    } finally {
      execFileSync('php', ['-r', `require "system/bootstrap.php"; $app = Kaleta\\Core\\App::boot(); Kaleta\\Core\\QuoteDelivery::delete($app->db(), [${Number(quoteId) || 0}, ${Number(draftId) || 0}]);`], { cwd: process.env.WEB_DIR, encoding: 'utf8' });
      sql("DELETE FROM ka_quote_counters; DELETE FROM ka_nastaveni WHERE promenna IN ('quote_vat_rate', 'quote_terms'); UPDATE ka_nastaveni SET hodnota = TRIM(BOTH ',' FROM REPLACE(CONCAT(',', hodnota, ','), ',quotes,', ',')) WHERE promenna = 'extensions'");
    }
  });

  // 3.38 (agent B): a quote from an enquiry's basket – the box on the enquiry, the editor (a line without a price in red, the
  // totals asked from the server as you type), Save, Finalise through the confirmation, Mark accepted; the editor and the
  // detail at 1440 and 390 px in light and dark without sideways scroll, axe on both
  await step('3.38 quotes: New quote from an enquiry, the editor with live totals, Finalise and Mark accepted; 1440 and 390 px (light/dark, axe)', async () => {
    if (!MYSQL.length) { throw new Error('MYSQL_JSON is not set'); }
    sql("UPDATE ka_nastaveni SET hodnota = CONCAT(hodnota, ',quotes') WHERE promenna = 'extensions' AND NOT FIND_IN_SET('quotes', hodnota);"
      + " INSERT INTO ka_nastaveni (promenna, hodnota) VALUES ('quote_vat_rate', '21') ON DUPLICATE KEY UPDATE hodnota = VALUES(hodnota);"
      + " INSERT INTO ka_kolekce (nazev, seo_link, pole, detail, preset) VALUES ('Lasers C338', 'lasers-c338', '[{\"klic\":\"code\",\"popisek\":\"Code\",\"typ\":\"text\"}]', 1, 'products');"
      + " INSERT INTO ka_kolekce_polozky (idk, nazev, seo_link, data, datum, zobrazit) SELECT idk, 'Therapy laser C338', 'laser-c338', '{\"code\":\"LC338\"}', NOW(), 1 FROM ka_kolekce WHERE seo_link = 'lasers-c338';"
      + " INSERT INTO ka_price_lists (name, currency, is_default, note, created_at, updated_at) SELECT 'Standard C338', 'EUR', 1, '', NOW(), NOW() FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM ka_price_lists WHERE is_default = 1);"
      + " INSERT INTO ka_prices (price_list_id, item_id, variant_code, code, label, unit_price, unit, note, updated_at) SELECT l.id, p.idp, '', 'LC338', '', 2500.00, 'pc', '', NOW() FROM ka_price_lists l, ka_kolekce_polozky p WHERE l.is_default = 1 AND p.seo_link = 'laser-c338';"
      + " INSERT INTO ka_contacts (email, email_norm, name, source, created_at, updated_at) VALUES ('buyer-c338@example.com', 'buyer-c338@example.com', 'Beatrix Buyer', 'manual', NOW(), NOW());"
      + " INSERT INTO ka_poptavky (datum, formular, tema, email, contact_id, stav, data) SELECT NOW(), 'Enquiry C338', 'Lasers', 'buyer-c338@example.com', id, 0, '[[\"Basket\",\"2 \\\\u00d7 Therapy laser C338 (LC338)\\\\n1 \\\\u00d7 A mystery box\"]]' FROM ka_contacts WHERE email = 'buyer-c338@example.com'");
    const enq = sql("SELECT MAX(idp) FROM ka_poptavky WHERE formular = 'Enquiry C338'").trim();
    let id = 0;
    try {
      await visit(`/admin.php?module=enquiries&action=detail&id=${Number(enq)}`);
      await Promise.all([page.waitForNavigation(), page.locator('[data-quotes-box="enquiry"] [data-quote-new-here] button').click()]);
      id = Number(new URL(page.url()).searchParams.get('id'));
      if (!id || !(await page.locator('[data-quote-editor]').count())) { throw new Error('New quote did not open the editor'); }
      if ((await page.locator('[data-quote-line]').count()) !== 2 || !(await page.locator('[data-quote-flag="unpriced"]').count())) { throw new Error('the basket did not become a priced line and a line without a price'); }
      // the totals are the server's: a changed quantity updates the line and the sum without saving
      await page.locator('[data-quote-line] input[name="line_quantity[]"]').first().fill('3');
      await page.waitForFunction(() => /7,500|7\.500|7 500/.test(document.querySelector('[data-quote-line-net]')?.textContent || ''), null, { timeout: 5000 });
      if (sql(`SELECT quantity FROM ka_quote_lines WHERE quote_id = ${id} ORDER BY position LIMIT 1`).trim() !== '2.000') { throw new Error('typing saved the quote'); }
      await page.locator('[data-quote-line] input[name="line_price[]"]').nth(1).fill('99');
      await Promise.all([page.waitForNavigation(), page.locator('[data-quote-save]').click()]);
      if (sql(`SELECT total_net FROM ka_quotes WHERE id = ${id}`).trim() !== '7599.00') { throw new Error('Save did not store the lines and totals'); }
      const state = await context.storageState();
      for (const [what, url, root] of [['editor', `/admin.php?module=quotes&action=edit&id=${id}`, '[data-quote-editor]'], ['detail', `/admin.php?module=quotes&action=detail&id=${id}`, '[data-quote-document]']]) {
        for (const scheme of ['light', 'dark']) {
          for (const [label, width, height] of [['desktop', 1440, 900], ['phone', 390, 844]]) {
            const other = await browser.newContext({ viewport: { width, height }, locale: 'en-GB', colorScheme: scheme, storageState: state, ...(width < 500 ? { isMobile: true, hasTouch: true } : {}) });
            const p2 = await other.newPage(); watch(p2);
            try {
              await p2.goto(BASE + url, { waitUntil: 'networkidle' });
              if (!(await p2.locator(root).count())) { throw new Error(`the ${what} did not open (${label}, ${scheme})`); }
              const wide = await p2.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
              if (wide > 1) { throw new Error(`${wide} px of sideways scroll on the quote ${what} (${label}, ${scheme})`); }
              if (SHOTS) { await p2.screenshot({ path: `${SHOTS}/338b-quote-${what}-${label}-${scheme}.png`, fullPage: true }); }
            } finally {
              await other.close();
            }
          }
        }
        const axeContext = await browser.newContext({ storageState: state, bypassCSP: true, viewport: { width: 1440, height: 900 } });
        try {
          const axePage = await axeContext.newPage();
          for (const scheme of ['light', 'dark']) {
            await axePage.emulateMedia({ colorScheme: scheme });
            await axePage.goto(BASE + url, { waitUntil: 'networkidle' });
            await axePage.addScriptTag({ path: AXE });
            const found = await axePage.evaluate(([inc, tags]) => window.axe.run({ include: inc.map((x) => [x]) }, { runOnly: { type: 'tag', values: tags }, resultTypes: ['violations'] }), [['[data-record-bar]', '.zaznam'], WCAG]);
            if (found.violations.length) { throw new Error(`axe quote ${what} (${scheme}): ` + found.violations.map((v) => `${v.id} ${v.nodes[0].target.join(' ')}`).join(', ')); }
          }
        } finally {
          await axeContext.close();
        }
      }
      // Finalise asks first (the custom dialog), then the number; Mark accepted from the detail's bar
      await visit(`/admin.php?module=quotes&action=edit&id=${id}`);
      await page.locator('[data-quote-finalise]').click();
      await Promise.all([page.waitForNavigation(), page.locator('dialog.potvrzeni [data-ano]').click()]);
      if (!/^\d{4}-\d{4}$/.test(sql(`SELECT number FROM ka_quotes WHERE id = ${id} AND status = 'sent'`).trim())) { throw new Error('Finalise gave no number'); }
      await Promise.all([page.waitForNavigation(), page.locator('[data-quote-accept] button').click()]);
      if (sql(`SELECT status FROM ka_quotes WHERE id = ${id}`).trim() !== 'accepted') { throw new Error('Mark accepted did not accept the quote'); }
      await visit('/lasers-c338/laser-c338');
      if (/2,500\.00|2500\.00|7599/.test(await page.content())) { throw new Error('a quote price is on the product page'); }
    } finally {
      sql(`DELETE FROM ka_quote_lines WHERE quote_id IN (SELECT id FROM ka_quotes WHERE enquiry_id = ${Number(enq) || 0}); DELETE FROM ka_quotes WHERE enquiry_id = ${Number(enq) || 0}; DELETE FROM ka_quote_counters;`
        + ` DELETE FROM ka_prices WHERE code = 'LC338'; DELETE FROM ka_kolekce_polozky WHERE seo_link = 'laser-c338'; DELETE FROM ka_kolekce WHERE seo_link = 'lasers-c338'; DELETE FROM ka_poptavky WHERE formular = 'Enquiry C338';`
        + ` DELETE FROM ka_contacts WHERE email = 'buyer-c338@example.com'; DELETE FROM ka_nastaveni WHERE promenna = 'quote_vat_rate';`
        + ` UPDATE ka_nastaveni SET hodnota = TRIM(BOTH ',' FROM REPLACE(CONCAT(',', hodnota, ','), ',quotes,', ',')) WHERE promenna = 'extensions'`);
    }
  });

  // 3.39 B (N110, N112): a note written through a drafts-only connection is marked as a proposal in the Notebook list and its form;
  // the form of a blocked account offers, ticked, to revoke its Claude connections on unblocking – 1440 and 390 px, light/dark, axe
  await step('3.39 B: a notebook proposal and the unblocking box of a blocked account (1440 and 390 px, light/dark, axe)', async () => {
    if (!MYSQL.length) { throw new Error('MYSQL_JSON is not set'); }
    sql("INSERT INTO ka_notebook (topic, title, text, pinned, author, via, access, created_at, updated_at) VALUES ('decisions', 'Proposal B339', 'Always publish at once.', 0, 'Drafts B339', 'Drafts B339', 'drafts', NOW(), NOW());"
      + " INSERT INTO ka_uzivatele (user, password, jmeno, email, admin, blokovat) VALUES ('blocked-b339', '!', 'Blocked B339', 'blocked-b339@browser.example', 1, 1)");
    const note = Number(sql("SELECT id FROM ka_notebook WHERE title = 'Proposal B339'").trim());
    const user = Number(sql("SELECT idu FROM ka_uzivatele WHERE user = 'blocked-b339'").trim());
    sql(`INSERT INTO ka_api_tokeny (idu, nazev, otisk, vytvoren) VALUES (${user}, 'Token B339', SHA2('b339-${Date.now()}', 256), NOW())`);
    try {
      const state = await context.storageState();
      for (const scheme of ['light', 'dark']) {
        for (const [label, width, height] of [['desktop', 1440, 900], ['phone', 390, 844]]) {
          const other = await browser.newContext({ viewport: { width, height }, locale: 'en-GB', colorScheme: scheme, storageState: state, ...(width < 500 ? { isMobile: true, hasTouch: true } : {}) });
          const p2 = await other.newPage(); watch(p2);
          try {
            await p2.goto(`${BASE}/admin.php?module=notebook&topic=decisions`, { waitUntil: 'networkidle' });
            const badge = p2.locator('table.vypis tr', { hasText: 'Proposal B339' }).locator('.stitek', { hasText: 'proposal' });
            if ((await badge.count()) !== 1 || !(await badge.isVisible())) { throw new Error(`the proposal badge is missing (${label}, ${scheme})`); }
            let wide = await p2.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
            if (wide > 1) { throw new Error(`${wide} px of sideways scroll on the Notebook (${label}, ${scheme})`); }
            await p2.goto(`${BASE}/admin.php?module=notebook&action=edit&id=${note}`, { waitUntil: 'networkidle' });
            if (!(await p2.locator('.hlaska-varovani', { hasText: 'drafts-only connection Drafts B339' }).isVisible())) { throw new Error(`the proposal notice is missing in the form (${label}, ${scheme})`); }
            if (SHOTS) { await p2.screenshot({ path: `${SHOTS}/339b-proposal-${label}-${scheme}.png`, fullPage: true }); }
            await p2.goto(`${BASE}/admin.php?module=users&action=edit&id=${user}`, { waitUntil: 'networkidle' });
            const box = p2.locator('input[type="checkbox"][name="zrusit_pripojeni"]');
            if ((await box.count()) !== 1 || !(await box.isVisible()) || !(await box.isChecked())) { throw new Error(`the unblocking box is missing or not ticked (${label}, ${scheme})`); }
            wide = await p2.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
            if (wide > 1) { throw new Error(`${wide} px of sideways scroll on the user form (${label}, ${scheme})`); }
            if (SHOTS) { await p2.screenshot({ path: `${SHOTS}/339b-unblock-${label}-${scheme}.png`, fullPage: true }); }
          } finally {
            await other.close();
          }
        }
      }
      const axeContext = await browser.newContext({ storageState: state, bypassCSP: true, viewport: { width: 1440, height: 900 } });
      try {
        const axePage = await axeContext.newPage();
        for (const scheme of ['light', 'dark']) {
          await axePage.emulateMedia({ colorScheme: scheme });
          for (const [url, include] of [['/admin.php?module=notebook&topic=decisions', ['table.vypis']], [`/admin.php?module=notebook&action=edit&id=${note}`, ['.hlaska-varovani']], [`/admin.php?module=users&action=edit&id=${user}`, ['details.pokrocile']]]) {
            await axePage.goto(BASE + url, { waitUntil: 'networkidle' });
            await axePage.addScriptTag({ path: AXE });
            const found = await axePage.evaluate(([inc, tags]) => window.axe.run({ include: inc.map((x) => [x]) }, { runOnly: { type: 'tag', values: tags }, resultTypes: ['violations'] }), [include, WCAG]);
            if (found.violations.length) { throw new Error(`axe ${url} (${scheme}): ` + found.violations.map((v) => `${v.id} ${v.nodes[0].target.join(' ')}`).join(', ')); }
          }
        }
      } finally {
        await axeContext.close();
      }
    } finally {
      sql(`DELETE FROM ka_notebook WHERE id = ${note || 0}; DELETE FROM ka_api_tokeny WHERE idu = ${user || 0}; DELETE FROM ka_uzivatele_prava WHERE fk_id_user = ${user || 0}; DELETE FROM ka_uzivatele WHERE idu = ${user || 0}`);
    }
  });

  // 3.40 (agent B): quotes II – the customer's Accept / Decline on the quote page at 1440 px (axe) and on a phone (decline with a
  // reason, no sideways scroll), the price list in the box Quotes of a company, a saved line set inserted in the editor, and the
  // detail with "by the customer", the first opening and Remind me – at 390 px in light and dark
  await step('3.40 B quotes II: Accept / Decline on the quote page (1440 axe, 390 decline), customer price list, line set, Remind me; 390 px light/dark', async () => {
    if (!MYSQL.length) { throw new Error('MYSQL_JSON is not set'); }
    sql("UPDATE ka_nastaveni SET hodnota = CONCAT(hodnota, ',quotes') WHERE promenna = 'extensions' AND NOT FIND_IN_SET('quotes', hodnota);"
      + " REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('quote_vat_rate', '21'), ('quote_customer_decision', '1'), ('quote_track_opened', '1');"
      + " INSERT INTO ka_companies (name, created_at, updated_at) VALUES ('Clinic B340 Ltd', NOW(), NOW())");
    const company = Number(sql("SELECT MAX(id) FROM ka_companies WHERE name = 'Clinic B340 Ltd'").trim());
    const ids = execFileSync('php', ['-r', `require "system/bootstrap.php"; $app = Kaleta\\Core\\App::boot(); $db = $app->db();
    $lines = [["name" => "Shock-wave unit B340", "code" => "SW-B340", "quantity" => "1", "unit" => "pc", "unit_price" => "12500", "vat_rate" => "21"], ["name" => "Installation and training B340", "quantity" => "4", "unit" => "h", "unit_price" => "90", "vat_rate" => "21"]];
    $customer = ["name" => "Grace Customer", "company" => "Clinic B340 Ltd", "address" => "12 Long Street\\nLondon E1 6AN", "email" => "grace.b340@customer.example"];
    $admin = (int) $db->value("SELECT idu FROM {uzivatele} WHERE user = ?", ["admin"]);
    $sent = Kaleta\\Core\\Quotes::finalise($app, Kaleta\\Core\\Quotes::save($app, null, ["customer" => $customer, "title" => "Shock-wave unit for the clinic", "lines" => $lines, "valid_days" => 21, "owner_id" => $admin], 0)["id"], 0);
    $draft = Kaleta\\Core\\Quotes::save($app, null, ["customer" => $customer, "title" => "A draft for a line set", "lines" => array_slice($lines, 0, 1)], 0)["id"];
    $set = Kaleta\\Core\\QuoteLineSets::fromQuote($app, $sent["id"], "Unit with installation B340", 0)["id"];
    echo $sent["id"], " ", $draft, " ", $set, " ", Kaleta\\Core\\QuoteDelivery::issueLink($app, $sent["id"], 0);`], { cwd: process.env.WEB_DIR, encoding: 'utf8' }).trim().split(' ');
    const [quoteId, draftId, setId, link] = ids;
    try {
      if (!/\/quote\/[a-f0-9]{64}$/.test(link || '')) { throw new Error(`no link: ${ids.join(' ')}`); }
      // the customer's page: the answer under the document – axe at 1440 (that context bypasses the page's own CSP for axe only)
      const wide = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'en-GB', bypassCSP: true });
      try {
        const p2 = await wide.newPage(); watch(p2);
        await p2.goto(link, { waitUntil: 'networkidle' });
        if (!(await p2.locator('[data-quote-decision="open"]').isVisible())) { throw new Error('the answer is not shown under the quote'); }
        await p2.locator('[data-quote-accept] summary').click();
        if (!(await p2.locator('[data-quote-accept] button[type="submit"]').isVisible())) { throw new Error('Accept does not unfold its confirmation'); }
        await p2.addScriptTag({ path: AXE });
        const found = await p2.evaluate(([inc, tags]) => window.axe.run({ include: inc.map((x) => [x]) }, { runOnly: { type: 'tag', values: tags }, resultTypes: ['violations'] }), [['.quote-decision'], WCAG]);
        if (found.violations.length) { throw new Error('axe quote answer: ' + found.violations.map((v) => `${v.id} ${v.nodes[0].target.join(' ')}`).join(', ')); }
        await p2.emulateMedia({ media: 'print' });
        if ((await p2.evaluate(() => getComputedStyle(document.querySelector('.quote-decision')).display)) !== 'none') { throw new Error('the answer is printed'); }
      } finally {
        await wide.close();
      }
      if (sql(`SELECT first_opened_at IS NOT NULL FROM ka_quotes WHERE id = ${Number(quoteId)}`).trim() !== '1') { throw new Error('the first opening was not recorded'); }
      // on a phone: Decline with a reason – the page thanks, the quote is declined by the customer
      const phone = await browser.newContext({ viewport: { width: 390, height: 844 }, locale: 'en-GB', isMobile: true, hasTouch: true });
      try {
        const p2 = await phone.newPage(); watch(p2);
        await p2.goto(link, { waitUntil: 'networkidle' });
        await p2.locator('[data-quote-decline] summary').click();
        await p2.locator('#quote-decline-reason').fill('We chose a smaller unit this year.');
        const sideways = await p2.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
        if (sideways > 1) { throw new Error(`${sideways} px of sideways scroll on the quote answer (phone)`); }
        if (SHOTS) { await p2.screenshot({ path: `${SHOTS}/340b-quote-answer-phone.png`, fullPage: true }); }
        await Promise.all([p2.waitForNavigation(), p2.locator('[data-quote-decline] button[type="submit"]').click()]);
        if (!(await p2.locator('[data-quote-decision="done"]').isVisible()) || (await p2.locator('[data-quote-decision="open"]').count())) { throw new Error('no thank-you after declining'); }
      } finally {
        await phone.close();
      }
      if (sql(`SELECT CONCAT_WS('|', status, customer_reason) FROM ka_quotes WHERE id = ${Number(quoteId)}`).trim() !== 'declined|We chose a smaller unit this year.') { throw new Error('the decline was not stored'); }
      // the admin: the editor inserts the saved line set; the detail says "by the customer" and Remind me adds a task
      await visit(`/admin.php?module=quotes&action=edit&id=${Number(draftId)}`);
      await page.locator('#nabidka-sada').selectOption(String(Number(setId)));
      await Promise.all([page.waitForNavigation(), page.locator('[data-quote-insert-set]').click()]);
      if (sql(`SELECT COUNT(*) FROM ka_quote_lines WHERE quote_id = ${Number(draftId)}`).trim() !== '3') { throw new Error('the line set was not inserted'); }
      await visit(`/admin.php?module=quotes&action=detail&id=${Number(quoteId)}`);
      if (!(await page.locator('[data-quote-customer-reason]').isVisible()) || !(await page.locator('[data-quote-opened]').count())) { throw new Error('the detail does not show the customer\'s answer or the first opening'); }
      await Promise.all([page.waitForNavigation(), page.locator('[data-quote-follow-up] button[type="submit"]').click()]);
      if (!(await page.locator(`[data-tasks="quote:${Number(quoteId)}"] [data-task]`).count())) { throw new Error('Remind me did not add a task about the quote'); }
      const state = await context.storageState();
      for (const scheme of ['light', 'dark']) {
        const other = await browser.newContext({ viewport: { width: 390, height: 844 }, locale: 'en-GB', colorScheme: scheme, storageState: state, isMobile: true, hasTouch: true });
        const p2 = await other.newPage(); watch(p2);
        try {
          for (const [what, url, root] of [['detail', `/admin.php?module=quotes&action=detail&id=${Number(quoteId)}`, '[data-quote-follow-up]'], ['company', `/admin.php?module=contacts&action=company&id=${company}`, '[data-quote-customer-list]'],
            ['editor', `/admin.php?module=quotes&action=edit&id=${Number(draftId)}`, '[data-quote-line-sets]'], ['setup', '/admin.php?module=quotes&action=setup', '[data-quotes-line-sets]']]) {
            await p2.goto(BASE + url, { waitUntil: 'networkidle' });
            if (!(await p2.locator(root).count())) { throw new Error(`the ${what} lacks ${root} (${scheme})`); }
            const sideways = await p2.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
            if (sideways > 1) { throw new Error(`${sideways} px of sideways scroll on the quote ${what} (phone, ${scheme})`); }
            if (SHOTS) { await p2.screenshot({ path: `${SHOTS}/340b-${what}-phone-${scheme}.png`, fullPage: true }); }
          }
        } finally {
          await other.close();
        }
      }
    } finally {
      execFileSync('php', ['-r', `require "system/bootstrap.php"; $app = Kaleta\\Core\\App::boot(); Kaleta\\Core\\QuoteDelivery::delete($app->db(), [${Number(quoteId) || 0}, ${Number(draftId) || 0}]);`], { cwd: process.env.WEB_DIR, encoding: 'utf8' });
      sql(`DELETE FROM ka_quote_counters; DELETE FROM ka_quote_line_sets; DELETE FROM ka_tasks WHERE target LIKE 'quote:%'; DELETE FROM ka_notifications WHERE target LIKE 'quote:%'; DELETE FROM ka_companies WHERE id = ${company || 0};`
        + " DELETE FROM ka_nastaveni WHERE promenna IN ('quote_vat_rate', 'quote_customer_decision', 'quote_track_opened'); UPDATE ka_nastaveni SET hodnota = TRIM(BOTH ',' FROM REPLACE(CONCAT(',', hodnota, ','), ',quotes,', ',')) WHERE promenna = 'extensions'");
    }
  });

  // 3.40 (agent A): the quote as a PDF – "Download PDF" on the customer's page (390 px, beside Print, axe on the tools) and in
  // the More menu of the quote's detail; both download <number>.pdf, a real PDF file
  await step('3.40 A Download PDF: the customer\'s page at 390 px and the quote\'s detail both download <number>.pdf', async () => {
    if (!MYSQL.length) { throw new Error('MYSQL_JSON is not set'); }
    sql("UPDATE ka_nastaveni SET hodnota = CONCAT(hodnota, ',quotes') WHERE promenna = 'extensions' AND NOT FIND_IN_SET('quotes', hodnota); REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('quote_vat_rate', '21')");
    const ids = execFileSync('php', ['-r', `require "system/bootstrap.php"; $app = Kaleta\\Core\\App::boot(); $db = $app->db();
    $lines = [["name" => "Therapy laser A340", "code" => "TL-A340", "quantity" => "2", "unit" => "pc", "unit_price" => "12500", "vat_rate" => "21"], ["name" => "Installation and training", "quantity" => "1", "unit" => "h", "unit_price" => "900", "vat_rate" => "21"]];
    $sent = Kaleta\\Core\\Quotes::finalise($app, Kaleta\\Core\\Quotes::save($app, null, ["customer" => ["name" => "Grace A340", "company" => "Clinic A340 Ltd", "email" => "grace.a340@customer.example"], "title" => "A laser for the clinic", "lines" => $lines], 0)["id"], 0);
    echo $sent["id"], " ", $sent["number"], " ", Kaleta\\Core\\QuoteDelivery::issueLink($app, $sent["id"], 0);`], { cwd: process.env.WEB_DIR, encoding: 'utf8' }).trim().split(' ');
    const [quoteId, number, link] = ids;
    const isPdf = async (download) => {
      const chunks = [];
      for await (const chunk of await download.createReadStream()) { chunks.push(chunk); }
      const file = Buffer.concat(chunks).toString('latin1');
      return file.startsWith('%PDF-1.4') && file.trimEnd().endsWith('%%EOF');
    };
    try {
      if (!/\/quote\/[a-f0-9]{64}$/.test(link || '')) { throw new Error(`no link: ${ids.join(' ')}`); }
      const other = await browser.newContext({ viewport: { width: 390, height: 844 }, locale: 'en-GB', isMobile: true, hasTouch: true, acceptDownloads: true });
      const p2 = await other.newPage(); watch(p2);
      try {
        await p2.goto(link, { waitUntil: 'networkidle' });
        const button = p2.locator('a.quote-pdf');
        if (!(await button.isVisible()) || (await button.innerText()).trim() !== 'Download PDF') { throw new Error('Download PDF is not shown on the customer\'s page'); }
        const wide = await p2.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
        if (wide > 1) { throw new Error(`${wide} px of sideways scroll with Download PDF`); }
        if (SHOTS) { await p2.screenshot({ path: `${SHOTS}/340a-quote-phone.png` }); }
        const [download] = await Promise.all([p2.waitForEvent('download'), button.click()]);
        if (download.suggestedFilename() !== `${number}.pdf` || !(await isPdf(download))) { throw new Error(`the customer's download: ${download.suggestedFilename()}`); }
      } finally {
        await other.close();
      }
      const axeContext = await browser.newContext({ viewport: { width: 1440, height: 900 }, bypassCSP: true });
      try {
        const axePage = await axeContext.newPage();
        await axePage.goto(link, { waitUntil: 'networkidle' });
        await axePage.addScriptTag({ path: AXE });
        const found = await axePage.evaluate(([inc, tags]) => window.axe.run({ include: inc.map((x) => [x]) }, { runOnly: { type: 'tag', values: tags }, resultTypes: ['violations'] }), [['.quote-tools'], WCAG]);
        if (found.violations.length) { throw new Error('axe quote tools: ' + found.violations.map((v) => `${v.id} ${v.nodes[0].target.join(' ')}`).join(', ')); }
      } finally {
        await axeContext.close();
      }
      await visit(`/admin.php?module=quotes&action=detail&id=${Number(quoteId)}`);
      await page.locator('[data-record-more] > summary').click();
      const [download] = await Promise.all([page.waitForEvent('download'), page.locator('[data-quote-pdf]').click()]);
      if (download.suggestedFilename() !== `${number}.pdf` || !(await isPdf(download))) { throw new Error(`the admin's download: ${download.suggestedFilename()}`); }
    } finally {
      execFileSync('php', ['-r', `require "system/bootstrap.php"; $app = Kaleta\\Core\\App::boot(); Kaleta\\Core\\QuoteDelivery::delete($app->db(), [${Number(quoteId) || 0}]);`], { cwd: process.env.WEB_DIR, encoding: 'utf8' });
      sql("DELETE FROM ka_quote_counters; DELETE FROM ka_nastaveni WHERE promenna = 'quote_vat_rate'; UPDATE ka_nastaveni SET hodnota = TRIM(BOTH ',' FROM REPLACE(CONCAT(',', hodnota, ','), ',quotes,', ',')) WHERE promenna = 'extensions'");
    }
  });
};
