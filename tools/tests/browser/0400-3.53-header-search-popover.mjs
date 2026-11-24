// Kaleta browser steps 0400 (tools/test-browser.mjs runs the files of this folder in number order on one site – the order is
// the contract; tools/tests/README.md). 3.53 C3 – the header search in the "icon" appearance (Builder\Elements\Search): the
// magnifier opens the field as a popover (no script), the field has the focus and hangs under the icon inside the screen,
// Esc and a click outside close it (the focus goes back to the magnifier), and a sent search lands on the results page –
// at 1440 and 375 px, light and dark, with the logo on the left and in the middle, and right to left (the Arabic version of
// the Crafts starter). The browser site is installed in English.
export default async (t) => {
  const { BASE, browser, context, watch, sql, step, SHOTS, AXE, WCAG, WEB_DIR, STARTER_SITES, execFileSync } = t;

  const php = (dir, code) => execFileSync('php', ['-r', `require "system/bootstrap.php"; $app = Kaleta\\Core\\App::boot(); $db = $app->db(); ${code}`], { cwd: dir, encoding: 'utf8' });
  const crafts = `${WEB_DIR}/../remeslo`;
  const newPage = async (width, height, signedIn) => {
    const ctx = await browser.newContext({ ...(signedIn ? { storageState: await context.storageState() } : {}), bypassCSP: true, viewport: { width, height }, locale: 'en-GB' });
    const p = await ctx.newPage();
    watch(p);
    return [ctx, p];
  };
  const axeIn = async (p, include, label) => {
    await p.addScriptTag({ path: AXE });
    const found = await p.evaluate(([inc, tags]) => window.axe.run({ include: inc.map((s) => [s]) }, { runOnly: { type: 'tag', values: tags }, resultTypes: ['violations'] }), [include, WCAG]);
    if (found.violations.length) { throw new Error(`axe ${label}: ` + found.violations.map((v) => `${v.id} ${v.nodes[0].target.join(' ')}`).join(', ')); }
  };
  const isOpen = (field) => field.evaluate((el) => el.matches(':popover-open'));

  // one header at one size and colour scheme: open, focus, place, Esc, click outside, send. side = the edge of the icon the field
  // hangs from ('right' left to right, 'left' right to left) – unless the field would leave the screen there
  const check = async (p, url, width, label, side) => {
    await p.goto(url, { waitUntil: 'networkidle' });
    const icon = p.locator('header .ka-hledani--ikona .ka-hledani-tl').first();
    const field = p.locator('header .ka-hledani--ikona [popover]').first();
    if (!(await icon.isVisible())) { throw new Error(`${label}: no magnifier in the header`); }
    if (await field.isVisible()) { throw new Error(`${label}: the field shows before the magnifier is used`); }
    await icon.click();
    if (!(await isOpen(field))) { throw new Error(`${label}: the magnifier does not open the field`); }
    if (!(await p.evaluate(() => document.activeElement && document.activeElement.name === 'q'))) { throw new Error(`${label}: the opened field has no focus`); }
    const [i, f] = [await icon.boundingBox(), await field.boundingBox()];
    if (!i || !f) { throw new Error(`${label}: no box for the icon or the field`); }
    if (f.x < 0 || f.x + f.width > width + 1) { throw new Error(`${label}: the field leaves the screen (${JSON.stringify(f)})`); }
    if (f.y < i.y + i.height - 1 || f.y > i.y + i.height + 16) { throw new Error(`${label}: the field does not hang right under the icon (icon ${JSON.stringify(i)}, field ${JSON.stringify(f)})`); }
    if (f.x > i.x + i.width || f.x + f.width < i.x) { throw new Error(`${label}: the field is not under the icon (icon ${JSON.stringify(i)}, field ${JSON.stringify(f)})`); }
    const fits = side === 'right' ? i.x + i.width - f.width >= 0 : i.x + f.width <= width;
    const edge = side === 'right' ? Math.abs((f.x + f.width) - (i.x + i.width)) : Math.abs(f.x - i.x);
    if (fits && edge > 2) { throw new Error(`${label}: the field does not hang from the ${side} edge of the icon (icon ${JSON.stringify(i)}, field ${JSON.stringify(f)})`); }
    const look = await field.evaluate((el) => ({ color: getComputedStyle(el).color, background: getComputedStyle(el).backgroundColor }));
    if (look.color === look.background || look.background === 'rgba(0, 0, 0, 0)') { throw new Error(`${label}: the field has no own background (${JSON.stringify(look)})`); }
    if (SHOTS) { await p.screenshot({ path: `${SHOTS}/400-header-search-${label.replace(/[^a-z0-9]+/gi, '-')}.png` }); }
    await axeIn(p, ['header .ka-hledani--ikona'], label);
    await p.keyboard.press('Escape');
    if (await isOpen(field)) { throw new Error(`${label}: Esc does not close the field`); }
    if (!(await icon.evaluate((el) => el === document.activeElement))) { throw new Error(`${label}: after Esc the focus is not back on the magnifier`); }
    await icon.click();
    await p.mouse.click(Math.round(width / 2), Math.round(i.y + i.height + f.height + 120));
    if (await isOpen(field)) { throw new Error(`${label}: a click outside does not close the field`); }
    await icon.click();
    const action = new URL(await p.locator('header form.ka-hledani--ikona').first().getAttribute('action'), url).pathname;
    await p.keyboard.type('popover c353');
    await Promise.all([p.waitForNavigation(), p.keyboard.press('Enter')]);
    const sent = new URL(p.url());
    if (sent.pathname !== action || !/\/(search|hledani)$/.test(sent.pathname) || sent.searchParams.get('q') !== 'popover c353') { throw new Error(`${label}: the field sent the search to ${p.url()} (form action ${action})`); }
  };

  // the header draft of the main site: the template With search (logo left), then the logo in the middle with the magnifier
  // under the menu; the old draft is put back at the end
  const header = sql("SELECT IFNULL((SELECT IFNULL(HEX(stavba_koncept), 'NULL') FROM ka_casti WHERE typ = 'hlavicka' AND jazyk = '' AND varianta = ''), 'NONE')").trim();
  const draft = (centred) => php(WEB_DIR, `$b = Kaleta\\Core\\Language::runWith("en", function () use ($db): array { $b = Kaleta\\Builder\\PartTemplates::build("hlavicka", "${centred ? 'na-stred' : 's-hledanim'}", "en") ?? [];
    if (${centred ? 'true' : 'false'}) { $add = function (array &$n) use (&$add): bool { if (($n["typ"] ?? "") === "kontejner") { $n["deti"][] = Kaleta\\Builder\\Build::fresh("hledani", ["podoba" => "ikona"]); return true; }
      foreach (array_keys($n["deti"] ?? []) as $i) { if ($add($n["deti"][$i])) { return true; } } return false; }; foreach (array_keys($b["deti"]) as $i) { if ($add($b["deti"][$i])) { break; } } }
    return $b; }); // the English site: the added element takes its English defaults (the Search button)
    [$b] = Kaleta\\Builder\\Build::sanitize($b); $json = Kaleta\\Builder\\Build::toJson($b);
    if ($db->value("SELECT COUNT(*) FROM {casti} WHERE typ = 'hlavicka' AND jazyk = '' AND varianta = ''")) { $db->update("casti", ["stavba_koncept" => $json], ["typ" => "hlavicka", "jazyk" => "", "varianta" => ""]); }
    else { $db->insert("casti", ["typ" => "hlavicka", "jazyk" => "", "varianta" => "", "nazev" => "Header", "stavba_koncept" => $json]); }`);
  try {
    for (const centred of [false, true]) {
      await step(`3.53 C3: the header search (${centred ? 'logo in the middle' : 'logo on the left'}) – the magnifier opens the field with the focus right under it inside the screen, Esc and a click outside close it, the search lands on the results page; 1440 and 375 px, light and dark, axe clean`, async () => {
        draft(centred);
        for (const [width, height] of [[1440, 900], [375, 812]]) {
          const [ctx, p] = await newPage(width, height, true);
          try {
            for (const scheme of ['light', 'dark']) {
              await p.emulateMedia({ colorScheme: scheme });
              await check(p, `${BASE}/?part=hlavicka&build=koncept`, width, `${centred ? 'centred' : 'left'} ${width} ${scheme}`, 'right');
            }
          } finally {
            await ctx.close();
          }
        }
      });
    }
  } finally {
    if (header === 'NONE') {
      sql("DELETE FROM ka_casti WHERE typ = 'hlavicka' AND jazyk = '' AND varianta = ''");
    } else {
      sql(`UPDATE ka_casti SET stavba_koncept = ${header === 'NULL' ? 'NULL' : `UNHEX('${header}')`} WHERE typ = 'hlavicka' AND jazyk = '' AND varianta = ''`);
    }
  }

  // right to left: the Arabic version of the Crafts starter gets a published header With search for a moment (a visitor's view)
  await step('3.53 C3: right to left (Arabic) – the field hangs from the left edge of the magnifier inside the screen, Esc closes it, the search stays in the Arabic version; 1440 and 375 px, light and dark, axe clean', async () => {
    php(crafts, `$b = Kaleta\\Core\\Language::runWith("ar", fn () => Kaleta\\Builder\\PartTemplates::build("hlavicka", "s-hledanim", "ar") ?? []); [$b] = Kaleta\\Builder\\Build::sanitize($b);
      $db->run("DELETE FROM {casti} WHERE typ = 'hlavicka' AND jazyk = 'ar' AND varianta = ''");
      $db->insert("casti", ["typ" => "hlavicka", "jazyk" => "ar", "varianta" => "", "nazev" => "C353", "stavba" => Kaleta\\Builder\\Build::toJson($b)]); Kaleta\\Core\\PageCache::clear();`);
    try {
      for (const [width, height] of [[1440, 900], [375, 812]]) {
        const [ctx, p] = await newPage(width, height, false);
        try {
          for (const scheme of ['light', 'dark']) {
            await p.emulateMedia({ colorScheme: scheme });
            await check(p, `${STARTER_SITES.remeslo}/ar/rtl-test`, width, `rtl ${width} ${scheme}`, 'left');
            if (!new URL(p.url()).pathname.startsWith('/ar/')) { throw new Error(`rtl ${width} ${scheme}: the search left the Arabic version (${p.url()})`); }
          }
        } finally {
          await ctx.close();
        }
      }
    } finally {
      php(crafts, `$db->run("DELETE FROM {casti} WHERE typ = 'hlavicka' AND jazyk = 'ar' AND nazev = 'C353'"); Kaleta\\Core\\PageCache::clear();`);
    }
  });
};
