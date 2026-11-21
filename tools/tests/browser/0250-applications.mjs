// Kaleta browser steps 0250 (tools/test-browser.mjs runs the files of this folder in number order on one site – the order is
// the contract; tools/tests/README.md). Moved verbatim from the single file in 3.49 P1.
export default async (t) => {
  const { BASE, SHOTS, MYSQL, sql, browser, context, page, watch, step, visit, AXE, WCAG, execFileSync } = t;

  // 3.34 (A): job applications – the list and the detail at 1440 and 390 px, light and dark, axe; no sideways scroll on a phone
  await step('3.34 applications: the list and the detail – 1440 and 390 px, light/dark, axe', async () => {
    const extensions = sql("SELECT hodnota FROM ka_nastaveni WHERE promenna = 'extensions'").trim();
    sql(`REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('extensions', '${extensions === '' ? 'novinky,poptavky,customers,pipeline,statistika,presmerovani,claude' : extensions},applications');
    INSERT INTO ka_applications (received_at, job_title, form, name, email, phone, data, status, status_at, last_activity_at, pool_consent_at, pool_until, history)
    VALUES (NOW(), 'Technician B334', 'Job application', 'Ivan B334', 'ivan-b334@example.com', '777334', '[["Full name","Ivan B334"],["A few words about you","I would like to join your team."]]', 'interview', NOW(), NOW(), NOW(), CURDATE() + INTERVAL 1 YEAR,
      '[{"at":"2026-11-10 10:00:00","by":null,"from":"new","to":"interview"}]')`);
    const id = sql("SELECT MAX(id) FROM ka_applications WHERE email = 'ivan-b334@example.com'").trim();
    try {
      for (const scheme of ['light', 'dark']) {
        await page.emulateMedia({ colorScheme: scheme });
        for (const [width, height] of [[1440, 900], [390, 844]]) {
          await page.setViewportSize({ width, height });
          for (const url of ['/admin.php?module=applications', `/admin.php?module=applications&action=detail&id=${id}`]) {
            await visit(url);
            if (!(await page.locator(url.includes('detail') ? '[data-application-card]' : '[data-applications-list]').count())) { throw new Error(`${url}: the application is not shown`); }
            if (await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1)) { throw new Error(`${url} scrolls sideways at ${width} px (${scheme})`); }
            if (SHOTS) { await page.screenshot({ path: `${SHOTS}/applications-334-${url.includes('detail') ? 'detail' : 'list'}-${width}-${scheme}.png`, fullPage: true }); }
          }
        }
      }
      const axeContext = await browser.newContext({ storageState: await context.storageState(), bypassCSP: true, viewport: { width: 1440, height: 900 } });
      try {
        const axePage = await axeContext.newPage();
        for (const url of ['/admin.php?module=applications', `/admin.php?module=applications&action=detail&id=${id}`]) {
          await axePage.goto(BASE + url, { waitUntil: 'networkidle' });
          await axePage.addScriptTag({ path: AXE });
          const found = await axePage.evaluate((tags) => window.axe.run({ include: [['main']] }, { runOnly: { type: 'tag', values: tags }, resultTypes: ['violations'] }), WCAG);
          if (found.violations.length) { throw new Error(`axe ${url}: ` + found.violations.map((v) => `${v.id} ${v.nodes[0].target.join(' ')}`).join(', ')); }
        }
      } finally {
        await axeContext.close();
      }
    } finally {
      await page.emulateMedia({ colorScheme: 'light' });
      await page.setViewportSize({ width: 1440, height: 900 });
      sql(`DELETE FROM ka_applications WHERE email = 'ivan-b334@example.com'; REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('extensions', '${extensions}'); DELETE FROM ka_nastaveni WHERE promenna = 'extensions' AND hodnota = ''`);
    }
  });

  // 3.34 (B): the detail with a template in the reply, the settings and the move by form – 1440 and 390 px, light/dark, axe;
  // the reason of a rejection shows only with Rejected chosen (CSS :has, no script)
  await step('3.34 B applications: reply template, settings, move by form – 1440 and 390 px, light/dark, axe; the reason follows Rejected', async () => {
    const extensions = sql("SELECT hodnota FROM ka_nastaveni WHERE promenna = 'extensions'").trim();
    sql(`DROP TABLE IF EXISTS ka_b335_mail; CREATE TABLE ka_b335_mail AS SELECT * FROM ka_nastaveni WHERE promenna IN ('mail_mode', 'smtp_host');
    REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('extensions', '${extensions === '' ? 'novinky,poptavky,customers,pipeline,statistika,presmerovani,claude' : extensions},applications'), ('mail_mode', 'smtp'), ('smtp_host', '127.0.0.1');
    INSERT INTO ka_applications (received_at, job_title, form, name, email, phone, data, status, status_at, last_activity_at)
    VALUES (NOW(), 'Painter B335', 'Job application', 'Rita B335', 'rita-b335@example.com', '', '[["Full name","Rita B335"]]', 'new', NOW(), NOW())`);
    const id = sql("SELECT MAX(id) FROM ka_applications WHERE email = 'rita-b335@example.com'").trim();
    // 3.34 second review N3: the review of the way back (how many move, how many stay and why) – at both widths and through axe
    const urls = [`/admin.php?module=applications&action=detail&id=${id}&template=interview`, '/admin.php?module=applications&action=settings', '/admin.php?module=applications&action=move',
      `/admin.php?module=applications&action=move&back=${encodeURIComponent('|Job application')}`];
    try {
      await page.setViewportSize({ width: 1440, height: 900 });
      await visit(urls[0]);
      if (!(await page.locator('[data-reply-form]').count())) { throw new Error('the reply form is not offered'); }
      if ((await page.locator('#uchazec-predmet').inputValue()) !== 'Invitation to an interview – Painter B335') { throw new Error('the template did not fill the subject'); }
      if (await page.locator('#uchazec-duvod').isVisible()) { throw new Error('the reason shows before Rejected is chosen'); }
      await page.locator('#uchazec-stav').selectOption('rejected');
      if (!(await page.locator('#uchazec-duvod').isVisible())) { throw new Error('the reason does not show with Rejected'); }
      await visit(urls[3]);
      if (!(await page.locator('[data-move-back-review][data-move-back-review^="1|1|"]').count())) { throw new Error('the review of the way back does not say what moves'); }
      for (const scheme of ['light', 'dark']) {
        await page.emulateMedia({ colorScheme: scheme });
        for (const [width, height] of [[1440, 900], [390, 844]]) {
          await page.setViewportSize({ width, height });
          for (const url of urls) {
            await visit(url);
            if (await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1)) { throw new Error(`${url} scrolls sideways at ${width} px (${scheme})`); }
            if (SHOTS) { await page.screenshot({ path: `${SHOTS}/applications-335-${url.match(/action=(\w+)/)[1]}${url.includes('back=') ? '-review' : ''}-${width}-${scheme}.png`, fullPage: true }); }
          }
        }
      }
      const axeContext = await browser.newContext({ storageState: await context.storageState(), bypassCSP: true, viewport: { width: 1440, height: 900 } });
      try {
        const axePage = await axeContext.newPage();
        for (const url of urls) {
          await axePage.goto(BASE + url, { waitUntil: 'networkidle' });
          await axePage.addScriptTag({ path: AXE });
          const found = await axePage.evaluate((tags) => window.axe.run({ include: [['main']] }, { runOnly: { type: 'tag', values: tags }, resultTypes: ['violations'] }), WCAG);
          if (found.violations.length) { throw new Error(`axe ${url}: ` + found.violations.map((v) => `${v.id} ${v.nodes[0].target.join(' ')}`).join(', ')); }
        }
      } finally {
        await axeContext.close();
      }
    } finally {
      await page.emulateMedia({ colorScheme: 'light' });
      await page.setViewportSize({ width: 1440, height: 900 });
      sql(`DELETE FROM ka_applications WHERE email = 'rita-b335@example.com'; DELETE FROM ka_nastaveni WHERE promenna IN ('mail_mode', 'smtp_host'); INSERT INTO ka_nastaveni SELECT * FROM ka_b335_mail; DROP TABLE ka_b335_mail;
      REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('extensions', '${extensions}'); DELETE FROM ka_nastaveni WHERE promenna = 'extensions' AND hodnota = ''`);
    }
  });

  // 3.34 (integration): "Move to Applications" on one enquiry – the button fits 390 px, the confirmation names what happens, the
  // application opens afterwards; axe on the enquiry detail
  await step('3.34 Move to Applications: one enquiry moves after the confirmation and its application opens (390 px, axe)', async () => {
    const extensions = sql("SELECT hodnota FROM ka_nastaveni WHERE promenna = 'extensions'").trim();
    sql(`REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('extensions', '${extensions === '' ? 'novinky,poptavky,customers,pipeline,statistika,presmerovani,claude' : extensions},applications')`);
    const idp = sql(`INSERT INTO ka_poptavky (datum, formular, zdroj, prvek, stranka, email, data, stav, kategorie, triaged_by) VALUES (NOW(), 'Contact B340', 'stranka:1', 'x', '/contact', 'eva-b340@example.com', '[["Name","Eva B340"]]', 1, 'job', 'person'); SELECT LAST_INSERT_ID();`).trim();
    try {
      const axeContext = await browser.newContext({ storageState: await context.storageState(), bypassCSP: true, viewport: { width: 1440, height: 900 } });
      try {
        const axePage = await axeContext.newPage();
        await axePage.goto(`${BASE}/admin.php?module=enquiries&action=detail&id=${idp}`, { waitUntil: 'networkidle' });
        await axePage.addScriptTag({ path: AXE });
        const found = await axePage.evaluate((tags) => window.axe.run({ include: [['main']] }, { runOnly: { type: 'tag', values: tags }, resultTypes: ['violations'] }), WCAG);
        if (found.violations.length) { throw new Error('axe enquiry detail: ' + found.violations.map((v) => `${v.id} ${v.nodes[0].target.join(' ')}`).join(', ')); }
      } finally {
        await axeContext.close();
      }
      await page.setViewportSize({ width: 390, height: 844 });
      await visit(`/admin.php?module=enquiries&action=detail&id=${idp}`);
      if (await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1)) { throw new Error('the enquiry detail scrolls sideways at 390 px'); }
      await page.locator('[data-record-more] > summary').click(); // 3.37: in More of the bar
      await page.locator('form[data-to-application] button').click();
      const dialog = page.locator('dialog.potvrzeni[open]');
      await dialog.waitFor();
      if (!/Applications/.test(await dialog.textContent() || '')) { throw new Error('the confirmation does not say where the enquiry goes'); }
      await Promise.all([page.waitForNavigation(), dialog.locator('[data-ano]').click()]);
      if (!/module=applications&action=detail/.test(page.url()) || !(await page.locator('[data-application-card]').count())) { throw new Error(`the application did not open: ${page.url()}`); }
      if (sql(`SELECT COUNT(*) FROM ka_poptavky WHERE idp = ${idp}`).trim() !== '0') { throw new Error('the enquiry is still in Enquiries'); }
    } finally {
      await page.setViewportSize({ width: 1440, height: 900 });
      sql(`DELETE FROM ka_comments WHERE target IN (SELECT CONCAT('application:', id) FROM ka_applications WHERE email = 'eva-b340@example.com'); DELETE FROM ka_applications WHERE email = 'eva-b340@example.com'; DELETE FROM ka_poptavky WHERE idp = ${idp};
      REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('extensions', '${extensions}'); DELETE FROM ka_nastaveni WHERE promenna = 'extensions' AND hodnota = ''`);
    }
  });

  // 3.40 (C): applications II – the board of one position (a card moves with its own form, the reason follows Rejected, with a
  // mouse the form folds behind ⋯), the talent pool, the statistics and the page of the renewal link – 1440 and 390 px,
  // light/dark, axe; no sideways scroll on a phone
  await step('3.40 applications II: the board, the talent pool, the statistics and the renewal page – 1440 and 390 px, light/dark, axe', async () => {
    const extensions = sql("SELECT hodnota FROM ka_nastaveni WHERE promenna = 'extensions'").trim();
    sql(`REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('extensions', '${extensions === '' ? 'novinky,poptavky,customers,pipeline,statistika,presmerovani,claude' : extensions},applications');
    INSERT INTO ka_applications (received_at, job_item_id, job_title, form, name, email, email_norm, data, status, status_at, last_activity_at, decided_at, reject_reason, pool_consent_at, pool_until, pool_renewal_sent_at, pool_token, pool_token_until) VALUES
      (NOW() - INTERVAL 10 DAY, 9341, 'Welder B340', 'Job application', 'Nina B340', 'nina-b340@example.com', 'nina-b340@example.com', '[]', 'new', NOW(), NOW(), NULL, NULL, NULL, NULL, NULL, NULL, NULL),
      (NOW(), 9341, 'Welder B340', 'Job application', 'Oskar Longname-Of-A-Welder B340', 'oskar-b340@example.com', 'oskar-b340@example.com', '[]', 'interview', NOW(), NOW(), NULL, NULL, NULL, NULL, NULL, NULL, NULL),
      (NOW(), 9341, 'Welder B340', 'Job application', 'Pia B340', 'pia-b340@example.com', 'pia-b340@example.com', '[]', 'rejected', NOW(), NOW(), NOW(), 'position_filled', NOW() - INTERVAL 11 MONTH, CURDATE() + INTERVAL 15 DAY, NOW(), NULL, NOW() + INTERVAL 15 DAY);
    INSERT IGNORE INTO ka_application_counts (month, job_item_id, job_title, received, interview, offer, hired, rejected) VALUES (DATE_FORMAT(NOW(), '%Y-%m'), 9341, 'Welder B340', 3, 1, 0, 0, 1)`);
    const nina = sql("SELECT id FROM ka_applications WHERE email = 'nina-b340@example.com'").trim();
    const token = execFileSync('php', ['-r', 'echo hash("sha256", "b340-renewal-token");'], { encoding: 'utf8' }).trim();
    // the link carries a 64-hex token, the row only its sha256 (Core\TalentPool)
    sql(`UPDATE ka_applications SET pool_token = SHA2('${token}', 256) WHERE email = 'pia-b340@example.com'`);
    const urls = ['/admin.php?module=applications&action=board&job=9341', '/admin.php?module=applications&action=pool', '/admin.php?module=applications&action=stats', `/application-consent/${token}`];
    try {
      await page.setViewportSize({ width: 1440, height: 900 });
      await visit(urls[0]);
      if ((await page.locator('[data-board-status]').count()) !== 6) { throw new Error('the board has not six columns'); }
      const card = page.locator(`[data-board-card="${nina}"]`);
      if (!(await page.locator(`[data-board-status="new"] [data-board-card="${nina}"]`).count())) { throw new Error('Nina is not in New'); }
      if (await card.locator('select[name="status"]').isVisible()) { throw new Error('with a mouse the move form does not fold behind ⋯'); }
      await card.locator('details.obchod-vice > summary').click();
      if (await card.locator('select[name="reject_reason"]').isVisible()) { throw new Error('the reason shows before Rejected is chosen'); }
      await card.locator('select[name="status"]').selectOption('rejected');
      if (!(await card.locator('select[name="reject_reason"]').isVisible())) { throw new Error('the reason does not show with Rejected'); }
      await card.locator('select[name="status"]').selectOption('interview');
      await card.locator('form[data-board-move] button').focus();
      await Promise.all([page.waitForNavigation(), page.keyboard.press('Enter')]); // the keyboard moves it
      if (!(await page.locator(`[data-board-status="interview"] [data-board-card="${nina}"]`).count())) { throw new Error(`Nina did not move to Interview: ${page.url()}`); }
      for (const scheme of ['light', 'dark']) {
        await page.emulateMedia({ colorScheme: scheme });
        for (const [width, height] of [[1440, 900], [390, 844]]) {
          await page.setViewportSize({ width, height });
          for (const url of urls) {
            await visit(url);
            if (await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1)) { throw new Error(`${url} scrolls sideways at ${width} px (${scheme})`); }
            if (SHOTS) { await page.screenshot({ path: `${SHOTS}/applications-340-${url.includes('consent') ? 'consent' : url.match(/action=(\w+)/)[1]}-${width}-${scheme}.png`, fullPage: true }); }
          }
        }
      }
      await visit(urls[3]);
      if ((await page.locator('button[name="choice"]').count()) !== 2) { throw new Error('the renewal page does not offer keep and delete'); }
      const axeContext = await browser.newContext({ storageState: await context.storageState(), bypassCSP: true, viewport: { width: 1440, height: 900 } });
      try {
        const axePage = await axeContext.newPage();
        for (const url of urls) {
          await axePage.goto(BASE + url, { waitUntil: 'networkidle' });
          await axePage.addScriptTag({ path: AXE });
          const found = await axePage.evaluate((tags) => window.axe.run({ include: [['main']] }, { runOnly: { type: 'tag', values: tags }, resultTypes: ['violations'] }), WCAG);
          if (found.violations.length) { throw new Error(`axe ${url}: ` + found.violations.map((v) => `${v.id} ${v.nodes[0].target.join(' ')}`).join(', ')); }
        }
      } finally {
        await axeContext.close();
      }
    } finally {
      await page.emulateMedia({ colorScheme: 'light' });
      await page.setViewportSize({ width: 1440, height: 900 });
      sql(`DELETE FROM ka_applications WHERE email IN ('nina-b340@example.com', 'oskar-b340@example.com', 'pia-b340@example.com'); DELETE FROM ka_application_counts WHERE job_item_id = 9341;
      REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('extensions', '${extensions}'); DELETE FROM ka_nastaveni WHERE promenna = 'extensions' AND hodnota = ''`);
    }
  });

  // 3.47 B (U64, U65, carried 2): the board of a position moves a card by drag and by keyboard (posted as JSON, the counts follow,
  // announced; Rejected asks the reason on the card), "All positions" names each card's position; the application detail on the
  // record template at 390 px in light and dark without sideways scroll and axe clean; a public form refuses a file over its
  // limit in the browser (a custom validity) and lets a small one through
  await step('3.47 B: Applications board – drag and keyboard; the detail at 390 px light/dark with axe; a form file over its limit is stopped in the browser', async () => {
    if (!MYSQL.length) { throw new Error('MYSQL_JSON is not set'); }
    const extensions = sql("SELECT hodnota FROM ka_nastaveni WHERE promenna = 'extensions'").trim();
    sql(`REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('extensions', '${extensions === '' ? 'novinky,poptavky,customers,pipeline,statistika,presmerovani,claude' : extensions},applications');
    INSERT INTO ka_applications (received_at, job_item_id, job_title, form, name, email, email_norm, data, status, status_at, last_activity_at) VALUES
      (NOW() - INTERVAL 20 DAY, 9471, 'Welder B347', 'Job application', 'Nora B347', 'nora-b347@example.com', 'nora-b347@example.com', '[["Full name","Nora B347"]]', 'new', NOW(), NOW()),
      (NOW(), 9471, 'Welder B347', 'Job application', 'Petr Longname-Of-A-Welder B347', 'petr-b347@example.com', 'petr-b347@example.com', '[]', 'new', NOW(), NOW()),
      (NOW(), 9471, 'Welder B347', 'Job application', 'Rosa B347', 'rosa-b347@example.com', 'rosa-b347@example.com', '[]', 'new', NOW(), NOW()),
      (NOW(), 9472, 'Painter B347', 'Job application', 'Saul B347', 'saul-b347@example.com', 'saul-b347@example.com', '[]', 'reviewed', NOW(), NOW());
    INSERT INTO ka_applications (received_at, job_item_id, job_title, form, name, email, email_norm, data, status, status_at, last_activity_at, decided_at, reject_reason) VALUES
      (NOW(), 9471, 'Welder B347', 'Job application', 'Vera B347', 'vera-b347@example.com', 'vera-b347@example.com', '[]', 'rejected', NOW(), NOW(), NOW(), 'position_filled')`);
    const id = (email) => Number(sql(`SELECT id FROM ka_applications WHERE email = '${email}'`).trim());
    // 3.48 review: Vera, a recently rejected card with its reason chip, comes before the reason popover in the page
    const nora = id('nora-b347@example.com'), petr = id('petr-b347@example.com'), rosa = id('rosa-b347@example.com');
    const pageId = Number(execFileSync('php', ['-r', `require "system/bootstrap.php"; $app = Kaleta\\Core\\App::boot(); $db = $app->db();
    [$build] = Kaleta\\Builder\\Build::sanitize(["v" => 1, "deti" => [["typ" => "sekce", "deti" => [["typ" => "formular", "obsah" => ["nazev" => "Files B347", "bez_captcha" => true, "max_priloha" => 1,
      "pole" => [["popisek" => "CV", "typ" => "soubor", "povinne" => false], ["popisek" => "Email", "typ" => "email", "povinne" => true]]]]]]]]);
    $ids = $db->insert("stranky", ["seo_link" => "files-b347", "titulek" => "Files B347", "text" => "", "v_menu" => 0, "stavba" => Kaleta\\Builder\\Build::toJson($build)]);
    Kaleta\\Core\\PageCache::clear(); echo $ids;`], { cwd: process.env.WEB_DIR, encoding: 'utf8' }).trim()) || 0;
    try {
      await page.setViewportSize({ width: 1440, height: 900 });
      await visit('/admin.php?module=applications&action=board&job=9471');
      if ((await page.locator('[data-applications-board] .obchod-uchyt').count()) !== 4) { throw new Error('the cards have no keyboard handle'); }
      if ((await page.locator('section[data-board-status="rejected"] [data-board-reason]').count()) !== 1) { throw new Error('the rejected card shows no reason chip'); }
      // the drag: Nora to Interview – posted as JSON, the card and the counts follow, announced
      const count = (status) => page.locator(`section[data-board-status="${status}"] [data-board-count]`).innerText().then(Number);
      // 3.47 review F2: a column the server cut keeps the cards it left out (data-board-more) – here New as if 40 more were in the list
      await page.evaluate(() => { const c = document.querySelector('section[data-board-status="new"] [data-board-count]'); c.setAttribute('data-board-more', '40'); c.textContent = String(Number(c.textContent) + 40); });
      // 3.47 review F4: Nora (20 days without a reply) carries the "waiting" chip and the overdue look of New
      if ((await page.locator(`#application-${nora}.obchod-po-terminu [data-board-waiting]`).count()) !== 1) { throw new Error('the waiting card has no waiting chip'); }
      const before = [await count('new'), await count('interview')];
      await page.locator(`#application-${nora}`).dragTo(page.locator('section[data-board-status="interview"]'));
      await page.locator(`section[data-board-status="interview"] #application-${nora}`).waitFor({ timeout: 5000 });
      await page.waitForFunction(() => /Interview/.test(document.querySelector('[data-board-announce]').textContent));
      if (sql(`SELECT status FROM ka_applications WHERE id = ${nora}`).trim() !== 'interview') { throw new Error('the drag was not saved'); }
      if ((await count('new')) !== before[0] - 1 || (await count('interview')) !== before[1] + 1) { throw new Error(`the counts did not follow the drag (the hidden 40 of New kept): ${before} → ${await count('new')},${await count('interview')}`); }
      if ((await page.locator(`#application-${nora}.obchod-po-terminu, #application-${nora} [data-board-waiting]`).count()) !== 0) { throw new Error('the card keeps the waiting chip of New after the move'); }
      // the keyboard: Petr's handle, Space, ArrowRight, Space → Reviewed
      await page.locator(`#application-${petr} .obchod-uchyt`).focus();
      await page.keyboard.press('Space');
      await page.waitForFunction(() => /Picked up/.test(document.querySelector('[data-board-announce]').textContent));
      await page.keyboard.press('ArrowRight');
      await page.keyboard.press('Space');
      await page.locator(`section[data-board-status="reviewed"] #application-${petr}`).waitFor({ timeout: 5000 });
      if (sql(`SELECT status FROM ka_applications WHERE id = ${petr}`).trim() !== 'reviewed') { throw new Error('the keyboard move was not saved'); }
      if (await page.evaluate(() => !document.activeElement.classList.contains('obchod-uchyt'))) { throw new Error('the focus did not stay on the moved card'); }
      // Rejected (the last column, scrolled off at 1440 – reached by the keyboard): the card's form opens with the reason, focused; nothing is saved yet
      await page.locator(`#application-${rosa} .obchod-uchyt`).focus();
      await page.keyboard.press('Space');
      for (let i = 0; i < 5; i++) { await page.keyboard.press('ArrowRight'); }
      await page.keyboard.press('Space');
      // 3.48 B (U30): the reason popover of the board, focused; Cancel leaves the card where it was
      await page.locator('[data-board-reason-pop]').waitFor({ state: 'visible', timeout: 3000 });
      if ((await page.evaluate(() => document.activeElement.id)) !== 'uchazeci-duvod-vyber') { throw new Error('the reason is not focused after a move to Rejected'); }
      if (sql(`SELECT status FROM ka_applications WHERE id = ${rosa}`).trim() !== 'new') { throw new Error('a move to Rejected saved without a reason'); }
      await page.locator('[data-board-reason-pop] button[popovertargetaction="hide"]').click();
      await page.waitForFunction(() => /stays/.test(document.querySelector('[data-board-message]').textContent));
      if (await page.locator('[data-board-reason-pop]').isVisible() || sql(`SELECT status FROM ka_applications WHERE id = ${rosa}`).trim() !== 'new') { throw new Error('Cancel did not leave the application'); }
      // All positions: each card names its position
      await visit('/admin.php?module=applications&action=board&job=all');
      if ((await page.locator('[data-board-position]', { hasText: 'Painter B347' }).count()) !== 1) { throw new Error('All positions does not name the position on the card'); }
      // the detail on the record template: 390 px, light and dark, no sideways scroll, axe clean
      const state = await context.storageState();
      for (const scheme of ['light', 'dark']) {
        const other = await browser.newContext({ viewport: { width: 390, height: 844 }, locale: 'en-GB', colorScheme: scheme, storageState: state, isMobile: true, hasTouch: true });
        const p2 = await other.newPage(); watch(p2);
        try {
          for (const url of [`/admin.php?module=applications&action=detail&id=${nora}`, '/admin.php?module=applications&status=', '/admin.php?module=applications&action=board&job=all']) {
            await p2.goto(`${BASE}${url}`, { waitUntil: 'networkidle' });
            const wide = await p2.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
            if (wide > 0) { throw new Error(`${wide} px of sideways scroll (${url}, ${scheme})`); }
            if (url.includes('detail') && !(await p2.locator('[data-record-bar]').count())) { throw new Error('the detail has no record bar'); }
            if (url.includes('status=') && await p2.locator('form.uchazeci-hromadne').isVisible()) { throw new Error('the bulk bar shows before a row is ticked'); }
            if (SHOTS) { await p2.screenshot({ path: `${SHOTS}/347b-${url.includes('detail') ? 'detail' : url.includes('board') ? 'board' : 'list'}-390-${scheme}.png`, fullPage: true }); }
          }
          await p2.goto(`${BASE}/admin.php?module=applications&status=`, { waitUntil: 'networkidle' });
          await p2.locator('input[name="ids[]"]').first().check();
          if (!(await p2.locator('form.uchazeci-hromadne').isVisible())) { throw new Error('the bulk bar does not show once a row is ticked'); }
        } finally {
          await other.close();
        }
      }
      const axeContext = await browser.newContext({ storageState: state, bypassCSP: true, viewport: { width: 390, height: 844 } });
      try {
        const axePage = await axeContext.newPage();
        for (const scheme of ['light', 'dark']) {
          await axePage.emulateMedia({ colorScheme: scheme });
          await axePage.goto(`${BASE}/admin.php?module=applications&action=detail&id=${nora}`, { waitUntil: 'networkidle' });
          await axePage.addScriptTag({ path: AXE });
          const found = await axePage.evaluate(([tags]) => window.axe.run({ include: [['main']] }, { runOnly: { type: 'tag', values: tags }, resultTypes: ['violations'] }), [WCAG]);
          if (found.violations.length) { throw new Error(`axe application detail (${scheme}): ` + found.violations.map((v) => `${v.id} ${v.nodes[0].target.join(' ')}`).join(', ')); }
        }
      } finally {
        await axeContext.close();
      }
      // carried 2 (f4dac2b7): a file over the form's limit gets the browser's custom validity with the form's message; a small one clears it
      const visitor = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'en-GB' });
      const p3 = await visitor.newPage(); watch(p3);
      try {
        await p3.goto(`${BASE}/files-b347`, { waitUntil: 'networkidle' });
        const input = p3.locator('form[data-formular] input[type=file]');
        if ((await input.getAttribute('data-max-bytes')) !== '1048576') { throw new Error(`the file field carries no limit of 1 MB: ${await input.getAttribute('data-max-bytes')}`); }
        await input.setInputFiles({ name: 'big-cv.pdf', mimeType: 'application/pdf', buffer: Buffer.alloc(2 * 1048576, 65) });
        const big = await input.evaluate((el) => [el.validity.customError, el.validationMessage, el.form.getAttribute('data-file-too-large')]);
        if (!big[0] || big[1] !== big[2] || !big[1]) { throw new Error(`a 2 MB file is not stopped by the browser: ${JSON.stringify(big)}`); }
        await input.setInputFiles({ name: 'cv.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4\n%%EOF\n') });
        if (await input.evaluate((el) => el.validity.customError)) { throw new Error('a small file keeps the custom validity'); }
        // 3.47 review F6: with the server's body limit equal to the file limit (1M/1M), one file just under it is not refused "together"
        await input.evaluate((el) => el.form.setAttribute('data-max-post', el.getAttribute('data-max-bytes')));
        await input.setInputFiles({ name: 'near-cv.pdf', mimeType: 'application/pdf', buffer: Buffer.alloc(1048576 - 20000, 65) });
        const near = await input.evaluate((el) => [el.validity.customError, el.validationMessage]);
        if (near[0]) { throw new Error(`one file under the limit is refused when the body limit equals the file limit: ${JSON.stringify(near)}`); }
      } finally {
        await visitor.close();
      }
    } finally {
      await page.emulateMedia({ colorScheme: 'light' });
      await page.setViewportSize({ width: 1440, height: 900 });
      sql(`DELETE FROM ka_applications WHERE email LIKE '%-b347@example.com'; DELETE FROM ka_application_counts WHERE job_item_id IN (9471, 9472); DELETE FROM ka_stranky WHERE ids = ${pageId};
      REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('extensions', '${extensions}'); DELETE FROM ka_nastaveni WHERE promenna = 'extensions' AND hodnota = ''`);
    }
  });
};
