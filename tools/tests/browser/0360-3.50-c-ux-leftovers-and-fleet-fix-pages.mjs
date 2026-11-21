// Kaleta browser steps 0360 (tools/test-browser.mjs runs the files of this folder in number order on one site – the order is
// the contract; tools/tests/README.md). 3.50 C – the UX leftovers and Fleet: the phone menu (U51), roles grouped
// like the menu (U53), the short form of a new page (U56), the deal page on a phone (U72), the agency logo from Media and the
// fix page of a Fleet check (CPO 11), the tasks of a customer's enquiries and deals (U33). One file: the plan gave part C the
// numbers 0360–0366 and the runner takes files in steps of 10.
export default async (t) => {
  const { BASE, PASSWORD, SHOTS, axeViolations, browser, execFileSync, page, sql, step, visit, watch } = t;
  // axe needs its script in the page, which the administration's CSP refuses: the page is opened again in a context that
  // bypasses the CSP, with the signed-in session, the same size and colour scheme (as 0150)
  const axeAt = async (p, label) => {
    const size = p.viewportSize() || { width: 1440, height: 900 };
    const scheme = await p.evaluate(() => (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'));
    const ctx = await browser.newContext({ storageState: await p.context().storageState(), bypassCSP: true, viewport: size, colorScheme: scheme });
    try {
      const q = await ctx.newPage();
      await q.goto(p.url(), { waitUntil: 'networkidle' });
      return await axeViolations(q, label);
    } finally {
      await ctx.close();
    }
  };

  // 3.50 C (U51): on a phone a short menu (eight items or fewer) is one column, and View site and Sign out share one full row at the end – checked at 390 px in light and dark mode.
  await step('3.50 C U51: phone menu – a short menu is one column, View site and Sign out one full row (390 px, light and dark); the administrator\'s grouped menu keeps the footer row too', async () => {
    const user = 'phone-c350';
    const hash = execFileSync('php', ['-r', 'echo password_hash($argv[1], PASSWORD_DEFAULT);', '--', PASSWORD], { encoding: 'utf8' });
    sql(`DELETE FROM ka_uzivatele_prava WHERE fk_id_user IN (SELECT idu FROM ka_uzivatele WHERE user = '${user}'); DELETE FROM ka_uzivatele WHERE user = '${user}';
      INSERT INTO ka_uzivatele (user, password, jmeno, email, jazyk, admin, posledni_login, potvrzeno) VALUES ('${user}', '${hash}', 'Phone C350', '', 'en', 1, NOW(), NOW());
      INSERT INTO ka_uzivatele_prava (fk_id_user, ident_modulu) SELECT idu, m.ident FROM ka_uzivatele JOIN (SELECT 'pages' AS ident UNION SELECT 'media') m WHERE user = '${user}'`);
    // the footer row: View site and Sign out on one line, together as wide as the menu, the divider over both
    const footer = (p) => p.evaluate(() => {
      const box = (s) => document.querySelector(s).getBoundingClientRect();
      const menu = box('#menu'); const web = box('.menu .menu-web'); const out = box('.menu .menu-logout');
      const items = [...document.querySelectorAll('#menu > li:not(.menu-web):not(.menu-logout)')].map((li) => li.getBoundingClientRect());
      return { sameRow: Math.abs(web.top - out.top) < 1, order: web.left < out.left, span: Math.round(out.right - web.left - menu.width + parseFloat(getComputedStyle(document.querySelector('#menu')).paddingLeft || '0') * 2),
        lefts: [...new Set(items.map((r) => Math.round(r.left)))].length, widths: [...new Set(items.map((r) => Math.round(r.width)))].length,
        divider: getComputedStyle(document.querySelector('.menu .menu-logout')).borderTopStyle };
    });
    for (const [who, scheme] of [[user, 'light'], [user, 'dark'], ['admin', 'light']]) {
      const ctx = await browser.newContext({ viewport: { width: 390, height: 844 }, locale: 'en-GB', colorScheme: scheme });
      try {
        const p = await ctx.newPage(); watch(p);
        await p.goto(BASE + '/admin.php', { waitUntil: 'networkidle' });
        await p.fill('input[name="user"]', who);
        await p.fill('input[name="password"]', PASSWORD);
        await Promise.all([p.waitForNavigation(), p.press('input[name="password"]', 'Enter')]);
        await p.locator('.menu-prepinac').click();
        await p.locator('.menu .menu-logout').waitFor();
        const flat = await p.locator('#menu > .menu-sekce').count() === 0;
        if ((who === user) !== flat) { throw new Error(`${who}: the menu is ${flat ? 'flat' : 'grouped'}`); }
        const f = await footer(p);
        if (!f.sameRow || !f.order || f.divider !== 'solid') { throw new Error(`${who} (${scheme}): View site and Sign out are not one row under a divider: ${JSON.stringify(f)}`); }
        if (flat && (f.lefts !== 1 || f.widths !== 1)) { throw new Error(`${who} (${scheme}): the short menu is not one column: ${JSON.stringify(f)}`); }
        const wide = await p.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
        if (wide > 1) { throw new Error(`${who} (${scheme}): ${wide} px of sideways scroll`); }
        const found = (await axeAt(p, `${who} menu (${scheme})`)).filter((v) => /menu/.test(v) || / #menu/.test(v));
        if (found.length) { throw new Error(found.join('; ')); }
        if (SHOTS) { await p.screenshot({ path: `${SHOTS}/350-phone-menu-${who}-${scheme}.png` }); }
      } finally {
        await ctx.close();
      }
    }
    sql(`DELETE FROM ka_uzivatele_prava WHERE fk_id_user IN (SELECT idu FROM ka_uzivatele WHERE user = '${user}'); DELETE FROM ka_uzivatele WHERE user = '${user}'`);
  });

  // 3.50 C (U53): the role form groups its boxes like the menu; the boxes keep their field name and values, so a role saved from a preset gets exactly the sections it ticks.
  await step('3.50 C U53: the role form – groups in menu order, the level under its own legend, a preset saved with the same sections as before', async () => {
    await visit('/admin.php?module=roles&action=new&preset=office');
    const groups = await page.locator('fieldset.role-skupina > legend').allTextContents();
    if (groups[0] !== 'Content' || !groups.includes('Workspace') || groups.indexOf('Workspace') < groups.indexOf('Content')) { throw new Error(`the groups: ${groups.join(', ')}`); }
    if (await page.locator('legend', { hasText: 'What the role may do with content' }).count() !== 1 || await page.locator('legend', { hasText: /^News$/ }).count() !== 0) { throw new Error('the level is not under its own legend'); }
    if (await page.locator('fieldset.role-skupina[data-role-group="workspace"] input[value="contacts"]').count() !== 1) { throw new Error('Customers is not in the Workspace group'); }
    const ticked = (await page.locator('input[name="moduly[]"]:checked').evaluateAll((all) => all.map((i) => i.value))).sort().join(',');
    const found = await axeAt(page, 'role form');
    if (found.length) { throw new Error(found.join('; ')); }
    if (SHOTS) { await page.screenshot({ path: `${SHOTS}/350-roles-grouped.png`, fullPage: true }); }
    sql("DELETE FROM ka_role WHERE nazev = 'Office C350'");
    await page.fill('input[name="nazev"]', 'Office C350');
    await Promise.all([page.waitForNavigation(), page.click('form.formular input[type="submit"]')]);
    const saved = sql("SELECT moduly FROM ka_role WHERE nazev = 'Office C350'").trim().split(',').sort().join(',');
    if (saved !== ticked) { throw new Error(`saved ${saved}, ticked ${ticked}`); }
    sql("DELETE FROM ka_role WHERE nazev = 'Office C350'");
  });

  // 3.50 C (U56): a new page shows the title and the start choice; the rest folds under "More settings", which a parent from the link opens.
  await step('3.50 C U56: New page – the title and the start choice, the rest folded under More settings (opens on click, open with ?parent=)', async () => {
    await visit('/admin.php?module=pages&action=new');
    const more = page.locator('details[data-more-settings]');
    if (await more.count() !== 1 || await more.evaluate((d) => d.open)) { throw new Error('More settings is missing or open on a new page'); }
    if (!(await page.locator('#titulek').isVisible()) || !(await page.locator('#start-builder').isVisible()) || await page.locator('#nadrazena').isVisible() || await page.locator('#seo_link').isVisible()) {
      throw new Error('the new page shows more than the title and the start choice');
    }
    if (SHOTS) { await page.screenshot({ path: `${SHOTS}/350-new-page-short.png` }); }
    await more.locator('> summary').click();
    if (!(await page.locator('#nadrazena').isVisible()) || !(await page.locator('input[name="zobrazit"]').isVisible())) { throw new Error('More settings does not open on a click'); }
    const found = await axeAt(page, 'new page');
    if (found.length) { throw new Error(found.join('; ')); }
    await visit('/admin.php?module=pages&action=new&parent=1');
    if (!(await page.locator('details[data-more-settings]').evaluate((d) => d.open))) { throw new Error('a parent from the link does not open More settings'); }
  });

  // 3.50 C (U72): the deal page says its stage once, the responsible person in its panel only, one panel style in the side column, and a long title is two lines at most on a phone.
  await step('3.50 C U72: the deal page – the stage once, one Responsible person, one panel style, a long title clamped to two lines at 390 px (light and dark)', async () => {
    const title = 'Greta Lindqvist – 40 custom stainless brackets for the new terrace railing, delivered in two batches before the spring opening C350';
    const deal = Number(sql(`DELETE FROM ka_deals WHERE title LIKE '%C350'; INSERT INTO ka_deals (title, stage, created_at, updated_at) VALUES ('${title}', 'new', NOW(), NOW()); SELECT LAST_INSERT_ID();`).trim());
    try {
      await page.setViewportSize({ width: 390, height: 844 });
      for (const scheme of ['light', 'dark']) {
        await page.emulateMedia({ colorScheme: scheme });
        await visit(`/admin.php?module=pipeline&action=detail&id=${deal}`);
        if (await page.locator('[data-deal-closed]').count() !== 0 || await page.locator('select[data-deal-stage="new"]').count() !== 1) { throw new Error('the stage is said twice'); }
        if (await page.locator('[name="owner_id"]').count() !== 1) { throw new Error(`${await page.locator('[name="owner_id"]').count()} Responsible person fields`); }
        const h1 = await page.locator('h1').evaluate((h) => ({ height: h.getBoundingClientRect().height, line: parseFloat(getComputedStyle(h).lineHeight) || parseFloat(getComputedStyle(h).fontSize) * 1.3 }));
        if (h1.height > h1.line * 2 + 2) { throw new Error(`the title is ${Math.round(h1.height)} px high (line ${h1.line} px)`); }
        const panels = await page.locator('.zaznam-bok > section').evaluateAll((all) => [...new Set(all.map((s) => getComputedStyle(s).backgroundColor))]);
        if (panels.length !== 1) { throw new Error(`the side column mixes panel styles: ${panels.join(' / ')}`); }
        const wide = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
        if (wide > 1) { throw new Error(`${wide} px of sideways scroll`); }
        const found = await axeAt(page, `deal (${scheme})`);
        if (found.length) { throw new Error(found.join('; ')); }
        if (SHOTS) { await page.screenshot({ path: `${SHOTS}/350-deal-phone-${scheme}.png` }); }
      }
    } finally {
      await page.emulateMedia({ colorScheme: 'light' });
      await page.setViewportSize({ width: 1440, height: 900 });
      sql(`DELETE FROM ka_deal_stage_history WHERE deal_id = ${deal}; DELETE FROM ka_deals WHERE id = ${deal}`);
    }
  });

  // 3.50 C (CPO 11): the agency logo is chosen from Media; the field keeps the path inside the site (media/…), the form in which the setting is validated and stored.
  await step('3.50 C CPO 11: Settings → Agency – "Choose from Media", an uploaded logo fills media/…, Save stores it', async () => {
    const before = sql("SELECT IFNULL((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'agency_logo'), '')").trim();
    try {
      await visit('/admin.php?module=settings&tab=agency');
      const field = page.locator('input[name="agency_logo"]');
      const choose = page.locator('input[name="agency_logo"] ~ button', { hasText: 'Choose from Media' });
      if (await choose.count() !== 1) { throw new Error('no "Choose from Media" next to the agency logo'); }
      await choose.click();
      const picker = page.locator('dialog.galerie-okno[open]');
      await picker.waitFor();
      const png = Buffer.from((await page.evaluate(() => { const c = document.createElement('canvas'); c.width = 64; c.height = 32; const g = c.getContext('2d'); g.fillStyle = '#121212'; g.fillRect(0, 0, 64, 32); return c.toDataURL('image/png'); })).split(',')[1], 'base64');
      await picker.locator('input[type=file]').first().setInputFiles({ name: 'agency-c350.png', mimeType: 'image/png', buffer: png });
      await page.waitForFunction(() => /agency-c350/.test(document.querySelector('input[name="agency_logo"]').value), null, { timeout: 15000 });
      const value = await field.inputValue();
      if (!/^media\/[A-Za-z0-9/_.-]+\.png$/.test(value)) { throw new Error(`the field holds "${value}", not a path inside the site`); }
      if (!(await page.locator('input[name="agency_logo"] ~ img.obrazek-nahled').isVisible())) { throw new Error('no preview of the chosen logo'); }
      if (SHOTS) { await page.screenshot({ path: `${SHOTS}/350-agency-logo.png` }); }
      await Promise.all([page.waitForNavigation(), field.evaluate((f) => f.form.requestSubmit())]);
      const stored = sql("SELECT IFNULL((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'agency_logo'), '')").trim();
      if (stored !== value) { throw new Error(`stored "${stored}", the field had "${value}"`); }
    } finally {
      sql(`REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('agency_logo', '${before}')`);
    }
  });

  // 3.50 C (CPO 11): the fix page of a Fleet check – reached from the health matrix, plain links out, readable at 1440 and 390 px in light and dark mode.
  await step('3.50 C CPO 11: a Fleet check\'s fix page – from the matrix heading, the sites to fix with plain links, the fixed one ticked; no sideways scroll, axe clean (1440/390, light/dark)', async () => {
    const extensions = sql("SELECT IFNULL((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'extensions'), '')").trim();
    const beat = (age) => JSON.stringify({ version: '3.49.0', last_backup: Math.floor(Date.now() / 1000) - age, cron_last_run: Math.floor(Date.now() / 1000) - 60, auto_updates: true });
    sql(`REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('extensions', '${extensions === '' ? 'novinky,poptavky,statistika,presmerovani,claude' : extensions},fleet');
      DELETE FROM ka_fleet_sites WHERE public_key LIKE '%c350key';
      INSERT INTO ka_fleet_sites (name, url, public_key, paired_at, last_seen, version, version_since, manage_updates, up, up_status, heartbeat) VALUES
      ('C350 Fresh bakery', 'https://fresh-c350.example', 'freshc350key', NOW(), NOW() - INTERVAL 10 MINUTE, '3.49.0', NOW(), 1, 1, 200, '${beat(3600)}'),
      ('C350 Old smithy with a long name for the card', 'https://old-c350.example', 'oldc350key', NOW(), NOW() - INTERVAL 10 MINUTE, '3.49.0', NOW(), 1, 1, 200, '${beat(2000000)}')`);
    try {
      await visit('/admin.php?module=fleet&action=health');
      await Promise.all([page.waitForNavigation(), page.locator('a[data-fleet-fix-link="local_backup"]').click()]);
      if (!page.url().includes('action=fix&check=local_backup')) { throw new Error(`the heading led to ${page.url()}`); }
      const old = page.locator('tr', { hasText: 'C350 Old smithy' });
      if ((await old.locator('a[target="_blank"]').getAttribute('href')) !== 'https://old-c350.example/admin.php?module=settings&tab=backups') { throw new Error('the old site has no plain link to its backups'); }
      if (await page.locator('tr[data-fleet-fix-done]', { hasText: 'C350 Fresh bakery' }).count() !== 1) { throw new Error('the fresh site is not ticked'); }
      for (const [width, height] of [[1440, 900], [390, 844]]) {
        await page.setViewportSize({ width, height });
        for (const scheme of ['light', 'dark']) {
          await page.emulateMedia({ colorScheme: scheme });
          await visit('/admin.php?module=fleet&action=fix&check=local_backup');
          const wide = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
          if (wide > 1) { throw new Error(`${wide} px of sideways scroll at ${width} px`); }
          const found = await axeAt(page, `fix page ${width} ${scheme}`);
          if (found.length) { throw new Error(found.join('; ')); }
          if (SHOTS) { await page.screenshot({ path: `${SHOTS}/350-fleet-fix-${width}-${scheme}.png`, fullPage: true }); }
        }
      }
    } finally {
      await page.emulateMedia({ colorScheme: 'light' });
      await page.setViewportSize({ width: 1440, height: 900 });
      sql(`DELETE FROM ka_fleet_sites WHERE public_key LIKE '%c350key'; REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('extensions', '${extensions}'); DELETE FROM ka_nastaveni WHERE promenna = 'extensions' AND hodnota = ''`);
    }
  });

  // 3.50 C (U33): the customer page lists the open tasks of its enquiries and deals, each with a link to where it is done.
  await step('3.50 C U33: the customer page – "On its enquiries and deals" lists the deal task and the enquiry task, each link opens its record\'s tasks', async () => {
    sql("DELETE FROM ka_tasks WHERE title LIKE '%C366'; DELETE FROM ka_deals WHERE title = 'Website C366'; DELETE FROM ka_poptavky WHERE tema = 'Kitchen C366'; DELETE FROM ka_contacts WHERE email_norm = 'olga-c366@example.com'");
    const contact = Number(sql("INSERT INTO ka_contacts (email, email_norm, name, source, created_at, updated_at, last_activity_at) VALUES ('olga-c366@example.com', 'olga-c366@example.com', 'Olga C366', 'manual', NOW(), NOW(), NOW()); SELECT LAST_INSERT_ID();").trim());
    const enquiry = Number(sql(`INSERT INTO ka_poptavky (datum, formular, tema, email, data, contact_id) VALUES (NOW(), 'Contact', 'Kitchen C366', 'olga-c366@example.com', '[]', ${contact}); SELECT LAST_INSERT_ID();`).trim());
    const deal = Number(sql(`INSERT INTO ka_deals (title, contact_id, stage, created_at, updated_at) VALUES ('Website C366', ${contact}, 'new', NOW(), NOW()); SELECT LAST_INSERT_ID();`).trim());
    sql(`INSERT INTO ka_tasks (title, target, assignee_id, created_by, due_at, created_at, updated_at) SELECT 'Call back C366', 'enquiry:${enquiry}', idu, idu, NOW() + INTERVAL 2 DAY, NOW(), NOW() FROM ka_uzivatele WHERE user = 'admin';
      INSERT INTO ka_tasks (title, target, assignee_id, created_by, due_at, created_at, updated_at) SELECT 'Send the offer C366', 'deal:${deal}', idu, idu, NOW() - INTERVAL 1 DAY, NOW(), NOW() FROM ka_uzivatele WHERE user = 'admin'`);
    try {
      await visit(`/admin.php?module=contacts&action=detail&id=${contact}`);
      const related = page.locator('[data-tasks-related] li');
      const titles = await related.locator('.ukol-nazev').allTextContents();
      if (titles.join('|') !== 'Send the offer C366|Call back C366') { throw new Error(`the related tasks: ${titles.join('|')}`); }
      if (await page.locator('[data-tasks-none]').count() !== 0) { throw new Error('"No open tasks" while two wait'); }
      const found = await axeAt(page, 'customer tasks');
      if (found.length) { throw new Error(found.join('; ')); }
      if (SHOTS) { await page.locator('#tasks').scrollIntoViewIfNeeded(); await page.screenshot({ path: `${SHOTS}/350-customer-tasks.png` }); }
      await Promise.all([page.waitForNavigation(), related.first().locator('a').click()]);
      if (!page.url().includes(`module=pipeline&action=detail&id=${deal}`)) { throw new Error(`the deal task led to ${page.url()}`); }
    } finally {
      sql(`DELETE FROM ka_tasks WHERE title LIKE '%C366'; DELETE FROM ka_deal_stage_history WHERE deal_id = ${deal}; DELETE FROM ka_deals WHERE id = ${deal}; DELETE FROM ka_poptavky WHERE idp = ${enquiry}; DELETE FROM ka_contacts WHERE id = ${contact}`);
    }
  });
};
