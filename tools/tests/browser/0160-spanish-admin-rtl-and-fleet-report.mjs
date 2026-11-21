// Kaleta browser steps 0160 (tools/test-browser.mjs runs the files of this folder in number order on one site – the order is
// the contract; tools/tests/README.md). Moved verbatim from the single file in 3.49 P1.
export default async (t) => {
  const { BASE, SHOTS, browser, context, page, watch, step, visit, AXE, WCAG, STARTER_SITES } = t;

  await step('3.13 Spanish admin: switched in My account, Spanish screens without sideways scroll at 390 px', async () => {
    const adminLanguage = async (code) => {
      await visit('/admin.php?action=account');
      await page.selectOption('#jazyk', code);
      await Promise.all([page.waitForNavigation(), page.locator('form:has(#jazyk) input[type="submit"]').click()]);
    };
    await adminLanguage('es');
    try {
      for (const [width, height] of [[1440, 900], [390, 844]]) {
        await page.setViewportSize({ width, height });
        for (const url of ['/admin.php', '/admin.php?module=pages', '/admin.php?module=settings', '/admin.php?action=account']) {
          await visit(url);
          if ((await page.locator('html').getAttribute('lang')) !== 'es') { throw new Error(`${url} is not in Spanish`); }
          if (!(await page.evaluate(() => typeof window.KALETA_PREKLAD === 'object' && Object.keys(window.KALETA_PREKLAD).length > 2500))) { throw new Error(`${url} has no Spanish script texts`); }
          const wider = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
          if (wider > 1) { throw new Error(`${url} scrolls sideways by ${wider} px at ${width} px`); }
          if (SHOTS && width === 390) { await page.screenshot({ path: `${SHOTS}/spanish-${url.replace(/\W+/g, '-')}.png`, fullPage: true }); }
        }
      }
      await visit('/admin.php?module=settings');
      if (!(await page.locator('h1', { hasText: 'Ajustes' }).count())) { throw new Error('the Settings heading is not Spanish'); }
    } finally {
      await page.setViewportSize({ width: 1440, height: 900 });
      await adminLanguage('en');
    }
  });

  // right-to-left (data model §5): the Arabic page of the Crafts starter (tools/test-browser.sh) as a visitor sees it on a computer and
  // on a phone, and its English twin – the carousel shows its first slide whole at load and Next brings the second, in both directions
  for (const [language, path] of [['ar', '/ar/rtl-test'], ['en', '/ltr-test']]) {
    for (const [width, height, name] of [[1440, 900, 'desktop'], [390, 844, 'phone']]) {
      await step(`RTL: the ${language === 'ar' ? 'Arabic' : 'English'} page at ${width} px – direction, no sideways scroll, the carousel${language === 'ar' ? ' and the layout mirrored' : ''}`, async () => {
        const visitor = await browser.newContext({ viewport: { width, height }, locale: language });
        const v = await visitor.newPage();
        watch(v);
        try {
          await v.goto(`${STARTER_SITES.remeslo}${path}`, { waitUntil: 'networkidle' });
          const root = await v.evaluate(() => [document.documentElement.lang, document.documentElement.dir].join());
          if (root !== (language === 'ar' ? 'ar,rtl' : 'en,')) { throw new Error(`<html> is ${root}`); }
          const overflow = () => v.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
          if (await overflow() > 1) { throw new Error(`the page scrolls sideways by ${await overflow()} px`); }
          // the cookie bar sits at the start side – the right edge of a right-to-left page
          const bar = await v.locator('#cookies-lista').boundingBox();
          if (language === 'ar' && bar && width - (bar.x + bar.width) > 24) { throw new Error(`the cookie bar is ${Math.round(width - bar.x - bar.width)} px from the right edge`); }
          if (await v.locator('#cookies-lista button').count()) { await v.locator('#cookies-lista button').first().click(); }
          // the carousel at load: the first slide and its heading whole inside the strip; Previous is off
          const carousel = v.locator('[data-karusel]').first();
          const strip = carousel.locator('.ka-karusel-pas');
          await carousel.scrollIntoViewIfNeeded();
          const inside = (i) => strip.evaluate((s, n) => { const box = s.getBoundingClientRect(); const slide = s.children[n].getBoundingClientRect(); const heading = s.children[n].querySelector('h3').getBoundingClientRect();
            return [slide, heading].every((r) => r.width > 0 && r.left >= box.left - 1 && r.right <= box.right + 1); }, i);
          if (!(await inside(0))) { throw new Error('the first slide is not whole inside the carousel at load'); }
          if (!(await carousel.locator('[data-krok="-1"]').isDisabled())) { throw new Error('Previous is not off at the first slide'); }
          if (SHOTS && language === 'ar') { await v.screenshot({ path: `${SHOTS}/rtl-arabic-${name}.png`, fullPage: true }); }
          const [previous, next] = [await carousel.locator('[data-krok="-1"]').boundingBox(), await carousel.locator('[data-krok="1"]').boundingBox()];
          if (!previous || !next || (language === 'ar' ? previous.x <= next.x : previous.x >= next.x)) { throw new Error('Previous and Next are in the wrong order'); }
          if (await carousel.locator('[data-krok="-1"]').evaluate((b) => getComputedStyle(b).direction) !== (language === 'ar' ? 'rtl' : 'ltr')) { throw new Error('the arrow buttons do not follow the direction (their arrows mirror with it)'); }
          await carousel.locator('[data-krok="1"]').click();
          await v.waitForTimeout(900);
          if (!(await inside(1)) || await inside(0)) { throw new Error('Next did not bring the second slide'); }
          const scrolled = await strip.evaluate((s) => s.scrollLeft);
          if (language === 'ar' ? !(scrolled < -10) : !(scrolled > 10)) { throw new Error(`Next moved the strip to ${scrolled}`); }
          await carousel.locator('[data-krok="-1"]').click();
          await v.waitForTimeout(900);
          if (!(await inside(0))) { throw new Error('Previous did not bring the first slide back'); }
          if (name === 'phone' && language === 'ar') {
            // the phone menu: its button at the left (the end side), the logo at the right; the sheet opens inside the window, its items from the right
            const toggle = v.locator('.ka-nav-tl:visible, .menu-tl:visible').first();
            const logo = v.locator('.ka-logo:visible, .logo:visible').first();
            const [t, l] = [await toggle.boundingBox(), await logo.boundingBox()];
            if (!t || !l || t.x >= l.x) { throw new Error('the menu button is not left of the logo'); }
            await toggle.click();
            const sheet = v.locator('.ka-nav-menu[popover]:popover-open, .navigace[popover]:popover-open').first();
            await sheet.waitFor();
            const box = await sheet.boundingBox();
            const item = await sheet.locator('a:visible').first().boundingBox();
            if (!box || box.x < 0 || box.x + box.width > width + 1) { throw new Error('the menu sheet is outside the window'); }
            if (!item || (box.x + box.width) - (item.x + item.width) > 48) { throw new Error('the menu items do not start at the right'); }
            if (await overflow() > 1) { throw new Error('the open menu scrolls the page sideways'); }
            if (SHOTS) { await v.screenshot({ path: `${SHOTS}/rtl-arabic-phone-menu.png` }); }
          }
        } finally {
          await visitor.close();
        }
      });
    }
  }

  // 3.17: the fleet console (test-browser.sh switches the extension on and stores a site with 95 days of history and last month's
  // fleet report): on the site's page the trends – 90 bars with a summary for screen readers, the days as a table that opens,
  // no WCAG violation in the block, nothing wider than a phone – and the fleet report in print at the width of an A4 page
  await step('3.17 fleet console: the trends of a site and the monthly fleet report in print', async () => {
    await visit('/admin.php?module=fleet&action=detail&id=900');
    // 3.26.1: the bars are Core\UptimeStrip – the e-mail-safe table the site's monthly report draws too
    const strip = page.locator('figure.trend table[role="img"]');
    await strip.waitFor();
    if ((await strip.locator('td[title]').count()) !== 90) { throw new Error('the trend has not 90 bars'); }
    if (!/90/.test(await strip.getAttribute('aria-label') || '')) { throw new Error('the trend has no summary for screen readers'); }
    if ((await strip.locator('td[bgcolor="#D92D20"]').count()) < 1 || (await strip.locator('td[bgcolor="#C97F00"]').count()) < 1) { throw new Error('the bad days are not marked'); }
    const stripBox = await strip.boundingBox();
    if (!stripBox || stripBox.height < 36 || stripBox.width < 300) { throw new Error('the strip is not drawn at its size'); }
    await page.locator('details.trend-tabulka summary').click();
    if ((await page.locator('details.trend-tabulka tbody tr').count()) < 85) { throw new Error('the table of the days is short'); }
    // axe in a context that lets the test inject it – the administration allows no inline script (CSP), rightly
    const axeContext = await browser.newContext({ storageState: await context.storageState(), bypassCSP: true, viewport: { width: 1440, height: 900 } });
    try {
      const axePage = await axeContext.newPage();
      await axePage.goto(BASE + '/admin.php?module=fleet&action=detail&id=900', { waitUntil: 'networkidle' });
      await axePage.locator('details.trend-tabulka summary').click();
      await axePage.addScriptTag({ path: AXE });
      const found = await axePage.evaluate((tags) => window.axe.run({ include: [['figure.trend'], ['details.trend-tabulka']] }, { runOnly: { type: 'tag', values: tags }, resultTypes: ['violations'] }), WCAG);
      if (found.violations.length) { throw new Error('axe: ' + found.violations.map((v) => `${v.id} ${v.nodes[0].target.join(' ')}`).join(', ')); }
    } finally {
      await axeContext.close();
    }
    if (SHOTS) {
      await page.locator('figure.trend').scrollIntoViewIfNeeded();
      await page.screenshot({ path: `${SHOTS}/fleet-trends.png`, fullPage: true });
      await page.emulateMedia({ colorScheme: 'dark' });
      await page.screenshot({ path: `${SHOTS}/fleet-trends-dark.png`, fullPage: true });
      await page.emulateMedia({ colorScheme: 'light' });
    }
    await page.setViewportSize({ width: 390, height: 844 });
    try {
      const right = await page.evaluate(() => document.querySelector('figure.trend').getBoundingClientRect().right);
      if (right > 391) { throw new Error(`the trend is wider than a phone (${right} px)`); }
      if (SHOTS) { await page.screenshot({ path: `${SHOTS}/fleet-trends-phone.png`, fullPage: false }); }
    } finally {
      await page.setViewportSize({ width: 1440, height: 900 });
    }
    await visit('/admin.php?module=fleet&action=reports');
    const view = page.locator('a[href*="action=report_view"]').first();
    await view.waitFor();
    if (SHOTS) { await page.screenshot({ path: `${SHOTS}/fleet-reports.png`, fullPage: true }); }
    const report = await context.newPage();
    watch(report);
    try {
      await report.goto(new URL(await view.getAttribute('href'), BASE).href, { waitUntil: 'networkidle' });
      await report.locator('[data-tisk]').waitFor();
      if ((await report.locator('section.sekce').count()) < 3) { throw new Error('the fleet report has fewer than 3 sections'); }
      if ((await report.locator('p.druh').textContent()) !== 'Fleet report') { throw new Error('the page does not say Fleet report'); }
      // 3.26.1: the site's month as the same uptime strip as on its page (Core\UptimeStrip), one bar a day
      const monthStrip = report.locator('.uptime table[role="img"]').first();
      if ((await monthStrip.locator('td[title]').count()) < 28) { throw new Error('the fleet report has no uptime strip for the month'); }
      if (SHOTS) { await report.screenshot({ path: `${SHOTS}/fleet-report-screen.png`, fullPage: true }); }
      await report.emulateMedia({ media: 'print' });
      await report.setViewportSize({ width: 680, height: 1000 });
      const wide = await report.evaluate(() => {
        const sheet = document.querySelector('.listina').getBoundingClientRect();
        return [...document.querySelectorAll('.listina table, .listina dl, .listina h1, .listina h2, .listina p, .listina td')]
          .filter((el) => el.getBoundingClientRect().right > sheet.right + 1 || el.scrollWidth > el.clientWidth + 1).map((el) => el.tagName + ': ' + el.textContent.slice(0, 40));
      });
      if (wide.length) { throw new Error('wider than the A4 page in print: ' + wide.slice(0, 3).join(' | ')); }
      if (await report.locator('.lista').isVisible()) { throw new Error('the toolbar prints'); }
      if (SHOTS) {
        await report.setViewportSize({ width: 680, height: await report.evaluate(() => document.documentElement.scrollHeight) });
        await report.screenshot({ path: `${SHOTS}/fleet-report-print.png` });
        await report.pdf({ path: `${SHOTS}/fleet-report-print.pdf`, format: 'A4', preferCSSPageSize: true });
      }
      const pdf = await report.pdf({ format: 'A4', preferCSSPageSize: true });
      const pages = (pdf.toString('latin1').match(/\/Type\s*\/Page[^s]/g) || []).length;
      if (pages < 1 || pages > 4) { throw new Error(`the A4 PDF has ${pages} pages`); }
    } finally {
      await report.close();
    }
  });
};
