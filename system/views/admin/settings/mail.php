<?php /** The "Pošta" (Mail) tab: from where and how the site sends e-mails. For the variables and the $field function see vypis.php. */ ?>
<p class="hlaska"><?= e(t('The site sends password reset links and system notifications, and enquiries from forms. With your own SMTP server, messages go out from a verified mailbox and do not end up in spam.')) ?></p>
<fieldset>
<legend><?= e(t('Sending method')) ?></legend>
<div class="karty-volby">
	<label class="karta-volba"><input type="radio" name="mail_mode" value="mail"<?= $values['mail_mode'] !== 'smtp' ? ' checked' : '' ?> data-prepni="smtp:0"><strong><?= e(t('Hosting server')) ?></strong><span><?= e(t('The mail() function. Nothing to set up, but with some hosts messages end up in spam.')) ?></span></label>
	<label class="karta-volba"><input type="radio" name="mail_mode" value="smtp"<?= $values['mail_mode'] === 'smtp' ? ' checked' : '' ?> data-prepni="smtp:1"><strong><?= e(t('Custom SMTP server')) ?></strong><span><?= e(t('A mailbox at your host, Google Workspace, Seznam, or a service such as Brevo, Mailgun, Amazon SES… Recommended for newsletters.')) ?></span></label>
</div>
</fieldset>
<fieldset data-sekce="smtp"<?= $values['mail_mode'] === 'smtp' ? '' : ' hidden' ?>>
<legend><?= e(t('SMTP server')) ?></legend>
<?php
// 3.9 (Core\MailServices): a mail service fills the server, port and encryption and says what the user name and password are;
// the choice shown is derived from the saved server, so a working configuration is shown as it is and saved unchanged
$siteSettings = $app->settings();
$serviceChoice = Kaleta\Core\MailServices::choice($siteSettings);
$sesRegion = Kaleta\Core\MailServices::region($values['smtp_host']) ?: Kaleta\Core\MailServices::DEFAULT_REGION;
$newsletterLink = Kaleta\Core\MailServices::newsletterLink($siteSettings);
$featuresUrl = $app->url('admin.php?module=extensions') . '#newsletter';
?>
<div class="radek">
	<label for="smtp_provider"><?= e(t('Send through')) ?></label>
	<div><select id="smtp_provider" name="smtp_provider" data-smtp-sluzba>
		<option value="<?= e(Kaleta\Core\MailServices::OTHER) ?>"<?= $serviceChoice === Kaleta\Core\MailServices::OTHER ? ' selected' : '' ?>><?= e(t('Other server – your host, Google Workspace, Seznam…')) ?></option>
		<optgroup label="<?= e(t('Mail services with an SMTP relay')) ?>">
<?php foreach (Kaleta\Core\MailServices::PROVIDERS as $key => $service): ?>
		<option value="<?= e($key) ?>" data-host="<?= e(Kaleta\Core\MailServices::host($key, $sesRegion)) ?>" data-port="<?= $service['port'] ?>" data-sifrovani="<?= e($service['encryption']) ?>"<?= $serviceChoice === $key ? ' selected' : '' ?>><?= e($service['name']) ?></option>
<?php endforeach ?>
		</optgroup>
	</select>
	<span class="napoveda"><?= e(t('A mail service fills in the server, the port and the encryption. The user name and the password stay yours to paste – the service’s SMTP credentials, not its API key unless the service says so.')) ?></span></div>
</div>
<div class="radek" data-smtp-tip="ses"<?= $serviceChoice === 'ses' ? '' : ' hidden' ?>>
	<label for="smtp_ses_region"><?= e(t('Amazon SES region')) ?></label>
	<div><select id="smtp_ses_region" name="smtp_ses_region" data-smtp-region>
<?php foreach (Kaleta\Core\MailServices::SES_REGIONS + [$sesRegion => $sesRegion] as $region => $regionName): ?>
		<option value="<?= e($region) ?>"<?= $region === $sesRegion ? ' selected' : '' ?>><?= e($regionName . ' – ' . $region) ?></option>
<?php endforeach ?>
	</select>
	<span class="napoveda"><?= e(t('The region where the sending domain is verified in Amazon SES – the SMTP credentials work only there.')) ?></span></div>
</div>
<?php foreach (Kaleta\Core\MailServices::PROVIDERS as $key => $service): ?>
<div class="hlaska hlaska-akce" data-smtp-tip="<?= e($key) ?>"<?= $serviceChoice === $key ? '' : ' hidden' ?>>
	<p><strong><?= e(t('User name')) ?>:</strong> <?= e(t($service['user'])) ?></p>
	<p><strong><?= e(t('Password')) ?>:</strong> <?= e(t($service['password'])) ?></p>
<?php if ($newsletterLink !== null && $newsletterLink['provider'] === $key): ?>
	<p><?= e(t('Your newsletter integration uses %s too. Its API key is not copied here – the SMTP relay signs in with its own credentials, so paste them below.', $newsletterLink['service'])) ?> <a href="<?= e($featuresUrl) ?>"><?= e(t('Newsletter integration')) ?></a></p>
<?php endif ?>
	<p><a href="<?= e($service['docs']) ?>" target="_blank" rel="noopener noreferrer"><?= e(t('%s: SMTP guide', $service['name'])) ?></a></p>
</div>
<?php endforeach ?>
<?php if ($newsletterLink !== null && $newsletterLink['provider'] !== '' && $newsletterLink['provider'] !== $serviceChoice): ?>
<p class="napoveda"><?= e(t('Your newsletter integration uses %s, which also relays e-mail over SMTP – choose %s above to send the site’s mail through the same account.', $newsletterLink['service'], Kaleta\Core\MailServices::PROVIDERS[$newsletterLink['provider']]['name'])) ?> <a href="<?= e($featuresUrl) ?>"><?= e(t('Newsletter integration')) ?></a></p>
<?php elseif ($newsletterLink !== null && $newsletterLink['note'] !== ''): ?>
<p class="napoveda"><?= e(t($newsletterLink['note'])) ?> <a href="<?= e($featuresUrl) ?>"><?= e(t('Newsletter integration')) ?></a></p>
<?php endif ?>
<?php
$field('smtp_host', 'Server address', 'text', 'For example smtp.gmail.com, smtp.seznam.cz, smtp-relay.brevo.com or smtp.vasedomena.cz.', 'maxlength="120" placeholder="smtp.example.com" autocomplete="off"');
?>
<div class="radek">
	<label for="smtp_encryption"><?= e(t('Zabezpečení')) ?></label>
	<div><select id="smtp_encryption" name="smtp_encryption">
		<option value="tls"<?= $values['smtp_encryption'] === 'tls' ? ' selected' : '' ?>><?= e(t('STARTTLS – port 587 (most common)')) ?></option>
		<option value="ssl"<?= $values['smtp_encryption'] === 'ssl' ? ' selected' : '' ?>><?= e(t('SSL/TLS – port 465')) ?></option>
		<option value="zadne"<?= $values['smtp_encryption'] === 'zadne' ? ' selected' : '' ?>><?= e(t('none – only for a server on your own network')) ?></option>
	</select></div>
</div>
<?php
$field('smtp_port', 'Port', 'cislo', '', 'min="1" max="65535"');
$field('smtp_user', 'Přihlašovací jméno', 'text', 'Usually the full e-mail address of the mailbox.', 'maxlength="190" autocomplete="off"');
?>
<div class="radek">
	<label for="smtp_password"><?= e(t('Password')) ?></label>
	<div><input class="textpole siroke" type="password" id="smtp_password" name="smtp_password" value="" autocomplete="new-password" placeholder="<?= $values['smtp_password'] !== '' ? e(t('password is saved – enter a new one only to change it')) : '' ?>">
	<span class="napoveda"><?= e(t('For Gmail and Seznam use an “app password”, not your account password. The password is stored only on your site and is never displayed back.')) ?></span>
<?php if ($values['smtp_password'] !== ''): ?>
	<label><input type="checkbox" name="smtp_password_smazat" value="1"> <?= e(t('Remove saved password')) ?></label>
<?php endif ?>
	</div>
</div>
<?php
$field('newsletter_hourly_limit', 'Newsletters: e-mails per hour', 'cislo', 'Newsletters go out in batches while cron runs. Keep to the sending limit of your SMTP service – free plans often allow only a few hundred e-mails a day.', 'min="10" max="100000"');
?>
</fieldset>
<?php
// 3.9: after saving with a mail service – the DNS records it needs for the sending domain, and what the daily domain check found
$currentService = Kaleta\Core\MailServices::current($siteSettings);
if ($currentService !== null):
    $service = Kaleta\Core\MailServices::PROVIDERS[$currentService];
    $sender = $siteSettings->get('mail_from') !== '' ? $siteSettings->get('mail_from') : $siteSettings->get('site_email');
    $sendingDomain = strtolower(substr((string) strrchr($sender, '@'), 1));
    $watchMail = is_array($domainWatch['mail'] ?? null) && ($domainWatch['mail']['domain'] ?? '') === $sendingDomain ? $domainWatch['mail'] : null;
?>
<fieldset>
<legend><?= e(t('DNS records for %s', $service['name'])) ?></legend>
<p class="napoveda"><?= e(t($service['dns'])) ?></p>
<?php if ($sendingDomain !== '' && $service['spf'] !== ''): ?>
<p class="napoveda"><?= e(t('The SPF record of %s must include %s – one SPF record per domain, for example:', $sendingDomain, 'include:' . $service['spf'])) ?> <code><?= e('v=spf1 mx include:' . $service['spf'] . ' ~all') ?></code></p>
<?php endif ?>
<?php if ($watchMail !== null && ($watchMail['error'] ?? null) === null): ?>
<ul class="napoveda">
	<li><?= e(t('SPF record')) ?>: <?= e(match (true) {
        $watchMail['spf'] === null => t('none found on %s', $sendingDomain),
        $watchMail['spf_covers_smtp'] === true || $service['spf'] === '' => t('found – all is well'),
        default => t('found, but it does not seem to include %s yet', 'include:' . $service['spf']),
    }) ?></li>
	<li><?= e(t('DKIM signature')) ?>: <?= e($watchMail['dkim'] !== null ? t('key published under the selector %s', (string) $watchMail['dkim']) : t('not found yet')) ?></li>
</ul>
<?php endif ?>
<p class="napoveda"><?= e(t('System status checks the sending domain once a day; after adding the records, use Check now there.')) ?> <a href="<?= e($app->url('admin.php?module=status')) ?>"><?= e(t('System status')) ?></a></p>
</fieldset>
<?php endif ?>
<details class="pokrocile"<?= $values['mail_from'] !== ''|| $values['mail_reply_to'] !== '' ? ' open' : '' ?>>
<summary><?= e(t('Sender and replies')) ?></summary>
<?php
$field('mail_from', 'Sender address', 'email', 'Empty = the site e-mail. With SMTP it must be an address your mailbox is allowed to send from.');
$field('mail_reply_to', 'Send replies to', 'email', 'Optional – when readers\' replies should go somewhere other than the sender.');
?>
</details>
<p><button class="navigace" type="submit" formaction="<?= e($module->url('test_mail')) ?>"><?= e(t('Send a test e-mail to the site address')) ?></button> <span class="smltxt"><?= e(t('Save the settings first – the test uses the saved values.')) ?></span></p>
<fieldset>
<legend><?= e(t('Monthly report')) ?></legend>
<p class="napoveda"><?= e(t('In the first days of each month the site e-mails a short report about the previous month: traffic, enquiries and sign-ups, updates, backups, changes made by people and by Claude, the problems right now and what needs your decision. Counts and page addresses only – never names, e-mail addresses or the contents of enquiries, so the report can be forwarded. With the agency details from the General tab it carries the agency’s branding.')) ?></p>
<?php
$field('report_monthly', 'Send a monthly report by e-mail', 'ano', '');
$field('report_recipients', 'Recipients', 'radky', 'One address per line or separated by commas, at most 10. Empty = the site e-mail (Settings → General).', 'maxlength="2000"');
?>
<p><a class="navigace" href="<?= e($module->url('report_preview')) ?>" target="_blank" rel="noopener"><?= e(t('Preview last month')) ?></a> <button class="navigace" type="submit" formaction="<?= e($module->url('report_send')) ?>"><?= e(t('Send last month’s report now')) ?></button> <span class="smltxt"><?= e(t('Save the settings first – sending uses the saved recipients.')) ?></span></p>
</fieldset>
<?php if (!empty($mail)): ?>
<h2><?= e(t('Recent messages')) ?></h2>
<div class="tab-obal"><table class="vypis">
<thead><tr><th scope="col"><?= e(t('Time')) ?></th><th scope="col"><?= e(t('Komu')) ?></th><th scope="col"><?= e(t('Subject')) ?></th><th scope="col"><?= e(t('Status')) ?></th></tr></thead>
<tbody>
<?php foreach ($mail as $z): ?>
<tr>
	<td class="cislo"><?= e(format_date($z['vytvoreno'], true)) ?></td>
	<td><?= e($z['komu']) ?></td>
	<td><?= e($z['predmet']) ?></td>
	<td><?php if ($z['odeslano'] !== null): ?><span class="stitek stitek-vydano"><?= e(t('sent')) ?></span><?= (int) $z['pokusu'] > 1 ? ' ' . e(t('on attempt %s', (int) $z['pokusu'])) : '' ?>
<?php elseif ($z['dalsi_pokus'] !== null): ?><span class="stitek stitek-koncept"><?= e(t('waiting for the next attempt')) ?></span> <?= e(format_date($z['dalsi_pokus'], true)) ?><br><small><?= e($z['chyba']) ?></small>
<?php else: ?><span class="stitek stitek-koncept"><?= e(t('not sent')) ?></span><br><small><?= e($z['chyba']) ?></small><?php endif ?></td>
</tr>
<?php endforeach ?>
</tbody></table></div>
<p class="smltxt"><?= e(t('A message that fails to send is retried after 5 minutes, 30 minutes, 2 and 12 hours. Records are deleted after 30 days; message content is not kept.')) ?></p>
<?php endif ?>
