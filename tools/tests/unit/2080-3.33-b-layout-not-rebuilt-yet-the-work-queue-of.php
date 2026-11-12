<?php

declare(strict_types=1);

/* ---------- 3.33 (part B): "layout not rebuilt yet" – the work queue of a move in list_pages and the admin list of pages ---------- */
$l333Layout = (string) file_get_contents(KALETA_SYSTEM . '/src/Core/ImportLayout.php');
check('[source-pin] 3.33 layout_not_rebuilt: no migration – a fingerprint of the imported build in ka_import_mapa ("<page>:<sha256>", SHA2 – MySQL 9 has no SHA1), compared in the database; a page with a draft or in the trash does not wait', [
    str_contains($l333Layout, "\$page . ':' . hash('sha256', \$build)"), str_contains($l333Layout, "m.cizi_id = CONCAT(s.ids, ':', SHA2(s.stavba, 256))"),
    str_contains($l333Layout, 's.stavba_koncept IS NULL') && str_contains($l333Layout, 's.smazano IS NULL'), hash('sha256', '{"v":1}') === strtolower(hash('sha256', '{"v":1}'))],
    [true, true, true, true]);
check('[source-pin] 3.33 layout_not_rebuilt: both importers remember the build they stored (WordPress keeps following it while the page waits), list_pages and the admin list show it', [
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Core/WebImport.php'), 'ImportLayout::remember($this->db, $ids, $build);'),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Core/WpImport.php'), 'ImportLayout::remember($this->db, $ids, $build);'),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Core/WpImport.php'), 'ImportLayout::replaced($this->db, $id, (string) $before, $build[\'stavba\']);'),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Mcp/Handlers/PageTools.php'), "['layout_not_rebuilt' => true]"),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/views/admin/pages/list.php'), "e(t('layout not rebuilt yet'))"),
    str_contains((string) json_encode(Kaleta\Mcp\Translator::listAll(Kaleta\Mcp\Tools::definitions())), 'layout_not_rebuilt: an imported page whose build is still the imported text.'),
    in_array('list_pages', Kaleta\Mcp\Catalog::ESSENTIALS, true)],
    [true, true, true, true, true, true, true]);
