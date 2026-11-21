// Kaleta browser steps 0330 (tools/test-browser.mjs runs the files of this folder in number order on one site – the order is
// the contract; tools/tests/README.md). 3.49 B: one absence (the booking person's form and the booking detail) and files on a
// record (the box on a deal and a customer, the file's page).
export default async (t) => {
  const { BASE, SHOTS, MYSQL, sql, browser, context, page, watch, step, visit, AXE, WCAG } = t;

  // a screen at 1440 and 390 px, light and dark: no sideways scroll, a screenshot each, and axe (WCAG A/AA) on the parts named
  const screens = async (name, urls, include) => {
    const state = await context.storageState();
    for (const scheme of ['light', 'dark']) {
      for (const [label, width, height] of [['desktop', 1440, 900], ['phone', 390, 844]]) {
        const other = await browser.newContext({ viewport: { width, height }, locale: 'en-GB', colorScheme: scheme, storageState: state, bypassCSP: true, ...(width < 500 ? { isMobile: true, hasTouch: true } : {}) });
        const p2 = await other.newPage(); watch(p2);
        try {
          for (const [key, url] of Object.entries(urls)) {
            await p2.goto(`${BASE}${url}`, { waitUntil: 'networkidle' });
            const wide = await p2.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
            if (wide > 0) { throw new Error(`${wide} px of sideways scroll (${key}, ${label}, ${scheme})`); }
            if (SHOTS) { await p2.screenshot({ path: `${SHOTS}/349b-${name}-${key}-${label}-${scheme}.png`, fullPage: true }); }
            if (label === 'desktop') {
              await p2.addScriptTag({ path: AXE });
              const found = await p2.evaluate(([inc, tags]) => window.axe.run({ include: inc.filter((x) => document.querySelector(x)).map((x) => [x]) }, { runOnly: { type: 'tag', values: tags }, resultTypes: ['violations'] }), [include, WCAG]);
              if (found.violations.length) { throw new Error(`axe ${key} (${scheme}): ` + found.violations.map((v) => `${v.id} ${v.nodes[0].target.join(' ')}`).join(', ')); }
            }
          }
        } finally { await other.close(); }
      }
    }
  };

  // 3.49 B (one absence): an absence of the account a booking person is linked to – their form lists it (days only, where it
  // is set), the booking detail labels the booking and its proposed time "the person is away" in the warning badge
  await step('3.49 B: the booking person with an absence from the account and the "away" label on the booking – 1440 and 390 px, light/dark, axe', async () => {
    if (!MYSQL.length) { throw new Error('MYSQL_JSON is not set'); }
    const extensions = sql("SELECT IFNULL((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'extensions'), '')").trim();
    const admin = Number(sql("SELECT idu FROM ka_uzivatele WHERE user = 'admin'").trim());
    sql(`REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('extensions', '${extensions === '' ? 'novinky,poptavky,customers,pipeline,statistika,presmerovani,claude' : extensions},bookings')`);
    const service = Number(sql("INSERT INTO ka_booking_services (name, duration_min, buffer_min, active) VALUES ('Fitting B349', 60, 0, 1); SELECT LAST_INSERT_ID();").trim());
    const staff = Number(sql(`INSERT INTO ka_booking_staff (name, email, active, user_id) VALUES ('Olga B349', 'olga-b349@example.com', 1, ${admin}); SELECT LAST_INSERT_ID();`).trim());
    const booking = Number(sql(`INSERT INTO ka_booking_staff_services (staff_id, service_id) VALUES (${staff}, ${service});
      INSERT INTO ka_absences (user_id, starts_on, ends_on, reason, created_at) VALUES (${admin}, CURDATE() + INTERVAL 3 DAY, CURDATE() + INTERVAL 4 DAY, 'holiday', NOW());
      INSERT INTO ka_bookings (service_id, staff_id, starts_at, ends_at, name, email, status, hold_until, token_hash, created_at, source)
        VALUES (${service}, ${staff}, CURDATE() + INTERVAL 3 DAY + INTERVAL 10 HOUR, CURDATE() + INTERVAL 3 DAY + INTERVAL 11 HOUR, 'Karel Novák', 'karel-b349@example.com', 'pending', NOW() + INTERVAL 2 DAY, SHA2('b349', 256), NOW(), 'admin');
      SELECT LAST_INSERT_ID();`).trim());
    sql(`INSERT INTO ka_booking_proposals (booking_id, starts_at, ends_at) VALUES (${booking}, CURDATE() + INTERVAL 4 DAY + INTERVAL 9 HOUR, CURDATE() + INTERVAL 4 DAY + INTERVAL 10 HOUR)`);
    try {
      await page.setViewportSize({ width: 1440, height: 900 });
      await visit(`/admin.php?module=bookings&action=staff_edit&id=${staff}`);
      const listed = (await page.locator('[data-staff-absences] li').allInnerTexts()).length;
      if (listed !== 1 || !(await page.locator('[data-staff-absences]').innerText()).includes("they close this person's times")) { throw new Error(`the form lists ${listed} absences or misses the hint`); }
      if ((await page.locator('[data-staff-absences]').innerText()).includes('Holiday')) { throw new Error('the form shows the reason of the absence'); }
      await visit(`/admin.php?module=bookings&action=detail&id=${booking}`);
      const labels = await page.locator('[data-booking-away]').count();
      if (labels !== 2) { throw new Error(`${labels} "away" labels on the booking detail (the booking and its proposed time expected)`); }
      const badge = await page.locator('[data-booking-away]').first().evaluate((el) => ({ cls: el.className, colour: getComputedStyle(el).color }));
      if (badge.cls !== 'stitek stitek-koncept') { throw new Error('the label is not the warning badge: ' + badge.cls); }
      await screens('booking', { staff: `/admin.php?module=bookings&action=staff_edit&id=${staff}`, detail: `/admin.php?module=bookings&action=detail&id=${booking}` }, ['main']);
    } finally {
      sql(`DELETE FROM ka_bookings WHERE id = ${booking}; DELETE FROM ka_absences WHERE user_id = ${admin}; DELETE FROM ka_booking_staff WHERE id = ${staff}; DELETE FROM ka_booking_services WHERE id = ${service};
        REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('extensions', '${extensions}'); DELETE FROM ka_nastaveni WHERE promenna = 'extensions' AND hodnota = ''`);
    }
  });

  // 3.49 B (files on a record): a deal without a file shows only the closed "Link a file"; a file is chosen by search (the
  // combobox of 3.49 C) and linked; the deal lists it with Unlink; a customer without a file keeps the one closed line; the
  // file's page lists "Linked to"
  await step('3.49 B: the internal files of a deal and a customer (without and with rows) and the file page – link by search, 1440 and 390 px, light/dark, axe', async () => {
    const extensions = sql("SELECT IFNULL((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'extensions'), '')").trim();
    sql(`REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('extensions', '${extensions === '' ? 'novinky,poptavky,statistika,presmerovani,claude' : extensions},customers,pipeline')`);
    const folder = Number(sql("INSERT INTO ka_internal_folders (name, audience, roles, created_at, updated_at) VALUES ('Sales B349', 'all', '', NOW(), NOW()); SELECT LAST_INSERT_ID();").trim());
    const file = Number(sql(`INSERT INTO ka_internal_files (folder_id, name, status, extension, size, version, created_at, updated_at) VALUES (${folder}, 'Price sheet B349.pdf', 'ready', 'pdf', 20480, 1, NOW(), NOW()); SELECT LAST_INSERT_ID();`).trim());
    const deal = Number(sql("INSERT INTO ka_deals (title, stage, created_at, updated_at) VALUES ('Shelving for the warehouse B349', 'new', NOW(), NOW()); SELECT LAST_INSERT_ID();").trim());
    const contact = Number(sql("INSERT INTO ka_contacts (email, email_norm, name, source, created_at, updated_at, last_activity_at) VALUES ('mira-b349@example.com', 'mira-b349@example.com', 'Mira B349', 'manual', NOW(), NOW(), NOW()); SELECT LAST_INSERT_ID();").trim());
    try {
      await page.setViewportSize({ width: 1440, height: 900 });
      await visit(`/admin.php?module=pipeline&action=detail&id=${deal}`);
      const box = page.locator('[data-internal-files-box="deal"]');
      if ((await box.locator('[data-internal-file-row]').count()) !== 0 || (await box.locator('details[data-internal-link]').getAttribute('open')) !== null) { throw new Error('the empty box is not one closed "Link a file"'); }
      await screens('box-empty', { deal: `/admin.php?module=pipeline&action=detail&id=${deal}` }, ['#internal-files']);
      await box.locator('details[data-internal-link] > summary').click();
      const field = box.locator('input[role="combobox"]');
      await field.fill('Price sheet B3');
      await page.waitForSelector('[data-internal-files-box] [role="listbox"] [role="option"]', { timeout: 5000 });
      await page.waitForTimeout(400);
      await field.press('ArrowDown');
      const active = await field.getAttribute('aria-activedescendant');
      const row = active ? (await page.locator(`#${active}`).innerText()) : '';
      if (!row.startsWith('—') && !row.includes('Price sheet B349.pdf')) { throw new Error('the first rows of the file search: ' + row); }
      if (row.startsWith('—')) { await field.press('ArrowDown'); }
      await field.press('Enter');
      if ((await box.locator('select[name="file_id"]').inputValue()) !== String(file)) { throw new Error('the chosen file is not the select\'s value'); }
      await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), box.locator('button[type="submit"]', { hasText: 'Link a file' }).click()]);
      if (sql(`SELECT COUNT(*) FROM ka_internal_file_links WHERE file_id = ${file} AND kind = 'deal' AND record_id = ${deal}`).trim() !== '1') { throw new Error('the file was not linked'); }
      if ((await page.locator('[data-internal-files-box="deal"] [data-internal-file-row]').count()) !== 1) { throw new Error('the deal does not list the linked file'); }
      await visit(`/admin.php?module=contacts&action=detail&id=${contact}`);
      if ((await page.locator('[data-internal-files-box="contact"] [data-internal-file-row]').count()) !== 0) { throw new Error('the customer lists a file of the deal'); }
      await visit(`/admin.php?module=internal_files&action=file&id=${file}`);
      if (!(await page.locator('[data-internal-links]').innerText()).includes('Shelving for the warehouse B349')) { throw new Error('the file page does not name the deal'); }
      await screens('box', { deal: `/admin.php?module=pipeline&action=detail&id=${deal}`, customer: `/admin.php?module=contacts&action=detail&id=${contact}`, file: `/admin.php?module=internal_files&action=file&id=${file}` },
        ['#internal-files', '[data-internal-links]']);
    } finally {
      sql(`DELETE FROM ka_internal_file_links WHERE file_id = ${file}; DELETE FROM ka_internal_files WHERE id = ${file}; DELETE FROM ka_internal_folders WHERE id = ${folder}; DELETE FROM ka_deals WHERE id = ${deal}; DELETE FROM ka_contacts WHERE id = ${contact};
        REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('extensions', '${extensions}'); DELETE FROM ka_nastaveni WHERE promenna = 'extensions' AND hodnota = ''`);
    }
  });
};
