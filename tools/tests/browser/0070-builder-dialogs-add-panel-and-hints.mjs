// Kaleta browser steps 0070 (tools/test-browser.mjs runs the files of this folder in number order on one site – the order is
// the contract; tools/tests/README.md). Moved verbatim from the single file in 3.49 P1.
export default async (t) => {
  const { SHOTS, page, canvas, builderReady, step, visit } = t;

  await step('3.18.1 builder dialogs: readable buttons when the admin theme differs from the system (light on dark, dark on light)', async () => {
    // the dialogs live on <body>, outside .st: with the system dark and the admin switched to light, Close was white on white
    for (const [system, admin] of [['dark', 'svetly'], ['light', 'tmavy']]) {
      await page.emulateMedia({ colorScheme: system });
      await page.evaluate((t) => document.documentElement.setAttribute('data-tema', t), admin);
      await page.evaluate(() => document.querySelector('#st-lista-vice button[title="Draft history and published versions"]').click()); // an item of the ⋯ menu
      const dialog = page.locator('dialog.st-dialog[open]');
      await dialog.waitFor();
      const bad = await dialog.locator('button').evaluateAll((list) => list.map((b) => {
        const s = getComputedStyle(b);
        return s.color === s.backgroundColor || !/Bricolage/.test(s.fontFamily) ? `${b.textContent.trim()}: ${s.color} on ${s.backgroundColor}, ${s.fontFamily}` : '';
      }).filter(Boolean));
      await page.keyboard.press('Escape');
      if (bad.length) { throw new Error(`system ${system}, admin ${admin}: ${bad.join('; ')}`); }
    }
    await page.evaluate(() => document.documentElement.removeAttribute('data-tema'));
    await page.emulateMedia({ colorScheme: 'light' });
  });

  await step('builder: insert elements from the Add panel by click and by drag', async () => {
    // broken from 1.4.0 to 3.4.0: newElement() built the element under English keys the builder does not read (3.4.1)
    const count = () => canvas().locator('[data-ka-id]').count();
    // the canvas redraws after the autosave – on a slow CI runner that can take a few seconds, so wait for the change
    const grew = async (before) => { for (let i = 0; i < 25; i++) { if ((await count()) > before) { return true; } await page.waitForTimeout(200); } return false; };
    await page.locator('.st-zalozky [role="tab"]').first().click();
    await page.waitForTimeout(300);
    for (const name of ['Heading', 'Image', 'Button']) {
      const before = await count();
      await page.locator('.st-prvky button', { hasText: new RegExp(`^${name}$`) }).first().click();
      if (!(await grew(before))) { throw new Error(`clicking "${name}" in the Add panel inserted nothing`); }
      await page.locator('.st-zalozky [role="tab"]').first().click();
      await page.waitForTimeout(300);
    }
    const before = await count();
    await page.locator('.st-prvky button', { hasText: /^Text$/ }).first().dragTo(canvas().locator('h1').first());
    if (!(await grew(before))) { throw new Error('dragging "Text" from the Add panel onto the canvas inserted nothing'); }
  });

  // 3.20 (U11): the panel of a Form links to Enquiries and to the spam protection; the links come from D.adresy, so an
  // administrator (who may open everything) sees both
  await step('3.20 U11: the Form panel links to Enquiries and to the spam protection', async () => {
    await visit('/admin.php?module=pages&action=builder&id=4'); // Contact of the Business starter: an enquiry form
    await builderReady();
    await canvas().locator('form').first().click({ position: { x: 4, y: 4 } });
    await page.getByRole('tab', { name: 'Content' }).first().click().catch(() => {});
    const links = page.locator('.st-panel .st-odkazy-dat a');
    await links.first().waitFor();
    const hrefs = await links.evaluateAll((list) => list.map((a) => a.textContent.trim() + ' → ' + a.getAttribute('href')));
    if (!hrefs.some((h) => /^Open Enquiries → .*module=enquiries$/.test(h)) || !hrefs.some((h) => /^Spam protection → .*module=settings&tab=cookies$/.test(h))) { throw new Error(`the form panel links: ${hrefs.join('; ')}`); }
    if (SHOTS) { await page.locator('.st-pravy').screenshot({ path: `${SHOTS}/form-panel-data-links.png` }); }
    await visit('/admin.php?module=pages&action=builder&id=1');
    await builderReady();
  });

  // 3.20 (C4, C6, C22): menu paths are links in the module hints too, every "How to connect Claude" leads to one place,
  // the Site audit points to the Notebook, the cookie policy hint links the page
  await step('3.20 C4/C6/C22: hints link their menu paths, one "connect Claude" destination, Notebook from the audit', async () => {
    await visit('/admin.php?module=newsletters');
    const mail = page.locator('.hlaska-varovani a', { hasText: 'Settings → Mail' });
    if (!(await mail.count())) { throw new Error('the newsletters warning does not link Settings → Mail'); }
    // the links are read from the DOM (the appearance hint sits in a folded section, so a role query would not see it)
    const hrefs = async (text) => page.locator('a', { hasText: text }).evaluateAll((list) => list.map((a) => a.getAttribute('href')));
    for (const screen of ['appearance', 'extensions']) {
      await visit(`/admin.php?module=${screen}`);
      const found = await hrefs('How to connect Claude');
      if (!found.length || found.some((href) => !/module=claude_settings#how-to-connect$/.test(href))) { throw new Error(`${screen}: "How to connect Claude" leads to ${JSON.stringify(found)}`); }
    }
    await visit('/admin.php?module=claude_settings');
    if (!(await page.locator('#how-to-connect').count())) { throw new Error('Claude settings have no #how-to-connect'); }
    if (!(await hrefs('Create a personal token – My account')).some((href) => /action=account&tab=claude$/.test(href))) { throw new Error('the Connections box does not lead to My account for a token'); }
    await visit('/admin.php?module=audit');
    if (!(await hrefs('Decisions written down in the Notebook')).some((href) => /module=notebook$/.test(href))) { throw new Error('the Site audit does not link the Notebook'); }
    // 3.23 (C17): one hand-over checklist – the progress says "x of y done" and matches the ticked rows; no folded client questions
    const handover = await page.evaluate(() => {
      const rows = [...document.querySelectorAll('.predani-seznam > li')];
      const progress = document.querySelector('progress.predani-postup');
      return { rows: rows.length, done: rows.filter((li) => li.classList.contains('ok')).length, max: Number(progress?.max), value: Number(progress?.value),
        label: document.querySelector('#predani-nadpis')?.textContent ?? '', folded: document.querySelectorAll('details.pokrocile summary').length,
        wide: document.documentElement.scrollWidth > document.documentElement.clientWidth };
    });
    if (handover.rows < 12 || handover.max !== handover.rows || handover.value !== handover.done || !handover.label.includes(`${handover.done} of ${handover.rows} done`) || handover.folded || handover.wide) {
      throw new Error(`the hand-over checklist: ${JSON.stringify(handover)}`);
    }
    await visit('/admin.php?module=settings&tab=cookies');
    const policy = await page.locator('#cookies_policy_url ~ .napoveda a').evaluateAll((list) => list.map((a) => a.getAttribute('href')));
    if (!policy.some((href) => /module=pages/.test(href))) { throw new Error(`the cookie policy hint does not lead to Pages: ${JSON.stringify(policy)}`); }
  });

  await step('builder: element tree and search', async () => {
    const search = page.locator('input.st-hledat').first();
    if (await search.count()) { await search.fill('text'); await page.waitForTimeout(400); await search.fill(''); }
    const tree = page.locator('.st-strom [role="treeitem"], .st-strom li').nth(1);
    if (await tree.count()) { await tree.click(); await page.keyboard.press('ArrowDown'); await page.keyboard.press('ArrowUp'); }
  });
};
