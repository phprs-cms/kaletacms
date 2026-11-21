// Kaleta browser steps 0020 (tools/test-browser.mjs runs the files of this folder in number order on one site – the order is
// the contract; tools/tests/README.md). Moved verbatim from the single file in 3.49 P1.
export default async (t) => {
  const { sql, page, step, visit } = t;

  for (const url of ['/admin.php', '/admin.php?module=pages', '/admin.php?module=pages&action=new', '/admin.php?module=pages&action=edit&id=1',
    '/admin.php?module=news', '/admin.php?module=collections', '/admin.php?module=categories', '/admin.php?module=tags', '/admin.php?module=media',
    '/admin.php?module=appearance', '/admin.php?module=parts', '/admin.php?module=components', '/admin.php?module=popups', '/admin.php?module=users',
    '/admin.php?module=roles', '/admin.php?module=stats', '/admin.php?module=redirects', '/admin.php?module=changelog', '/admin.php?module=transfer',
    '/admin.php?module=extensions', '/admin.php?module=enquiries', '/admin.php?module=subscribers', '/admin.php?module=newsletters', '/admin.php?module=settings',
    '/admin.php?module=settings&tab=seo', '/admin.php?module=settings&tab=analytics', '/admin.php?module=settings&tab=backups', '/admin.php?module=status', '/admin.php?action=account',
    '/admin.php?module=bookings', '/admin.php?module=whistleblowing']) {
    await step(`open ${url}`, () => visit(url));
  }

  await step('3.19.2 a module whose feature is off says "switched off" with a link to Features, not "no access"', async () => {
    // every feature is on in this install (the fleet console too), so Redirects is switched off for the check and on again;
    // the administrator used to be sent to Users by a 403 "no access"
    sql("UPDATE ka_nastaveni SET hodnota = REPLACE(hodnota, 'presmerovani', '') WHERE promenna = 'extensions'");
    try {
      await visit('/admin.php?module=redirects');
      const text = (await page.locator('.hlaska-chyba').first().textContent({ timeout: 5000 }).catch(() => '')) || '';
      if (!/switched off/.test(text) || /do not have access/.test(text)) { throw new Error(`module=redirects with the feature off: "${text.trim()}"`); }
      if (!(await page.locator('.hlaska-chyba a[href*="module=extensions"]').count())) { throw new Error('"Features" in the message is not a link'); }
    } finally {
      sql("UPDATE ka_nastaveni SET hodnota = CONCAT(hodnota, ',presmerovani') WHERE promenna = 'extensions'");
    }
    // C3: the Statistics empty text names the screen Integrations and the path is a link
    await visit('/admin.php?module=stats');
    const hint = page.locator('p', { hasText: 'Search Console' }).first();
    if (!(await hint.count()) || /Connections/.test(await hint.textContent())) { throw new Error('the Search engines text still says Connections or is missing'); }
    if (!(await hint.locator('a[href*="module=connectors"]').count())) { throw new Error('"Administration → Integrations" in Statistics is not a link'); }
  });

  // 3.20 (C1, C11): the Pages list has status tabs with their counts (All / Published / Hidden / Unpublished changes / Trash,
  // a tab only when it has rows), the dashboard tiles lead to the matching tab, and an Edited column (when and who) – on a
  // phone a card line like every other column
  await step('3.20 pages: status tabs with counts, the tile leads to Unpublished changes, the Edited column is a card line at 390 px', async () => {
    const id = sql("SELECT ids FROM ka_stranky WHERE stavba IS NOT NULL AND stavba_koncept IS NULL AND smazano IS NULL ORDER BY ids LIMIT 1").trim();
    if (!id) { throw new Error('no built starter page to give a draft'); }
    sql(`UPDATE ka_stranky SET stavba_koncept = stavba WHERE ids = ${id}`); // one page with unpublished changes
    try {
      await visit('/admin.php');
      if (!(await page.locator('a[href*="module=pages&status=published"]').count())) { throw new Error('the "Published pages" tile does not lead to status=published'); }
      const tile = page.locator('a[href*="module=pages&status=draft"]').first();
      if (!(await tile.count())) { throw new Error('the "Pages with unpublished changes" tile does not lead to status=draft'); }
      await Promise.all([page.waitForNavigation(), tile.click()]);
      const active = (await page.locator('nav.zalozky a.aktivni').textContent()) || '';
      if (!/Unpublished changes \(1\)/.test(active)) { throw new Error(`the open tab is "${active.trim()}", expected "Unpublished changes (1)"`); }
      const rows = await page.locator('table.vypis tbody tr').count();
      if (rows !== 1) { throw new Error(`${rows} rows under Unpublished changes, expected 1`); }
      const tabs = (await page.locator('nav.zalozky a').allTextContents()).map((t) => t.trim());
      for (const name of ['All', 'Published', 'Hidden']) {
        if (!tabs.some((t) => new RegExp(`^${name} \\([1-9]\\d*\\)$`).test(t))) { throw new Error(`no "${name} (n)" tab: ${tabs.join(' | ')}`); }
      }
      if (tabs.some((t) => /^Trash/.test(t))) { throw new Error('a Trash tab without pages in the trash'); }
      if (!(await page.locator('table.vypis th', { hasText: 'Edited' }).count())) { throw new Error('no Edited column'); }
      const edited = (await page.locator('table.vypis tbody tr td.cislo').first().textContent()) || '';
      if (!/\d{4}/.test(edited)) { throw new Error(`the Edited cell of a starter page is empty: "${edited.trim()}" – the installer did not stamp zmeneno`); }
      // 390 px: a row is a card, the Edited cell is a block with its column label, nothing scrolls sideways
      await page.setViewportSize({ width: 390, height: 844 });
      await visit('/admin.php?module=pages');
      const cell = page.locator('table.vypis-karty td[data-popisek="Edited"]').first();
      if (!(await cell.count())) { throw new Error('the Edited cell has no card label'); }
      if ((await cell.evaluate((td) => getComputedStyle(td).display)) !== 'block') { throw new Error('the Edited cell is not a card line at 390 px'); }
      if (await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1)) { throw new Error('the pages list scrolls sideways at 390 px'); }
    } finally {
      await page.setViewportSize({ width: 1440, height: 900 });
      sql(`UPDATE ka_stranky SET stavba_koncept = NULL WHERE ids = ${id}`);
    }
  });
};
