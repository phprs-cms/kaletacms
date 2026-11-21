// Kaleta browser steps 0010 (tools/test-browser.mjs runs the files of this folder in number order on one site – the order is
// the contract; tools/tests/README.md). Moved verbatim from the single file in 3.49 P1.
export default async (t) => {
  const { BASE, PASSWORD, SHOTS, MYSQL, sql, page, step, visit, createHash } = t;

  await step('sign in', async () => {
    await visit('/admin.php');
    await page.fill('input[name="user"]', 'admin');
    await page.fill('input[name="password"]', PASSWORD);
    await Promise.all([page.waitForNavigation(), page.press('input[name="password"]', 'Enter')]);
  });

  // 3.18: whether Claude is connected – a chip next to the account on every screen with its state in words (never the colour
  // alone), the dot alone on a phone without sideways scrolling; Ask Claude warns and saves for later without a connection,
  // Requests have a status line and a highlighted old request, Claude settings a status panel. Then a call over MCP: connected.
  await step('3.18 header: the Claude connection state – not connected, then connected (1440 and 390 px, dark mode)', async () => {
    if (!MYSQL.length) { throw new Error('MYSQL_JSON is not set'); }
    sql("DELETE FROM ka_api_tokeny");
    const shot = async (name, target = page) => { if (SHOTS) { await target.screenshot({ path: `${SHOTS}/${name}.png`, ...(target === page ? { fullPage: false } : {}) }); } };
    const chip = async (expected, kind) => {
      const link = page.locator('.loginprouzek .claude-stav');
      if (!(await link.isVisible())) { throw new Error('no Claude state in the header'); }
      const state = await link.evaluate((el) => ({ cls: el.className, text: el.textContent.trim(), dot: getComputedStyle(el.querySelector('.claude-stav-tecka')).width }));
      if (!state.cls.includes(`claude-stav-${kind}`) || !state.text.startsWith(expected)) { throw new Error(`the header says ${JSON.stringify(state)}, expected ${expected}`); }
      if ((await page.getByRole('link', { name: new RegExp('^' + expected) }).count()) !== 1) { throw new Error(`no link named “${expected}” for screen readers`); }
    };
    const fits = async (where) => {
      const wide = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
      if (wide > 0) { throw new Error(`${where}: ${wide} px of sideways scroll at 390 px`); }
      const box = await page.locator('.loginprouzek .claude-stav').boundingBox();
      if (!box || box.width > 44 || box.x < 0 || box.x + box.width > 390) { throw new Error(`${where}: the Claude dot does not sit in the header row: ${JSON.stringify(box)}`); }
      const logo = await page.locator('.hlavicka .kaleta-logo').boundingBox();
      const first = await page.locator('.loginprouzek > :first-child').boundingBox();
      if (!logo || !first || logo.x + logo.width > first.x) { throw new Error(`${where}: the header buttons cover the logo: ${JSON.stringify([logo, first])}`); }
    };
    for (const [label, width, height] of [['desktop', 1440, 900], ['phone', 390, 844]]) {
      await page.setViewportSize({ width, height });
      await visit('/admin.php');
      await chip('Claude not connected', 'none');
      if (width === 390) { await fits('dashboard'); }
      const box = page.locator('.ask-claude:not(.pripojit-claude)');
      if (!(await box.locator('.claude-upozorneni').textContent()).includes('Claude is not connected to this site.')) { throw new Error('Ask Claude does not warn'); }
      if ((await box.locator('button[type="submit"]').textContent()).trim() !== 'Save for later') { throw new Error('Ask Claude still offers to send'); }
      await shot(`dashboard-none-${label}`);
      await box.scrollIntoViewIfNeeded();
      await shot(`ask-claude-warning-${label}`, box);
      await visit('/admin.php?module=requests');
      if ((await page.locator('tr.ceka-dlouho').count()) < 1) { throw new Error('the request waiting for weeks is not highlighted'); }
      if (width === 390) { await fits('requests'); }
      await shot(`requests-status-none-${label}`);
      await visit('/admin.php?module=claude_settings');
      if (!(await page.locator('.claude-panel h2').textContent()).includes('Claude is not connected')) { throw new Error('Claude settings have no status panel'); }
      if (width === 390) { await fits('Claude settings'); }
      await shot(`claude-settings-none-${label}`, page.locator('.claude-panel'));
    }
    // a personal token and one call over MCP: connected
    const token = 'kaleta_' + 'e'.repeat(48);
    sql(`INSERT INTO ka_api_tokeny (idu, nazev, otisk, vytvoren) SELECT idu, 'Browser 318', '${createHash('sha256').update(token).digest('hex')}', NOW() FROM ka_uzivatele WHERE user = 'admin'`);
    const call = await fetch(`${BASE}/mcp`, { method: 'POST', headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' }, body: JSON.stringify({ jsonrpc: '2.0', id: 1, method: 'tools/list' }) });
    if (call.status !== 200) { throw new Error(`the MCP call answered ${call.status}`); }
    for (const [label, width, height] of [['desktop', 1440, 900], ['phone', 390, 844]]) {
      await page.setViewportSize({ width, height });
      await visit('/admin.php');
      await chip('Claude connected', 'active');
      if (width === 390) { await fits('dashboard'); }
      if (!(await page.locator('.claude-stav .claude-stav-kdy').textContent()).includes('last call')) { throw new Error('the last call is not in the chip'); }
      await shot(`dashboard-active-${label}`);
      await visit('/admin.php?module=requests');
      await shot(`requests-status-active-${label}`);
      await visit('/admin.php?module=claude_settings');
      if (!(await page.locator('.claude-panel h2').textContent()).includes('Claude is connected')) { throw new Error('the status panel does not say connected'); }
      await shot(`claude-settings-active-${label}`, page.locator('.claude-panel'));
    }
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.emulateMedia({ colorScheme: 'dark' });
    await visit('/admin.php');
    const dark = await page.locator('.claude-stav').evaluate((el) => [getComputedStyle(el).backgroundColor, getComputedStyle(el).color]);
    if (dark[0] === 'rgb(255, 255, 255)') { throw new Error('the chip stays white in dark mode'); }
    await shot('dashboard-active-dark');
    await page.emulateMedia({ colorScheme: 'light' });
    // the steps below see a site Claude never called, as before (the Connect Claude card leads until a first call)
    sql("DELETE FROM ka_api_tokeny WHERE nazev = 'Browser 318'; DELETE FROM ka_nastaveni WHERE promenna = 'claude_first_used'");
  });
};
