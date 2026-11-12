<?php
/**
 * Settings: tabs + the form of the selected tab (settings/<tab>.php).
 *
 * @var Kaleta\Admin\Modules\Settings $module
 * @var string $csrf
 * @var string $tab
 * @var array<string, string> $values
 * @var list<array{skupina:string, nazev:string, stav:string, info:string}> $checks
 * @var string $siteUrl
 * @var list<string> $enabledExtensions
 * @var list<array{soubor:string, velikost:int, cas:int}> $backups
 * @var array<string, mixed>|null $update
 * @var array<string, mixed>|null $major  the next major version on offer (3.15, Core\Updater::nextMajor), Backups and updates and System status only
 * @var list<array{kategorie:string, pocet:int}> $consents
 * @var list<string> $errorLog  last lines of the error log
 * @var string $remoteStatus  result of the last backup upload off the server
 * @var string $tasksToken  secret part of the /ulohy url for cron
 * @var array<string, mixed>|null $domainWatch  the last domain and mail check (Core\DomainWatch), null = none yet
 * @var array<int, string> $pages  pages for choosing the home page (the General tab)
 * @var list<array{name: string, provider: string, purpose: string, duration: string, category: string}> $cookieTable  cookies and storage the site uses (2.14, the Privacy tab)
 * @var array{time?: int, pages?: int, cookies?: list<string>, error?: string} $cookieScan  the last scan of the site's own pages (2.14)
 * @var array<string, mixed>|null $statementPage  the accessibility statement page created from the audit (2.14), null = none yet
 * @var array{ids: int, titulek: string, seo_link: string}|null $privacyPage  the privacy policy page (3.20, the Privacy tab), null = none
 */
use Kaleta\Admin\Modules\Settings;

/** Form row: $field('key', 'Label', 'text|radky|kod|ano|cislo|url|email', 'hint', [attributes]) */
$invalidFields ??= [];
$field = function (string $key, string $labelText, string $kind = 'text', string $hint = '', string $attributes = '') use ($values, $module, $invalidFields): void {
    $h = $values[$key] ?? '';
    if (in_array($key, $invalidFields, true)) {
        $attributes .= ' aria-invalid="true"'; // an unsaved value to fix (the message at the top says what is wrong)
    }
    $labelText = t($labelText);
    $hint = $hint === '' ? '' : t($hint);
    // a hint without its own HTML: menu paths ("Nastavení → Pošta") turn into links
    $hintHtml = $hint !== '' ? '<span class="napoveda">' . (str_contains($hint, '<') ? $hint : $module->hint($hint)) . '</span>' : '';
    echo '<div class="radek">';
    if ($kind === 'ano') {
        // 3.32 (U49): the box is named by its row label ("Automatic updates"), not by the word next to it ("Yes")
        echo '<span class="popisek" id="' . e($key) . '-popisek">' . e($labelText) . '</span><div class="volby"><label><input type="checkbox" aria-labelledby="' . e($key) . '-popisek" name="' . e($key) . '" value="1"' . ($h === '1' ? ' checked' : '') . '> ' . e(t('Yes')) . '</label>' . $hintHtml . '</div>';
    } elseif ($kind === 'radky' || $kind === 'kod') {
        echo '<label for="' . e($key) . '">' . e($labelText) . '</label><div><textarea class="textbox nizky' . ($kind === 'kod' ? ' kod' : '') . '" id="' . e($key) . '" name="' . e($key) . '" rows="4" ' . $attributes . '>' . e($h) . '</textarea>' . $hintHtml . '</div>';
    } else {
        $type = ['cislo' => 'number', 'url' => 'url', 'email' => 'email'][$kind] ?? 'text';
        echo '<label for="' . e($key) . '">' . e($labelText) . '</label><div><input class="textpole' . ($type === 'number' ? '' : ' siroke') . '" type="' . $type . '" id="' . e($key) . '" name="' . e($key) . '" value="' . e($h) . '" ' . $attributes . '>' . $hintHtml . '</div>';
    }
    echo '</div>';
};
?>
<?php if ($module::IDENT === 'settings'): ?>
<?php // 3.23 (C8): the console tab shows only with a named agency or a pairing – opened by its address, it is in the row anyway
$navigation = Settings::visibleTabs($app->settings()) + (isset(Settings::TABS[$tab]) && !isset(Settings::MOVED_TABS[$tab]) ? [$tab => Settings::TABS[$tab]] : []);
$navigation = array_intersect_key(Settings::TABS, $navigation); // in the order of TABS
// 3.23 (C12): a link to a field that left General (tab=general#additional_languages) is taken on to its tab by image/admin.js
$movedFields = $tab === 'general' ? array_map(fn (string $to): string => $module->url('', ['tab' => $to]), Settings::movedFields($app->settings())) : []; ?>
<nav class="zalozky" aria-label="<?= e(t('Settings sections')) ?>"<?= $movedFields !== [] ? ' data-pole-jinde="' . e((string) json_encode($movedFields, JSON_UNESCAPED_SLASHES)) . '"' : '' ?>>
<?php foreach ($navigation as $key => $name): ?>
	<a href="<?= e($module->url('', ['tab' => $key])) ?>"<?= $tab === $key ? ' class="aktivni" aria-current="page"' : '' ?>><?= e(t($name)) ?></a>
<?php endforeach ?>
</nav>
<?php // 3.23.1 (N-C12): on a phone (image/admin.css, below 600 px) the row above gives way to this select of the same tabs ?>
<form class="zalozky-vyber" method="get" action="<?= e($app->url('admin.php')) ?>"><input type="hidden" name="module" value="settings">
	<label for="zalozka-vyber"><?= e(t('Settings')) ?></label>
	<select id="zalozka-vyber" name="tab" data-odeslat-pri-zmene>
<?php foreach ($navigation as $key => $name): ?>
		<option value="<?= e($key) ?>"<?= $tab === $key ? ' selected' : '' ?>><?= e(t($name)) ?></option>
<?php endforeach ?>
	</select>
	<noscript><button class="navigace" type="submit"><?= e(t('Show')) ?></button></noscript>
</form>
<?php endif ?>
<?php $proof ??= null; $proofPasskey = $proof !== null && $proof['passkeyOnly'] && !$proof['fresh']; // 3.35 review M3: "Confirm with your passkey" (views/admin/settings/proof.php) ?>
<form class="formular" method="post" action="<?= e($module->url('save')) ?>"<?= $proofPasskey ? ' data-klice="' . e($app->url('admin.php?action=account')) . '"' : '' ?>>
<?php /* the first submit button in the form determines what Enter does: save the settings (not a backup, an update check or a test e-mail) */ ?>
<button type="submit" class="vychozi-odeslani" tabindex="-1" aria-hidden="true"><?= e(t('Save settings')) ?></button>
<?= $csrf ?>
<input type="hidden" name="tab" value="<?= e($tab) ?>">
<?php if ($tab === 'general' && $module::IDENT === 'settings'): ?><input type="hidden" name="general_split" value="1"><?php /* 3.23: only the General fields – Settings::actionSave */ endif ?>
<?php require __DIR__ . '/' . $tab . '.php'; ?>
<?php if (!in_array($tab, ['health', 'console'], true)): ?>
<p class="tlacitka"><input class="tl" type="submit" value="<?= e(t('Save settings')) ?>"></p>
<?php endif ?>
</form>
<?php if ($proofPasskey): ?>
<script src="<?= e($app->url('image/klice.js')) ?>?v=<?= e(KALETA_VERSION) ?>" defer></script>
<?php endif ?>
