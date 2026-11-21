// Kaleta browser steps 0040 (tools/test-browser.mjs runs the files of this folder in number order on one site – the order is
// the contract; tools/tests/README.md). Moved verbatim from the single file in 3.49 P1.
export default async (t) => {
  const { BASE, SHOTS, browser, page, canvas, builderReady, step, visit } = t;

  await step('3.5 dashboard: Connect Claude leads until Claude is connected, its Copy button works', async () => {
    await visit('/admin.php');
    const card = page.locator('#pripojit-claude');
    await card.waitFor();
    const ordered = await page.evaluate(() => {
      const [a, b, c] = [document.getElementById('pripojit-claude'), document.querySelector('.pruvodce'), document.getElementById('ask-claude-nadpis')];
      return Boolean(a && b && c && a.compareDocumentPosition(b) & Node.DOCUMENT_POSITION_FOLLOWING && b.compareDocumentPosition(c) & Node.DOCUMENT_POSITION_FOLLOWING);
    });
    if (!ordered) { throw new Error('the dashboard does not show Connect Claude, then First steps, then Ask Claude'); }
    const copy = card.locator('[data-kopirovat]');
    const label = await copy.textContent();
    await copy.click();
    await page.waitForFunction(([el, before]) => el.textContent !== before, [await copy.elementHandle(), label]);
    if (SHOTS) { await page.screenshot({ path: `${SHOTS}/dashboard-connect-claude.png`, fullPage: false }); }
  });

  await step('3.45 review M1: the first steps keep the full width and the grid of steps – no rule of the guide reaches them (1440 and 375 px)', async () => {
    for (const width of [1440, 375]) {
      await page.setViewportSize({ width, height: 900 });
      await visit('/admin.php');
      const box = await page.evaluate(() => {
        const d = document.querySelector('details.pruvodce'), list = d && d.querySelector('.pruvodce-kroky');
        if (!d || !list) { return null; }
        const parent = d.parentElement, ps = getComputedStyle(parent), ls = getComputedStyle(list);
        return { gap: d.getBoundingClientRect().width - (parent.clientWidth - parseFloat(ps.paddingLeft) - parseFloat(ps.paddingRight)), indent: ls.paddingInlineStart, rowGap: ls.rowGap, maxWidth: getComputedStyle(d).maxWidth };
      });
      if (box === null) { throw new Error('the dashboard has no first steps to measure'); }
      if (Math.abs(box.gap) > 1 || box.indent !== '0px' || box.rowGap !== '10px' || box.maxWidth !== 'none') { throw new Error(`the first steps at ${width} px are not as in 3.44: ${JSON.stringify(box)}`); }
    }
    await page.setViewportSize({ width: 1440, height: 900 });
  });

  await step('3.24 N-U3/N-U4: New page starts in the builder – three choices, the text editor only for a text page, and the page title band is not offered twice', async () => {
    await visit('/admin.php?module=pages&action=new');
    const editor = page.locator('.radek.nova-jen-text');
    const create = page.locator('.tlacitka .nova-jen-stavitel button');
    const save = page.locator('.tlacitka .nova-jen-text button');
    const shown = async () => [await editor.isVisible(), await create.isVisible(), await save.isVisible(), await page.locator('#sablona').isVisible()].join('/');
    if (!(await page.locator('#start-builder').isChecked()) || await shown() !== 'false/true/false/false') { throw new Error(`the empty builder page is not the default start: ${await shown()}`); }
    await page.locator('label.karta-volba:has(#start-text)').click();
    if (await shown() !== 'true/false/true/false') { throw new Error(`a text page does not show the text editor and Save: ${await shown()}`); }
    await page.locator('label.karta-volba:has(#start-layout)').click();
    if (await shown() !== 'false/true/false/true') { throw new Error(`a ready-made layout does not show its list: ${await shown()}`); }
    if (SHOTS) { await page.screenshot({ path: `${SHOTS}/new-page-start.png`, fullPage: false }); }
    await page.locator('label.karta-volba:has(#start-builder)').click();
    await page.fill('#titulek', 'Browser builder page');
    await Promise.all([page.waitForNavigation(), create.click()]);
    await page.locator('.st-lista .st-skryta').waitFor();
    await builderReady();
    if (await canvas().locator('h1').count() !== 1 || !/Browser builder page/.test((await canvas().locator('h1').first().textContent()) || '')) { throw new Error('the empty builder page does not start with its title band'); }
    await page.locator('.st-zalozky [role="tab"]').first().click();
    const titleBand = page.locator('.st-knihovna button', { hasText: 'Page title' }).first();
    if (!/Already on this page/.test((await titleBand.textContent()) || '')) { throw new Error('"Page title" is offered on a page that has its title band'); }
    const sections = await canvas().locator('section').count();
    await titleBand.click();
    await page.waitForTimeout(800);
    if (await canvas().locator('section').count() !== sections || await canvas().locator('h1').count() !== 1 || await canvas().locator('h2', { hasText: 'Page title' }).count()) { throw new Error('"Page title" added a second title band'); }
    if (!/already starts with its title/.test((await page.locator('.st-stav').first().textContent()) || '')) { throw new Error('choosing "Page title" does not say the page has its title'); }
  });

  await step('3.5 a new page from a template stays hidden until its build is published, then goes live', async () => {
    await visit('/admin.php?module=pages&action=new');
    await page.fill('#titulek', 'Browser template page');
    await page.locator('label.karta-volba:has(#start-layout)').click(); // 3.24 (N-U3): the layout list belongs to its choice
    await page.selectOption('#sablona', 'landing');
    await Promise.all([page.waitForNavigation(), page.locator('form.formular button[type="submit"]:visible').first().click()]);
    await page.locator('.st-lista .st-skryta').waitFor();
    const visitor = await browser.newContext();
    if ((await visitor.request.get(`${BASE}/browser-template-page`)).status() !== 404) { throw new Error('the empty template page is live before its build is published'); }
    await page.locator('.st-lista button.st-tl-hlavni').click();
    // 3.11 (UX310-1): the template's placeholders and sample texts are in the pre-publish check, the summary first
    const anyway = page.locator('dialog[open] .st-tl-hlavni');
    await anyway.waitFor({ timeout: 5000 });
    const firstFinding = (await page.locator('dialog[open] .st-kontrola li').first().textContent()) || '';
    if (!/This page still has sample content from the starter site \(sample testimonials: 2, placeholder figures: 2, sample texts: \d+\)/.test(firstFinding)) {
      throw new Error(`the pre-publish check does not lead with the sample content: ${firstFinding}`);
    }
    if (SHOTS) { await page.screenshot({ path: `${SHOTS}/builder-sample-content-check.png`, fullPage: false }); }
    await anyway.click();
    await page.locator('.st-lista .st-skryta').waitFor({ state: 'detached' });
    if ((await visitor.request.get(`${BASE}/browser-template-page`)).status() !== 200) { throw new Error('the page is not live after its first publish'); }
    await visitor.close();
  });
};
