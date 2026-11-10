<?php

declare(strict_types=1);

/* ---------- German in two registers: formal (Sie) and informal (du), issue #20 ---------- */
// A form of address is a capitalised Sie/Ihr… inside a sentence; at the start of a sentence (or after a quotation mark) it is the application or a term ("Sie handelt…").
$addressForms = static function (string $text): int {
    $count = 0;
    preg_match_all('/\b(Sie|Ihr|Ihre|Ihren|Ihrem|Ihrer|Ihres|Ihnen)\b/u', $text, $m, PREG_OFFSET_CAPTURE);
    foreach ($m[0] as [, $offset]) {
        $before = rtrim(substr($text, 0, $offset));
        if ($before !== '' && preg_match('/[.!?:\n„“"»(–]$/u', $before) !== 1) {
            $count++;
        }
    }

    return $count;
};
$scriptDictionary = static function (string $file): array {
    return json_decode(rtrim(preg_replace('/^[^{]*/', '', (string) file_get_contents($file), 1), " \n;)"), true) ?? [];
};
foreach (['admin-de', 'de', 'install-de'] as $set) {
    $base = require KALETA_ROOT . '/system/jazyky/' . $set . '.php';
    $overlay = require KALETA_ROOT . '/system/jazyky/' . $set . '-du.php';
    $formalLeft = array_keys(array_filter($overlay, fn ($v, $k): bool => $addressForms((string) $v) > 0, ARRAY_FILTER_USE_BOTH));
    $withoutOverlay = array_keys(array_filter($base, fn ($v, $k): bool => is_string($v) && $addressForms($v) > 0 && !isset($overlay[$k]), ARRAY_FILTER_USE_BOTH));
    check('Deutsch du: ' . $set . '-du.php – only keys of the base dictionary, no Sie/Ihr in the overlay, every base string with a form of address has its counterpart',
        [array_keys(array_diff_key($overlay, $base)), $formalLeft, $withoutOverlay], [[], [], []]);
}
$baseScripts = $scriptDictionary(KALETA_ROOT . '/image/jazyky/admin-de.js');
$overlayScripts = $scriptDictionary(KALETA_ROOT . '/image/jazyky/admin-de-du.js');
check('Deutsch du: admin-de-du.js – only keys of admin-de.js, no Sie/Ihr in the overlay, every script text with a form of address has its counterpart', [
    array_keys(array_diff_key($overlayScripts, $baseScripts)),
    array_keys(array_filter($overlayScripts, fn ($v): bool => $addressForms((string) $v) > 0)),
    array_keys(array_filter($baseScripts, fn ($v, $k): bool => $addressForms((string) $v) > 0 && !isset($overlayScripts[$k]), ARRAY_FILTER_USE_BOTH)),
], [[], [], []]);
// every Claude panel suggestion (AskClaude::EXAMPLES) follows the register of the administration
$suggestionsLeft = [];
foreach (['formal', 'informal'] as $register) {
    foreach (Kaleta\Core\AskClaude::EXAMPLES as $key => [, $suggestion]) {
        $text = Kaleta\Core\Language::runWith('de', fn (): string => t($suggestion), 'admin-', $register);
        if (($addressForms($text) > 0) !== false && $register === 'informal') {
            $suggestionsLeft[] = $register . ':' . $key;
        }
    }
}
check('Deutsch du: the Claude panel suggestions have no Sie in the informal administration', $suggestionsLeft, []);
check('Deutsch du: the register picks the dictionary (site, admin), is restored after runWith, and English ignores it', [
    Kaleta\Core\Language::runWith('de', fn (): string => t('Enter at least 3 characters.'), '', 'formal'),
    Kaleta\Core\Language::runWith('de', fn (): string => t('Enter at least 3 characters.'), '', 'informal'),
    Kaleta\Core\Language::runWith('de', fn (): string => t('Enter at least 3 characters.'), 'admin-', 'informal'),
    Kaleta\Core\Language::runWith('en', fn (): string => t('Enter at least 3 characters.'), '', 'informal'),
    Kaleta\Core\Language::runWith('de', fn (): string => Kaleta\Core\Language::runWith('de', fn (): string => 'x', '', 'informal') . Kaleta\Core\Language::register(), '', 'formal'),
    Kaleta\Core\Language::runWith('de', fn (): string => t('Enter at least 3 characters.'), '', 'nonsense'),
], ['Geben Sie mindestens 3 Zeichen ein.', 'Gib mindestens 3 Zeichen ein.', 'Gib mindestens 3 Zeichen ein.', 'Enter at least 3 characters.', 'xformal', 'Geben Sie mindestens 3 Zeichen ein.']);
Kaleta\Core\Language::setSiteRegister('informal');
Kaleta\Core\Language::setAdminRegister('formal');
check('Deutsch du: without an explicit register the site follows german_register and the administration the user’s choice', [
    Kaleta\Core\Language::runWith('de', fn (): string => t('Enter at least 3 characters.')),
    Kaleta\Core\Language::runWith('de', fn (): string => t('Enter at least 3 characters.'), 'admin-'),
], ['Gib mindestens 3 Zeichen ein.', 'Geben Sie mindestens 3 Zeichen ein.']);
Kaleta\Core\Language::setSiteRegister('formal');
Kaleta\Core\Language::set('cs', 'admin-');
// the form of address of the visitors reaches Claude: the connection instructions, site_info and the text copied from the dashboard
check('Deutsch du: the form of address of the site travels with the site export (and is checked on import)', [in_array('german_register', Kaleta\Core\SiteExport::SETTINGS, true), Kaleta\Admin\Modules\Settings::verifyValue('german_register', 'informal'), Kaleta\Admin\Modules\Settings::verifyValue('german_register', 'x')], [true, 'informal', null]);
check('Deutsch du: Language::visitorAddress – only a site with a German version has one', [
    Kaleta\Core\Language::visitorAddress($reportSettings(['site_language' => 'de', 'german_register' => 'informal'])),
    Kaleta\Core\Language::visitorAddress($reportSettings(['site_language' => 'de'])),
    Kaleta\Core\Language::visitorAddress($reportSettings(['site_language' => 'cs', 'additional_languages' => 'de', 'german_register' => 'informal', 'extensions' => 'jazyky'])),
    Kaleta\Core\Language::visitorAddress($reportSettings(['site_language' => 'en', 'german_register' => 'informal'])),
    Kaleta\Core\Language::normalizeRegister('x'),
], ['informal', 'formal', 'informal', null, 'formal']);
$promptIn = fn (string $language, ?string $address, string $register = 'formal'): string => Kaleta\Core\Language::runWith($language, fn (): string => Kaleta\Core\AskClaude::prompt('https://example.com', $address), 'admin-', $register);
check('Deutsch du: the text copied for Claude names the form of address of the visitors – in the language and register of the administration', [
    str_contains($promptIn('en', 'informal'), 'informal “du”'), str_contains($promptIn('en', 'formal'), 'formal “Sie”'), str_contains($promptIn('en', null), 'German'),
    str_contains($promptIn('de', 'formal'), 'Schreiben Sie deutsche Texte'), str_contains($promptIn('de', 'formal', 'informal'), 'Schreibe deutsche Texte'),
], [true, true, false, true, true]);

