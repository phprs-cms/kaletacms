// Kaleta browser steps 0030 (tools/test-browser.mjs runs the files of this folder in number order on one site – the order is
// the contract; tools/tests/README.md). Moved verbatim from the single file in 3.49 P1.
export default async (t) => {
  const { BASE, PASSWORD, SHOTS, page, step, visit, createHash } = t;

  // 3.13.1: the consent screen stands in the bare frame; its access choices are option cards (a hidden radio, a border) and
  // "recommended" / "new, not verified" are pills – both styled by editor.css, which the frame did not load
  await step('OAuth consent: the access choices are cards, the labels are pills', async () => {
    const redirect = 'https://claude.ai/api/mcp/auth_callback';
    const client = await (await fetch(`${BASE}/oauth/register`, { method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ client_name: 'Claude', redirect_uris: [redirect], token_endpoint_auth_method: 'none' }) })).json();
    const challenge = createHash('sha256').update('v'.repeat(50)).digest('base64url');
    await visit(`/oauth/authorize?response_type=code&client_id=${client.client_id}&redirect_uri=${encodeURIComponent(redirect)}&code_challenge=${challenge}&code_challenge_method=S256&state=s&scope=mcp`);
    const look = await page.evaluate(() => {
      const card = document.querySelector('.oauth-souhlas .karta-volba');
      const pill = document.querySelector('.oauth-souhlas .stitek');
      return card && pill ? { radio: getComputedStyle(card.querySelector('input')).opacity, border: getComputedStyle(card).borderTopWidth,
        title: getComputedStyle(card.querySelector('strong')).display, pill: getComputedStyle(pill).borderTopWidth } : null;
    });
    if (!look) { throw new Error('no access choices or label on the consent screen'); }
    if (look.radio !== '0' || look.border !== '2px' || look.title !== 'block' || look.pill !== '1px') { throw new Error(`the consent screen is unstyled: ${JSON.stringify(look)}`); }
    if (SHOTS) { await page.screenshot({ path: `${SHOTS}/oauth-consent.png`, fullPage: true }); }
  });

  // 3.10 audit (N310-7): Enter in the name of a new token creates the token – also when tokens with their tool set switch
  // and revoke buttons are listed above it (they belong to another form, so they are never the form's default button)
  await step('account: Enter in the token name creates the token, with tokens listed too', async () => {
    for (const name of ['Enter token 1', 'Enter token 2']) {
      await visit('/admin.php?action=account&tab=claude');
      await page.fill('#token-nazev', name);
      await page.fill('#token-heslo', PASSWORD); // 3.31.2 (N101): a token needs the current password
      await Promise.all([page.waitForNavigation(), page.press('#token-nazev', 'Enter')]);
      if (!((await page.locator('code.totp-klic').first().textContent()) || '').startsWith('kaleta_')) throw new Error(`no new token for ${name}`);
    }
    const listed = await page.locator('#claude .stitek', { hasText: 'Enter token' }).count();
    const switched = await page.locator('#claude .stitek', { hasText: /^essentials$/ }).count();
    if (listed !== 2 || switched !== 0) throw new Error(`${listed} tokens listed, ${switched} switched to the essentials`);
  });

  // 3.36 (B): the tool set of a connection changes through one folded choice of every other set offered on the site (fleet only
  // on a fleet site); it fits a phone and reads in dark mode
  await step('3.36 account: a token changes to the migration tools through the folded choice (1440 and 390 px, dark mode)', async () => {
    await visit('/admin.php?action=account&tab=claude');
    const choice = page.locator('#claude .napojeni', { hasText: 'Enter token 1' }).locator('details.sada-nastroju');
    await choice.locator('summary').click();
    const offered = (await choice.locator('button').allTextContents()).map((t) => t.trim()).join('|');
    // this site has the Fleet console extension on, so the fleet set is offered too
    if (offered !== 'essentials|work tools|migration tools|fleet tools') throw new Error(`the sets offered: ${offered}`);
    for (const [width, scheme] of [[390, 'light'], [390, 'dark']]) {
      await page.setViewportSize({ width, height: 844 });
      await page.emulateMedia({ colorScheme: scheme });
      const wide = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
      if (wide > 0) throw new Error(`${wide} px of sideways scroll at ${width} px with the choice open`);
      const look = await choice.locator('summary').evaluate((el) => [getComputedStyle(el).backgroundColor, getComputedStyle(el).color]);
      if (look[0] === look[1]) throw new Error(`the summary is unreadable in ${scheme} mode: ${look.join(' on ')}`);
      if (SHOTS) { await choice.screenshot({ path: `${SHOTS}/336b-tool-set-${scheme}.png` }); }
    }
    await page.emulateMedia({ colorScheme: 'light' });
    await page.setViewportSize({ width: 1440, height: 900 });
    await Promise.all([page.waitForNavigation(), choice.getByRole('button', { name: 'migration tools' }).click()]);
    const label = await page.locator('#claude .napojeni', { hasText: 'Enter token 1' }).locator('.stitek', { hasText: /^migration tools$/ }).count();
    if (label !== 1) throw new Error('the token is not labelled with the migration tools after the change');
  });

  await step('appearance: change a colour and preview', async () => {
    await visit('/admin.php?module=appearance');
    const colour = page.locator('input[type="color"]:visible').first();
    if (await colour.count()) { await colour.fill('#335577'); await page.waitForTimeout(800); }
  });

  // 3.12 (UX310-24): the logo and the site icon show a preview with Change and Remove, not a path box; the media picker offers
  // "Take a photo" only on touch devices
  await step('3.12 appearance: logo field with a preview and Change, no Take a photo on a desktop', async () => {
    await visit('/admin.php?module=appearance');
    await page.locator('#zalozka-znacka').click();
    if (await page.locator('#logo').isVisible()) { throw new Error('the logo path box is still shown'); }
    const choose = page.locator('#logo-vybrat');
    if (!(await choose.isVisible()) || (await page.locator('label[for="logo-vybrat"]').count()) !== 1) { throw new Error('the logo has no labelled Choose button'); }
    await choose.click();
    await page.locator('dialog.galerie-okno[open]').waitFor();
    if (await page.locator('dialog.galerie-okno[open] .galerie-vyfotit').isVisible()) { throw new Error('"Take a photo" shows on a desktop'); }
    await page.locator('dialog.galerie-okno[open] [data-zavri]').click();
  });

  await step('appearance: save to the draft look, preview bar, publish', async () => {
    await visit('/admin.php?module=appearance');
    // the colour field may sit on a tab that is not open – set the value directly
    await page.evaluate(() => { document.querySelectorAll('[name="ds[barvy][primarni]"]').forEach((i) => { i.value = '#335577'; }); });
    await Promise.all([page.waitForNavigation(), page.locator('.vzhled-ulozit input[type="submit"]').click()]);
    await page.locator('#vzhled-koncept').waitFor();
    if (SHOTS) { await page.screenshot({ path: `${SHOTS}/look-draft-bar.png`, fullPage: false }); }
    await Promise.all([page.waitForNavigation(), page.locator('#vzhled-koncept button.tl').click()]);
    if (await page.locator('#vzhled-koncept').count()) { throw new Error('the draft look is still there after publishing'); }
  });
};
