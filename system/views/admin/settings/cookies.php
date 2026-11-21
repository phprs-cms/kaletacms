<?php
/**
 * The "Soukromí a cookies" (Privacy and cookies) tab. 3.48 B (U40): first how long the site keeps personal data – every
 * period of Core\Retention in one place ($retentionRows); a shorter one is confirmed on its own screen (Settings::actionRetention).
 *
 * @var list<array{key: string, label: string, value: int, unit: string, zero: string, editable: bool, feature_on: bool, setting: string, effective: int, row: array<string, mixed>}> $retentionRows
 * @var array{count: int, awaiting: array{count: int, before: string, months: int}|null}|null $applicationsWaiting moved applications waiting for the confirmation (3.48 review)
 */
$retentionRows ??= [];
$applicationsWaiting ??= null;
// the hint of each period – the texts its section's form had before 3.48
$retentionHints = [
    'enquiries_months' => 'Enquiries contain personal data – they should not be kept longer than necessary. 0 = keep forever.',
    'job_applications_months' => 'Applications sent from the pages of a Job openings collection carry CVs and are usually kept only for a limited time after the selection. 0 = like other enquiries.',
    'contacts_months' => 'A customer record (Customers) with no enquiry, booking, reply, logged contact, comment or task for this long is anonymised – its name, e-mail, phone and notes go. 0 = keep.',
    'quotes_months' => 'Counted from the last change of status; then the quote goes the way of old enquiries (deleted or without the person). 0 = keep.',
    'applications_months' => 'A decided application (hired or rejected) is deleted this many months after the decision, an undecided one after this long without any activity – with its CVs, notes and tasks. 0 = keep.',
    'applications_pool_months' => 'Only when the form offers the consent (its property in the builder) and the applicant ticks it.',
    'whistleblowing_retention_months' => 'Closed cases are deleted with their attachments and messages; open cases are kept. Check the retention period your national law requires.',
    'cookies_log_months' => 'Older records are deleted automatically. 0 = keep.',
];
$retentionInput = function (array $r) use ($values, $invalidFields, $retentionHints): void {
    $row = $r['row'];
    $key = $r['setting'];
    $zero = isset($retentionHints[$key]) ? t($retentionHints[$key]) : '';
    echo '<div class="radek"><label for="' . e($key) . '">' . e(t($r['label'])) . '</label><div><input class="textpole" type="number" id="' . e($key) . '" name="' . e($key) . '" value="'
        . e($values[$key] ?? (string) $r['value']) . '" min="' . (int) $row['min'] . '" max="' . (int) $row['max'] . '" size="4"'
        . (in_array($key, $invalidFields, true) ? ' aria-invalid="true"' : '') . '> ' . e($r['unit'] === 'days' ? t('days') : t('months'))
        . ($zero !== '' ? '<span class="napoveda">' . e($zero) . '</span>' : '') . '</div></div>';
    if ($r['key'] === 'enquiries') {
        $expiry = ($values['enquiries_expiry'] ?? '') === 'anonymise' ? 'anonymise' : 'delete';
        echo '<div class="radek"><span class="popisek">' . e(t('After that period')) . '</span><div class="volby volby-radky">';
        foreach (['delete' => 'delete them including attachments', 'anonymise' => 'anonymise them – the row stays for statistics (date, form, page, topic, kind) without the name, e-mail, phone, message and attachments'] as $choice => $text) {
            echo '<label><input type="radio" name="enquiries_expiry" value="' . e($choice) . '"' . ($expiry === $choice ? ' checked' : '') . '> ' . e(t($text)) . '</label>';
        }
        echo '<span class="napoveda">' . e(t('The same for bookings and quotes.')) . '</span></div></div>';
    }
};
$retentionOn = array_filter($retentionRows, fn (array $r): bool => $r['editable'] && $r['feature_on']);
$retentionOff = array_filter($retentionRows, fn (array $r): bool => $r['editable'] && !$r['feature_on']);
?>
<?php if ($retentionRows !== []): ?>
<fieldset id="retention">
<legend><?= e(t('How long data is kept')) ?></legend>
<p class="napoveda"><?= e(t('Personal data should not be kept longer than necessary. The daily clean-up deletes or anonymises what is past its period. A shorter period is confirmed on the next screen before anything changes.')) ?></p>
<?php foreach ($retentionOn as $r): ?>
<?php $retentionInput($r) ?>
<?php if ($r['key'] === 'applications' && $applicationsWaiting !== null): /* 3.48 review: what waits for the confirmation (3.34 review H1, N8) – confirmed only by the checkbox or a changed period */ ?>
<div class="radek"><span></span><div data-applications-retention-pending>
<?php if ($applicationsWaiting['awaiting'] !== null): ?>
<p class="hlaska hlaska-varovani"><?= e(t('%s application(s) moved from Enquiries have had no activity since before %s – past the retention of %d months. Nothing moved is deleted until you confirm the period; then the daily clean-up deletes them with their CVs. Set 0 to keep them.', format_count($applicationsWaiting['awaiting']['count']), format_date($applicationsWaiting['awaiting']['before']), $applicationsWaiting['awaiting']['months'])) ?></p>
<?php endif ?>
<label><input type="checkbox" name="applications_retention_confirm" value="1"> <?= e(t('Apply this period to the %s application(s) moved from Enquiries', format_count($applicationsWaiting['count']))) ?></label>
</div></div>
<?php endif ?>
<?php endforeach ?>
<?php foreach ($retentionRows as $r): ?>
<?php if ($r['key'] === 'bookings' && $r['feature_on']): ?>
<div class="radek"><span class="popisek"><?= e(t('Bookings')) ?></span><div><?= e(t('Bookings: as enquiries')) ?> (<?= e(Kaleta\Core\Retention::label($r['effective'], $r['unit'])) ?>)</div></div>
<?php elseif (!$r['editable'] && isset($r['row']['edit'])): ?>
<div class="radek"><span class="popisek"><?= e(t($r['label'])) ?></span><div><?= e(Kaleta\Core\Retention::label($r['effective'], $r['unit'])) ?> – <a href="<?= e($app->url('admin.php?' . $r['row']['edit'])) ?>"><?= e(t('change')) ?></a></div></div>
<?php endif ?>
<?php endforeach ?>
<details class="pokrocile">
<summary><?= e(t('Kept for a fixed time')) ?></summary>
<div class="tab-obal">
<table class="vypis">
<thead><tr><th scope="col"><?= e(t('What')) ?></th><th scope="col"><?= e(t('Kept')) ?></th></tr></thead>
<tbody>
<?php foreach (Kaleta\Core\Retention::FIXED as [$what, $number, $unit]): ?>
	<tr><td><?= e(t($what)) ?></td><td><?= e(Kaleta\Core\Retention::label($number, $unit)) ?></td></tr>
<?php endforeach ?>
</tbody>
</table>
</div>
</details>
<?php if ($retentionOff !== []): ?>
<details class="pokrocile"<?= array_intersect(array_column($retentionOff, 'setting'), $invalidFields) !== [] ? ' open' : '' ?>>
<summary><?= e(t('Periods of features that are off')) ?></summary>
<p class="napoveda"><?= e(t('They still apply to the data the site stored while the feature was on.')) ?></p>
<?php foreach ($retentionOff as $r): ?>
<?php $retentionInput($r) ?>
<?php endforeach ?>
</details>
<?php endif ?>
</fieldset>
<?php endif ?>
<fieldset>
<legend><?= e(t('Cookie bar')) ?></legend>
<div class="karty-volby karty-volby-text">
<?php foreach ([
    'vestavena' => ['Built-in banner', 'Recommended. Shown only when there is something to consent to; tracking starts only after consent.'],
    'externi' => ['External service', 'Cookiebot, CookieYes, Usercentrics… You paste in their code.'],
    'zadna' => ['None', 'Tracking codes run immediately. Only if you handle consent another way.'],
] as $key => [$name, $description]): ?>
	<label class="karta-volba">
		<input type="radio" name="cookies_mode" value="<?= e($key) ?>"<?= $values['cookies_mode'] === $key ? ' checked' : '' ?>>
		<strong><?= e(t($name)) ?></strong>
		<span><?= e(t($description)) ?></span>
	</label>
<?php endforeach ?>
</div>
<?php
$field('cookies_text', 'Banner text', 'radky');
// 3.20 (C22): the site's privacy policy page is prefilled and linked; without one, the hint leads to a new page.
// 3.20 (N320-8): a hidden page (the installer makes it so) is prefilled too, and the hint says to publish it – its settings do that
$field('cookies_policy_url', 'Link to the policy', 'text', ($privacyPage ?? null) !== null
    ? ($privacyPage['visible']
        ? e(t('The privacy policy page of this site: %s.', $privacyPage['titulek'])) . ' <a href="' . e($app->url('admin.php?module=pages&action=edit&id=' . $privacyPage['ids'])) . '">' . e(t('Edit the page')) . '</a>'
        : e(t('The privacy policy page of this site: %s – hidden, publish it or visitors following the link get “page not found”.', $privacyPage['titulek'])) . ' <a href="' . e($app->url('admin.php?module=pages&action=edit&id=' . $privacyPage['ids'])) . '">' . e(t('Page settings')) . '</a>')
    : e(t('E.g. /privacy-policy – the page does not exist yet.')) . ' <a href="' . e($app->url('admin.php?module=pages&action=new')) . '">' . e(t('Create the page')) . '</a>', 'maxlength="255"');
?>
<?php if (($additionalLanguages = Kaleta\Core\Language::additional($app->settings())) !== []): // 3.9 (UXM-11): the bar in each language version ?>
<?php $invalidHere = fn (string $key): string => in_array($key, $invalidFields, true) ? ' aria-invalid="true"' : ''; ?>
<details class="pokrocile"<?= array_filter($additionalLanguages, fn (string $j): bool => ($values['cookies_text_' . $j] ?? '') . ($values['cookies_policy_url_' . $j] ?? '') !== '' || $invalidHere('cookies_policy_url_' . $j) !== '') !== [] ? ' open' : '' ?>>
<summary><?= e(t('Banner text and policy link in other language versions')) ?></summary>
<p class="napoveda"><?= e(t('Visitors of each language version read the banner in its language. An empty field means the same as in the default language.')) ?></p>
<?php foreach ($additionalLanguages as $j): ?>
<div class="radek"><label for="cookies_text_<?= e($j) ?>"><?= e(t('Banner text')) ?> (<?= e(strtoupper($j)) ?>)</label><div><textarea class="textbox nizky" id="cookies_text_<?= e($j) ?>" name="cookies_text_<?= e($j) ?>" rows="4" lang="<?= e($j) ?>"<?= $invalidHere('cookies_text_' . $j) ?>><?= e($values['cookies_text_' . $j] ?? '') ?></textarea></div></div>
<div class="radek"><label for="cookies_policy_url_<?= e($j) ?>"><?= e(t('Link to the policy')) ?> (<?= e(strtoupper($j)) ?>)</label><div><input class="textpole siroke" type="text" id="cookies_policy_url_<?= e($j) ?>" name="cookies_policy_url_<?= e($j) ?>" value="<?= e($values['cookies_policy_url_' . $j] ?? '') ?>" maxlength="255" placeholder="/<?= e($j) ?>/…"<?= $invalidHere('cookies_policy_url_' . $j) ?>></div></div>
<?php endforeach ?>
</details>
<?php endif ?>
</fieldset>
<details class="pokrocile"<?= $values['cookies_mode'] === 'externi' || $values['marketing_code'] !== '' ? ' open' : '' ?>>
<summary><?= e(t('Codes and records')) ?></summary>
<?php
$field('cookies_external_code', 'External service code', 'kod', 'The script from your provider (for Cookiebot, the line with data-cbid). It loads first.', 'spellcheck="false"');
$field('marketing_code', 'Marketing codes', 'kod', 'Meta Pixel, Sklik retargeting, Google Ads… Runs only after marketing consent is given.', 'spellcheck="false"');
$field('lead_attribution', 'Remember where leads came from', 'ano', 'The first page of a visit, its campaign and the site that sent the visitor go with enquiries and newsletter sign-ups (Statistics → Campaigns). Only for visitors who allow marketing in the cookie bar – turning it on adds that choice to the bar.');
$field('cookies_log', 'Log consents', 'ano', 'Time, a random identifier and the chosen categories – no IP address. Evidence in case of an audit.');
// 3.48 B: how long the records are kept is in “How long data is kept” at the top (cookies_log_months)
?>
</details>
<fieldset>
<legend><?= e(t('Spam check for forms')) ?></legend>
<p class="napoveda"><?= e(t('Forms are protected without one: a signed time, a hidden trap field and a limit per address. Add a CAPTCHA only if spam still gets through. The provider receives the visitor’s address and browser details – name it in your privacy policy.')) ?></p>
<div class="radek">
	<label for="captcha_provider"><?= e(t('Extra spam check')) ?></label>
	<div><select id="captcha_provider" name="captcha_provider">
		<option value=""<?= $values['captcha_provider'] === '' ? ' selected' : '' ?>><?= e(t('none – the built-in protection only')) ?></option>
<?php foreach (Kaleta\Core\Captcha::PROVIDERS as $key => [$name]): ?>
		<option value="<?= e($key) ?>"<?= $values['captcha_provider'] === $key ? ' selected' : '' ?>><?= e($name) ?></option>
<?php endforeach ?>
	</select>
	<span class="napoveda"><?= e(t('hCaptcha and Cloudflare Turnstile suit EU sites; reCAPTCHA v3 is invisible and scores every visit.')) ?></span></div>
</div>
<?php $field('captcha_site_key', 'Site key', 'text', 'From the provider’s dashboard, for this site’s domain.', 'maxlength="100" autocomplete="off" spellcheck="false"'); ?>
<div class="radek">
	<label for="captcha_secret"><?= e(t('Secret key')) ?></label>
	<div><input class="textpole siroke" type="password" id="captcha_secret" name="captcha_secret" value="" autocomplete="new-password" placeholder="<?= $values['captcha_secret'] !== '' ? e(t('the key is saved – enter a new one only to change it')) : '' ?>">
	<span class="napoveda"><?= e(t('Stays on your site; Claude cannot read it.')) ?></span>
<?php if ($values['captcha_secret'] !== ''): ?>
	<label><input type="checkbox" name="captcha_secret_smazat" value="1"> <?= e(t('Remove the saved key')) ?></label>
<?php endif ?>
	</div>
</div>
<?php $field('captcha_fail_open', 'Accept forms when the provider is down', 'ano', 'The built-in protection still applies. Off = such forms are refused until the provider answers again.'); ?>
</fieldset>
<?php if ($consents !== []): ?>
<p class="napoveda"><?= e(t('Consents in the last 30 days:')) ?> <?= implode(' · ', array_map(fn (array $r): string => e($r['kategorie'] === 'nic' ? t('necessary only') : $r['kategorie']) . ' ' . (int) $r['pocet'] . '×', $consents)) ?></p>
<?php endif ?>
<fieldset>
<legend><?= e(t('Cookies and storage this site uses')) ?></legend>
<p class="napoveda"><?= e(t('What Kaleta itself sets, what the known embeds and tags found in your pages and settings set (YouTube, Google Maps, Analytics, Tag Manager, Matomo, Meta Pixel, CAPTCHA) and what the server answers with. Put {{cookie_table}} into your cookie policy page – the table appears there in the site language.')) ?></p>
<?php // 3.32 (U47): a listing like the others – cards on a phone (admin.js), no sideways scroll of the page ?>
<div class="tab-obal">
<table class="vypis cookies-tabulka">
<thead><tr><th scope="col"><?= e(t('Name')) ?></th><th scope="col"><?= e(t('Provider')) ?></th><th scope="col"><?= e(t('Purpose')) ?></th><th scope="col"><?= e(t('Duration')) ?></th><th scope="col"><?= e(t('Category')) ?></th></tr></thead>
<tbody>
<?php foreach ($cookieTable as $r): ?>
	<tr><td><code><?= e($r['name']) ?></code></td><td><?= e($r['provider']) ?></td><td><?= e($r['purpose']) ?></td><td><?= e($r['duration']) ?></td><td><?= e(t(Kaleta\Core\Privacy::CATEGORIES[$r['category']] ?? $r['category'])) ?></td></tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<p class="napoveda"><?= isset($cookieScan['time']) ? e(t('Last scan of the site’s own pages: %s, %d pages, %d cookies set by the server.', format_date(date('Y-m-d H:i:s', (int) $cookieScan['time']), true), (int) ($cookieScan['pages'] ?? 0), count($cookieScan['cookies'] ?? [])))
    . (($cookieScan['error'] ?? '') !== '' ? ' ' . e(t('The server could not reach the site: %s.', (string) $cookieScan['error'])) : '') : e(t('The site’s own pages have not been scanned yet – the background tasks do it daily.')) ?></p>
<p><button class="navigace" type="submit" formaction="<?= e($module->url('cookie_scan')) ?>"><?= e(t('Scan the site now')) ?></button></p>
</fieldset>
<fieldset>
<legend><?= e(t('Documents from the configuration')) ?></legend>
<p class="napoveda"><?= e(t('Templates assembled from what the site is set up to do – review and complete them, they are not legal advice.')) ?></p>
<div class="radek"><span class="popisek"><?= e(t('Record of processing')) ?></span><div><a class="navigace" href="<?= e($module->url('processing_record')) ?>"><?= e(t('Show the record')) ?></a>
<span class="napoveda"><?= e(t('GDPR Art. 30 style: forms and their fields, enquiries and applications with their retention, newsletter, statistics, connected services, mail, backups, the writing assistant, cookies.')) ?></span></div></div>
<div class="radek"><span class="popisek"><?= e(t('Accessibility statement')) ?></span><div>
<?php if ($statementPage !== null): ?>
	<a class="navigace" href="<?= e($app->url('admin.php?module=pages&action=builder&id=' . (int) $statementPage['ids'])) ?>"><?= e($statementPage['titulek']) ?></a> · <?= e((int) $statementPage['zobrazit'] === 1 ? t('published') : t('hidden draft')) ?> · <?= e(format_date((string) $statementPage['zmeneno'], true)) ?>
<?php endif ?>
	<button class="navigace" type="submit" formaction="<?= e($module->url('accessibility_statement')) ?>"><?= e(t($statementPage !== null ? 'Regenerate the draft from the audit' : 'Create the draft from the audit')) ?></button>
<span class="napoveda"><?= e(t('Filled from the site audit: the standard (EN 301 549 / WCAG 2.1 AA), the status by the accessibility findings, the known barriers and the contact. Created as a hidden page – review it, then publish it. Regenerating updates the draft.')) ?></span></div></div>
<?php $field('accessibility_toolbar', 'Accessibility toolbar for visitors', 'ano', 'A small button on every page: larger text, higher contrast, underlined links, reduced motion. Remembered in the visitor’s browser, no cookies.'); ?>
</fieldset>
