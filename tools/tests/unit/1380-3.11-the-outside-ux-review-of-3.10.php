<?php

declare(strict_types=1);

/* ---------- 3.11: the outside UX review of 3.10 ---------- */
// UX310-1: sample content of the starter sites and templates is found – every starter home page has some, in Czech and English;
// a rewritten text is not, a build of 3.10 with its invented review is, and placeholders in brackets always are
$sampleHomes = [];
foreach (Kaleta\Builder\Library::SITES as $siteKey => $site311) {
    foreach (['cs', 'en'] as $language311) {
        [$home311] = Kaleta\Builder\Library::assemble($site311['stranky'][0], 'Home', $language311, [], true);
        $sampleHomes[] = $siteKey . '/' . $language311 . ':' . (Kaleta\Builder\SampleContent::find($home311) !== [] ? 'found' : 'none');
    }
}
$sample311 = fn (array ...$elements): array => array_column(Kaleta\Builder\SampleContent::find(['v' => 1, 'deti' => $elements]), 'kind', 'id');
[$landing311] = Kaleta\Builder\Library::assemble(Kaleta\Builder\Library::PAGE_TEMPLATES['landing'][1], 'Landing', 'en', [], true);
$landingKinds311 = array_count_values(array_column(Kaleta\Builder\SampleContent::find($landing311), 'kind'));
check('3.11 UX310-1: sample content is found in every starter home page and the landing template; rewritten texts are not; old invented reviews and bracket placeholders are', [
    array_values(array_filter($sampleHomes, fn (string $x): bool => !str_ends_with($x, ':found'))),
    [$landingKinds311['testimonial'] ?? 0, isset($landingKinds311['text'])],
    $sample311(['id' => 'a', 'typ' => 'text', 'obsah' => ['html' => '<p>We design gardens in Brno – ask us for a visit.</p>']], ['id' => 'b', 'typ' => 'nadpis', 'obsah' => ['text' => '1,500']]),
    $sample311(['id' => 'c', 'typ' => 'citat', 'obsah' => ['text' => 'Vše proběhlo přesně podle dohody, včas a v rozpočtu. Rádi se vrátíme.', 'autor' => 'Petr Svoboda']],
        ['id' => 'd', 'typ' => 'text', 'obsah' => ['html' => '<p>Ein paar Worte.</p><p>In one or two sentences, say what you do, who it is for and why you.</p>']],
        ['id' => 'e', 'typ' => 'nadpis', 'znacka' => 'p', 'obsah' => ['text' => '[number]']], ['id' => 'f', 'typ' => 'seznam', 'obsah' => ['polozky' => "Real\n[Your service]"]],
        ['id' => 'g', 'typ' => 'nadpis', 'obsah' => ['text' => 'years in business']]),
], [[], [2, true], [], ['c' => 'testimonial', 'd' => 'text', 'e' => 'figure', 'f' => 'text']]);
$findings311 = Kaleta\Builder\SampleContent::findings($landing311);
check('3.11 UX310-1: the pre-publish findings start with a summary with the counts; Library and element defaults no longer invent reviews or figures', [
    str_contains($findings311[0]['zprava'], 'sample testimonials: 2') && str_contains($findings311[0]['zprava'], 'invented reviews'), count($findings311) <= Kaleta\Builder\Check::MAX,
    array_values(array_filter(['David Clarke', 'Emma Johnson', 'Sarah Miller', '98%', '1,200', '★★★★★', 'from £40', '£120', 'John Smith', 'London EC2A 4NE'], fn (string $x): bool => str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Builder/Library.php'), "'" . $x . "'"))),
    Kaleta\Core\Language::runWith('en', fn (): array => [Kaleta\Builder\Elements\Quote::properties()['autor']['vychozi'], Kaleta\Builder\Elements\Counter::properties()['cislo']['vychozi']])],
    [true, true, [], ['[Customer’s name]', '[number]']]);
check('3.11 UX310-1: a page of the audit has the kind sample, MCP build checks start with it', [
    array_key_first(Kaleta\Core\Audit::KINDS), str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Mcp/Tools.php'), 'SampleContent::findings($build)')], ['sample', true]);
// UX310-2: one name per access level, drafts first and recommended on the consent screen
$consent311 = $oauthView->render('admin/connection-access', ['role' => 'Administrator', 'selected' => 'drafts', 'claude' => true, 'toolSet' => 'essentials', 'recommended' => 'drafts']);
check('3.11 UX310-2/9: the consent lists Drafts only first, preselected and recommended; the tool set is folded away under Advanced', [
    strpos($consent311, 'value="drafts"') < strpos($consent311, 'value="full"'), (bool) preg_match('/value="drafts" checked><strong>Drafts only – you publish <span class="stitek stitek-vydano">recommended/', $consent311),
    str_contains($consent311, '<details class="pokrocile">'), str_contains($consent311, 'value="essentials" checked'), array_values(Kaleta\Front\OAuth::ACCESS_LABELS)],
    [true, true, true, true, ['Drafts only – you publish', 'Can also publish', 'Read only']]);
// UX310-3: the freshness of the update information is one number for System status and Backups and updates
$stale311 = fn (string $date): ?int => Kaleta\Core\Updater::staleDays(['newest' => ['verze' => '9.9.9', 'vydano' => $date, 'at' => 0]], (int) strtotime('2026-10-11 12:00:00'));
check('3.11 UX310-3: Updater::staleDays – null while fresh (60 days), the age when older, null without a date', [
    $stale311('2026-09-01'), $stale311('2026-07-01'), $stale311(''), Kaleta\Core\Updater::staleDays(['newest' => null])], [null, 102, null, null]);
// UX310-13: a heading of a Collection list item is named for what it is, not by its {{token}}
check('3.11 UX310-13: a skipped level of a Collection list item heading does not show {{nazev}}', Kaleta\Builder\Check::builds(['v' => 1, 'deti' => [
    ['id' => 'a', 'typ' => 'nadpis', 'znacka' => 'h1', 'obsah' => ['text' => 'Services']], ['id' => 'b', 'typ' => 'nadpis', 'znacka' => 'h3', 'obsah' => ['text' => '{{nazev}}']]]], true),
    [['id' => 'b', 'zprava' => 'The item heading of a Collection list skips a level (h1 → h3).']]);

