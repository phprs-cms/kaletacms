// Kaleta browser steps 0310 (tools/test-browser.mjs runs the files of this folder in number order on one site – the order is
// the contract; tools/tests/README.md). 3.49 A: saved replies, the attachments of a reply and of the quote e-mail.
export default async (t) => {
  const { BASE, SHOTS, MYSQL, sql, browser, context, page, watch, step, visit, axeViolations, SMTP_DIR, SMTP_PORT, WEB_DIR, latestMail, execFileSync } = t;

  // the saved replies screen: the open "Add a reply" row is filled and saved; the rows are <details>; at 1440 and 390 px in light
  // and dark nothing scrolls sideways and axe finds nothing
  await step('3.49 A saved replies: add one on the screen, then the screen at 1440 and 390 px, light/dark, no sideways scroll, axe', async () => {
    if (!MYSQL.length) { throw new Error('MYSQL_JSON is not set'); }
    sql('DELETE FROM ka_saved_replies');
    await page.setViewportSize({ width: 1440, height: 900 });
    await visit('/admin.php?module=enquiries&action=saved_replies');
    const row = page.locator('details[data-saved-reply-row][open]');
    if ((await row.count()) !== 1 || (await row.locator('summary').innerText()).trim() !== 'Add a reply') { throw new Error('no open "Add a reply" row on an empty screen'); }
    await row.locator('input[name$="[name]"]').fill('Price list A349');
    await row.locator('input[name$="[subject]"]').fill('Our prices – {{topic}}');
    await row.locator('textarea[name$="[text]"]').fill('Hello {{name}},\nthe price list of {{company}} is attached.');
    await Promise.all([page.waitForNavigation(), page.locator('form[data-saved-replies] button.tl[type="submit"]').click()]);
    if (sql("SELECT COUNT(*) FROM ka_saved_replies WHERE name = 'Price list A349' AND language = ''").trim() !== '1') { throw new Error('the saved reply was not stored'); }
    if ((await page.locator('details[data-saved-reply-row] summary', { hasText: 'Price list A349' }).count()) !== 1) { throw new Error('the saved reply is not a row of its own after saving'); }
    const state = await context.storageState();
    const problems = [];
    for (const scheme of ['light', 'dark']) {
      for (const [label, width, height] of [['desktop', 1440, 900], ['phone', 390, 844]]) {
        const other = await browser.newContext({ viewport: { width, height }, locale: 'en-GB', colorScheme: scheme, storageState: state, bypassCSP: true, ...(width < 500 ? { isMobile: true, hasTouch: true } : {}) });
        const p2 = await other.newPage(); watch(p2);
        try {
          await p2.goto(`${BASE}/admin.php?module=enquiries&action=saved_replies`, { waitUntil: 'networkidle' });
          await p2.locator('details[data-saved-reply-row] summary', { hasText: 'Price list A349' }).click();
          const wide = await p2.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
          if (wide > 1) { problems.push(`${wide} px of sideways scroll (${label}, ${scheme})`); }
          problems.push(...await axeViolations(p2, `saved replies ${label} ${scheme}`));
          if (SHOTS) { await p2.screenshot({ path: `${SHOTS}/349a-saved-replies-${label}-${scheme}.png`, fullPage: true }); }
        } finally {
          await other.close();
        }
      }
    }
    if (problems.length) { throw new Error(problems.join('; ')); }
  });

  // the reply form: the saved reply fills the text (the draft was untouched), an internal file is added by search and an upload is
  // chosen; an upload over 5 MB is refused before anything is sent; the confirmation names two files; the e-mail carries both
  await step('3.49 A reply: a saved reply, an internal file by search and an upload; over 5 MB refused before sending; two files in the confirmation and the e-mail', async () => {
    if (!SMTP_DIR || !SMTP_PORT || !WEB_DIR) { throw new Error('SMTP_DIR, SMTP_PORT and WEB_DIR are not set (tools/test-browser.sh)'); }
    const setting = (key) => `IFNULL((SELECT hodnota FROM ka_nastaveni WHERE promenna = '${key}'), '')`;
    const saved = sql(`SELECT CONCAT_WS('|', ${['mail_mode', 'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_user', 'mail_from'].map(setting).join(', ')})`).trim();
    sql(`REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('mail_mode', 'smtp'), ('smtp_host', '127.0.0.1'), ('smtp_port', '${Number(SMTP_PORT)}'), ('smtp_encryption', 'zadne'), ('smtp_user', ''), ('mail_from', 'web@browser.example')`);
    sql("DELETE FROM ka_internal_file_versions WHERE file_id IN (SELECT id FROM ka_internal_files WHERE name LIKE '%A349%'); DELETE FROM ka_internal_files WHERE name LIKE '%A349%'; DELETE FROM ka_internal_folders WHERE name LIKE '%A349%'");
    const folder = Number(sql("INSERT INTO ka_internal_folders (name, description, audience, roles, created_at, updated_at) VALUES ('Price sheets A349', '', 'all', '', NOW(), NOW()); SELECT LAST_INSERT_ID();").trim());
    sql("INSERT INTO ka_poptavky (datum, formular, stranka, tema, email, data, stav, navrh_odpovedi) VALUES (NOW(), 'Enquiry A349', '/contact', 'Oak windows', 'olga349@example.com', '[[\"Name\",\"Olga Berg\"],[\"Message\",\"What do the windows cost?\"]]', 0, 'Hello Olga,')");
    const id = sql("SELECT MAX(idp) FROM ka_poptavky WHERE email = 'olga349@example.com'").trim();
    try {
      await page.setViewportSize({ width: 1440, height: 900 });
      await visit(`/admin.php?module=internal_files&folder=${folder}`);
      const upload = page.locator('details[data-internal-upload]');
      if (!(await upload.evaluate((d) => d.open))) { await upload.locator('> summary').click(); }
      await page.setInputFiles('#if-file', { name: 'Ceník oken A349.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4\n% A349\n1 0 obj << >> endobj\ntrailer << >>\n%%EOF\n') });
      await Promise.all([page.waitForNavigation(), upload.locator('form button[type=submit]').click()]);
      const file = Number(sql("SELECT id FROM ka_internal_files WHERE name LIKE '%oken A349.pdf'").trim());
      if (!file) { throw new Error(`the internal file was not stored: ${(await page.locator('.hlaska').allInnerTexts()).join(' / ')} (${page.url()})`); }

      await visit(`/admin.php?module=enquiries&action=detail&id=${id}`);
      const form = page.locator('form[data-reply-form]');
      if (await page.locator('[data-saved-reply-insert]').isVisible()) { throw new Error('the no-script Insert is shown with the script'); }
      await page.selectOption('#odpoved-ulozena', { label: 'Price list A349' });
      await page.waitForFunction(() => document.querySelector('#odpoved-text').value.includes('price list of'));
      if (await form.locator('textarea[name="text"]').inputValue() !== 'Hello Olga Berg,\nthe price list of ' + sql("SELECT IF(IFNULL((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'company_name'), '') <> '', (SELECT hodnota FROM ka_nastaveni WHERE promenna = 'company_name'), (SELECT hodnota FROM ka_nastaveni WHERE promenna = 'site_name'))").trim() + ' is attached.') {
        throw new Error(`the saved reply did not replace the untouched draft: ${await form.locator('textarea[name="text"]').inputValue()}`);
      }
      if (await form.locator('input[name="subject"]').inputValue() !== 'Our prices – Oak windows') { throw new Error('the subject was not taken from the saved reply'); }
      // the internal file by search: the combobox over the select of recent files
      const combo = page.locator('#odpoved-pridat-soubor');
      await combo.fill('Ceník oken');
      await page.locator('.vyber-seznam [role="option"]', { hasText: 'Ceník oken A349.pdf' }).first().click();
      if ((await form.locator(`[data-attach-list] input[name="internal_files[]"][value="${file}"]:checked`).count()) !== 1) { throw new Error('the chosen internal file is not a ticked row'); }
      if (await page.locator('[data-attach-add-button]').isVisible()) { throw new Error('the no-script Add is shown with the script'); }
      // over 5 MB: refused before the confirmation, nothing sent
      await page.setInputFiles('#odpoved-nahrat', { name: 'big.pdf', mimeType: 'application/pdf', buffer: Buffer.alloc(5_100_000, 65) });
      await form.locator('button[type="submit"]', { hasText: 'Send reply' }).click();
      await page.locator('[data-attach-error]:visible').waitFor();
      if ((await page.locator('dialog.potvrzeni[open]').count()) !== 0) { throw new Error('the confirmation opened for attachments over the limit'); }
      if (!/too large together/.test(await page.locator('[data-attach-error]').innerText())) { throw new Error('the refusal does not say the attachments are too large'); }
      await page.setInputFiles('#odpoved-nahrat', { name: 'Plán montáže.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4\n% plan\n%%EOF\n') });
      if (SHOTS) { await page.locator('#reply').scrollIntoViewIfNeeded(); await page.screenshot({ path: `${SHOTS}/349a-reply-attachments.png`, fullPage: true }); }
      await form.locator('button[type="submit"]', { hasText: 'Send reply' }).click();
      const dialog = page.locator('dialog.potvrzeni[open]');
      await dialog.waitFor();
      if (!/olga349@example\.com now, with attachments \(2\)\?/.test(await dialog.textContent() || '')) { throw new Error(`the confirmation does not name two files: ${await dialog.textContent()}`); }
      await Promise.all([page.waitForNavigation(), dialog.locator('[data-ano]').click()]);
      await page.locator('.hlaska-ok', { hasText: 'The reply was sent to olga349@example.com' }).waitFor();
      const mail = latestMail('olga349@example.com') || '';
      const names = [...mail.matchAll(/filename="([^"]+)"/g)].map((m) => m[1]).sort();
      if (names.join(',') !== 'Cenik-oken-A349.pdf,Plan-montaze.pdf') { throw new Error(`the e-mail carries ${names.join(',') || 'no files'}`); }
      if ((await page.locator('[data-reply-files] li').count()) !== 2) { throw new Error('the conversation does not list the two files of the reply'); }
      // the form at 1440 and 390 px in light and dark: no sideways scroll, axe (a context that lets axe in past the CSP)
      const state = await context.storageState();
      const phone = [];
      for (const [scheme, width, height] of [['light', 1440, 900], ['dark', 1440, 900], ['light', 390, 844], ['dark', 390, 844]]) {
        const other = await browser.newContext({ viewport: { width, height }, locale: 'en-GB', colorScheme: scheme, storageState: state, bypassCSP: true, ...(width < 500 ? { isMobile: true, hasTouch: true } : {}) });
        const p2 = await other.newPage(); watch(p2);
        try {
          await p2.goto(`${BASE}/admin.php?module=enquiries&action=detail&id=${id}`, { waitUntil: 'networkidle' });
          const wide = await p2.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
          if (wide > 1) { phone.push(`${wide} px of sideways scroll (${width}, ${scheme})`); }
          phone.push(...await axeViolations(p2, `reply form ${width} ${scheme}`));
          if (SHOTS) { await p2.locator('#reply').scrollIntoViewIfNeeded(); await p2.screenshot({ path: `${SHOTS}/349a-reply-${width}-${scheme}.png`, fullPage: true }); }
        } finally {
          await other.close();
        }
      }
      if (phone.length) { throw new Error(phone.join('; ')); }
    } finally {
      await page.setViewportSize({ width: 1440, height: 900 });
      const [mode, host, port, encryption, user, from] = saved.split('|');
      sql(`REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('mail_mode', '${mode || 'mail'}'), ('smtp_host', '${host || ''}'), ('smtp_port', '${port || ''}'), ('smtp_encryption', '${encryption || ''}'), ('smtp_user', '${user || ''}'), ('mail_from', '${from || ''}');
        DELETE FROM ka_posta WHERE komu = 'olga349@example.com'; DELETE FROM ka_poptavky WHERE email = 'olga349@example.com'`);
    }
  });

  // the quote send dialog: the same Attachments block under the PDF box; a file chosen by search shows in the e-mail preview and
  // in the confirmation; at 1440 and 390 px in light and dark no sideways scroll, axe. Nothing is sent.
  await step('3.49 A quote send dialog: the Attachments block, a file by search in the preview and the confirmation, 1440/390 px light/dark, axe', async () => {
    const file = Number(sql("SELECT IFNULL(MAX(id), 0) FROM ka_internal_files WHERE name LIKE '%oken A349.pdf'").trim());
    const features = sql("SELECT IFNULL((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'extensions'), '')").trim();
    const setting = (key) => `IFNULL((SELECT hodnota FROM ka_nastaveni WHERE promenna = '${key}'), '')`;
    const saved = sql(`SELECT CONCAT_WS('|', ${['mail_mode', 'smtp_host', 'smtp_port', 'mail_from'].map(setting).join(', ')})`).trim();
    if (!features.split(',').includes('quotes')) { sql(`REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('extensions', '${[...features.split(',').filter(Boolean), 'quotes'].join(',')}')`); }
    sql(`REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('mail_mode', 'smtp'), ('smtp_host', '127.0.0.1'), ('smtp_port', '${Number(SMTP_PORT)}'), ('mail_from', 'web@browser.example')`);
    const quote = Number(execFileSync('php', ['-r', `require "system/bootstrap.php"; $app = Kaleta\\Core\\App::boot(); $app->applyTimezone();
      $u = (int) $app->db()->value("SELECT idu FROM {uzivatele} WHERE user = 'admin'");
      echo Kaleta\\Core\\Quotes::save($app, null, ["title" => "Windows A349", "customer" => ["name" => "Olga Berg", "email" => "olga349@example.com"],
        "lines" => [["name" => "Oak window A349", "quantity" => "1", "unit" => "pc", "unit_price" => "1000", "vat_rate" => "21"]]], $u)["id"];`], { cwd: WEB_DIR, encoding: 'utf8' }).trim()) || 0;
    try {
      if (!quote || !file) { throw new Error(`no draft quote (${quote}) or internal file (${file}) to work with`); }
      await page.setViewportSize({ width: 1440, height: 900 });
      await visit(`/admin.php?module=quotes&action=send&id=${quote}`);
      const form = page.locator('form[data-quote-send]');
      if ((await form.locator('[data-attachments]').count()) !== 1) { throw new Error('the send dialog has no Attachments block'); }
      await page.locator('#nabidka-pridat-soubor').fill('Ceník oken');
      await page.locator('.vyber-seznam [role="option"]', { hasText: 'Ceník oken A349.pdf' }).first().click();
      await form.locator('details[data-quote-mail-preview] > summary').click();
      if (!(await page.locator('[data-quote-mail-files]').isVisible()) || !/Ceník oken A349\.pdf/.test(await page.locator('[data-quote-mail-files]').innerText())) { throw new Error('the e-mail preview does not list the chosen file'); }
      await form.locator('button[type="submit"]', { hasText: 'Send the quote' }).click();
      const dialog = page.locator('dialog.potvrzeni[open]');
      await dialog.waitFor();
      if (!/with attachments \(1\)\?/.test(await dialog.textContent() || '')) { throw new Error(`the confirmation does not count the file: ${await dialog.textContent()}`); }
      await dialog.locator('[data-ne]').click();
      if (SHOTS) { await page.screenshot({ path: `${SHOTS}/349a-quote-send.png`, fullPage: true }); }
      const state = await context.storageState();
      const problems = [];
      for (const scheme of ['light', 'dark']) {
        for (const [label, width, height] of [['desktop', 1440, 900], ['phone', 390, 844]]) {
          const other = await browser.newContext({ viewport: { width, height }, locale: 'en-GB', colorScheme: scheme, storageState: state, bypassCSP: true, ...(width < 500 ? { isMobile: true, hasTouch: true } : {}) });
          const p2 = await other.newPage(); watch(p2);
          try {
            await p2.goto(`${BASE}/admin.php?module=quotes&action=send&id=${quote}`, { waitUntil: 'networkidle' });
            const wide = await p2.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
            if (wide > 1) { problems.push(`${wide} px of sideways scroll (${label}, ${scheme})`); }
            problems.push(...await axeViolations(p2, `quote send ${label} ${scheme}`));
            if (SHOTS) { await p2.screenshot({ path: `${SHOTS}/349a-quote-send-${label}-${scheme}.png`, fullPage: true }); }
          } finally {
            await other.close();
          }
        }
      }
      if (problems.length) { throw new Error(problems.join('; ')); }
      if (sql(`SELECT CONCAT(status, '|', number IS NULL) FROM ka_quotes WHERE id = ${quote}`).trim() !== 'draft|1') { throw new Error('the quote was sent'); }
    } finally {
      const [mode, host, port, from] = saved.split('|');
      sql(`DELETE FROM ka_quote_lines WHERE quote_id = ${quote}; DELETE FROM ka_quotes WHERE id = ${quote}; REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('extensions', '${features}'),
        ('mail_mode', '${mode || 'mail'}'), ('smtp_host', '${host || ''}'), ('smtp_port', '${port || ''}'), ('mail_from', '${from || ''}');
        DELETE FROM ka_saved_replies; DELETE FROM ka_internal_file_versions WHERE file_id IN (SELECT id FROM ka_internal_files WHERE name LIKE '%A349%'); DELETE FROM ka_internal_files WHERE name LIKE '%A349%'; DELETE FROM ka_internal_folders WHERE name LIKE '%A349%'`);
    }
  });
};
