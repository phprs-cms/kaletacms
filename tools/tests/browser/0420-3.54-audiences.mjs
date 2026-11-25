// Kaleta browser steps 0420 (tools/test-browser.mjs runs the files of this folder in number order on one site – the order is
// the contract; tools/tests/README.md). 3.54 – who a newsletter goes to: a new newsletter starts with everyone confirmed
// although the previous one went to Czech customers (owner decision 1), the page says how many it is now, and Send is
// disabled with the reason for an empty audience and for "only customers" with the pipeline and quotes off (A4); a form
// with the news consent shows one unticked, optional box above its button on the web (B2). The browser site is installed
// in English; Czech is the second language.
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
  // the extensions switched on and off by name, the rest kept
  const extensions = (on, off) => php(`$s = $app->settings(); Kaleta\\Core\\Extensions::save($s, array_values(array_diff(array_unique([...Kaleta\\Core\\Extensions::enabled($s), ...${JSON.stringify(on)}]), ${JSON.stringify(off)})));`);

  await step('3.54 A4: a new newsletter starts with everyone confirmed (not the previous one\'s Czech customers), shows how many it is now, and Send is disabled with the reason for an empty or a blocked audience', async () => {
    const back = remember(['extensions', 'additional_languages', 'mail_mode', 'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_user', 'mail_from', 'tasks_last_run',
      'company_name', 'company_street', 'company_city', 'company_postcode']);
    try {
      // a site that can send (so a disabled Send is the audience's doing), with Czech as the second language
      sql(`REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('mail_mode', 'smtp'), ('smtp_host', '127.0.0.1'), ('smtp_port', '${Number(SMTP_PORT) || 1}'),
        ('smtp_encryption', 'zadne'), ('smtp_user', ''), ('mail_from', 'web@browser.example'), ('tasks_last_run', UNIX_TIMESTAMP()),
        ('company_name', 'Browser 354 Ltd'), ('company_street', 'Main Street 1'), ('company_city', 'Brno'), ('company_postcode', '602 00'), ('additional_languages', 'cs')`);
      extensions(['newsletter', 'jazyky'], []);
      sql(`DELETE FROM ka_odberatele WHERE email LIKE 'b354-%@example.com';
        INSERT INTO ka_odberatele (email, stav, token, datum, potvrzeno, language) VALUES ('b354-1@example.com', 1, REPLACE(UUID(), '-', ''), NOW(), NOW(), ''), ('b354-2@example.com', 1, REPLACE(UUID(), '-', ''), NOW(), NOW(), '');
        INSERT INTO ka_newsletters (subject, intro, news_mode, status, language, audience, audience_filter, author, created, changed)
        VALUES ('B354 previous', 'Hello', 'none', 'draft', 'cs', 'language', 'customers', (SELECT idu FROM ka_uzivatele WHERE user = 'admin'), NOW(), NOW())`);
      try {
        await visit('/admin.php?module=newsletters&action=new');
        if (!(await page.locator('input[name="audience"][value="all"]').isChecked())) { throw new Error('a new newsletter does not start with everyone confirmed'); }
        if (await page.locator('input[name="audience"][value="language"]').isChecked()) { throw new Error('a new newsletter took the previous one\'s audience "language"'); }
        const customers = page.locator('input[type="checkbox"][name="audience_filter"]');
        if (await customers.count() > 0 && await customers.isChecked()) { throw new Error('a new newsletter took the previous one\'s "Only customers"'); }
        await page.fill('#subject', 'B354 audiences');
        await page.fill('#intro', 'Hello from the browser.');
        if (await page.locator('input[name="news_mode"][value="none"]').count() > 0) { await page.check('input[name="news_mode"][value="none"]'); }
        await Promise.all([page.waitForNavigation(), page.locator('form[action*="action=save"] input.tl[type="submit"]').first().click()]);
        const id = Number(sql("SELECT id FROM ka_newsletters WHERE subject = 'B354 audiences' ORDER BY id DESC LIMIT 1").trim());
        if (!id) { throw new Error('the new newsletter was not saved'); }
        const stored = sql(`SELECT CONCAT(audience, '|', audience_filter) FROM ka_newsletters WHERE id = ${id}`).trim();
        if (stored !== 'all|') { throw new Error(`the saved audience is "${stored}", all with no filter expected`); }

        const send = page.locator('form[action*="action=send"] button[type="submit"]');
        const expectCount = async (label) => {
          const all = Number(sql('SELECT COUNT(*) FROM ka_odberatele WHERE stav = 1').trim());
          await visit(`/admin.php?module=newsletters&action=edit&id=${id}`);
          const text = page.locator('p', { hasText: `${all} confirmed subscribers.` });
          if (!(await text.first().isVisible())) { throw new Error(`${label}: the count text "${all} confirmed subscribers." is not visible`); }
          if (await send.isDisabled()) { throw new Error(`${label}: Send is disabled for an audience of ${all}`); }
          const posted = await page.locator('form[action*="action=send"] input[name="expected"]').inputValue();
          if (Number(posted) !== all) { throw new Error(`${label}: the send form posts ${posted} as the confirmed number, ${all} expected`); }
          if (!((await send.textContent()) || '').includes(String(all))) { throw new Error(`${label}: the Send button does not name ${all}`); }
        };
        await expectCount('everyone');
        for (const scheme of ['light', 'dark']) {
          await page.emulateMedia({ colorScheme: scheme });
          if (SHOTS) { await page.screenshot({ path: `${SHOTS}/420-audience-count-${scheme}.png`, fullPage: true }); }
        }
        await page.emulateMedia({ colorScheme: 'light' });

        // the subscribers of the newsletter's language: nobody confirmed in Czech here
        await page.check('input[name="audience"][value="language"]');
        await page.selectOption('#language', 'cs');
        await Promise.all([page.waitForNavigation(), page.locator('form[action*="action=save"] input.tl[type="submit"]').first().click()]);
        if (sql(`SELECT CONCAT(audience, '|', language) FROM ka_newsletters WHERE id = ${id}`).trim() !== 'language|cs') { throw new Error('the audience "language" in Czech was not saved'); }
        const czech = Number(sql("SELECT COUNT(*) FROM ka_odberatele WHERE stav = 1 AND language = 'cs'").trim());
        await visit(`/admin.php?module=newsletters&action=edit&id=${id}`);
        if (czech === 0) {
          if (!(await page.locator('.hlaska.hlaska-varovani', { hasText: 'language' }).first().isVisible())) { throw new Error('an empty Czech audience shows no reason'); }
          if (!(await send.isDisabled())) { throw new Error('Send is enabled for an empty audience'); }
          if (await page.locator('p', { hasText: 'confirmed subscribers (' }).count() !== 0) { throw new Error('an empty audience still shows a count text'); }
        } else if (!(await page.locator('p', { hasText: `${czech} confirmed subscribers (` }).first().isVisible())) {
          throw new Error(`the Czech audience of ${czech} shows no count text`);
        }

        // "only customers" while neither the sales pipeline nor quotes is on: refused with the reason, never sent to everyone
        sql(`UPDATE ka_newsletters SET audience = 'all', audience_filter = 'customers' WHERE id = ${id}`);
        extensions([], ['pipeline', 'quotes']);
        await visit(`/admin.php?module=newsletters&action=edit&id=${id}`);
        if (!(await page.locator('.hlaska.hlaska-varovani', { hasText: 'Only customers' }).first().isVisible())) { throw new Error('the blocked "Only customers" shows no reason'); }
        if (!(await send.isDisabled())) { throw new Error('Send is enabled for a blocked audience'); }
        if (await page.locator('input[type="checkbox"][name="audience_filter"]').count() !== 1) { throw new Error('the stored "Only customers" is not shown, so it cannot be switched off'); }
        if (SHOTS) { await page.screenshot({ path: `${SHOTS}/420-audience-blocked.png`, fullPage: true }); }
      } finally {
        sql("DELETE FROM ka_newsletters WHERE subject IN ('B354 previous', 'B354 audiences'); DELETE FROM ka_odberatele WHERE email LIKE 'b354-%@example.com'");
      }
    } finally {
      await page.emulateMedia({ colorScheme: 'light' });
      back();
    }
  });

  await step('3.54 B2: a form with the news consent shows one unticked, optional box above its button on the web, apart from the required processing consent; a form without it has none', async () => {
    const back = remember(['extensions']);
    const wording = 'B354: send me news and offers by e-mail.';
    try {
      extensions(['newsletter', 'poptavky'], []);
      php(`[$b] = Kaleta\\Builder\\Build::sanitize(["deti" => [
          ["typ" => "formular", "id" => "nf354", "obsah" => ["nazev" => "News 354", "souhlas_novinky" => "${wording}", "pole" => [
            ["popisek" => "Name", "typ" => "text", "povinne" => true], ["popisek" => "E-mail", "typ" => "email", "povinne" => true], ["popisek" => "I agree to the processing 354", "typ" => "souhlas", "povinne" => true]]]],
          ["typ" => "formular", "id" => "pf354", "obsah" => ["nazev" => "Plain 354", "pole" => [["popisek" => "E-mail", "typ" => "email", "povinne" => true]]]]]]);
        $db->insert("stranky", ["seo_link" => "b354-form", "titulek" => "Form 354", "text" => "", "zobrazit" => 1, "v_menu" => 0, "zmeneno" => date("Y-m-d H:i:s"), "stavba" => json_encode($b, JSON_UNESCAPED_UNICODE)]);`);
      // a visitor, not the signed-in admin
      const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'en-GB' });
      try {
        const p = await ctx.newPage();
        await p.goto(BASE + '/b354-form', { waitUntil: 'networkidle' });
        const withConsent = p.locator('form', { has: p.locator('input[name="ka_news"]') });
        if (await withConsent.count() !== 1) { throw new Error(`${await withConsent.count()} forms carry the news consent box, 1 expected`); }
        const box = withConsent.locator('input[name="ka_news"]');
        if (await box.count() !== 1 || await box.getAttribute('type') !== 'checkbox') { throw new Error('the news consent is not one checkbox'); }
        if (await box.isChecked()) { throw new Error('the news consent box is ticked in advance'); }
        if (await box.evaluate((el) => el.required || el.getAttribute('aria-required') === 'true')) { throw new Error('the news consent box is required'); }
        const label = await box.evaluate((el) => (el.closest('label') || el.labels?.[0])?.textContent || '');
        if (!label.includes(wording)) { throw new Error(`the box is labelled "${label.trim()}", not with the form's wording`); }
        const processing = withConsent.locator('input[type="checkbox"]:not([name="ka_news"])');
        if (await processing.count() !== 1 || !(await processing.evaluate((el) => el.required))) { throw new Error('the processing consent is not a separate, required box'); }
        const above = await box.evaluate((el) => {
          const button = el.form && el.form.querySelector('button[type="submit"], input[type="submit"]');
          return !!button && !!(el.compareDocumentPosition(button) & Node.DOCUMENT_POSITION_FOLLOWING);
        });
        if (!above) { throw new Error('the news consent box is not above the form\'s button'); }
        if (await p.locator('input[name="ka_news"]').count() !== 1) { throw new Error('the form without the wording shows a news consent box too'); }
        if (SHOTS) { await p.screenshot({ path: `${SHOTS}/420-form-news-consent.png`, fullPage: true }); }
      } finally {
        await ctx.close();
      }
    } finally {
      sql("DELETE FROM ka_stranky WHERE seo_link = 'b354-form'");
      back();
    }
  });
};
