// Kaleta browser steps 0060 (tools/test-browser.mjs runs the files of this folder in number order on one site – the order is
// the contract; tools/tests/README.md). Moved verbatim from the single file in 3.49 P1.
export default async (t) => {
  const { BASE, SHOTS, sql, browser, page, canvas, builderReady, step, visit } = t;

  // 3.20 "Connected admin", part B (CPO audit C2, C5, C16, C19, C20)
  await step('3.20 (C2, C16) enquiry → form → page: the detail names the form and the page with admin links, the builder opens with the form selected, Statistics link the page; a resolved enquiry offers a testimonial request, folded below the actions', async () => {
    // the starter's contact page carries a form; its hidden fields name the source and the element (Front\Forms)
    const visitor = await browser.newContext();
    const html = await (await visitor.request.get(`${BASE}/contact`)).text();
    const source = /name="zdroj" value="([^"]+)"/.exec(html);
    const element = /name="prvek" value="([^"]+)"/.exec(html);
    if (!source || !element) { throw new Error('the contact page has no form'); }
    const pageId = Number(source[1].replace('stranka:', ''));
    sql(`INSERT INTO ka_poptavky (datum, formular, zdroj, prvek, stranka, email, data, stav) VALUES (NOW(), 'Enquiry', '${source[1]}', '${element[1]}', '/contact', 'hannah@example.com', '[["Message","Where do I change the recipient?"]]', 0)`);
    // an older or imported row knows only the form name and the public path: the page by its address, the form by its name
    sql("INSERT INTO ka_poptavky (datum, formular, zdroj, prvek, stranka, email, data, stav) VALUES (NOW(), 'Enquiry', '', '', '/contact', 'old@example.com', '[]', 1)");
    const [newId, oldId] = sql("SELECT idp FROM ka_poptavky WHERE email IN ('hannah@example.com', 'old@example.com') ORDER BY email = 'old@example.com'").trim().split('\n').map(Number);
    await visit(`/admin.php?module=enquiries&action=detail&id=${newId}`);
    const origin = page.locator('dd[data-origin]');
    const line = ((await origin.textContent()) || '').replace(/\s+/g, ' ');
    if (!/From the form Enquiry on the page Contact – Open in the builder · Page settings/.test(line)) { throw new Error(`the origin line reads: ${line}`); }
    const builderHref = await origin.locator('a', { hasText: 'Open in the builder' }).getAttribute('href');
    if (!builderHref.endsWith(`module=pages&action=builder&id=${pageId}#element=${element[1]}`)) { throw new Error(`the builder link is ${builderHref}`); }
    if (!(await origin.locator('a', { hasText: 'Page settings' }).getAttribute('href')).includes(`module=pages&action=edit&id=${pageId}`)) { throw new Error('Page settings does not open the page form'); }
    // C16, 3.23.1 (N-C10 and the owner): asking for a testimonial is a folded step after the main actions – not on a new enquiry;
    // once it is marked resolved, the same buttons and one plain sentence for every role (the References collection comes with the first answer)
    if (await page.locator('[data-testimonial]').count() !== 0) { throw new Error('a new enquiry already offers to ask for a testimonial'); }
    await Promise.all([page.waitForNavigation(), page.locator('[data-record-status] button', { hasText: 'Mark as resolved' }).click()]);
    if (!page.url().includes(`id=${newId}`)) { await visit(`/admin.php?module=enquiries&action=detail&id=${newId}`); }
    const ask = page.locator('details.reference-zadost[data-testimonial]');
    if (await ask.count() !== 1 || await ask.evaluate((d) => d.open)) { throw new Error('a resolved enquiry has no folded testimonial block'); }
    if (!(await ask.evaluate((d) => { const actions = document.querySelector('[data-record-bar] form[action*="action=status"]'); return !!actions && !!(actions.compareDocumentPosition(d) & Node.DOCUMENT_POSITION_FOLLOWING); }))) { throw new Error('the testimonial block is not below the main actions'); }
    if ((await ask.locator('summary').textContent()).trim() !== 'Ask the customer for a testimonial') { throw new Error('the summary is not “Ask the customer for a testimonial”'); }
    await ask.locator('summary').click();
    if (!(await ask.locator('button', { hasText: 'Ask for a testimonial by e-mail' }).isVisible()) || !(await ask.locator('button', { hasText: 'Only create the link' }).isVisible())) { throw new Error('the two request buttons are not in the block'); }
    if (!(await ask.textContent()).includes('Was the customer happy? Send them a link where they can write a few words about you for your website. Nothing is published until you publish it.')) { throw new Error('the plain sentence is missing'); }
    if (await page.locator('button[name="preset"][value="references"]').count() !== 0) { throw new Error('the testimonial block still asks to create the References collection first'); }
    if (SHOTS) { await page.screenshot({ path: `${SHOTS}/enquiry-origin.png`, fullPage: true }); }
    await visit(`/admin.php?module=enquiries&action=detail&id=${oldId}`);
    if ((await page.locator('dd[data-origin] a', { hasText: 'Open in the builder' }).getAttribute('href')) !== builderHref) { throw new Error('an older enquiry without the element id does not find the form by its name'); }
    // the builder: the form is selected, the inspector shows it, the canvas scrolled to it, and the hash is dropped from the address
    await page.goto(new URL(builderHref, BASE).href, { waitUntil: 'networkidle' });
    const selected = canvas().locator(`[data-ka-id="${element[1]}"].ka-st-vybrany`);
    await selected.waitFor({ timeout: 15000 });
    if (!/Form name/.test((await page.locator('.st-pravy').textContent()) || '')) { throw new Error('the inspector does not show the selected form'); }
    if (page.url().includes('#element=')) { throw new Error('the #element hash stays in the address'); }
    const inView = await selected.evaluate((el) => { const r = el.getBoundingClientRect(); return r.bottom > 0 && r.top < window.innerHeight; });
    if (!inView) { throw new Error('the canvas did not scroll to the form'); }
    // an unknown id is ignored silently: nothing selected, no script error (the step fails on console errors)
    await page.goto(new URL(builderHref.replace(/#element=.*$/, '#element=nothing'), BASE).href);
    await page.reload({ waitUntil: 'networkidle' }); // a change of the hash alone is no new page load
    await canvas().locator('[data-ka-id]').first().waitFor({ timeout: 15000 });
    if (await canvas().locator('.ka-st-vybrany').count() !== 0) { throw new Error('an unknown #element id selected something'); }
    await builderReady(); // the reloaded builder has drawn its canvas before the next screen
    // Statistics: "Pages that bring leads" gets a small "edit" link into the page form next to the public one
    await visit('/admin.php?module=stats');
    const leadRow = page.locator('.stat-siroka tr', { hasText: '/contact' }).first();
    const editHref = await leadRow.locator('a', { hasText: 'edit' }).getAttribute('href');
    if (!editHref || !editHref.includes(`module=pages&action=edit&id=${pageId}`)) { throw new Error(`the lead row has no edit link to the page: ${editHref}`); }
    await visitor.close();
    sql("DELETE FROM ka_poptavky WHERE email IN ('hannah@example.com', 'old@example.com')");
  });

  await step('3.20 (C5) a collection from a preset shows its listing page – hidden · Edit in the builder · Publish · Add to the menu – and Publish puts the page live', async () => {
    await visit('/admin.php?module=collections');
    await Promise.all([page.waitForNavigation(), page.locator('button[name="preset"][value="people"]').click()]);
    try {
    const strip = page.locator('[data-listing-page]');
    const text = ((await strip.textContent()) || '').replace(/\s+/g, ' ').trim();
    if (!/^Listing page: \/team – hidden · Edit in the builder · Publish · Add to the menu$/.test(text)) { throw new Error(`the listing page line reads: ${text}`); }
    if (!(await strip.locator('a', { hasText: 'Edit in the builder' }).getAttribute('href')).includes('module=pages&action=builder&id=')) { throw new Error('Edit in the builder does not open the page builder'); }
    if (!(await strip.locator('a', { hasText: 'Add to the menu' }).getAttribute('href')).includes('module=menu')) { throw new Error('Add to the menu does not open the Menu module'); }
    if (!/publish the page from the “Listing page” line below/.test((await page.locator('.hlaska').first().textContent()) || '')) { throw new Error('the creation message does not point to the listing page line'); }
    const empty = (await page.locator('.prazdny-stav').textContent()) || '';
    if (!/the page \/team lists the items/.test(empty) || /Collection list element/.test(empty)) { throw new Error(`the empty state contradicts the listing page: ${empty.trim()}`); }
    if (SHOTS) { await page.screenshot({ path: `${SHOTS}/collection-listing-page.png`, fullPage: false }); }
    const visitor = await browser.newContext();
    if ((await visitor.request.get(`${BASE}/team`)).status() !== 404) { throw new Error('the hidden listing page is live before Publish'); }
    await Promise.all([page.waitForNavigation(), strip.locator('button', { hasText: 'Publish' }).click()]);
    const after = ((await page.locator('[data-listing-page]').textContent()) || '').replace(/\s+/g, ' ');
    if (!/\/team – published · Edit in the builder · Add to the menu/.test(after)) { throw new Error(`after Publish the line reads: ${after.trim()}`); }
    if ((await visitor.request.get(`${BASE}/team`)).status() !== 200) { throw new Error('the listing page is not live after Publish'); }
    await visitor.close();
    } finally {
      // tidy up: the later steps start on a site without collections (the ready-made ones are offered unfolded)
      sql("DELETE FROM ka_kolekce WHERE seo_link = 'team'; DELETE FROM ka_stranky WHERE seo_link = 'team' AND jazyk = ''");
    }
  });

  await step('3.20 (C19, C20) the news form always names the category, with Manage categories; Broken links is a tab of Addresses in Site care and opens the same screen', async () => {
    const categories = Number(sql('SELECT COUNT(*) FROM ka_kategorie').trim());
    await visit('/admin.php?module=news&action=new');
    if (categories < 2) {
      const shown = ((await page.locator('[data-category]').textContent()) || '').trim();
      if (!/\S/.test(shown) || await page.locator('input[type="hidden"][name="tema"]').count() !== 1) { throw new Error(`one category, yet the form does not name it: "${shown}"`); }
    } else if (await page.locator('select#tema').count() !== 1) { throw new Error('two or more categories, yet no category select'); }
    if (!(await page.locator('fieldset a', { hasText: 'Manage categories' }).first().getAttribute('href')).includes('module=categories')) { throw new Error('Manage categories does not open Categories'); }
    await visit('/admin.php?module=broken_links');
    if ((await page.locator('h1').textContent()) !== 'Broken links') { throw new Error('module=broken_links does not open the Broken links screen'); }
    // 3.25: a group is a <details> – its summary names it, its items follow in .menu-cast. 3.48: for an administrator Broken
    // links is a tab of the Addresses hub (Redirects | Broken links), the menu item Addresses sits in Site care
    const items = await page.locator('.menu summary, .menu li:not(.menu-sekce)').evaluateAll((list) => list.map((li) => li.textContent.replace(/\s+/g, ' ').trim()));
    const at = items.findIndex((x) => x.startsWith('Addresses'));
    if (at < 1 || items.includes('Broken links') || items.some((x) => x.startsWith('Redirects')) || items.lastIndexOf('Site care', at) < 0 || items.slice(items.lastIndexOf('Site care', at), at).some((x) => x === 'Content')) { throw new Error(`the menu order is ${items.join(' | ')}`); }
    if (!/Addresses/.test((await page.locator('.menu li.aktivni').textContent()) || '')) { throw new Error('the menu does not highlight Addresses'); }
    const hubTabs = await page.locator('nav.zalozky-hub a').evaluateAll((list) => list.map((a) => a.textContent.trim() + (a.getAttribute('aria-current') === 'page' ? '*' : '')));
    if (hubTabs.join(' | ') !== 'Redirects | Broken links*') { throw new Error(`the Addresses tabs are ${hubTabs.join(' | ')}`); }
    if (await page.locator('a', { hasText: 'Back to the news list' }).count() !== 0) { throw new Error('opened from Site care, the screen still says back to news'); }
    // the News shortcut still opens the same screen, there with its back link
    await visit('/admin.php?module=news');
    await Promise.all([page.waitForNavigation(), page.locator('.navigace-radek a', { hasText: 'Broken links' }).click()]);
    if ((await page.locator('h1').textContent()) !== 'Broken links' || await page.locator('a', { hasText: 'Back to the news list' }).count() !== 1) { throw new Error('News → Broken links lost its shortcut or back link'); }
    // the following steps continue in the builder of the home page, where the step before these left it
    await visit('/admin.php?module=pages&action=builder&id=1');
    await canvas().locator('[data-ka-id]').first().waitFor({ timeout: 15000 });
  });
};
