<?php

declare(strict_types=1);

/* ---------- update: cleaning up files the new release no longer contains ---------- */
$cleanup = sys_get_temp_dir() . '/kaleta-uklid-' . bin2hex(random_bytes(4));
mkdir($cleanup . '/system/stare', 0775, true);
mkdir($cleanup . '/media', 0775, true);
foreach (['index.php', 'system/stare/zrusene.php', 'system/zustava.php', 'media/foto.jpg', 'config.php', 'vlastni.php'] as $f) {
    file_put_contents($cleanup . '/' . $f, 'x');
}
$deleted = Kaleta\Core\Updater::cleanUpObsolete($cleanup, ['index.php', 'system/stare/zrusene.php', 'system/zustava.php', 'media/foto.jpg', 'config.php', '../mimo.php'], ['index.php', 'system/zustava.php']);
check('Aktualizace: smaže jen soubor zrušený novým vydáním', $deleted, 1);
check('Aktualizace: zrušený soubor i jeho prázdná složka jsou pryč', is_dir($cleanup . '/system/stare'), false);
check('Aktualizace: chráněné cesty a vlastní soubory zůstávají', [is_file($cleanup . '/media/foto.jpg'), is_file($cleanup . '/config.php'), is_file($cleanup . '/vlastni.php'), is_file($cleanup . '/system/zustava.php')], [true, true, true, true]);
// files that ride along only for the update (old classes, the alias file of 1.4–2.0) go after it – unless edited; nothing else
mkdir($cleanup . '/system/src/Old', 0775, true);
file_put_contents($cleanup . '/system/class-aliases.php', "<?php\nreturn [];\n");
file_put_contents($cleanup . '/system/src/Old/Gone.php', 'edited');
file_put_contents($cleanup . '/system/soubory.json', json_encode(['legacy' => ['system/class-aliases.php' => hash('sha256', "<?php\nreturn [];\n"),
    'system/src/Old/Gone.php' => hash('sha256', 'original'), 'system/zustava.php' => hash('sha256', 'x')]]));
check('Update: legacy files go after the update, edited and other files stay', [Kaleta\Core\Updater::cleanUpRemoved($cleanup), is_file($cleanup . '/system/class-aliases.php'),
    is_file($cleanup . '/system/src/Old/Gone.php'), is_file($cleanup . '/system/zustava.php')], [1, false, true, true]);
exec('rm -rf ' . escapeshellarg($cleanup));

