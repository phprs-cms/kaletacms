<?php

declare(strict_types=1);

/* ---------- 2.10: computed facts – years since, counts, the proof rule ---------- */
$yearsSince = fn (string $value, string $now): ?int => Kaleta\Core\Facts::yearsSince($value, new DateTimeImmutable($now));
check('2.10: years_since – a year, a date on, before and after its anniversary, this year, a bad argument, a date ahead', [
    $yearsSince('2004', '2026-10-02 12:00'), $yearsSince('2004-10-02', '2026-10-02 00:00'), $yearsSince('2004-10-03', '2026-10-02 12:00'), $yearsSince('2004-10-01', '2026-10-02 12:00'),
    $yearsSince('2026', '2026-01-01'), $yearsSince('soon', '2026-10-02'), $yearsSince('2004-13-01', '2026-10-02'), $yearsSince('2030', '2026-10-02'), $yearsSince('2026-12-24', '2026-10-02')],
    [22, 22, 21, 22, 0, null, null, null, null]);
$factApp = (new ReflectionClass(Kaleta\Core\App::class))->newInstanceWithoutConstructor();
check('2.10: computed() – years since a year as the site shows it, a bad argument is nothing (the audit reports it)', [Kaleta\Core\Facts::computed($factApp, 'years_since', '2004', new DateTimeImmutable('2026-06-01')), Kaleta\Core\Facts::computed($factApp, 'years_since', 'soon')], ['22', null]);
preg_match_all(Kaleta\Core\Facts::COMPUTED_PATTERN, '{{years_since:2004}} {{ years_since:fact.founded }} {{count:reference-2}} {{count:news}} {{count:Velka}} {{pocet:x}} {{nazev}}', $computedTokens, PREG_SET_ORDER);
check('2.10: the computed token – both forms with their arguments, nothing else', array_map(fn (array $c): string => $c[1] . ':' . $c[2], $computedTokens), ['years_since:2004', 'years_since:fact.founded', 'count:reference-2', 'count:news']);
check('2.10: claims – a sentence with a computed or fact token is not a claim any more', [Kaleta\Core\Facts::isClaim('Na trhu jsme 22 let.'), Kaleta\Core\Facts::isClaim('Na trhu jsme {{years_since:2004}} let.'),
    Kaleta\Core\Facts::isClaim('Máme {{fact.projects}} zakázek.'), Kaleta\Core\Facts::isClaim('Otevřeno od 8 hodin.')], [true, false, false, false]);
$proofBuild = ['v' => 1, 'deti' => [['id' => 's1', 'typ' => 'sekce', 'deti' => [['id' => 'c1', 'typ' => 'pocitadlo', 'obsah' => ['cislo' => 1500]], ['id' => 'c2', 'typ' => 'pocitadlo', 'obsah' => ['cislo' => '{{fact.projects}}']],
    ['id' => 'c3', 'typ' => 'pocitadlo', 'obsah' => ['cislo' => ' 22 ']], ['id' => 'c4', 'typ' => 'pocitadlo', 'obsah' => ['cislo' => '{{count:reference}}']], ['id' => 'n1', 'typ' => 'nadpis', 'znacka' => 'p', 'obsah' => ['text' => '1500']]]]]];
check('2.10: proof numbers typed in – counters with digits, not with tokens and not headings', Kaleta\Core\Facts::typedNumbers($proofBuild), [['id' => 'c1', 'number' => '1500'], ['id' => 'c3', 'number' => '22']]);
[$counterBuild] = Kaleta\Builder\Build::sanitize(['deti' => [['typ' => 'pocitadlo', 'obsah' => ['cislo' => 1500]], ['typ' => 'pocitadlo', 'obsah' => ['cislo' => '{{years_since:fact.founded}}']]]], false);
check('2.10: the counter keeps a number and a token alike', array_column(array_column($counterBuild['deti'], 'obsah'), 'cislo'), ['1500', '{{years_since:fact.founded}}']);
check('2.10: the counter is content – its number or token and label are in the text of the build (usage, the audit, the old value)', Kaleta\Builder\Build::asText($counterBuild),
    "<p>1500+ " . t('spokojených zákazníků') . "</p>\n<p>{{years_since:fact.founded}}+ " . t('spokojených zákazníků') . '</p>');
$counterContext = new Kaleta\Builder\Context($factApp, true);
$counterHtml = fn (string $number): string => Kaleta\Builder\Elements\Counter::render(['znacka' => 'div', 'obsah' => ['cislo' => $number, 'pred' => '', 'za' => '+', 'popisek' => 'zakázek']], '', '', $counterContext);
check('2.10: the counter – digits count up, the editor shows a token as it is (without the count-up)', Kaleta\Core\Language::runWith('cs', fn (): array => [str_contains($counterHtml('1500'), "data-pocitadlo=\"1500\">1\u{00A0}500<"),
    str_contains($counterHtml('{{fact.projects}}'), '>{{fact.projects}}<'), str_contains($counterHtml('{{fact.projects}}'), 'data-pocitadlo')]), [true, true, false]);
check('2.10.1: tokens inside <code> and <pre> are examples – never filled, not reported', [
    Kaleta\Core\Facts::withoutCode('<p>Write <code>{{fact.key}}</code> here, <pre>{{years_since:2004}}</pre> and {{fact.real}}.</p>')],
    ['<p>Write   here,   and {{fact.real}}.</p>']);
