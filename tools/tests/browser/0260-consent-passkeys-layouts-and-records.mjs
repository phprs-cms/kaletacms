// Kaleta browser steps 0260 (tools/test-browser.mjs runs the files of this folder in number order on one site – the order is
// the contract; tools/tests/README.md). Moved verbatim from the single file in 3.49 P1.
export default async (t) => {
  const { BASE, PASSWORD, SHOTS, MYSQL, sql, browser, context, page, watch, step, visit, AXE, WCAG, axeViolations, createHash, execFileSync } = t;

  // 3.35 B1 (owner decision 11 Nov 2026): without a fresh proof (a sign-in or a confirmed password in the last 10 minutes –
  // the session marker verified_at) the consent asks for the current password; "Can also publish" without it is refused on
  // the same page with why and the choice kept, at 1440 and 390 px in light and dark without sideways scroll, axe clean.
  await step('3.35 B1 OAuth consent: full access asks for the current password when the sign-in is not fresh (1440 and 390 px, light/dark, axe)', async () => {
    const sessions = execFileSync('php', ['-r', 'echo session_save_path() ?: sys_get_temp_dir();'], { encoding: 'utf8' }).trim();
    const redirect = 'https://claude.ai/api/mcp/auth_callback';
    const client = await (await fetch(`${BASE}/oauth/register`, { method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ client_name: 'Claude 335', redirect_uris: [redirect], token_endpoint_auth_method: 'none' }) })).json();
    const challenge = createHash('sha256').update('b'.repeat(50)).digest('base64url');
    for (const scheme of ['light', 'dark']) {
      for (const [label, width, height] of [['desktop', 1440, 900], ['phone', 390, 844]]) {
        const ctx = await browser.newContext({ viewport: { width, height }, locale: 'en-GB', colorScheme: scheme, bypassCSP: true, ...(width < 500 ? { isMobile: true, hasTouch: true } : {}) });
        const p = await ctx.newPage(); watch(p);
        try {
          await p.goto(`${BASE}/admin.php`, { waitUntil: 'networkidle' });
          await p.fill('input[name="user"]', 'admin');
          await p.fill('input[name="password"]', PASSWORD);
          await Promise.all([p.waitForNavigation(), p.press('input[name="password"]', 'Enter')]);
          // the proof of this sign-in is pushed back to 1970 in the PHP session file, as if it were hours old
          const id = (await ctx.cookies()).find((c) => c.name === 'kaleta')?.value || '';
          const file = `${sessions}/sess_${id}`;
          execFileSync('php', ['-r', '$f = $argv[1]; file_put_contents($f, preg_replace("/verified_at\\|i:\\d+;/", "verified_at|i:1;", (string) file_get_contents($f)));', file]);
          await p.goto(`${BASE}/oauth/authorize?response_type=code&client_id=${client.client_id}&redirect_uri=${encodeURIComponent(redirect)}&code_challenge=${challenge}&code_challenge_method=S256&state=b335&scope=mcp`, { waitUntil: 'networkidle' });
          if ((await p.locator('#oauth-heslo').count()) !== 1) { throw new Error(`no password field on the consent (${label}, ${scheme})`); }
          await p.locator('.oauth-souhlas input[name="access"][value="full"]').check({ force: true });
          await Promise.all([p.waitForNavigation(), p.locator('.oauth-souhlas button[name="povolit"][value="1"]').click()]);
          if ((await p.locator('.oauth-souhlas .hlaska-chyba').count()) !== 1 || !(await p.locator('.oauth-souhlas input[name="access"][value="full"]').isChecked())) {
            throw new Error(`full access without the password was not refused on the page with the choice kept (${label}, ${scheme})`);
          }
          const wide = await p.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
          if (wide > 0) { throw new Error(`${wide} px of sideways scroll (${label}, ${scheme})`); }
          if (SHOTS) { await p.screenshot({ path: `${SHOTS}/335b-consent-${label}-${scheme}.png`, fullPage: true }); }
          if (label === 'desktop') {
            const found = await axeViolations(p, `consent ${scheme}`);
            if (found.length) { throw new Error(found.join('; ')); }
          }
        } finally {
          await ctx.close();
        }
      }
    }
  });

  // 3.35 A: passkeys through Chrome's virtual authenticator (the CDP WebAuthn domain) – registration in My account, the sign-in
  // without a user name and password from the button, and the conditional UI (the user-name autofill) waiting on the sign-in page.
  // WebAuthn needs a domain, not an IP: this step runs on http://localhost:<port> with site_url pointed there, and restores it.
  await step('3.35 A: passkey registration, passwordless sign-in and conditional UI (virtual authenticator)', async () => {
    const local = BASE.replace('127.0.0.1', 'localhost');
    const siteUrl = sql("SELECT hodnota FROM ka_nastaveni WHERE promenna = 'site_url'").trim();
    const adminId = sql("SELECT idu FROM ka_uzivatele WHERE user = 'admin'").trim();
    const codesBefore = sql(`SELECT IFNULL(totp_zalozni, '') FROM ka_uzivatele WHERE idu = ${adminId}`).trim();
    // 3.41 (N116): adding the passkey in this other context ends the main page's sign-in – the epoch is put back below
    const epochBefore = Number(sql(`SELECT sign_in_epoch FROM ka_uzivatele WHERE idu = ${adminId}`).trim()) || 0;
    sql(`UPDATE ka_nastaveni SET hodnota = '${local}' WHERE promenna = 'site_url'`);
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'en-GB' });
    try {
      const p = await ctx.newPage();
      watch(p);
      const cdp = await ctx.newCDPSession(p);
      await cdp.send('WebAuthn.enable');
      const { authenticatorId } = await cdp.send('WebAuthn.addVirtualAuthenticator', { options: {
        protocol: 'ctap2', transport: 'internal', hasResidentKey: true, hasUserVerification: true, isUserVerified: true, automaticPresenceSimulation: true } });
      await p.goto(local + '/admin.php', { waitUntil: 'networkidle' });
      await p.fill('input[name="user"]', 'admin');
      await p.fill('input[name="password"]', PASSWORD);
      await Promise.all([p.waitForNavigation(), p.press('input[name="password"]', 'Enter')]);
      await p.goto(local + '/admin.php?action=account&tab=security', { waitUntil: 'networkidle' });
      await p.fill('#klic-nazev', 'Virtual authenticator');
      await p.fill('#klic-heslo', PASSWORD);
      await Promise.all([p.waitForNavigation(), p.click('#passkeys [data-klic-pridat]:not([data-nahradit])')]);
      const codes = await p.locator('.zalozni-kody').textContent();
      if (!/^([0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}\W*){8}$/.test((codes || '').trim())) { throw new Error(`backup codes with the first passkey: ${codes}`); }
      const stored = await cdp.send('WebAuthn.getCredentials', { authenticatorId });
      if (stored.credentials.length !== 1 || !stored.credentials[0].isResidentCredential) { throw new Error('the passkey is not a discoverable (resident) credential'); }
      const row = sql(`SELECT CONCAT(COUNT(*), '/', SUM(discoverable)) FROM ka_uzivatele_klice WHERE idu = ${adminId}`).trim();
      if (row !== '1/1') { throw new Error(`stored passkeys: ${row}`); }
      if (Buffer.from(stored.credentials[0].userHandle, 'base64').toString('base64url') !== sql(`SELECT passkey_handle FROM ka_uzivatele WHERE idu = ${adminId}`).trim()) {
        throw new Error('the user handle on the device is not the account\'s opaque handle');
      }
      const rowName = p.locator('#passkeys input[form^="klic-prejmenuj-"]');
      if ((await rowName.inputValue()) !== 'Virtual authenticator' || !(await rowName.getAttribute('aria-label'))) { throw new Error('the passkey row: name field with a label'); }
      // signing out lands on the sign-in page, which offers the passkeys in the user-name autofill (conditional UI): the virtual
      // authenticator answers the waiting request at once, so the page signs in by itself – no name, no password, no click
      const logged = () => Number(sql(`SELECT COUNT(*) FROM ka_protokol WHERE kdo = ${adminId} AND akce = 'login' AND popis = 'with a passkey, without a password'`).trim());
      const optionsRequest = p.waitForRequest((r) => r.method() === 'POST' && (r.postData() || '').includes('passkey_options'));
      await p.click('.menu-logout button');
      await optionsRequest;
      await p.waitForSelector('.menu-logout button', { timeout: 15000 });
      if (logged() < 1) { throw new Error('the autofill (conditional UI) did not sign in'); }
      // the button, from the keyboard, in a browser without the autofill: the modal request signs in the same way
      await p.addInitScript(() => { window.PublicKeyCredential.isConditionalMediationAvailable = () => Promise.resolve(false); });
      await Promise.all([p.waitForNavigation(), p.click('.menu-logout button')]);
      await p.waitForLoadState('networkidle');
      if ((await p.getAttribute('input[name="user"]', 'autocomplete')) !== 'username webauthn') { throw new Error('the user-name field does not offer passkeys (autocomplete webauthn)'); }
      const before = logged();
      await p.getByRole('button', { name: 'Sign in with a passkey' }).focus();
      await Promise.all([p.waitForNavigation(), p.keyboard.press('Enter')]);
      if (await p.locator('input[name="user"]').count()) { throw new Error('the passkey button did not sign in'); }
      if (logged() !== before + 1) { throw new Error(`change log of the sign-in with the button: ${logged()} after ${before}`); }
      if (SHOTS) { await p.goto(local + '/admin.php?action=account&tab=security', { waitUntil: 'networkidle' }); await p.locator('#passkeys').screenshot({ path: `${SHOTS}/335a-passkeys.png` }); }
      // the password of an account with a passkey needs the second step – the page offers the passkey (and a backup code)
      await p.goto(local + '/admin.php', { waitUntil: 'networkidle' });
      await Promise.all([p.waitForNavigation(), p.click('.menu-logout button')]);
      // 3.47 (U69): this browser signed in with a passkey – on the sign-in page the passkey is the primary button now
      if ((await p.getAttribute('[data-klic-bezhesla]', 'class')) !== 'tl' || (await p.getAttribute('[data-login-password]', 'class')) !== 'navigace') {
        throw new Error('the remembered passkey did not become the primary button on the sign-in page');
      }
      await p.fill('input[name="user"]', 'admin');
      await p.fill('input[name="password"]', PASSWORD);
      await Promise.all([p.waitForNavigation(), p.press('input[name="password"]', 'Enter')]);
      if (!(await p.locator('[data-klic-prihlasit]').count()) || !(await p.locator('input[name="kod"]').count())) { throw new Error('the second step after the password is missing'); }
      await Promise.all([p.waitForNavigation(), p.click('[data-klic-prihlasit]')]);
      if (await p.locator('input[name="user"], input[name="kod"]').count()) { throw new Error('the passkey as the second step did not sign in'); }
    } finally {
      await ctx.close();
      sql(`UPDATE ka_nastaveni SET hodnota = '${siteUrl}' WHERE promenna = 'site_url'; DELETE FROM ka_uzivatele_klice WHERE idu = ${adminId};
      UPDATE ka_uzivatele SET totp_zalozni = ${codesBefore === '' ? 'NULL' : `'${codesBefore}'`}, passkey_handle = '', sign_in_epoch = ${epochBefore} WHERE idu = ${adminId}`);
    }
  });

  // 3.36 (A): "Layout done" on a waiting row of the layout tab takes the imported page off the queue – the button fits 1440 and
  // 390 px in light and dark without sideways scroll and passes axe; clicking it leaves the page as it was
  await step('3.36 A: "Layout done" in the tab of layouts to rebuild (1440 and 390 px, light/dark, axe, the click)', async () => {
    if (!MYSQL.length) { throw new Error('MYSQL_JSON is not set'); }
    const build = '{"v":1,"deti":[{"id":"b336a","typ":"sekce","znacka":"section","obsah":{"sirka":"uzka"},"styl":{},"tridy":[],"deti":[{"id":"b336b","typ":"text","znacka":"div","obsah":{"html":"<p>Imported text B336</p>"},"styl":{},"tridy":[],"deti":[]}]}]}';
    const ids = Number(sql(`INSERT INTO ka_stranky (seo_link, titulek, text, stavba, zobrazit, v_menu) VALUES ('b336-imported', 'Imported B336', '<p>Imported text B336</p>', '${build}', 0, 0); SELECT LAST_INSERT_ID();`).trim());
    sql(`INSERT INTO ka_import_mapa (zdroj, typ, cizi_id, nase_id) SELECT 'layout', 'stranka', CONCAT(ids, ':', SHA2(stavba, 256)), ids FROM ka_stranky WHERE ids = ${ids}`);
    try {
      const state = await context.storageState();
      for (const scheme of ['light', 'dark']) {
        for (const [label, width, height] of [['desktop', 1440, 900], ['phone', 390, 844]]) {
          const other = await browser.newContext({ viewport: { width, height }, locale: 'en-GB', colorScheme: scheme, storageState: state, ...(width < 500 ? { isMobile: true, hasTouch: true } : {}) });
          const p2 = await other.newPage(); watch(p2);
          try {
            await p2.goto(`${BASE}/admin.php?module=pages&status=layout`, { waitUntil: 'networkidle' });
            const button = p2.locator(`[data-layout-done="${ids}"]`);
            if ((await button.count()) !== 1 || (await button.innerText()).trim() !== 'Layout done' || !(await button.isVisible())) { throw new Error(`no visible "Layout done" button (${label}, ${scheme})`); }
            if (!(await p2.locator('select[name="provest"] option[value="rebuilt"]').count())) { throw new Error('the bulk action "Layout done" is missing'); }
            const wide = await p2.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
            if (wide > 0) { throw new Error(`${wide} px of sideways scroll (${label}, ${scheme})`); }
            if (SHOTS) { await p2.screenshot({ path: `${SHOTS}/336a-layout-done-${label}-${scheme}.png`, fullPage: true }); }
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
          await axePage.goto(BASE + '/admin.php?module=pages&status=layout', { waitUntil: 'networkidle' });
          await axePage.addScriptTag({ path: AXE });
          const found = await axePage.evaluate(([inc, tags]) => window.axe.run({ include: inc.map((x) => [x]) }, { runOnly: { type: 'tag', values: tags }, resultTypes: ['violations'] }), [['table.vypis', 'form.hromadne'], WCAG]);
          if (found.violations.length) { throw new Error(`axe layout tab (${scheme}): ` + found.violations.map((v) => `${v.id} ${v.nodes[0].target.join(' ')}`).join(', ')); }
        }
      } finally {
        await axeContext.close();
      }
      await visit('/admin.php?module=pages&status=layout');
      await Promise.all([page.waitForNavigation(), page.click(`[data-layout-done="${ids}"]`)]);
      const left = sql(`SELECT CONCAT((SELECT COUNT(*) FROM ka_import_mapa WHERE zdroj = 'layout' AND nase_id = ${ids}), '|', (SELECT COUNT(*) FROM ka_stranky WHERE ids = ${ids} AND stavba = '${build}' AND smazano IS NULL))`).trim();
      if (left !== '0|1') { throw new Error(`after "Layout done": ${left} (the fingerprint must go, the page stay as it was)`); }
      if (!/taken off the list of layouts to rebuild: 1/.test(await page.locator('body').innerText())) { throw new Error('no message after "Layout done"'); }
    } finally {
      sql(`DELETE FROM ka_import_mapa WHERE zdroj = 'layout' AND nase_id = ${ids}; DELETE FROM ka_stranky WHERE ids = ${ids}`);
    }
  });

  // 3.37 (agent A): the record template – an enquiry, a deal and a customer in two columns with one action bar (sticky on a wide
  // screen), one column at 375 px with the bar reachable and no sideways scroll; the Handling form saves who handles the enquiry
  // with one Save; the board's card folds Stage + Move behind ⋯ with a mouse and is short; light and dark; axe on all four
  await step('3.37 record pages and board cards: two columns at 1440, one at 375, one bar, one Handling Save, ⋯ on a card (light/dark, axe)', async () => {
    if (!MYSQL.length) { throw new Error('MYSQL_JSON is not set'); }
    sql("DELETE FROM ka_deal_stage_history WHERE deal_id IN (SELECT id FROM ka_deals WHERE title LIKE '%A337%'); DELETE FROM ka_deals WHERE title LIKE '%A337%'; DELETE FROM ka_poptavky WHERE formular = 'Contact A337'; DELETE FROM ka_contacts WHERE email_norm LIKE '%a337%'");
    const admin = Number(sql("SELECT idu FROM ka_uzivatele WHERE user = 'admin'").trim());
    const olga = Number(sql(`INSERT INTO ka_contacts (email, email_norm, name, phone, language, source, first_seen_at, last_activity_at, created_at, updated_at, owner_id)
    VALUES ('olga@hartsteel-a337.example', 'olga@hartsteel-a337.example', 'Olga Longname-Hartsteel A337', '+44 20 7946 0337', 'en', 'enquiry', NOW(), NOW(), NOW(), NOW(), ${admin}); SELECT LAST_INSERT_ID();`).trim());
    const enquiry = Number(sql(`INSERT INTO ka_poptavky (datum, formular, email, data, stav, contact_id, kategorie, priorita, triaged_by) VALUES (NOW(), 'Contact A337', 'olga@hartsteel-a337.example',
    '[["Name","Olga Longname-Hartsteel A337"],["Phone","+44 20 7946 0337"],["Message","We need forty custom brackets for a new warehouse rack system, delivered to Leeds by the end of the month."]]', 1, ${olga}, 'sales', 3, 'claude'); SELECT LAST_INSERT_ID();`).trim());
    const deal = Number(sql(`INSERT INTO ka_deals (title, contact_id, owner_id, stage, value, currency, expected_close, created_at, updated_at)
    VALUES ('Forty custom brackets for the warehouse rack system A337', ${olga}, ${admin}, 'new', '18000.00', 'GBP', CURDATE() - INTERVAL 2 DAY, NOW(), NOW()); SELECT LAST_INSERT_ID();`).trim());
    sql(`INSERT INTO ka_deal_stage_history (deal_id, from_stage, to_stage, at) VALUES (${deal}, NULL, 'new', NOW())`);
    const pages = [`/admin.php?module=enquiries&action=detail&id=${enquiry}`, `/admin.php?module=pipeline&action=detail&id=${deal}`, `/admin.php?module=contacts&action=detail&id=${olga}`];
    try {
      // the Handling form: one Save keeps the person and the summary, Claude's unchanged sorting stays Claude's
      await page.setViewportSize({ width: 1440, height: 900 });
      await visit(pages[0]);
      if (await page.locator('button', { hasText: 'Save triage' }).count()) { throw new Error('the enquiry still has Save triage'); }
      await page.selectOption('#prirazeno', String(admin));
      await page.fill('#poznamka', 'Quote by Friday A337');
      await Promise.all([page.waitForNavigation(), page.locator('[data-enquiry-handling] button.tl[type="submit"]').click()]);
      const saved = sql(`SELECT CONCAT_WS('|', prirazeno, poznamka, triaged_by) FROM ka_poptavky WHERE idp = ${enquiry}`).trim();
      if (saved !== `${admin}|Quote by Friday A337|claude`) { throw new Error(`the Handling form saved: ${saved}`); }
      // More opens a menu with Delete and closes with Esc
      await page.locator('[data-record-more] > summary').click();
      if (!(await page.locator('[data-record-more] button', { hasText: 'Delete' }).isVisible())) { throw new Error('More does not show Delete'); }
      await page.keyboard.press('Escape');
      if (await page.locator('[data-record-more]').evaluate((d) => d.open)) { throw new Error('Esc did not close More'); }
      // the board: ⋯ folds Stage + Move with a mouse, the card is short; opening ⋯ shows the select
      await visit('/admin.php?module=pipeline');
      const card = page.locator(`#deal-${deal}`);
      if (await card.locator('select[name="stage"]').isVisible()) { throw new Error('the stage select is not folded behind ⋯ with a mouse'); }
      const cardHeight = (await card.boundingBox()).height;
      if (cardHeight > 190) { throw new Error(`the card is ${Math.round(cardHeight)} px tall`); } // 3.41: + one line of the days in the stage and the next step
      await card.locator('details.obchod-vice > summary').click();
      if (!(await card.locator('select[name="stage"]').isVisible())) { throw new Error('⋯ does not open the stage select'); }
      const state = await context.storageState();
      for (const scheme of ['light', 'dark']) {
        for (const [label, width, height] of [['desktop', 1440, 900], ['phone', 375, 812]]) {
          const other = await browser.newContext({ viewport: { width, height }, locale: 'en-GB', colorScheme: scheme, storageState: state, ...(width < 500 ? { isMobile: true, hasTouch: true } : {}) });
          const p2 = await other.newPage(); watch(p2);
          try {
            for (const url of [...pages, '/admin.php?module=pipeline']) {
              await p2.goto(`${BASE}${url}`, { waitUntil: 'networkidle' });
              const wide = await p2.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
              if (wide > 0) { throw new Error(`${wide} px of sideways scroll (${url}, ${label}, ${scheme})`); }
              if (url.endsWith('pipeline')) {
                // on a touch screen the stage select stays open on the card (no drag there); with a mouse it is behind ⋯
                if ((await p2.locator(`#deal-${deal} select[name="stage"]`).isVisible()) !== (label === 'phone')) { throw new Error(`the card's stage select (${label}, ${scheme})`); }
                if (SHOTS) { await p2.screenshot({ path: `${SHOTS}/337a-board-${label}-${scheme}.png`, fullPage: true }); }
                continue;
              }
              const main = await p2.locator('.zaznam-hlavni').boundingBox();
              const side = await p2.locator('.zaznam-bok').boundingBox();
              if (label === 'desktop' && !(side.x > main.x + main.width - 1 && Math.abs(side.y - main.y) < 40)) { throw new Error(`not two columns at 1440 (${url}, ${scheme})`); }
              if (label === 'phone' && Math.abs(side.x - main.x) > 1) { throw new Error(`not one column at 375 (${url}, ${scheme})`); }
              // the bar: at the top, its buttons a finger high; on a wide screen it stays under the top bar while scrolling
              const primary = p2.locator('[data-record-bar] :is(.tl, [data-record-status] button)').first();
              const box = await primary.boundingBox();
              if (!box || box.height < 40 || box.y > 520) { throw new Error(`the bar's primary action is not reachable (${url}, ${label}, ${scheme}): ${JSON.stringify(box)}`); }
              if (label === 'desktop') {
                // scrolled down, the bar sits under the top bar (64 px) – or where it is, when the page is too short to pass it
                const [start, scrolled, top] = await p2.locator('[data-record-bar]').evaluate((el) => { const t = el.getBoundingClientRect().top + window.scrollY; window.scrollTo(0, 1200); return [t, window.scrollY, el.getBoundingClientRect().top]; });
                if (Math.abs(top - Math.max(64, start - scrolled)) > 2) { throw new Error(`the bar does not stay at the top while scrolling (${url}): ${top}`); }
              }
              if (SHOTS) { await p2.screenshot({ path: `${SHOTS}/337a-${url.includes('enquiries') ? 'enquiry' : url.includes('pipeline') ? 'deal' : 'customer'}-${label}-${scheme}.png`, fullPage: true }); }
            }
          } finally {
            await other.close();
          }
        }
      }
      const axeContext = await browser.newContext({ storageState: state, bypassCSP: true, viewport: { width: 1440, height: 900 } });
      try {
        const axePage = await axeContext.newPage();
        for (const [url, include] of [...pages.map((u) => [u, ['[data-record-bar]', '.zaznam']]), ['/admin.php?module=pipeline', ['[data-pipeline-board]', '.obchody-navod']]]) {
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
      sql(`DELETE FROM ka_comments WHERE target IN ('enquiry:${enquiry}', 'deal:${deal}', 'contact:${olga}'); DELETE FROM ka_deal_stage_history WHERE deal_id = ${deal}; DELETE FROM ka_deals WHERE id = ${deal}; DELETE FROM ka_poptavky WHERE idp = ${enquiry}; DELETE FROM ka_contacts WHERE id = ${olga}`);
    }
  });

  await step('3.37 B: My account → Colleagues and the fallback e-mail signature (1440 and 390 px, light/dark, axe)', async () => {
    if (!MYSQL.length) { throw new Error('MYSQL_JSON is not set'); }
    const before = sql("SELECT CONCAT_WS('|', pozice, phone, IFNULL(team_item_id, 'NULL'), email) FROM ka_uzivatele WHERE user = 'admin'").trim().split('|');
    sql("UPDATE ka_uzivatele SET pozice = 'Owner B337', phone = '+420 777 337 337', team_item_id = NULL, email = 'nobody-b337@browser.example' WHERE user = 'admin'; INSERT INTO ka_uzivatele (user, password, jmeno, email, pozice, phone) VALUES ('colleague-b337', 'x', 'Colleague B337 With A Rather Long Name', 'colleague.with.a.long.address.b337@browser.example', 'Field engineer', '+420 603 337 000')");
    try {
      const state = await context.storageState();
      for (const scheme of ['light', 'dark']) {
        for (const [label, width, height] of [['desktop', 1440, 900], ['phone', 390, 844]]) {
          const other = await browser.newContext({ viewport: { width, height }, locale: 'en-GB', colorScheme: scheme, storageState: state, ...(width < 500 ? { isMobile: true, hasTouch: true } : {}) });
          const p2 = await other.newPage(); watch(p2);
          try {
            await p2.goto(`${BASE}/admin.php?action=account&tab=colleagues`, { waitUntil: 'networkidle' });
            const card = p2.locator('.kolegove .kolega', { hasText: 'Colleague B337' });
            if ((await card.count()) !== 1 || !(await card.isVisible()) || !(await card.innerText()).includes('Field engineer')) { throw new Error(`the colleague card is missing (${label}, ${scheme})`); }
            if ((await card.locator('a[href="tel:+420603337000"]').count()) !== 1) { throw new Error('the phone is not a tel: link'); }
            if (!(await p2.locator('nav.zalozky a[aria-current="true"]', { hasText: 'Colleagues' }).count())) { throw new Error('the Colleagues tab is not current'); }
            let wide = await p2.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
            if (wide > 0) { throw new Error(`${wide} px of sideways scroll on Colleagues (${label}, ${scheme})`); }
            if (SHOTS) { await p2.screenshot({ path: `${SHOTS}/337b-colleagues-${label}-${scheme}.png`, fullPage: true }); }
            await p2.goto(`${BASE}/admin.php?action=account&tab=signature`, { waitUntil: 'networkidle' });
            const preview = p2.locator('[data-podpis-nahled]');
            if (!(await preview.innerText()).includes('Owner B337') || (await preview.locator('a[href="tel:+420777337337"]').count()) !== 1) { throw new Error(`the fallback signature lacks the position or the phone (${label}, ${scheme})`); }
            wide = await p2.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
            if (wide > 1) { throw new Error(`${wide} px of sideways scroll on the signature (${label}, ${scheme})`); }
            if (SHOTS) { await p2.screenshot({ path: `${SHOTS}/337b-signature-${label}-${scheme}.png`, fullPage: true }); }
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
          await axePage.goto(BASE + '/admin.php?action=account&tab=colleagues', { waitUntil: 'networkidle' });
          await axePage.addScriptTag({ path: AXE });
          const found = await axePage.evaluate(([inc, tags]) => window.axe.run({ include: inc.map((x) => [x]) }, { runOnly: { type: 'tag', values: tags }, resultTypes: ['violations'] }), [['nav.zalozky', 'ul.kolegove'], WCAG]);
          if (found.violations.length) { throw new Error(`axe Colleagues (${scheme}): ` + found.violations.map((v) => `${v.id} ${v.nodes[0].target.join(' ')}`).join(', ')); }
        }
      } finally {
        await axeContext.close();
      }
      // the work phone is saved in My account and refused when it is not a number
      await visit('/admin.php?action=account');
      await page.fill('#phone', 'call me');
      await Promise.all([page.waitForNavigation(), page.locator('form:has(#phone) input[type="submit"]').click()]);
      if (sql("SELECT phone FROM ka_uzivatele WHERE user = 'admin'").trim() !== '+420 777 337 337') { throw new Error('a phone that is not a number was saved'); }
      if (!/may contain only digits/.test(await page.locator('body').innerText())) { throw new Error('no reason for the refused phone'); }
    } finally {
      const [pozice, phone, team, email] = before;
      sql(`UPDATE ka_uzivatele SET pozice = '${(pozice || '').replace(/'/g, "''")}', phone = '${(phone || '').replace(/'/g, "''")}', team_item_id = ${team === 'NULL' || !team ? 'NULL' : Number(team)}, email = '${(email || '').replace(/'/g, "''")}' WHERE user = 'admin'; DELETE FROM ka_uzivatele WHERE user = 'colleague-b337'`);
    }
  });
};
