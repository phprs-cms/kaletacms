// Kaleta browser steps 0220 (tools/test-browser.mjs runs the files of this folder in number order on one site – the order is
// the contract; tools/tests/README.md). Moved verbatim from the single file in 3.49 P1.
export default async (t) => {
  const { BASE, SHOTS, MYSQL, sql, browser, context, page, watch, step, visit, axeViolations, fs } = t;

  // 3.28 (agent B): Customers – the list (cards on a phone), a customer's detail with the timeline, "Log a call / meeting" from
  // the chips, the company suggestion, the merge screen side by side, the box on an enquiry and the palette search; 1440 and
  // 390 px, light and dark, no sideways scroll
  await step('3.28 customers: list, detail, log a meeting, company suggestion, merge screen, enquiry box, palette (1440 and 390 px, light/dark)', async () => {
    if (!MYSQL.length) { throw new Error('MYSQL_JSON is not set'); }
    sql("DELETE FROM ka_contacts WHERE email_norm LIKE '%b328%'; DELETE FROM ka_companies WHERE name LIKE '%B328%'");
    const eva = Number(sql(`INSERT INTO ka_contacts (email, email_norm, name, phone, language, source, summary, first_seen_at, last_activity_at, created_at, updated_at)
    VALUES ('eva.b328@northwind-b328.example', 'eva.b328@northwind-b328.example', 'Eva Longname-Doubleservice B328', '+44 20 7946 0328', 'en', 'enquiry', 'Prefers e-mail. Asked about the yearly service plan for all three offices.', NOW() - INTERVAL 20 DAY, NOW() - INTERVAL 2 DAY, NOW(), NOW()); SELECT LAST_INSERT_ID();`).trim());
    const other = Number(sql(`INSERT INTO ka_contacts (email, email_norm, name, phone, language, source, first_seen_at, last_activity_at, created_at, updated_at)
    VALUES ('eva.private.b328@gmail.com', 'eva.private.b328@gmail.com', 'Eva B328', '', '', 'subscriber', NOW() - INTERVAL 5 DAY, NOW() - INTERVAL 5 DAY, NOW(), NOW()); SELECT LAST_INSERT_ID();`).trim());
    const enquiry = Number(sql(`INSERT INTO ka_poptavky (datum, formular, email, data, stav, contact_id) VALUES (NOW() - INTERVAL 2 DAY, 'Contact B328', 'eva.b328@northwind-b328.example', '[["Message","Secret message B328"]]', 1, ${eva}); SELECT LAST_INSERT_ID();`).trim());
    const submit = async (locator) => { await Promise.all([page.waitForNavigation(), locator.click()]); };
    try {
      await page.setViewportSize({ width: 1440, height: 900 });
      await visit('/admin.php?module=contacts&search=B328');
      if ((await page.locator('[data-contacts-list] tr[data-contact]').count()) !== 2) { throw new Error('the search does not list both customers'); }
      await submit(page.locator(`tr[data-contact="${eva}"] td:first-child a`));
      if (!(await page.locator('h1').innerText()).includes('Eva Longname-Doubleservice B328')) { throw new Error('the detail is not titled after the customer'); }
      if ((await page.locator('[data-timeline-entry="enquiry"]').count()) !== 1) { throw new Error('the enquiry is not on the timeline'); }
      // the company of the e-mail domain is suggested; one click creates it and adds her
      await submit(page.locator('form[data-company-suggestion] button'));
      await page.locator('.hlaska-ok', { hasText: 'The contact was saved.' }).waitFor();
      if (!(await page.locator('[data-contact-card] dd a', { hasText: 'Northwind' }).count())) { throw new Error('the suggested company was not added'); }
      // log a meeting from the chips
      await page.locator('.zakaznik-druh label', { hasText: 'Meeting' }).click();
      await page.locator('#zapis-text').fill('Met at the trade fair – sends the floor plans by Friday.');
      await submit(page.locator('form[data-activity-form] button[type="submit"]'));
      await page.locator('.hlaska-ok', { hasText: 'Logged.' }).waitFor();
      const first = page.locator('[data-timeline-list] [data-timeline-entry]').first();
      if ((await first.getAttribute('data-timeline-entry')) !== 'meeting' || !(await first.innerText()).includes('floor plans')) { throw new Error('the meeting is not first on the timeline'); }
      if (SHOTS) { await page.screenshot({ path: `${SHOTS}/328-contact-detail.png`, fullPage: true }); }
      // the merge screen: both side by side
      await visit(`/admin.php?module=contacts&action=merge&id=${eva}&with=${other}`);
      const sides = await page.locator('[data-merge-side]').evaluateAll((els) => els.map((el) => el.getBoundingClientRect().top));
      if (sides.length !== 2 || Math.abs(sides[0] - sides[1]) > 2) { throw new Error(`the two contacts are not side by side at 1440 px: ${JSON.stringify(sides)}`); }
      if (SHOTS) { await page.screenshot({ path: `${SHOTS}/328-merge.png` }); }
      // the box on the enquiry
      await visit(`/admin.php?module=enquiries&action=detail&id=${enquiry}`);
      const box = page.locator(`[data-contact-box="${eva}"]`);
      if (!(await box.innerText()).includes('Eva Longname-Doubleservice B328')) { throw new Error('the enquiry does not show its customer'); }
      // the palette finds her by the company
      await page.keyboard.press('Control+k');
      await page.locator('#paleta .paleta-pole').fill('Northwind');
      await page.locator('#paleta .paleta-seznam a', { hasText: 'Eva Longname-Doubleservice B328' }).waitFor({ timeout: 5000 });
      await page.keyboard.press('Escape');
      // light and dark, desktop and phone: no sideways scroll, readable text; the list becomes cards on a phone
      const state = await context.storageState();
      for (const scheme of ['light', 'dark']) {
        for (const [label, width, height] of [['desktop', 1440, 900], ['phone', 390, 844]]) {
          const other2 = await browser.newContext({ viewport: { width, height }, locale: 'en-GB', colorScheme: scheme, storageState: state, ...(width < 500 ? { isMobile: true, hasTouch: true } : {}) });
          const p2 = await other2.newPage(); watch(p2);
          try {
            for (const url of ['/admin.php?module=contacts&search=B328', `/admin.php?module=contacts&action=detail&id=${eva}`, `/admin.php?module=contacts&action=merge&id=${eva}&with=${other}`]) {
              await p2.goto(`${BASE}${url}`, { waitUntil: 'networkidle' });
              const wide = await p2.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
              if (wide > 0) { throw new Error(`${wide} px of sideways scroll (${url}, ${label}, ${scheme})`); }
              if (url.includes('search=B328')) {
                const display = await p2.locator('[data-contacts-list] tbody tr').first().evaluate((el) => getComputedStyle(el).display);
                if ((label === 'phone') !== (display === 'block')) { throw new Error(`the list rows are ${display} on the ${label}`); }
              }
              if (url.includes('action=detail')) {
                const colours = await p2.locator('[data-contact-card]').evaluate((el) => [getComputedStyle(el).backgroundColor, getComputedStyle(el.querySelector('dd')).color]);
                if (colours[0] === colours[1]) { throw new Error(`the card text has the colour of the card (${label}, ${scheme})`); }
              }
              if (SHOTS) { await p2.screenshot({ path: `${SHOTS}/328-${url.includes('merge') ? 'merge' : url.includes('detail') ? 'detail' : 'list'}-${label}-${scheme}.png`, fullPage: true }); }
            }
          } finally {
            await other2.close();
          }
        }
      }
    } finally {
      await page.setViewportSize({ width: 1440, height: 900 });
      sql(`DELETE FROM ka_contact_activities WHERE contact_id IN (${eva}, ${other}); DELETE FROM ka_poptavky WHERE idp = ${enquiry};
      DELETE FROM ka_contacts WHERE id IN (${eva}, ${other}); DELETE FROM ka_companies WHERE domain = 'northwind-b328.example'`);
    }
  });

  // 3.28 (D-W12): off-site backups are encrypted at rest – until the recovery key is saved the copies wait and the Backups tab
  // says so plainly; the key is shown on a page of its own (readable at 1440 and 390 px, light and dark), confirmed with a tick,
  // and never shown again
  await step('3.28 Backups: the recovery key of the encrypted off-site copies – paused until saved, shown once, confirmed (1440 and 390 px, light/dark)', async () => {
    const keyFile = `${process.env.WEB_DIR}/storage/backup-key.php`;
    sql(`REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('remote_backup', 's3'), ('backup_host', 's3.example.com'), ('backup_folder', 'kaleta-zalohy'), ('backup_key_confirmed', '')`);
    const submit = async (locator) => { await Promise.all([page.waitForNavigation(), locator.click()]); };
    try {
      await visit('/admin.php?module=settings&tab=backups');
      const state = page.locator('#recovery-key-state');
      if (!(await state.evaluate((el) => el.classList.contains('hlaska-chyba'))) || !(await state.innerText()).includes('Off-site copies are paused')) { throw new Error(`the Backups tab does not say the copies wait: ${await state.innerText()}`); }
      await submit(state.locator('button', { hasText: 'Show and save the recovery key' }));
      const shown = (await page.locator('#recovery-key').innerText()).trim();
      if (!/^KBK1(-[A-Z2-7]{4}){13}$/.test(shown)) { throw new Error(`the recovery key looks wrong: ${shown}`); }
      if (!(await page.locator('button[data-kopirovat="#recovery-key"]').count()) || !(await page.locator('form[action*="backup_key_download"] button').count())) { throw new Error('no Copy or Download for the key'); }
      // light and dark, desktop and phone: the key page fits without sideways scrolling and the key is readable
      const stored = await context.storageState();
      for (const scheme of ['light', 'dark']) {
        for (const [label, width, height] of [['desktop', 1440, 900], ['phone', 390, 844]]) {
          const other = await browser.newContext({ viewport: { width, height }, locale: 'en-GB', colorScheme: scheme, storageState: stored, ...(width < 500 ? { isMobile: true, hasTouch: true } : {}) });
          const p2 = await other.newPage(); watch(p2);
          try {
            await p2.goto(`${BASE}/admin.php?module=settings&tab=backups`, { waitUntil: 'networkidle' });
            await Promise.all([p2.waitForNavigation(), p2.locator('#recovery-key-state button', { hasText: 'Show and save the recovery key' }).click()]);
            if ((await p2.locator('#recovery-key').innerText()).trim() !== shown) { throw new Error(`another key was shown (${label}, ${scheme})`); }
            const wide = await p2.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
            if (wide > 0) { throw new Error(`${wide} px of sideways scroll (${label}, ${scheme})`); }
            const colours = await p2.locator('#recovery-key').evaluate((el) => [getComputedStyle(el.closest('.hlaska')).backgroundColor, getComputedStyle(el).color]);
            if (colours[0] === colours[1]) { throw new Error(`the key has the colour of its box (${label}, ${scheme})`); }
            if (SHOTS) { await p2.screenshot({ path: `${SHOTS}/328-recovery-key-${label}-${scheme}.png`, fullPage: true }); }
          } finally {
            await other.close();
          }
        }
      }
      // the tick is required, then the copies start and the key is not offered again
      await page.locator('form[action*="backup_key_confirm"] input[name="saved"]').check();
      await submit(page.locator('form[action*="backup_key_confirm"] button[type="submit"]'));
      await page.locator('.hlaska-ok', { hasText: 'The recovery key is saved.' }).waitFor();
      const after = page.locator('#recovery-key-state');
      if (!(await after.evaluate((el) => el.classList.contains('hlaska-ok'))) || !(await after.innerText()).includes('Recovery key saved') || await after.locator('button', { hasText: 'Show and save the recovery key' }).count()) { throw new Error(`the confirmed state is wrong: ${await after.innerText()}`); }
      if ((await page.content()).includes(shown)) { throw new Error('the key is shown again after the confirmation'); }
      if (SHOTS) { await after.scrollIntoViewIfNeeded(); await page.screenshot({ path: `${SHOTS}/328-backups-confirmed.png`, fullPage: true }); }
    } finally {
      sql(`UPDATE ka_nastaveni SET hodnota = 'vypnuto' WHERE promenna = 'remote_backup'; UPDATE ka_nastaveni SET hodnota = '' WHERE promenna IN ('backup_host', 'backup_folder', 'backup_key_confirmed')`);
      try { (await import('node:fs')).unlinkSync(keyFile); } catch (e) { /* none was made */ }
    }
  });
  // 3.31.1: an encrypted off-site copy uploaded back for a restore (….enc) is marked in the Backups list and never counts as a
  // backup made here – with only such a fresh copy in the folder, opening the administration still makes the automatic local
  // backup at once (before 3.31.1 the copy's upload time held it up); 1440 and 390 px, light and dark, no sideways scroll, axe
  await step('3.31.1 Backups: an uploaded copy is marked and does not hold up the automatic backup, 1440 and 390 px, light/dark, axe', async () => {
    const fs = await import('node:fs');
    const folder = `${process.env.WEB_DIR}/storage/zalohy`;
    const aside = `${process.env.WEB_DIR}/storage/zalohy-3311`;
    const copy = 'kaleta-20261109-030000-auto-b3311abc.sql.gz.enc';
    fs.mkdirSync(folder, { recursive: true });
    fs.mkdirSync(aside, { recursive: true });
    const local = fs.readdirSync(folder).filter((f) => /^kaleta-.*\.sql(\.gz)?$/.test(f));
    for (const f of local) { fs.renameSync(`${folder}/${f}`, `${aside}/${f}`); }
    fs.writeFileSync(`${folder}/${copy}`, Buffer.concat([Buffer.from([0x89, 0x4b, 0x42, 0x4b, 0x0d, 0x0a, 0x1a, 0x0a]), Buffer.alloc(64)]));
    try {
      await visit('/admin.php');
      const made = fs.readdirSync(folder).filter((f) => /^kaleta-.*-auto-.*\.sql(\.gz)?$/.test(f));
      if (made.length !== 1) { throw new Error(`the uploaded copy held up the automatic backup (${made.length} made)`); }
      if ((await page.content()).includes('The site has no database backup yet.')) { throw new Error('the dashboard warns although a backup was just made'); }
      const stored = await context.storageState();
      for (const scheme of ['light', 'dark']) {
        for (const [label, width, height] of [['desktop', 1440, 900], ['phone', 390, 844]]) {
          // bypassCSP: only so axe can be injected into the admin page (script-src 'self'), as the other admin axe checks do
          const other = await browser.newContext({ viewport: { width, height }, locale: 'en-GB', colorScheme: scheme, storageState: stored, bypassCSP: true, ...(width < 500 ? { isMobile: true, hasTouch: true } : {}) });
          const p2 = await other.newPage(); watch(p2);
          try {
            await p2.goto(`${BASE}/admin.php?module=settings&tab=backups`, { waitUntil: 'networkidle' });
            const row = p2.locator('table.vypis tr', { hasText: copy });
            const badge = row.locator('.stitek', { hasText: 'uploaded copy' });
            if (await badge.count() !== 1) { throw new Error(`the uploaded copy is not marked (${label}, ${scheme})`); }
            if (!(await badge.getAttribute('title') || '').includes('does not count as a backup made on this server')) { throw new Error('the badge does not say why'); }
            const wide = await p2.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
            if (wide > 0) { throw new Error(`${wide} px of sideways scroll (${label}, ${scheme})`); }
            const found = await axeViolations(p2, `backups ${label} ${scheme}`);
            if (found.length) { throw new Error(found.join('\n')); }
            if (SHOTS) { await row.scrollIntoViewIfNeeded(); await p2.screenshot({ path: `${SHOTS}/3311-uploaded-copy-${label}-${scheme}.png` }); }
          } finally {
            await other.close();
          }
        }
      }
    } finally {
      try { fs.unlinkSync(`${folder}/${copy}`); } catch (e) { /* gone already */ }
      for (const f of fs.readdirSync(folder).filter((f) => /^kaleta-.*\.sql(\.gz)?$/.test(f))) { fs.unlinkSync(`${folder}/${f}`); } // made during the step
      for (const f of local) { fs.renameSync(`${aside}/${f}`, `${folder}/${f}`); }
      fs.rmdirSync(aside);
    }
  });
  // 3.29: visits confirmed by a browser (Front\Stats::hit). Playwright's Chrome reports navigator.webdriver, so on a page as it is
  // the beacon must not go out at all; a context set up like a visitor's browser (a normal user agent, webdriver hidden – on the
  // test side only, the site has no switch for it) sends exactly one per page view, which counts exactly one view
  await step('3.29 visits confirmed by a browser: none under automation, exactly one per page view in a visitor’s browser, the note in Statistics', async () => {
    const total = () => sql("SELECT CONCAT(COALESCE(SUM(navstevy), 0), '/', COALESCE(SUM(zobrazeni), 0)) FROM ka_stat_dny").trim();
    const settle = async (before, expected) => {
      for (let i = 0; i < 20 && total() === before; i++) { await page.waitForTimeout(150); }
      await page.waitForTimeout(300); // a second beacon would arrive by now
      const [v0, p0] = before.split('/').map(Number);
      const [v1, p1] = total().split('/').map(Number);
      if (`${v1 - v0}/${p1 - p0}` !== expected) { throw new Error(`visits/views changed by ${v1 - v0}/${p1 - p0}, expected ${expected}`); }
    };
    const beacons = (p) => { const sent = []; p.on('request', (r) => { if (new URL(r.url()).pathname === '/hit') { sent.push(r.method()); } }); return sent; };
    const robot = await browser.newContext({ locale: 'en-GB' });
    try {
      const p = await robot.newPage();
      watch(p);
      const sent = beacons(p);
      const before = total();
      await p.goto(BASE + '/', { waitUntil: 'networkidle' });
      if (!(await p.evaluate(() => navigator.webdriver))) { throw new Error('the automated browser does not report navigator.webdriver'); }
      if (!(await p.locator('script[src*="/image/vitals.js"][data-hit="/hit"][data-hit-page="/"][data-hit-key]').count())) { throw new Error('the page carries no page-view beacon'); }
      await settle(before, '0/0');
      if (sent.length) { throw new Error(`${sent.length} page-view beacon(s) sent under browser automation`); }
    } finally {
      await robot.close();
    }
    const person = await browser.newContext({ locale: 'en-GB', userAgent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36' });
    await person.addInitScript(() => { Object.defineProperty(Navigator.prototype, 'webdriver', { get: () => false, configurable: true }); });
    try {
      const p = await person.newPage();
      watch(p);
      const sent = beacons(p);
      let before = total();
      await p.goto(BASE + '/', { waitUntil: 'networkidle' });
      await settle(before, '1/1');
      before = total();
      await p.reload({ waitUntil: 'networkidle' });
      await settle(before, '0/1'); // the same visitor again: one more page view, not another visit
      if (sent.length > 2) { throw new Error(`${sent.length} beacons for two page views`); }
    } finally {
      await person.close();
    }
    // the honest history: an older day with numbers counted the old way, and the Statistics screen says from when they are browser-confirmed
    sql("INSERT IGNORE INTO ka_stat_dny (den, navstevy, zobrazeni) VALUES (CURDATE() - INTERVAL 5 DAY, 0, 0)");
    await visit('/admin.php?module=stats&days=7');
    const note = page.locator('[data-stats-confirmed]');
    await note.waitFor();
    if (!/only visits confirmed by a visitor’s browser are counted/.test(await note.innerText())) { throw new Error(`the note says: ${await note.innerText()}`); }
    if (await page.locator('.graf-sloupec.graf-zacatek').count() !== 1) { throw new Error('the first day of browser-confirmed counting is not marked on the chart'); }
    if (SHOTS) { await page.screenshot({ path: `${SHOTS}/329-stats-confirmed.png`, fullPage: true }); }
  });
  // 3.29.1: the dashboard's chart of 14 days says it too – the day marked with a line, its bar's label and the note of Statistics
  await step('3.29.1 the dashboard chart marks the day browser-confirmed counting started and says why the visits dropped', async () => {
    await visit('/admin.php');
    const chart = page.locator('.prehled-graf');
    await chart.waitFor();
    const note = chart.locator('[data-stats-confirmed]');
    if (!/only visits confirmed by a visitor’s browser are counted/.test(await note.innerText())) { throw new Error(`the dashboard note says: ${await note.innerText()}`); }
    const line = chart.locator('line.prehled-zacatek');
    if (await line.count() !== 1) { throw new Error(`${await line.count()} marked days on the dashboard chart`); }
    const day = await line.getAttribute('data-stats-confirmed-day');
    if (day !== sql("SELECT hodnota FROM ka_nastaveni WHERE promenna = 'stats_confirmed_since'").trim()) { throw new Error(`the chart marks ${day}`); }
    if (await chart.locator('rect[aria-label*="only visits confirmed by a browser count from this day"]').count() !== 1) { throw new Error('the marked day\'s bar does not say it'); }
    const stroke = await line.evaluate((el) => getComputedStyle(el).stroke);
    if (!stroke || stroke === 'none') { throw new Error('the marker line is not drawn'); }
    if (SHOTS) { await chart.screenshot({ path: `${SHOTS}/3291-dashboard-confirmed.png` }); }
  });
};
