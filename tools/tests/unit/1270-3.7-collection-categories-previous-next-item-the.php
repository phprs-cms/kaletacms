<?php

declare(strict_types=1);

/* ---------- 3.7: collection categories, previous / next item, the attachment limit of a form ---------- */
$categoryRow = ['id' => 7, 'parent_id' => 3, 'visible' => true, 'parent_visible' => true, 'slug' => 'medical', 'parent_slug' => 'treadmills'];
check('3.7: category paths – a subcategory under its parent, a top-level one alone', [
    Kaleta\Builder\CollectionCategories::path('equipment', $categoryRow), Kaleta\Builder\CollectionCategories::path('equipment', ['parent_slug' => '', 'slug' => 'treadmills']),
], ['equipment/treadmills/medical', 'equipment/treadmills']);
check('3.7: a category is public only when it and its parent are visible and the parent has an address in the language', [
    Kaleta\Builder\CollectionCategories::isPublic($categoryRow), Kaleta\Builder\CollectionCategories::isPublic(['visible' => false] + $categoryRow),
    Kaleta\Builder\CollectionCategories::isPublic(['parent_visible' => false] + $categoryRow), Kaleta\Builder\CollectionCategories::isPublic(['parent_slug' => ''] + $categoryRow),
], [true, false, false, false]);
check('3.7: "latest" (the stable file address of a document) is never a category address', in_array('latest', Kaleta\Builder\CollectionCategories::RESERVED_SLUGS, true), true);
check('3.7: the category template has its own version key, apart from the item template', [
    Kaleta\Builder\Collections::templateKey(['idk' => 4, 'sablona_jazyk' => '', 'sablona_druh' => 'kategorie']), Kaleta\Builder\Collections::templateKey(['idk' => 4, 'sablona_jazyk' => 'en', 'sablona_druh' => 'kategorie']),
    Kaleta\Builder\Collections::templateKey(['idk' => 4, 'sablona_jazyk' => '']),
], ['kategorie:4', 'kategorie:4:en', 'kolekce:4']);
$categoryTemplate = Kaleta\Builder\CollectionCategories::defaultTemplate(['seo_link' => 'equipment', 'pole' => [['klic' => 'foto', 'popisek' => 'Photo', 'typ' => 'obrazek']]]);
$categoryLists = [];
array_walk_recursive($categoryTemplate, function (mixed $v, string|int $k) use (&$categoryLists): void { if ($k === 'zdroj' || $k === 'kategorie') { $categoryLists[] = $k . '=' . (is_scalar($v) ? (string) $v : ''); } });
check('3.7: the default category page lists the subcategories and the items of the category shown, with the first image field',
    [$categoryLists, str_contains((string) json_encode($categoryTemplate), '{{foto}}'), str_contains((string) json_encode($categoryTemplate), '"typ":"drobecky"')],
    [['zdroj=kategorie', 'kategorie=*', 'zdroj=polozky', 'kategorie=*'], true, true]);
$serverLimit = Kaleta\Core\Files::limit();
$limitOf = fn (int $mb): int => min($mb * 1048576, $serverLimit > 0 ? $serverLimit : PHP_INT_MAX);
check('3.7: the attachment limit of a form – its own 1 to 25 MB, the default 10 MB, never above the server', [
    Kaleta\Builder\Elements\Form::attachmentLimit([]), Kaleta\Builder\Elements\Form::attachmentLimit(['max_priloha' => 12]), Kaleta\Builder\Elements\Form::attachmentLimit(['max_priloha' => 500]),
    Kaleta\Builder\Elements\Form::attachmentLimit(['max_priloha' => 0]), Kaleta\Builder\Build::sanitize(['v' => 1, 'deti' => [['typ' => 'formular', 'obsah' => ['max_priloha' => 99]]]])[0]['deti'][0]['obsah']['max_priloha'],
], [$limitOf(10), $limitOf(12), $limitOf(25), $limitOf(1), 25]);
$navigationContext = (new ReflectionClass(Kaleta\Builder\Context::class))->newInstanceWithoutConstructor();
$navigationContext->editor = false;
$navigationElement = Kaleta\Builder\Build::fresh(Kaleta\Builder\Elements\ItemNavigation::TYPE);
check('3.7: Previous / next item renders nothing outside an item page; in the editor a nav landmark with rel links',
    [Kaleta\Builder\Elements\ItemNavigation::render($navigationElement, '', '', $navigationContext)], ['']);
$navigationContext->editor = true;
$navigationHtml = Kaleta\Builder\Elements\ItemNavigation::render($navigationElement, ' data-ka-id="x"', '', $navigationContext);
check('3.7: Previous / next item markup – nav with a label, rel="prev" and rel="next", decorative arrows', [
    (bool) preg_match('#^<nav data-ka-id="x" class="ka-predchozi-dalsi" aria-label="[^"]+">#', $navigationHtml), str_contains($navigationHtml, 'rel="prev"'), str_contains($navigationHtml, 'rel="next"'),
    str_contains($navigationHtml, '<span aria-hidden="true">←</span>'),
], [true, true, true, true]);

