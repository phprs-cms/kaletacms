// Kaleta browser steps 0080 (tools/test-browser.mjs runs the files of this folder in number order on one site – the order is
// the contract; tools/tests/README.md). Moved verbatim from the single file in 3.49 P1.
export default async (t) => {
  const { SHOTS, sql, page, canvas, builderReady, builderSaved, step, visit, liveCanvas, builderIdle, menuOpen } = t;

  // 3.21 (U8, U16, U18, U24): the builder for people who are not designers – the element menu, the nesting path, moving by
  // keyboard, instant canvas text and the Countdown date picker. The canvas is the live iframe (never the one still loading).

  await step('3.21 (U8) the element menu by right-click and Shift+F10, and the nesting path under the panel header', async () => {
    await visit('/admin.php?module=pages&action=builder&id=1');
    await liveCanvas().locator('h1').first().waitFor();
    await builderIdle();
    const elements = () => liveCanvas().locator('[data-ka-id]').count();
    await liveCanvas().locator('h1').first().click({ button: 'right' });
    const menu = page.locator('#st-kontext[role="menu"]');
    await menu.waitFor();
    const items = await menu.locator('[role="menuitem"]').evaluateAll((list) => list.map((b) => b.querySelector('span').textContent + (b.getAttribute('aria-disabled') ? ' (disabled)' : '')));
    const names = items.map((t) => t.replace(' (disabled)', ''));
    if (JSON.stringify(names) !== JSON.stringify(['Duplicate', 'Delete', 'Copy', 'Paste', 'Copy style', 'Paste style', 'Save as component', 'Select parent element'])) { throw new Error(`the element menu offers ${JSON.stringify(items)}`); }
    if (items[7].includes('disabled')) { throw new Error('Select parent element is disabled on a nested heading'); }
    if ((await page.evaluate(() => document.activeElement && document.activeElement.getAttribute('role'))) !== 'menuitem') { throw new Error('the menu does not take the focus'); }
    await page.keyboard.press('ArrowDown');
    if ((await page.evaluate(() => document.activeElement.textContent)) !== 'DeleteDelete') { throw new Error('ArrowDown does not move to the second item'); }
    if (SHOTS) { await page.screenshot({ path: `${SHOTS}/321-element-menu.png` }); }
    await page.keyboard.press('Escape');
    if (await menuOpen()) { throw new Error('Escape does not close the element menu'); }
    // Duplicate adds an element (then Ctrl+Z takes it back)
    const before = await elements();
    await liveCanvas().locator('h1').first().click({ button: 'right' });
    await menu.locator('[role="menuitem"]', { hasText: 'Duplicate' }).click();
    await page.waitForFunction((n) => { const f = document.querySelector('.st-platno iframe:not(.st-nacita)'); return f && f.contentDocument && f.contentDocument.querySelectorAll('[data-ka-id]').length > n; }, before, { timeout: 15000 });
    if (await menuOpen()) { throw new Error('the menu stays open after Duplicate'); }
    await builderIdle();
    await page.locator('body').press('ControlOrMeta+z');
    await page.waitForFunction((n) => { const f = document.querySelector('.st-platno iframe:not(.st-nacita)'); return f && f.contentDocument && f.contentDocument.querySelectorAll('[data-ka-id]').length === n; }, before, { timeout: 15000 });
    await builderIdle();
    // a click outside closes it
    await liveCanvas().locator('h1').first().click({ button: 'right' });
    await menu.waitFor();
    await page.locator('.st-pravy').click({ position: { x: 10, y: 300 } });
    if (await menuOpen()) { throw new Error('a click outside does not close the element menu'); }
    // the nesting path: every ancestor a button that selects it, the element itself plain text
    await liveCanvas().locator('h1').first().click();
    const path = page.locator('.st-pravy nav.st-cesta');
    await path.waitFor();
    const crumbs = await path.locator('li > *').evaluateAll((list) => list.map((x) => x.tagName + ':' + x.textContent));
    if (crumbs.length < 2 || !crumbs[crumbs.length - 1].startsWith('SPAN:Heading') || !crumbs.slice(0, -1).every((c) => c.startsWith('BUTTON:'))) { throw new Error(`the nesting path is ${JSON.stringify(crumbs)}`); }
    const wide = await page.locator('.st-pravy').evaluate((p) => p.scrollWidth - p.clientWidth);
    if (wide > 1) { throw new Error(`the nesting path widens the panel by ${wide} px`); }
    if (SHOTS) { await page.locator('.st-pravy').screenshot({ path: `${SHOTS}/321-nesting-path.png` }); }
    await path.locator('li > button').first().click();
    if ((await page.locator('.st-hlava-prvku strong').textContent()).trim() !== 'Section') { throw new Error('the first crumb does not select the section'); }
    if (await page.locator('.st-pravy nav.st-cesta').count()) { throw new Error('a top-level section shows a nesting path'); }
    if (await page.locator('.st-pravy').evaluate((p) => Array.from(p.childNodes).some((n) => n.nodeType === 3 && n.textContent.trim() === 'null'))) { throw new Error('the panel shows the text "null"'); }
    // Structure: Shift+F10 on a focused row opens the menu, Escape gives the focus back to the row; right-click on a row too
    await page.locator('.st-levy .st-zalozky [role="tab"]', { hasText: 'Structure' }).click();
    const row = page.locator('.st-strom [role="treeitem"]').nth(1);
    await row.focus();
    await page.keyboard.press('Shift+F10');
    await menu.waitFor();
    if (!(await menuOpen())) { throw new Error('Shift+F10 on a Structure row opens no menu'); }
    await page.keyboard.press('Escape');
    if ((await page.evaluate(() => document.activeElement && document.activeElement.getAttribute('role'))) !== 'treeitem') { throw new Error('Escape does not give the focus back to the Structure row'); }
    await row.click({ button: 'right' });
    if (!(await menuOpen())) { throw new Error('right-click on a Structure row opens no menu'); }
    await page.keyboard.press('Escape');
    await page.locator('.st-levy .st-zalozky [role="tab"]').first().click();
  });

  await step('3.21 (U16, U18) Alt+arrows move the element, typing in Content reaches the canvas within 150 ms', async () => {
    await builderIdle();
    // a leaf element with a next sibling: Alt+ArrowDown puts it after that sibling, Alt+ArrowUp back
    const id = await liveCanvas().locator('body').evaluate((b) => {
      const leaf = Array.from(b.querySelectorAll('[data-ka-id]:not([data-ka-zamek])')).find((x) => !x.querySelector('[data-ka-id]') && x.nextElementSibling && x.nextElementSibling.hasAttribute('data-ka-id') && x.getBoundingClientRect().height > 8);
      return leaf ? leaf.getAttribute('data-ka-id') : '';
    });
    if (!id) { throw new Error('no element with a next sibling on the canvas'); }
    const position = () => page.evaluate((i) => {
      const f = document.querySelector('.st-platno iframe:not(.st-nacita)');
      const x = f && f.contentDocument.querySelector(`[data-ka-id="${i}"]`);
      return x ? Array.from(x.parentElement.children).filter((c) => c.hasAttribute('data-ka-id')).indexOf(x) : -1;
    }, id);
    const start = await position();
    await liveCanvas().locator(`[data-ka-id="${id}"]`).click({ position: { x: 4, y: 4 } });
    await page.keyboard.press('Alt+ArrowDown');
    await page.waitForFunction(([i, p]) => { const f = document.querySelector('.st-platno iframe:not(.st-nacita)'); const x = f && f.contentDocument.querySelector(`[data-ka-id="${i}"]`); return x && Array.from(x.parentElement.children).filter((c) => c.hasAttribute('data-ka-id')).indexOf(x) === p + 1; }, [id, start], { timeout: 15000 });
    await builderIdle();
    await liveCanvas().locator(`[data-ka-id="${id}"]`).click({ position: { x: 4, y: 4 } }); // the canvas was redrawn: the keys go to the new one
    await page.keyboard.press('Alt+ArrowUp');
    await page.waitForFunction(([i, p]) => { const f = document.querySelector('.st-platno iframe:not(.st-nacita)'); const x = f && f.contentDocument.querySelector(`[data-ka-id="${i}"]`); return x && Array.from(x.parentElement.children).filter((c) => c.hasAttribute('data-ka-id')).indexOf(x) === p; }, [id, start], { timeout: 15000 });
    await builderIdle();
    const help = async () => { await page.locator('body').press('?'); const d = page.locator('dialog[open] .st-zkratky'); await d.waitFor(); const t = await d.textContent(); await page.keyboard.press('Escape'); return t; };
    const shortcuts = await help();
    if (!/Alt\+↑ \/ Alt\+↓|⌥\+↑/.test(shortcuts) || !shortcuts.includes('Shift+F10')) { throw new Error('the keyboard shortcuts do not list Alt+arrows and Shift+F10'); }
    // U16: the audit measured 836 ms from a key to the canvas (the redraw after the save); the text is on the canvas at once now
    await liveCanvas().locator('h1').first().click();
    await page.getByRole('tab', { name: 'Content' }).first().click();
    const input = page.locator('.st-panel [data-pole="text"] input').first();
    await input.click();
    await input.press('End');
    const h1 = await liveCanvas().locator('h1').first().getAttribute('data-ka-id');
    await page.evaluate(() => { window.u16Start = performance.now(); });
    await page.keyboard.type('Q');
    const ms = await page.waitForFunction((i) => {
      const f = document.querySelector('.st-platno iframe:not(.st-nacita)');
      const x = f && f.contentDocument.querySelector(`[data-ka-id="${i}"]`);
      return x && x.textContent.endsWith('Q') ? performance.now() - window.u16Start : false;
    }, h1, { polling: 'raf', timeout: 5000 }).then((r) => r.jsonValue());
    console.log(`         U16: keystroke to canvas ${Math.round(ms)} ms`);
    if (ms > 150) { throw new Error(`typing reached the canvas after ${Math.round(ms)} ms`); }
    await page.keyboard.press('Backspace');
    await builderIdle();
  });

  await step('3.21 (U24) the Countdown has a date-and-time picker and saves "YYYY-MM-DD HH:MM"', async () => {
    await page.locator('.st-levy .st-zalozky [role="tab"]').first().click();
    await page.locator('.st-prvky button', { hasText: /^Countdown$/ }).first().click();
    const picker = page.locator('.st-panel input[type="datetime-local"]');
    await picker.waitFor();
    if (!/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(await picker.inputValue())) { throw new Error(`the new Countdown shows "${await picker.inputValue()}"`); }
    if (SHOTS) { await page.locator('.st-pravy').screenshot({ path: `${SHOTS}/321-countdown-picker.png` }); }
    await picker.fill('2031-05-06T07:08');
    await builderIdle();
    const draft = sql('SELECT stavba_koncept FROM ka_stranky WHERE ids = 1');
    if (!draft.includes('"cil":"2031-05-06 07:08"')) { throw new Error('the draft does not keep the date as "YYYY-MM-DD HH:MM"'); }
    // the picker shows the stored value after a reload, then the Countdown goes again
    await visit('/admin.php?module=pages&action=builder&id=1');
    await liveCanvas().locator('.ka-odpocet').first().waitFor();
    await builderIdle();
    await liveCanvas().locator('.ka-odpocet').first().click({ position: { x: 4, y: 4 } });
    if ((await picker.inputValue()) !== '2031-05-06T07:08') { throw new Error(`after a reload the picker shows "${await picker.inputValue()}"`); }
    await page.locator('body').press('Delete');
    await liveCanvas().locator('.ka-odpocet').waitFor({ state: 'detached', timeout: 15000 });
    await builderIdle();
  });

  await step('3.12 UX310-18: the Form inspector – a row per field, a contact form edited without the condition fields, a condition by keyboard', async () => {
    const selectForm = async () => {
      await visit('/admin.php?module=pages&action=builder&id=4'); // Contact of the Business starter: an enquiry form
      await builderReady();
      await canvas().locator('form').first().click({ position: { x: 4, y: 4 } });
      await page.getByRole('tab', { name: 'Content' }).first().click().catch(() => {});
      await page.locator('.st-panel details.st-polozka-radek').first().waitFor();
      return page.locator('.st-panel details.st-polozka-radek');
    };
    let rows = await selectForm();
    if ((await rows.count()) < 3) { throw new Error(`the form shows ${await rows.count()} field rows`); }
    if (await page.locator('.st-panel details.st-polozka-radek[open]').count()) { throw new Error('a field row is open before it is opened'); }
    if (await page.locator('.st-panel .st-podminka').count()) { throw new Error('the condition fields show without asking for them'); }
    const summary = (await rows.first().locator('summary').textContent()).trim();
    if (!/ · /.test(summary)) { throw new Error(`the first row says "${summary}", not "label · type · …"`); }
    const wider = await page.locator('.st-panel').first().evaluate((panel) => panel.scrollWidth - panel.clientWidth);
    if (wider > 1) { throw new Error(`a long field label widens the inspector by ${wider} px`); }
    if (SHOTS) { await page.locator('.st-pravy').screenshot({ path: `${SHOTS}/form-inspector-rows.png` }); }
    // the keyboard opens a row (Enter on its summary) and Tab goes on to its label box
    await rows.first().locator('summary').focus();
    await page.keyboard.press('Enter');
    if (!(await rows.first().evaluate((d) => d.open))) { throw new Error('Enter does not open a field row'); }
    await page.keyboard.press('Tab');
    if (await page.evaluate(() => (document.activeElement && document.activeElement.closest('.st-polozka-radek') ? document.activeElement.tagName : '')) !== 'INPUT') { throw new Error('Tab from the row summary does not reach its label'); }
    await page.keyboard.press('ControlOrMeta+A');
    await page.keyboard.type('Your name');
    if (!/^Your name · /.test((await rows.first().locator('summary').textContent()).trim())) { throw new Error('the row does not follow the label'); }
    if (await rows.first().locator('.st-podminka').count()) { throw new Error('the condition opened by itself'); }
    // the condition is still there: "Add a condition" by keyboard moves the focus into it – after the autosave of the label
    // has come back (on a slow runner its redraw of the canvas and the panel could land between the key and the check)
    await builderSaved();
    await rows.first().locator('button.st-pridat-podminku').focus();
    await page.keyboard.press('Enter');
    // a slow runner may redraw the panel once more (a check coming back from the server) – the focus must survive it
    await page.waitForTimeout(600);
    if (!(await page.waitForFunction(() => !!(document.activeElement && document.activeElement.closest('.st-podminka')), null, { timeout: 3000 }).then(() => true, () => false))) { throw new Error('Add a condition does not move the focus into the condition'); }
    await page.keyboard.type('Email');
    await page.keyboard.press('Tab');
    await page.keyboard.type('*');
    await page.waitForTimeout(400);
    if (SHOTS) { await page.locator('.st-pravy').screenshot({ path: `${SHOTS}/form-inspector-condition.png` }); }
    await builderSaved(); // autosave of the draft
    rows = await selectForm();
    const saved = (await rows.first().locator('summary').textContent()).trim();
    if (!/^Your name · .* · with a condition$/.test(saved)) { throw new Error(`after a reload the first row says "${saved}"`); }
    // the saved build keeps its keys: remove the condition and the label again, then the row is as it was
    await rows.first().locator('summary').click();
    await rows.first().getByRole('button', { name: 'Remove the condition' }).click();
    await page.waitForTimeout(300);
    await page.locator('.st-panel details.st-polozka-radek').first().locator('input[type="text"]').first().fill(summary.split(' · ')[0]);
    await builderSaved();
  });

  await step('3.6 UXA-09: the draft look bar is one line; "Save and publish menu" publishes the menu, the colours keep waiting', async () => {
    await visit('/admin.php?module=appearance');
    await page.evaluate(() => { document.querySelectorAll('[name="ds[barvy][primarni]"]').forEach((i) => { i.value = '#225588'; }); });
    await Promise.all([page.waitForNavigation(), page.locator('.vzhled-ulozit input[type="submit"]').click()]);
    await visit('/admin.php?module=menu');
    await Promise.all([page.waitForNavigation(), page.locator('form[data-menu] button[name="publikovat"]').click()]);
    const bar = page.locator('#vzhled-koncept');
    await bar.waitFor();
    const areas = await bar.locator('summary').textContent();
    if (!/colours/.test(areas) || /menu/.test(areas)) { throw new Error(`the bar should list only the colours after publishing the menu, it says "${areas.trim()}"`); }
    const tall = (await bar.boundingBox()).height;
    await page.setViewportSize({ width: 390, height: 844 });
    await visit('/admin.php?module=menu'); // 3.32 (A2): the bar is on the Appearance screens and the dashboard only
    const phone = (await page.locator('#vzhled-koncept').boundingBox()).height;
    if (SHOTS) { await page.screenshot({ path: `${SHOTS}/look-bar-phone.png` }); }
    await page.setViewportSize({ width: 1440, height: 900 });
    if (tall > 70 || phone > 120) { throw new Error(`the draft look bar is ${tall} px tall on a desktop and ${phone} px on a phone`); }
    await page.locator('#vzhled-koncept summary').click();
    if (!(await page.locator('#vzhled-koncept details[open] button.nebezpecne').isVisible())) { throw new Error('the details of the draft look do not open'); }
    await Promise.all([page.waitForNavigation(), page.locator('#vzhled-koncept button.tl').click()]);
  });

  await step('3.6 UXA-14, UXA-11, UXA-12: typing on the canvas updates the inspector; a one-line top bar; header options only in the header', async () => {
    await visit('/admin.php?module=pages&action=builder&id=1');
    await builderReady();
    await canvas().locator('h1').first().dblclick();
    await page.keyboard.type('Zq');
    await page.waitForTimeout(200);
    const typed = await page.locator('.st-panel [data-pole="text"] input').inputValue();
    if (!typed.includes('Zq')) { throw new Error(`the inspector still shows "${typed}" while typing on the canvas`); }
    if (!(await page.locator('.st-lista .st-cip-koncept').count())) { throw new Error('the top bar does not show the draft while typing on the canvas'); }
    await page.keyboard.press('Enter');
    await page.waitForTimeout(1200);
    const top = (await page.locator('.st-lista').boundingBox()).height;
    if (top > 60) { throw new Error(`the builder top bar with a draft is ${top} px tall at 1440 px`); }
    await page.locator('.st-lista .st-lista-vice').click();
    if (!(await page.locator('#st-lista-vice button', { hasText: 'Versions' }).isVisible())) { throw new Error('Versions are not in the ⋯ menu'); }
    await page.keyboard.press('Escape');
    await canvas().locator('section').first().click({ position: { x: 5, y: 5 } });
    await page.waitForTimeout(400);
    if (/Header on scroll/.test(await page.locator('.st-pravy').textContent())) { throw new Error('a page section offers the header-only options'); }
    await visit('/admin.php?module=parts&action=builder&typ=hlavicka&jazyk=');
    await builderReady();
    await canvas().locator('[data-ka-id]').first().click({ position: { x: 3, y: 3 } });
    await page.waitForTimeout(400);
    if (!/Header on scroll/.test(await page.locator('.st-pravy').textContent())) { throw new Error('the header part does not offer the header options'); }
  });
};
