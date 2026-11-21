// Kaleta browser steps 0290 (tools/test-browser.mjs runs the files of this folder in number order on one site – the order is
// the contract; tools/tests/README.md). Moved verbatim from the single file in 3.49 P1.
export default async (t) => {
  const { BASE, SHOTS, MYSQL, sql, browser, context, page, watch, step, visit, AXE, WCAG, execFileSync } = t;

  // 3.42 (agent C): any collection feeds the internal price list – the mapping on Quotes → Price list set-up (3.45) at 1440 and 390 px (light/dark,
  // axe), the prefilled grid with "still without a price", and an enquiry from the item's page → New quote arrives priced
  await step('3.42 C collections in the price list: the mapping (1440/390, light/dark, axe), the prefilled grid, a prepared quote from an enquiry', async () => {
    if (!MYSQL.length) { throw new Error('MYSQL_JSON is not set'); }
    sql("UPDATE ka_nastaveni SET hodnota = CONCAT(hodnota, ',quotes') WHERE promenna = 'extensions' AND NOT FIND_IN_SET('quotes', hodnota);"
      + " INSERT INTO ka_kolekce (nazev, seo_link, pole, detail, preset) VALUES ('Devices C342', 'devices-c342', '[{\"klic\":\"order_code\",\"popisek\":\"Order code\",\"typ\":\"text\"},{\"klic\":\"versions\",\"popisek\":\"Versions\",\"typ\":\"radky\"},{\"klic\":\"about\",\"popisek\":\"About\",\"typ\":\"html\"}]', 1, '');"
      + " INSERT INTO ka_kolekce_polozky (idk, nazev, seo_link, data, datum, zobrazit) SELECT idk, 'Ultrasound unit C342', 'ultrasound-c342', '{\"order_code\":\"US-C342\",\"versions\":\"Portable | US-C342-P\\\\nCart | US-C342-C\"}', NOW(), 1 FROM ka_kolekce WHERE seo_link = 'devices-c342';"
      + " INSERT INTO ka_kolekce_polozky (idk, nazev, seo_link, data, datum, zobrazit) SELECT idk, 'Gel C342', 'gel-c342', '{}', NOW(), 1 FROM ka_kolekce WHERE seo_link = 'devices-c342'");
    const idk = sql("SELECT idk FROM ka_kolekce WHERE seo_link = 'devices-c342'").trim();
    const idp = sql(`SELECT idp FROM ka_kolekce_polozky WHERE idk = ${Number(idk)} AND seo_link = 'ultrasound-c342'`).trim();
    let enquiry = '';
    try {
      const state = await context.storageState();
      for (const scheme of ['light', 'dark']) {
        for (const [label, width, height] of [['desktop', 1440, 900], ['phone', 390, 844]]) {
          const other = await browser.newContext({ viewport: { width, height }, locale: 'en-GB', colorScheme: scheme, storageState: state, ...(width < 500 ? { isMobile: true, hasTouch: true } : {}) });
          const p2 = await other.newPage(); watch(p2);
          try {
            await p2.goto(`${BASE}/admin.php?module=quotes&action=setup#price-collections`, { waitUntil: 'networkidle' });
            if (!(await p2.locator(`[data-price-collection="${idk}"] select[name="code_${idk}"]`).count())) { throw new Error(`the mapping row of the collection is missing (${label}, ${scheme})`); }
            const wide = await p2.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
            if (wide > 1) { throw new Error(`${wide} px of sideways scroll on Quotes → Price list set-up (${label}, ${scheme})`); }
            if (SHOTS) { await p2.locator('[data-price-collections]').screenshot({ path: `${SHOTS}/342c-collections-${label}-${scheme}.png` }); }
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
          // 3.45 (U62): the mapping is on Price list set-up, the automatic draft stays in Settings
          for (const [action, root] of [['setup', '[data-price-collections]'], ['settings', '[data-quotes-auto-draft]']]) {
            await axePage.goto(`${BASE}/admin.php?module=quotes&action=${action}`, { waitUntil: 'networkidle' });
            await axePage.addScriptTag({ path: AXE });
            const found = await axePage.evaluate(([inc, tags]) => window.axe.run({ include: inc.map((x) => [x]) }, { runOnly: { type: 'tag', values: tags }, resultTypes: ['violations'] }), [[root], WCAG]);
            if (found.violations.length) { throw new Error(`axe the mapping (${action}, ${scheme}): ` + found.violations.map((v) => `${v.id} ${v.nodes[0].target.join(' ')}`).join(', ')); }
          }
        }
      } finally {
        await axeContext.close();
      }
      // the administrator ticks the collection, picks its code and variants fields and saves
      await visit('/admin.php?module=quotes&action=setup');
      const row = page.locator(`[data-price-collection="${idk}"]`);
      await row.locator('input[name="collection[]"]').check();
      await row.locator(`select[name="code_${idk}"]`).selectOption('order_code');
      await row.locator(`select[name="variants_${idk}"]`).selectOption('versions');
      await Promise.all([page.waitForNavigation(), page.locator('[data-price-collections] button[type="submit"]').click()]);
      const stored = sql("SELECT hodnota FROM ka_nastaveni WHERE promenna = 'quote_price_collections'").trim();
      // 3.44 review M1: the form stores the basket choice too (ticked by default only for a collection with item pages)
      if (!new RegExp(`^\\[\\{"collection":${Number(idk)},"code":"order_code","variants":"versions","basket":(true|false)\\}\\]$`).test(stored)) { throw new Error(`the mapping saved: ${stored}`); }
      // the grid: every item with an empty price, its versions as variants; "still without a price" filters; a price is typed
      await visit(`/admin.php?module=quotes&action=prices&collection=${idk}`);
      if ((await page.locator(`[data-price-row^="${idp}:"]`).count()) !== 3) { throw new Error('the item and its two versions are not in the grid'); }
      await Promise.all([page.waitForNavigation(), page.locator('[data-prices-missing]').click()]);
      if (!page.url().includes('missing=1') || !(await page.locator('[data-price-row$=":"]', { hasText: 'Gel C342' }).count())) { throw new Error('the link "still without a price" does not filter'); }
      await page.locator(`[data-price-row="${idp}:US-C342-P"] input[name="price[]"]`).fill('12 345,60');
      await Promise.all([page.waitForNavigation(), page.locator('[data-prices-grid] button[type="submit"]').click()]);
      if (sql(`SELECT unit_price FROM ka_prices WHERE item_id = ${Number(idp)} AND variant_code = 'US-C342-P'`).trim() !== '12345.60') { throw new Error('the price of the version was not saved'); }
      // an enquiry from the item's page names the version in a field → New quote on the enquiry arrives with that one line, priced
      sql(`INSERT INTO ka_poptavky (datum, formular, zdroj, stranka, email, data, stav) VALUES (NOW(), 'Enquiry C342', 'kolekce:${Number(idk)}', '/devices-c342/ultrasound-c342', 'clinic@c342.example', '[["Version","ultrasound unit c342 - Portable"]]', 0)`);
      enquiry = sql("SELECT MAX(idp) FROM ka_poptavky WHERE formular = 'Enquiry C342'").trim();
      await visit(`/admin.php?module=enquiries&action=detail&id=${Number(enquiry)}`);
      await Promise.all([page.waitForNavigation(), page.locator('[data-quote-new-here] button[type="submit"]').click()]);
      const codes = await page.locator('input[name="line_code[]"]').evaluateAll((els) => els.map((e) => e.value));
      const prices = await page.locator('input[name="line_price[]"]').evaluateAll((els) => els.map((e) => e.value));
      if (codes.join(' ') !== 'US-C342-P' || prices.join(' ') !== '12345.60') { throw new Error(`the prepared quote: ${codes.join(' ')} / ${prices.join(' ')}`); }
      await visit('/devices-c342/ultrasound-c342');
      if (/12345|12 345/.test(await page.content())) { throw new Error('the internal price is on the item page'); }
    } finally {
      sql(`DELETE l FROM ka_quote_lines l JOIN ka_quotes q ON q.id = l.quote_id WHERE q.enquiry_id = ${Number(enquiry) || 0}; DELETE FROM ka_quotes WHERE enquiry_id = ${Number(enquiry) || 0};`
        + ` DELETE FROM ka_poptavky WHERE formular = 'Enquiry C342'; DELETE FROM ka_prices WHERE item_id = ${Number(idp) || 0}; DELETE FROM ka_kolekce_polozky WHERE idk = ${Number(idk) || 0};`
        + " DELETE FROM ka_kolekce WHERE seo_link = 'devices-c342'; DELETE FROM ka_nastaveni WHERE promenna = 'quote_price_collections';"
        + " UPDATE ka_nastaveni SET hodnota = TRIM(BOTH ',' FROM REPLACE(CONCAT(',', hodnota, ','), ',quotes,', ',')) WHERE promenna = 'extensions'");
    }
  });

  // 3.44 A (owner decision 2): the enquiry basket works for a collection mapped to the internal price list – a visitor picks
  // a variant and a quantity with Add to enquiry on the item page (no Compare box: no parameters), the basket lists it, the
  // form sends it with the quantity (rebuilt by the server); 1440 and 390 px without sideways scroll, axe; never a price
  await step('3.44 A: the enquiry basket on a collection in the price list – Add to enquiry, the quantity, sending (1440/390, axe), never a price', async () => {
    if (!MYSQL.length) { throw new Error('MYSQL_JSON is not set'); }
    const setup = execFileSync('php', ['-r', `require "system/bootstrap.php"; $app = Kaleta\\Core\\App::boot(); $db = $app->db();
    $ext = $app->settings()->get("extensions"); if (!in_array("quotes", explode(",", $ext), true)) { $app->settings()->set("extensions", trim($ext . ",quotes", ",")); }
    $idk = $db->insert("kolekce", ["nazev" => "Pumps A344", "seo_link" => "pumps-a344", "detail" => 1, "preset" => "", "zmeneno" => date("Y-m-d H:i:s"),
      "pole" => json_encode([["klic" => "order_code", "popisek" => "Order code", "typ" => "text"], ["klic" => "versions", "popisek" => "Versions", "typ" => "radky"]])]);
    $db->insert("kolekce_polozky", ["idk" => $idk, "nazev" => "Pump A344", "seo_link" => "pump-a344", "datum" => date("Y-m-d H:i:s"), "zobrazit" => 1, "data" => json_encode(["order_code" => "PU-A344", "versions" => "Steel | PU-A344-S\\nBrass | PU-A344-B"])]);
    $app->settings()->set("quote_price_collections", json_encode([["collection" => $idk, "code" => "order_code", "variants" => "versions"]]));
    [$build] = Kaleta\\Builder\\Build::sanitize(["v" => 1, "deti" => [["typ" => "sekce", "deti" => [["typ" => "nadpis", "znacka" => "h1", "obsah" => ["text" => "{{nazev}}"]], ["typ" => "do_poptavky"],
      ["typ" => "formular", "obsah" => ["nazev" => "Basket A344", "bez_captcha" => true, "pole" => [["popisek" => "Products", "typ" => "kosik", "povinne" => true], ["popisek" => "Email", "typ" => "email", "povinne" => true]]]]]]]]);
    $db->update("kolekce", ["stavba" => Kaleta\\Builder\\Build::toJson($build)], ["idk" => $idk]);
    $list = (int) $db->value("SELECT id FROM {price_lists} WHERE is_default = 1 ORDER BY id LIMIT 1");
    if ($list === 0) { $list = Kaleta\\Core\\Prices::saveList($app, null, "Default A344", "CZK"); }
    $item = (int) $db->value("SELECT idp FROM {kolekce_polozky} WHERE seo_link = 'pump-a344'");
    Kaleta\\Core\\Prices::save($app, $list, ["item_id" => $item, "variant_code" => "PU-A344-B", "unit_price" => "76543.21"], 0, false);
    Kaleta\\Core\\PageCache::clear(); echo $idk;`], { cwd: process.env.WEB_DIR, encoding: 'utf8' }).trim();
    const idk = Number(setup) || 0;
    try {
      for (const [label, width, height] of [['desktop', 1440, 900], ['phone', 390, 844]]) {
        const visitor = await browser.newContext({ viewport: { width, height }, locale: 'en-GB', ...(width < 500 ? { isMobile: true, hasTouch: true } : {}) });
        const p2 = await visitor.newPage(); watch(p2);
        try {
          await p2.goto(`${BASE}/pumps-a344/pump-a344`, { waitUntil: 'networkidle' });
          const button = p2.locator('form.ka-do-poptavky');
          if (!(await button.count()) || await button.locator('[data-porovnat]').count()) { throw new Error(`Add to enquiry is missing or offers Compare (${label})`); }
          if (/76543|76 543/.test(await p2.content())) { throw new Error(`the internal price is on the item page (${label})`); }
          await button.locator('select[name="variant"]').selectOption('Brass');
          await button.locator('input[name="quantity"]').fill('3');
          await button.locator('button[type="submit"]').click();
          await p2.locator('[data-kosik-seznam] li').first().waitFor({ timeout: 5000 });
          const line = (await p2.locator('[data-kosik-seznam]').innerText()).replace(/\s+/g, ' ');
          if (!/Pump A344/.test(line) || !/Brass/.test(line)) { throw new Error(`the basket lists: ${line} (${label})`); }
          const wide = await p2.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
          if (wide > 1) { throw new Error(`${wide} px of sideways scroll on the item page with the basket (${label})`); }
          if (SHOTS) { await p2.screenshot({ path: `${SHOTS}/344a-basket-${label}.png`, fullPage: true }); }
          if (label === 'desktop') {
            await p2.addScriptTag({ path: AXE });
            const found = await p2.evaluate(([tags]) => window.axe.run({ include: [['form.ka-do-poptavky'], ['.ka-kosik-pole']] }, { runOnly: { type: 'tag', values: tags }, resultTypes: ['violations'] }), [WCAG]);
            if (found.violations.length) { throw new Error('axe the basket: ' + found.violations.map((v) => `${v.id} ${v.nodes[0].target.join(' ')}`).join(', ')); }
            await p2.locator('input[type="email"]').fill('pumps.a344@visitor.example');
            await p2.waitForTimeout(4500); // the form's antispam wants a few seconds on the page
            await Promise.all([p2.waitForNavigation(), p2.locator('form.ka-formular button[type="submit"], form[data-formular] button[type="submit"]').last().click()]);
            // the mysql client may print × and – in another charset: the line is matched around them
            const data = sql("SELECT data FROM ka_poptavky WHERE email = 'pumps.a344@visitor.example' ORDER BY idp DESC LIMIT 1");
            if (!/"3 \S{1,3} Pump A344 \S{1,3} Brass \(PU-A344-B\)"/.test(data)) { throw new Error(`the enquiry has: ${data.slice(0, 300)}`); }
            if (data.includes('76543')) { throw new Error('the enquiry carries the internal price'); }
          }
        } finally {
          await visitor.close();
        }
      }
    } finally {
      sql(`DELETE FROM ka_poptavky WHERE email = 'pumps.a344@visitor.example'; DELETE p FROM ka_prices p JOIN ka_kolekce_polozky i ON i.idp = p.item_id WHERE i.idk = ${idk};`
        + ` DELETE FROM ka_kolekce_polozky WHERE idk = ${idk}; DELETE FROM ka_kolekce WHERE idk = ${idk}; DELETE FROM ka_nastaveni WHERE promenna = 'quote_price_collections';`
        + " UPDATE ka_nastaveni SET hodnota = TRIM(BOTH ',' FROM REPLACE(CONCAT(',', hodnota, ','), ',quotes,', ',')) WHERE promenna = 'extensions'");
      execFileSync('sh', ['-c', 'rm -f "$WEB_DIR"/storage/cache/stranky/*.html'], { env: process.env });
    }
  });

  await step('3.44 B quote editor: Up/Down and Remove in place, the unsaved-changes dialog (editor, price grid paging, stages), the check before Save and send, the send summary', async () => {
    if (!MYSQL.length) { throw new Error('MYSQL_JSON is not set'); }
    sql("UPDATE ka_nastaveni SET hodnota = CONCAT(hodnota, ',quotes') WHERE promenna = 'extensions' AND NOT FIND_IN_SET('quotes', hodnota);"
      + " INSERT INTO ka_kolekce (nazev, seo_link, pole, detail, preset) VALUES ('Parts C344', 'parts-c344', '[{\"klic\":\"code\",\"popisek\":\"Code\",\"typ\":\"text\"}]', 1, 'products');"
      + " INSERT INTO ka_price_lists (name, currency, is_default, note, created_at, updated_at) SELECT 'Standard C344', 'EUR', 1, '', NOW(), NOW() FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM ka_price_lists WHERE is_default = 1)");
    const idk = sql("SELECT idk FROM ka_kolekce WHERE seo_link = 'parts-c344'").trim();
    // 55 parts: the grid has two pages (Core\Prices::PAGE = 50)
    sql('INSERT INTO ka_kolekce_polozky (idk, nazev, seo_link, data, datum, zobrazit) VALUES ' + Array.from({ length: 55 }, (_, i) => `(${Number(idk)}, 'Part C344 ${String(i + 1).padStart(2, '0')}', 'part-c344-${i + 1}', '{"code":"PC344-${i + 1}"}', NOW(), 1)`).join(', '));
    let id = 0;
    try {
      await visit('/admin.php?module=quotes');
      await Promise.all([page.waitForNavigation(), page.locator('[data-quote-create] button').click()]);
      id = Number(new URL(page.url()).searchParams.get('id'));
      if (!/^Draft quote/.test(((await page.locator('h1').first().textContent()) || '').trim())) { throw new Error('a draft is not titled "Draft quote"'); }
      for (const [name, price] of [['Alpha C344', '10'], ['Beta C344', '20'], ['Gamma C344', '30']]) {
        await page.locator('#nabidka-volny').fill(name); await page.locator('#nabidka-volny-cena').fill(price);
        await Promise.all([page.waitForNavigation(), page.locator('[data-quote-add-line]').click()]);
      }
      if (new URL(page.url()).hash !== '#line-3') { throw new Error(`Add a line lands on ${new URL(page.url()).hash}, not on the new line`); }
      const names = () => page.locator('input[name="line_name[]"]').evaluateAll((els) => els.map((e) => e.value).join(','));
      // the keyboard: Down on the first line moves it and keeps the focus on the button; Remove greys the line until Save
      await page.locator('[data-quote-line] [data-quote-move="down"]').first().focus();
      await page.keyboard.press('Enter');
      if ((await names()) !== 'Beta C344,Alpha C344,Gamma C344') { throw new Error(`Down did not move the line: ${await names()}`); }
      if ((await page.evaluate(() => document.activeElement?.getAttribute('aria-label'))) !== 'Move line 2 down') { throw new Error('the focus did not stay on the moved line'); }
      await page.locator('[data-quote-line]').nth(2).locator('[data-quote-remove]').click();
      if (!(await page.locator('[data-quote-line].nabidka-odebrano').count()) || ((await page.locator('[data-quote-line]').nth(2).locator('[data-quote-remove]').textContent()) || '').indexOf('Undo') !== 0) { throw new Error('Remove did not mark the line with Undo'); }
      const box = await page.locator('[data-quote-line]').nth(2).locator('[data-quote-remove]').boundingBox();
      if (!box || box.height < 24 || box.width < 24) { throw new Error('the Remove button is smaller than 24 px'); }
      // leaving by a link asks in the admin's dialog; Stay keeps the page
      await page.locator('.zaznam-lista a.navigace').first().click();
      await page.locator('dialog[data-unsaved-dialog][open]').waitFor({ timeout: 3000 });
      await page.locator('[data-unsaved-stay]').click();
      if (!page.url().includes(`id=${id}`)) { throw new Error('Stay left the editor'); }
      // Save and send without a customer stops here with the reasons and the fields marked
      await page.locator('[data-quote-send]').click();
      if (!(await page.locator('[data-quote-send-check]').isVisible()) || !(await page.locator('#nabidka-c-name[aria-invalid="true"]').count()) || page.url().includes('action=send')) { throw new Error('Save and send did not check the customer first'); }
      await page.locator('#nabidka-c-name').fill('Greta C344'); await page.locator('#nabidka-c-email').fill('greta-c344@example.com');
      await Promise.all([page.waitForNavigation(), page.locator('[data-quote-send]').click()]);
      const stored = sql(`SELECT GROUP_CONCAT(name ORDER BY position) FROM ka_quote_lines WHERE quote_id = ${id}`).trim();
      if (stored !== 'Beta C344,Alpha C344') { throw new Error(`the order and the removal were not saved: ${stored}`); }
      if (!(await page.locator('[data-quote-summary]').count()) || !/Greta C344/.test((await page.locator('[data-quote-summary]').textContent()) || '')) { throw new Error('the send page has no summary'); }
      // the price grid: a typed price and a page link → Save and open page 2 saves it and opens page 2
      await visit(`/admin.php?module=quotes&action=prices&collection=${Number(idk)}`);
      await page.locator('[data-price-row] input[name="price[]"]').first().fill('12');
      await page.locator('[data-unsaved-page="2"]').click();
      await page.locator('dialog[data-unsaved-dialog][open] [data-unsaved-save]').waitFor({ timeout: 3000 });
      await Promise.all([page.waitForNavigation(), page.locator('[data-unsaved-save]').click()]);
      if (!page.url().includes('page=2') || sql(`SELECT COUNT(*) FROM ka_prices p JOIN ka_kolekce_polozky i ON i.idp = p.item_id WHERE i.idk = ${Number(idk)} AND p.unit_price = 12.00`).trim() !== '1') { throw new Error('Save and open page 2 did not save the price and open the page'); }
      // the stages: a typed name and a tab link ask too; Leave without saving goes
      await visit('/admin.php?module=pipeline&action=stages');
      await page.locator('input[name="label[]"]').first().fill('Renamed C344');
      await page.getByRole('link', { name: 'Board', exact: true }).first().click();
      await page.locator('dialog[data-unsaved-dialog][open]').waitFor({ timeout: 3000 });
      if (await page.locator('[data-unsaved-save]').isVisible()) { throw new Error('the stages offer to save and open a page'); }
      await Promise.all([page.waitForNavigation(), page.locator('[data-unsaved-leave]').click()]);
      if (sql("SELECT COUNT(*) FROM ka_nastaveni WHERE promenna = 'pipeline_stages' AND hodnota LIKE '%Renamed C344%'").trim() !== '0') { throw new Error('leaving saved the stages'); }
      // 390 px light and dark: no sideways scroll in the editor
      const state = await context.storageState();
      for (const scheme of ['light', 'dark']) {
        const other = await browser.newContext({ viewport: { width: 390, height: 844 }, locale: 'en-GB', colorScheme: scheme, storageState: state, isMobile: true, hasTouch: true });
        const p2 = await other.newPage(); watch(p2);
        try {
          await p2.goto(BASE + `/admin.php?module=quotes&action=edit&id=${id}`, { waitUntil: 'networkidle' });
          const wide = await p2.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
          if (wide > 1) { throw new Error(`${wide} px of sideways scroll in the editor (390, ${scheme})`); }
          if (SHOTS) { await p2.screenshot({ path: `${SHOTS}/344b-quote-editor-phone-${scheme}.png`, fullPage: true }); }
        } finally {
          await other.close();
        }
      }
    } finally {
      sql(`DELETE FROM ka_quote_lines WHERE quote_id = ${id}; DELETE FROM ka_quotes WHERE id = ${id}; DELETE p FROM ka_prices p JOIN ka_kolekce_polozky i ON i.idp = p.item_id WHERE i.idk = ${Number(idk) || 0};`
        + ` DELETE FROM ka_kolekce_polozky WHERE idk = ${Number(idk) || 0}; DELETE FROM ka_kolekce WHERE seo_link = 'parts-c344';`
        + " UPDATE ka_nastaveni SET hodnota = TRIM(BOTH ',' FROM REPLACE(CONCAT(',', hodnota, ','), ',quotes,', ',')) WHERE promenna = 'extensions'");
    }
  });

  // 3.45 A (CPO 3, U62, CPO 6): the first steps of quotes on the quote list, Price list set-up and the office's first steps on
  // the dashboard at 1440 and 375 px in light and dark – no sideways scroll, axe on each block; Hide puts the quotes' list away
  await step('3.45 A: the first steps of quotes, Price list set-up and the office\'s first steps (1440/375, light/dark, axe), Hide', async () => {
    if (!MYSQL.length) { throw new Error('MYSQL_JSON is not set'); }
    const keys = "'quotes_steps_hidden', 'office_steps_hidden', 'company_name', 'extensions'";
    sql(`DROP TABLE IF EXISTS ka_b345_backup; CREATE TABLE ka_b345_backup AS SELECT * FROM ka_nastaveni WHERE promenna IN (${keys});`
      + " UPDATE ka_nastaveni SET hodnota = CONCAT(hodnota, ',quotes') WHERE promenna = 'extensions' AND NOT FIND_IN_SET('quotes', hodnota);"
      + " REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('quotes_steps_hidden', '0'), ('office_steps_hidden', '0'), ('company_name', '');"
      + " INSERT INTO ka_poptavky (datum, formular, email, data, stav) VALUES (NOW(), 'Office B345', 'office@b345.example', '[]', 0)");
    try {
      const state = await context.storageState();
      for (const scheme of ['light', 'dark']) {
        for (const [label, width, height] of [['desktop', 1440, 900], ['phone', 375, 812]]) {
          const other = await browser.newContext({ viewport: { width, height }, locale: 'en-GB', colorScheme: scheme, storageState: state, ...(width < 500 ? { isMobile: true, hasTouch: true } : {}) });
          const p2 = await other.newPage(); watch(p2);
          try {
            for (const [what, url, root] of [['quotes', '/admin.php?module=quotes', '.pruvodce-nabidky'], ['setup', '/admin.php?module=quotes&action=setup', '#price-collections'], ['dashboard', '/admin.php', 'main']]) {
              await p2.goto(BASE + url, { waitUntil: 'networkidle' });
              if (!(await p2.locator(root).count())) { throw new Error(`${what} lacks ${root} (${label}, ${scheme})`); }
              const wide = await p2.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
              if (wide > 1) { throw new Error(`${wide} px of sideways scroll on ${what} (${label}, ${scheme})`); }
              if (SHOTS) { await p2.screenshot({ path: `${SHOTS}/345a-${what}-${label}-${scheme}.png`, fullPage: true }); }
            }
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
          for (const [url, roots] of [['/admin.php?module=quotes', ['.pruvodce-nabidky', 'nav.zalozky']], ['/admin.php?module=quotes&action=setup', ['nav.zalozky', '[data-price-collections]', '[data-quotes-texts]']], ['/admin.php?module=quotes&action=prices', ['[data-prices-grid]']], ['/admin.php', ['details.pruvodce']]]) {
            await axePage.goto(BASE + url, { waitUntil: 'networkidle' });
            const present = [];
            for (const r of roots) { if (await axePage.locator(r).count()) { present.push(r); } }
            if (!present.length) { continue; }
            await axePage.addScriptTag({ path: AXE });
            const found = await axePage.evaluate(([inc, tags]) => window.axe.run({ include: inc.map((x) => [x]) }, { runOnly: { type: 'tag', values: tags }, resultTypes: ['violations'] }), [present, WCAG]);
            if (found.violations.length) { throw new Error(`axe ${url} (${scheme}): ` + found.violations.map((v) => `${v.id} ${v.nodes[0].target.join(' ')}`).join(', ')); }
          }
        }
      } finally {
        await axeContext.close();
      }
      // the steps lead somewhere real; Hide puts the quotes' list away and stays on Quotes
      await visit('/admin.php?module=quotes');
      if ((await page.locator('.pruvodce-nabidky .pruvodce-kroky li').count()) !== 6) { throw new Error('the first steps of quotes do not have six steps'); }
      await Promise.all([page.waitForNavigation(), page.locator('.pruvodce-nabidky .pruvodce-skryt button').click()]);
      if (!page.url().includes('module=quotes') || await page.locator('.pruvodce-nabidky').count()) { throw new Error('Hide did not put the first steps of quotes away'); }
      if (sql("SELECT hodnota FROM ka_nastaveni WHERE promenna = 'quotes_steps_hidden'").trim() !== '1') { throw new Error('Hide did not store the quotes\' setting'); }
    } finally {
      sql(`DELETE FROM ka_poptavky WHERE formular = 'Office B345'; DELETE FROM ka_nastaveni WHERE promenna IN (${keys}); INSERT INTO ka_nastaveni SELECT * FROM ka_b345_backup; DROP TABLE ka_b345_backup`);
    }
  });
};
