// Kaleta browser steps 0110 (tools/test-browser.mjs runs the files of this folder in number order on one site – the order is
// the contract; tools/tests/README.md). Moved verbatim from the single file in 3.49 P1.
export default async (t) => {
  const { BASE, SHOTS, context, page, watch, step, visit } = t;

  await step('newsletter: draft and preview', async () => {
    await visit('/admin.php?module=newsletters&action=new');
    await page.fill('input[name="subject"]', 'Spring news from our workshop');
    await page.fill('input[name="preheader"]', 'Two new projects and a spring offer');
    await page.fill('textarea[name="intro"]', 'Hello,\n\nhere is what we have been working on this spring. The full offer is at https://example.com/offer');
    await page.fill('input[name="button_label"]', 'See all news');
    await page.fill('input[name="button_url"]', '/news');
    await Promise.all([page.waitForNavigation(), page.locator('form.formular input[type="submit"]').first().click()]);
    const preview = page.locator('iframe.rozesilka-nahled');
    await preview.waitFor();
    await page.frameLocator('iframe.rozesilka-nahled').locator('h1').waitFor({ timeout: 5000 });
    if (SHOTS) {
      await page.screenshot({ path: `${SHOTS}/newsletter-admin.png`, fullPage: true });
      const src = await preview.getAttribute('src');
      for (const [name, width] of [['newsletter-email', 700], ['newsletter-email-phone', 390]]) {
        await page.setViewportSize({ width, height: 900 });
        await page.goto(new URL(src, BASE).href, { waitUntil: 'networkidle' });
        await page.screenshot({ path: `${SHOTS}/${name}.png`, fullPage: true });
      }
      await page.setViewportSize({ width: 1440, height: 900 });
    }
  });

  // 3.16: the archived monthly report (Settings → Reports, Core\ReportPage) – the tab lists the month test-browser.sh stored,
  // the printable page has its Print button, and in print media at the width of an A4 page (210 mm less the 15 mm margins)
  // nothing is wider than the page: no table is cut at its right edge; a PDF of it is A4
  await step('monthly report: the archive and the A4 print layout', async () => {
    await visit('/admin.php?module=settings&tab=reports');
    const view = page.locator('a[href*="action=report_view"]').first();
    await view.waitFor();
    if (SHOTS) { await page.screenshot({ path: `${SHOTS}/reports-tab.png`, fullPage: true }); }
    const href = new URL(await view.getAttribute('href'), BASE).href;
    const report = await context.newPage();
    watch(report);
    try {
      await report.goto(href, { waitUntil: 'networkidle' });
      await report.locator('[data-tisk]').waitFor();
      if ((await report.locator('section.sekce').count()) < 5) { throw new Error('the report has fewer than 5 sections'); }
      if (SHOTS) { await report.screenshot({ path: `${SHOTS}/report-screen.png`, fullPage: true }); }
      await report.emulateMedia({ media: 'print' });
      await report.setViewportSize({ width: 680, height: 1000 }); // 180 mm at 96 dpi: the printable width of A4
      const wide = await report.evaluate(() => {
        const page = document.querySelector('.listina').getBoundingClientRect();
        return [...document.querySelectorAll('.listina table, .listina dl, .listina h1, .listina h2, .listina p, .listina td')]
          .filter((el) => el.getBoundingClientRect().right > page.right + 1 || el.scrollWidth > el.clientWidth + 1).map((el) => el.tagName + ': ' + el.textContent.slice(0, 40));
      });
      if (wide.length) { throw new Error('wider than the A4 page in print: ' + wide.slice(0, 3).join(' | ')); }
      if (await report.locator('.lista').isVisible()) { throw new Error('the toolbar prints'); }
      if (SHOTS) {
        // print media in one tall viewport (a full-page screenshot repeats the first screen in print media); the PDF has the pages
        await report.setViewportSize({ width: 680, height: await report.evaluate(() => document.documentElement.scrollHeight) });
        await report.screenshot({ path: `${SHOTS}/report-print.png` });
        await report.pdf({ path: `${SHOTS}/report-print.pdf`, format: 'A4', preferCSSPageSize: true });
      }
      const pdf = await report.pdf({ format: 'A4', preferCSSPageSize: true });
      const pages = (pdf.toString('latin1').match(/\/Type\s*\/Page[^s]/g) || []).length;
      if (pages < 1 || pages > 6) { throw new Error(`the A4 PDF has ${pages} pages`); }
    } finally {
      await report.close();
    }
  });

  // 3.26.1: the report page and the e-mail share one look (Core\ReportVisual) – at 1440 and 390 px, light and dark: the tiles and
  // the groups of "What it took to run the site" side by side on a computer and wrapped on a phone, no sideways scroll, the dark
  // screen turns the paper dark (printing stays light – the step above); the e-mail preview reads at 390 px in both modes too
  await step('3.26.1 monthly report: the report page and the e-mail at 1440 and 390 px, light and dark', async () => {
    await visit('/admin.php?module=settings&tab=reports');
    const href = new URL(await page.locator('a[href*="action=report_view"]').first().getAttribute('href'), BASE).href;
    const preview = new URL('/admin.php?module=settings&action=report_preview', BASE).href;
    for (const scheme of ['light', 'dark']) {
      for (const width of [1440, 390]) {
        const tab = await context.newPage();
        watch(tab);
        try {
          await tab.emulateMedia({ colorScheme: scheme });
          await tab.setViewportSize({ width, height: 900 });
          for (const [name, url] of [['report', href], ['email', preview]]) {
            await tab.goto(url, { waitUntil: 'networkidle' });
            const look = await tab.evaluate(() => {
              const tiles = [...document.querySelectorAll('.kr-tile')].map((t) => t.getBoundingClientRect());
              const card = document.querySelector('.kr-card, .listina');
              return { tiles: tiles.length, rows: new Set(tiles.map((r) => Math.round(r.top))).size, groups: document.querySelectorAll('.kr-box').length,
                wide: document.documentElement.scrollWidth > window.innerWidth + 1, background: card ? getComputedStyle(card).backgroundColor : '' };
            });
            if (look.tiles < 3 || look.groups < 2) { throw new Error(`${name} ${width} ${scheme}: ${look.tiles} tiles, ${look.groups} groups`); }
            if (look.wide) { throw new Error(`${name} ${width} ${scheme}: scrolls sideways`); }
            if (width === 1440 && look.rows !== 1) { throw new Error(`${name} 1440: the tiles are not in one row (${look.rows})`); }
            if (width === 390 && look.rows < 2) { throw new Error(`${name} 390: the tiles do not wrap`); }
            const dark = look.background === 'rgb(28, 28, 27)';
            if (dark !== (scheme === 'dark')) { throw new Error(`${name} ${width} ${scheme}: the card is ${look.background}`); }
            if (SHOTS) { await tab.screenshot({ path: `${SHOTS}/report3261-${name}-${width}-${scheme}.png`, fullPage: true }); }
          }
        } finally {
          await tab.close();
        }
      }
    }
  });

  await step('3.9 Settings → Mail: choosing a mail service fills the server, port and encryption, shows its hint, and leaves the user name alone', async () => {
    await visit('/admin.php?module=settings&tab=mail');
    await page.locator('label.karta-volba', { has: page.locator('input[name="mail_mode"][value="smtp"]') }).click();
    await page.fill('#smtp_user', 'kept@example.com');
    await page.selectOption('#smtp_provider', 'brevo');
    const brevo = [await page.inputValue('#smtp_host'), await page.inputValue('#smtp_port'), await page.inputValue('#smtp_encryption'), await page.inputValue('#smtp_user'),
      await page.locator('[data-smtp-tip="brevo"]').isVisible(), await page.locator('#smtp_ses_region').isVisible()].join('|');
    if (brevo !== 'smtp-relay.brevo.com|587|tls|kept@example.com|true|false') { throw new Error('Brevo: ' + brevo); }
    await page.selectOption('#smtp_provider', 'ses');
    await page.selectOption('#smtp_ses_region', 'eu-west-1');
    const ses = [await page.inputValue('#smtp_host'), await page.locator('[data-smtp-tip="brevo"]').isVisible(), await page.locator('#smtp_ses_region').isVisible()].join('|');
    if (ses !== 'email-smtp.eu-west-1.amazonaws.com|false|true') { throw new Error('SES: ' + ses); }
    // "Other server" keeps whatever is typed in – the free form as before
    await page.selectOption('#smtp_provider', 'other');
    if (await page.inputValue('#smtp_host') !== 'email-smtp.eu-west-1.amazonaws.com') { throw new Error('Other server changed the server'); }
  });

  // 3.23 (C12, C8): Settings → General split into General, Languages, Security and Agency – saving one tab leaves the fields of the
  // others alone (the checkboxes too), an old link to a moved field goes on to its tab, and the console tab ("Reporting to a
  // console") is in the row only once an agency is named
  await step('3.23 Settings tabs: saving one keeps the others, old links move on, the console tab follows the agency', async () => {
    const save = () => Promise.all([page.waitForNavigation(), page.locator('p.tlacitka input[type="submit"]').click()]);
    const tabs = async () => page.locator('nav.zalozky a').evaluateAll((links) => links.map((a) => new URL(a.href).searchParams.get('tab')));
    await visit('/admin.php?module=settings&tab=security');
    const suspend = page.locator('input[name="auto_suspend[]"]').first();
    const suspendBefore = await suspend.isChecked();
    await suspend.setChecked(true);
    await save();
    if (!(await page.locator('a[href*="tab=firewall"]', { hasText: 'Settings → Firewall' }).count())) { throw new Error('Security does not link Settings → Firewall'); }
    await visit('/admin.php?module=settings&tab=general');
    if (await page.locator('[name="auto_suspend[]"], [name="agency_name"], [name="site_language"], [name="require_2fa"]').count()) { throw new Error('General still shows moved fields'); }
    const description = await page.inputValue('#site_description');
    await page.fill('#site_description', description + ' 323');
    await save();
    await visit('/admin.php?module=settings&tab=security');
    if (!(await page.locator('input[name="auto_suspend[]"]').first().isChecked())) { throw new Error('saving General cleared the automatic suspension'); }
    // the link of 3.22 to a field that moved (a bookmark, Features → Choose languages before the update)
    await page.goto(BASE + '/admin.php?module=settings&tab=general#require_2fa');
    await page.waitForURL(/tab=security#require_2fa$/, { timeout: 5000 });
    if (!(await page.locator('#require_2fa').isVisible())) { throw new Error('the old link did not reach Two-factor sign-in'); }
    // 3.31 (code scanning #44): a map naming another origin, a protocol-relative path or javascript: never moves the page;
    // a same-origin path with doubled slashes goes to the path on this origin
    const movedRoute = (map) => async (route) => {
      const response = await route.fetch();
      const html = (await response.text()).replace(/data-pole-jinde="[^"]*"/, `data-pole-jinde="${JSON.stringify(map).replaceAll('&', '&amp;').replaceAll('"', '&quot;')}"`);
      await route.fulfill({ response, body: html });
    };
    const movedPattern = /module=settings&tab=general$/;
    // each time from another page: a goto to the same address with the same #anchor is a jump within the page, no reload
    const openGeneral = async () => { await page.goto(BASE + '/admin.php?module=settings&tab=agency'); await page.goto(BASE + '/admin.php?module=settings&tab=general#require_2fa'); };
    for (const evil of ['https://evil.example/x', '//evil.example/x', '/\\evil.example/x', 'javascript:alert(1)']) {
      await page.route(movedPattern, movedRoute({ require_2fa: evil }));
      await openGeneral();
      await page.waitForTimeout(400);
      await page.unroute(movedPattern);
      if (!/module=settings&tab=general#require_2fa$/.test(page.url())) { throw new Error(`the moved-field map ${evil} moved the page to ${page.url()}`); }
    }
    await page.route(movedPattern, movedRoute({ require_2fa: new URL(BASE).origin + '//admin.php?module=settings&tab=security' }));
    await openGeneral();
    await page.waitForURL((url) => url.hash === '#require_2fa' && url.search === '?module=settings&tab=security', { timeout: 5000 });
    await page.unroute(movedPattern);
    if (new URL(page.url()).origin !== new URL(BASE).origin) { throw new Error(`a doubled slash left the site: ${page.url()}`); }
    // the console tab: not in the row without an agency, the last one with it
    await visit('/admin.php?module=settings&tab=agency');
    const agencyBefore = await page.inputValue('#agency_name');
    await page.fill('#agency_name', '');
    await save();
    if ((await tabs()).includes('console')) { throw new Error('the console tab shows without an agency'); }
    await page.fill('#agency_name', 'Browser Studio');
    await save();
    const withAgency = await tabs();
    if (withAgency.at(-1) !== 'console' || withAgency.at(-2) !== 'agency' || !(await page.locator('nav.zalozky a', { hasText: 'Reporting to a console' }).count())) { throw new Error(`the tabs with an agency: ${withAgency.join(', ')}`); }
    if (SHOTS) { await page.screenshot({ path: `${SHOTS}/settings-agency-tab.png`, fullPage: true }); }
    // put everything back
    await page.fill('#agency_name', agencyBefore);
    await save();
    await visit('/admin.php?module=settings&tab=general');
    await page.fill('#site_description', description);
    await save();
    await visit('/admin.php?module=settings&tab=security');
    await page.locator('input[name="auto_suspend[]"]').first().setChecked(suspendBefore);
    await save();
  });
};
