// Kaleta browser steps 0240 (tools/test-browser.mjs runs the files of this folder in number order on one site – the order is
// the contract; tools/tests/README.md). Moved verbatim from the single file in 3.49 P1.
export default async (t) => {
  const { BASE, SHOTS, MYSQL, sql, browser, context, page, watch, step, visit, AXE, WCAG } = t;

  // 3.32 (agent A): records named by who and what – the enquiry list's Who column, Create deal as the short prefilled form
  // (the creator responsible when nobody handles the enquiry), the follow-up offered in the reply form; 1440 and 390 px,
  // light and dark, no sideways scroll, axe clean on the list, the reply form and the new deal form
  await step('3.32 names: Who in the enquiry list, Create deal prefilled, the follow-up in the reply form (1440 and 390 px, light/dark, axe)', async () => {
    if (!MYSQL.length) { throw new Error('MYSQL_JSON is not set'); }
    const data = JSON.stringify([['Name', 'Olivia Hart A332'], ['E-mail', 'olivia@hartsteel-a332.example'], ['Message', 'We need 40 custom brackets for a new hall in March']]).replace(/'/g, "''");
    const enquiry = Number(sql(`INSERT INTO ka_poptavky (datum, formular, zdroj, tema, email, data, stav) VALUES (NOW(), 'Contact', 'stranka:1', 'Contact', 'olivia@hartsteel-a332.example', '${data}', 0); SELECT LAST_INSERT_ID();`).trim());
    const mail = sql("SELECT IFNULL(GROUP_CONCAT(CONCAT(promenna, '=', hodnota) SEPARATOR '\\n'), '') FROM ka_nastaveni WHERE promenna IN ('mail_mode', 'smtp_host', 'mail_from')").trim();
    sql("REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('mail_mode', 'smtp'), ('smtp_host', '127.0.0.1'), ('mail_from', 'web@example.com')");
    let deal = 0;
    try {
      await page.setViewportSize({ width: 1440, height: 900 });
      await visit('/admin.php?module=enquiries');
      const cell = page.locator(`td[data-enquiry-name]:has(a[href*="id=${enquiry}"])`);
      if (!/Olivia Hart A332\s*We need 40 custom brackets for…/.test(await cell.innerText())) { throw new Error(`the Who cell says: ${await cell.innerText()}`); }
      await visit(`/admin.php?module=enquiries&action=detail&id=${enquiry}`);
      if (!/Olivia Hart A332 – We need 40 custom brackets for…/.test(await page.title())) { throw new Error(`the page title is ${await page.title()}`); }
      if ((await page.locator('select[data-reply-follow-up] option').count()) !== 4) { throw new Error('the reply form does not offer the follow-up'); }
      await Promise.all([page.waitForNavigation(), page.locator('a[data-deal-create]').click()]);
      if ((await page.locator('input[name="title"]').inputValue()) !== 'Olivia Hart A332 – We need 40 custom brackets for…') { throw new Error(`the deal form starts with "${await page.locator('input[name="title"]').inputValue()}"`); }
      if ((await page.locator('input[name="value"]').inputValue()) !== '' || (await page.locator('select[name="currency"]').inputValue()) !== '') { throw new Error('the value or currency is not empty'); }
      const state = await context.storageState();
      for (const scheme of ['light', 'dark']) {
        for (const [label, width, height] of [['desktop', 1440, 900], ['phone', 390, 844]]) {
          const other = await browser.newContext({ viewport: { width, height }, locale: 'en-GB', colorScheme: scheme, storageState: state, ...(width < 500 ? { isMobile: true, hasTouch: true } : {}) });
          const p2 = await other.newPage(); watch(p2);
          try {
            for (const url of ['/admin.php?module=enquiries', `/admin.php?module=enquiries&action=detail&id=${enquiry}`, `/admin.php?module=pipeline&action=new&enquiry=${enquiry}`]) {
              await p2.goto(`${BASE}${url}`, { waitUntil: 'networkidle' });
              const wide = await p2.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
              if (wide > 0) { throw new Error(`${wide} px of sideways scroll (${url}, ${label}, ${scheme})`); }
              if (SHOTS) { await p2.screenshot({ path: `${SHOTS}/332-${url.includes('pipeline') ? 'new-deal' : url.includes('detail') ? 'enquiry' : 'enquiries'}-${label}-${scheme}.png`, fullPage: true }); }
            }
          } finally {
            await other.close();
          }
        }
      }
      const axeContext = await browser.newContext({ storageState: state, bypassCSP: true, viewport: { width: 1440, height: 900 } });
      try {
        const axePage = await axeContext.newPage();
        for (const [url, include] of [['/admin.php?module=enquiries', ['table.vypis']], [`/admin.php?module=enquiries&action=detail&id=${enquiry}`, ['form[data-reply-form]']], [`/admin.php?module=pipeline&action=new&enquiry=${enquiry}`, ['form[data-deal-form]']]]) {
          for (const scheme of ['light', 'dark']) {
            await axePage.emulateMedia({ colorScheme: scheme });
            await axePage.goto(BASE + url, { waitUntil: 'networkidle' });
            await axePage.addScriptTag({ path: AXE });
            const found = await axePage.evaluate(([inc, tags]) => window.axe.run({ include: inc.map((s) => [s]) }, { runOnly: { type: 'tag', values: tags }, resultTypes: ['violations'] }), [include, WCAG]);
            if (found.violations.length) { throw new Error(`axe ${url} (${scheme}): ` + found.violations.map((v) => `${v.id} ${v.nodes[0].target.join(' ')}`).join(', ')); }
          }
        }
      } finally {
        await axeContext.close();
      }
      await Promise.all([page.waitForNavigation(), page.locator('form[data-deal-form] button[type="submit"]').click()]);
      deal = Number(sql(`SELECT IFNULL(MAX(id), 0) FROM ka_deals WHERE source_enquiry_id = ${enquiry}`).trim());
      // the title as ASCII and its length in characters (the mysql client of the test may not speak UTF-8)
      const row = sql(`SELECT CONCAT_WS('/', LEFT(title, 16), CHAR_LENGTH(title), IFNULL(value, '-'), IFNULL(currency, '-'), owner_id = (SELECT idu FROM ka_uzivatele WHERE user = 'admin')) FROM ka_deals WHERE id = ${deal}`).trim();
      if (row !== 'Olivia Hart A332/50/-/-/1' || !page.url().includes(`action=detail&id=${deal}`)) { throw new Error(`the deal from the form: ${row} (${page.url()})`); }
    } finally {
      await page.emulateMedia({ colorScheme: 'light' });
      await page.setViewportSize({ width: 1440, height: 900 });
      sql(`DELETE FROM ka_deal_stage_history WHERE deal_id = ${deal}; DELETE FROM ka_deals WHERE id = ${deal}; DELETE FROM ka_poptavky WHERE idp = ${enquiry}; DELETE FROM ka_contacts WHERE email_norm = 'olivia@hartsteel-a332.example'; DELETE FROM ka_nastaveni WHERE promenna IN ('mail_mode', 'smtp_host', 'mail_from')`);
      for (const line of mail.split('\n').filter((l) => l.includes('='))) {
        const [key, ...value] = line.split('=');
        sql(`INSERT INTO ka_nastaveni (promenna, hodnota) VALUES ('${key}', '${value.join('=').replace(/'/g, "''")}')`);
      }
    }
  });

  // 3.32 part B: row buttons 32/40 px and axe target size on My work at 390 px, a task edited in its row, Take it; the dashboard
  // leads with its numbers; Privacy and cookies fits a phone; scrolling table wrappers are focusable; the Statistics chart, the
  // Backups boxes and System status pass axe; System status lists its problems first and folds the green groups
  await step('3.32 (U35–U50): row buttons, My work edit and Take it, dashboard order, phone fit, focusable scrollers, axe', async () => {
    const admin = sql("SELECT idu FROM ka_uzivatele WHERE user = 'admin'").trim();
    sql(`INSERT INTO ka_tasks (title, assignee_id, created_by, due_at, created_at, updated_at) VALUES ('B332 call back', ${admin}, ${admin}, NOW() - INTERVAL 1 DAY, NOW(), NOW()), ('B332 send the offer', ${admin}, ${admin}, NULL, NOW(), NOW());
    INSERT INTO ka_poptavky (datum, formular, email, data, stav) VALUES (NOW(), 'B332 form', 'b332@visitor.example', '[]', 0)`);
    const state = await context.storageState();
    try {
      // desktop: 32 px, the task edited in its row
      await visit('/admin.php?module=my_work');
      const row = page.locator('[data-list="tasks"] tr[data-task]', { hasText: 'B332 send the offer' });
      const desk = await row.locator('td.akce button.navigace').evaluateAll((bs) => bs.map((b) => b.getBoundingClientRect().height));
      if (!desk.length || desk.some((h) => h < 32)) { throw new Error(`row buttons on a desktop: ${desk}`); }
      await row.locator('details.moje-ukol-upravit > summary').click();
      await row.locator('input[name="title"]').fill('B332 send the new offer');
      await Promise.all([page.waitForNavigation(), row.locator('button', { hasText: 'Save the task' }).click()]);
      if (!(await page.locator('[data-list="tasks"] .moje-ukol-nazev', { hasText: 'B332 send the new offer' }).count())) { throw new Error('the task was not saved from its row'); }
      const take = page.locator('[data-list="unassigned"] tr', { hasText: 'B332 form' }).locator('button', { hasText: 'Take it' });
      await Promise.all([page.waitForNavigation(), take.click()]);
      if (sql("SELECT prirazeno FROM ka_poptavky WHERE formular = 'B332 form'").trim() !== admin) { throw new Error('Take it did not assign the enquiry'); }
      sql("UPDATE ka_poptavky SET prirazeno = NULL WHERE formular = 'B332 form'");
      // a phone with touch: 40 px, no sideways scroll, axe (with target size) clean in the lists of My work
      const phone = await browser.newContext({ viewport: { width: 390, height: 844 }, locale: 'en-GB', storageState: state, isMobile: true, hasTouch: true, bypassCSP: true });
      try {
        const p2 = await phone.newPage(); watch(p2);
        await p2.goto(BASE + '/admin.php?module=my_work', { waitUntil: 'networkidle' });
        const touch = await p2.locator('[data-list="tasks"] td.akce button.navigace, [data-list="unassigned"] td.akce button.navigace').evaluateAll((bs) => bs.map((b) => b.getBoundingClientRect().height));
        if (!touch.length || touch.some((h) => h < 40)) { throw new Error(`row buttons on a phone: ${touch}`); }
        if (await p2.evaluate(() => document.documentElement.scrollWidth > innerWidth)) { throw new Error('My work scrolls sideways on a phone'); }
        await p2.addScriptTag({ path: AXE });
        const found = await p2.evaluate((tags) => window.axe.run({ include: [['[data-list="tasks"]'], ['[data-list="unassigned"]']] }, { runOnly: { type: 'tag', values: tags }, resultTypes: ['violations'] }), WCAG);
        if (found.violations.length) { throw new Error('axe My work (390 px): ' + found.violations.map((v) => `${v.id} ${v.nodes[0].target.join(' ')}`).join(', ')); }
        if (SHOTS) { await p2.screenshot({ path: `${SHOTS}/my-work-332-phone.png`, fullPage: true }); }
        // U47: Privacy and cookies fits a phone
        await p2.goto(BASE + '/admin.php?module=settings&tab=cookies', { waitUntil: 'networkidle' });
        const wide = await p2.evaluate(() => document.documentElement.scrollWidth - innerWidth);
        if (wide > 0) { throw new Error(`Privacy and cookies scrolls ${wide} px sideways at 390 px`); }
      } finally {
        await phone.close();
      }
      // U37: the dashboard leads with the numbers
      await visit('/admin.php');
      const order = await page.evaluate(() => { const t = document.querySelector('.dlazdice'), o = document.querySelector('#pripojit-claude, .ask-claude, .pruvodce'); return Boolean(t && (!o || t.compareDocumentPosition(o) & Node.DOCUMENT_POSITION_FOLLOWING)); });
      if (!order) { throw new Error('the dashboard tiles do not come before the onboarding blocks'); }
      // U46: at 1024 px a table wrapper that scrolls sideways is a named, focusable region
      await page.setViewportSize({ width: 1024, height: 800 });
      for (const url of ['/admin.php?module=fleet&action=rollout', '/admin.php?module=fleet&action=inventory', '/admin.php?module=fleet&action=sites&show=all', '/admin.php?module=pages', '/admin.php?module=status']) {
        await visit(url);
        const bad = await page.evaluate(() => [...document.querySelectorAll('.tab-obal')].filter((b) => b.scrollWidth > b.clientWidth + 1 && (b.tabIndex !== 0 || !b.getAttribute('aria-label'))).length);
        if (bad) { throw new Error(`${url}: ${bad} scrolling table wrapper(s) cannot be reached with the keyboard`); }
      }
      await page.setViewportSize({ width: 1440, height: 900 });
      // U48: problems first, every problem links to its row; all-green groups folded
      await visit('/admin.php?module=status');
      const status = await page.evaluate(() => {
        const links = [...document.querySelectorAll('[data-status-problems] a')];
        const groups = [...document.querySelectorAll('details[data-status-group]')];
        return { linked: links.every((a) => document.querySelector(a.getAttribute('href'))), folded: groups.every((g) => g.open === Boolean(g.querySelector('tr.stav-varovani, tr.stav-chyba'))), groups: groups.length,
          first: !links.length || (document.querySelector('[data-status-problems]').compareDocumentPosition(groups[0]) & Node.DOCUMENT_POSITION_FOLLOWING) > 0 };
      });
      if (!status.linked || !status.folded || status.groups < 3 || !status.first) { throw new Error(`System status: ${JSON.stringify(status)}`); }
      if (SHOTS) { await page.screenshot({ path: `${SHOTS}/status-332.png`, fullPage: true }); }
      // axe: the Statistics chart (U50), the Backups boxes (U49), System status and Privacy and cookies
      const axeContext = await browser.newContext({ storageState: state, bypassCSP: true, viewport: { width: 1440, height: 900 } });
      try {
        const axePage = await axeContext.newPage();
        for (const [url, include] of [['/admin.php?module=stats', ['.graf']], ['/admin.php?module=settings&tab=backups', ['form.formular']], ['/admin.php?module=status', ['main']], ['/admin.php?module=settings&tab=cookies', ['.cookies-tabulka']]]) {
          await axePage.goto(BASE + url, { waitUntil: 'networkidle' });
          if (!(await axePage.locator(include[0]).count())) { continue; } // e.g. Statistics switched off on this site
          await axePage.addScriptTag({ path: AXE });
          const found = await axePage.evaluate(([inc, tags]) => window.axe.run({ include: inc.map((x) => [x]) }, { runOnly: { type: 'tag', values: tags }, resultTypes: ['violations'] }), [include, WCAG]);
          if (found.violations.length) { throw new Error(`axe ${url}: ` + found.violations.map((v) => `${v.id} ${v.nodes[0].target.join(' ')}`).join(', ')); }
        }
      } finally {
        await axeContext.close();
      }
    } finally {
      await page.setViewportSize({ width: 1440, height: 900 });
      sql("DELETE FROM ka_tasks WHERE title LIKE 'B332 %'; DELETE FROM ka_poptavky WHERE formular = 'B332 form'");
    }
  });

  await step('3.33 move: the Move block of Site audit and the sample pages of Import and export – at 1440 and 390 px, axe on both', async () => {
    // an imported page (the import map), one untouched sample page kept as it is – the Move block shows, the sample pages panel too
    const page333 = sql("SELECT ids FROM ka_stranky WHERE smazano IS NULL ORDER BY ids DESC LIMIT 1").trim();
    sql(`INSERT IGNORE INTO ka_import_mapa (zdroj, typ, cizi_id, nase_id) VALUES ('web:b333.example', 'stranka', 'b333', ${page333})`);
    try {
      for (const width of [1440, 390]) {
        await page.setViewportSize({ width, height: 900 });
        await visit('/admin.php?module=audit');
        const move = await page.evaluate(() => {
          const block = document.querySelector('#move');
          return { rows: block ? block.querySelectorAll('[data-move-radek] a[href]').length : 0, overflow: document.documentElement.scrollWidth > window.innerWidth + 1 };
        });
        if (move.rows !== 6 || move.overflow) { throw new Error(`Move block at ${width} px: ${JSON.stringify(move)}`); }
        await visit('/admin.php?module=transfer');
        const panel = await page.evaluate(() => {
          const p = document.querySelector('#sample-pages');
          return { button: p ? Boolean(p.querySelector('button[data-potvrdit]')) : null, overflow: document.documentElement.scrollWidth > window.innerWidth + 1 };
        });
        if (panel.button === false || panel.overflow) { throw new Error(`sample pages panel at ${width} px: ${JSON.stringify(panel)}`); }
      }
      if (SHOTS) { await visit('/admin.php?module=audit'); await page.screenshot({ path: `${SHOTS}/move-333.png`, fullPage: true }); }
      const state = await context.storageState();
      const axeContext = await browser.newContext({ storageState: state, bypassCSP: true, viewport: { width: 1440, height: 900 } });
      try {
        const axePage = await axeContext.newPage();
        for (const [url, sel] of [['/admin.php?module=audit', '#move'], ['/admin.php?module=transfer', 'main']]) {
          await axePage.goto(BASE + url, { waitUntil: 'networkidle' });
          await axePage.addScriptTag({ path: AXE });
          const found = await axePage.evaluate(([inc, tags]) => window.axe.run({ include: [[inc]] }, { runOnly: { type: 'tag', values: tags }, resultTypes: ['violations'] }), [sel, WCAG]);
          if (found.violations.length) { throw new Error(`axe ${url}: ` + found.violations.map((v) => `${v.id} ${v.nodes[0].target.join(' ')}`).join(', ')); }
        }
      } finally {
        await axeContext.close();
      }
    } finally {
      await page.setViewportSize({ width: 1440, height: 900 });
      sql("DELETE FROM ka_import_mapa WHERE zdroj = 'web:b333.example'");
    }
  });

  // 3.33 part B: an imported page whose build is still the imported text is labelled "layout not rebuilt yet" in the list of
  // pages (the work queue of a move); the label fits 1440 and 390 px in light and dark without sideways scroll, axe clean
  await step('3.33 B: the list of pages labels an imported page whose layout waits to be rebuilt (1440 and 390 px, light/dark, axe)', async () => {
    if (!MYSQL.length) { throw new Error('MYSQL_JSON is not set'); }
    const build = '{"v":1,"deti":[{"id":"b333a","typ":"sekce","znacka":"section","obsah":{"sirka":"uzka"},"styl":{},"tridy":[],"deti":[{"id":"b333b","typ":"text","znacka":"div","obsah":{"html":"<p>Imported text B333</p>"},"styl":{},"tridy":[],"deti":[]}]}]}';
    const ids = Number(sql(`INSERT INTO ka_stranky (seo_link, titulek, text, stavba, zobrazit, v_menu) VALUES ('b333-imported', 'Imported B333', '<p>Imported text B333</p>', '${build}', 0, 0); SELECT LAST_INSERT_ID();`).trim());
    sql(`INSERT INTO ka_import_mapa (zdroj, typ, cizi_id, nase_id) SELECT 'layout', 'stranka', CONCAT(ids, ':', SHA2(stavba, 256)), ids FROM ka_stranky WHERE ids = ${ids}`);
    try {
      await visit('/admin.php?module=pages');
      const label = page.locator(`[data-layout-not-rebuilt="${ids}"]`);
      if ((await label.count()) !== 1 || !/layout not rebuilt yet/.test(await label.innerText())) { throw new Error('the imported page has no "layout not rebuilt yet" label'); }
      // the tab "Layout not rebuilt (n)" lists exactly the waiting pages (3.33, A and B together)
      const tab = page.locator('nav.zalozky a[href*="status=layout"]');
      if ((await tab.count()) !== 1 || !/Layout not rebuilt \(\d+\)/.test(await tab.innerText())) { throw new Error('the list of pages has no "Layout not rebuilt (n)" tab'); }
      await visit('/admin.php?module=pages&status=layout');
      if ((await page.locator(`[data-layout-not-rebuilt="${ids}"]`).count()) !== 1 || (await page.locator('table.vypis tbody tr').count()) !== (await page.locator('table.vypis [data-layout-not-rebuilt]').count())) { throw new Error('the tab "Layout not rebuilt" lists other pages too'); }
      const state = await context.storageState();
      for (const scheme of ['light', 'dark']) {
        for (const [label2, width, height] of [['desktop', 1440, 900], ['phone', 390, 844]]) {
          const other = await browser.newContext({ viewport: { width, height }, locale: 'en-GB', colorScheme: scheme, storageState: state, ...(width < 500 ? { isMobile: true, hasTouch: true } : {}) });
          const p2 = await other.newPage(); watch(p2);
          try {
            await p2.goto(`${BASE}/admin.php?module=pages`, { waitUntil: 'networkidle' });
            const wide = await p2.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
            if (wide > 0) { throw new Error(`${wide} px of sideways scroll (${label2}, ${scheme})`); }
            if (SHOTS) { await p2.screenshot({ path: `${SHOTS}/333b-pages-${label2}-${scheme}.png`, fullPage: true }); }
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
          await axePage.goto(BASE + '/admin.php?module=pages', { waitUntil: 'networkidle' });
          await axePage.addScriptTag({ path: AXE });
          const found = await axePage.evaluate(([inc, tags]) => window.axe.run({ include: inc.map((x) => [x]) }, { runOnly: { type: 'tag', values: tags }, resultTypes: ['violations'] }), [['table.vypis'], WCAG]);
          if (found.violations.length) { throw new Error(`axe pages (${scheme}): ` + found.violations.map((v) => `${v.id} ${v.nodes[0].target.join(' ')}`).join(', ')); }
        }
      } finally {
        await axeContext.close();
      }
    } finally {
      sql(`DELETE FROM ka_import_mapa WHERE zdroj = 'layout' AND nase_id = ${ids}; DELETE FROM ka_stranky WHERE ids = ${ids}`);
    }
  });
};
