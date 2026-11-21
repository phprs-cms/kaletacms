// Kaleta browser steps 0090 (tools/test-browser.mjs runs the files of this folder in number order on one site – the order is
// the contract; tools/tests/README.md). Moved verbatim from the single file in 3.49 P1.
export default async (t) => {
  const { BASE, page, canvas, builderReady, builderSaved, step, visit } = t;

  // 3.18.2 N3182-2: a clipboard envelope used to be inserted without the server when it merely claimed this site, and the layer
  // tree parsed its HTML in the admin page; now every envelope goes through the server and code stays only in this site's signed copy
  await step('3.18.2 builder: a pasted envelope claiming this site runs nothing; the own copy (Ctrl+C, Ctrl+V) still pastes (N3182-2)', async () => {
    await visit('/admin.php?module=pages&action=builder&id=1');
    await builderReady();
    const key = (k) => page.evaluate((x) => document.body.dispatchEvent(new KeyboardEvent('keydown', { key: x, ctrlKey: true, bubbles: true, cancelable: true })), k);
    const paste = (text) => page.evaluate(async (t) => {
      const status = () => document.querySelector('.st-stav');
      status().textContent = '';
      const data = new DataTransfer();
      data.setData('text/plain', t);
      document.body.dispatchEvent(new ClipboardEvent('paste', { clipboardData: data, bubbles: true, cancelable: true }));
      for (let i = 0; i < 50 && !/Inserted|could not|None of/.test(status().textContent); i++) { await new Promise((r) => setTimeout(r, 100)); }
      return { text: status().textContent, error: status().classList.contains('chyba') };
    }, text);
    await page.evaluate(() => { window.__clipRan = 0; });
    const claim = JSON.stringify({ kaleta: 'elements', v: 1, site: new URL(BASE).origin, elements: [{ typ: 'text', obsah: { html: '<p>Clip 3182<img src="/media/missing-3182.png" alt="" onerror="window.__clipRan = 1"></p>' } }] });
    const claimed = await paste(claim);
    if (!/^Inserted/.test(claimed.text) || claimed.error) { throw new Error(`the envelope claiming this site was not inserted through the server: ${JSON.stringify(claimed)}`); }
    await canvas().locator('.ka-text', { hasText: 'Clip 3182' }).first().waitFor({ timeout: 8000 });
    const inserted = await canvas().locator('.ka-text', { hasText: 'Clip 3182' }).first().innerHTML();
    await page.waitForTimeout(500);
    if (await page.evaluate(() => window.__clipRan)) { throw new Error('the pasted envelope ran code in the admin'); }
    if (/onerror/i.test(inserted)) { throw new Error(`the server kept the handler: ${inserted}`); }
    await key('z');
    await page.waitForTimeout(800);
    // the administrator's own copy: Ctrl+C writes this site's signed envelope (captured here instead of the system clipboard)
    await canvas().locator('h1').first().click();
    await page.waitForTimeout(400);
    const copied = await page.evaluate(async () => {
      let captured = null;
      Object.defineProperty(navigator.clipboard, 'write', { configurable: true, value: async (items) => { captured = await (await items[0].getType('text/plain')).text(); } });
      Object.defineProperty(navigator.clipboard, 'writeText', { configurable: true, value: async (t) => { captured = t; } });
      document.body.dispatchEvent(new KeyboardEvent('keydown', { key: 'c', ctrlKey: true, bubbles: true, cancelable: true }));
      for (let i = 0; i < 50 && captured === null; i++) { await new Promise((r) => setTimeout(r, 100)); }
      return captured;
    });
    const envelope = JSON.parse(copied || '{}');
    if (!envelope.sig || envelope.site !== new URL(BASE).origin || envelope.elements?.[0]?.typ !== 'nadpis') { throw new Error(`Ctrl+C did not write a signed envelope of the heading: ${String(copied).slice(0, 200)}`); }
    const headings = await canvas().locator('h1').count();
    const own = await paste(copied);
    if (own.text !== 'Inserted' || own.error) { throw new Error(`the own copy did not paste: ${JSON.stringify(own)}`); }
    await builderSaved();
    if ((await canvas().locator('h1').count()) !== headings + 1) { throw new Error('the pasted heading is not on the canvas'); }
    await key('z');
    await page.waitForTimeout(800);
  });

  // 3.18.2 N3182-1 (CodeQL #42): the inspector shows what is typed on the canvas through the editor's cleanup, as nodes
  await step('3.18.2 builder inspector: text typed on the canvas is shown cleaned – the iframe bypass runs nothing (N3182-1)', async () => {
    await visit('/admin.php?module=pages&action=builder&id=1');
    await builderReady();
    const text = canvas().locator('.ka-text').first();
    await text.dblclick();
    const field = page.locator('.st-panel [data-pole="html"] .editor-plocha');
    await field.waitFor({ timeout: 5000 });
    await page.evaluate(() => { window.__inspRan = 0; });
    await text.evaluate((node) => {
      node.innerHTML = '<p>Typed 3182</p><iframe></ifr<p></p>ame><img src="/media/missing-3182.png" alt="" onerror="top.__inspRan = 1"></iframe>';
      node.dispatchEvent(new Event('input', { bubbles: true }));
    });
    await page.waitForTimeout(800);
    const shown = await field.innerHTML();
    if (await page.evaluate(() => window.__inspRan)) { throw new Error('the inspector ran the typed HTML'); }
    if (!/Typed 3182/.test(shown) || /<iframe|onerror/i.test(shown)) { throw new Error(`the inspector shows ${shown}`); }
    // ending the edit stores the value and draws the inspector's editor again from it – still nothing runs
    await text.evaluate((node) => node.blur());
    await page.waitForTimeout(1500);
    if (await page.evaluate(() => window.__inspRan)) { throw new Error('the redrawn inspector ran the typed HTML'); }
    await page.evaluate(() => document.body.dispatchEvent(new KeyboardEvent('keydown', { key: 'z', ctrlKey: true, bubbles: true, cancelable: true })));
    await page.waitForTimeout(800);
  });

  // 3.22 (U6, U7): the builder's Text has a numbered list and a quote (no subheadings – the Heading element has them), and its
  // link dialog is the same picker, with the anchors of the page from the builder
  await step('3.22 (U6, U7) builder Text: numbered list and quote, no H2; the link dialog suggests pages there too', async () => {
    await visit('/admin.php?module=pages&action=builder&id=1');
    await builderReady();
    await canvas().locator('.ka-text').first().dblclick();
    const tools = page.locator('.st-panel [data-pole="html"] .editor-nastroje');
    await tools.waitFor({ timeout: 5000 });
    const titles = await tools.locator('button').evaluateAll((all) => all.map((b) => b.textContent));
    if (!titles.includes('1. list') || !titles.includes('“quote”') || titles.includes('H2') || titles.includes('H3')) { throw new Error(`toolbar: ${titles.join(', ')}`); }
    if (!(await page.evaluate(() => Array.isArray(window.kaletaKotvy && window.kaletaKotvy())))) { throw new Error('the builder does not give the editor its anchors'); }
    const field = page.locator('.st-panel [data-pole="html"] .editor-plocha');
    await field.click();
    await page.keyboard.press('End');
    await page.keyboard.press('Shift+ArrowLeft');
    await page.keyboard.press('Control+k');
    const dialog = page.locator('dialog[open].odkaz-okno');
    await dialog.locator('[name=adresa]').fill('Conta');
    await dialog.locator('[role=option]', { hasText: 'Contact' }).first().waitFor({ timeout: 5000 });
    await page.keyboard.press('Escape');
    await page.keyboard.press('Escape');
    await page.waitForTimeout(300);
    if (await page.locator('dialog[open].odkaz-okno').count()) { throw new Error('the link dialog stayed open'); }
  });

  await step('3.6 UXA-10, UXA-13: a ready section below the first gets an H2, "Fix it" in the check, the image field and the media picker', async () => {
    await visit('/admin.php?module=pages&action=builder&id=1');
    await builderReady();
    await canvas().locator('section').first().click({ position: { x: 5, y: 5 } });
    await page.locator('.st-zalozky [role="tab"]').first().click();
    await page.locator('.st-knihovna button', { hasText: 'Hero with image' }).first().click();
    await builderSaved();
    const hero = canvas().locator('section').nth(1);
    if (await hero.locator('h1').count() || !(await hero.locator('h2').count())) { throw new Error('the ready-made section below the first one kept its H1'); }
    await hero.locator('h2').first().click();
    await page.locator('.st-uroven button', { hasText: /^H1$/ }).click();
    await page.waitForTimeout(800);
    await page.locator('.st-lista .st-tl-hlavni').click();
    const fix = page.locator('dialog[open] button', { hasText: 'Fix it' });
    await fix.waitFor();
    await fix.click();
    if (/more than one main heading/.test(await page.locator('dialog[open]').textContent())) { throw new Error('"Fix it" left the second H1'); }
    await page.locator('dialog[open] button', { hasText: 'Back to editing' }).click();
    await hero.getByText('Choose an image').click();
    await page.waitForTimeout(400);
    if (await page.locator('.st-panel [data-pole="priorita"] select').inputValue() !== '') { throw new Error('the image of a ready-made section does not load automatically'); }
    await page.locator('.st-obrazek-vybrat').click();
    const picker = page.locator('dialog.galerie-okno[open]');
    await picker.waitFor();
    await page.waitForTimeout(500);
    const png = Buffer.from((await page.evaluate(() => { const c = document.createElement('canvas'); c.width = 64; c.height = 48; const g = c.getContext('2d'); g.fillStyle = '#3366aa'; g.fillRect(0, 0, 64, 48); return c.toDataURL('image/png'); })).split(',')[1], 'base64');
    await picker.locator('input[type=file]').first().setInputFiles({ name: 'team-photo.png', mimeType: 'image/png', buffer: png });
    // 3.12 (UX310-24): one uploaded image is the choice – the picker closes and the field shows it, no second click
    await page.locator('.st-obrazek-pole img.st-obrazek-nahled:visible').waitFor();
    if (await picker.count()) { throw new Error('the picker stays open after uploading the one image to choose'); }
    await page.locator('.st-obrazek-vybrat').click();
    await picker.locator('.galerie-polozka', { hasText: 'team-photo.png' }).waitFor();
    if (/No images here yet/.test(await picker.locator('.galerie-mrizka').textContent())) { throw new Error('"No images here yet." stays next to the uploaded image'); }
    await picker.locator('.galerie-polozka').first().click();
    await page.locator('.st-obrazek-pole img.st-obrazek-nahled:visible').waitFor();
    await page.locator('.st-obrazek-odebrat').click();
    if (await page.locator('.st-obrazek-pole img.st-obrazek-nahled:visible').count()) { throw new Error('Remove left the image in the field'); }
  });

  await step('builder: site header', async () => {
    await visit('/admin.php?module=parts&action=builder&type=hlavicka&language=');
    await builderReady();
    await canvas().locator('nav, header').first().click().catch(() => {});
  });
};
