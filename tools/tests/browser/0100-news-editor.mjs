// Kaleta browser steps 0100 (tools/test-browser.mjs runs the files of this folder in number order on one site – the order is
// the contract; tools/tests/README.md). Moved verbatim from the single file in 3.49 P1.
export default async (t) => {
  const { sql, page, step, visit } = t;

  await step('news editor: type and format', async () => {
    await visit('/admin.php?module=news&action=new');
    await page.fill('input[name="titulek"]', 'Browser test');
    const editor = page.locator('[contenteditable="true"]').first();
    if (await editor.count()) {
      await editor.click();
      await page.keyboard.type('Some text for the browser test.');
      await page.keyboard.press('Control+A');
      const bold = page.locator('button[data-prikaz="bold"], button[title*="Bold"]').first();
      if (await bold.count()) { await bold.click(); }
    }
  });

  await step('3.18.2 news editor: pasted HTML is cleaned without running anything (CodeQL #32)', async () => {
    // before 3.18.2 the paste cleanup parsed the HTML in the admin page itself: an <img onerror> ran before it was removed
    t.state.inertHandlers = true;
    try { await pasteChecks(); } finally { await page.waitForTimeout(300); t.state.inertHandlers = false; }
  });
  async function pasteChecks() {
    const editor = page.locator('[contenteditable="true"]').first();
    await editor.click();
    const result = await editor.evaluate(async (surface) => {
      window.__pasteRan = 0;
      const data = new DataTransfer();
      data.setData('text/html', '<p>Pasted <mark>word</mark></p><img src="/media/missing-3182.png" onerror="window.__pasteRan = 1"><a href="java&#9;script:window.__pasteRan=2">link</a>');
      surface.dispatchEvent(new ClipboardEvent('paste', { clipboardData: data, bubbles: true, cancelable: true }));
      await new Promise((r) => setTimeout(r, 600));
      return { ran: window.__pasteRan, html: surface.innerHTML };
    });
    if (result.ran) { throw new Error(`the pasted HTML ran code (${result.ran})`); }
    if (/onerror|script:/i.test(result.html)) { throw new Error(`the pasted HTML kept a handler or script link: ${result.html}`); }
    if (!/<mark>word<\/mark>/.test(result.html)) { throw new Error(`the highlighted word was lost: ${result.html}`); }
    // N3182-1: the frame's raw text held "</ifr<p></p>ame>"; the regular expression removing empty paragraphs joined it into a
    // real </iframe>, and the <img onerror> after it ran when the cleaned string was parsed again (also with <p><br></p>)
    const bypass = await editor.evaluate(async (surface) => {
      const payloads = [
        '<iframe></ifr<p></p>ame><img src="/media/missing-3182.png" alt="" onerror="window.__pasteRan = 3"></iframe>',
        '<iframe></ifr<p><br></p>ame><img src="/media/missing-3182.png" alt="" onerror="window.__pasteRan = 4"></iframe>',
        '<p>a</p><noscript><p title="</noscript><img src=/media/missing-3182.png alt=&quot;&quot; onerror=window.__pasteRan=5>"></p></noscript>',
        '<xmp></xmp><img src=/media/missing-3182.png alt="" onerror=window.__pasteRan=6></xmp><p>b&nbsp;c</p><p>&nbsp;</p>',
        '<iframe src="https://frames.example/x"></iframe><a href="https://e.example/1" target="_top" rel="opener">one</a> <a href="https://e.example/2" target="_blank" rel="opener nofollow">two</a>',
      ];
      for (const html of payloads) {
        const data = new DataTransfer();
        data.setData('text/html', html);
        surface.dispatchEvent(new ClipboardEvent('paste', { clipboardData: data, bubbles: true, cancelable: true }));
      }
      await new Promise((r) => setTimeout(r, 800));
      // the cleanup gives markup that comes out the same when cleaned again (it is parsed again by insertHTML and innerHTML)
      const clean = window.kaletaCistiHtml;
      const samples = [...payloads, '<div><p>x</p></div>', '<table><p>t</p><tr><td>c</td></tr></table>', '<a href="/a"><p><a href="/b">b</a></p></a>', '<svg><style><img src=x onerror=1></style></svg>'];
      const unstable = samples.filter((h) => clean(clean(h, true), true) !== clean(h, true));
      return { ran: window.__pasteRan, html: surface.innerHTML, unstable,
        youtube: clean('<iframe src="https://www.youtube.com/embed/abc">x</iframe>', true), other: clean('<iframe src="https://frames.example/x"></iframe>', true),
        kept: clean('<iframe src="https://frames.example/x">x</iframe>'), spaces: clean('<p>b&nbsp;c</p><p>&nbsp;</p><p><br></p>', true) };
    });
    if (bypass.ran) { throw new Error(`a pasted payload ran code (${bypass.ran})`); }
    if (/onerror|<iframe|frames\.example|rel="(?:[^"]*\s)?opener[\s"]|target="_top"/i.test(bypass.html)) { throw new Error(`the pasted HTML kept a handler, a frame, rel=opener or a target: ${bypass.html}`); }
    if (!/<a href="https:\/\/e\.example\/2" target="_blank" rel="noopener nofollow">two<\/a>/.test(bypass.html) || !/b c/.test(bypass.html)) { throw new Error(`a link or the text was lost: ${bypass.html}`); }
    if (bypass.unstable.length) { throw new Error(`the cleanup is not stable for: ${bypass.unstable.join(' | ')}`); }
    if (bypass.spaces !== '<p>b c</p>') { throw new Error(`empty paragraphs and non-breaking spaces: ${bypass.spaces}`); }
    if (bypass.youtube !== '<iframe src="https://www.youtube-nocookie.com/embed/abc"></iframe>' || bypass.other !== '' || bypass.kept !== '<iframe src="https://frames.example/x"></iframe>') {
      throw new Error(`frames: ${JSON.stringify([bypass.youtube, bypass.other, bypass.kept])}`);
    }
  }

  await step('3.18.2 news editor: the link dialog refuses java<TAB>script:, vbscript: and data: (N3182-6)', async () => {
    const editor = page.locator('[contenteditable="true"]').first();
    for (const address of ['java\tscript:window.__linkRan=1', 'vbscript:x', 'data:text/html,x']) {
      await editor.click();
      await page.keyboard.press('End');
      await page.keyboard.type(' linkme');
      await page.keyboard.press('Shift+ArrowLeft');
      await page.keyboard.press('Control+k');
      const dialog = page.locator('dialog[open]');
      await dialog.locator('[name=adresa]').fill(address);
      await dialog.locator('button[value="ok"]').click();
      await page.waitForTimeout(200);
      if (await page.locator('dialog[open]').count()) { await page.keyboard.press('Escape'); }
    }
    const html = await editor.innerHTML();
    if (/script:|vbscript|data:text/i.test(html)) { throw new Error(`the link dialog inserted an unsafe address: ${html}`); }
  });

  // 3.22 (U6): one field for an address or a search – the pages come as you type, arrows and Enter pick one, a second Enter
  // inserts the link; an e-mail and a phone number are offered as mailto: and tel:; the first Esc closes only the list
  await step('3.22 (U6) news editor: the link dialog finds a page as you type and by keyboard; mailto: and tel: for an e-mail and a phone', async () => {
    const editor = page.locator('[contenteditable="true"]').first();
    await editor.click();
    await page.keyboard.press('End');
    await page.keyboard.type(' contactus');
    for (let i = 0; i < 9; i++) { await page.keyboard.press('Shift+ArrowLeft'); }
    await page.keyboard.press('Control+k');
    const dialog = page.locator('dialog[open]');
    const field = dialog.locator('[name=adresa]');
    await dialog.locator('[role=option]').first().waitFor({ timeout: 5000 }); // an empty field offers the pages at once
    await field.fill('Conta');
    // the list of the empty field has Contact too – wait for the answer to "Conta", where Contact is the first suggestion
    await page.waitForFunction(() => { const all = [...document.querySelectorAll('dialog[open] [role=option]')]; return all.length > 0 && /Contact/.test(all[0].textContent) && all.every((o) => /conta/i.test(o.textContent)); }, null, { timeout: 5000 });
    await page.waitForTimeout(300);
    await page.keyboard.press('ArrowDown');
    if (!(await field.getAttribute('aria-activedescendant')) || (await field.getAttribute('aria-expanded')) !== 'true') { throw new Error('ArrowDown did not mark a suggestion'); }
    await page.keyboard.press('Enter');
    const chosen = await field.inputValue();
    if (!/\/contact\/?$/.test(chosen)) { throw new Error(`Enter picked ${chosen}`); }
    if (!(await page.locator('dialog[open]').count())) { throw new Error('Enter on a suggestion closed the dialog'); }
    await page.keyboard.press('Enter');
    await page.waitForTimeout(200);
    const html = await editor.innerHTML();
    if (!/<a href="[^"]*\/contact\/?">contactus<\/a>/.test(html)) { throw new Error(`the link was not inserted: ${html.slice(-300)}`); }
    await editor.click();
    await page.keyboard.press('End');
    await page.keyboard.type(' mail');
    for (let i = 0; i < 4; i++) { await page.keyboard.press('Shift+ArrowLeft'); }
    await page.keyboard.press('Control+k');
    await field.fill('info@example.com');
    await dialog.locator('[role=option][data-url="mailto:info@example.com"]').waitFor({ timeout: 3000 });
    await field.fill('+420 777 123 456');
    await dialog.locator('[role=option][data-url="tel:+420777123456"]').waitFor({ timeout: 3000 });
    await page.keyboard.press('ArrowDown');
    await page.keyboard.press('Escape');
    if (!(await page.locator('dialog[open]').count()) || !(await dialog.locator('.odkaz-vysledky').isHidden())) { throw new Error('the first Esc did not close only the list'); }
    if ((await field.inputValue()) !== '+420 777 123 456') { throw new Error('Esc changed the field'); }
    await page.keyboard.press('Escape');
    await page.waitForTimeout(200);
    if (await page.locator('dialog[open]').count()) { throw new Error('the second Esc did not close the dialog'); }
  });

  // 3.22 (U7): Word's list paragraphs (MsoListParagraph, mso-list levels, the bullet in a span marked mso-list:Ignore) become
  // nested <ul>/<ol>; Google Docs lists stay; plain "·" paragraphs only as a run, and never in text that was not pasted
  await step('3.22 (U7) news editor: lists pasted from Word become nested lists, Google Docs lists stay', async () => {
    const word = `<html xmlns:o="urn:schemas-microsoft-com:office:office"><head><style>p.MsoListParagraph{}</style></head><body lang=EN>
<p class=MsoNormal>Intro<o:p></o:p></p>
<p class=MsoListParagraphCxSpFirst style='text-indent:-18.0pt;mso-list:l0 level1 lfo1'><![if !supportLists]><span style='font-family:Symbol'><span style='mso-list:Ignore'>·<span style='font:7.0pt "Times New Roman"'>&nbsp;&nbsp;&nbsp; </span></span></span><![endif]>First item with <i>italic</i><o:p></o:p></p>
<p class=MsoListParagraphCxSpMiddle style='margin-left:72.0pt;text-indent:-18.0pt;mso-list:l0 level2 lfo1'><![if !supportLists]><span style='font-family:"Courier New"'><span style='mso-list:Ignore'>o<span>&nbsp;&nbsp; </span></span></span><![endif]>Nested<o:p></o:p></p>
<p class=MsoListParagraphCxSpLast style='text-indent:-18.0pt;mso-list:l0 level1 lfo1'><![if !supportLists]><span style='font-family:Symbol'><span style='mso-list:Ignore'>·<span>&nbsp;&nbsp; </span></span></span><![endif]>Second<o:p></o:p></p>
<p class=MsoNormal>Between</p>
<p class=MsoListParagraphCxSpFirst style='text-indent:-18.0pt;mso-list:l1 level1 lfo2'><![if !supportLists]><span><span style='mso-list:Ignore'>1.<span>&nbsp;&nbsp; </span></span></span><![endif]>One<o:p></o:p></p>
<p class=MsoListParagraphCxSpLast style='text-indent:-18.0pt;mso-list:l1 level2 lfo2'><![if !supportLists]><span><span style='mso-list:Ignore'>a)<span>&nbsp;&nbsp; </span></span></span><![endif]>Sub a<o:p></o:p></p>
</body></html>`;
    if (await page.locator('dialog[open]').count()) { await page.keyboard.press('Escape'); await page.keyboard.press('Escape'); }
    const editor = page.locator('[contenteditable="true"]').first();
    await editor.click();
    await page.keyboard.press('Control+End');
    const result = await editor.evaluate(async (surface, html) => {
      const data = new DataTransfer();
      data.setData('text/html', html);
      surface.dispatchEvent(new ClipboardEvent('paste', { clipboardData: data, bubbles: true, cancelable: true }));
      await new Promise((r) => setTimeout(r, 300));
      const clean = (h, mode) => window.kaletaCistiHtml(h, mode).replace(/>\s+</g, '><');
      return { html: surface.innerHTML, word: clean(html, 'paste'), docs: clean('<ul><li><p>Docs item</p></li></ul><ol><li>n</li></ol>', 'paste'),
        plain: clean('<p>· one</p><p>· two</p><p>1. Introduction</p>', 'paste'), typed: clean('<p>· one</p><p>· two</p>', true),
        // N322-1: paragraphs that start with dates are no list; numbers that count on are
        dates: clean('<p>15. May we opened.</p><p>20. June is next.</p>', 'paste'), counted: clean('<p>1. one</p><p>2. two</p>', 'paste') };
    }, word);
    const expected = '<p>Intro</p><ul><li>First item with <i>italic</i><ul><li>Nested</li></ul></li><li>Second</li></ul><p>Between</p><ol><li>One<ol><li>Sub a</li></ol></li></ol>';
    if (result.word !== expected) { throw new Error(`Word lists: ${result.word}`); }
    if (!/<ul><li>First item with <i>italic<\/i><ul><li>Nested<\/li><\/ul><\/li><li>Second<\/li><\/ul>/.test(result.html.replace(/>\s+</g, '><'))) { throw new Error(`the pasted Word list in the editor: ${result.html.slice(-400)}`); }
    if (result.docs !== '<ul><li><p>Docs item</p></li></ul><ol><li>n</li></ol>') { throw new Error(`Google Docs list: ${result.docs}`); }
    if (result.plain !== '<ul><li>one</li><li>two</li></ul><p>1. Introduction</p>' || result.typed !== '<p>· one</p><p>· two</p>') { throw new Error(`plain bullets: ${result.plain} | ${result.typed}`); }
    if (result.dates !== '<p>15. May we opened.</p><p>20. June is next.</p>' || result.counted !== '<ol><li>one</li><li>two</li></ol>') { throw new Error(`numbered paragraphs: ${result.dates} | ${result.counted}`); }
  });

  // 3.22 (C14): Media scales a large photo down in the browser before the upload (2000 px) and the message says so
  await step('3.22 (C14) Media: a 3000 px photo is scaled to 2000 px in the browser, and the message says "resized from … to …"', async () => {
    await visit('/admin.php?module=media');
    await page.evaluate(async () => {
      const c = document.createElement('canvas');
      c.width = 3000; c.height = 2000;
      const g = c.getContext('2d');
      const gradient = g.createLinearGradient(0, 0, 3000, 2000);
      gradient.addColorStop(0, '#c33'); gradient.addColorStop(1, '#33c');
      g.fillStyle = gradient; g.fillRect(0, 0, 3000, 2000);
      const blob = await new Promise((r) => c.toBlob(r, 'image/jpeg', 0.95));
      const files = new DataTransfer();
      files.items.add(new File([blob], 'big-photo-322.jpg', { type: 'image/jpeg' }));
      document.querySelector('form[data-nahravani] input[type=file]').files = files.files;
    });
    await Promise.all([page.waitForNavigation(), page.evaluate(() => document.querySelector('form[data-nahravani]').requestSubmit())]);
    const text = await page.locator('main, body').first().innerText();
    if (!/big-photo-322\.jpg resized from 3000 × 2000 px \([^)]+\) to 2000 × 1333 px \([^)]+\)/.test(text)) { throw new Error(`no resize note: ${text.slice(0, 400)}`); }
    const width = sql("SELECT obr_width FROM ka_media WHERE obr_poloha LIKE '%big-photo-322%' ORDER BY ido DESC LIMIT 1").trim();
    if (width !== '2000') { throw new Error(`stored width ${width}`); }
  });
};
