// Kaleta browser steps 0300 (tools/test-browser.mjs runs the files of this folder in number order on one site – the order is
// the contract; tools/tests/README.md). Moved verbatim from the single file in 3.49 P1.
export default async (t) => {
  const { BASE, PASSWORD, SHOTS, sql, browser, context, page, watch, proof, step, visit, AXE, WCAG } = t;

  // 3.46 (N127): a save of Settings without a recent proof stops at "Confirm it is you" with the typed value kept; the password
  // there saves it, and the next save within the window asks nothing
  await step('3.46 Settings save without a recent proof: Confirm it is you, then saved with the password', async () => {
    const before = sql("SELECT IFNULL((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'agency_name'), '')").trim();
    try {
      await visit('/admin.php?module=settings&tab=agency');
      await proof(true);
      await page.fill('#agency_name', 'Agency B346');
      await Promise.all([page.waitForNavigation(), page.locator('.tlacitka input.tl[type="submit"]').first().click()]);
      if (!(await page.locator('#confirm-proof').count()) || !(await page.locator('#confirm-heslo').isVisible())) { throw new Error('no “Confirm it is you” after a save without a recent proof'); }
      if (sql("SELECT IFNULL((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'agency_name'), '')").trim() !== before) { throw new Error('the change was saved before the proof'); }
      if ((await page.locator('#confirm-proof input[type="hidden"][name="agency_name"]').getAttribute('value')) !== 'Agency B346') { throw new Error('the typed value is not in the confirmation form'); }
      if (SHOTS) { await page.screenshot({ path: `${SHOTS}/confirm-proof.png`, fullPage: true }); }
      await page.fill('#confirm-heslo', PASSWORD);
      await Promise.all([page.waitForNavigation(), page.locator('#confirm-proof button.tl[type="submit"]').click()]);
      if (sql("SELECT hodnota FROM ka_nastaveni WHERE promenna = 'agency_name'").trim() !== 'Agency B346') { throw new Error('the change was not saved after the password'); }
      if (!page.url().includes('module=settings') || !(await page.locator('.hlaska-ok, .hlaska.hlaska-ok').count())) { throw new Error(`after the password the page is ${page.url()} without “Settings saved.”`); }
      await page.fill('#agency_name', 'Agency B346 again');
      await Promise.all([page.waitForNavigation(), page.locator('.tlacitka input.tl[type="submit"]').first().click()]);
      if (await page.locator('#confirm-proof').count()) { throw new Error('the next save within the window asked again'); }
    } finally {
      sql(`REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('agency_name', '${before.replace(/'/g, "''")}')`);
    }
  });

  // 3.47 A (U68, U69): My account in six tabs – at 390 px the row gives way to a select (light/dark, no sideways scroll, axe); an
  // old link with a fragment (#claude) goes on to its tab; a browser that never signed in with a passkey gets the password as
  // the one primary button on the sign-in page
  await step('3.47 A My account: the tabs at 390 px (light/dark, axe), the #claude link goes to its tab, one primary button on sign-in', async () => {
    const state = await context.storageState();
    const tabs = ['', 'absence', 'security', 'claude', 'signature', 'colleagues'];
    for (const scheme of ['light', 'dark']) {
      const other = await browser.newContext({ viewport: { width: 390, height: 844 }, locale: 'en-GB', colorScheme: scheme, storageState: state, isMobile: true, hasTouch: true });
      const p2 = await other.newPage(); watch(p2);
      try {
        for (const tab of tabs) {
          await p2.goto(`${BASE}/admin.php?action=account${tab ? '&tab=' + tab : ''}`, { waitUntil: 'networkidle' });
          if (!(await p2.locator('form.zalozky-vyber #ucet-zalozka').isVisible()) || (await p2.locator('nav.zalozky[aria-label="My account"]').isVisible())) {
            throw new Error(`the tab select does not replace the row at 390 px (${tab || 'details'}, ${scheme})`);
          }
          if ((await p2.locator('#ucet-zalozka').inputValue()) !== tab) { throw new Error(`the select does not show the tab ${tab || 'details'}`); }
          const wide = await p2.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
          if (wide > 1) { throw new Error(`${wide} px of sideways scroll on My account (${tab || 'details'}, ${scheme})`); }
          if (SHOTS) { await p2.screenshot({ path: `${SHOTS}/347a-account-${tab || 'details'}-${scheme}.png`, fullPage: true }); }
        }
        await Promise.all([p2.waitForNavigation(), p2.selectOption('#ucet-zalozka', 'security')]);
        if (!/tab=security/.test(p2.url()) || !(await p2.locator('#two-factor').count())) { throw new Error(`the select did not open the security tab: ${p2.url()}`); }
      } finally {
        await other.close();
      }
    }
    // an old link: #claude on my details goes to the Claude tab; #notifications stays on my details
    await visit('/admin.php?action=account#claude');
    await page.waitForURL(/tab=claude#claude$/, { timeout: 10000 });
    if (!(await page.locator('#claude').count())) { throw new Error('the Claude tab has no #claude'); }
    await visit('/admin.php?action=account#notifications');
    if (/tab=/.test(page.url()) || !(await page.locator('#notifications').count())) { throw new Error(`#notifications left my details: ${page.url()}`); }
    // the absence form: the first day becomes the earliest last day
    await visit('/admin.php?action=account&tab=absence');
    const day = sql('SELECT CURDATE() + INTERVAL 20 DAY').trim();
    await page.fill('#absence-from', day);
    await page.locator('#absence-from').dispatchEvent('change');
    if ((await page.locator('#absence-to').inputValue()) !== day || (await page.locator('#absence-to').getAttribute('min')) !== day) { throw new Error('the first day does not guide the last one'); }
    const axeContext = await browser.newContext({ storageState: state, bypassCSP: true, viewport: { width: 390, height: 844 } });
    try {
      const axePage = await axeContext.newPage();
      for (const scheme of ['light', 'dark']) {
        await axePage.emulateMedia({ colorScheme: scheme });
        for (const tab of ['', 'absence', 'security']) {
          await axePage.goto(`${BASE}/admin.php?action=account${tab ? '&tab=' + tab : ''}`, { waitUntil: 'networkidle' });
          await axePage.addScriptTag({ path: AXE });
          const found = await axePage.evaluate(([tags]) => window.axe.run({ include: [['main']] }, { runOnly: { type: 'tag', values: tags }, resultTypes: ['violations'] }), [WCAG]);
          if (found.violations.length) { throw new Error(`axe My account ${tab || 'details'} (${scheme}): ` + found.violations.map((v) => `${v.id} ${v.nodes[0].target.join(' ')}`).join(', ')); }
        }
      }
    } finally {
      await axeContext.close();
    }
    // signed out, without a passkey used here before: the password is the one primary button
    const fresh = await browser.newContext({ viewport: { width: 390, height: 844 }, locale: 'en-GB' });
    try {
      const p3 = await fresh.newPage(); watch(p3);
      await p3.goto(BASE + '/admin.php', { waitUntil: 'networkidle' });
      if ((await p3.locator('.login-karta .tl').count()) !== 1 || !(await p3.locator('[data-login-password].tl').count())) { throw new Error('the sign-in page has not exactly one primary button, the password'); }
    } finally {
      await fresh.close();
    }
  });

  // 3.48 B (U40, U70, U31): the Privacy tab starts with How long data is kept; a shorter period goes to its own screen and is
  // saved only from there; Settings of sections; the stage arrows move a stage without numbers (1440 and 390 px, light and
  // dark, no sideways scroll, axe clean)
  await step('3.48 B: How long data is kept and the shorter-retention screen, Settings of sections, the stage arrows (1440 and 390 px, light/dark, axe)', async () => {
    const months = sql("SELECT IFNULL((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'enquiries_months'), '')").trim();
    try {
      sql("REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('enquiries_months', '24')");
      await page.setViewportSize({ width: 1440, height: 900 });
      await visit('/admin.php?module=settings&tab=cookies');
      if (!(await page.locator('fieldset#retention input#enquiries_months').count())) { throw new Error('the Privacy tab has no retention block with the enquiries period'); }
      await page.fill('#enquiries_months', '12');
      await Promise.all([page.waitForNavigation(), page.locator('form.formular > p.tlacitka input.tl[type="submit"]').last().click()]);
      if (!/action=retention&enquiries_months=12/.test(page.url()) || !(await page.locator('[data-retention-confirm] [data-retention-harsher]').count())) { throw new Error(`a shorter period did not lead to its screen: ${page.url()}`); }
      if (sql("SELECT hodnota FROM ka_nastaveni WHERE promenna = 'enquiries_months'").trim() !== '24') { throw new Error('the shorter period was saved before the confirmation'); }
      const confirmUrl = new URL(page.url()).pathname + new URL(page.url()).search;
      await Promise.all([page.waitForNavigation(), page.locator('[data-retention-confirm] button[value="apply"]').click()]);
      if (sql("SELECT hodnota FROM ka_nastaveni WHERE promenna = 'enquiries_months'").trim() !== '12') { throw new Error('Shorten the retention did not save it'); }
      await visit('/admin.php?module=pipeline&action=stages');
      const first = await page.locator('tr[data-stage-row] input[name="key[]"]').first().inputValue();
      await Promise.all([page.waitForNavigation(), page.locator('button[name="shift"][value="down:0"]').click()]);
      if ((await page.locator('tr[data-stage-row] input[name="key[]"]').nth(1).inputValue()) !== first) { throw new Error('Move down did not move the first stage'); }
      const box = await page.locator('button[name="shift"][value="up:1"]').boundingBox();
      if (!box || box.height < 40 || box.width < 40) { throw new Error('the arrow buttons are smaller than 40 px'); }
      await Promise.all([page.waitForNavigation(), page.locator('button[name="shift"][value="up:1"]').click()]); // and back
      if ((await page.locator('tr[data-stage-row] input[name="key[]"]').first().inputValue()) !== first) { throw new Error('Move up did not move the stage back'); }
      sql("REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('enquiries_months', '24')");
      const state = await context.storageState();
      for (const scheme of ['light', 'dark']) {
        for (const [label, width, height] of [['desktop', 1440, 900], ['phone', 390, 844]]) {
          const other = await browser.newContext({ viewport: { width, height }, locale: 'en-GB', colorScheme: scheme, storageState: state, bypassCSP: true, ...(width < 500 ? { isMobile: true, hasTouch: true } : {}) });
          const p2 = await other.newPage(); watch(p2);
          try {
            for (const url of ['/admin.php?module=settings&tab=cookies', confirmUrl, '/admin.php?module=settings&tab=sections', '/admin.php?module=pipeline&action=stages']) {
              await p2.goto(`${BASE}${url}`, { waitUntil: 'networkidle' });
              const wide = await p2.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
              if (wide > 1) { throw new Error(`${wide} px of sideways scroll on ${url} (${label}, ${scheme})`); }
              await p2.addScriptTag({ path: AXE });
              const found = await p2.evaluate(([tags]) => window.axe.run({ include: [['main']] }, { runOnly: { type: 'tag', values: tags }, resultTypes: ['violations'] }), [WCAG]);
              if (found.violations.length) { throw new Error(`axe ${url} (${label}, ${scheme}): ` + found.violations.map((v) => `${v.id} ${v.nodes[0].target.join(' ')}`).join(', ')); }
              if (SHOTS) { await p2.screenshot({ path: `${SHOTS}/348b-${url.replace(/[^a-z0-9]+/gi, '-').slice(11, 60)}-${label}-${scheme}.png`, fullPage: true }); }
            }
          } finally {
            await other.close();
          }
        }
      }
    } finally {
      sql(/^\d+$/.test(months) ? `REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('enquiries_months', '${months}')` : "DELETE FROM ka_nastaveni WHERE promenna = 'enquiries_months'");
    }
  });
};
