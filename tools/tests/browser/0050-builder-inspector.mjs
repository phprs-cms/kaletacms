// Kaleta browser steps 0050 (tools/test-browser.mjs runs the files of this folder in number order on one site – the order is
// the contract; tools/tests/README.md). Moved verbatim from the single file in 3.49 P1.
export default async (t) => {
  const { BASE, SHOTS, browser, page, watch, canvas, builderReady, builderSaved, step, visit } = t;

  await step('builder: select, style, phone, edit text', async () => {
    await visit('/admin.php?module=pages&action=builder&id=1');
    await builderReady();
    await canvas().locator('h1').first().click();
    await page.waitForTimeout(500);
    await page.getByRole('tab', { name: 'Style' }).first().click().catch(() => page.getByText('Style', { exact: true }).first().click());
    await page.waitForTimeout(400);
    await page.getByRole('button', { name: 'Phone' }).first().click(); // 3.11: "Phone", as in Site appearance
    await page.waitForTimeout(1000);
    await page.getByRole('button', { name: 'Desktop' }).first().click().catch(() => {});
    await page.waitForTimeout(600);
    await canvas().locator('h1').first().click();
    await page.getByRole('tab', { name: 'Content' }).first().click().catch(() => page.getByText('Content', { exact: true }).first().click());
    const field = page.locator('.st-panel textarea, .st-panel input[type="text"]').first();
    if (await field.count()) { await field.fill('Browser test heading'); await builderSaved(); } // autosave of the draft
  });

  // 3.21 (U3, U4): a phone override is visible from Desktop (a dot on the Phone button, a device icon with the value next to the field,
  // „×“ resets it); one Visibility row per device in Advanced – a hidden element is a dashed strip on the phone canvas (click „show“
  // to bring it back) and carries the device icon in Structure; the public page gets no strip
  await step('3.21 (U3, U4) builder: phone override seen from Desktop, reset, hide on phone – strip, tree mark, show', async () => {
    const device = (name) => page.locator('.st-lista .st-skupina button[aria-label="' + name + '"]');
    const pick = async (name) => { await device(name).click(); await page.waitForFunction((n) => document.querySelector('.st-lista .st-skupina button[aria-label="' + n + '"]').getAttribute('aria-pressed') === 'true', name); };
    await canvas().locator('h1').first().click();
    await page.getByRole('tab', { name: 'Style' }).first().click();
    await pick('Phone');
    await page.evaluate(() => document.querySelectorAll('.st-styl details').forEach((d) => { d.open = true; }));
    const colour = page.locator('#st-v-barva');
    await colour.selectOption('primarni'); // 3.26 (N-U2): a select of the site's colours
    await page.waitForFunction(() => document.querySelector('#st-v-barva').closest('.st-vlastnost').classList.contains('nastaveno'));
    if (!(await page.locator('#st-v-barva').locator('xpath=ancestor::*[contains(@class,"st-vlastnost")]').locator('.st-vynulovat').count())) { throw new Error('a field with an own value on Phone has no × to reset it'); }
    await pick('Desktop');
    await device('Phone').locator('.st-tecka').waitFor({ timeout: 3000 });
    if (await device('Desktop').locator('.st-tecka').count()) { throw new Error('the current device button (Desktop) shows a dot'); }
    if (!/own style values of the selected element here: 1/.test(await device('Phone').getAttribute('title') || '')) { throw new Error('the Phone button does not say how many own values the element has there'); }
    await page.evaluate(() => document.querySelectorAll('.st-styl details').forEach((d) => { d.open = true; }));
    const mark = page.locator('#st-v-barva').locator('xpath=ancestor::*[contains(@class,"st-vlastnost")]').locator('.st-znacka-bp');
    if ((await mark.getAttribute('title')) !== 'Set on Phone: primarni') { throw new Error('the colour field does not show the phone override: ' + await mark.getAttribute('title').catch(() => 'none')); }
    if ((await page.locator('#st-v-barva').inputValue()) !== '') { throw new Error('the Desktop field shows the phone value as its own'); }
    await mark.click(); // the device icon jumps to that breakpoint
    await page.waitForFunction(() => document.querySelector('.st-lista .st-skupina button[aria-label="Phone"]').getAttribute('aria-pressed') === 'true');
    await page.evaluate(() => document.querySelectorAll('.st-styl details').forEach((d) => { d.open = true; }));
    if ((await page.locator('#st-v-barva').inputValue()) !== 'primarni') { throw new Error('the Phone field lost its value'); }
    await page.locator('#st-v-barva').locator('xpath=ancestor::*[contains(@class,"st-vlastnost")]').locator('.st-vynulovat').click();
    await page.waitForFunction(() => document.querySelector('#st-v-barva') && document.querySelector('#st-v-barva').value === '' && !document.querySelector('#st-v-barva').closest('.st-vlastnost').querySelector('.st-vynulovat'));
    await pick('Desktop');
    if (await device('Phone').locator('.st-tecka').count()) { throw new Error('the Phone dot stays after the reset'); }
    // U4: hide on Phone from the Visibility row (Advanced), while editing on Desktop
    await page.getByRole('tab', { name: 'Advanced' }).first().click();
    const phoneToggle = page.locator('.st-viditelnost button[data-bp="mobil"]');
    if ((await phoneToggle.getAttribute('aria-pressed')) !== 'true') { throw new Error('the Visibility row shows the heading hidden on Phone before anything was hidden'); }
    if (await page.locator('.st-panel .st-zaskrt', { hasText: 'Hide on' }).count()) { throw new Error('the old Hide on mobile checkboxes are still there'); }
    await phoneToggle.click();
    await page.waitForFunction(() => document.querySelector('.st-viditelnost button[data-bp="mobil"]').getAttribute('aria-pressed') === 'false');
    if ((await page.locator('.st-viditelnost button[data-bp="zaklad"]').getAttribute('aria-pressed')) !== 'true') { throw new Error('hiding on Phone hid the Desktop too'); }
    if (await canvas().locator('.ka-st-skryty-prvek').count()) { throw new Error('a strip on the Desktop canvas for an element hidden on Phone only'); }
    await pick('Phone');
    const strip = canvas().locator('.ka-st-skryty-prvek').first();
    await strip.waitFor({ timeout: 5000 });
    const stripText = (await strip.textContent()) || '';
    if (!stripText.startsWith('Hidden on phone')) { throw new Error('the strip says: ' + stripText); }
    const stripBox = await strip.boundingBox();
    if (!stripBox || Math.round(stripBox.height) !== 24) { throw new Error('the strip is not 24 px high: ' + JSON.stringify(stripBox)); }
    await page.waitForFunction(() => { const f = document.querySelector('.st-platno iframe:not(.st-nacita)'); const h = f && f.contentDocument && f.contentDocument.querySelector('h1'); return h && getComputedStyle(h).display === 'none'; }, null, { timeout: 8000 }); // the refreshed canvas hides the heading itself
    // the Structure tree marks the hidden element with the device icon
    await page.getByRole('tab', { name: 'Structure' }).first().click();
    const marked = page.locator('.st-strom .st-uzel-zarizeni[title="Hidden on: Phone"]');
    await marked.first().waitFor({ timeout: 3000 });
    if (SHOTS) { await page.screenshot({ path: `${SHOTS}/builder-hidden-on-phone.png`, fullPage: false }); }
    // the public page (no editor) is the published version: never a strip, never an editor id
    const visitor = await browser.newContext();
    const html = await (await visitor.request.get(`${BASE}/`)).text();
    await visitor.close();
    if (/ka-st-skryty-prvek|data-ka-id/.test(html)) { throw new Error('the public page carries an editor strip or editor ids'); }
    // N321-1 (review): typing the hidden heading's text in Content goes to the heading, never into its strip (same data-ka-id)
    await strip.click();
    await page.getByRole('tab', { name: 'Content' }).first().click();
    const headingText = page.locator('.st-panel [data-pole="text"] input').first();
    await headingText.click();
    await headingText.press('End');
    await page.keyboard.type('Z');
    await page.waitForTimeout(300);
    if (!((await strip.textContent()) || '').startsWith('Hidden on phone')) { throw new Error('typing the hidden heading\'s text overwrote its strip: ' + await strip.textContent()); }
    await page.keyboard.press('Backspace');
    await builderSaved();
    // „show“ on the strip brings the element back on the phone and keeps the other devices as they were
    await strip.locator('button').click();
    await canvas().locator('.ka-st-skryty-prvek').first().waitFor({ state: 'detached', timeout: 5000 });
    await page.waitForFunction(() => !document.querySelector('.st-strom .st-uzel-zarizeni'));
    await canvas().locator('h1').first().click();
    await page.getByRole('tab', { name: 'Advanced' }).first().click();
    if ((await page.locator('.st-viditelnost button[data-bp="mobil"]').getAttribute('aria-pressed')) !== 'true') { throw new Error('after „show“ the Visibility row still says hidden on Phone'); }
    await pick('Desktop');
    await builderSaved(); // autosave of the draft before the next step
  });

  await step('builder: select the parent element', async () => {
    // broken from 1.4.0 to 3.2.0: find() returned the parent under another key than its callers read (3.2.1)
    await canvas().locator('h1').first().click();
    await page.waitForTimeout(400);
    const parent = page.locator('button[title^="Select parent element"]').first();
    if (!(await parent.count())) { throw new Error('a nested element offers no "Select parent element" button'); }
    await parent.click();
    await page.waitForTimeout(300);
  });

  // 3.21 "Builder for non-designers" (Head-of-UX audit U2, U5, U9, U19): the Style tab shows only the groups the kind of element
  // needs, the rest behind "Show all properties"; font size is a select of named steps with a custom value checked in the field;
  // the count badge is a pill with a space before it; a heading's tag is set only as its Level in Content
  await step('3.21 (U2, U5, U9, U19) builder: a heading shows Typography and Spacing but Layout only after "Show all properties"; font size is a select of steps, a custom "xl" is refused in the field; the count badge is a pill after a space ("Typography 2"); no HTML tag select on a heading', async () => {
    const groups = async () => page.locator('.st-panel .st-styl details').evaluateAll((els) => els.map((d) => {
      const summary = d.querySelector('summary');
      return { name: summary.firstChild.textContent.trim(), open: d.open, text: summary.textContent.trim() };
    }));
    const until = async (fn, what, ms = 8000) => {
      const end = Date.now() + ms;
      let last;
      while (Date.now() < end) { last = await fn(); if (last) { return last; } await page.waitForTimeout(200); }
      throw new Error(`${what} (last: ${JSON.stringify(last)})`);
    };
    const h1Size = () => canvas().locator('h1').first().evaluate((e) => getComputedStyle(e).fontSize).catch(() => '');
    // the section selected by the previous step: Layout is relevant, the toggle is offered, the active state is "Pressed"
    await page.getByRole('tab', { name: 'Style' }).first().click();
    await page.locator('.st-panel .st-styl').waitFor();
    let names = (await groups()).map((g) => g.name);
    if (!names.includes('Layout')) { throw new Error(`a section's style groups have no Layout: ${names.join(', ')}`); }
    if (!(await page.getByLabel('Show all properties').count())) { throw new Error('no "Show all properties" toggle under the style groups'); }
    if ((await page.getByRole('button', { name: 'Pressed', exact: true }).count()) !== 1) { throw new Error('the active state is not named "Pressed"'); }
    // the heading: Typography and Spacing open, no Layout (grid columns) until "Show all properties"
    await canvas().locator('h1').first().click();
    const size = page.locator('#st-v-velikost_pisma');
    await size.waitFor();
    const heading = await groups();
    names = heading.map((g) => g.name);
    if (!names.includes('Typography') || !names.includes('Spacing') || names.includes('Layout')) { throw new Error(`a heading's style groups: ${names.join(', ')}`); }
    if (await page.locator('#st-v-sloupce').count()) { throw new Error('Grid columns are offered on a heading'); }
    for (const name of ['Typography', 'Spacing']) { if (!heading.find((g) => g.name === name).open) { throw new Error(`${name} is not open on a heading`); } }
    const showAll = page.getByLabel('Show all properties');
    await showAll.check();
    await page.locator('#st-v-sloupce').waitFor({ state: 'attached' }); // in the Layout group, which opens only when clicked
    if (!(await groups()).map((g) => g.name).includes('Layout')) { throw new Error('"Show all properties" does not bring Layout'); }
    await showAll.uncheck();
    await page.locator('#st-v-sloupce').waitFor({ state: 'detached' });
    // font size: a select of the steps; a step renders on the canvas as the token
    if ((await size.evaluate((e) => e.tagName)) !== 'SELECT') { throw new Error('font size is not a select'); }
    const options = await size.locator('option').allTextContents();
    if (!options.some((o) => /^2 · Large$/.test(o)) || !options.includes('Custom…')) { throw new Error(`font size options: ${options.join(' | ')}`); }
    const originalSize = await h1Size();
    const originalStep = await size.inputValue(); // the starter's h1 has a step of its own; the step puts it back at the end
    await size.selectOption(originalStep === '1' ? '2' : '1');
    const stepSize = await until(async () => { const h = await h1Size(); return h && h !== originalSize ? h : null; }, 'the canvas heading did not take step 1');
    // U19: the badge reads "Typography 2" (a space, a pill), not "Typography2"
    await canvas().locator('h1').first().click();
    await size.waitFor();
    const typography = (await groups()).find((g) => g.name === 'Typography');
    if (!typography || !/^Typography \d+$/.test(typography.text)) { throw new Error(`the group header reads "${typography && typography.text}"`); }
    const radius = await page.locator('.st-styl summary small').first().evaluate((e) => getComputedStyle(e).borderRadius);
    if (radius === '0px') { throw new Error('the count badge is not a pill'); }
    // Custom… + "xl": refused right at the field, nothing saved (no warning from the server, the canvas keeps step 1)
    await size.selectOption('vlastni');
    const custom = page.locator('.st-krok-vlastni');
    await custom.waitFor();
    await custom.fill('xl');
    await custom.press('Tab');
    const row = page.locator('.st-vlastnost:has(#st-v-velikost_pisma)');
    await row.locator('.st-chyba-pole:visible').waitFor();
    if (!/Not a valid size/.test((await row.locator('.st-chyba-pole').textContent()) || '')) { throw new Error('no inline message for an invalid size'); }
    if (!/st-pole-chyba/.test((await row.getAttribute('class')) || '')) { throw new Error('the invalid size is not marked on the field'); }
    await page.waitForTimeout(2500);
    if (await page.locator('.st-panel .st-chyby').count()) { throw new Error('the invalid size reached the server'); }
    if (/warning/i.test((await page.locator('.st-stav').first().textContent()) || '')) { throw new Error('the top bar warns – the invalid size was saved'); }
    if ((await h1Size()) !== stepSize) { throw new Error('the canvas changed after an invalid size'); }
    // a valid custom length is saved and rendered; then the field is cleared so the later steps see the heading as before
    await custom.fill('40px');
    await custom.press('Tab');
    await until(async () => (await h1Size()) === '40px', 'the canvas heading did not take the custom 40px');
    // N321-5 (review): "×" (3.21 U3) on a custom size clears the value and the custom field with it – the row is drawn anew
    await row.locator('.st-vynulovat').click();
    await page.waitForFunction(() => { const s = document.querySelector('#st-v-velikost_pisma'); return s && s.value === '' && !s.closest('.st-vlastnost').querySelector('.st-krok-vlastni, .st-vynulovat'); });
    await size.selectOption(originalStep);
    await until(async () => (await h1Size()) === originalSize, 'the canvas heading did not return to its size');
    // U9: a heading's tag is its Level in Content – Advanced has no HTML tag select, only a note
    await page.getByRole('tab', { name: 'Advanced' }).first().click();
    await page.locator('.st-panel h3', { hasText: 'Display conditions' }).waitFor();
    if (await page.locator('.st-panel label.st-pole', { hasText: 'HTML tag' }).count()) { throw new Error('the Advanced tab of a heading offers an HTML tag select next to Level'); }
    if (!(await page.locator('.st-panel', { hasText: 'Level in the Content tab' }).count())) { throw new Error('the Advanced tab of a heading does not say where the tag is set'); }
    await page.getByRole('tab', { name: 'Content' }).first().click();
    if (!(await page.locator('.st-uroven button', { hasText: 'H1' }).count())) { throw new Error('Content has no Level buttons for the heading'); }
    if (SHOTS) { await page.screenshot({ path: `${SHOTS}/builder-321-heading-style.png`, fullPage: false }); }
  });

  // 3.26 (N-U2): spacing and colour are token choices like the font size – a select of the design system's steps or the site's
  // colours (with swatches) and "Custom…" checked in the field; "primarni" typed into Margin bottom is refused there and never
  // reaches the server; a step keeps its stored form (the token, not a length)
  await step('3.26 (N-U2) builder: Margin bottom is a select of spacing steps, "primarni" as a custom value is refused in the field and not saved; colour is a select with swatches and Custom…', async () => {
    const until = async (fn, what, ms = 8000) => {
      const end = Date.now() + ms;
      let last;
      while (Date.now() < end) { last = await fn(); if (last) { return last; } await page.waitForTimeout(200); }
      throw new Error(`${what} (last: ${JSON.stringify(last)})`);
    };
    const openGroups = () => page.evaluate(() => document.querySelectorAll('.st-styl details').forEach((d) => { d.open = true; }));
    const h1 = () => canvas().locator('h1').first();
    const h1Margin = () => h1().evaluate((e) => getComputedStyle(e).marginBlockEnd).catch(() => '');
    const h1Colour = () => h1().evaluate((e) => getComputedStyle(e).color).catch(() => '');
    await h1().click();
    await page.getByRole('tab', { name: 'Style' }).first().click();
    await openGroups();
    const margin = page.locator('#st-v-okraj_dole');
    await margin.waitFor();
    if ((await margin.evaluate((e) => e.tagName)) !== 'SELECT') { throw new Error('Margin bottom is not a select'); }
    const options = await margin.locator('option').allTextContents();
    for (const o of ['0 · none', 'm · medium', '3xl · largest', 'Custom…']) { if (!options.includes(o)) { throw new Error(`Margin bottom options: ${options.join(' | ')}`); } }
    const row = page.locator('.st-vlastnost:has(#st-v-okraj_dole)');
    const before = await h1Margin();
    // Custom… + "primarni": refused right at the field – no request with it, no warning, the canvas unchanged
    const saves = [];
    const watch = (r) => { if (r.method() === 'POST' && /primarni/.test(r.postData() || '') && /okraj_dole/.test(r.postData() || '')) { saves.push(r.url()); } };
    page.on('request', watch);
    await margin.selectOption('vlastni');
    const custom = row.locator('.st-krok-vlastni');
    await custom.waitFor();
    await custom.fill('primarni');
    await custom.press('Tab');
    await row.locator('.st-chyba-pole:visible').waitFor();
    if (!/Not a valid spacing/.test((await row.locator('.st-chyba-pole').textContent()) || '')) { throw new Error('no inline message for an invalid spacing'); }
    if (!/st-pole-chyba/.test((await row.getAttribute('class')) || '')) { throw new Error('the invalid spacing is not marked on the field'); }
    await page.waitForTimeout(2500);
    page.off('request', watch);
    if (saves.length) { throw new Error('"primarni" in Margin bottom reached the server: ' + saves.join(', ')); }
    if (await page.locator('.st-panel .st-chyby').count()) { throw new Error('the server reported an invalid value'); }
    if (/warning/i.test((await page.locator('.st-stav').first().textContent()) || '')) { throw new Error('the top bar warns – the invalid spacing was saved'); }
    if ((await h1Margin()) !== before) { throw new Error('the canvas changed after an invalid spacing'); }
    // a step: saved, rendered, and stored as the token (the select shows it again after the element is selected anew)
    await margin.selectOption('3xl');
    await until(async () => { const m = await h1Margin(); return m && m !== before ? m : null; }, 'the canvas heading did not take the 3xl spacing');
    await builderSaved(); // the autosave answers with the cleaned build; the panel is drawn from it anew
    await page.getByRole('tab', { name: 'Content' }).first().click();
    await page.getByRole('tab', { name: 'Style' }).first().click();
    await openGroups();
    if ((await page.locator('#st-v-okraj_dole').inputValue()) !== '3xl') { throw new Error('the stored spacing lost its form (not the token 3xl)'); }
    if (await page.locator('.st-vlastnost:has(#st-v-okraj_dole) .st-krok-vlastni').count()) { throw new Error('a token spacing opened the custom field'); }
    await page.locator('.st-vlastnost:has(#st-v-okraj_dole) .st-vynulovat').click();
    await page.waitForFunction(() => { const s = document.querySelector('#st-v-okraj_dole'); return s && s.value === '' && !s.closest('.st-vlastnost').querySelector('.st-krok-vlastni, .st-vynulovat'); });
    await until(async () => (await h1Margin()) === before, 'the heading did not return to its margin');
    // colour: a select of the site's colours, swatches under it, Custom… checked like the server checks it
    await openGroups();
    const colour = page.locator('#st-v-barva');
    if ((await colour.evaluate((e) => e.tagName)) !== 'SELECT') { throw new Error('Text colour is not a select'); }
    const colourOptions = await colour.locator('option').allTextContents();
    if (!colourOptions.includes('Primary') || !colourOptions.includes('Custom…')) { throw new Error(`Text colour options: ${colourOptions.join(' | ')}`); }
    const colourRow = page.locator('.st-vlastnost:has(#st-v-barva)');
    if ((await colourRow.locator('.st-vzorky button').count()) < 5) { throw new Error('no swatches of the site\'s colours'); }
    const ink = await h1Colour();
    await colourRow.locator('.st-vzorky button[data-token="primarni"]').click();
    const primary = await until(async () => { const c = await h1Colour(); return c && c !== ink ? c : null; }, 'the canvas heading did not take the primary colour');
    if ((await colour.inputValue()) !== 'primarni' || (await colourRow.locator('.st-vzorky button[data-token="primarni"]').getAttribute('aria-pressed')) !== 'true') { throw new Error('the swatch did not pick the token'); }
    await colour.selectOption('vlastni');
    const customColour = colourRow.locator('.st-krok-vlastni');
    await customColour.fill('blue-ish');
    await customColour.press('Tab');
    await colourRow.locator('.st-chyba-pole:visible').waitFor();
    if (!/Not a valid colour/.test((await colourRow.locator('.st-chyba-pole').textContent()) || '')) { throw new Error('no inline message for an invalid colour'); }
    await page.waitForTimeout(2000);
    if ((await h1Colour()) !== primary) { throw new Error('the canvas changed after an invalid colour'); }
    await customColour.fill('#123456');
    await customColour.press('Tab');
    await until(async () => (await h1Colour()) === 'rgb(18, 52, 86)', 'the canvas heading did not take the custom #123456');
    if (SHOTS) { await page.screenshot({ path: `${SHOTS}/builder-326-token-fields.png`, fullPage: false }); }
    await page.locator('.st-vlastnost:has(#st-v-barva) .st-vynulovat').click();
    await page.waitForFunction(() => { const s = document.querySelector('#st-v-barva'); return s && s.value === '' && !s.closest('.st-vlastnost').querySelector('.st-krok-vlastni, .st-vynulovat'); });
    await until(async () => (await h1Colour()) === ink, 'the heading did not return to its colour');
  });

  await step('3.19.2 builder: Save as component, Save to my sections and a shared class reach the server', async () => {
    // broken from 1.4.0 to 3.19.1: the script sent the name as "name", the server read "nazev" – every save answered
    // "Name or element is missing." (the same for the shared-class endpoint and the writing assistant's prompt)
    const viaMore = async (label, name, expected) => {
      await page.getByRole('button', { name: 'More actions' }).first().click();
      await page.locator('#st-vice button', { hasText: label }).first().click();
      const dialog = page.locator('dialog.st-dialog[open]').last(); // the name dialog opens over the Versions dialog of an earlier step
      await dialog.waitFor();
      await dialog.locator('input[type="text"]').fill(name);
      await dialog.locator('button[type="submit"]').click();
      let status = '';
      for (let i = 0; i < 25; i++) { // the status line reports the result of the request (the autosave writes there too)
        status = (await page.locator('.st-stav').first().textContent()) || '';
        if (/missing|failed/i.test(status)) { throw new Error(`"${label}": ${status.trim()}`); }
        if (expected.test(status)) { return; }
        await page.waitForTimeout(200);
      }
      throw new Error(`"${label}": no confirmation in the status line, last: "${status.trim()}"`);
    };
    await canvas().locator('h1').first().click();
    await page.waitForTimeout(400);
    await viaMore('Save to my sections', 'Browser section', /My sections/);
    await page.locator('.st-zalozky [role="tab"]').first().click(); // the Add panel lists it under My sections
    await page.waitForTimeout(300);
    if (!(await page.locator('.st-moje-sekce strong', { hasText: 'Browser section' }).count())) { throw new Error('the saved section is not in the Add panel'); }
    // a visible paragraph (the first <p> of the page sits in the folded phone menu), so the h1 the later steps select stays a plain element
    await canvas().locator('p:visible').first().click();
    await page.waitForTimeout(400);
    await viaMore('Save as component', 'Browser component', /Component saved/);
    // the shared-class endpoint takes the same key; the panel's class editor posts it on every change
    const j = await page.evaluate(async () => {
      const D = JSON.parse(document.getElementById('stavitel-data').textContent);
      const f = new FormData();
      f.append('_csrf', document.querySelector('input[name="_csrf"]').value);
      f.append('name', 'browser-card'); f.append('styl', '{}'); f.append('css', '');
      return (await fetch(D.adresy.trida, { method: 'POST', body: f, credentials: 'same-origin' })).json();
    });
    if (!j.ok) { throw new Error(`saving a shared class failed: ${j.chyba || j.status}`); }
    await builderSaved(); // the draft autosaves before the next step reloads the builder
  });
};
