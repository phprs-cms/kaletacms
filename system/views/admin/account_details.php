<?php
/**
 * My account → My details (3.47, U68): name, e-mail, the profile and the administration language, then how notifications
 * come by e-mail (#notifications – the address e-mails link to).
 *
 * @var Kaleta\Core\App $app
 * @var array<string, mixed> $user
 * @var string $csrf
 * @var string $action  the address the forms post to (this tab)
 * @var string $proofNote  the note shown instead of a password field to an account that signs in with a passkey only
 * @var bool $passkeyOnly
 * @var bool $fresh
 */
?>
<form class="formular" method="post" action="<?= $action ?>">
<?= $csrf ?><input type="hidden" name="co" value="profil">
<fieldset><legend><?= e(t('My details')) ?></legend>
<div class="radek"><span class="popisek"><?= e(t('User name')) ?></span><div><?= e($user['user']) ?> <span class="napoveda"><?= e(t('Changed by an administrator in Users.')) ?></span></div></div>
<div class="radek"><label for="jmeno"><?= e(t('Jméno')) ?></label><div><input class="textpole siroke" type="text" id="jmeno" name="jmeno" value="<?= e($user['jmeno']) ?>" maxlength="100"><span class="napoveda"><?= e(t('Shown with news items on the site.')) ?></span></div></div>
<div class="radek"><label for="email"><?= e(t('Email')) ?></label><input class="textpole siroke" type="email" id="email" name="email" value="<?= e($user['email']) ?>" maxlength="190"></div>
<?php if ($passkeyOnly): ?>
<?= $proofNote ?>
<?php else: ?>
<div class="radek"><label for="email-heslo"><?= e(t('Current password')) ?></label><div><input class="textpole" type="password" id="email-heslo" name="soucasne" size="30" autocomplete="current-password"><span class="napoveda"><?= e(t('Needed only when you change the e-mail – a password reset goes there. The old address gets a notice.')) ?></span></div></div>
<?php endif ?>
<div class="radek"><label for="url"><?= e(t('My website')) ?></label><input class="textpole siroke" type="url" id="url" name="url" value="<?= e($user['url']) ?>" maxlength="255" placeholder="https://"></div>
<div class="radek"><label for="pozice"><?= e(t('Position in the company')) ?></label><div><input class="textpole siroke" type="text" id="pozice" name="pozice" value="<?= e($user['pozice']) ?>" maxlength="100" placeholder="<?= e(t('e.g. head of sales')) ?>"><span class="napoveda"><?= e(t('Shown to your colleagues and in your e-mail signature.')) ?></span></div></div>
<div class="radek"><label for="phone"><?= e(t('Work phone')) ?></label><div><input class="textpole" type="tel" id="phone" name="phone" value="<?= e((string) ($user['phone'] ?? '')) ?>" maxlength="30" autocomplete="tel" placeholder="+420 777 123 456"><span class="napoveda"><?= e(t('Shown to your colleagues and in your e-mail signature. Without it, the signature has the company phone.')) ?></span></div></div>
<div class="radek"><label for="foto"><?= e(t('My photo')) ?></label><div><input class="textpole siroke" type="text" id="foto" name="foto" value="<?= e($user['foto']) ?>" maxlength="255" data-obrazek><span class="napoveda"><?= e(t('A square photo, 300 × 300 px is enough.')) ?></span></div></div>
<div class="radek"><label for="bio"><?= e(t('A few sentences about me')) ?></label><div><textarea class="textbox nizky" id="bio" name="bio" rows="4" maxlength="1200"><?= e((string) $user['bio']) ?></textarea><span class="napoveda"><?= e(t('Shown as a short profile under your news items. What you do in the company and your background.')) ?></span></div></div>
<div class="radek"><label for="jazyk"><?= e(t('Administration language')) ?></label><div><select id="jazyk" name="jazyk">
<?php
// the selected language is the one the admin actually runs in (without an own choice, the site language, if the admin supports it)
$adminLanguage = Kaleta\Core\Language::base($user['jazyk'] ?: Kaleta\Core\Language::defaults($app->settings()));
$adminLanguage = isset(Kaleta\Core\Language::ADMIN_LANGUAGES[$adminLanguage]) ? $adminLanguage : 'cs';
foreach (Kaleta\Core\Language::ADMIN_LANGUAGES as $languageCode => $languageName): ?>
	<option value="<?= e($languageCode) ?>"<?= $adminLanguage === $languageCode ? ' selected' : '' ?>><?= e($languageName) ?></option>
<?php endforeach ?>
</select><span class="napoveda">Language · Jazyk</span></div></div>
<?php if ($adminLanguage === 'de'): ?>
<div class="radek"><label for="register"><?= e(t('Form of address in German')) ?></label><div><select id="register" name="register">
	<option value="formal"<?= ($user['register'] ?? '') !== 'informal' ? ' selected' : '' ?>><?= e(t('Formal (Sie)')) ?></option>
	<option value="informal"<?= ($user['register'] ?? '') === 'informal' ? ' selected' : '' ?>><?= e(t('Informal (du)')) ?></option>
</select><span class="napoveda"><?= e(t('How the German administration addresses you. The texts for visitors have their own setting in Settings.')) ?></span></div></div>
<?php endif ?>
</fieldset>
<p class="tlacitka"><input class="tl" type="submit" value="<?= e(t('Save details')) ?>"></p>
</form>

<form class="formular" method="post" action="<?= $action ?>" id="notifications">
<?= $csrf ?><input type="hidden" name="co" value="notifications">
<fieldset><legend><?= e(t('Notifications by e-mail')) ?></legend>
<p class="napoveda"><?= e(t('Notifications tell you when a colleague mentions you, comments on what you are responsible for or assigns you something, and when a review date comes. They are always under the bell at the top of the administration; here you choose whether they also come by e-mail.')) ?></p>
<?php $notifyEmail = Kaleta\Core\Inbox::emailMode((string) ($user['notify_email'] ?? '')); ?>
<div class="radek"><span class="popisek" id="notify_email_popisek"><?= e(t('Email')) ?></span><div role="radiogroup" aria-labelledby="notify_email_popisek">
<div class="karty-volby karty-volby-text">
<?php foreach ([
    'instant' => ['Right away', 'One e-mail for each notification, as it happens.'],
    'daily' => ['Once a day', 'One summary in the morning (around 8:00) of what you have not read yet.'],
    'off' => ['Never', 'No e-mail – only the bell in the administration.'],
] as $key => [$name, $description]): ?>
	<label class="karta-volba"><input type="radio" name="notify_email" value="<?= e($key) ?>"<?= $notifyEmail === $key ? ' checked' : '' ?>><strong><?= e(t($name)) ?></strong><span><?= e(t($description)) ?></span></label>
<?php endforeach ?>
</div>
<?php if (Kaleta\Core\Mail::addresses((string) $user['email']) === []): ?>
<span class="napoveda"><?= e(t('Your account has no e-mail address – add it above, otherwise notifications are only under the bell.')) ?></span>
<?php endif ?>
</div></div>
</fieldset>
<p class="tlacitka"><input class="tl" type="submit" value="<?= e(t('Save the choice')) ?>"></p>
</form>
