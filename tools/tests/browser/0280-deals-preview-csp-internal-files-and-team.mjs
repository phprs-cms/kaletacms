// Kaleta browser steps 0280 (tools/test-browser.mjs runs the files of this folder in number order on one site – the order is
// the contract; tools/tests/README.md). Moved verbatim from the single file in 3.49 P1.
export default async (t) => {
  const { BASE, SHOTS, MYSQL, sql, browser, context, page, errors, watch, step, visit, AXE, WCAG } = t;

  // 3.41 A (step 7, R5): the deal card says how many days the deal is in its stage and its next step (or "No next step"); a
  // keyboard move sets the days to today; the detail says both; My work → Summary – a row per person for an administrator with
  // the team's total – fits 1440 and 375 px without sideways scroll (one card per row on a phone), light and dark, axe
  await step('3.41 A: the deal card (days in the stage, next step), the deal detail and My work → Summary – 1440 and 375 px, light/dark, axe', async () => {
    if (!MYSQL.length) { throw new Error('MYSQL_JSON is not set'); }
    sql("DELETE FROM ka_tasks WHERE title LIKE '%A341%'; DELETE FROM ka_deal_stage_history WHERE deal_id IN (SELECT id FROM ka_deals WHERE title LIKE '%A341%'); DELETE FROM ka_deals WHERE title LIKE '%A341%'");
    const admin = Number(sql("SELECT idu FROM ka_uzivatele WHERE user = 'admin'").trim());
    const deal = (title) => Number(sql(`INSERT INTO ka_deals (title, owner_id, stage, created_at, updated_at) VALUES ('${title}', ${admin}, 'new', NOW() - INTERVAL 20 DAY, NOW()); SELECT LAST_INSERT_ID();`).trim());
    const a = deal('Warehouse racks for the Leeds site A341');
    const b = deal('Logo refresh A341');
    sql(`INSERT INTO ka_deal_stage_history (deal_id, from_stage, to_stage, at, user_id) VALUES (${a}, NULL, 'new', NOW() - INTERVAL 12 DAY, ${admin}), (${b}, NULL, 'new', NOW() - INTERVAL 3 DAY, ${admin});
    INSERT INTO ka_tasks (title, target, assignee_id, created_by, due_at, created_at, updated_at) VALUES ('Call Olga about the delivery date A341', 'deal:${a}', ${admin}, ${admin}, CURDATE() + INTERVAL 2 DAY + INTERVAL 9 HOUR, NOW(), NOW());
    INSERT INTO ka_tasks (title, assignee_id, created_by, done_at, done_by, created_at, updated_at) VALUES ('Done A341', ${admin}, ${admin}, NOW() - INTERVAL 1 DAY, ${admin}, NOW() - INTERVAL 2 DAY, NOW())`);
    try {
      await page.setViewportSize({ width: 1440, height: 900 });
      await visit('/admin.php?module=pipeline');
      const line = async (id) => ((await page.locator(`#deal-${id} .obchod-krok`).innerText()) || '').replace(/\s+/g, ' ').trim();
      // 3.44 (U57): the days are a chip in the row of value and close, the next step has its own line and is not cut off
      if (!/^Next: Call Olga about the delivery date A341 · /.test(await line(a))) { throw new Error('the card with a task says: ' + await line(a)); }
      if ((await line(b)) !== 'No next step') { throw new Error('the card without a task says: ' + await line(b)); }
      const chip = async (id) => (await page.locator(`#deal-${id} [data-deal-days] [aria-hidden="true"]`).innerText()).trim();
      if ((await chip(a)) !== '12 d' || (await chip(b)) !== '3 d') { throw new Error(`the day chips say ${await chip(a)} and ${await chip(b)}`); }
      const cut = await page.locator(`#deal-${a} .obchod-krok`).evaluate((el) => el.scrollHeight > el.clientHeight + 1);
      if (cut) { throw new Error('the next step of the card is still cut off at 1440 px'); }
      const cardHeight = (await page.locator(`#deal-${a}`).boundingBox()).height;
      // 3.44 (U57): the next step may take up to three lines instead of being cut off – the card grows by those lines only
      if (cardHeight > 240) { throw new Error(`the card with its next step is ${Math.round(cardHeight)} px tall`); }
      // a keyboard move starts the stage today – the card says so without a reload
      await page.locator(`#deal-${b} .obchod-uchyt`).focus();
      await page.keyboard.press('Space');
      await page.keyboard.press('ArrowRight');
      await page.keyboard.press('Space');
      await page.waitForFunction((id) => /since today/.test(document.querySelector(`#deal-${id} [data-deal-days]`).textContent), b, { timeout: 5000 });
      if (sql(`SELECT stage FROM ka_deals WHERE id = ${b}`).trim() === 'new') { throw new Error('the keyboard move was not saved'); }
      await visit(`/admin.php?module=pipeline&action=detail&id=${a}`);
      if (!/^12 days \(since /.test(await page.locator('[data-deal-age]').innerText())) { throw new Error('the detail says: ' + await page.locator('[data-deal-age]').innerText()); }
      await page.locator('[data-deal-next] a[href="#tasks"]').click();
      if (!(await page.locator('#tasks').isVisible())) { throw new Error('the next step does not lead to the tasks'); }
      await visit('/admin.php?module=my_work&action=summary&period=7_days');
      if (!(await page.locator(`[data-team-summary] tr[data-person="${admin}"]`).count()) || !(await page.locator('[data-team-summary] tr[data-person="team"]').count())) { throw new Error('the summary has no row of the administrator or of the team'); }
      const state = await context.storageState();
      for (const scheme of ['light', 'dark']) {
        for (const [label, width, height] of [['desktop', 1440, 900], ['phone', 375, 812]]) {
          const other = await browser.newContext({ viewport: { width, height }, locale: 'en-GB', colorScheme: scheme, storageState: state, ...(width < 500 ? { isMobile: true, hasTouch: true } : {}) });
          const p2 = await other.newPage(); watch(p2);
          try {
            for (const url of ['/admin.php?module=my_work&action=summary&period=7_days', '/admin.php?module=pipeline', `/admin.php?module=pipeline&action=detail&id=${a}`]) {
              await p2.goto(`${BASE}${url}`, { waitUntil: 'networkidle' });
              const wide = await p2.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
              if (wide > 0) { throw new Error(`${wide} px of sideways scroll (${url}, ${label}, ${scheme})`); }
              if (url.includes('summary') && label === 'phone') {
                // one card per row: the cells stack under the person's name, each with its label
                const [name, cell] = await Promise.all([p2.locator(`tr[data-person="${admin}"] th`).boundingBox(), p2.locator(`tr[data-person="${admin}"] td[data-count="tasks_done"]`).boundingBox()]);
                if (!(cell.y > name.y + name.height - 1)) { throw new Error(`the summary is not a card per row at 375 px (${scheme})`); }
              }
              if (SHOTS) { await p2.screenshot({ path: `${SHOTS}/341a-${url.includes('summary') ? 'summary' : url.includes('detail') ? 'deal' : 'board'}-${label}-${scheme}.png`, fullPage: true }); }
            }
          } finally {
            await other.close();
          }
        }
      }
      const axeContext = await browser.newContext({ storageState: state, bypassCSP: true, viewport: { width: 1440, height: 900 } });
      try {
        const axePage = await axeContext.newPage();
        for (const [url, include] of [['/admin.php?module=my_work&action=summary&period=7_days', ['[data-team-summary]', '[data-team-summary-period]']], ['/admin.php?module=pipeline', [`#deal-${a}`]],
          [`/admin.php?module=pipeline&action=detail&id=${a}`, ['[data-deal-card]']]]) {
          for (const scheme of ['light', 'dark']) {
            await axePage.emulateMedia({ colorScheme: scheme });
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
      await page.setViewportSize({ width: 1440, height: 900 });
      sql(`DELETE FROM ka_tasks WHERE title LIKE '%A341%'; DELETE FROM ka_deal_stage_history WHERE deal_id IN (${a}, ${b}); DELETE FROM ka_deals WHERE id IN (${a}, ${b})`);
    }
  });

  await step('3.41 a draft preview runs under its Content-Security-Policy: the head code runs with the nonce, a script without it does not', async () => {
    const saved = sql("SELECT hodnota FROM ka_nastaveni WHERE promenna = 'head_code'").trim();
    const firstError = errors.length;
    try {
      sql("REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('head_code', '<script>window.s341Head = 1;</script>')");
      const response = await page.goto(BASE + '/?build=koncept', { waitUntil: 'networkidle' });
      const policy = response?.headers()['content-security-policy'] || '';
      if (!/^script-src 'self' 'nonce-[A-Za-z0-9_-]{24,}'/.test(policy)) { throw new Error(`no preview policy: ${policy}`); }
      // a script the page did not write (no nonce) is refused – Chrome reports the violation, which this step expects
      const ran = await page.evaluate(() => new Promise((resolve) => {
        document.addEventListener('securitypolicyviolation', () => resolve('blocked'), { once: true });
        const s = document.createElement('script'); s.textContent = 'window.s341Injected = 1;'; document.body.append(s);
        setTimeout(() => resolve(window.s341Injected === 1 ? 'ran' : 'silent'), 1000);
      }));
      const head = await page.evaluate(() => window.s341Head === 1);
      await page.waitForTimeout(500); // the console report of the violation arrives a moment after the event
      errors.splice(firstError, errors.length - firstError, ...errors.slice(firstError).filter((e) => !/Content Security Policy/.test(e))); // the refused script above, on purpose
      if (!head || ran !== 'blocked') { throw new Error(`head code ran: ${head}, injected script: ${ran}`); }
    } finally {
      sql(`REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('head_code', '${saved.replace(/\\/g, '\\\\').replace(/'/g, "''")}')`);
    }
  });

  // 3.42 A: Internal files – a folder for everyone, an upload with a description and tags through the form, the file's detail with
  // its versions; the list and the detail fit 1440 and 375 px without sideways scroll, light and dark, axe
  await step('3.42 A: Internal files – upload through the form, the list and the file detail at 1440 and 375 px, light/dark, axe', async () => {
    if (!MYSQL.length) { throw new Error('MYSQL_JSON is not set'); }
    sql("DELETE FROM ka_internal_file_versions WHERE file_id IN (SELECT id FROM ka_internal_files WHERE name LIKE '%A342%'); DELETE FROM ka_internal_files WHERE name LIKE '%A342%'; DELETE FROM ka_internal_folders WHERE name LIKE '%A342%'");
    const folder = Number(sql("INSERT INTO ka_internal_folders (name, description, audience, roles, created_at, updated_at) VALUES ('Contracts and price sheets A342', 'Signed contracts and the current price sheets', 'all', '', NOW(), NOW()); SELECT LAST_INSERT_ID();").trim());
    try {
      await page.setViewportSize({ width: 1440, height: 900 });
      await visit(`/admin.php?module=internal_files&folder=${folder}`);
      // 3.47 (U66): Upload files is a <details> that looks like a button, open on an empty folder; two files in one go
      const upload = page.locator('details[data-internal-upload]');
      if ((await upload.locator('> summary').innerText()).trim() !== 'Upload files' || !(await upload.getByText('Upload a file to Contracts and price sheets A342').count())) { throw new Error('no upload form for the folder'); }
      if (!(await upload.evaluate((d) => d.open))) { await upload.locator('> summary').click(); }
      await page.setInputFiles('#if-file', [{ name: 'Onboarding checklist A342.md', mimeType: 'text/markdown', buffer: Buffer.from('# Onboarding\n\n- keys\n- laptop\n') },
        { name: 'Price sheet A342.csv', mimeType: 'text/csv', buffer: Buffer.from('item;price\nBracket;12\n') }]);
      await page.fill('#if-description', 'What a new colleague gets in the first week');
      await page.fill('#if-tags', 'HR, onboarding');
      await Promise.all([page.waitForNavigation(), upload.locator('form button[type=submit]').click()]);
      const file = Number(sql("SELECT id FROM ka_internal_files WHERE name = 'Onboarding checklist A342.md'").trim());
      if (!file || !Number(sql("SELECT COUNT(*) FROM ka_internal_files WHERE name = 'Price sheet A342.csv'").trim())) { throw new Error('the two files were not both stored'); }
      if (!new RegExp(`folder=${folder}`).test(page.url()) || !/(Stored|Uloženo): 2/.test(await page.locator('main').innerText())) { throw new Error(`two files do not go back to the folder with "Stored: 2": ${page.url()}`); }
      if (!(await page.locator('table.vypis a.stitek', { hasText: 'onboarding' }).count())) { throw new Error('the list does not show the file with its tags'); }
      const state = await context.storageState();
      const urls = [`/admin.php?module=internal_files&folder=${folder}`, `/admin.php?module=internal_files&action=file&id=${file}`, `/admin.php?module=internal_files&action=folder&id=${folder}`];
      for (const scheme of ['light', 'dark']) {
        for (const [label, width, height] of [['desktop', 1440, 900], ['phone', 375, 812]]) {
          const other = await browser.newContext({ viewport: { width, height }, locale: 'en-GB', colorScheme: scheme, storageState: state, ...(width < 500 ? { isMobile: true, hasTouch: true } : {}) });
          const p2 = await other.newPage(); watch(p2);
          try {
            for (const url of urls) {
              await p2.goto(`${BASE}${url}`, { waitUntil: 'networkidle' });
              const wide = await p2.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
              if (wide > 0) { throw new Error(`${wide} px of sideways scroll (${url}, ${label}, ${scheme})`); }
              if (SHOTS) { await p2.screenshot({ path: `${SHOTS}/342a-${url.includes('action=file') ? 'file' : url.includes('action=folder') ? 'folder' : 'list'}-${label}-${scheme}.png`, fullPage: true }); }
            }
          } finally {
            await other.close();
          }
        }
      }
      const axeContext = await browser.newContext({ storageState: state, bypassCSP: true, viewport: { width: 1440, height: 900 } });
      try {
        const axePage = await axeContext.newPage();
        for (const url of urls) {
          for (const scheme of ['light', 'dark']) {
            await axePage.emulateMedia({ colorScheme: scheme });
            await axePage.goto(BASE + url, { waitUntil: 'networkidle' });
            await axePage.addScriptTag({ path: AXE });
            const found = await axePage.evaluate(([tags]) => window.axe.run({ include: [['main']] }, { runOnly: { type: 'tag', values: tags }, resultTypes: ['violations'] }), [WCAG]);
            if (found.violations.length) { throw new Error(`axe ${url} (${scheme}): ` + found.violations.map((v) => `${v.id} ${v.nodes[0].target.join(' ')}`).join(', ')); }
          }
        }
      } finally {
        await axeContext.close();
      }
    } finally {
      await page.setViewportSize({ width: 1440, height: 900 });
      sql(`DELETE FROM ka_internal_file_versions WHERE file_id IN (SELECT id FROM ka_internal_files WHERE folder_id = ${folder}); DELETE FROM ka_internal_files WHERE folder_id = ${folder}; DELETE FROM ka_internal_folders WHERE id = ${folder}`);
    }
  });

  await step('3.42 B: the team hierarchy – Users → Departments, the org chart at 1440 and 390 px (light/dark, axe, no script, printable) and an own absence in My account', async () => {
    if (!MYSQL.length) { throw new Error('MYSQL_JSON is not set'); }
    sql("INSERT INTO ka_departments (name, head_id, parent_id, enquiry_forms, created_at, updated_at) VALUES ('Sales B342', NULL, NULL, '', NOW(), NOW())");
    const dep = sql("SELECT id FROM ka_departments WHERE name = 'Sales B342'").trim();
    sql(`INSERT INTO ka_departments (name, head_id, parent_id, enquiry_forms, created_at, updated_at) VALUES ('Export with a rather long department name B342', NULL, ${dep}, '', NOW(), NOW());
    INSERT INTO ka_uzivatele (user, password, jmeno, email, pozice, phone, department_id) VALUES ('head-b342', 'x', 'Head B342 With A Rather Long Name', 'head.with.a.long.address.b342@browser.example', 'Head of sales', '+420 603 342 000', ${dep})`);
    const head = sql("SELECT idu FROM ka_uzivatele WHERE user = 'head-b342'").trim();
    const admin = sql("SELECT idu FROM ka_uzivatele WHERE user = 'admin'").trim();
    sql(`UPDATE ka_departments SET head_id = ${head} WHERE id = ${dep}; UPDATE ka_uzivatele SET reports_to = ${head} WHERE user = 'admin';
    INSERT INTO ka_absences (user_id, starts_on, ends_on, deputy_id, reason, created_at) VALUES (${head}, CURDATE(), CURDATE() + INTERVAL 3 DAY, ${admin}, 'holiday', NOW())`);
    try {
      await visit('/admin.php?module=users&action=departments');
      if (!(await page.locator('table.vypis', { hasText: 'Sales B342' }).count()) || !(await page.locator('#department-name').count())) { throw new Error('Users → Departments lacks the tree or the form'); }
      const state = await context.storageState();
      for (const scheme of ['light', 'dark']) {
        for (const [label, width, height] of [['desktop', 1440, 900], ['phone', 390, 844]]) {
          const other = await browser.newContext({ viewport: { width, height }, locale: 'en-GB', colorScheme: scheme, storageState: state, javaScriptEnabled: false, ...(width < 500 ? { isMobile: true, hasTouch: true } : {}) });
          const p2 = await other.newPage(); watch(p2);
          try {
            await p2.goto(`${BASE}/admin.php?action=account&tab=org_chart`, { waitUntil: 'networkidle' });
            const card = p2.locator('.organigram .kolega', { hasText: 'Head B342' });
            if ((await card.count()) !== 1 || !(await card.isVisible())) { throw new Error(`the head's card is missing (${label}, ${scheme})`); }
            const text = await card.innerText();
            if (!/Head/.test(text) || !/Away until/.test(text) || /Holiday/.test(text)) { throw new Error(`the head's card: ${text}`); }
            if (!(await p2.locator('.organigram .organigram', { hasText: 'Export with a rather long department name B342' }).isVisible())) { throw new Error('the sub-department is not nested'); }
            const wide = await p2.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
            if (wide > 0) { throw new Error(`${wide} px of sideways scroll on the org chart (${label}, ${scheme})`); }
            if (SHOTS) { await p2.screenshot({ path: `${SHOTS}/342b-org-chart-${label}-${scheme}.png`, fullPage: true }); }
            if (label === 'desktop' && scheme === 'light') {
              await p2.emulateMedia({ media: 'print' });
              if (await p2.locator('.hlavicka').isVisible()) { throw new Error('the header bar prints with the org chart'); }
              await p2.emulateMedia({ media: 'screen' });
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
          await axePage.goto(BASE + '/admin.php?action=account&tab=org_chart', { waitUntil: 'networkidle' });
          await axePage.addScriptTag({ path: AXE });
          const found = await axePage.evaluate(([inc, tags]) => window.axe.run({ include: inc.map((x) => [x]) }, { runOnly: { type: 'tag', values: tags }, resultTypes: ['violations'] }), [['nav.zalozky', 'ul.organigram'], WCAG]);
          if (found.violations.length) { throw new Error(`axe org chart (${scheme}): ` + found.violations.map((v) => `${v.id} ${v.nodes[0].target.join(' ')}`).join(', ')); }
        }
      } finally {
        await axeContext.close();
      }
      // an own absence in My account at phone width: two dates, a reason from the list, the deputy
      await page.setViewportSize({ width: 390, height: 844 });
      await visit('/admin.php?action=account&tab=absence');
      const from = sql('SELECT CURDATE() + INTERVAL 7 DAY').trim();
      const to = sql('SELECT CURDATE() + INTERVAL 9 DAY').trim();
      await page.fill('#absence-from', from);
      await page.fill('#absence-to', to);
      await page.selectOption('#absence-reason', 'trip');
      await page.selectOption('#absence-deputy', head);
      const wide = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
      if (wide > 0) { throw new Error(`${wide} px of sideways scroll on My account with the absences`); }
      await Promise.all([page.waitForNavigation(), page.locator('#absences form.formular button[type="submit"]').click()]);
      if (sql(`SELECT CONCAT(starts_on, '|', ends_on, '|', reason, '|', deputy_id) FROM ka_absences WHERE user_id = ${admin}`).trim() !== `${from}|${to}|trip|${head}`) { throw new Error('the own absence was not saved'); }
      if (!(await page.locator('#absences .nepritomnosti li').count())) { throw new Error('the saved absence is not listed'); }
    } finally {
      await page.setViewportSize({ width: 1440, height: 900 });
      sql(`DELETE FROM ka_absences WHERE user_id IN (${head}, ${admin}); UPDATE ka_uzivatele SET reports_to = NULL, department_id = NULL WHERE user = 'admin';
      DELETE FROM ka_uzivatele WHERE idu = ${head}; DELETE FROM ka_departments WHERE name LIKE '%B342'`);
    }
  });
};
