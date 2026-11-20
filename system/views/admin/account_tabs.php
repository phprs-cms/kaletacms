<?php
/**
 * The tabs of My account (3.14): the details, the own e-mail signature and (3.37) the colleagues – for every signed-in user.
 * 3.47 (U68): six tabs (Admin\Account::TABS) – Claude only with the extension on; the org chart is the Colleagues tab by
 * department. On a phone (image/admin.css, below 600 px) the row gives way to a select of the same tabs. On my details,
 * data-account-tabs maps the fragments of old links (#claude, #two-factor…) to their tab – image/admin.js goes there.
 *
 * @var Kaleta\Core\App $app
 * @var string $current '' = the details, 'absence', 'security', 'claude', 'signature', 'colleagues'
 */
use Kaleta\Admin\Account;

$claude = Kaleta\Core\Extensions::isEnabled($app->settings(), 'claude');
$labels = ['' => t('My details'), 'absence' => t('Absence'), 'security' => t('Sign-in and security'), 'claude' => t('Claude'), 'signature' => t('My e-mail signature'), 'colleagues' => t('Colleagues')];
$tabs = array_filter($labels, fn (string|int $tab): bool => $tab !== 'claude' || $claude, ARRAY_FILTER_USE_KEY);
$fragments = $current === '' ? array_map(fn (string $tab): string => $app->url(Account::path($tab)), array_filter(Account::FRAGMENT_TABS, fn (string $tab): bool => isset($tabs[$tab]))) : [];
?>
<nav class="zalozky" aria-label="<?= e(t('My account')) ?>"<?= $fragments !== [] ? ' data-account-tabs="' . e((string) json_encode($fragments, JSON_UNESCAPED_SLASHES)) . '"' : '' ?>>
<?php foreach ($tabs as $tab => $label): ?>
	<a href="<?= e($app->url(Account::path((string) $tab))) ?>"<?= $current === (string) $tab ? ' class="aktivni" aria-current="true"' : '' ?>><?= e($label) ?></a>
<?php endforeach ?>
</nav>
<form class="zalozky-vyber" method="get" action="<?= e($app->url('admin.php')) ?>"><input type="hidden" name="action" value="account">
	<label for="ucet-zalozka"><?= e(t('My account')) ?></label>
	<select id="ucet-zalozka" name="tab" data-odeslat-pri-zmene>
<?php foreach ($tabs as $tab => $label): ?>
		<option value="<?= e((string) $tab) ?>"<?= $current === (string) $tab ? ' selected' : '' ?>><?= e($label) ?></option>
<?php endforeach ?>
	</select>
	<noscript><button class="navigace" type="submit"><?= e(t('Show')) ?></button></noscript>
</form>
