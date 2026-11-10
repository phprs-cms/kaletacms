<?php

declare(strict_types=1);

/* ---------- 3.5 First hour: no Czech for English administrations ---------- */
// UXA-05: a Czech literal outside t() reaches an English administration as it is ("Není vyplněný e-mail webu…" on the Mail tab)
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(KALETA_ROOT . '/tools/find-czech.php') . ' --php', $czechLiterals);
check('3.5 UXA-05: tools/find-czech.php --php finds no Czech literal outside t() in the PHP sources', $czechLiterals, []);
check('3.5 UXA-05: the mail error without a sender is an English source text with Czech and German translations', [
    Kaleta\Core\Language::runWith('en', fn (): string => t('Neither the site e-mail (Settings → General) nor a sender address is filled in.'), 'admin-'),
    Kaleta\Core\Language::runWith('cs', fn (): string => t('Neither the site e-mail (Settings → General) nor a sender address is filled in.'), 'admin-'),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Core/Mail.php'), "'Neither the site e-mail (Settings → General) nor a sender address is filled in.'")],
    ['Neither the site e-mail (Settings → General) nor a sender address is filled in.', 'Není vyplněný e-mail webu (Nastavení → Základní) ani adresa odesílatele.', true]);
// UXA-06: no country is claimed unless the site sets one (the default was CZ); the retention hint falls back to the site language
check('3.5 UXA-06: the company country is empty by default, may be saved empty, and the structured data name a country only when it is set', [
    Kaleta\Core\Settings::DEFAULTS['company_country'], preg_match('/^([A-Z]{2})?$/', ''), Kaleta\Core\Jobs::suggestedRetention('', 'en'), Kaleta\Core\Jobs::suggestedRetention('', 'cs'),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Admin/Modules/Settings.php'), "'company_country' => 'vzor:/^([A-Z]{2})?$/'")],
    ['', 1, null, ['CZ', 6], true]);
check('3.5 UXA-06: the builder\'s attribute example, the URL example and the news address are not Czech in English', [
    str_contains((string) file_get_contents(KALETA_ROOT . '/image/stavitel.js'), 'data-sledovat'),
    Kaleta\Core\Language::runWith('en', fn (): string => t('generated from the title, e.g. about-us'), 'admin-'),
    Kaleta\Core\Language::runWith('cs', fn (): string => t('generated from the title, e.g. about-us'), 'admin-'),
    Kaleta\Core\Language::runWith('cs', fn (): string => t(Kaleta\Core\Extensions::CATALOG['novinky'][1], '/novinky'), 'admin-'),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Install/Installer.php'), "[\$x('Úvod'), slugify(\$x('Úvod')), 0,")],
    [false, 'generated from the title, e.g. about-us', 'vytvoří se z názvu, např. o-nas', 'Aktuality a blog: výpis /novinky s kategoriemi a štítky, RSS, prvek Novinky v builderu a odkaz v automatickém menu.', true]);
// UXA-22: Czech names of admin screens are inflected ("na stránce Stav systému", not "v Stav systému"); no English "landing page" in Czech chips
$czechAdmin = (string) file_get_contents(KALETA_SYSTEM . '/jazyky/admin-cs.php') . (string) file_get_contents(KALETA_ROOT . '/image/jazyky/admin-cs.js');
check('3.5 UXA-22: Czech copy – inflected screen names, no "landing page" in the Ask Claude chip', [
    preg_match('/ v (Stav systému|Můj účet)\b/u', $czechAdmin), Kaleta\Core\Language::runWith('cs', fn (): string => t(Kaleta\Core\AskClaude::EXAMPLES['landing'][1]), 'admin-')],
    [0, 'Připravte prodejní stránku pro naši jarní kampaň s poptávkovým formulářem.']);
