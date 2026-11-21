// Kaleta browser steps 0190 (tools/test-browser.mjs runs the files of this folder in number order on one site – the order is
// the contract; tools/tests/README.md). Moved verbatim from the single file in 3.49 P1.
export default async (t) => {
  const { BASE, SHOTS, sql, browser, page, watch, step, visit, liveCanvas, builderIdle, SMTP_DIR, SMTP_PORT, WEB_DIR, latestMail, decodeMail, createHash, execFileSync } = t;

  // 3.23.1 (CPO 1 Nov, item 12): the 3.20–3.23 flows end to end in the browser, mail caught by a local SMTP sink (tools/fake-smtp.php,
  // started by tools/test-browser.sh – nothing leaves the machine): an enquiry reply, an Appearance-area member, every Settings tab

  await step('3.23.1 item 12: a reply sent with the enquiry form reaches the SMTP sink – From the site, Reply-To the user, the signature', async () => {
    if (!SMTP_DIR || !SMTP_PORT || !WEB_DIR) { throw new Error('SMTP_DIR, SMTP_PORT and WEB_DIR are not set (tools/test-browser.sh)'); }
    sql(`REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('mail_mode', 'smtp'), ('smtp_host', '127.0.0.1'), ('smtp_port', '${Number(SMTP_PORT)}'), ('smtp_encryption', 'zadne'), ('smtp_user', ''), ('mail_from', 'web@browser.example')`);
    // the admin's own Team record (a people collection, chosen for the account) gives the signature; an earlier step may have chosen another
    const earlierRecord = sql("SELECT IFNULL(team_item_id, 'NULL') FROM ka_uzivatele WHERE user = 'admin'").trim();
    execFileSync('php', ['-r', `require "system/bootstrap.php"; $app = Kaleta\\Core\\App::boot(); $db = $app->db();
    $idk = (int) Kaleta\\Builder\\Presets::create($app, "people", "Team 3231"); $f = Kaleta\\Builder\\EmailSignature::fields((array) Kaleta\\Builder\\Collections::byId($db, $idk));
    $item = $db->insert("kolekce_polozky", ["idk" => $idk, "nazev" => "Alex Signature", "seo_link" => "alex-signature", "data" => (string) json_encode(array_filter([$f["email"] => "alex@browser.example", $f["role"] => "Head of sales"], fn ($v, $k) => $k !== "", ARRAY_FILTER_USE_BOTH)), "zobrazit" => 1, "jazyk" => "", "datum" => date("Y-m-d H:i:s"), "poradi" => 0]);
    $db->update("uzivatele", ["email" => "alex@browser.example", "team_item_id" => $item], ["user" => "admin"]);`], { cwd: WEB_DIR, encoding: 'utf8' });
    sql("INSERT INTO ka_poptavky (datum, formular, zdroj, stranka, email, data, stav, navrh_odpovedi) VALUES (NOW(), 'Quote 3231', '', '/contact', 'grace@customer.example', '[[\"Message\",\"How much is a kitchen?\"]]', 0, 'Hello Grace, we will send a quote.')");
    const id = Number(sql("SELECT idp FROM ka_poptavky WHERE email = 'grace@customer.example' ORDER BY idp DESC LIMIT 1").trim());
    try {
      await page.setViewportSize({ width: 1440, height: 900 });
      await visit(`/admin.php?module=enquiries&action=detail&id=${id}`);
      const form = page.locator('form[data-reply-form]');
      if (await form.count() !== 1) { throw new Error('no reply form with SMTP set up'); }
      // N-C5: with a signature the hint says it is added; no "no signature" note
      if (await form.locator('[data-reply-no-signature]').count() !== 0 || !/e-mail signature from My account is added/.test(await form.textContent())) { throw new Error('the hint does not promise the signature the user has'); }
      if ((await form.locator('#odpoved-text').inputValue()) !== 'Hello Grace, we will send a quote.') { throw new Error('the drafted reply is not prefilled'); }
      await form.locator('#odpoved-text').fill('Hello Grace,\nthe quote follows by Friday.');
      await form.locator('button[type="submit"]', { hasText: 'Send reply' }).click();
      const confirm = page.locator('dialog.potvrzeni[open]');
      await confirm.waitFor();
      if (!(await confirm.textContent()).includes('grace@customer.example')) { throw new Error('the confirmation does not name the recipient'); }
      await Promise.all([page.waitForNavigation(), confirm.locator('[data-ano]').click()]);
      const raw = latestMail('grace@customer.example');
      if (!raw) { throw new Error('nothing reached the SMTP sink for grace@customer.example'); }
      const { head, text } = decodeMail(raw);
      if (!/^Reply-To: alex@browser\.example\r?$/m.test(head)) { throw new Error(`Reply-To is not the user: ${(/^Reply-To:.*$/m.exec(head) || ['none'])[0]}`); }
      if (!/^From: .*web@browser\.example/m.test(head)) { throw new Error('From is not the site sender'); }
      if ((raw.match(/^X-Rcpt-To:/gm) || []).length !== 1) { throw new Error('more than one recipient'); }
      const missing = [['the text', text.includes('the quote follows by Friday.')], ['the -- line', /\n-- \r?\n/.test(text)], ['the name', text.includes('Alex Signature')], ['the role', text.includes('Head of sales')]].filter(([, ok]) => !ok).map(([what]) => what);
      if (missing.length) { throw new Error(`missing in the sent mail: ${missing.join(', ')}`); }
      const logged = sql(`SELECT CONCAT_WS('|', stav, JSON_UNQUOTE(JSON_EXTRACT(replies, '$[0].status')), JSON_UNQUOTE(JSON_EXTRACT(replies, '$[0].reply_to'))) FROM ka_poptavky WHERE idp = ${id}`).trim();
      if (logged !== '2|sent|alex@browser.example') { throw new Error(`the enquiry logged: ${logged}`); }
      if (await page.locator('[data-reply-status="sent"]').count() !== 1) { throw new Error('the detail does not show the sent reply'); }
      // N-C5, 3.37: without a Team record the user has the site's fallback signature – the hint promises it, never "without one"
      sql("UPDATE ka_uzivatele SET email = 'nobody-3231@browser.example', team_item_id = NULL WHERE user = 'admin'");
      await visit(`/admin.php?module=enquiries&action=detail&id=${id}`);
      if (await form.locator('[data-reply-no-signature]').count() !== 0 || !/e-mail signature from My account is added/.test(await form.textContent())) { throw new Error('without a Team record the hint does not promise the fallback signature'); }
    } finally {
      sql(`UPDATE ka_uzivatele SET email = '', team_item_id = ${earlierRecord === 'NULL' ? 'NULL' : Number(earlierRecord)} WHERE user = 'admin'; DELETE FROM ka_poptavky WHERE idp = ${id}; UPDATE ka_nastaveni SET hodnota = 'mail' WHERE promenna = 'mail_mode'`);
    }
  });

  await step('3.23.1 item 12: a custom role with the Appearance area – its member signs in and gets Menu (200), not Site appearance (403)', async () => {
    // the role made with the role form (a custom role, not the Client preset, which 3.23.1 changes elsewhere)
    await visit('/admin.php?module=roles&action=new');
    await page.fill('#nazev', 'Appearance 3231');
    await page.locator('input[name="uroven"][value="1"]').check();
    await page.locator('input[name="moduly[]"][value="pages"]').check();
    await page.locator('input[name="moduly[]"][value="appearance_area"]').check();
    await Promise.all([page.waitForNavigation(), page.locator('form p.tlacitka input.tl').click()]);
    const idr = sql("SELECT idr FROM ka_role WHERE nazev = 'Appearance 3231'").trim();
    if (!idr) { throw new Error('the role was not saved'); }
    const secret = `Look-${createHash('sha256').update(String(Date.now())).digest('hex').slice(0, 12)}`;
    await visit('/admin.php?module=users&action=new');
    await page.fill('#user', 'look3231');
    await page.fill('#jmeno', 'Lou Look');
    await page.fill('#password', secret);
    await page.locator(`input[name="admin"][value="r${idr}"]`).check();
    await Promise.all([page.waitForNavigation(), page.locator('form p.tlacitka input.tl').first().click()]);
    if (sql("SELECT COUNT(*) FROM ka_uzivatele WHERE user = 'look3231'").trim() !== '1') { throw new Error('the user was not saved'); }
    const member = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'en-GB' });
    const p2 = await member.newPage(); watch(p2);
    try {
      sql("DELETE FROM ka_kontrola_ip WHERE typ = 'login'");
      await p2.goto(`${BASE}/admin.php`, { waitUntil: 'networkidle' });
      await p2.fill('input[name="user"]', 'look3231');
      await p2.fill('input[name="password"]', secret);
      await Promise.all([p2.waitForNavigation(), p2.press('input[name="password"]', 'Enter')]);
      const codes = {};
      for (const [name, url] of [['menu', '/admin.php?module=menu'], ['parts', '/admin.php?module=parts'], ['appearance', '/admin.php?module=appearance'], ['settings', '/admin.php?module=settings&tab=general']]) {
        codes[name] = (await p2.goto(`${BASE}${url}`, { waitUntil: 'networkidle' })).status();
        if (name === 'menu' && !(await p2.locator('h1').first().textContent()).includes('Menu')) { throw new Error('the Menu screen does not open for the member'); }
      }
      if (JSON.stringify(codes) !== JSON.stringify({ menu: 200, parts: 200, appearance: 403, settings: 403 })) { throw new Error(`the member got ${JSON.stringify(codes)}`); }
    } finally {
      await member.close();
      sql(`DELETE p FROM ka_uzivatele_prava p JOIN ka_uzivatele u ON u.idu = p.fk_id_user WHERE u.user = 'look3231'; DELETE FROM ka_uzivatele WHERE user = 'look3231'; DELETE FROM ka_role WHERE idr = ${Number(idr) || 0}`);
    }
  });

  await step('3.23.1 item 12: each Settings tab saved with its Save button keeps the values of every other tab', async () => {
    await page.setViewportSize({ width: 1440, height: 900 });
    sql("REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('agency_name', 'Studio 3231')"); // the console tab is in the row too
    await visit('/admin.php?module=settings&tab=general');
    const tabs = await page.locator('nav.zalozky a').evaluateAll((links) => links.map((a) => new URL(a.href).searchParams.get('tab')));
    if (tabs.length !== 14) { throw new Error(`the row has ${tabs.length} tabs: ${tabs.join(', ')}`); } // 3.48 B: Settings of sections (no Save button – skipped below)
    // mysql -N escapes line breaks and tabs inside a value, so one line is one setting
    // 3.24 (T11): the admin runs the light background jobs after its answer, so their bookkeeping (notification_check and the
    // other *_check/_time/_at stamps – Core\AgentJournal::BOOKKEEPING) moves on its own while the tabs are saved; it is no setting
    const bookkeeping = /^(db_version|data_migrations|notification_check)$|_(check|time|checked|seen|ts|at)$/;
    const snapshot = () => Object.fromEntries(sql('SELECT promenna, hodnota FROM ka_nastaveni').split('\n').filter(Boolean).map((l) => [l.slice(0, l.indexOf('\t')), l.slice(l.indexOf('\t') + 1)]).filter(([k]) => !bookkeeping.test(k)));
    const saved = [];
    const saveAll = async () => {
      for (const tab of tabs) {
        await visit(`/admin.php?module=settings&tab=${tab}`);
        const save = page.locator('form.formular > p.tlacitka input.tl[type="submit"]'); // the console tab has only its own buttons
        if (await save.count() === 0) { continue; }
        saved.push(tab);
        await Promise.all([page.waitForNavigation(), save.last().click()]);
        if (new URL(page.url()).searchParams.get('tab') !== tab) { throw new Error(`saving ${tab} went to ${page.url()}`); }
      }
    };
    // the first round may only add the default of a key never saved before; the second round must change nothing at all
    const before = snapshot(); await saveAll(); const middle = snapshot();
    const changed = Object.keys(before).filter((k) => middle[k] !== before[k]);
    await saveAll(); const after = snapshot();
    const drift = [...new Set([...Object.keys(middle), ...Object.keys(after)])].filter((k) => middle[k] !== after[k]);
    sql("UPDATE ka_nastaveni SET hodnota = '' WHERE promenna = 'agency_name'");
    if (changed.length || drift.length) { throw new Error(`changed by saving other tabs: ${changed.join(', ')} | second round: ${drift.join(', ')}`); }
    if (saved.length < 24) { throw new Error(`only ${saved.length / 2} tabs had a Save button: ${saved.join(', ')}`); }
  });

  await step('3.23.1 (N-C4, N-C12): at 390 px System status clips nothing and Settings offers its tabs as a select', async () => {
    try {
      await page.setViewportSize({ width: 390, height: 844 });
      await visit('/admin.php?module=status');
      const clipped = await page.evaluate(() => [...document.querySelectorAll('main .tab-obal, main fieldset')].filter((c) => c.scrollWidth > c.clientWidth + 1).map((c) => `${c.className || c.tagName} ${c.scrollWidth}>${c.clientWidth}`));
      if (clipped.length) { throw new Error(`clipped on System status: ${clipped.join(', ')}`); }
      if (await page.evaluate(() => document.documentElement.scrollWidth > innerWidth)) { throw new Error('System status scrolls sideways'); }
      if (SHOTS) { await page.screenshot({ path: `${SHOTS}/status-phone.png`, fullPage: true }); }
      await visit('/admin.php?module=settings&tab=general');
      if (await page.locator('nav.zalozky').isVisible()) { throw new Error('the row of tabs still shows on a phone'); }
      const select = page.locator('select#zalozka-vyber');
      if (!(await select.isVisible()) || (await select.locator('option').count()) < 12 || (await select.inputValue()) !== 'general') { throw new Error('no select of the Settings tabs on a phone'); }
      await Promise.all([page.waitForNavigation(), select.selectOption('security')]);
      if (!page.url().includes('module=settings&tab=security') || !(await page.locator('[name="require_2fa"]').count())) { throw new Error(`choosing Security opened ${page.url()}`); }
      if (SHOTS) { await page.screenshot({ path: `${SHOTS}/settings-select-phone.png` }); }
      await page.setViewportSize({ width: 1440, height: 900 });
      await visit('/admin.php?module=settings&tab=general');
      if (!(await page.locator('nav.zalozky').isVisible()) || (await page.locator('select#zalozka-vyber').isVisible())) { throw new Error('the desktop lost its row of tabs or shows the select'); }
    } finally {
      await page.setViewportSize({ width: 1440, height: 900 });
    }
  });

  // 3.23.1 (N-U15): a move by Alt+arrows is read out by a live region – "Moved after …"
  await step('3.23.1 (N-U15) builder: Alt+ArrowDown is announced in a live region', async () => {
    await visit('/admin.php?module=pages&action=builder&id=1');
    await builderIdle();
    const id = await liveCanvas().locator('body').evaluate((b) => {
      const leaf = Array.from(b.querySelectorAll('[data-ka-id]:not([data-ka-zamek])')).find((x) => !x.querySelector('[data-ka-id]') && x.nextElementSibling && x.nextElementSibling.hasAttribute('data-ka-id') && x.getBoundingClientRect().height > 8);
      return leaf ? leaf.getAttribute('data-ka-id') : '';
    });
    if (!id) { throw new Error('no element with a next sibling on the canvas'); }
    await liveCanvas().locator(`[data-ka-id="${id}"]`).click({ position: { x: 4, y: 4 } });
    await page.keyboard.press('Alt+ArrowDown');
    const region = page.locator('.st-ohlas[aria-live="polite"]');
    await page.waitForFunction(() => /^Moved after .+\.$/.test((document.querySelector('.st-ohlas') || {}).textContent || ''), null, { timeout: 5000 });
    if (!(await region.evaluate((r) => r.getBoundingClientRect().width <= 1))) { throw new Error('the live region is visible'); }
    const first = await region.textContent();
    await builderIdle();
    await liveCanvas().locator(`[data-ka-id="${id}"]`).click({ position: { x: 4, y: 4 } });
    await page.keyboard.press('Alt+ArrowUp'); // back where it was
    await page.waitForFunction((before) => { const t = (document.querySelector('.st-ohlas') || {}).textContent || ''; return t !== before && /^Moved (after|to the start of) .+\.$/.test(t); }, first, { timeout: 5000 });
    await builderIdle();
  });

  // 3.23.1 (N-U16): "Edit here" on the public site leads to the builder without a console error – the site page skips its page
  // transition into the administration (before, Chrome aborted it with "Transition was aborted because of invalid state")
  await step('3.23.1 (N-U16) "Edit here" from the home page and from Contact: no console error', async () => {
    for (const path of ['/', '/contact']) {
      await visit(path);
      await Promise.all([page.waitForURL(/action=builder/), page.locator('a.ka-upravit-zde').first().click()]);
      await page.waitForLoadState('networkidle');
      await builderIdle();
    }
  });
};
