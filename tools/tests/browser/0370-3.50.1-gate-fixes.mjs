// Kaleta browser steps 0370 (tools/test-browser.mjs runs the files of this folder in number order on one site – the order is
// the contract; tools/tests/README.md). 3.50.1 C – the UX Lows of the gate: an invalid settings field in the error colour of
// the theme, light and dark (UX-l5); the Claude sessions table at phone width without sideways page scroll (UX-l4); a draft
// comment the site refused keeps its text (UX-l7).
export default async (t) => {
  const { BASE, browser, page, sql, step, visit, SHOTS } = t;

  // UX-l5: the red of an invalid field comes from --chyba, so it follows dark mode instead of a fixed dark red
  await step('3.50.1 C UX-l5: an invalid settings field has the error colour of the theme in light and dark mode', async () => {
    await visit('/admin.php?module=settings&tab=general');
    // the browser's own pattern check is taken away, so the value reaches the server, which refuses it and marks the field
    await page.locator('input[name="news_slug"]').evaluate((el) => { el.removeAttribute('pattern'); el.form.noValidate = true; el.value = 'Not A Slug!'; });
    await Promise.all([page.waitForNavigation(), page.locator('input[name="news_slug"]').evaluate((el) => el.form.requestSubmit(el.form.querySelector('.vychozi-odeslani, [type="submit"]')))]);
    const field = page.locator('input[name="news_slug"][aria-invalid="true"]');
    if (await field.count() !== 1) { throw new Error('the refused value is not marked aria-invalid'); }
    const colours = async () => field.evaluate((el) => {
      const probe = document.createElement('span'); probe.style.color = 'var(--chyba)'; document.body.append(probe);
      const want = getComputedStyle(probe).color; probe.remove();
      return { border: getComputedStyle(el).borderTopColor, want };
    });
    const results = [];
    for (const scheme of ['light', 'dark']) {
      await page.emulateMedia({ colorScheme: scheme });
      const c = await colours();
      if (c.border !== c.want) { throw new Error(`${scheme}: the border is ${c.border}, the theme's error colour ${c.want}`); }
      results.push(c.want);
      if (SHOTS) { await page.screenshot({ path: `${SHOTS}/370-invalid-field-${scheme}.png` }); }
    }
    await page.emulateMedia({ colorScheme: 'light' });
    if (results[0] === results[1]) { throw new Error(`the error colour is the same in light and dark mode (${results[0]})`); }
  });

  // UX-l4: a long connection name in the sessions table scrolls inside the table, never the page
  await step('3.50.1 C UX-l4: Change log → Claude sessions at 390 px – the table scrolls inside tab-obal, no sideways page scroll', async () => {
    sql(`DELETE FROM ka_agent_sessions WHERE connection LIKE 'C3501 %'; INSERT INTO ka_agent_sessions (connection, started_at, last_at, calls)
      VALUES ('C3501 a-very-long-connection-name-without-any-space-to-break-it-up-on-a-phone', NOW() - INTERVAL 1 HOUR, NOW(), 3)`);
    const ctx = await browser.newContext({ storageState: await page.context().storageState(), viewport: { width: 390, height: 844 }, locale: 'en-GB' });
    try {
      const p = await ctx.newPage();
      await p.goto(BASE + '/admin.php?module=changelog&action=sessions', { waitUntil: 'networkidle' });
      if (await p.locator('.tab-obal > table.vypis').count() !== 1) { throw new Error('the sessions table is not inside tab-obal'); }
      const wide = await p.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
      if (wide > 1) { throw new Error(`${wide} px of sideways page scroll`); }
      if (SHOTS) { await p.screenshot({ path: `${SHOTS}/370-sessions-phone.png`, fullPage: true }); }
    } finally {
      await ctx.close();
      sql("DELETE FROM ka_agent_sessions WHERE connection LIKE 'C3501 %'");
    }
  });

  // UX-l7: the site refuses a comment without a name (the browser's own check is taken away to reach the server); the text
  // comes back from the tab's sessionStorage and the refusal is announced
  await step('3.50.1 C UX-l7: a draft comment the site refused keeps its text, and the error is an alert', async () => {
    const id = sql("SELECT MIN(ids) FROM ka_stranky WHERE smazano IS NULL AND stavba IS NOT NULL AND stavba <> ''").trim();
    await visit('/admin.php?module=pages');
    const token = await page.locator('input[name="_csrf"]').first().getAttribute('value');
    const answer = await page.evaluate(async ({ url, token }) => {
      const r = await fetch(url, { method: 'POST', credentials: 'same-origin', body: new URLSearchParams({ _csrf: token, dni: '1', komentare: '1' }) });
      return r.text();
    }, { url: `${BASE}/admin.php?module=pages&action=build_share&id=${id}`, token });
    const link = JSON.parse(answer).odkaz;
    if (!link) { throw new Error(`no share link: ${answer.slice(0, 200)}`); }
    const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 }, locale: 'en-GB' });
    try {
      const p = await ctx.newPage();
      await p.goto(link, { waitUntil: 'networkidle' });
      await p.locator('#ka-komentar > .ka-komentare-tl').click();
      await p.fill('#ka-komentar-panel textarea[name="text"]', 'C3501 the paragraph needs a new photo');
      await p.evaluate(() => { const n = document.querySelector('#ka-komentar-panel input[name="jmeno"]'); n.removeAttribute('required'); n.value = ''; });
      await Promise.all([p.waitForNavigation(), p.click('#ka-komentar-panel button[type="submit"]')]);
      if (!/comment=chyba/.test(p.url())) { throw new Error(`the comment was not refused: ${p.url()}`); }
      if (await p.locator('#ka-komentar-panel .ka-komentare-vysledek[role="alert"]').count() !== 1) { throw new Error('the refusal is not an alert'); }
      const kept = await p.inputValue('#ka-komentar-panel textarea[name="text"]');
      if (kept !== 'C3501 the paragraph needs a new photo') { throw new Error(`the text did not come back: "${kept}"`); }
      if (SHOTS) { await p.screenshot({ path: `${SHOTS}/370-refused-comment.png` }); }
    } finally {
      await ctx.close();
    }
  });
};
