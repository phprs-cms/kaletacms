// Kaleta browser steps 0410 (tools/test-browser.mjs runs the files of this folder in number order on one site – the order is
// the contract; tools/tests/README.md). 3.54: Integrations → Mailing service and Subscribers → Service settings link to
// admin.php?module=extensions#newsletter – the folded "Newsletter – connection to a mailing service" section opens and shows
// its fields (image/admin.js opens the <details> a fragment names), also when the fragment changes on the same page.
export default async (t) => {
  const { page, visit, step } = t;

  await step('3.54: Features#newsletter opens the folded mailing-service section with its fields; a later #asistent opens the assistant section', async () => {
    await visit('/admin.php?module=extensions#newsletter');
    const section = page.locator('details#newsletter');
    if (!(await section.evaluate((el) => el.open))) { throw new Error('#newsletter does not open the mailing-service section'); }
    if (!(await page.locator('#newsletter_service').isVisible())) { throw new Error('the Service field of the mailing-service section is not visible'); }
    await page.evaluate(() => { location.hash = 'asistent'; });
    await page.waitForFunction(() => document.getElementById('asistent').open, null, { timeout: 5000 });
  });
};
