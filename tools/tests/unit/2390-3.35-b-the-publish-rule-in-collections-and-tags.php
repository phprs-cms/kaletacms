<?php

declare(strict_types=1);

use Kaleta\Admin\Modules\Collections;
use Kaleta\Admin\Modules\Roles;
use Kaleta\Admin\Modules\Tags;
use Kaleta\Core\Auth;

/* ---------- 3.35 B6 (N107, N108): the publish rule in Collections and Tags ---------- */
check('3.35 B6 N107: a draft is a hidden item that is not scheduled', [
    Collections::isDraft(['zobrazit' => 0, 'zverejnit_od' => null]), Collections::isDraft(['zobrazit' => 1, 'zverejnit_od' => null]),
    Collections::isDraft(['zobrazit' => 0, 'zverejnit_od' => '2030-01-01 10:00:00']), Collections::isDraft([]),
], [true, false, false, false]);
check('3.35 B6 N108: tags are renamed, merged and deleted only by people who may publish; the Writer preset keeps the Tags section (the list) at the author level', [
    Tags::LIVE_ACTIONS, Roles::PRESETS['writer'][2] === Auth::AUTHOR && in_array('tags', Roles::PRESETS['writer'][3], true),
    // the Client preset is editor-level and keeps publishing in Collections and Tags
    Roles::PRESETS['client'][2], Roles::PRESETS['office'][2] === Auth::AUTHOR && !in_array('collections', Roles::PRESETS['office'][3], true),
], [['save', 'delete'], true, Auth::EDITOR, true]);
$i335fAdmin = (string) file_get_contents(KALETA_SYSTEM . '/src/Admin/Modules/Collections.php');
$i335fMcp = (string) file_get_contents(KALETA_SYSTEM . '/src/Mcp/Handlers/CollectionTools.php');
// the body of one method (up to the first line "    }" after it), so a needle never comes from the next method
$i335fAction = function (string $source, string $method, string $needle): bool {
    $start = strpos($source, 'function ' . $method . '(');
    $end = $start === false ? false : strpos($source, "\n    }\n", $start);

    return $start !== false && $end !== false && str_contains(substr($source, $start, $end - $start), $needle);
};
check('3.35 B6 N107: admin – every action that changes an item or a category on the site checks the permission to publish', [
    $i335fAction($i335fAdmin, 'actionSaveItem', '$this->requirePublish('), $i335fAction($i335fAdmin, 'actionSaveItem', "\$row['zobrazit'] = 0;"),
    $i335fAction($i335fAdmin, 'actionBulkItems', '!$mayPublish && !self::isDraft($p)'), $i335fAction($i335fAdmin, 'actionBulkItems', "!\$mayPublish && \$action === 'zobrazit'"),
    $i335fAction($i335fAdmin, 'actionRestoreItemVersion', '$this->requirePublish($item)'), $i335fAction($i335fAdmin, 'actionDeleteItem', '$this->requirePublish('),
    $i335fAction($i335fAdmin, 'actionDeleteItemPermanently', '!$this->app->auth()->canPublish()'),
    $i335fAction($i335fAdmin, 'actionSaveCategory', "'visible' => \$mayPublish && \$r->postBool('visible')"), $i335fAction($i335fAdmin, 'actionDeleteCategory', 'LIVE_CATEGORY_REFUSAL'),
    // a hidden copy and a restore from the trash (as hidden) stay open to the role
    !$i335fAction($i335fAdmin, 'actionDuplicateItem', 'canPublish'), !$i335fAction($i335fAdmin, 'actionRestoreItem', 'canPublish'),
], array_fill(0, 11, true));
check('3.35 B6 N107: MCP – the same rule for a role that does not publish over any connection (not only drafts-only connections)', [
    $i335fAction($i335fMcp, 'toolSaveCollectionItem', 'if (!$auth->canPublish()) {'),
    $i335fAction($i335fMcp, 'toolSaveCollectionItems', "\$options = ['drafts_only' => !\$auth->canPublish()];"),
    $i335fAction($i335fMcp, 'toolDeleteCollectionItem', 'Collections::isDraft($stored)'), $i335fAction($i335fMcp, 'toolListItemVersions', 'Collections::isDraft($item)'),
    $i335fAction($i335fMcp, 'toolSaveCollectionCategory', 'if (!$auth->canPublish()) {'), $i335fAction($i335fMcp, 'toolDeleteCollectionCategory', "!\$this->app->auth()->canPublish() && (CollectionCategories::byId(\$db, \$id)['visible'] ?? false)"),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Builder/ItemImport.php'), "'drafts_only' => !\$app->auth()->canPublish()"),
], array_fill(0, 7, true));
$i335fTexts = [Collections::LIVE_ITEM_REFUSAL, Collections::LIVE_CATEGORY_REFUSAL, 'Skipped: %d (on the site – only an editor changes it – or the address is taken in that language).',
    'This item is on the site (or scheduled to be published) – only an editor or administrator changes it.', 'The item stays hidden until an editor or administrator publishes it.',
    'Tags change the topic pages and other authors\' news at once – an editor or administrator renames, merges and deletes them. New tags come from the Tags field of your news.'];
check('3.35 B6: the texts are translated (cs, de, es); the ones that address the reader have an informal German form', [
    array_values(array_filter($i335fTexts, fn (string $k): bool => !isset($adminCs[$k], $adminDe[$k], $a3312Es[$k]))),
    array_values(array_filter($i335fTexts, fn (string $k): bool => preg_match('/\b(Sie|Ihr|Ihre|Ihrer)\b/', $adminDe[$k] ?? '') === 1 && !isset($a3312Du[$k]))),
], [[], []]);
