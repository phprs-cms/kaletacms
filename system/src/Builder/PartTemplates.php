<?php

declare(strict_types=1);

namespace Kaleta\Builder;

use Kaleta\Core\Language;

/**
 * Ready-made templates of site parts (1.7): a few clean skeletons of the header, the footer and the wrappers. They carry
 * structure only – colours, fonts, spacing and corners come from the design system tokens, so every template fits every
 * site (Kaleta is themeless). A template goes into the part's draft; the published version stays until publishing.
 */
final class PartTemplates
{
    /** type => key => [name, description, extension it needs ('' = none)] */
    public const array LIST = [
        'hlavicka' => [
            'klasicka' => ['Logo left, menu right', 'The logo on the left, the menu and a button on the right. Stays at the top while scrolling.', ''],
            'na-stred' => ['Centred logo', 'The logo in the middle with the menu below it – for a site led by the brand.', ''],
            's-listou' => ['With a contact bar', 'A thin bar with the phone and e-mail above the logo and the menu.', ''],
            'minimalni' => ['Minimal', 'Only the logo and one button – for a campaign page without the menu.', ''],
            's-hledanim' => ['With search', 'The logo on the left, the menu and a magnifier that opens the search field on the right.', ''],
        ],
        'paticka' => [
            'sloupce' => ['Columns', 'The company, the footer menu, contacts and a newsletter sign-up, the copyright below.', ''],
            'kompaktni' => ['Compact', 'One line: the company name, the footer menu and the copyright.', ''],
            'tiraz' => ['With the imprint', 'The company details for the imprint next to the footer menu and social networks.', ''],
            'vyzva' => ['With a call to action', 'A coloured call to action above a two-column footer.', ''],
        ],
        'novinka' => [
            's-vyzvou' => ['With a call to action', 'The news item and a call to action below it.', ''],
            'jednoducha' => ['Plain', 'Only the news item.', ''],
        ],
        'vypis' => [
            'jednoduchy' => ['Plain', 'Only the list of news.', ''],
            's-odberem' => ['With a newsletter sign-up', 'The list of news and a newsletter sign-up below it.', 'newsletter'],
        ],
        'nenalezeno' => [
            'jednoducha' => ['Plain', 'Only the message and the links from the system.', ''],
            's-hledanim' => ['With search', 'The message with a search box below it.', ''],
        ],
    ];

    /** @return list<array{klic: string, nazev: string, popis: string}> templates of the part type, without those of disabled extensions */
    public static function forType(string $type, ?array $extensions = null): array
    {
        $out = [];
        foreach (self::LIST[$type] ?? [] as $key => [$name, $description, $extension]) {
            if ($extension === '' || $extensions === null || in_array($extension, $extensions, true)) {
                $out[] = ['klic' => $key, 'nazev' => $name, 'popis' => $description];
            }
        }

        return $out;
    }

    /**
     * The template's build in the language of the part (texts through the site dictionary), or null for an unknown template.
     *
     * @param list<string> $extensions enabled extensions (the newsletter sign-up only with the Newsletter extension)
     */
    public static function build(string $type, string $key, string $language, array $extensions = []): ?array
    {
        if (!isset(self::LIST[$type][$key])) {
            return null;
        }

        return Language::runWith($language, function () use ($type, $key, $extensions): array {
            $n = Build::fresh(...);
            $s = fn (array $p, array $style): array => ['styl' => $style] + $p;
            $z = fn (array $p, string $htmlTag): array => ['znacka' => $htmlTag] + $p;
            $url = fn (string $page): string => '/' . slugify(t($page));
            $row = fn (array $children, string $distribution = 'space-between'): array => $s($n('kontejner', [], $children),
                ['zaklad' => ['zobrazeni' => 'flex', 'smer' => 'row', 'rozmisteni' => $distribution, 'zarovnani' => 'center', 'zalamovani' => 'wrap', 'mezera' => 'm']]);
            $header = fn (array $children, array $style = []): array => $s($z($n('sekce', [], $children), 'header'),
                ['zaklad' => $style + ['odsazeni_y' => 's', 'pozadi' => 'pozadi', 'linka_dole' => '1px solid var(--ka-barva-linka)', 'pozice' => 'sticky', 'odshora' => '0', 'vrstva' => '10']]);
            $footer = fn (array $children): array => $s($z($n('sekce', [], $children), 'footer'),
                ['zaklad' => ['odsazeni_y' => 'xl', 'pozadi' => 'plocha', 'linka_nahore' => '1px solid var(--ka-barva-linka)']]);
            $copyright = $s($n('udaje', ['udaj' => 'copyright']), ['zaklad' => ['okraj_nahore' => 'l', 'velikost_pisma' => '-1', 'barva' => 'tlumeny']]);
            $companyName = $s($z($n('udaje', ['udaj' => 'nazev']), 'p'), ['zaklad' => ['tloustka_pisma' => '700']]);
            $footerMenu = $n('navigace', ['menu' => 'paticka', 'novinky' => false, 'mobil' => false]);
            $button = $n('tlacitko', ['text' => t('Request a quote'), 'odkaz' => $url('Contact')]);

            $children = match ($type . ':' . $key) {
                'hlavicka:klasicka' => [$header([$row([$n('logo'), $row([$n('navigace'), $s($button, ['mobil' => ['zobrazeni' => 'none']])], 'end')])])],
                'hlavicka:na-stred' => [$header([$s($n('kontejner', [], [$n('logo'), $n('navigace')]),
                    ['zaklad' => ['zobrazeni' => 'flex', 'smer' => 'column', 'zarovnani' => 'center', 'mezera' => 's']])], ['odsazeni_y' => 'm', 'pozice' => 'static'])],
                'hlavicka:s-listou' => [$header([
                    $s($row([$n('udaje', ['udaj' => 'telefon']), $n('udaje', ['udaj' => 'email'])], 'end'), ['zaklad' => ['velikost_pisma' => '-1', 'barva' => 'tlumeny', 'okraj_dole' => 's']]),
                    $row([$n('logo'), $n('navigace')]),
                ])],
                'hlavicka:s-hledanim' => [$header([$row([$n('logo'), $row([$n('navigace'), $n('hledani', ['podoba' => 'ikona'])], 'end')])])],
                'hlavicka:minimalni' => [$header([$row([$n('logo'), $button])], ['pozice' => 'static'])],
                'paticka:sloupce' => [$footer([
                    $s($n('mrizka', [], [
                        $n('kontejner', [], [$companyName, $n('udaje', ['udaj' => 'popis'])]),
                        $n('kontejner', [], [$footerMenu]),
                        $n('kontejner', [], [$n('udaje', ['udaj' => 'adresa']), $n('udaje', ['udaj' => 'telefon']), $n('udaje', ['udaj' => 'email']), $n('udaje', ['udaj' => 'site'])]),
                        $n('kontejner', [], [in_array('newsletter', $extensions, true) ? $n('newsletter') : $n('udaje', ['udaj' => 'hodiny'])]),
                    ]), ['zaklad' => ['zobrazeni' => 'grid', 'sloupce' => '4', 'mezera' => 'l'], 'tablet' => ['sloupce' => '2'], 'mobil' => ['sloupce' => '1']]),
                    $copyright,
                ])],
                'paticka:kompaktni' => [$footer([$row([$companyName, $footerMenu, $s($n('udaje', ['udaj' => 'copyright']), ['zaklad' => ['velikost_pisma' => '-1', 'barva' => 'tlumeny']])])])],
                'paticka:tiraz' => [$footer([
                    $s($n('mrizka', [], [$n('udaje', ['udaj' => 'tiraz']), $n('kontejner', [], [$footerMenu, $n('udaje', ['udaj' => 'site'])])]),
                        ['zaklad' => ['zobrazeni' => 'grid', 'sloupce' => '2', 'mezera' => 'l'], 'mobil' => ['sloupce' => '1']]),
                    $copyright,
                ])],
                'paticka:vyzva' => [Library::section('vyzva', Language::code())['prvek'], ...SiteParts::defaults('paticka', Language::code())['deti']],
                'novinka:s-vyzvou' => [$n('obsah'), Library::section('vyzva', Language::code())['prvek']],
                'vypis:s-odberem' => [$n('obsah'), $s($n('sekce', [], [$s($n('kontejner', [], [$n('newsletter')]), ['zaklad' => ['max_sirka' => '40rem', 'na_stred' => 'auto']])]),
                    ['zaklad' => ['odsazeni_y' => 'xl']])],
                'nenalezeno:s-hledanim' => [$n('obsah'), $s($n('sekce', [], [$s($n('kontejner', [], [$n('hledani')]), ['zaklad' => ['max_sirka' => '32rem', 'na_stred' => 'auto']])]),
                    ['zaklad' => ['odsazeni_y' => 'l']])],
                default => [$n('obsah')], // plain wrappers
            };

            return Build::sanitize(['v' => Build::VERSION, 'deti' => $children])[0];
        });
    }
}
