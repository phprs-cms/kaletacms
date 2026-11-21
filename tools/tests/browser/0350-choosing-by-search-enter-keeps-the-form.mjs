// Kaleta browser steps 0350 (tools/test-browser.mjs runs the files of this folder in number order on one site – the order is
// the contract; tools/tests/README.md). 3.49 review C9: Enter in a search picker with no highlighted row never sends the
// surrounding form – "type a few letters, press Enter" on the deal form keeps the page, the typed search and the list.
export default async (t) => {
  const { MYSQL, sql, page, step, visit } = t;

  await step('3.49 review C9: the deal form – Enter after typing a customer\'s name sends nothing; a row chosen with the arrows and Enter does not send either; the form then saves with it', async () => {
    if (!MYSQL.length) { throw new Error('MYSQL_JSON is not set'); }
    const extensions = sql("SELECT IFNULL((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'extensions'), '')").trim();
    sql(`REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('extensions', '${extensions === '' ? 'novinky,poptavky,statistika,presmerovani,claude' : extensions},customers,pipeline')`);
    sql("DELETE FROM ka_deals WHERE title LIKE '%R449%'; DELETE FROM ka_contacts WHERE email_norm = 'zdena-r449@example.com'");
    const zdena = Number(sql("INSERT INTO ka_contacts (email, email_norm, name, source, created_at, updated_at, last_activity_at) VALUES ('zdena-r449@example.com', 'zdena-r449@example.com', 'Zdena R449', 'manual', NOW(), NOW(), NOW()); SELECT LAST_INSERT_ID();").trim());
    try {
      await page.setViewportSize({ width: 1440, height: 900 });
      await visit('/admin.php?module=pipeline&action=new');
      await page.fill('#obchod-novy-titulek', 'Enter R449');
      const field = page.locator('#obchod-novy-zakaznik');
      await field.click();
      await field.type('Zdena', { delay: 20 });
      await page.waitForFunction((id) => [...document.querySelectorAll(`#${id} [role="option"]`)].some((o) => o.textContent.includes('Zdena R449')), await field.getAttribute('aria-controls'), { timeout: 6000 });
      await page.waitForTimeout(300);
      if ((await field.getAttribute('aria-activedescendant')) !== null) { throw new Error('a row is highlighted before the arrows – the step tests nothing'); }
      await field.press('Enter');
      await page.waitForTimeout(800);
      if (!page.url().includes('action=new') || sql("SELECT COUNT(*) FROM ka_deals WHERE title = 'Enter R449'").trim() !== '0') { throw new Error('Enter in the search sent the form – a deal was created without the customer'); }
      if ((await field.inputValue()) !== 'Zdena' || (await page.locator(`#${await field.getAttribute('aria-controls')}`).isHidden())) { throw new Error('Enter dropped the typed search or closed the list'); }
      // the arrows highlight the row, Enter chooses it – the form still waits for its own button
      for (let i = 0; i < 10; i++) {
        await field.press('ArrowDown');
        const active = await field.getAttribute('aria-activedescendant');
        if (active && (await page.locator(`#${active}`).innerText()).includes('Zdena R449')) { break; }
      }
      await field.press('Enter');
      await page.waitForTimeout(300);
      if ((await page.locator('#obchod-novy-zakaznik-hodnota').inputValue()) !== String(zdena) || !page.url().includes('action=new')) { throw new Error('the highlighted row was not chosen, or the form was sent'); }
      await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.locator('button[type="submit"]', { hasText: 'Create the deal' }).click()]);
      if (sql("SELECT IFNULL(contact_id, 'null') FROM ka_deals WHERE title = 'Enter R449'").trim() !== String(zdena)) { throw new Error('the deal was not saved with the chosen customer'); }
    } finally {
      sql(`DELETE FROM ka_deals WHERE title LIKE '%R449%'; DELETE FROM ka_contacts WHERE id = ${zdena}; REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('extensions', '${extensions}'); DELETE FROM ka_nastaveni WHERE promenna = 'extensions' AND hodnota = ''`);
    }
  });
};
