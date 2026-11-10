<?php

declare(strict_types=1);

/* ---------- 3.14: tags per language version and export format 3 (data model §3 answer 5, §4) ---------- */
check('3.14: the export writes format 2 unless asked (format 3 becomes the default in 4.0), the import reads up to format 3',
    [Kaleta\Core\SiteExport::FORMAT_VERSION, Kaleta\Core\ExportFormat::DEFAULT, Kaleta\Core\ExportFormat::NEWEST], [2, 2, 3]);
// every row of every imported table survives format 3 and back: the English keys of a table never meet another of its
// columns (a key lost or read into the wrong column would be silent data loss), the news item's own values included
$format314 = [];
foreach (Kaleta\Core\SiteImport::TABLES as $table) {
    $keys = [...($schemaColumns310[$table] ?? []), ...array_keys(Kaleta\Core\ExportFormat::COMPUTED[$table] ?? [])];
    $row = array_combine($keys, array_map(fn (string $k): string => 'v:' . $k, $keys));
    $out = Kaleta\Core\ExportFormat::rowOut($table, $row, 3);
    if (count($out) !== count($row) || Kaleta\Core\ExportFormat::rowIn($table, $out, 3) !== $row || Kaleta\Core\ExportFormat::rowOut($table, $row, 2) !== $row || $keys === []) {
        $format314[] = $table;
    }
}
check('3.14 format 3: every row of every exported table goes to English keys and back unchanged (no key collides with a column)', $format314, []);
$tableKeys314 = array_map(fn (string $t): string => Kaleta\Core\ExportFormat::tableKey($t, 3), Kaleta\Core\SiteImport::TABLES);
check('3.14 format 3: table keys are the English names of the rename map, unique, and read back to the tables of today', [
    count(array_unique($tableKeys314)) === count($tableKeys314), array_map(fn (string $k): string => Kaleta\Core\ExportFormat::tableFromKey($k, 3), $tableKeys314) === Kaleta\Core\SiteImport::TABLES,
    array_slice($tableKeys314, 0, 6), Kaleta\Core\ExportFormat::tableKey('stitky', 2), Kaleta\Core\ExportFormat::tableFromKey('news', 2)],
    [true, true, ['categories', 'tags', 'popups', 'pages', 'news', 'redirects'], 'stitky', 'news']);
check('3.14 format 3: a news item\'s author name and tag ids, the tag\'s language; the header and its version in both forms', [
    Kaleta\Core\ExportFormat::rowOut('novinky', ['idc' => 7, 'autor' => 'Jana', 'stitky' => [1, 2], 'tema' => 3, 'text' => '<p>x</p>', 'datum' => '2026-10-13 12:00:00'], 3),
    Kaleta\Core\ExportFormat::rowOut('stitky', ['ids' => 4, 'nazev' => 'Trh', 'seo_link' => 'trh', 'jazyk' => 'en', 'popis' => null], 3),
    Kaleta\Core\ExportFormat::headerOut(['format' => 'kaleta-export', 'verze_formatu' => 3, 'kaleta' => '3.14.0', 'vytvoreno' => 'now', 'nastaveni' => ['site_name' => 'A']], 3),
    Kaleta\Core\ExportFormat::headerIn(['format' => 'kaleta-export', 'format_version' => 3, 'created' => 'now', 'settings' => []]),
    Kaleta\Core\ExportFormat::headerIn(['verze_formatu' => 2, 'nastaveni' => []]), Kaleta\Core\ExportFormat::version(['format_version' => 3]), Kaleta\Core\ExportFormat::version([])],
    [['id' => 7, 'author' => 'Jana', 'tag_ids' => [1, 2], 'category_id' => 3, 'text' => '<p>x</p>', 'published_at' => '2026-10-13 12:00:00'],
    ['id' => 4, 'name' => 'Trh', 'slug' => 'trh', 'language' => 'en', 'popis' => null],
    ['format' => 'kaleta-export', 'format_version' => 3, 'kaleta' => '3.14.0', 'created' => 'now', 'settings' => ['site_name' => 'A']],
    ['format' => 'kaleta-export', 'verze_formatu' => 3, 'vytvoreno' => 'now', 'nastaveni' => []], ['verze_formatu' => 2, 'nastaveni' => []], 3, 1]);
// the reader: the same content as format 2 and as format 3 (streamed as SiteExport writes it, and formatted by hand) splits
// into the same files per table and the same header – everything after the preparation sees no difference
$rows314 = ['stitky' => [['ids' => 1, 'nazev' => 'Trh', 'seo_link' => 'trh', 'jazyk' => '', 'popis' => null, 'obrazek' => ''], ['ids' => 2, 'nazev' => 'Market', 'seo_link' => 'trh', 'jazyk' => 'en', 'popis' => '<p>EN</p>', 'obrazek' => '']],
    'novinky' => [['idc' => 5, 'titulek' => 'Akce', 'seo_link' => 'akce', 'jazyk' => 'en', 'tema' => 3, 'datum' => '2026-10-13 12:00:00', 'autor' => 'Jana', 'stitky' => [2]]],
    'casti' => [['typ' => 'hlavicka', 'jazyk' => '', 'varianta' => '', 'stranky' => '[1]', 'stavba' => '{"v":1,"deti":[]}']],
    'facts' => [['fact_key' => 'phone', 'language' => '', 'value' => '+420 1']]];
$write314 = function (int $format, bool $pretty) use ($rows314): string {
    $header = Kaleta\Core\ExportFormat::headerOut(['format' => 'kaleta-export', 'verze_formatu' => $format, 'kaleta' => '3.14.0', 'vytvoreno' => '2026-10-13T12:00:00+02:00', 'nastaveni' => ['site_name' => 'Trh']], $format);
    if ($pretty) {
        foreach ($rows314 as $table => $rows) {
            $header[Kaleta\Core\ExportFormat::tableKey($table, $format)] = array_map(fn (array $r): array => Kaleta\Core\ExportFormat::rowOut($table, $r, $format), $rows);
        }

        return (string) json_encode($header, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
    $out = substr((string) json_encode($header, JSON_UNESCAPED_UNICODE), 0, -1);
    foreach ($rows314 as $table => $rows) {
        $out .= ",\n" . json_encode(Kaleta\Core\ExportFormat::tableKey($table, $format)) . ':[' . implode(',', array_map(fn (array $r): string => "\n" . json_encode(Kaleta\Core\ExportFormat::rowOut($table, $r, $format), JSON_UNESCAPED_UNICODE), $rows)) . "\n]";
    }

    return $out . "}\n";
};
$split314 = function (string $content): array {
    $work = sys_get_temp_dir() . '/kaleta-format-' . bin2hex(random_bytes(4));
    mkdir($work);
    file_put_contents($work . '/obsah.json', $content);
    [$header, $counts] = (new ReflectionMethod(Kaleta\Core\SiteImport::class, 'split'))->invoke(null, $work . '/obsah.json', $work);
    $files = [];
    foreach (glob($work . '/*.ndjson') ?: [] as $file) {
        $files[basename($file)] = (string) file_get_contents($file);
    }
    ksort($files);
    array_map('unlink', glob($work . '/*') ?: []);
    rmdir($work);
    unset($header['verze_formatu']);

    return [$header, $counts, $files];
};
$split2_314 = $split314($write314(2, false));
check('3.14 SiteImport reads format 3 (streamed and formatted by hand) into the same tables, rows and header as format 2', [
    $split314($write314(3, false)) === $split2_314, $split314($write314(3, true)) === $split2_314, $split2_314[1], $split2_314[0]['nastaveni'] ?? null,
    json_decode(explode("\n", $split2_314[2]['novinky.ndjson'] ?? '')[0], true)],
    [true, true, ['stitky' => 2, 'novinky' => 1, 'casti' => 1, 'facts' => 1], ['site_name' => 'Trh'], $rows314['novinky'][0]]);
// tags in the language version of their news item: the helper scopes the lookup like every other slug check
check('3.14: Slug::TABLES lists tags with their global and per-language keys, so the switch handles them with the others', Kaleta\Core\Slug::TABLES['stitky'] ?? null, ['ids', 'uq_stitky_seo', 'uq_stitky_jazyk_seo']);
check('3.14: the tag of a news item is created in its language (Tags::findOrCreate, used by News::tags, WpImport and Import\\Batch)', [
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Admin/Modules/Tags.php'), '\'jazyk\' => $language'),
    substr_count((string) file_get_contents(KALETA_SYSTEM . '/src/Admin/Modules/News.php') . file_get_contents(KALETA_SYSTEM . '/src/Core/WpImport.php') . file_get_contents(KALETA_SYSTEM . '/src/Import/Batch.php'), 'Tags::findOrCreate('),
    preg_match_all("/insert\\('stitky'/", implode('', array_map(fn (string $f): string => (string) file_get_contents($f), [...glob(KALETA_SYSTEM . '/src/*/*.php') ?: [], ...glob(KALETA_SYSTEM . '/src/*/*/*.php') ?: []])))],
    [true, 3, 1]);
