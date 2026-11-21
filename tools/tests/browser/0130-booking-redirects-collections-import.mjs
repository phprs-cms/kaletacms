// Kaleta browser steps 0130 (tools/test-browser.mjs runs the files of this folder in number order on one site – the order is
// the contract; tools/tests/README.md). Moved verbatim from the single file in 3.49 P1.
export default async (t) => {
  const { SHOTS, page, step, visit } = t;

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
};
