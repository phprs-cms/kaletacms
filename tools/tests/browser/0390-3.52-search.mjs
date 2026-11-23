// Kaleta browser steps 0390 (tools/test-browser.mjs runs the files of this folder in number order on one site – the order is
// the contract; tools/tests/README.md). 3.52 – search: Ctrl/⌘K answers from the server in groups with "+ N more" (A4, A5),
// the news list says Found and marks the words (A7, A8), the site search lists the matches with marks (B1–B3) and the
// header template With search opens its field from the magnifier without a script (B4) – at 1440 and 390 px, light and
// dark, axe clean. The browser site is installed in English.
export default async (t) => {
  const { BASE, browser, context, page, watch, sql, step, visit, SHOTS, AXE, WCAG, WEB_DIR, execFileSync } = t;

  const php = (code) => execFileSync('php', ['-r', `require "system/bootstrap.php"; $app = Kaleta\\Core\\App::boot(); $db = $app->db(); ${code}`], { cwd: WEB_DIR, encoding: 'utf8' });
  // a context of the signed-in admin that may inject axe (the admin's CSP is script-src 'self')
  const signedIn = async (width, height) => {
    const ctx = await browser.newContext({ storageState: await context.storageState(), bypassCSP: true, viewport: { width, height }, locale: 'en-GB' });
    const p = await ctx.newPage();
    watch(p);
    return [ctx, p];
  };
  const axeIn = async (p, include, label) => {
    await p.addScriptTag({ path: AXE });
    const found = await p.evaluate(([inc, tags]) => window.axe.run({ include: inc.map((s) => [s]) }, { runOnly: { type: 'tag', values: tags }, resultTypes: ['violations'] }), [include, WCAG]);
    if (found.violations.length) { throw new Error(`axe ${label}: ` + found.violations.map((v) => `${v.id} ${v.nodes[0].target.join(' ')}`).join(', ')); }
  };
  const sideways = (p) => p.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  const looks = (locator) => locator.evaluate((el) => ({ color: getComputedStyle(el).color, background: getComputedStyle(el).backgroundColor }));

  // seven news items and a page named Dvořák (typed "dvorak b352" in the palette and the list); for the site search a page with
  // "workshop" in its text, a news item with "workshops" and a hidden page that must never show – every one with the marker b352
  php(`$u = (int) $db->value("SELECT idu FROM {uzivatele} WHERE user = 'admin'");
    $tema = (int) $db->value("SELECT idt FROM {kategorie} WHERE jazyk = '' ORDER BY idt LIMIT 1");
    if ($tema === 0) { $tema = $db->insert("kategorie", ["nazev" => "B352", "seo_link" => "b352", "popis" => ""]); }
    $news = fn (string $slug, string $title, string $text, int $hours) => Kaleta\\Core\\Search::index($db, $db->insert("novinky", ["seo_link" => $slug, "titulek" => $title,
      "uvod" => "<p>Intro.</p>", "text" => $text, "tema" => $tema, "datum" => date("Y-m-d H:i:s", time() - 3600 * $hours), "visible" => 1, "autor" => $u]));
    for ($i = 1; $i <= 7; $i++) { $news("b352-dvorak-$i", "B352 Dvořák report $i", "<p>Report.</p>", $i); }
    $news("b352-workshops", "B352 Workshops for beginners", "<p>Two workshops a month in Brno.</p>", 1);
    $db->insert("stranky", ["seo_link" => "b352-dvorak", "titulek" => "B352 Dvořák page", "text" => "<p>About.</p>", "v_menu" => 0, "zobrazit" => 1]);
    $db->insert("stranky", ["seo_link" => "b352-workshop", "titulek" => "B352 Repairs in Brno", "text" => "<p>Our workshop repairs bikes and prams in Brno.</p>", "v_menu" => 0, "zobrazit" => 1]);
    $db->insert("stranky", ["seo_link" => "b352-hidden", "titulek" => "B352 Workshop hidden", "text" => "<p>workshop</p>", "v_menu" => 0, "zobrazit" => 0]);`);
  const cleanUp = () => sql("DELETE FROM ka_novinky WHERE seo_link LIKE 'b352-%'; DELETE FROM ka_stranky WHERE seo_link LIKE 'b352-%'; DELETE FROM ka_kategorie WHERE seo_link = 'b352'");

  try {
    await step('3.52 A4, A5: Ctrl/⌘K finds the page and the news on the server – groups, "+ 2 more" to the news list, light and dark at 1440 px and at 390 px, axe clean', async () => {
      for (const [width, height] of [[1440, 900], [390, 844]]) {
        const [ctx, p] = await signedIn(width, height);
        try {
          for (const scheme of ['light', 'dark']) {
            await p.emulateMedia({ colorScheme: scheme });
            await p.goto(BASE + '/admin.php', { waitUntil: 'networkidle' });
            await p.keyboard.press('Control+k');
            const palette = p.locator('dialog#paleta[open]');
            await palette.waitFor();
            if (await p.locator('.paleta-pole[role="combobox"]').count() !== 1) { throw new Error(`${width} ${scheme}: the palette field is no combobox`); }
            await p.locator('.paleta-pole').fill('dvorak b352');
            const more = p.locator('.paleta-seznam a[role="option"]', { hasText: '+ 2 more' });
            await more.waitFor({ timeout: 10000 });
            const headings = await p.locator('.paleta-seznam .paleta-skupina').allTextContents();
            if (!headings.includes('Pages') || !headings.includes('News')) { throw new Error(`${width} ${scheme}: the groups are ${JSON.stringify(headings)}`); }
            const news = await p.locator('.paleta-seznam a[role="option"]', { hasText: 'B352 Dvořák report' }).count();
            if (news !== 5) { throw new Error(`${width} ${scheme}: ${news} news rows, 5 expected in a group`); }
            const href = (await more.getAttribute('href')) || '';
            if (!/module=news/.test(href) || !/search=dvorak(%20|\+)b352/.test(href)) { throw new Error(`${width} ${scheme}: "+ 2 more" leads to ${href}`); }
            if (await p.locator('.paleta-pole').getAttribute('aria-activedescendant') === null) { throw new Error(`${width} ${scheme}: no row is announced as selected`); }
            const heading = await looks(p.locator('.paleta-seznam .paleta-skupina').first());
            if (heading.color === heading.background) { throw new Error(`${width} ${scheme}: a group heading has the colour of its background`); }
            if (width < 720 && await p.locator('.paleta-napoveda').isVisible()) { throw new Error('the key hints show at phone width'); }
            if (width < 720 && await sideways(p) > 1) { throw new Error(`${scheme}: the palette scrolls the page sideways at 390 px`); }
            if (SHOTS) { await p.screenshot({ path: `${SHOTS}/390-palette-${width}-${scheme}.png` }); }
            await axeIn(p, ['dialog#paleta'], `palette ${width} ${scheme}`);
            if (width === 1440 && scheme === 'light') {
              // Enter opens the selected row – arrow down to "+ 2 more" and the news list opens with the search
              const options = await p.locator('.paleta-seznam a[role="option"]').evaluateAll((as) => as.map((a) => a.textContent || ''));
              const target = options.findIndex((o) => o.startsWith('+ 2 more'));
              const selected = await p.locator('.paleta-seznam a[role="option"][aria-selected="true"]').evaluate((option) => [...option.closest('ul').querySelectorAll('[role="option"]')].indexOf(option));
              for (let i = selected; i < target; i++) { await p.keyboard.press('ArrowDown'); }
              await Promise.all([p.waitForNavigation(), p.keyboard.press('Enter')]);
              if (!/module=news/.test(p.url()) || !/search=dvorak/.test(p.url())) { throw new Error(`Enter on "+ 2 more" opened ${p.url()}`); }
            }
          }
        } finally {
          await ctx.close();
        }
      }
    });

    await step('3.52 A7, A8: the news list found by the palette says Found: 7 and marks the words, readable in light and dark, axe clean', async () => {
      const [ctx, p] = await signedIn(1440, 900);
      try {
        const marks = [];
        for (const scheme of ['light', 'dark']) {
          await p.emulateMedia({ colorScheme: scheme });
          await p.goto(BASE + '/admin.php?module=news&search=' + encodeURIComponent('dvorak b352'), { waitUntil: 'networkidle' });
          const found = ((await p.locator('[data-found]').textContent()) || '').trim();
          if (found !== 'Found: 7') { throw new Error(`${scheme}: "${found}" instead of Found: 7`); }
          const marked = await p.locator('mark', { hasText: /^Dvořák$/ }).count();
          if (marked !== 7) { throw new Error(`${scheme}: ${marked} marked "Dvořák", 7 expected`); }
          const look = await looks(p.locator('mark').first());
          if (look.color === look.background) { throw new Error(`${scheme}: a marked word has the colour of its background (${look.color})`); }
          marks.push(look.background);
          if (SHOTS) { await p.screenshot({ path: `${SHOTS}/390-news-found-${scheme}.png`, fullPage: true }); }
          await axeIn(p, ['[data-found]', 'mark'], `news list ${scheme}`);
        }
      } finally {
        await ctx.close();
      }
    });

    await step('3.52 B1–B4: the header template With search – the magnifier opens the field (no script) inside the screen, the search lists the page and the news with marks, never the hidden page; 1440 and 390 px, light and dark, axe clean', async () => {
      const header = sql("SELECT IFNULL((SELECT IFNULL(HEX(stavba_koncept), 'NULL') FROM ka_casti WHERE typ = 'hlavicka' AND jazyk = '' AND varianta = ''), 'NONE')").trim();
      try {
        await visit('/admin.php?module=parts&action=templates&type=hlavicka');
        await Promise.all([page.waitForNavigation(), page.locator('input[name="sablona"][value="s-hledanim"] ~ button').click()]);
        for (const [width, height] of [[1440, 900], [390, 844]]) {
          const [ctx, p] = await signedIn(width, height);
          try {
            for (const scheme of ['light', 'dark']) {
              await p.emulateMedia({ colorScheme: scheme });
              await p.goto(BASE + '/?part=hlavicka&build=koncept', { waitUntil: 'networkidle' });
              const icon = p.locator('header .ka-hledani--ikona .ka-hledani-tl').first();
              if (!(await icon.isVisible())) { throw new Error(`${width} ${scheme}: no magnifier in the header`); }
              await icon.click();
              const field = p.locator('header .ka-hledani--ikona [popover]').first();
              if (!(await field.evaluate((el) => el.matches(':popover-open')))) { throw new Error(`${width} ${scheme}: the magnifier does not open the field`); }
              if (!(await p.evaluate(() => document.activeElement && document.activeElement.name === 'q'))) { throw new Error(`${width} ${scheme}: the opened field has no focus`); }
              const box = await field.boundingBox();
              if (!box || box.x < 0 || box.x + box.width > width + 1) { throw new Error(`${width} ${scheme}: the field leaves the screen (${JSON.stringify(box)})`); }
              if (SHOTS) { await p.screenshot({ path: `${SHOTS}/390-header-search-${width}-${scheme}.png` }); }
              await axeIn(p, ['header .ka-hledani--ikona'], `header search ${width} ${scheme}`);
              await p.keyboard.type('workshops b352');
              await Promise.all([p.waitForNavigation(), p.keyboard.press('Enter')]);
              if (!/\/search\?q=workshops(\+|%20)b352/.test(p.url())) { throw new Error(`${width} ${scheme}: the field sent the search to ${p.url()}`); }
              await p.waitForLoadState('networkidle');
              const found = ((await p.locator('.vypis-hlavicka p').first().textContent()) || '').trim();
              const results = await p.locator('.vypis-stranky > li > a').evaluateAll((as) => as.map((a) => a.textContent || ''));
              if (found !== 'Found: 2' || results.length !== 2 || !results.includes('B352 Repairs in Brno') || !results.includes('B352 Workshops for beginners')) {
                throw new Error(`${width} ${scheme}: "${found}", results ${JSON.stringify(results)}`);
              }
              const marks = await p.locator('.vypis-stranky mark').allTextContents();
              if (!marks.some((m) => /^workshop/i.test(m))) { throw new Error(`${width} ${scheme}: no matched word is marked (${JSON.stringify(marks)})`); }
              const look = await looks(p.locator('.vypis-stranky mark').first());
              if (look.color === look.background) { throw new Error(`${width} ${scheme}: a marked word has the colour of its background`); }
              if (await sideways(p) > 1) { throw new Error(`${width} ${scheme}: the results scroll the page sideways`); }
              if (SHOTS) { await p.screenshot({ path: `${SHOTS}/390-site-search-${width}-${scheme}.png`, fullPage: true }); }
              await axeIn(p, ['.vypis-hlavicka', '.vypis-stranky'], `site search ${width} ${scheme}`);
            }
          } finally {
            await ctx.close();
          }
        }
      } finally {
        if (header === 'NONE') {
          sql("DELETE FROM ka_casti WHERE typ = 'hlavicka' AND jazyk = '' AND varianta = ''");
        } else {
          sql(`UPDATE ka_casti SET stavba_koncept = ${header === 'NULL' ? 'NULL' : `UNHEX('${header}')`} WHERE typ = 'hlavicka' AND jazyk = '' AND varianta = ''`);
        }
      }
    });
  } finally {
    cleanUp();
  }
};
