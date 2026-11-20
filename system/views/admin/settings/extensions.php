<?php
/** The "Rozšíření" (Extensions) tab. */
use Kaleta\Core\Extensions;
?>
<p class="hlaska"><?= e(t('Features are optional parts of Kaleta. All of them are part of the system and maintained by the Kaleta team – nothing is downloaded or installed. A switched-off feature disappears from the menu and the site; its data stays and returns when you switch it on again.')) ?></p>
<?php
// where an enabled extension is configured – each lives elsewhere in the admin, so the card leads straight to that place
$adminUrl = fn (string $query): string => $app->url('admin.php?' . $query);
$extensionSettings = [
    'novinky' => [[$adminUrl('module=news'), 'News'], [$adminUrl('module=categories'), 'Categories'], [$adminUrl('module=tags'), 'Tags']],
    'poptavky' => [[$adminUrl('module=enquiries'), 'Enquiries and retention'], [$adminUrl('module=settings&tab=webhooks'), 'Webhook to CRM']],
    'newsletter' => [[$adminUrl('module=subscribers'), 'Subscribers and export'], ['#newsletter', 'Connection to a mailing service']],
    'bookings' => [[$adminUrl('module=bookings'), 'Bookings'], [$adminUrl('module=bookings&action=services'), 'Services'], [$adminUrl('module=bookings&action=staff'), 'People']],
    'statistika' => [[$adminUrl('module=stats'), 'Statistics'], [$adminUrl('module=settings&tab=analytics'), 'Analytics']],
    'presmerovani' => [[$adminUrl('module=redirects'), 'Redirects']],
    'jazyky' => [[$adminUrl('module=settings&tab=languages#additional_languages'), 'Choose languages']],
    'asistent' => [['#asistent', 'Provider, key and model']],
    'whistleblowing' => [[$adminUrl('module=whistleblowing'), 'Channel set-up and readers']],
    // 3.48: the CRM, quotes and applications lead to their screens and settings too
    'customers' => [[$adminUrl('module=contacts'), 'Customers'], [$adminUrl('module=contacts&action=companies'), 'Companies']],
    'pipeline' => [[$adminUrl('module=pipeline'), 'Pipeline'], [$adminUrl('module=pipeline&action=stages'), 'Deal stages']],
    'quotes' => [[$adminUrl('module=quotes'), 'Quotes'], [$adminUrl('module=quotes&action=prices'), 'Internal prices'], [$adminUrl('module=quotes&action=setup'), 'Price list set-up'], [$adminUrl('module=quotes&action=settings'), 'Settings']],
    'applications' => [[$adminUrl('module=applications'), 'Applications'], [$adminUrl('module=applications&action=settings'), 'Settings']],
    'claude' => [[$adminUrl('module=claude_settings#how-to-connect'), 'How to connect Claude']], // 3.20 (C6): one destination
];
if (in_array('bookings', $enabledExtensions, true) && in_array(false, Kaleta\Core\Booking::setup($app->db()), true)) {
    // switched on, not set up yet (3.5): the first link leads to the set-up card of Bookings
    array_unshift($extensionSettings['bookings'], [$adminUrl('module=bookings'), 'Set up Bookings →']);
}
?>
<?php
// 3.48 (decision 3): a profile is a preselection – the link only ticks its features (GET), the Save below stores them; the
// notice names what the choice would switch off, with the records each holds
$profile = $app->request->get('profile');
$chosen = isset(Extensions::PROFILES[$profile]) ? Kaleta\Admin\Modules\Extensions::preselect($app->settings(), $profile, $enabledExtensions) : null;
$ticked = $chosen['keys'] ?? $enabledExtensions;
$profileHints = ['website' => 'Pages, news, statistics, redirects and the Claude connection – a presentation of the company.',
    'enquiries' => 'The website and forms with the Enquiries inbox.',
    'office' => 'Enquiries with customers, the pipeline of deals and quotes – the daily work of a front office.'];
?>
<nav class="rozsireni-profily" aria-label="<?= e(t('Profiles')) ?>" data-feature-profiles>
<?php foreach (Extensions::PROFILES as $key => $label): ?>
	<a class="rozsireni-profil" href="<?= e($app->url('admin.php?module=extensions&profile=' . $key)) ?>"<?= $profile === $key ? ' aria-current="true"' : '' ?>><strong><?= e(t($label)) ?></strong><span><?= e(t($profileHints[$key])) ?></span></a>
<?php endforeach ?>
</nav>
<?php if ($chosen !== null):
    $going = array_map(function (string $key) use ($app): string {
        $n = Kaleta\Admin\Modules\Extensions::records($app->db(), $key);
        return $n > 0 ? t('%s (%d records)', t(Extensions::CATALOG[$key][0]), $n) : t(Extensions::CATALOG[$key][0]);
    }, $chosen['off']); ?>
<p class="hlaska hlaska-varovani" role="status" data-profile-notice><?= e($going !== [] ? t('Not saved yet. This choice switches off: %s', implode(', ', $going)) : t('Not saved yet.')) ?> <?= e(t('Their records stay; the sections only leave the menu.')) ?></p>
<?php endif ?>
<div class="rozsireni-seznam">
<?php foreach (Extensions::CATALOG as $key => [$name, $description]): $isEnabled = in_array($key, $enabledExtensions, true); $isTicked = in_array($key, $ticked, true); ?>
	<div class="rozsireni-karta">
		<input type="checkbox" id="rozsireni-<?= e($key) ?>" name="rozsireni[]" value="<?= e($key) ?>"<?= $isTicked ? ' checked' : '' ?>>
		<span><label for="rozsireni-<?= e($key) ?>"><strong><?= e(t($name)) ?></strong><br><?= e($key === 'novinky' ? t($description, $app->url('novinky')) : t($description)) ?></label>
<?php foreach (Extensions::REQUIRES as $needs => $needed): if ($needed === $key): ?>
			<span class="rozsireni-potreba smltxt"><?= e(t('Needed by %s', t(Extensions::CATALOG[$needs][0]))) ?></span>
<?php endif; endforeach ?>
<?php if (in_array($key, ['customers', 'pipeline'], true)): $seen = Kaleta\Admin\Modules\Extensions::seenBy($app->db(), $key === 'customers' ? Kaleta\Core\Contacts::SECTION : Kaleta\Core\Deals::SECTION); ?>
			<span class="rozsireni-vidi smltxt"><?= e(t('Seen by: %s (%d people)', implode(', ', [t('Administrators'), ...$seen['roles']]), $seen['people'])) ?></span>
<?php endif ?>
<?php if ($isEnabled && isset($extensionSettings[$key])): ?>
			<span class="rozsireni-odkazy"><?php foreach ($extensionSettings[$key] as $i => [$url, $text]): ?><?= $i > 0 ? ' · ' : '' ?><a href="<?= e($url) ?>"><?= e(t($text)) ?></a><?php endforeach ?></span>
<?php endif ?>
		</span>
	</div>
<?php endforeach ?>
</div>
<p class="napoveda"><?= e(t('Links for a feature appear once it is switched on and saved.')) ?></p>
<p class="napoveda"><?= e(t('Always-on core: Pages, Collections, Media, Site appearance, Site parts, Menu, Components, Pop-ups, Users and roles, Import and export, Change log and Settings.')) ?></p>
<details class="pokrocile" id="asistent"<?= in_array('asistent', $enabledExtensions, true) ? ' open' : '' ?>>
<summary><?= e(t('Writing assistant – provider, your key and model')) ?></summary>
<input type="hidden" name="ai_poskytovatel_puvodni" value="<?= e($values['ai_provider']) ?>">
<div class="radek">
	<label for="ai_provider"><?= e(t('Provider')) ?></label>
	<div><select id="ai_provider" name="ai_provider">
<?php foreach (Kaleta\Core\Assistant::PROVIDERS as $key => [$name, , $console]): ?>
		<option value="<?= e($key) ?>"<?= $values['ai_provider'] === $key ? ' selected' : '' ?>><?= e(t($name)) ?></option>
<?php endforeach ?>
	</select>
	<span class="napoveda"><?= e(t('Create a key with the provider:')) ?>
<?php foreach (Kaleta\Core\Assistant::PROVIDERS as [$name, , $console]): ?>
		<a href="<?= e($console) ?>" target="_blank" rel="noopener"><?= e(t($name)) ?></a>
<?php endforeach ?>
		· <?= e(t('You pay only for actual use; one suggestion costs a fraction of a cent. The key is stored only on your site.')) ?></span></div>
</div>
<div class="radek">
	<label for="ai_key"><?= e(t('API key')) ?></label>
	<div><input class="textpole siroke" type="password" id="ai_key" name="ai_key" value="" autocomplete="off" placeholder="<?= $values['ai_key'] !== '' ? e(t('saved key ending in %s – enter a new one only to change it', $values['ai_key'])) : '' ?>">
<?php if ($values['ai_key'] !== ''): ?>
	<label><input type="checkbox" name="ai_key_smazat" value="1"> <?= e(t('Remove saved key')) ?></label>
<?php endif ?>
	</div>
</div>
<div class="radek">
	<label for="ai_model"><?= e(t('Model')) ?></label>
	<div><input class="textpole" id="ai_model" name="ai_model" value="<?= e($values['ai_model']) ?>" list="ai_modely" maxlength="80" spellcheck="false">
	<datalist id="ai_modely">
<?php foreach (Kaleta\Core\Assistant::MODELS as $key => $name): ?>
		<option value="<?= e($key) ?>"><?= e(t($name)) ?></option>
<?php endforeach ?>
	</datalist>
	<span class="napoveda"><?= e(t('For Claude, choose from the list (Sonnet is recommended). For other providers, enter the exact model name from their documentation – their model line-up changes often.')) ?></span></div>
</div>
<p class="napoveda"><?= e(t('The assistant only suggests – a person decides on every change. When used, the text is sent to the chosen provider; nothing is sent anywhere without clicking an assistant button.')) ?></p>
</details>
<details class="pokrocile" id="newsletter"<?= in_array('newsletter', $enabledExtensions, true) && $values['newsletter_service'] !== '' ? ' open' : '' ?>>
<summary><?= e(t('Newsletter – connection to a mailing service')) ?></summary>
<p class="napoveda"><?= e(t('After a sign-up is confirmed (double opt-in), the site adds the address to the list in your service and removes it after unsubscribing. Sending, deliverability and unsubscribing from e-mails stay with the service. The transfer runs in the background – visitors do not wait.')) ?></p>
<div class="radek">
	<label for="newsletter_service"><?= e(t('Service')) ?></label>
	<div><select id="newsletter_service" name="newsletter_service">
		<option value=""><?= e(t('none – you export subscribers to CSV')) ?></option>
<?php foreach (Kaleta\Core\Newsletter::SERVICES as $key => [$name]): ?>
		<option value="<?= e($key) ?>"<?= $values['newsletter_service'] === $key ? ' selected' : '' ?>><?= e(t($name)) ?></option>
<?php endforeach ?>
	</select>
<?php
// 3.9: the same account can relay the site's own e-mail (Settings → Mail) – said here and linked, never a key copied across
$newsletterLink = Kaleta\Core\MailServices::newsletterLink($app->settings());
if ($newsletterLink !== null && ($newsletterLink['provider'] !== '' || $newsletterLink['note'] !== '')):
    $mailUrl = $app->url('admin.php?module=settings&tab=mail');
?>
	<span class="napoveda"><?php if ($newsletterLink['provider'] !== '' && Kaleta\Core\MailServices::current($app->settings()) === $newsletterLink['provider']): ?><?= e(t('The site’s own e-mail goes through %s too (Settings → Mail), signed in with an SMTP key of its own – this API key is not used there.', $newsletterLink['service'])) ?>
<?php elseif ($newsletterLink['provider'] !== ''): ?><?= e(t('%s can also send the site’s own e-mail (password resets, enquiries, newsletters) over SMTP – choose it in Settings → Mail and paste an SMTP key there; this API key is not copied.', $newsletterLink['service'])) ?>
<?php else: ?><?= e(t($newsletterLink['note'])) ?>
<?php endif ?> <a href="<?= e($mailUrl) ?>"><?= e(t('Settings → Mail')) ?></a></span>
<?php endif ?>
	</div>
</div>
<div class="radek">
	<label for="newsletter_key"><?= e(t('API key')) ?></label>
	<div><input class="textpole siroke" type="password" id="newsletter_key" name="newsletter_key" value="" autocomplete="off" placeholder="<?= $values['newsletter_key'] !== '' ? e(t('saved key ending in %s – enter a new one only to change it', $values['newsletter_key'])) : '' ?>">
<?php if ($values['newsletter_key'] !== ''): ?>
	<label><input type="checkbox" name="newsletter_key_smazat" value="1"> <?= e(t('Remove saved key')) ?></label>
<?php endif ?>
	<span class="napoveda"><?= e(t('Create the key in your service account (API, integrations). For SmartEmailing, enter the user name and the key separated by a colon. The key is stored only on your site and is never shown over MCP.')) ?></span></div>
</div>
<div class="radek">
	<label for="newsletter_list"><?= e(t('List')) ?></label>
	<div><input class="textpole" id="newsletter_list" name="newsletter_list" value="<?= e($values['newsletter_list']) ?>" maxlength="64" spellcheck="false">
	<span class="napoveda"><?= e(t('The ID of the list (Brevo, Ecomail, SmartEmailing), group (MailerLite) or audience (Mailchimp) – you find it in the list settings in the service.')) ?></span></div>
</div>
<div class="radek">
	<label for="newsletter_webhook"><?= e(t('Webhook URL')) ?></label>
	<div><input class="textpole siroke" type="url" id="newsletter_webhook" name="newsletter_webhook" value="<?= e($values['newsletter_webhook']) ?>" placeholder="https://hook.eu1.make.com/…">
	<span class="napoveda"><?= e(t('Only for “Another service via a webhook”: the site sends JSON with the event novy_odberatel or odhlaseni_odberu and the e-mail.')) ?></span></div>
</div>
</details>
<?php if (in_array('claude', $enabledExtensions, true)): ?>
<p class="hlaska"><?= e(t('How to connect Claude, its instructions, its guardrails and every connection are in')) ?> <a href="<?= e($app->url('admin.php?module=claude_settings#how-to-connect')) ?>"><?= e(t('Claude settings')) ?></a>.</p>
<?php endif ?>
