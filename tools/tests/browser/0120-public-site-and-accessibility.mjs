// Kaleta browser steps 0120 (tools/test-browser.mjs runs the files of this folder in number order on one site – the order is
// the contract; tools/tests/README.md). Moved verbatim from the single file in 3.49 P1.
export default async (t) => {
  const { BASE, SHOTS, browser, page, watch, step, visit, AXE, WCAG, axeViolations, STARTER_SITES } = t;

  await step('site parts: every header and footer template renders', async () => {
    for (const [part, templates] of [['hlavicka', ['klasicka', 'na-stred', 's-listou', 'minimalni']], ['paticka', ['sloupce', 'kompaktni', 'tiraz', 'vyzva']]]) {
      for (const template of templates) {
        await visit(`/admin.php?module=parts&action=templates&type=${part}`);
        await Promise.all([page.waitForNavigation(), page.locator(`input[name="sablona"][value="${template}"] ~ button`).click()]);
        await visit(`/?part=${part}&build=koncept`);
        if (SHOTS) {
          const box = page.locator(part === 'hlavicka' ? 'header' : 'footer').last();
          await box.screenshot({ path: `${SHOTS}/part-${part}-${template}.png` });
        }
      }
      await visit('/admin.php?module=parts');
    }
  });

  await step('menu editor', async () => {
    await visit('/admin.php?module=menu');
    const add = page.getByRole('button', { name: /Add|Přidat/ }).first();
    if (await add.count()) { await add.click().catch(() => {}); }
  });

  await step('public site: home, phone menu, cookies', async () => {
    await visit('/');
    const accept = page.getByRole('button', { name: /Accept|Allow|Přijmout/ }).first();
    if (await accept.count()) { await accept.click().catch(() => {}); }
    await page.setViewportSize({ width: 390, height: 844 });
    await visit('/');
    const toggle = page.locator('.ka-nav-prepinac, button[popovertarget]').first();
    if (await toggle.count()) { await toggle.click().catch(() => {}); await page.waitForTimeout(300); }
    await page.setViewportSize({ width: 1440, height: 900 });
  });

  for (const url of ['/services', '/contact', '/news', '/search?q=test']) {
    await step(`site ${url}`, () => visit(url));
  }

  // 3.5: axe-core (installed next to playwright-core, injected from the local file – no CDN) on the starter's home page, contact
  // page and a news item, light mode, the cookie bar showing (test-browser.sh switches on lead attribution, a policy link and
  // the accessibility toolbar), at 1440 and 390 px; any WCAG 2.0/2.1/2.2 A or AA violation fails. Dark mode: below (3.6).
  for (const [label, width, height] of [['desktop', 1440, 900], ['phone', 390, 844]]) {
    await step(`accessibility (axe, ${label}): home, contact and a news item with the cookie bar`, async () => {
      const ctx = await browser.newContext({ viewport: { width, height }, locale: 'en-GB', colorScheme: 'light', isMobile: width < 768, hasTouch: width < 768 });
      const p = await ctx.newPage();
      watch(p);
      try {
        if (!t.state.newsItem) {
          await p.goto(BASE + '/news', { waitUntil: 'networkidle' });
          t.state.newsItem = await p.locator('main a[href*="/news/"]').evaluateAll((links) => links.map((a) => new URL(a.href).pathname).find((path) => /^\/news\/[^/]+$/.test(path) && !path.includes('/category/') && !path.includes('/tag/')) || '');
          if (!t.state.newsItem) { throw new Error('no news item linked from /news'); }
        }
        const found = [];
        for (const url of ['/', '/contact', t.state.newsItem]) {
          await p.goto(BASE + url, { waitUntil: 'networkidle' });
          if (await p.locator('#cookies-lista:not([hidden])').count() !== 1) { throw new Error(`${url}: the cookie bar is not showing`); }
          await p.addScriptTag({ path: AXE });
          const result = await p.evaluate((tags) => window.axe.run(document, { runOnly: { type: 'tag', values: tags }, resultTypes: ['violations'] }), WCAG);
          for (const v of result.violations) { found.push(`${url} ${v.id} (${v.impact}, ${v.nodes.length}×): ${v.nodes[0].target.join(' ')}`); }
        }
        if (found.length) { throw new Error(`axe found ${found.length} WCAG violation(s): ${found.join(' | ')}`); }
      } finally {
        await ctx.close();
      }
    });
  }

  // 3.6 (UXP-01): every starter in dark mode (dark_mode = auto, the browser prefers dark) – until 3.5 the dark palette kept the
  // light primary and secondary, so links, buttons, the current menu item and focus rings failed (Consulting 1.14 : 1)
  for (const [label, width, height] of [['desktop', 1440, 900], ['phone', 390, 844]]) {
    await step(`accessibility (axe, dark mode, ${label}): home, services and contact of all three starters`, async () => {
      const ctx = await browser.newContext({ viewport: { width, height }, locale: 'en-GB', colorScheme: 'dark', isMobile: width < 768, hasTouch: width < 768 });
      const p = await ctx.newPage();
      watch(p);
      try {
        const found = [];
        for (const [starter, base] of Object.entries(STARTER_SITES)) {
          for (const url of ['/', '/services', '/contact']) {
            await p.goto(base + url, { waitUntil: 'networkidle' });
            if (!(await p.evaluate(() => matchMedia('(prefers-color-scheme: dark)').matches && document.documentElement.hasAttribute('data-tmavy')))) { throw new Error(`${starter}${url}: not in dark mode`); }
            found.push(...await axeViolations(p, `${starter}${url}`));
          }
          if (SHOTS) { await p.goto(base + '/', { waitUntil: 'networkidle' }); await p.screenshot({ path: `${SHOTS}/dark-${starter}-${label}.png` }); }
        }
        if (found.length) { throw new Error(`axe found ${found.length} WCAG violation(s) in dark mode: ${found.join(' | ')}`); }
      } finally {
        await ctx.close();
      }
    });
  }

  // 3.6 (UXP-04, UXM-03): submenus are disclosures – a button with aria-expanded and aria-controls per submenu, opened by click,
  // Enter or Space (on a touch tablet too), Esc closes it and returns focus; the phone menu is an accordion; without JavaScript
  // the submenus are still reachable. Crafts = the built-in header, Consulting = the Navigation element as a mega menu.
  for (const [starter, nav, opener] of [['remeslo', '.navigace', '.menu-tl'], ['poradenstvi', '.ka-nav', '.ka-nav-tl']]) {
    const base = STARTER_SITES[starter];
    if (!base) { continue; }
    await step(`submenus (${starter}): keyboard, touch tablet, phone accordion and no JavaScript; axe with a panel open`, async () => {
      const problems = [];
      const desktop = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'en-GB', colorScheme: 'light' });
      const p = await desktop.newPage();
      watch(p);
      try {
        await p.goto(base + '/', { waitUntil: 'networkidle' });
        const toggles = p.locator(`${nav} li.podmenu > button[aria-controls]`);
        if (await toggles.count() !== 2) { problems.push(`${await toggles.count()} submenu toggles, expected 2 (a linked parent and a group)`); }
        const states = await toggles.evaluateAll((list) => list.map((b) => [b.getAttribute('aria-expanded'), !!document.getElementById(b.getAttribute('aria-controls'))]));
        if (states.some(([expanded, target]) => expanded !== 'false' || !target)) { problems.push(`toggles not collapsed disclosures: ${JSON.stringify(states)}`); }
        // keyboard: Enter opens the linked parent's submenu, Esc closes it and keeps focus on the toggle; Space opens the group
        const first = toggles.first(), panel = p.locator(`${nav} li.podmenu`).first().locator(':scope > ul');
        await first.focus();
        if (await panel.isVisible()) { problems.push('a focused toggle opens its panel by itself (it should wait for Enter or Space)'); }
        await p.keyboard.press('Enter');
        if (await first.getAttribute('aria-expanded') !== 'true' || !(await panel.isVisible())) { problems.push('Enter does not open the submenu'); }
        await p.keyboard.press('Tab');
        if (!(await p.evaluate(() => !!document.activeElement.closest('li.podmenu > ul')))) { problems.push('Tab after opening does not move into the submenu'); }
        await p.keyboard.press('Escape');
        if (await first.getAttribute('aria-expanded') !== 'false' || await panel.isVisible() || !(await first.evaluate((b) => b === document.activeElement))) { problems.push('Esc does not close the submenu and return focus to its toggle'); }
        const group = toggles.nth(1);
        await group.focus();
        await p.keyboard.press(' ');
        if (await group.getAttribute('aria-expanded') !== 'true') { problems.push('Space does not open the group'); }
        problems.push(...await axeViolations(p, `${starter} desktop, group open`));
        if (SHOTS) { await p.screenshot({ path: `${SHOTS}/submenu-${starter}-open.png` }); }
        await p.mouse.click(5, 600);
        if (await group.getAttribute('aria-expanded') !== 'false') { problems.push('a click outside does not close the submenu'); }
        // a mouse still opens a panel on hover
        await p.locator(`${nav} li.podmenu`).nth(1).hover();
        if (!(await p.locator(`${nav} li.podmenu`).nth(1).locator(':scope > ul').isVisible())) { problems.push('hover does not open the submenu'); }
      } finally {
        await desktop.close();
      }
      // a touch tablet: a tap on the toggle opens the submenu (until 3.5 a tap followed the link and the submenu stayed hidden)
      const tablet = await browser.newContext({ viewport: { width: 1024, height: 768 }, locale: 'en-GB', isMobile: true, hasTouch: true });
      const t = await tablet.newPage();
      watch(t);
      try {
        await t.goto(base + '/', { waitUntil: 'networkidle' });
        const toggle = t.locator(`${nav} li.podmenu > button[aria-controls]`).first();
        await toggle.tap();
        if (await toggle.getAttribute('aria-expanded') !== 'true' || !(await t.locator(`${nav} li.podmenu`).first().locator(':scope > ul').isVisible())) { problems.push('a tap on a touch tablet does not open the submenu'); }
        if (new URL(t.url()).pathname !== '/') { problems.push('the tap navigated away'); }
      } finally {
        await tablet.close();
      }
      // the phone menu: groups collapsed, the one with the current page open; a tap opens another
      const phone = await browser.newContext({ viewport: { width: 390, height: 844 }, locale: 'en-GB', isMobile: true, hasTouch: true });
      const m = await phone.newPage();
      watch(m);
      try {
        await m.goto(base + '/services', { waitUntil: 'networkidle' });
        await m.locator(opener).first().tap();
        await m.waitForTimeout(300);
        const sheet = await m.locator(`${nav} li.podmenu`).evaluateAll((list) => list.map((li) => [li.classList.contains('aktivni'), li.querySelector(':scope > button[aria-controls]').getAttribute('aria-expanded'), getComputedStyle(li.querySelector(':scope > ul')).display !== 'none']));
        if (JSON.stringify(sheet) !== JSON.stringify([[true, 'true', true], [false, 'false', false]])) { problems.push(`phone menu: the current group open, the others closed – got ${JSON.stringify(sheet)}`); }
        if (SHOTS) { await m.screenshot({ path: `${SHOTS}/submenu-${starter}-phone.png` }); }
        await m.locator(`${nav} li.podmenu > button[aria-controls]`).nth(1).tap();
        if (!(await m.locator(`${nav} li.podmenu`).nth(1).locator(':scope > ul').isVisible())) { problems.push('phone menu: a tap does not open a group'); }
      } finally {
        await phone.close();
      }
      // without JavaScript: keyboard focus and hover still open the submenus, the phone menu shows them all
      for (const [w, h] of [[1440, 900], [390, 844]]) {
        const plain = await browser.newContext({ viewport: { width: w, height: h }, locale: 'en-GB', javaScriptEnabled: false });
        const n = await plain.newPage();
        try {
          await n.goto(base + '/');
          if (w > 768) {
            await n.locator(`${nav} li.podmenu`).first().locator(':scope > a').focus();
            await n.keyboard.press('Tab');
            if (!(await n.locator(`${nav} li.podmenu`).first().locator(':scope > ul').isVisible())) { problems.push('without JavaScript keyboard focus does not open the submenu'); }
          } else {
            await n.locator(opener).first().click();
            if (await n.locator(`${nav} li.podmenu > ul:visible`).count() !== 2) { problems.push('without JavaScript the phone menu does not show every submenu'); }
          }
        } finally {
          await plain.close();
        }
      }
      if (problems.length) { throw new Error(problems.join(' | ')); }
    });
  }

  await step('cookie bar (3.5): compact on a phone, reached right after the skip link, never hides keyboard focus', async () => {
    // until 3.4 the bar took 340 px of a 390×844 phone, came last in the tab order and covered focused links (WCAG 2.2 SC 2.4.11)
    const ctx = await browser.newContext({ viewport: { width: 390, height: 844 }, locale: 'en-GB', colorScheme: 'light', isMobile: true, hasTouch: true });
    const p = await ctx.newPage();
    watch(p);
    try {
      await p.goto(BASE + '/', { waitUntil: 'networkidle' });
      const bar = await p.evaluate(() => Math.round(document.getElementById('cookies-lista').getBoundingClientRect().height));
      if (bar > 180) { throw new Error(`the cookie bar is ${bar} px tall on a phone (at most 180)`); }
      if (SHOTS) { await p.screenshot({ path: `${SHOTS}/cookie-bar-phone.png` }); }
      await p.keyboard.press('Tab');
      await p.keyboard.press('Tab');
      if (!(await p.evaluate(() => !!document.activeElement.closest('#cookies-lista')))) { throw new Error('the second Tab does not reach the cookie bar'); }
      const hidden = [];
      for (let i = 0; i < 200; i++) {
        await p.keyboard.press('Tab');
        const r = await p.evaluate(() => {
          const e = document.activeElement;
          if (!e || e === document.body) { return null; }
          if (e.hasAttribute('data-test-seen')) { return { again: true }; }
          e.setAttribute('data-test-seen', '');
          const b = e.getBoundingClientRect(), c = document.getElementById('cookies-lista').getBoundingClientRect();
          const covered = !e.closest('#cookies-lista') && b.width > 0 && b.top >= c.top && b.bottom <= c.bottom && b.left >= c.left && b.right <= c.right;
          return { covered, text: (e.textContent || e.getAttribute('aria-label') || e.tagName).trim().slice(0, 40) };
        });
        if (r && r.again) { break; }
        if (r && r.covered) { hidden.push(r.text); }
      }
      if (hidden.length) { throw new Error(`the cookie bar hides keyboard focus on: ${hidden.join(', ')}`); }
      // the accessibility toolbar sits above the bar; its panel opens above its button, not at the top of the window (UXP-03)
      const toolbar = await p.evaluate(() => [document.querySelector('.ka-pristupnost-tl').getBoundingClientRect().bottom, document.getElementById('cookies-lista').getBoundingClientRect().top]);
      if (toolbar[0] > toolbar[1]) { throw new Error('the accessibility toolbar is behind the cookie bar'); }
      await p.locator('.ka-pristupnost-tl').click();
      const panel = await p.evaluate(() => document.getElementById('ka-pristupnost-panel').getBoundingClientRect().top);
      if (panel < 844 / 3) { throw new Error(`the accessibility panel opens at the top of the window (top ${Math.round(panel)} px)`); }
      await p.locator('[data-pristupnost-volba="text"]').click();
      if (!/112[.,]5\s?%/.test(await p.locator('[data-pristupnost-volba="text"]').innerText())) { throw new Error('"Larger text" does not show the size it set'); }
      await p.keyboard.press('Escape');
      // once the visitor chooses, the bar's scroll padding goes with it
      await p.locator('#cookies-lista [data-cookies="nic"]').click();
      const after = await p.evaluate(() => [document.documentElement.style.getPropertyValue('--ka-cookies-vyska'), getComputedStyle(document.documentElement).scrollPaddingBottom]);
      if (after[0] !== '' || after[1] !== 'auto') { throw new Error(`the bar's scroll padding stays after the choice (${after.join(', ')})`); }
    } finally {
      await ctx.close();
    }
  });
};
