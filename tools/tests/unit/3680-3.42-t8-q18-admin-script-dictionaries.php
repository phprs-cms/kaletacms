<?php

declare(strict_types=1);

/* ---------- 3.42 T8 (audit Q18): the dictionaries of the admin scripts are checked and filled by tools/js-dictionaries.php ---------- */

require_once KALETA_ROOT . '/tools/js-dictionaries.php';

check('3.42 Q18: every line of image/jazyky/admin-*.js is an entry, every text a script names literally has its cs/de/es entry, no translation drops a placeholder',
    kaleta_js_check(KALETA_ROOT), []);

// the tool on a small copy: a script with a new text, a dictionary missing it, one odd line, and the PHP dictionary that has it
$q18Root = sys_get_temp_dir() . '/kaleta-q18-' . bin2hex(random_bytes(4));
mkdir($q18Root . '/image/jazyky', 0777, true);
mkdir($q18Root . '/system/jazyky', 0777, true);
foreach (KALETA_JS_ADMIN_SCRIPTS as $q18Script) {
    file_put_contents($q18Root . '/image/' . $q18Script . '.js', $q18Script === 'admin' ? "x.textContent = T('Saved'); y = T(\"New text %s\", n); z = T('Uloženo');\n" : '');
}
$q18Header = "/* test */\nwindow.KALETA_PREKLAD = {\n";
file_put_contents($q18Root . '/image/jazyky/admin-en.js', $q18Header . "\t\"Uloženo\": \"Saved\"\n};\n");
file_put_contents($q18Root . '/image/jazyky/admin-de-du.js', "/* du */\nObject.assign(window.KALETA_PREKLAD, {\n});\n");
file_put_contents($q18Root . '/image/jazyky/admin-cs.js', $q18Header . "\t\"Saved\": \"Uloženo\"\n};\n");
file_put_contents($q18Root . '/image/jazyky/admin-de.js', $q18Header . "\t\"Saved\": \"Gespeichert\",\n\tbroken line\n};\n");
file_put_contents($q18Root . '/image/jazyky/admin-es.js', $q18Header . "\t\"Saved\": \"Guardado\",\n\t\"New text %s\": \"Texto nuevo\"\n};\n");
file_put_contents($q18Root . '/system/jazyky/admin-cs.php', "<?php return ['New text %s' => 'Nový text %s'];\n");
$q18Before = kaleta_js_check($q18Root);
$q18Filled = kaleta_js_fill($q18Root);
check('3.42 Q18: the check names the odd line, the missing texts and the lost placeholder; --fill adds the text the PHP dictionary has, in the same format', [
    $q18Before, $q18Filled, kaleta_js_dictionary($q18Root . '/image/jazyky/admin-cs.js'), substr((string) file_get_contents($q18Root . '/image/jazyky/admin-cs.js'), strlen($q18Header)),
], [
    ['missing – admin-cs.js: New text %s (image/admin.js)', 'not an entry – admin-de.js:4: 	broken line', 'missing – admin-de.js: New text %s (image/admin.js)', 'placeholders differ – admin-es.js: New text %s'],
    ['cs' => 1, 'de' => 0, 'es' => 0],
    [['Saved' => 'Uloženo', 'New text %s' => 'Nový text %s'], []],
    "\t\"Saved\": \"Uloženo\",\n\t\"New text %s\": \"Nový text %s\"\n};\n",
]);
array_map('unlink', [...glob($q18Root . '/image/jazyky/*'), ...glob($q18Root . '/image/*.js'), ...glob($q18Root . '/system/jazyky/*')]);
array_map('rmdir', [$q18Root . '/image/jazyky', $q18Root . '/image', $q18Root . '/system/jazyky', $q18Root . '/system', $q18Root]);
