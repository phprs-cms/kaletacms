// Kaleta browser steps 0150 (tools/test-browser.mjs runs the files of this folder in number order on one site – the order is
// the contract; tools/tests/README.md). Moved verbatim from the single file in 3.49 P1.
export default async (t) => {
  const { BASE, SHOTS, MYSQL, sql, browser, context, page, watch, step, visit, AXE, WCAG } = t;

  // 3.17: the inbox of the fleet console (Fleet\Inbox) – every row with a plain link out, no sideways scroll; on a phone the rows are cards
  await step('3.17 fleet inbox: rows with plain links, the filters, no sideways scroll at 1440 and 390 px (cards on a phone)', async () => {
    try {
      for (const [width, height, name] of [[1440, 900, 'desktop'], [390, 844, 'phone']]) {
        await page.setViewportSize({ width, height });
        for (const url of ['/admin.php?module=fleet&action=inbox', '/admin.php?module=fleet&action=sites&show=all', '/admin.php?module=fleet&action=customers']) {
          await visit(url);
          const wider = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
          if (wider > 1) { throw new Error(`${url} scrolls sideways by ${wider} px at ${width} px`); }
          if (SHOTS) { await page.screenshot({ path: `${SHOTS}/fleet-${url.replace(/.*module=fleet&?/, '').replace(/\W+/g, '-') || 'inbox'}-${name}.png`, fullPage: true }); }
        }
        await visit('/admin.php?module=fleet&action=inbox');
        const rows = page.locator('table.vypis-karty tbody tr');
        if (await rows.count() < 10) { throw new Error(`the inbox lists ${await rows.count()} rows`); }
        const out = await page.locator('table.vypis-karty a[target="_blank"]').evaluateAll((links) => links.map((a) => [a.href, a.rel]));
        if (!out.some(([href]) => href === 'https://smile-dental-clinic-with-a-long-domain.example/admin.php?module=requests&action=detail&id=12')) { throw new Error('no plain link to the request'); }
        if (out.some(([href, rel]) => rel !== 'noopener' || /token|password|login/i.test(href))) { throw new Error('a link out is not plain'); }
        const first = await rows.first().locator('.stitek').last().textContent();
        if (!/urgent/.test(first)) { throw new Error(`the first row is not urgent: ${first}`); }
        if (width === 390 && await rows.first().evaluate((tr) => getComputedStyle(tr).display) !== 'block') { throw new Error('the rows are not cards on a phone'); }
      }
      await page.setViewportSize({ width: 1440, height: 900 });
      await visit('/admin.php?module=fleet&action=inbox&urgent=1');
      if (await page.locator('table.vypis-karty .stitek-chyba').count() !== await page.locator('table.vypis-karty tbody tr').count()) { throw new Error('"only urgent" lists other rows'); }
    } finally {
      await page.setViewportSize({ width: 1440, height: 900 });
    }
  });

  // 3.29: the console opens on Today (ranked items with plain links out), the health matrix (a chip per check, the site column
  // fixed while the table scrolls inside its frame, a card per site on a phone) and the workload (sortable, a totals row) – at
  // 1440 and 390 px, light and dark, none scrolls the page sideways; axe finds nothing in the three screens
  await step('3.29 fleet console: Today, Health and Workload at 1440 and 390 px, light and dark, no sideways scroll, axe clean', async () => {
    const known = ['https://smile-dental-clinic-with-a-long-domain.example/', 'https://booking.smile.example/', 'https://bakery.example/', 'https://shop.smile.example/', 'https://northwind-architects.example/'];
    try {
      for (const scheme of ['light', 'dark']) {
        await page.emulateMedia({ colorScheme: scheme });
        for (const [width, height, name] of [[1440, 900, 'desktop'], [390, 844, 'phone']]) {
          await page.setViewportSize({ width, height });
          for (const [url, shot] of [['/admin.php?module=fleet', 'today'], ['/admin.php?module=fleet&action=health', 'health'], ['/admin.php?module=fleet&action=workload', 'workload']]) {
            await visit(url);
            const wider = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
            if (wider > 1) { throw new Error(`${url} scrolls sideways by ${wider} px at ${width} px (${scheme})`); }
            if (SHOTS) { await page.screenshot({ path: `${SHOTS}/fleet-329-${shot}-${name}-${scheme}.png`, fullPage: true }); }
          }
          await visit('/admin.php?module=fleet');
          if (!(await page.locator('nav.zalozky a.aktivni', { hasText: 'Today' }).count())) { throw new Error('the console does not open on Today'); }
          const rows = page.locator('table.flotila-dnes tbody tr');
          if (await rows.count() < 5) { throw new Error(`Today lists ${await rows.count()} items`); }
          if (!/flotila-bad/.test(await rows.first().getAttribute('class'))) { throw new Error('the first item of Today is not red'); }
          const out = await page.locator('table.flotila-dnes a[target="_blank"]').evaluateAll((links) => links.map((a) => [a.href, a.rel]));
          if (out.some(([href, rel]) => rel !== 'noopener' || !known.some((k) => href.startsWith(k)) || /token|password|login|session/i.test(href))) { throw new Error('an Open link is not a plain link to a known site'); }
          if (!out.some(([href]) => href === 'https://northwind-architects.example/admin.php?module=settings&tab=security')) { throw new Error('no link to fix the two-step sign-in'); }
          if (width === 390 && await rows.first().evaluate((tr) => getComputedStyle(tr).display) !== 'block') { throw new Error('Today is not cards on a phone'); }
          await visit('/admin.php?module=fleet&action=health');
          const site = page.locator('table.flotila-matice tbody tr:not(.flotila-skupina)');
          if (await site.count() !== 5) { throw new Error(`the matrix has ${await site.count()} sites`); }
          if (await page.locator('table.flotila-matice thead th[title]').count() !== 18) { throw new Error('the matrix has not 18 check columns'); }
          const chips = await site.first().locator('.flotila-stav').evaluateAll((c) => c.map((x) => [x.textContent.trim().length > 0, x.querySelector('.navod-skryte')?.textContent || '']));
          if (chips.length !== 18 || chips.some(([text, sr]) => !text || !/: /.test(sr))) { throw new Error('a matrix cell has no symbol or no text for screen readers'); }
          if (!(await page.locator('table.flotila-matice th[scope="row"] .stitek', { hasText: 'Update the site to see' }).count())) { throw new Error('the sites before 3.29 are not marked'); }
          if (width === 1440 && await site.first().locator('th').evaluate((th) => getComputedStyle(th).position) !== 'sticky') { throw new Error('the site column is not fixed'); }
          if (width === 390 && await site.first().evaluate((tr) => getComputedStyle(tr).display) !== 'flex') { throw new Error('the matrix is not a card per site on a phone'); }
          await visit('/admin.php?module=fleet&action=workload&sort=tasks_open&dir=desc');
          const first = await page.locator('table.flotila-prace tbody tr').first().locator('th').textContent();
          if (!/Northwind/.test(first)) { throw new Error(`the workload sorted by open tasks starts with ${first}`); }
          if (!(await page.locator('table.flotila-prace tfoot').count()) || !(await page.locator('table.flotila-prace tbody td', { hasText: '–' }).count())) { throw new Error('the workload has no totals or no "–" for the older sites'); }
        }
      }
      // between a phone and a computer the matrix scrolls inside its frame, never the page, and the site column stays put
      await page.emulateMedia({ colorScheme: 'light' });
      await page.setViewportSize({ width: 820, height: 900 });
      await visit('/admin.php?module=fleet&action=health');
      const frame = await page.locator('.flotila-matice-obal').evaluate((f) => [f.scrollWidth - f.clientWidth, getComputedStyle(f).overflowX]);
      const page820 = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
      if (frame[0] < 1 || frame[1] !== 'auto' || page820 > 1) { throw new Error(`at 820 px the matrix frame scrolls ${frame[0]} px (${frame[1]}), the page ${page820} px`); }
      const before = await page.locator('table.flotila-matice tbody th[scope="row"]').first().boundingBox();
      await page.locator('.flotila-matice-obal').evaluate((f) => { f.scrollLeft = 300; });
      const after = await page.locator('table.flotila-matice tbody th[scope="row"]').first().boundingBox();
      if (!before || !after || Math.abs(before.x - after.x) > 1) { throw new Error('the site column moves with the scrolled matrix'); }
      if (SHOTS) { await page.screenshot({ path: `${SHOTS}/fleet-329-health-820.png`, fullPage: true }); }
    } finally {
      await page.emulateMedia({ colorScheme: 'light' });
      await page.setViewportSize({ width: 1440, height: 900 });
    }
    const axeContext = await browser.newContext({ storageState: await context.storageState(), bypassCSP: true, viewport: { width: 1440, height: 900 } });
    try {
      const axePage = await axeContext.newPage();
      for (const [url, include] of [['/admin.php?module=fleet', ['.flotila-souhrn', 'table.flotila-dnes']], ['/admin.php?module=fleet&action=health', ['.flotila-legenda', 'table.flotila-matice']],
        ['/admin.php?module=fleet&action=workload', ['table.flotila-prace']]]) {
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
  });

  // 3.39 (A): Today grouped by check – the red groups open, the amber ones open up to 5 rows, six section tabs with a row of views; accepting
  // a state with a note moves it to the folded Accepted group (nothing on the site changes) and withdrawing it brings it back;
  // at 1440 and 390 px, light and dark, no sideways scroll, axe clean with a group and the Accept form open
  await step('3.39 fleet Today: groups by check, accept and withdraw a state, six tabs, 1440 and 390 px, light and dark, axe clean', async () => {
    const submit = async (locator) => { await Promise.all([page.waitForNavigation(), locator.click()]); };
    try {
      for (const scheme of ['light', 'dark']) {
        await page.emulateMedia({ colorScheme: scheme });
        for (const [width, height, name] of [[1440, 900, 'desktop'], [390, 844, 'phone']]) {
          await page.setViewportSize({ width, height });
          await visit('/admin.php?module=fleet');
          const groups = page.locator('details[data-fleet-group]');
          if (await groups.count() < 3) { throw new Error(`Today has ${await groups.count()} groups`); }
          if (!(await groups.first().evaluate((d) => d.open)) || !(await groups.first().locator('summary .stitek-chyba').count())) { throw new Error('the first group is not red and open'); }
          const amber = page.locator('details[data-fleet-group]').filter({ has: page.locator('summary .stitek-koncept') }).filter({ has: page.locator('[data-fleet-accept]') });
          if (!(await amber.count())) { throw new Error('no amber group with Accept'); }
          if (!(await amber.first().evaluate((d) => d.open === (d.querySelectorAll('tbody tr').length <= 5)))) { throw new Error('an amber group of up to 5 rows is not open, or a longer one not folded'); }
          if (await page.locator('nav.zalozky:not(.flotila-pohledy) a').count() !== 6) { throw new Error('the console has not six section tabs'); }
          if (!(await page.locator('nav.flotila-pohledy a[aria-current="page"]', { hasText: 'Today' }).count())) { throw new Error('the views row does not mark Today'); }
          await amber.first().evaluate((d) => { d.open = true; });
          await amber.first().locator('[data-fleet-accept] summary').first().click();
          const wider = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
          if (wider > 1) { throw new Error(`Today with a group and the Accept form open scrolls sideways by ${wider} px at ${width} px (${scheme})`); }
          if (SHOTS) { await page.screenshot({ path: `${SHOTS}/fleet-339-today-${name}-${scheme}.png`, fullPage: true }); }
        }
      }
      await page.emulateMedia({ colorScheme: 'light' });
      await page.setViewportSize({ width: 1440, height: 900 });
      // accept the first amber row with a note: it leaves its group for the Accepted group, with the note and who accepted
      await visit('/admin.php?module=fleet');
      const group = page.locator('details[data-fleet-group]').filter({ has: page.locator('summary .stitek-koncept') }).filter({ has: page.locator('[data-fleet-accept]') }).first();
      const check = await group.getAttribute('data-fleet-group');
      const before = await group.locator('tbody tr').count();
      await group.evaluate((d) => { d.open = true; });
      const form = group.locator('[data-fleet-accept]').first();
      await form.locator(':scope > summary').click();
      await form.locator('input[name="note"]').fill('The customer decides this one');
      if ((await form.locator('input[name="until"]').inputValue()).length !== 10) { throw new Error('the last day has no default'); }
      await submit(form.locator('button[type="submit"]'));
      if (!/Accepted until/.test((await page.locator('.hlaska').first().textContent()) || '')) { throw new Error('no message after accepting'); }
      const accepted = page.locator('details[data-fleet-accepted]');
      if (await accepted.evaluate((d) => d.open)) { throw new Error('the Accepted group is not folded'); }
      if (!(await accepted.locator(`tr[data-fleet-accepted-item="${check}"]`, { hasText: 'The customer decides this one' }).count())) { throw new Error('the accepted state is not in the Accepted group with its note'); }
      const after = await page.locator(`details[data-fleet-group="${check}"] tbody tr`).count();
      if (after !== before - 1) { throw new Error(`the group ${check} still has ${after} rows (before ${before})`); }
      if (!(await page.locator('[data-fleet-accepted-count]').count())) { throw new Error('the summary does not count the accepted states'); }
      // axe with the Accepted group open and a red group's Accept form open
      await accepted.locator(':scope > summary').click();
      await page.locator('details[data-fleet-group][open] [data-fleet-accept] summary').first().click();
      const axeContext = await browser.newContext({ storageState: await context.storageState(), bypassCSP: true, viewport: { width: 1440, height: 900 } });
      try {
        const axePage = await axeContext.newPage();
        for (const scheme of ['light', 'dark']) {
          await axePage.emulateMedia({ colorScheme: scheme });
          await axePage.goto(BASE + '/admin.php?module=fleet', { waitUntil: 'networkidle' });
          await axePage.evaluate(() => { document.querySelectorAll('details[data-fleet-group], details[data-fleet-accepted], details[data-fleet-accept]').forEach((d) => { d.open = true; }); });
          await axePage.addScriptTag({ path: AXE });
          const found = await axePage.evaluate(([tags]) => window.axe.run({ include: [['main']] }, { runOnly: { type: 'tag', values: tags }, resultTypes: ['violations'] }), [WCAG]);
          if (found.violations.length) { throw new Error(`axe Today (${scheme}): ` + found.violations.map((v) => `${v.id} ${v.nodes[0].target.join(' ')}`).join(', ')); }
        }
      } finally {
        await axeContext.close();
      }
      // withdraw it: the state is open again
      await submit(page.locator(`details[data-fleet-accepted] tr[data-fleet-accepted-item="${check}"] button[type="submit"]`).first());
      if (await page.locator('details[data-fleet-accepted]').count() || await page.locator(`details[data-fleet-group="${check}"] tbody tr`).count() !== before) { throw new Error('withdrawing did not reopen the state'); }
    } finally {
      sql('DELETE FROM ka_fleet_acceptances');
      await page.emulateMedia({ colorScheme: 'light' });
      await page.setViewportSize({ width: 1440, height: 900 });
    }
  });

  // 3.30 (agent Y): console tasks – the task panel on a site's screen (with the site's own Claude connector and Copy) and on a
  // customer's screen, the Tasks counts of Sites and Customers, the overdue task on Today (amber, opening the task on the console)
  // and in My work – at 1440 and 390 px, light and dark, no sideways scroll, axe clean in the panel, the connector and the sites
  await step('3.30 console tasks: the panels on the site and customer screens, the connector, Sites, Customers, Today and My work (1440 and 390 px, light/dark, axe)', async () => {
    if (!MYSQL.length) { throw new Error('MYSQL_JSON is not set'); }
    const submit = async (locator) => { await Promise.all([page.waitForNavigation(), locator.click()]); };
    const customer = Number(sql('SELECT customer_id FROM ka_fleet_sites WHERE id = 900').trim());
    const past = new Date(Date.now() - 3 * 86400000).toISOString().slice(0, 10);
    try {
      await page.setViewportSize({ width: 1440, height: 900 });
      // the site: its connector (the known address + /mcp, no token) and a task overdue for me
      await visit('/admin.php?module=fleet&action=detail&id=900');
      if ((await page.locator('[data-fleet-connector] code').innerText()) !== 'https://shop.smile.example/mcp') { throw new Error('the connector of the site is not its /mcp address'); }
      if (!(await page.locator('[data-fleet-connector] button[data-kopirovat="#fleet-connector"]').count())) { throw new Error('the connector has no Copy button'); }
      const panel = page.locator('#tasks[data-tasks="fleet_site:900"]');
      await panel.locator('.ukol-pridat > summary').click();
      const form = panel.locator('form[data-task-add]');
      await form.locator('input[name="title"]').fill('Renew the domain of the Smile shop');
      await form.locator('input[name="due_date"]').fill(past);
      await submit(form.locator('button[type="submit"].tl'));
      await page.locator('.hlaska-ok', { hasText: 'The task was added.' }).waitFor();
      const row = panel.locator('[data-tasks-open] .ukol', { hasText: 'Renew the domain of the Smile shop' });
      if (!(await row.locator('.stitek-chyba', { hasText: 'overdue' }).count())) { throw new Error('the overdue site task is not marked overdue'); }
      // the customer: its screen from Customers, a task for tomorrow by the quick button
      await visit('/admin.php?module=fleet&action=customers');
      const counted = await page.locator(`a[href*="action=customer_detail&id=${customer}"]`).count();
      if (counted < 1) { throw new Error('Customers do not link to the customer\'s screen'); }
      await submit(page.locator(`a[href$="action=customer_detail&id=${customer}"]`).first());
      if (!(await page.locator('[data-customer-sites] a[href*="action=detail&id=900"]').count())) { throw new Error('the customer\'s screen does not list its sites'); }
      const cpanel = page.locator(`#tasks[data-tasks="fleet_customer:${customer}"]`);
      await cpanel.locator('.ukol-pridat > summary').click();
      await cpanel.locator('form[data-task-add] input[name="title"]').fill('Check the prices before January');
      await submit(cpanel.locator('form[data-task-add] .ukol-rychle button', { hasText: 'Tomorrow' }));
      if (!(await cpanel.locator('[data-tasks-open] .ukol-nazev', { hasText: 'Check the prices before January' }).count())) { throw new Error('the customer task is not listed'); }
      // Today: the overdue task among the checks – amber, the first item still red, its Open stays on the console
      await visit('/admin.php?module=fleet');
      const item = page.locator('table.flotila-dnes tr[data-fleet-task]');
      if (await item.count() !== 1 || !/flotila-warn/.test(await item.getAttribute('class'))) { throw new Error('Today does not show the overdue console task as one amber item'); }
      if (!/flotila-bad/.test(await page.locator('table.flotila-dnes tbody tr').first().getAttribute('class'))) { throw new Error('the first item of Today is not red'); }
      const open = item.locator('a.navigace');
      if (!(await open.getAttribute('href')).endsWith('action=detail&id=900#tasks') || await open.getAttribute('target')) { throw new Error('the task on Today does not open on the console'); }
      if (!(await page.locator('[data-fleet-overdue-tasks]').count())) { throw new Error('the summary does not count the overdue tasks'); }
      // My work: both, with what they are about
      await visit('/admin.php?module=my_work');
      for (const [title, about] of [['Renew the domain of the Smile shop', 'Site “Smile Dental – shop”'], ['Check the prices before January', 'Console customer “Dental Clinic Smile and Partners International Ltd”']]) {
        if (!(await page.locator('[data-list="tasks"] tr[data-task]', { hasText: title }).locator('a', { hasText: about }).count())) { throw new Error(`My work does not show “${title}” about ${about}`); }
      }
      // light and dark, desktop and phone: the screens fit; the task text is readable on its card
      const state = await context.storageState();
      for (const scheme of ['light', 'dark']) {
        for (const [label, width, height] of [['desktop', 1440, 900], ['phone', 390, 844]]) {
          const other = await browser.newContext({ viewport: { width, height }, locale: 'en-GB', colorScheme: scheme, storageState: state, ...(width < 500 ? { isMobile: true, hasTouch: true } : {}) });
          const p2 = await other.newPage(); watch(p2);
          try {
            for (const [url, shot] of [['/admin.php?module=fleet&action=detail&id=900', 'site'], [`/admin.php?module=fleet&action=customer_detail&id=${customer}`, 'customer'],
              ['/admin.php?module=fleet&action=sites&show=all', 'sites'], ['/admin.php?module=fleet&action=customers', 'customers'], ['/admin.php?module=fleet', 'today']]) {
              await p2.goto(BASE + url, { waitUntil: 'networkidle' });
              if (await p2.locator('#tasks .ukol-pridat').count()) { await p2.locator('#tasks .ukol-pridat').evaluate((d) => { d.open = true; }); }
              const wide = await p2.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
              if (wide > 0) { throw new Error(`${url}: ${wide} px of sideways scroll (${label}, ${scheme})`); }
              if (await p2.locator('#tasks .ukol').count()) {
                const colours = await p2.locator('#tasks .ukol').first().evaluate((el) => [getComputedStyle(el).backgroundColor, getComputedStyle(el.querySelector('.ukol-nazev')).color]);
                if (colours[0] === colours[1]) { throw new Error(`${url}: the task text has the colour of its card (${label}, ${scheme})`); }
              }
              if (SHOTS) { await p2.screenshot({ path: `${SHOTS}/fleet-330-${shot}-${label}-${scheme}.png`, fullPage: true }); }
            }
          } finally {
            await other.close();
          }
        }
      }
      const axeContext = await browser.newContext({ storageState: state, bypassCSP: true, viewport: { width: 1440, height: 900 } });
      try {
        const axePage = await axeContext.newPage();
        for (const [url, include] of [['/admin.php?module=fleet&action=detail&id=900', ['#tasks', '[data-fleet-connector]']], [`/admin.php?module=fleet&action=customer_detail&id=${customer}`, ['#tasks', '[data-customer-sites]']],
          ['/admin.php?module=fleet', ['table.flotila-dnes']]]) {
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
      // done from the site's panel: Today has no task item any more
      await visit('/admin.php?module=fleet&action=detail&id=900');
      await submit(page.locator('#tasks [data-tasks-open] .ukol', { hasText: 'Renew the domain of the Smile shop' }).locator('button.ukol-hotovo'));
      await visit('/admin.php?module=fleet');
      if (await page.locator('tr[data-fleet-task]').count()) { throw new Error('a done console task is still on Today'); }
    } finally {
      await page.setViewportSize({ width: 1440, height: 900 });
      sql("DELETE FROM ka_notifications WHERE target LIKE 'fleet%'; DELETE FROM ka_tasks WHERE target LIKE 'fleet%'");
    }
  });

  // 3.30: the inventory (a row per site, a filter per column, the CSV export), the expiry calendar (a month of dates – on a phone only the
  // days with a date – the list, the recovery keys) and the rollout (the rings, the sites, a window per customer) – at 1440 and 390 px,
  // light and dark, none scrolls the page sideways; axe finds nothing in the three screens
  await step('3.30 fleet console: Inventory, Calendar and Rollout at 1440 and 390 px, light and dark, no sideways scroll, axe clean', async () => {
    const soon = new Date(Date.now() + 5 * 86400000);
    const month = `${soon.getFullYear()}-${String(soon.getMonth() + 1).padStart(2, '0')}`;
    const screens = [['/admin.php?module=fleet&action=inventory', 'inventory'], [`/admin.php?module=fleet&action=calendar&month=${month}`, 'calendar'], ['/admin.php?module=fleet&action=rollout', 'rollout']];
    try {
      for (const scheme of ['light', 'dark']) {
        await page.emulateMedia({ colorScheme: scheme });
        for (const [width, height, name] of [[1440, 900, 'desktop'], [390, 844, 'phone']]) {
          await page.setViewportSize({ width, height });
          for (const [url, shot] of screens) {
            await visit(url);
            const wider = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
            if (wider > 1) { throw new Error(`${url} scrolls sideways by ${wider} px at ${width} px (${scheme})`); }
            if (SHOTS) { await page.screenshot({ path: `${SHOTS}/fleet-330-${shot}-${name}-${scheme}.png`, fullPage: true }); }
          }
          await visit(screens[0][0]);
          if (!(await page.locator('nav.zalozky a.aktivni', { hasText: 'Inventory' }).count())) { throw new Error('the Inventory tab is not current'); }
          const rows = page.locator('table.flotila-inventar tbody tr');
          if (await rows.count() < 5) { throw new Error(`the inventory lists ${await rows.count()} sites`); }
          if (!(await page.locator('table.flotila-inventar tbody td', { hasText: 'MariaDB 10.11.6' }).count())) { throw new Error('the inventory does not show the database'); }
          if (!(await page.locator('form.flotila-filtry select[name="php"] option[value="8.3"]').count())) { throw new Error('no PHP 8.3 in the PHP filter'); }
          if (!/action=inventory_csv/.test(await page.locator('form.flotila-filtry a[download]').getAttribute('href'))) { throw new Error('no CSV export link'); }
          if (width === 390 && await rows.first().evaluate((tr) => getComputedStyle(tr).display) !== 'block') { throw new Error('the inventory is not cards on a phone'); }
          await visit(screens[1][0]);
          const bad = page.locator('table.flotila-kalendar a.flotila-stav-bad', { hasText: 'Northwind' });
          if (!(await bad.count())) { throw new Error('the month has no red chip for the certificate of Northwind'); }
          if (!(await bad.first().locator('.navod-skryte').textContent()).includes('Certificate')) { throw new Error('a calendar chip has no text for screen readers'); }
          if (width === 390) {
            const shown = await page.locator('table.flotila-kalendar td').evaluateAll((tds) => tds.filter((td) => getComputedStyle(td).display !== 'none').map((td) => td.classList.contains('flotila-ma')));
            if (!shown.length || shown.some((has) => !has)) { throw new Error('on a phone the month shows days without a date'); }
          }
          if (!(await page.locator('table.flotila-terminy tbody tr').count()) || !(await page.locator('table.flotila-klice').count())) { throw new Error('no list of dates or recovery keys'); }
          await visit(screens[2][0]);
          if (await page.locator('table.flotila-okruhy tbody tr').count() !== 3) { throw new Error('the rollout has not three rings'); }
          if (!(await page.locator('table.flotila-rollout tbody tr', { hasText: 'Smile Dental – shop' }).locator('td', { hasText: 'Pilot' }).count())) { throw new Error('the pilot site is not shown in its ring'); }
          if (!(await page.locator('form.flotila-okno input[name="window_days[]"][value="1"]:checked').count())) { throw new Error('the customer\'s window is not ticked'); }
        }
      }
    } finally {
      await page.emulateMedia({ colorScheme: 'light' });
      await page.setViewportSize({ width: 1440, height: 900 });
    }
    const axeContext = await browser.newContext({ storageState: await context.storageState(), bypassCSP: true, viewport: { width: 1440, height: 900 } });
    try {
      const axePage = await axeContext.newPage();
      for (const [url, include] of [[screens[0][0], ['form.flotila-filtry', 'table.flotila-inventar']], [screens[1][0], ['.flotila-mesice', 'table.flotila-kalendar', 'table.flotila-terminy', 'table.flotila-klice']],
        [screens[2][0], ['table.flotila-okruhy', 'table.flotila-rollout', 'form.flotila-okno']]]) {
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
  });
};
