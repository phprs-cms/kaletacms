<?php

declare(strict_types=1);

/* ---------- installer: every text has a translation in all languages ---------- */
$keys = [];
foreach (['system/views/install/form.php', 'system/views/install/done.php', 'system/src/Install/Installer.php'] as $file) {
    preg_match_all("/\\bt\\('((?:[^'\\\\]|\\\\.)*)'/", (string) file_get_contents(KALETA_ROOT . '/' . $file), $found);
    foreach ($found[1] as $text) {
        $keys[stripslashes($text)] = true;
    }
}
// the installer prints the extension and sample site cards via t() from constants
foreach ([...array_values(Kaleta\Core\Extensions::CATALOG), ...array_values(Kaleta\Builder\Library::SITES)] as $card) {
    $keys[$card['nazev'] ?? $card[0]] = true;
    $keys[$card['popis'] ?? $card[1]] = true;
}
foreach (['en', 'de', 'es'] as $code) {
    // English: Czech keys to English on top of the English source texts; German (2.5) and Spanish (3.13): every source text translated
    $dictionary = (require KALETA_ROOT . '/system/jazyky/install-' . $code . '.php') + ($code === 'en' ? require KALETA_ROOT . '/system/jazyky/install-cs.php' : []);
    // international words are not translated (the dictionary tool does not write identical entries)
    $missing = array_values(array_diff(array_keys($keys), array_keys($dictionary), ['Server', 'Port', 'E-mail', 'Newsletter', 'Whistleblowing']));
    check('instalátor: úplný slovník ' . $code, $missing, []);
}

