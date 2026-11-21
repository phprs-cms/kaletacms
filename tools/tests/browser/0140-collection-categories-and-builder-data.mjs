// Kaleta browser steps 0140 (tools/test-browser.mjs runs the files of this folder in number order on one site – the order is
// the contract; tools/tests/README.md). Moved verbatim from the single file in 3.49 P1.
export default async (t) => {
  const { BASE, SHOTS, sql, browser, page, watch, canvas, step, visit } = t;

  await step('3.7 collection categories: a category and a subcategory in the admin, items ticked into them, the category page', async () => {
    await visit('/admin.php?module=collections&action=new');
    await page.fill('#nazev', 'Equipment');
    await page.fill('#seo_link', 'equipment');
    await page.check('input[name="detail"]');
    await Promise.all([page.waitForNavigation(), page.locator('form.formular input[type="submit"]').first().click()]);
    t.state.equipment = Number(new URL(page.url()).searchParams.get('id'));
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
      await visit('/admin.php?module=collections&action=categories&id=' + t.state.equipment);
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
    await visit('/admin.php?module=collections&action=builder&id=' + t.state.equipment);
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
    await visit('/admin.php?module=collections&action=builder&id=' + t.state.equipment);
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
};
