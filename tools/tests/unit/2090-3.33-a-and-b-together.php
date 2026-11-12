<?php

declare(strict_types=1);

use Kaleta\Core\Language;
use Kaleta\Core\WebImport;

/* ---------- 3.33 (A and B together): one flag for "not rebuilt", a Pages tab for it, attention lines in the reader's language ---------- */
$t333Move = (string) file_get_contents(KALETA_SYSTEM . '/src/Core/MoveStatus.php');
check('[source-pin] 3.33: the Move block counts the imported pages not reworked yet by the same flag as the list of pages and list_pages (ImportLayout::pending), its row leads to that tab', [
    str_contains($t333Move, "'pages_not_reworked' => count(ImportLayout::pending(\$db))"), str_contains($t333Move, 's.zmenil IS NULL'),
    str_contains($t333Move, "'admin.php?module=pages&status=layout'")],
    [true, false, true]);
$t333Pages = (string) file_get_contents(KALETA_SYSTEM . '/src/Admin/Modules/Pages.php');
check('[source-pin] 3.33: the admin list of pages has the tab "Layout not rebuilt" (status=layout) beside the others, counted from ImportLayout::pending', [
    Kaleta\Admin\Modules\Pages::LAYOUT, str_contains($t333Pages, 'self::LAYOUT => count('),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/views/admin/pages/list.php'), "'layout' => 'Layout not rebuilt'")],
    ['layout', true, true]);
$t333Web = (string) file_get_contents(KALETA_SYSTEM . '/src/Core/WebImport.php');
check('[source-pin] 3.33: a page that replaced a starter page gets the layout fingerprint like any imported page (makeRoom, then createPage, then ImportLayout::remember)', [
    strpos($t333Web, 'StarterPages::makeRoom(') < strpos($t333Web, '$ids = $this->createPage($page, $target, $language, $build, $seo);'),
    strpos($t333Web, '$ids = $this->createPage($page, $target, $language, $build, $seo);') < strpos($t333Web, 'ImportLayout::remember($this->db, $ids, $build);')],
    [true, true]);
$t333State = ['pozor' => [['/%s – the video %s could not be downloaded: add it in the builder.', 'gutenberg', '/files/tour.mp4'], 'a line from before 3.33', 42]];
check('3.33: attention lines are stored as English templates – English over MCP, the admin\'s language in the admin, an old text line as it is', [
    WebImport::attentionLines($t333State, false),
    Language::runWith('cs', fn (): array => WebImport::attentionLines($t333State, true), 'admin-')[0],
    count(Language::runWith('cs', fn (): array => WebImport::attentionLines($t333State, true), 'admin-'))],
    [['/gutenberg – the video /files/tour.mp4 could not be downloaded: add it in the builder.', 'a line from before 3.33'], '/gutenberg – video /files/tour.mp4 se nepodařilo stáhnout: vložte ho v builderu.', 2]);
check('[source-pin] 3.33: the importer never stores a translated attention line', preg_match("/self::attention\\(\\\$state, t\\(/", $t333Web), 0);
