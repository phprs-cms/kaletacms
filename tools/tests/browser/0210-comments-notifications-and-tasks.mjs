// Kaleta browser steps 0210 (tools/test-browser.mjs runs the files of this folder in number order on one site – the order is
// the contract; tools/tests/README.md). Moved verbatim from the single file in 3.49 P1.
export default async (t) => {
  const { BASE, SHOTS, MYSQL, sql, browser, context, page, watch, step, visit, builderIdle } = t;

  // 3.26 (A): the team's thread in the page settings – "@" lists the colleagues who may open Pages (arrows and Enter insert the
  // sign-in name), the comment shows the name, Edit in place, Delete through the confirmation and a placeholder line; the mention
  // tinted in light and dark mode, no sideways scroll at 390 px; the builder's Team tab next to the preview-link comments
  await step('3.26 comments: page thread with an @mention, edit, delete; the builder Team tab (1440 and 390 px, light/dark)', async () => {
    if (!MYSQL.length) { throw new Error('MYSQL_JSON is not set'); }
    sql(`DELETE FROM ka_stranky WHERE seo_link = 'comments-326'; INSERT INTO ka_stranky (seo_link, titulek, text, v_menu, zobrazit) VALUES ('comments-326', 'Comments 326', '<p>Text</p>', 0, 0);
    DELETE FROM ka_uzivatele WHERE user = 'jana-326'; INSERT INTO ka_uzivatele (user, password, jmeno, email, admin, posledni_login, potvrzeno) VALUES ('jana-326', '', 'Jana Nowak', '', 2, NOW(), NOW())`);
    const id = sql("SELECT ids FROM ka_stranky WHERE seo_link = 'comments-326'").trim();
    try {
      await page.setViewportSize({ width: 1440, height: 900 });
      await visit(`/admin.php?module=pages&action=edit&id=${id}`);
      const field = page.locator('#novy-komentar');
      await field.click();
      await field.pressSequentially('Please check this, @now');
      const option = page.locator('.zminky-navrhy [role=option]', { hasText: 'Jana Nowak' });
      await option.waitFor({ timeout: 3000 });
      if ((await field.getAttribute('aria-activedescendant')) !== await option.getAttribute('id')) { throw new Error('the first match is not the active option'); }
      if (SHOTS) { await page.screenshot({ path: `${SHOTS}/326-mention-list.png` }); }
      await page.keyboard.press('Enter');
      if ((await field.inputValue()) !== 'Please check this, @jana-326 ') { throw new Error(`Enter inserted “${await field.inputValue()}”`); }
      if (await page.locator('.zminky-navrhy [role=option]').count()) { throw new Error('the list stays open after the pick'); }
      await field.pressSequentially('thanks.');
      await Promise.all([page.waitForNavigation(), page.locator('form[data-comment-form] button[type="submit"]').click()]);
      await page.locator('.hlaska', { hasText: 'The comment was added.' }).waitFor();
      const mention = page.locator('#comments .zminka').first();
      if ((await mention.textContent()) !== '@Jana Nowak') { throw new Error(`the mention reads “${await mention.textContent()}”`); }
      const light = await mention.evaluate((n) => getComputedStyle(n).backgroundColor);
      await page.emulateMedia({ colorScheme: 'dark' });
      const dark = await mention.evaluate((n) => [getComputedStyle(n).backgroundColor, getComputedStyle(n).color]);
      if (dark[0] === light || dark[0] === dark[1]) { throw new Error(`the mention is not tinted for dark mode (${dark.join(' on ')})`); }
      if (SHOTS) { await page.locator('#comments').scrollIntoViewIfNeeded(); await page.screenshot({ path: `${SHOTS}/326-page-thread-dark.png` }); }
      await page.emulateMedia({ colorScheme: 'light' });
      // edit in place
      await page.locator('#comments .komentar-upravit summary').first().click();
      await page.locator('#comments form[data-comment-edit] textarea').first().fill('Prices checked, @jana-326.');
      await Promise.all([page.waitForNavigation(), page.locator('#comments form[data-comment-edit] button[type="submit"]').first().click()]);
      await page.locator('.hlaska', { hasText: 'The comment was saved.' }).waitFor();
      if (!/Prices checked, @Jana Nowak\./.test(await page.locator('#comments .vlakno-komentar').first().textContent()) || !(await page.locator('#comments .vlakno-komentar', { hasText: 'edited' }).count())) { throw new Error('the edit is not shown with its mark'); }
      // a phone: the thread and the composer fit
      await page.setViewportSize({ width: 390, height: 844 });
      await visit(`/admin.php?module=pages&action=edit&id=${id}`);
      const sideways = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
      if (sideways > 0) { throw new Error(`${sideways} px of sideways scroll at 390 px`); }
      if (SHOTS) { await page.locator('#comments').scrollIntoViewIfNeeded(); await page.screenshot({ path: `${SHOTS}/326-page-thread-phone.png` }); }
      await page.setViewportSize({ width: 1440, height: 900 });
      await visit(`/admin.php?module=pages&action=edit&id=${id}`);
      // delete: the confirmation, then a quiet line
      await page.locator('#comments form[data-comment-delete] button').first().click();
      await page.locator('dialog.potvrzeni[open]').waitFor();
      await Promise.all([page.waitForNavigation(), page.locator('dialog.potvrzeni [data-ano]').click()]);
      if ((await page.locator('#comments [data-comment-deleted]').count()) !== 1 || (await page.locator('#comments .zminka').count()) !== 0) { throw new Error('the deleted comment is not a placeholder'); }
      // the builder: Comments in the ⋯ menu, the Team tab with the thread and a composer with the same "@" list
      await visit(`/admin.php?module=pages&action=builder&id=${id}`);
      await builderIdle();
      await page.locator('.st-lista .st-lista-vice').click();
      await page.locator('#st-lista-vice button', { hasText: 'Comments' }).click();
      const dialog = page.locator('dialog.st-komentare-dialog[open]');
      await dialog.waitFor();
      if ((await dialog.locator('[role=tab][aria-selected="true"]').textContent()) !== 'Team') { throw new Error('the Team tab is not the open one without preview-link comments'); }
      if ((await dialog.locator('.st-komentare-tym [data-tym-komentar]').count()) !== 1) { throw new Error('the Team tab does not list the deleted comment'); }
      const composer = dialog.locator('.st-tym-novy textarea');
      await composer.click();
      await composer.pressSequentially('From the builder @jan');
      await dialog.locator('.zminky-navrhy [role=option]', { hasText: 'Jana Nowak' }).waitFor({ timeout: 3000 });
      await page.keyboard.press('Enter');
      await dialog.locator('.st-tym .st-tl-hlavni', { hasText: 'Add comment' }).click();
      await dialog.locator('.st-komentare-tym .zminka', { hasText: '@Jana Nowak' }).waitFor({ timeout: 5000 });
      if ((await composer.inputValue()) !== '') { throw new Error('the composer keeps the sent text'); }
      if (SHOTS) { await page.screenshot({ path: `${SHOTS}/326-builder-team.png` }); }
      await page.emulateMedia({ colorScheme: 'dark' });
      if (SHOTS) { await page.screenshot({ path: `${SHOTS}/326-builder-team-dark.png` }); }
      await page.emulateMedia({ colorScheme: 'light' });
      await dialog.locator('[role=tab]', { hasText: 'Preview link' }).click();
      if (!(await dialog.locator('#st-komentare-host').isVisible()) || await dialog.locator('#st-komentare-tym').isVisible()) { throw new Error('the tabs do not switch'); }
      await dialog.locator('footer button', { hasText: 'Close' }).click();
    } finally {
      await page.emulateMedia({ colorScheme: 'light' });
      await page.setViewportSize({ width: 1440, height: 900 });
      sql(`DELETE FROM ka_comments WHERE target = 'page:${Number(id)}'; DELETE FROM ka_stranky WHERE ids = ${Number(id)}; DELETE FROM ka_uzivatele WHERE user = 'jana-326'`);
    }
  });
  // 3.26 (agent B): notifications – the bell with the unread count in the header bar (never in the left menu), the
  // Notifications screen (who, what, where, when; the unread marked), opening one marks it read and leads to its place,
  // Mark all as read empties the bell; My account offers the e-mail choice. 1440 and 390 px, light and dark.
  await step('3.26 notifications: the bell, the Notifications screen, open and mark all read, the e-mail choice (1440 and 390 px, light and dark)', async () => {
    if (!MYSQL.length) { throw new Error('MYSQL_JSON is not set'); }
    await page.setViewportSize({ width: 1440, height: 900 });
    const admin = Number(sql("SELECT idu FROM ka_uzivatele WHERE user = 'admin'").trim());
    const pageId = Number(sql("INSERT INTO ka_stranky (seo_link, titulek, text, zobrazit, v_menu) VALUES ('bell-326', 'Price list 326', '', 0, 0); SELECT LAST_INSERT_ID();").trim());
    sql(`DELETE FROM ka_notifications WHERE user_id = ${admin};
    INSERT INTO ka_notifications (user_id, kind, target, actor_id, actor_name, excerpt, created_at) VALUES
    (${admin}, 'comment', 'enquiry:999999', NULL, 'Wendy Work', 'This one is gone', NOW() - INTERVAL 3 DAY),
    (${admin}, 'review_due', 'page:${pageId}', NULL, '', '', NOW() - INTERVAL 1 DAY),
    (${admin}, 'assigned', 'page:${pageId}', NULL, 'Wendy Work', '', NOW() - INTERVAL 2 HOUR),
    (${admin}, 'mention', 'page:${pageId}', NULL, 'Wendy Work', 'Please check the prices before Friday, the new supplier list is in the shared folder.', NOW() - INTERVAL 5 MINUTE)`); // newest last: the screen lists by id, newest first
    try {
      await visit('/admin.php');
      const bell = page.locator('.loginprouzek a.zvonek');
      if (!(await bell.isVisible())) { throw new Error('no bell in the header bar'); }
      if ((await bell.locator('.zvonek-pocet').textContent()).trim() !== '4') { throw new Error(`the bell counts ${await bell.locator('.zvonek-pocet').textContent()}`); }
      if ((await bell.getAttribute('aria-label')) !== 'Notifications – 4 unread') { throw new Error(`the bell is named ${await bell.getAttribute('aria-label')}`); }
      if (await page.locator('#menu .zvonek, #menu a[href*="action=inbox"]').count() !== 0) { throw new Error('the notifications are in the left menu'); }
      await Promise.all([page.waitForNavigation(), bell.click()]);
      if ((await page.locator('main h1').textContent()).trim() !== 'Notifications') { throw new Error('the bell does not open the Notifications screen'); }
      if ((await bell.getAttribute('aria-current')) !== 'page') { throw new Error('the bell is not marked as the current screen'); }
      const rows = page.locator('[data-inbox] > li');
      if (await rows.count() !== 4 || await page.locator('[data-inbox] .upozorneni-neprectene').count() !== 4) { throw new Error(`the screen lists ${await rows.count()} notifications`); }
      const first = await rows.first().innerText();
      if (!first.includes('Wendy Work mentioned you') || !first.includes('Page “Price list 326”') || !first.includes('Please check the prices')) { throw new Error(`the first row says ${first}`); }
      if (!(await rows.nth(1).innerText()).includes('Wendy Work made you responsible') || !(await rows.nth(2).innerText()).includes('The review date has come')) { throw new Error('the assignment or the review row is wrong'); }
      if (!(await rows.nth(3).innerText()).includes('(no longer available)') || await rows.nth(3).locator('a').count() !== 0) { throw new Error('a place that is gone still has a link'); }
      if (SHOTS) { await page.screenshot({ path: `${SHOTS}/notifications-desktop.png`, fullPage: true }); }
      // light and dark, desktop and phone: no sideways scroll; on the phone the bell sits in the header row without covering the logo
      const state = await context.storageState();
      for (const scheme of ['light', 'dark']) {
        for (const [label, width, height] of [['desktop', 1440, 900], ['phone', 390, 844]]) {
          const other = await browser.newContext({ viewport: { width, height }, locale: 'en-GB', colorScheme: scheme, storageState: state, ...(width < 500 ? { isMobile: true, hasTouch: true } : {}) });
          const p2 = await other.newPage(); watch(p2);
          try {
            await p2.goto(`${BASE}/admin.php?action=inbox`, { waitUntil: 'networkidle' });
            const wide = await p2.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
            if (wide > 0) { throw new Error(`${wide} px of sideways scroll (${label}, ${scheme})`); }
            const box = await p2.locator('.loginprouzek a.zvonek').boundingBox();
            if (!box || box.x < 0 || box.x + box.width > width || box.y > 64) { throw new Error(`the bell is not in the header row (${label}, ${scheme}): ${JSON.stringify(box)}`); }
            if (width < 500) {
              const logo = await p2.locator('.hlavicka .kaleta-logo').boundingBox();
              const firstButton = await p2.locator('.loginprouzek > :first-child').boundingBox();
              if (!logo || !firstButton || logo.x + logo.width > firstButton.x) { throw new Error(`the header buttons cover the logo (${scheme}): ${JSON.stringify([logo, firstButton])}`); }
            }
            const colours = await p2.locator('.upozorneni-neprectene').first().evaluate((el) => [getComputedStyle(el).backgroundColor, getComputedStyle(el.querySelector('.upozorneni-co')).color]);
            if (colours[0] === colours[1]) { throw new Error(`the text has the colour of its card (${label}, ${scheme})`); }
            if (SHOTS) { await p2.screenshot({ path: `${SHOTS}/notifications-${label}-${scheme}.png`, fullPage: true }); }
          } finally {
            await other.close();
          }
        }
      }
      // opening one marks it read and leads to its place
      const mention = sql(`SELECT id FROM ka_notifications WHERE user_id = ${admin} AND kind = 'mention'`).trim();
      await Promise.all([page.waitForNavigation(), rows.first().locator('a').click()]);
      if (!page.url().includes(`module=pages&action=edit&id=${pageId}`)) { throw new Error(`the notification leads to ${page.url()}`); }
      if (sql(`SELECT read_at IS NOT NULL FROM ka_notifications WHERE id = ${Number(mention)}`).trim() !== '1') { throw new Error('opening did not mark it read'); }
      if ((await page.locator('.loginprouzek .zvonek-pocet').textContent()).trim() !== '3') { throw new Error('the bell did not count one less'); }
      await visit('/admin.php?action=inbox');
      await Promise.all([page.waitForNavigation(), page.locator('button[name="read_all"]').click()]);
      if (await page.locator('.loginprouzek .zvonek-pocet').count() !== 0 || await page.locator('[data-inbox] .upozorneni-neprectene').count() !== 0) { throw new Error('Mark all as read left something unread'); }
      if (await page.locator('button[name="read_all"]').count() !== 0) { throw new Error('Mark all as read is offered with nothing unread'); }
      if (!(await page.locator('.hlaska-ok', { hasText: 'All notifications are marked as read.' }).isVisible())) { throw new Error('no message after Mark all as read'); }
      // My account: the e-mail choice
      await visit('/admin.php?action=account#notifications');
      const choice = page.locator('#notifications');
      if (await choice.locator('input[name="notify_email"]').count() !== 3 || !(await choice.locator('input[value="instant"]').isChecked())) { throw new Error('My account does not offer the three choices with Right away chosen'); }
      await choice.locator('label.karta-volba', { hasText: 'Once a day' }).click();
      if (SHOTS) { await choice.screenshot({ path: `${SHOTS}/notifications-account.png` }); }
      await Promise.all([page.waitForNavigation(), choice.locator('input[type="submit"]').click()]);
      if (sql(`SELECT notify_email FROM ka_uzivatele WHERE idu = ${admin}`).trim() !== 'daily') { throw new Error('the choice was not saved'); }
      if (!(await page.locator('#notifications input[value="daily"]').isChecked())) { throw new Error('the saved choice is not shown'); }
    } finally {
      sql(`DELETE FROM ka_notifications WHERE user_id = ${admin}; DELETE FROM ka_stranky WHERE ids = ${pageId}; UPDATE ka_uzivatele SET notify_email = 'instant' WHERE idu = ${admin}`);
    }
  });
  // 3.27 (agent A): tasks in My work – the person's tasks come first, grouped overdue / today / upcoming / later, each row with
  // Done, Tomorrow and In 3 days; Done and Tomorrow work; the administrator has the Team tab, whose "Not assigned" lists a task
  // handed to nobody (the nobody row links there) with Reassign to… and Save on screen; reassigning it works. 1440 and 390 px,
  // light and dark.
  await step('3.27 My work: the tasks first by group, Done and snooze, the Team tab with Not assigned and Reassign (1440 and 390 px, light and dark)', async () => {
    if (!MYSQL.length) { throw new Error('MYSQL_JSON is not set'); }
    await page.setViewportSize({ width: 1440, height: 900 });
    const admin = Number(sql("SELECT idu FROM ka_uzivatele WHERE user = 'admin'").trim());
    const pageId = Number(sql("INSERT INTO ka_stranky (seo_link, titulek, text, zobrazit, v_menu) VALUES ('tasks-327', 'Price list 327', '', 0, 0); SELECT LAST_INSERT_ID();").trim());
    const day = (offset) => `CONCAT(CURDATE() + INTERVAL ${offset} DAY, ' 09:00:00')`;
    sql(`DELETE FROM ka_tasks WHERE assignee_id = ${admin};
    INSERT INTO ka_tasks (title, target, assignee_id, created_by, due_at, created_at, updated_at) VALUES
    ('Call the supplier back about the delayed delivery of the oak boards', NULL, ${admin}, ${admin}, ${day(-2)}, NOW(), NOW()),
    ('Review the price list before the newsletter goes out', 'page:${pageId}', ${admin}, ${admin}, CONCAT(CURDATE(), ' 23:30:00'), NOW(), NOW()),
    ('Send the offer', NULL, ${admin}, ${admin}, ${day(3)}, NOW(), NOW()),
    ('Plan the spring campaign', NULL, ${admin}, ${admin}, NULL, NOW(), NOW()),
    ('Order the trade fair banner left by a colleague who has gone', NULL, NULL, ${admin}, ${day(1)}, NOW(), NOW())`);
    try {
      await visit('/admin.php?module=my_work');
      const first = page.locator('main section.moje-prace').first();
      if ((await first.getAttribute('data-list')) !== 'tasks') { throw new Error('the tasks are not the first list of My work'); }
      const groups = await page.locator('[data-list="tasks"] h3[data-group]').evaluateAll((els) => els.map((el) => el.dataset.group));
      if (groups.join(',') !== 'overdue,today,upcoming,later') { throw new Error(`the groups are ${groups.join(',')}`); }
      const today = page.locator('[data-list="tasks"] tr[data-task]', { hasText: 'Review the price list' });
      if (!(await today.locator('a', { hasText: 'Page “Price list 327”' }).isVisible())) { throw new Error('the task does not link to its page'); }
      if (await page.locator('[data-list="tasks"] tr[data-task] td.akce > button').count() !== 12) { throw new Error('not every task has Done, Tomorrow and In 3 days'); }
      if (await page.locator('.zalozky a', { hasText: 'Team' }).count() !== 1) { throw new Error('no Team tab for the administrator'); }
      if (SHOTS) { await page.screenshot({ path: `${SHOTS}/my-work-tasks-desktop.png`, fullPage: true }); }
      const state = await context.storageState();
      for (const scheme of ['light', 'dark']) {
        for (const [label, width, height] of [['desktop', 1440, 900], ['phone', 390, 844]]) {
          const other = await browser.newContext({ viewport: { width, height }, locale: 'en-GB', colorScheme: scheme, storageState: state, ...(width < 500 ? { isMobile: true, hasTouch: true } : {}) });
          const p2 = await other.newPage(); watch(p2);
          try {
            for (const url of ['/admin.php?module=my_work', '/admin.php?module=my_work&action=team']) {
              await p2.goto(`${BASE}${url}`, { waitUntil: 'networkidle' });
              const wide = await p2.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
              if (wide > 0) { throw new Error(`${wide} px of sideways scroll (${url}, ${label}, ${scheme})`); }
            }
            // the Team tab (the last URL above): the task handed to nobody under Not assigned, its person list and Save on screen
            const orphan = p2.locator('[data-list="unassigned"] tr[data-task]', { hasText: 'Order the trade fair banner' });
            if (!(await orphan.isVisible()) || await p2.locator('[data-list="team"] a[href="#unassigned"]').count() < 1) { throw new Error(`Not assigned or its link is missing (${label}, ${scheme})`); }
            for (const control of [orphan.locator('select[name="assignee_id"]'), orphan.getByRole('button', { name: 'Save', exact: true })]) {
              const box = await control.boundingBox();
              if (!box || box.x < 0 || box.x + box.width > width) { throw new Error(`Reassign to… is off the screen (${label}, ${scheme}): ${JSON.stringify(box)}`); }
            }
            if (SHOTS) { await p2.screenshot({ path: `${SHOTS}/my-work-team-unassigned-${label}-${scheme}.png`, fullPage: true }); }
            await p2.goto(`${BASE}/admin.php?module=my_work`, { waitUntil: 'networkidle' });
            for (const name of ['Done', 'Tomorrow', 'In 3 days']) {
              const box = await p2.locator('[data-list="tasks"] tr[data-task]').first().getByRole('button', { name, exact: true }).boundingBox();
              if (!box || box.x < 0 || box.x + box.width > width) { throw new Error(`the ${name} button is off the screen (${label}, ${scheme}): ${JSON.stringify(box)}`); }
            }
            const colours = await p2.locator('[data-list="tasks"] h3').first().evaluate((el) => [getComputedStyle(document.body).backgroundColor, getComputedStyle(el).color]);
            if (colours[0] === colours[1]) { throw new Error(`the group heading has the colour of the page (${label}, ${scheme})`); }
            if (SHOTS) { await p2.screenshot({ path: `${SHOTS}/my-work-tasks-${label}-${scheme}.png`, fullPage: true }); }
          } finally {
            await other.close();
          }
        }
      }
      // Done on the overdue one, Tomorrow on today's
      const overdue = page.locator('[data-list="tasks"] tr[data-task]', { hasText: 'Call the supplier back' });
      await Promise.all([page.waitForNavigation(), overdue.getByRole('button', { name: 'Done', exact: true }).click()]);
      if (!(await page.locator('.hlaska-ok', { hasText: 'Task done: Call the supplier back' }).isVisible())) { throw new Error('no message after Done'); }
      if (await page.locator('[data-list="tasks"] h3[data-group="overdue"]').count() !== 0) { throw new Error('the done task is still listed'); }
      await Promise.all([page.waitForNavigation(), page.locator('[data-list="tasks"] tr[data-task]', { hasText: 'Review the price list' }).getByRole('button', { name: 'Tomorrow', exact: true }).click()]);
      if (!(await page.locator('.hlaska-ok', { hasText: 'Task moved to' }).isVisible())) { throw new Error('no message after Tomorrow'); }
      if (sql(`SELECT DATE(due_at) = CURDATE() + INTERVAL 1 DAY AND TIME(due_at) = '23:30:00' FROM ka_tasks WHERE title LIKE 'Review the price list%'`).trim() !== '1') { throw new Error('Tomorrow did not move the due day'); }
      await Promise.all([page.waitForNavigation(), page.locator('.zalozky a', { hasText: 'Team' }).click()]);
      if (await page.locator('[data-list="team"] tbody tr').count() < 1 || (await page.locator('.zalozky a.aktivni').textContent()).trim() !== 'Team') { throw new Error('the Team tab lists nobody'); }
      if (SHOTS) { await page.screenshot({ path: `${SHOTS}/my-work-team.png`, fullPage: true }); }
      // the nobody row jumps to Not assigned; Reassign to… me takes the task
      await page.locator('[data-list="team"] a[href="#unassigned"]', { hasText: 'nobody' }).click();
      if (!(await page.evaluate(() => location.hash === '#unassigned'))) { throw new Error('the nobody row does not link to Not assigned'); }
      const orphan = page.locator('[data-list="unassigned"] tr[data-task]', { hasText: 'Order the trade fair banner' });
      await orphan.locator('select[name="assignee_id"]').selectOption(String(admin));
      await Promise.all([page.waitForNavigation(), orphan.getByRole('button', { name: 'Save', exact: true }).click()]);
      if (!(await page.locator('.hlaska-ok', { hasText: 'The task is yours now.' }).isVisible())) { throw new Error('no message after Reassign'); }
      if (sql("SELECT IFNULL(assignee_id, 'null') FROM ka_tasks WHERE title LIKE 'Order the trade fair banner%'").trim() !== String(admin)) { throw new Error('Reassign did not give the task'); }
      if (await page.locator('[data-list="unassigned"] tr[data-task]', { hasText: 'Order the trade fair banner' }).count() !== 0) { throw new Error('the reassigned task is still under Not assigned'); }
    } finally {
      sql(`DELETE FROM ka_tasks WHERE assignee_id = ${admin} OR title LIKE 'Order the trade fair banner%'; DELETE FROM ka_stranky WHERE ids = ${pageId}`);
    }
  });
  // 3.27 (agent B): the tasks of a record – the follow-up buttons of an enquiry, Mark done and Reopen, Snooze, Edit in place,
  // Hand to Claude through the confirmation (a link to the request), the page's task for its review date, "Add a task" for a
  // colleague with a quick day, Delete through the confirmation; 1440 and 390 px, light and dark, no sideways scroll
  await step('3.27 tasks: enquiry follow-up, done, snooze, edit, hand to Claude; the page review task, add for a colleague, delete; New task in My work (1440 and 390 px, light/dark)', async () => {
    if (!MYSQL.length) { throw new Error('MYSQL_JSON is not set'); }
    const enquiry = Number(sql(`INSERT INTO ka_poptavky (datum, formular, email, data, stav) VALUES (NOW(), 'Contact B327', 'b327@visitor.example', '[["Message","Secret message B327"]]', 1); SELECT LAST_INSERT_ID();`).trim());
    const pageId = Number(sql("INSERT INTO ka_stranky (seo_link, titulek, text, zobrazit, v_menu, review_by) VALUES ('tasks-327', 'Price list 327', '<p>x</p>', 0, 0, CURDATE() + INTERVAL 12 DAY); SELECT LAST_INSERT_ID();").trim());
    sql(`DELETE FROM ka_uzivatele WHERE user = 'jana-327'; INSERT INTO ka_uzivatele (user, password, jmeno, email, admin, posledni_login, potvrzeno) VALUES ('jana-327', '', 'Jana Tasks', '', 1, NOW(), NOW());
    INSERT INTO ka_uzivatele_prava (fk_id_user, ident_modulu) SELECT idu, 'pages' FROM ka_uzivatele WHERE user = 'jana-327'`);
    const jana = Number(sql("SELECT idu FROM ka_uzivatele WHERE user = 'jana-327'").trim());
    const submit = async (locator) => { await Promise.all([page.waitForNavigation(), locator.click()]); };
    const confirmed = async (locator) => { await locator.click(); await page.locator('dialog.potvrzeni[open]').waitFor(); await submit(page.locator('dialog.potvrzeni [data-ano]')); };
    try {
      await page.setViewportSize({ width: 1440, height: 900 });
      await visit(`/admin.php?module=enquiries&action=detail&id=${enquiry}`);
      const panel = page.locator('#tasks');
      await submit(panel.locator('form[data-task-follow-up] button', { hasText: 'In 3 days' }));
      await page.locator('.hlaska-ok', { hasText: 'is in My work.' }).waitFor();
      const row = panel.locator('[data-tasks-open] .ukol').first();
      if ((await row.locator('.ukol-nazev').innerText()) !== 'Follow up: Contact B327') { throw new Error(`the follow-up is titled “${await row.locator('.ukol-nazev').innerText()}”`); }
      if (!(await row.locator('.ukol-meta').innerText()).startsWith('For me · due ')) { throw new Error(`the follow-up says “${await row.locator('.ukol-meta').innerText()}”`); }
      if (SHOTS) { await panel.scrollIntoViewIfNeeded(); await page.screenshot({ path: `${SHOTS}/327-enquiry-tasks.png` }); }
      // done, then open again from the folded Done list
      await submit(row.locator('button.ukol-hotovo'));
      await page.locator('.hlaska-ok', { hasText: 'The task is done.' }).waitFor();
      await panel.locator('.ukoly-hotove summary').click();
      if (!(await panel.locator('[data-tasks-done] .ukol').count())) { throw new Error('the done task is not under Done'); }
      await submit(panel.locator('[data-tasks-done] button', { hasText: 'Reopen' }));
      // snooze to tomorrow, then edit in place
      await submit(panel.locator('[data-tasks-open] form[data-task-snooze] button', { hasText: 'Tomorrow' }));
      await page.locator('.hlaska-ok', { hasText: 'The task comes back on' }).waitFor();
      await panel.locator('.ukol-upravit summary').first().click();
      await panel.locator('form[data-task-edit] input[name="title"]').fill('Call Mrs Novak back');
      await panel.locator('form[data-task-edit] input[name="due_time"]').fill('15:45');
      await submit(panel.locator('form[data-task-edit] button[type="submit"]'));
      const edited = await panel.locator('[data-tasks-open] .ukol').first().innerText();
      if (!edited.includes('Call Mrs Novak back') || !edited.includes('15:45')) { throw new Error(`the edit is not shown: ${edited}`); }
      // hand to Claude: the confirmation, then the task is done with a link to its request
      await confirmed(panel.locator('form[data-task-claude] button'));
      await page.locator('.hlaska-ok', { hasText: 'was handed to Claude as request #' }).waitFor();
      await panel.locator('.ukoly-hotove summary').click();
      const link = panel.locator('[data-task-request] a');
      if (!(await link.getAttribute('href')).includes('module=requests&action=detail&id=')) { throw new Error('the handed task has no link to its request'); }
      const requestText = sql(`SELECT text FROM ka_requests WHERE title = 'Call Mrs Novak back'`);
      if (!requestText.includes(`module=enquiries&action=detail&id=${enquiry}`) || requestText.includes('Secret message')) { throw new Error(`the request text is wrong: ${requestText}`); }
      // the page: one click for the review date, then a task for Jana tomorrow from the Add a task form
      await visit(`/admin.php?module=pages&action=edit&id=${pageId}`);
      await submit(panel.locator('form[data-task-review] button'));
      if (!(await panel.locator('[data-tasks-open] .ukol-nazev', { hasText: 'Review: Price list 327' }).count()) || await panel.locator('form[data-task-review]').count()) { throw new Error('the review task is not added or still offered'); }
      await panel.locator('.ukol-pridat > summary').click();
      const form = panel.locator('form[data-task-add]');
      await form.locator('input[name="title"]').fill('Update the prices');
      await form.locator('select[name="assignee_id"]').selectOption(String(jana));
      await submit(form.locator('.ukol-rychle button', { hasText: 'Tomorrow' }));
      await page.locator('.hlaska-ok', { hasText: 'The task was added for Jana Tasks' }).waitFor();
      const janaRow = panel.locator('[data-tasks-open] .ukol', { hasText: 'Update the prices' });
      if (!(await janaRow.locator('.ukol-meta').innerText()).startsWith('For Jana Tasks · due ')) { throw new Error(`Jana's task says ${await janaRow.locator('.ukol-meta').innerText()}`); }
      if (sql(`SELECT DATEDIFF(due_at, CURDATE()) FROM ka_tasks WHERE title = 'Update the prices'`).trim() !== '1') { throw new Error('the quick day is not tomorrow'); }
      // light and dark, desktop and phone: the panel fits, its text is readable
      const state = await context.storageState();
      for (const scheme of ['light', 'dark']) {
        for (const [label, width, height] of [['desktop', 1440, 900], ['phone', 390, 844]]) {
          const other = await browser.newContext({ viewport: { width, height }, locale: 'en-GB', colorScheme: scheme, storageState: state, ...(width < 500 ? { isMobile: true, hasTouch: true } : {}) });
          const p2 = await other.newPage(); watch(p2);
          try {
            await p2.goto(`${BASE}/admin.php?module=pages&action=edit&id=${pageId}`, { waitUntil: 'networkidle' });
            await p2.locator('#tasks .ukol-pridat').evaluate((d) => { d.open = true; });
            const wide = await p2.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
            if (wide > 0) { throw new Error(`${wide} px of sideways scroll (${label}, ${scheme})`); }
            const colours = await p2.locator('#tasks .ukol').first().evaluate((el) => [getComputedStyle(el).backgroundColor, getComputedStyle(el.querySelector('.ukol-nazev')).color]);
            if (colours[0] === colours[1]) { throw new Error(`the task text has the colour of its card (${label}, ${scheme})`); }
            if (SHOTS) { await p2.locator('#tasks').scrollIntoViewIfNeeded(); await p2.screenshot({ path: `${SHOTS}/327-page-tasks-${label}-${scheme}.png`, fullPage: true }); }
          } finally {
            await other.close();
          }
        }
      }
      // delete through the confirmation
      await confirmed(janaRow.locator('form[data-task-delete] button'));
      await page.locator('.hlaska-ok', { hasText: 'The task was deleted.' }).waitFor();
      if (await panel.locator('.ukol', { hasText: 'Update the prices' }).count()) { throw new Error('the deleted task is still listed'); }
      // My work: New task (the same form) – a task about nothing for today lands in the Today group
      await visit('/admin.php?module=my_work');
      await page.locator('[data-new-task] > summary').click();
      await page.locator('[data-new-task] input[name="title"]').fill('Order paper 327');
      await submit(page.locator('[data-new-task] .ukol-rychle button', { hasText: 'Today' }));
      await page.locator('.hlaska-ok', { hasText: 'The task was added.' }).waitFor();
      if (!(await page.locator('[data-list="tasks"] tr[data-task]', { hasText: 'Order paper 327' }).count())) { throw new Error('the new task is not in My work'); }
      if (sql("SELECT CONCAT(IFNULL(target, 'none'), '/', DATE(due_at) = CURDATE()) FROM ka_tasks WHERE title = 'Order paper 327'").trim() !== 'none/1') { throw new Error('the new task is not about nothing, due today'); }
    } finally {
      await page.setViewportSize({ width: 1440, height: 900 });
      sql(`DELETE FROM ka_notifications WHERE target LIKE 'task:%'; DELETE FROM ka_tasks WHERE target IN ('enquiry:${enquiry}', 'page:${pageId}') OR title = 'Order paper 327';
      DELETE FROM ka_request_messages WHERE request_id IN (SELECT id FROM ka_requests WHERE title = 'Call Mrs Novak back'); DELETE FROM ka_requests WHERE title = 'Call Mrs Novak back';
      DELETE FROM ka_poptavky WHERE idp = ${enquiry}; DELETE FROM ka_stranky WHERE ids = ${pageId}; DELETE FROM ka_uzivatele_prava WHERE fk_id_user = ${jana}; DELETE FROM ka_uzivatele WHERE idu = ${jana}`);
    }
  });
};
