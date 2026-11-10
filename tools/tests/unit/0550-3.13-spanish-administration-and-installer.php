<?php

declare(strict_types=1);

/* ---------- 3.13: Spanish administration and installer ---------- */
// every text the German admin, its scripts and the installer translate has a Spanish translation with the same placeholders,
// tags and {{tokens}}; the Spanish admin says "tú" throughout (the texts for visitors in es.php keep "usted")
$esPlaceholders = function (string $text): array { preg_match_all('/%(?:\d+\$)?[sd]|<\/?[a-z]+|\{\{[^}]*\}\}/i', $text, $m); sort($m[0]); return $m[0]; };
$esGaps = [];
$esUsted = [];
foreach ([[(require KALETA_SYSTEM . '/jazyky/admin-de.php') + (require KALETA_SYSTEM . '/jazyky/admin-cs.php'), require KALETA_SYSTEM . '/jazyky/admin-es.php', 'admin-es.php'],
    [$jsDictionary(KALETA_ROOT . '/image/jazyky/admin-de.js') + $jsDictionary(KALETA_ROOT . '/image/jazyky/admin-cs.js'), $jsDictionary(KALETA_ROOT . '/image/jazyky/admin-es.js'), 'admin-es.js'],
    [require KALETA_SYSTEM . '/jazyky/install-de.php', require KALETA_SYSTEM . '/jazyky/install-es.php', 'install-es.php']] as [$sourceTexts, $esTexts, $esFile]) {
    foreach ($sourceTexts as $key => $_) {
        $key = (string) $key;
        if (!isset($esTexts[$key])) { $esGaps[] = $esFile . ' missing: ' . $key; }
        elseif ($esPlaceholders($key) !== $esPlaceholders((string) $esTexts[$key]) && !in_array($key, ['datum_format', 'datum_slovy'], true)) { $esGaps[] = $esFile . ' placeholders: ' . $key; }
    }
    $esUsted = [...$esUsted, ...array_keys(array_filter($esTexts, fn ($v): bool => preg_match('/\b(usted|ustedes)\b/iu', (string) $v) === 1))];
}
check('3.13: the Spanish admin, its scripts and the installer cover every German text, with the same placeholders', array_slice($esGaps, 0, 5), []);
check('3.13: the Spanish admin addresses the user with tú, never usted', array_slice($esUsted, 0, 5), []);
check('3.13: Spanish is an admin and installation language; its dictionaries load, English fills nothing in', [
    Kaleta\Core\Language::ADMIN_LANGUAGES['es'] ?? null, count($jsDictionary(KALETA_ROOT . '/image/jazyky/admin-es.js')) > 2500,
    Kaleta\Core\Language::runWith('es', fn (): string => t('Settings') . '|' . t('Sign out') . '|' . t('Draft'), 'admin-'),
    Kaleta\Core\Language::runWith('es', fn (): string => t('Database') . '|' . t('Kaleta installation'), 'install-'),
    Kaleta\Core\Language::runWith('es', fn (): string => t('datum_format'), 'admin-'),
], ['Español', true, 'Ajustes|Cerrar sesión|Borrador', 'Base de datos|Instalación de Kaleta', 'd/m/Y']);

check('2.10: alert e-mails – errors and the warnings that need the owner, not every warning', array_column(Kaleta\Core\Alerts::worth([
    ['id' => 1, 'created_at' => '', 'type' => 'backup.failed', 'severity' => 'error', 'message' => '', 'data' => []],
    ['id' => 2, 'created_at' => '', 'type' => 'firewall.blocked', 'severity' => 'warning', 'message' => '', 'data' => []],
    ['id' => 3, 'created_at' => '', 'type' => 'content.review', 'severity' => 'warning', 'message' => '', 'data' => []],
    ['id' => 4, 'created_at' => '', 'type' => 'notfound.spike', 'severity' => 'warning', 'message' => '', 'data' => []]]), 'id'), [1, 3, 4]);
