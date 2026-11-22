// Kaleta browser steps 0380 (tools/test-browser.mjs runs the files of this folder in number order on one site – the order is
// the contract; tools/tests/README.md). 3.51 – honest sending: a send under way is paused, resumed and cancelled from the
// newsletter in light and dark mode (B1), Subscribers with languages and a bounce at phone width (A1, A2), and the three
// e-mail texts with the fields of a second language (R0.6). The browser site is installed in English; Czech is the second language.
export default async (t) => {
  const { BASE, browser, page, sql, step, visit, SHOTS, SMTP_PORT, WEB_DIR, execFileSync } = t;

  // settings a step changes, put back exactly as they were (a missing row stays missing)
  const remember = (keys) => {
    const list = keys.map((k) => `'${k}'`).join(', ');
    const rows = sql(`SELECT promenna, hodnota FROM ka_nastaveni WHERE promenna IN (${list})`).split('\n').filter(Boolean).map((l) => l.split('\t'));
    return () => {
      sql(`DELETE FROM ka_nastaveni WHERE promenna IN (${list})`);
      for (const [k, v] of rows) { sql(`INSERT INTO ka_nastaveni (promenna, hodnota) VALUES ('${k}', '${(v || '').replace(/\\/g, '\\\\').replace(/'/g, "''")}')`); }
    };
  };
  const php = (code) => execFileSync('php', ['-r', `require "system/bootstrap.php"; $app = Kaleta\\Core\\App::boot(); $db = $app->db(); ${code}`], { cwd: WEB_DIR, encoding: 'utf8' });
  const confirmDialog = async (submit) => {
    await submit.click();
    const dialog = page.locator('dialog.potvrzeni[open]');
    await dialog.waitFor();
    const text = (await dialog.textContent()) || '';
    await Promise.all([page.waitForNavigation(), dialog.locator('[data-ano]').click()]);
    return text;
  };
  const languagesOn = () => {
    const back = remember(['extensions', 'additional_languages']);
    php('$s = $app->settings(); Kaleta\\Core\\Extensions::save($s, array_values(array_unique([...Kaleta\\Core\\Extensions::enabled($s), "jazyky"]))); $s->set("additional_languages", "cs");');
    return back;
  };

  await step('3.51 B: a send under way is paused, resumed and cancelled from the newsletter – the stopped state reads in light and dark mode', async () => {
    const back = remember(['mail_mode', 'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_user', 'mail_from', 'tasks_last_run', 'company_name', 'company_street', 'company_city', 'company_postcode']);
    try {
      sql(`REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('mail_mode', 'smtp'), ('smtp_host', '127.0.0.1'), ('smtp_port', '${Number(SMTP_PORT) || 1}'),
        ('smtp_encryption', 'zadne'), ('smtp_user', ''), ('mail_from', 'web@browser.example'), ('tasks_last_run', UNIX_TIMESTAMP()),
        ('company_name', 'Browser 351 Ltd'), ('company_street', 'Main Street 1'), ('company_city', 'Brno'), ('company_postcode', '602 00')`);
      sql(`DELETE FROM ka_odberatele WHERE email LIKE 'b351-%@example.com';
        INSERT INTO ka_odberatele (email, stav, token, datum, potvrzeno) VALUES ('b351-1@example.com', 1, REPLACE(UUID(), '-', ''), NOW(), NOW()), ('b351-2@example.com', 1, REPLACE(UUID(), '-', ''), NOW(), NOW()), ('b351-3@example.com', 1, REPLACE(UUID(), '-', ''), NOW(), NOW())`);
      const id = Number(sql(`INSERT INTO ka_newsletters (subject, intro, news_mode, status, recipients, started_at, html, text, author, created, changed)
        VALUES ('B351 pause and resume', 'Hello', 'none', 'sending', 3, NOW(), '<p>Hello</p>', 'Hello', (SELECT idu FROM ka_uzivatele WHERE user = 'admin'), NOW(), NOW()); SELECT LAST_INSERT_ID();`).trim());
      // the queue waits a day, so nothing leaves while the step runs
      sql(`INSERT INTO ka_newsletter_queue (newsletter_id, subscriber_id, next_attempt) SELECT ${id}, ido, NOW() + INTERVAL 1 DAY FROM ka_odberatele WHERE email LIKE 'b351-%@example.com'`);
      const state = () => sql(`SELECT CONCAT_WS('|', status, pause_reason, resumed_at IS NOT NULL, skipped_count, recipients = sent_count + failed_count + skipped_count) FROM ka_newsletters WHERE id = ${id}`).trim();
      try {
        await visit(`/admin.php?module=newsletters&action=edit&id=${id}`);
        await Promise.all([page.waitForNavigation(), page.locator('form[action*="action=pause"] button[type="submit"]').click()]);
        if (!state().startsWith('paused|manual|0')) { throw new Error(`after Pause: ${state()}`); }
        const notice = page.locator('.hlaska.hlaska-varovani').first();
        if (!(await notice.isVisible())) { throw new Error('the paused newsletter shows no warning'); }
        const looks = [];
        for (const scheme of ['light', 'dark']) {
          await page.emulateMedia({ colorScheme: scheme });
          const look = await notice.evaluate((el) => ({ color: getComputedStyle(el).color, background: getComputedStyle(el).backgroundColor }));
          if (look.color === look.background) { throw new Error(`${scheme}: the warning text has the colour of its background (${look.color})`); }
          const resume = page.locator('form[action*="action=resume"] button[type="submit"]');
          if (!(await resume.isVisible()) || await resume.isDisabled()) { throw new Error(`${scheme}: Resume is not offered on a newsletter that can go on`); }
          looks.push(look.color);
          if (SHOTS) { await page.screenshot({ path: `${SHOTS}/380-paused-${scheme}.png`, fullPage: true }); }
        }
        await page.emulateMedia({ colorScheme: 'light' });
        if (looks[0] === looks[1]) { throw new Error(`the warning text has the same colour in light and dark mode (${looks[0]})`); }
        if (await page.locator(`form[action*="action=delete"] input[name="id"][value="${id}"]`).count() !== 0) { throw new Error('a paused newsletter offers Delete'); }

        const asked = await confirmDialog(page.locator('form[action*="action=resume"] button[type="submit"]'));
        if (!asked.includes('3')) { throw new Error(`the resume question does not say how many are left: "${asked.trim()}"`); }
        if (!state().startsWith('sending||1')) { throw new Error(`after Resume: ${state()}`); }

        await Promise.all([page.waitForNavigation(), page.locator('form[action*="action=pause"] button[type="submit"]').click()]);
        await confirmDialog(page.locator('form[action*="action=cancel"] button[type="submit"]'));
        const ended = sql(`SELECT CONCAT_WS('|', status, skipped_count, recipients = sent_count + failed_count + skipped_count, finished_at IS NOT NULL) FROM ka_newsletters WHERE id = ${id}`).trim();
        if (ended !== 'cancelled|3|1|1') { throw new Error(`after Cancel (status|skipped|recipients = sent + failed + skipped|finished): ${ended}`); }
        const kept = sql(`SELECT CONCAT(COUNT(*), '|', SUM(next_attempt IS NULL), '|', SUM(error = 'cancelled')) FROM ka_newsletter_queue WHERE newsletter_id = ${id}`).trim();
        if (kept !== '3|3|3') { throw new Error(`the cancelled rows are not kept for the hourly limit: ${kept}`); }
        await visit('/admin.php?module=newsletters');
        if (SHOTS) { await page.screenshot({ path: `${SHOTS}/380-cancelled-list.png`, fullPage: true }); }
        if (await page.locator(`form[action*="action=delete"] input[name="id"][value="${id}"]`).count() !== 1) { throw new Error('a cancelled newsletter cannot be deleted from the list'); }
      } finally {
        sql(`DELETE FROM ka_newsletter_queue WHERE newsletter_id = ${id}; DELETE FROM ka_newsletters WHERE id = ${id}; DELETE FROM ka_odberatele WHERE email LIKE 'b351-%@example.com'`);
      }
    } finally {
      await page.emulateMedia({ colorScheme: 'light' });
      back();
    }
  });

  await step('3.51 A: Subscribers at 390 px – counts per language, the Language column and a bounce lifted, no sideways page scroll in light and dark', async () => {
    const back = languagesOn();
    try {
      sql(`DELETE FROM ka_odberatele WHERE email LIKE 'a351-%@example.com';
        INSERT INTO ka_odberatele (email, stav, token, datum, potvrzeno, zdroj, language) VALUES
        ('a351-en@example.com', 1, REPLACE(UUID(), '-', ''), NOW(), NOW(), '/', ''), ('a351-cs@example.com', 1, REPLACE(UUID(), '-', ''), NOW(), NOW(), '/cs/', 'cs'),
        ('a351-waiting-a-very-long-address-without-any-break@example.com', 0, REPLACE(UUID(), '-', ''), NOW(), NULL, '/cs/', 'cs')`);
      php('Kaleta\\Core\\SendRegister::record($db, "bounced", "a351-cs@example.com");');
      const ctx = await browser.newContext({ storageState: await page.context().storageState(), viewport: { width: 390, height: 844 }, locale: 'en-GB' });
      try {
        const p = await ctx.newPage();
        await p.goto(BASE + '/admin.php?module=subscribers&search=a351-', { waitUntil: 'networkidle' });
        if (await p.locator('[data-subscriber-counts]').count() !== 1) { throw new Error('no counts line above the table'); }
        if (await p.locator('[data-register="bounced"]').count() !== 1) { throw new Error('the bounced address has no mark'); }
        for (const scheme of ['light', 'dark']) {
          await p.emulateMedia({ colorScheme: scheme });
          const wide = await p.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
          if (wide > 1) { throw new Error(`${scheme}: ${wide} px of sideways page scroll`); }
          if (SHOTS) { await p.screenshot({ path: `${SHOTS}/380-subscribers-phone-${scheme}.png`, fullPage: true }); }
        }
        await p.emulateMedia({ colorScheme: 'light' });
        await p.locator('form[action*="action=lift"] button[type="submit"]').click();
        const dialog = p.locator('dialog.potvrzeni[open]');
        await dialog.waitFor();
        await Promise.all([p.waitForNavigation(), dialog.locator('[data-ano]').click()]);
        if (await p.locator('[data-register="bounced"]').count() !== 0) { throw new Error('the bounce mark stays after Lift'); }
        const left = php('echo Kaleta\\Core\\SendRegister::blocked($db, "a351-cs@example.com") ?? "none";').trim();
        if (left !== 'none') { throw new Error(`the address is still on the register: ${left}`); }
      } finally {
        await ctx.close();
      }
    } finally {
      php('Kaleta\\Core\\SendRegister::lift($db, "bounced", "a351-cs@example.com");');
      sql("DELETE FROM ka_odberatele WHERE email LIKE 'a351-%@example.com'");
      back();
    }
  });

  await step('3.51 R0: Settings → Mail – the three e-mail texts are marked default, a second language has its own fields, a text without its placeholder is refused', async () => {
    const back = languagesOn();
    const texts = remember(['mail_identity', 'newsletter_reason', 'mail_refuse_text', 'mail_identity_cs', 'newsletter_reason_cs', 'mail_refuse_text_cs']);
    sql("DELETE FROM ka_nastaveni WHERE promenna IN ('mail_identity', 'newsletter_reason', 'mail_refuse_text', 'mail_identity_cs', 'newsletter_reason_cs', 'mail_refuse_text_cs')");
    const save = () => Promise.all([page.waitForNavigation(), page.locator('input[name="mail_identity"]').evaluate((el) => { el.form.noValidate = true; el.form.requestSubmit(el.form.querySelector('.vychozi-odeslani, [type="submit"]:not([formaction])')); })]);
    try {
      await visit('/admin.php?module=settings&tab=mail');
      for (const key of ['mail_identity', 'newsletter_reason', 'mail_refuse_text']) {
        const input = page.locator(`input[name="${key}"]`);
        if (await input.inputValue() !== '' || !(await input.getAttribute('placeholder'))) { throw new Error(`${key}: an empty field with the default as its placeholder was expected`); }
      }
      const details = page.locator('details', { has: page.locator('input[name="mail_refuse_text_cs"]') });
      if (await details.count() !== 1) { throw new Error('the fields of the Czech version are missing'); }
      await details.locator('summary').click();
      const placeholder = await page.locator('input[name="mail_identity_cs"]').getAttribute('placeholder');
      if (!/\{\{company\}\}/.test(placeholder || '') || !/\{\{address\}\}/.test(placeholder || '')) { throw new Error(`the Czech placeholder is not the default sender line: "${placeholder}"`); }
      // the browser's own pattern check is switched off (noValidate), so the server refuses a refuse line without {{link}} and marks the field
      await page.fill('input[name="mail_refuse_text_cs"]', 'Napište nám, pokud nechcete další e-maily');
      await save();
      if (await page.locator('input[name="mail_refuse_text_cs"][aria-invalid="true"]').count() !== 1) { throw new Error('a refuse line without {{link}} is not marked invalid'); }
      if (!(await page.locator('details', { has: page.locator('input[name="mail_refuse_text_cs"]') }).evaluate((el) => el.open))) { throw new Error('the language fields stay closed around an invalid field'); }
      const stored = () => sql("SELECT IFNULL((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'mail_refuse_text_cs'), '')").trim();
      if (stored() !== '') { throw new Error(`the invalid text was saved: ${stored()}`); }
      if (SHOTS) { await page.screenshot({ path: `${SHOTS}/380-mail-texts-invalid.png`, fullPage: true }); }
      // ASCII only: the mysql client of sql() reads the stored text in its own character set
      await page.fill('input[name="mail_refuse_text_cs"]', 'Nechcete dalsi e-maily? Napiste nam zde: {{link}}');
      await save();
      if (stored() !== 'Nechcete dalsi e-maily? Napiste nam zde: {{link}}') { throw new Error(`the Czech refuse line was not saved: "${stored()}"`); }
      if (await page.locator('input[name="mail_refuse_text"]').inputValue() !== '') { throw new Error('the base field took the Czech text'); }
    } finally {
      texts();
      back();
    }
  });
};
