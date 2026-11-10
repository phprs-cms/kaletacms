<?php

declare(strict_types=1);

/* ---------- 2.14: content hygiene (Core\MediaHygiene, Core\ContentCheck, Core\Translations) ---------- */
$f16Rows = [
    ['ido' => 1, 'obr_poloha' => 'media/2026/01/foto-aa11bb.jpg', 'nahl_poloha' => 'media/2026/01/foto-aa11bb-nahled.jpg'],
    ['ido' => 2, 'obr_poloha' => 'media/2026/01/logo-cc22dd.svg', 'nahl_poloha' => 'media/2026/01/logo-cc22dd.svg'],
    ['ido' => 3, 'obr_poloha' => 'media/2026/02/cenik-ee33ff.pdf', 'nahl_poloha' => ''],
    ['ido' => 4, 'obr_poloha' => 'media/2026/02/hala-0011aa.png', 'nahl_poloha' => 'media/2026/02/hala-0011aa-nahled.png'],
    ['ido' => 5, 'obr_poloha' => 'media/2026/03/tym-2233bb.webp', 'nahl_poloha' => 'media/2026/03/tym-2233bb-nahled.webp'],
];
$f16Content = '{"src":"media\/2026\/01\/foto-aa11bb-1200.jpg"} <a href="https://example.com/media/2026/02/cenik-ee33ff.pdf">PDF</a> <img src="/media/2026/02/hala-0011aa.png.webp"> media/2026/02/hala-0011aa-nahled.png';
check('2.14 MediaHygiene::paths – JSON escapes, the site URL and the -1200, -nahled, .webp variants all point at the original file', Kaleta\Core\MediaHygiene::paths($f16Content),
    ['media/2026/01/foto-aa11bb.jpg', 'media/2026/02/cenik-ee33ff.pdf', 'media/2026/02/hala-0011aa.png']);
check('2.14 MediaHygiene::unused – the referenced files and the one a news item uses stay, the rest is unused', array_column(Kaleta\Core\MediaHygiene::unused($f16Rows, Kaleta\Core\MediaHygiene::paths($f16Content), [5]), 'ido'), [2]);
check('2.14 MediaHygiene::unused – the thumbnail path counts as a reference; a .webp upload keeps its name', [array_column(Kaleta\Core\MediaHygiene::unused($f16Rows, ['media/2026/03/tym-2233bb-nahled.webp']), 'ido'),
    Kaleta\Core\MediaHygiene::paths('media/2026/03/tym-2233bb.webp')], [[1, 2, 3, 4], ['media/2026/03/tym-2233bb.webp']]);
check('2.14 MediaHygiene::duplicates – groups of the same hash with two or more files, oldest first, files without a hash left out', Kaleta\Core\MediaHygiene::duplicates([
    ['ido' => 9, 'sha1' => 'b'], ['ido' => 3, 'sha1' => 'a'], ['ido' => 7, 'sha1' => 'a'], ['ido' => 4, 'sha1' => 'c'], ['ido' => 5, 'sha1' => 'b'], ['ido' => 6, 'sha1' => ''], ['ido' => 1, 'sha1' => 'a']]),
    [[['ido' => 1, 'sha1' => 'a'], ['ido' => 3, 'sha1' => 'a'], ['ido' => 7, 'sha1' => 'a']], [['ido' => 5, 'sha1' => 'b'], ['ido' => 9, 'sha1' => 'b']]]);
check('2.14 MediaHygiene::isOversized – over 2 MB or wider than 2560 px, never an SVG or an attachment', array_map([Kaleta\Core\MediaHygiene::class, 'isOversized'], [
    ['obr_poloha' => 'media/2026/01/a.jpg', 'nahl_poloha' => 'media/2026/01/a-nahled.jpg', 'obr_vel' => 2 * 1024 * 1024 + 1, 'obr_width' => 1600],
    ['obr_poloha' => 'media/2026/01/b.jpg', 'nahl_poloha' => 'media/2026/01/b-nahled.jpg', 'obr_vel' => 900_000, 'obr_width' => 4000],
    ['obr_poloha' => 'media/2026/01/c.jpg', 'nahl_poloha' => 'media/2026/01/c-nahled.jpg', 'obr_vel' => 900_000, 'obr_width' => 2000],
    ['obr_poloha' => 'media/2026/01/d.svg', 'nahl_poloha' => 'media/2026/01/d.svg', 'obr_vel' => 3_000_000, 'obr_width' => 5000],
    ['obr_poloha' => 'media/2026/01/e.pdf', 'nahl_poloha' => '', 'obr_vel' => 30_000_000, 'obr_width' => 0]]), [true, true, false, false, false]);
$f16Check = fn (array $input): array => array_column(Kaleta\Core\ContentCheck::run($input), 'ok', 'check');
check('2.14 ContentCheck – a good text page: the title is the H1, H2 follows, the keyword from the title is in the description and the first paragraph, images described', $f16Check([
    'title' => 'Kuchyně na míru pro rodinné domy v Brně', 'description' => 'Navrhujeme a vyrábíme kuchyně na míru pro rodinné domy v Brně a okolí – od zaměření po montáž, se zárukou pěti let.',
    'html' => '<p>Kuchyně na míru stavíme už dvacet let.</p><h2>Jak pracujeme</h2><p>Text</p><h3>Zaměření</h3><img src="/media/a.jpg" alt="Kuchyň">', 'title_is_h1' => true]),
    ['title_length' => true, 'description_length' => true, 'single_h1' => true, 'heading_order' => true, 'keyword' => true, 'images_alt' => true]);
check('2.14 ContentCheck – a short title, no description, two H1s, a skipped level, the keyword missing, an image without alt', [$f16Check([
    'title' => 'Kuchyně', 'description' => '', 'html' => '<h1>Nabídka</h1><p>Vyrábíme nábytek.</p><h3>Skok</h3><img src="/media/a.jpg"><img src="/media/b.jpg" alt="">', 'title_is_h1' => true]),
    array_column(Kaleta\Core\ContentCheck::run(['title' => 'Kuchyně', 'description' => '', 'html' => '<h1>Nabídka</h1><h3>Skok</h3>', 'title_is_h1' => true]), 'message', 'check')['heading_order']],
    [['title_length' => false, 'description_length' => false, 'single_h1' => false, 'heading_order' => false, 'keyword' => false, 'images_alt' => false],
        t('Headings skip a level (%s) – screen readers and search engines read the outline.', 'H1 → H3')]);
check('2.14 ContentCheck – a build page has its own H1 (none = a warning), a too long title and description are warnings, the keyword search ignores case and diacritics', $f16Check([
    'title' => str_repeat('Dlouhý titulek ', 5), 'description' => str_repeat('Popis stránky pro vyhledávače. ', 6),
    'html' => '<h2>DLOUHY titulek bez diakritiky</h2><p>dlouhy TITULEK v odstavci</p>', 'title_is_h1' => false]),
    ['title_length' => false, 'description_length' => false, 'single_h1' => false, 'heading_order' => true, 'keyword' => false, 'images_alt' => true]);
check('2.14 ContentCheck::forPage – a text page is judged with its title as the H1, a build page by its draft', [
    array_column(Kaleta\Core\ContentCheck::forPage(['titulek' => 'O nás', 'seo_titulek' => '', 'popis' => '', 'text' => '<h2>Tým</h2>', 'stavba' => null, 'stavba_koncept' => null]), 'ok', 'check')['single_h1'],
    array_column(Kaleta\Core\ContentCheck::forPage(['titulek' => 'O nás', 'seo_titulek' => '', 'popis' => '', 'text' => '', 'stavba' => null,
        'stavba_koncept' => Kaleta\Builder\Build::toJson(Kaleta\Builder\Build::fromText('Náš tým', '<p>Lidé.</p>'))]), 'ok', 'check')['single_h1']], [true, true]);
check('2.14 Translations::status – missing, present, outdated (the original changed after the translation was saved); a never-changed row counts by its date', [
    Kaleta\Core\Translations::status(null, '2026-01-10 10:00:00'), Kaleta\Core\Translations::status(['zmeneno' => '2026-01-11 10:00:00'], '2026-01-10 10:00:00'),
    Kaleta\Core\Translations::status(['zmeneno' => '2026-01-09 10:00:00'], '2026-01-10 10:00:00'), Kaleta\Core\Translations::status(['zmeneno' => null, 'datum' => '2026-01-09 10:00:00'], '2026-01-10 10:00:00'),
    Kaleta\Core\Translations::status(['zmeneno' => '2026-01-09 10:00:00'], null)], ['missing', 'present', 'outdated', 'outdated', 'present']);
// 3.10.1 (N33): the translation overview and the list tools take their scope from the same Auth methods, so they cannot drift
$n33Sources = array_map(fn (string $f): string => (string) file_get_contents(KALETA_ROOT . $f), ['/system/src/Core/Translations.php', '/system/src/Mcp/Handlers/PageTools.php', '/system/src/Mcp/Handlers/NewsTools.php', '/system/src/Mcp/Handlers/CollectionTools.php']);
check('3.10.1 N33: Translations::matrix scopes pages, news and collection items (originals and translations) with the helpers list_pages, list_news and list_collection_items use', [
    substr_count($n33Sources[0], '$auth->pageListScope('), substr_count($n33Sources[0], '$auth->newsListScope('), substr_count($n33Sources[0], '$auth->collectionItemListScope('),
    str_contains($n33Sources[1], '$auth->pageListScope()'), str_contains($n33Sources[2], "\$auth->newsListScope('c.')"), str_contains($n33Sources[3], '$auth->collectionItemListScope()'),
    str_contains($n33Sources[2], 'managedAuthors')], [2, 2, 2, true, true, true, false]);
check('3.10.1 N39-5: a rotation record is kept 30 days – within the refresh token\'s year, well past the retry grace', [Kaleta\Front\OAuth::ROTATED_LIFETIME, Kaleta\Front\OAuth::ROTATED_LIFETIME < Kaleta\Front\OAuth::REFRESH_LIFETIME, Kaleta\Front\OAuth::ROTATED_LIFETIME > Kaleta\Front\OAuth::REFRESH_GRACE], [30 * 86400, true, true]);
check('2.14 MCP: the two read-only tools are in the catalog as reads', [Kaleta\Mcp\Catalog::access('list_media_without_alt'), Kaleta\Mcp\Catalog::access('translation_status'), Kaleta\Mcp\Tools::annotations('translation_status')['readOnlyHint']], ['read', 'read', true]);
