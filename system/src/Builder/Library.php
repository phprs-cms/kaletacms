<?php

declare(strict_types=1);

namespace Kaleta\Builder;

/**
 * Section library for a company site: ready-made builds from design system tokens and a few shared classes, so that after
 * insertion they fit the site's colors and fonts right away. The editor inserts a copy (new ids); the classes the section uses
 * are created when the site does not have them yet.
 */
final class Library
{
    /** Shared classes of the library (name => style). Created on the first insertion of a section that uses them; then they belong to the site. */
    public const array CLASSES = [
        'karta' => ['zaklad' => ['zobrazeni' => 'flex', 'smer' => 'column', 'mezera' => 's', 'odsazeni_y' => 'l', 'odsazeni_x' => 'l', 'pozadi' => 'plocha', 'zaobleni' => 'm']],
        'nadpis-sekce' => ['zaklad' => ['max_radek' => 'var(--ka-sirka-textu)', 'okraj_dole' => 'l']],
        'podtitul' => ['zaklad' => ['velikost_pisma' => '1', 'barva' => 'tlumeny', 'max_radek' => 'var(--ka-sirka-textu)']],
    ];

    /** @return array<string, array{nazev:string, popis:string, stavba:callable(): array}> */
    private static function sections(): array
    {
        $n = Build::fresh(...);
        $s = fn (array $p, array $style): array => ['styl' => $style] + $p;       // an element with its own style
        $t = fn (array $p, string ...$classes): array => ['tridy' => $classes] + $p; // an element with classes
        $z = fn (array $p, string $htmlTag): array => ['znacka' => $htmlTag] + $p;
        $url = fn (string $page): string => '/' . slugify(t($page)); // the installer creates the pages under a translated name
        $buttonRow = fn (array ...$buttons): array => $s($n('kontejner', [], $buttons), ['zaklad' => ['zobrazeni' => 'flex', 'smer' => 'row', 'zalamovani' => 'wrap', 'mezera' => 's']]);

        return [
            'uvod' => ['nazev' => t('Hero'), 'popis' => t('Large headline, subtitle and two buttons.'), 'stavba' => fn (): array => $s($n('sekce', [], [
                $s($z($n('nadpis', ['text' => t('We help businesses grow – quickly and hassle-free')]), 'h1'), ['zaklad' => ['velikost_pisma' => '5', 'max_radek' => '20ch']]),
                $t($n('text', ['html' => '<p>' . t('In one or two sentences, say what you do, who it is for and why you.') . '</p>']), 'podtitul'),
                $buttonRow($n('tlacitko', ['text' => t('Request a quote'), 'odkaz' => $url('Contact')]), $n('tlacitko', ['text' => t('Our services'), 'odkaz' => $url('Services'), 'varianta' => 'obrys'])),
            ]), ['zaklad' => ['odsazeni_y' => '3xl'], 'mobil' => ['odsazeni_y' => '2xl']])],

            'uvod-obrazek' => ['nazev' => t('Hero with image'), 'popis' => t('Text and buttons on the left, image on the right; stacked on phones.'), 'stavba' => fn (): array => $s($n('sekce', [], [
                $s($n('mrizka', [], [
                    $n('kontejner', [], [
                        $s($z($n('nadpis', ['text' => t('Craftsmanship you can rely on')]), 'h1'), ['zaklad' => ['velikost_pisma' => '4']]),
                        $t($n('text', ['html' => '<p>' . t('Describe the main benefit for your customer. Briefly, specifically and in their words.') . '</p>']), 'podtitul'),
                        $buttonRow($n('tlacitko', ['text' => t('Contact us'), 'odkaz' => $url('Contact')])),
                    ]),
                    // the loading stays automatic (3.6): at once only as the first image of the page, lazily lower down
                    $s($n('obrazek', ['alt' => '']), ['zaklad' => ['sirka' => '100%', 'zaobleni' => 'l', 'pomer_stran' => '4/3', 'prizpusobeni' => 'cover']]),
                ]), ['zaklad' => ['zobrazeni' => 'grid', 'sloupce' => '2', 'mezera' => '2xl', 'zarovnani' => 'center'], 'tablet' => ['sloupce' => '1', 'mezera' => 'xl']]),
            ]), ['zaklad' => ['odsazeni_y' => '2xl']])],

            'vyhody' => ['nazev' => t('Benefits'), 'popis' => t('A heading and three cards with the main reasons to choose you.'), 'stavba' => fn (): array => $n('sekce', [], [
                $t($n('nadpis', ['text' => t('Why choose us')]), 'nadpis-sekce'),
                $n('mrizka', [], array_map(fn (array $d): array => $t($n('kontejner', [], [$z($n('nadpis', ['text' => $d[0]]), 'h3'), $n('text', ['html' => '<p>' . $d[1] . '</p>'])]), 'karta'), [
                    [t('Experience'), t('In fifteen years we have completed hundreds of projects across the country.')],
                    [t('Fair pricing'), t('You know the price upfront and pay only for work that is actually done.')],
                    [t('Speed'), t('We reply to every enquiry within one business day.')],
                ])),
            ])],

            'sluzby' => ['nazev' => t('Services with images'), 'popis' => t('Service cards with an image, description and link.'), 'stavba' => fn (): array => $n('sekce', [], [
                $t($n('nadpis', ['text' => t('What we do for you')]), 'nadpis-sekce'),
                $n('mrizka', [], array_map(fn (string $name): array => $t($n('kontejner', [], [
                    $s($n('obrazek', ['alt' => $name]), ['zaklad' => ['sirka' => '100%', 'pomer_stran' => '3/2', 'prizpusobeni' => 'cover', 'zaobleni' => 's']]),
                    $z($n('nadpis', ['text' => $name]), 'h3'),
                    $n('text', ['html' => '<p>' . t('A short description of the service and who it is for.') . '</p>']),
                    $n('tlacitko', ['text' => t('More information'), 'varianta' => 'odkaz', 'odkaz' => $url('Services')]),
                ]), 'karta'), [t('Design'), t('Realizace'), t('Support')])),
            ])],

            'cisla' => ['nazev' => t('Numbers'), 'popis' => t('A band with four bold numbers in the primary colour.'), 'stavba' => fn (): array => $s($n('sekce', [], [
                $s($n('mrizka', [], array_map(fn (array $d): array => $s($n('kontejner', [], [
                    $s($z($n('nadpis', ['text' => $d[0]]), 'p'), ['zaklad' => ['velikost_pisma' => '4', 'tloustka_pisma' => '800']]),
                    $n('text', ['html' => '<p>' . $d[1] . '</p>']),
                ]), ['zaklad' => ['zobrazeni' => 'flex', 'smer' => 'column', 'mezera' => '2xs', 'zarovnani_textu' => 'center']]), [[t('[number]'), t('years in business')], [t('[number]'), t('completed projects')], [t('[number]'), t('spokojených zákazníků')], [t('[number]'), t('response time')]])),
                    ['zaklad' => ['zobrazeni' => 'grid', 'sloupce' => '4', 'mezera' => 'l'], 'tablet' => ['sloupce' => '2']]),
            ]), ['zaklad' => ['odsazeni_y' => 'xl', 'pozadi' => 'primarni', 'barva' => 'na-primarni']])],

            'reference' => ['nazev' => t('Testimonials'), 'popis' => t('What customers say about you – quotes with names.'), 'stavba' => fn (): array => $n('sekce', [], [
                $t($n('nadpis', ['text' => t('What our customers say')]), 'nadpis-sekce'),
                $s($n('mrizka', [], [
                    // 3.11 (UX310-1): placeholders, never invented reviews – SampleContent reports them until real ones replace them
                    $t($n('citat', ['text' => t('[A customer’s words – a real quote, used with their permission]'), 'autor' => t('[Customer’s name]'), 'pozice' => t('[Role, company]')]), 'karta'),
                    $t($n('citat', ['text' => t('[A customer’s words – a real quote, used with their permission]'), 'autor' => t('[Customer’s name]'), 'pozice' => t('[Role, company]')]), 'karta'),
                ]), ['zaklad' => ['zobrazeni' => 'grid', 'sloupce' => 'auto:20rem', 'mezera' => 'l']]),
            ])],

            'faq' => ['nazev' => t('Questions and answers'), 'popis' => t('Frequently asked questions – also as structured data for search engines.'), 'stavba' => fn (): array => $n('sekce', ['sirka' => 'uzka'], [
                $n('nadpis', ['text' => t('Frequently asked questions')]),
                $n('faq'),
            ])],

            'vyzva' => ['nazev' => t('Výzva k akci'), 'popis' => t('A coloured box with a heading, a sentence and a button.'), 'stavba' => fn (): array => $n('sekce', [], [
                $s($n('kontejner', [], [
                    $z($n('nadpis', ['text' => t('Have a project? Let\'s talk.')]), 'h2'),
                    $n('text', ['html' => '<p>' . t('Get in touch – within 24 hours we will come back with a proposal for next steps.') . '</p>']),
                    $n('tlacitko', ['text' => t('Write to us'), 'odkaz' => $url('Contact'), 'varianta' => 'sekundarni']),
                ]), ['zaklad' => ['zobrazeni' => 'flex', 'smer' => 'column', 'zarovnani' => 'center', 'mezera' => 's', 'zarovnani_textu' => 'center',
                    'odsazeni_y' => '2xl', 'odsazeni_x' => 'l', 'pozadi' => 'primarni', 'barva' => 'na-primarni', 'zaobleni' => 'l']]),
            ])],

            'novinky' => ['nazev' => t('Latest news'), 'popis' => t('The three latest news items and a link to all of them.'), 'stavba' => fn (): array => $n('sekce', [], [
                $s($n('kontejner', [], [$z($n('nadpis', ['text' => t('Novinky')]), 'h2'), $n('tlacitko', ['text' => t('All news'), 'odkaz' => '/' . \Kaleta\Core\Routes::publicPath('novinky', \Kaleta\Core\Language::code(), null), 'varianta' => 'odkaz'])]),
                    ['zaklad' => ['zobrazeni' => 'flex', 'smer' => 'row', 'rozmisteni' => 'space-between', 'zarovnani' => 'baseline', 'zalamovani' => 'wrap', 'mezera' => 's']]),
                $n('novinky'),
            ])],

            'poptavka' => ['nazev' => t('Enquiry form'), 'popis' => t('A heading, a sentence and a form – messages arrive in Enquiries and by email.'), 'stavba' => fn (): array => $n('sekce', ['sirka' => 'uzka'], [
                $z($n('nadpis', ['text' => t('Write to us')]), 'h2'),
                $t($n('text', ['html' => '<p>' . t('Tell us what you need – we will get back to you within one business day.') . '</p>']), 'podtitul'),
                $s($n('formular'), ['zaklad' => ['okraj_nahore' => 'm']]),
            ])],

            'kontakt' => ['nazev' => t('Contact'), 'popis' => t('Address and contacts on the left, opening hours on the right.'), 'stavba' => fn (): array => $n('sekce', [], [
                $s($n('mrizka', [], [
                    // details from „Nastavení → Firma“ (Business details): filled in once, they also apply to the footer and search engines
                    $s($n('kontejner', [], [
                        $z($n('nadpis', ['text' => t('Contact details')]), 'h2'),
                        $n('udaje', ['udaj' => 'firma']),
                        $z($n('udaje', ['udaj' => 'adresa']), 'address'),
                        $n('udaje', ['udaj' => 'telefon']),
                        $n('udaje', ['udaj' => 'email']),
                        $n('udaje', ['udaj' => 'mapa']),
                    ]), ['zaklad' => ['zobrazeni' => 'flex', 'smer' => 'column', 'mezera' => 'xs']]),
                    $t($n('kontejner', [], [
                        $z($n('nadpis', ['text' => t('Opening hours')]), 'h3'),
                        $n('udaje', ['udaj' => 'hodiny']),
                    ]), 'karta'),
                ]), ['zaklad' => ['zobrazeni' => 'grid', 'sloupce' => '2', 'mezera' => 'xl'], 'tablet' => ['sloupce' => '1']]),
            ])],

            /* ---------- intro ---------- */

            'uvod-stred' => ['nazev' => t('Centred hero'), 'popis' => t('A short label, a big headline and a button – all centred.'), 'stavba' => fn (): array => $s($n('sekce', [], [
                $s($n('kontejner', [], [
                    $s($z($n('nadpis', ['text' => t('New: service within 48 hours')]), 'p'), ['zaklad' => ['velikost_pisma' => '-1', 'tloustka_pisma' => '600', 'barva' => 'primarni', 'velka_pismena' => 'uppercase', 'proklad' => '0.06em']]),
                    $s($z($n('nadpis', ['text' => t('We take care of everything from design to handover')]), 'h1'), ['zaklad' => ['velikost_pisma' => '5', 'max_radek' => '20ch']]),
                    $t($n('text', ['html' => '<p>' . t('One company, one contact, a clear price. You focus on your work, we handle the rest.') . '</p>']), 'podtitul'),
                    $n('tlacitko', ['text' => t('Book a meeting'), 'odkaz' => $url('Contact')]),
                ]), ['zaklad' => ['zobrazeni' => 'flex', 'smer' => 'column', 'zarovnani' => 'center', 'zarovnani_textu' => 'center', 'mezera' => 'm']]),
            ]), ['zaklad' => ['odsazeni_y' => '3xl'], 'mobil' => ['odsazeni_y' => '2xl']])],

            'uvod-tmavy' => ['nazev' => t('Hero on a dark background'), 'popis' => t('A bold hero on a dark background – set a background image in the section style.'), 'stavba' => fn (): array => $s($n('sekce', [], [
                $s($z($n('nadpis', ['text' => t('Quality that lasts for decades')]), 'h1'), ['zaklad' => ['velikost_pisma' => '5', 'max_radek' => '20ch', 'barva' => 'pozadi']]),
                $s($n('text', ['html' => '<p>' . t('We work with honest materials and guarantee every job.') . '</p>']), ['zaklad' => ['velikost_pisma' => '1', 'max_radek' => 'var(--ka-sirka-textu)', 'barva' => 'pozadi']]),
                $buttonRow($n('tlacitko', ['text' => t('Request a quote'), 'odkaz' => $url('Contact')])),
            ]), ['zaklad' => ['odsazeni_y' => '3xl', 'pozadi' => 'text', 'zobrazeni' => 'flex', 'smer' => 'column', 'mezera' => 'm'], 'mobil' => ['odsazeni_y' => '2xl']])],

            'uvod-video' => ['nazev' => t('Hero with video'), 'popis' => t('Headline and text on the left, video on the right (YouTube or Vimeo).'), 'stavba' => fn (): array => $s($n('sekce', [], [
                $s($n('mrizka', [], [
                    $s($n('kontejner', [], [
                        $s($z($n('nadpis', ['text' => t('See how we work')]), 'h1'), ['zaklad' => ['velikost_pisma' => '4']]),
                        $t($n('text', ['html' => '<p>' . t('A two-minute video says more than a long text. Paste the video address in the Content panel.') . '</p>']), 'podtitul'),
                        $buttonRow($n('tlacitko', ['text' => t('Contact us'), 'odkaz' => $url('Contact')])),
                    ]), ['zaklad' => ['zobrazeni' => 'flex', 'smer' => 'column', 'mezera' => 'm']]),
                    $n('video', ['url' => '']),
                ]), ['zaklad' => ['zobrazeni' => 'grid', 'sloupce' => '2', 'mezera' => '2xl', 'zarovnani' => 'center'], 'tablet' => ['sloupce' => '1']]),
            ]), ['zaklad' => ['odsazeni_y' => '2xl']])],

            'nadpis-stranky' => ['nazev' => t('Nadpis stránky'), 'popis' => t('A title and an intro sentence for the top of a subpage.'), 'stavba' => fn (): array => $s($n('sekce', [], [
                $s($z($n('nadpis', ['text' => t('Nadpis stránky')]), 'h1'), ['zaklad' => ['velikost_pisma' => '4']]),
                $t($n('text', ['html' => '<p>' . t('In one sentence, what this page is about.') . '</p>']), 'podtitul'),
            ]), ['zaklad' => ['odsazeni_y' => 'xl', 'pozadi' => 'plocha', 'zobrazeni' => 'flex', 'smer' => 'column', 'mezera' => 's']])],

            'tiraz' => ['nazev' => t('Imprint'), 'popis' => t('Who runs the site: company, registered office, identification numbers, register entry and contact from Business details.'), 'stavba' => fn (): array => $s($n('sekce', [], [
                $s($n('kontejner', [], [
                    $z($n('nadpis', ['text' => t('Website operator')]), 'h2'),
                    $n('udaje', ['udaj' => 'tiraz']),
                ]), ['zaklad' => ['zobrazeni' => 'flex', 'smer' => 'column', 'mezera' => 'm', 'max_sirka' => '48rem']]),
            ]), ['zaklad' => ['odsazeni_y' => 'xl']])],

            /* ---------- services and content ---------- */

            'vyhody-seznam' => ['nazev' => t('Benefits with a list'), 'popis' => t('Text on the left, a ticked list on the right.'), 'stavba' => fn (): array => $n('sekce', [], [
                $s($n('mrizka', [], [
                    $s($n('kontejner', [], [
                        $n('nadpis', ['text' => t('What you get with us')]),
                        $t($n('text', ['html' => '<p>' . t('More than just finished work. We make sure working together is pleasant from the first meeting.') . '</p>']), 'podtitul'),
                    ]), ['zaklad' => ['zobrazeni' => 'flex', 'smer' => 'column', 'mezera' => 's']]),
                    $n('seznam', ['polozky' => implode("\n", [t('You know the price upfront'), t('A fixed completion date'), t('A guarantee on all work'), t('We always clean up after ourselves')]), 'styl' => 'fajfky']),
                ]), ['zaklad' => ['zobrazeni' => 'grid', 'sloupce' => '2', 'mezera' => '2xl', 'zarovnani' => 'center'], 'tablet' => ['sloupce' => '1', 'mezera' => 'l']]),
            ])],

            'strida' => ['nazev' => t('Alternating image and text'), 'popis' => t('Two rows: image with text, then the other way round.'), 'stavba' => fn (): array => $n('sekce', [], [
                $s($n('kontejner', [], array_map(fn (array $d): array => $s($n('mrizka', [], [
                    $s($n('obrazek', ['alt' => $d[0]]), ['zaklad' => ['sirka' => '100%', 'pomer_stran' => '4/3', 'prizpusobeni' => 'cover', 'zaobleni' => 'l'] + ($d[2] ? ['poradi' => '2'] : []), 'tablet' => ['poradi' => '0']]),
                    $s($n('kontejner', [], [$n('nadpis', ['text' => $d[0]]), $n('text', ['html' => '<p>' . $d[1] . '</p>'])]), ['zaklad' => ['zobrazeni' => 'flex', 'smer' => 'column', 'mezera' => 's']]),
                ]), ['zaklad' => ['zobrazeni' => 'grid', 'sloupce' => '2', 'mezera' => '2xl', 'zarovnani' => 'center'], 'tablet' => ['sloupce' => '1', 'mezera' => 'l']]), [
                    [t('A design made for you'), t('We measure the space, talk through your habits and prepare a design you can see before we start.'), false],
                    [t('Honest workmanship'), t('We use proven materials and check every detail. We guarantee the result.'), true],
                ])), ['zaklad' => ['zobrazeni' => 'flex', 'smer' => 'column', 'mezera' => '2xl']]),
            ])],

            'proces' => ['nazev' => t('How it works'), 'popis' => t('Four numbered steps of working together.'), 'stavba' => fn (): array => $n('sekce', [], [
                $t($n('nadpis', ['text' => t('How it works')]), 'nadpis-sekce'),
                $s($n('mrizka', [], array_map(fn (array $d): array => $s($n('kontejner', [], [
                    $s($z($n('nadpis', ['text' => $d[0]]), 'p'), ['zaklad' => ['velikost_pisma' => '3', 'tloustka_pisma' => '800', 'barva' => 'primarni']]),
                    $z($n('nadpis', ['text' => $d[1]]), 'h3'),
                    $n('text', ['html' => '<p>' . $d[2] . '</p>']),
                ]), ['zaklad' => ['zobrazeni' => 'flex', 'smer' => 'column', 'mezera' => 'xs', 'linka_nahore' => '2px solid var(--ka-barva-primarni)', 'odsazeni_y' => 's']]), [
                    ['01', t('Enquiry'), t('You tell us what you need.')], ['02', t('Design and price'), t('Within a week you get a design and a fixed price.')],
                    ['03', t('Realizace'), t('We work on the agreed dates.')], ['04', t('Handover'), t('We go through everything together and hand it over.')],
                ])), ['zaklad' => ['zobrazeni' => 'grid', 'sloupce' => '4', 'mezera' => 'l'], 'tablet' => ['sloupce' => '2']]),
            ])],

            'sluzby-seznam' => ['nazev' => t('Services with prices'), 'popis' => t('A list of services with a short description and a “from” price.'), 'stavba' => fn (): array => $n('sekce', ['sirka' => 'uzka'], [
                $t($n('nadpis', ['text' => t('Services and prices')]), 'nadpis-sekce'),
                $s($n('kontejner', [], array_map(fn (array $d): array => $s($n('kontejner', [], [
                    $s($n('kontejner', [], [$z($n('nadpis', ['text' => $d[0]]), 'h3'), $t($n('text', ['html' => '<p>' . $d[1] . '</p>']), 'podtitul')]), ['zaklad' => ['zobrazeni' => 'flex', 'smer' => 'column', 'mezera' => '2xs']]),
                    $s($z($n('nadpis', ['text' => $d[2]]), 'p'), ['zaklad' => ['tloustka_pisma' => '700', 'velikost_pisma' => '1']]),
                ]), ['zaklad' => ['zobrazeni' => 'flex', 'smer' => 'row', 'rozmisteni' => 'space-between', 'zarovnani' => 'baseline', 'mezera' => 'm', 'odsazeni_y' => 'm', 'linka_dole' => '1px solid var(--ka-barva-linka)'], 'mobil' => ['smer' => 'column', 'mezera' => 'xs']]), [
                    [t('Consultation'), t('An hour with an expert at your place or online.'), t('[price]')],
                    [t('Tailored design'), t('A design including visualisation and budget.'), t('[price]')],
                    [t('Realizace'), t('Complete execution according to the design.'), t('depending on scope')],
                ])), ['zaklad' => ['zobrazeni' => 'flex', 'smer' => 'column']]),
            ])],

            'cenik' => ['nazev' => t('Pricing – three packages'), 'popis' => t('Three cards with price and features, the middle one highlighted.'), 'stavba' => fn (): array => $n('sekce', [], [
                $t($n('nadpis', ['text' => t('Choose a package')]), 'nadpis-sekce'),
                $s($n('mrizka', [], array_map(fn (array $d): array => $s($t($n('kontejner', [], [
                    $z($n('nadpis', ['text' => $d[0]]), 'h3'),
                    $s($z($n('nadpis', ['text' => $d[1]]), 'p'), ['zaklad' => ['velikost_pisma' => '3', 'tloustka_pisma' => '800']]),
                    $n('seznam', ['polozky' => $d[2], 'styl' => 'fajfky']),
                    $n('tlacitko', ['text' => t('I\'m interested'), 'odkaz' => $url('Contact'), 'varianta' => $d[3] ? 'primarni' : 'obrys']),
                ]), 'karta'), $d[3] ? ['zaklad' => ['ramecek' => '2px solid var(--ka-barva-primarni)', 'stin' => 'm']] : []), [
                    [t('Basic'), t('[price]'), t('Consultation') . "\n" . t('Solution design'), false],
                    [t('Standard'), t('[price]'), t('Consultation') . "\n" . t('Solution design') . "\n" . t('Realizace') . "\n" . t('A year of free service'), true],
                    [t('Na míru'), t('by agreement'), t('Everything in Standard') . "\n" . t('Your own schedule') . "\n" . t('A personal project manager'), false],
                ])), ['zaklad' => ['zobrazeni' => 'grid', 'sloupce' => 'auto:16rem', 'mezera' => 'l', 'zarovnani' => 'stretch']]),
            ])],

            'karty-odkazy' => ['nazev' => t('Signpost'), 'popis' => t('Three cards linking to subpages.'), 'stavba' => fn (): array => $n('sekce', [], [
                $s($n('mrizka', [], array_map(fn (array $d): array => $t($n('kontejner', ['odkaz' => $d[2]], [
                    $z($n('nadpis', ['text' => $d[0]]), 'h3'), $n('text', ['html' => '<p>' . $d[1] . '</p>']),
                    $s($z($n('nadpis', ['text' => t('More →')]), 'p'), ['zaklad' => ['barva' => 'primarni', 'tloustka_pisma' => '600']]),
                ]), 'karta'), [
                    [t('Services'), t('Everything we can do for you.'), $url('Services')], [t('About us'), t('Who we are and how we work.'), $url('About us')], [t('Contact'), t('Where to find us and how to get in touch.'), $url('Contact')],
                ])), ['zaklad' => ['zobrazeni' => 'grid', 'sloupce' => '3', 'mezera' => 'l'], 'tablet' => ['sloupce' => '1']]),
            ])],

            'text-sloupce' => ['nazev' => t('Text in two columns'), 'popis' => t('A heading and a longer text split into two columns.'), 'stavba' => fn (): array => $n('sekce', [], [
                $t($n('nadpis', ['text' => t('Section heading')]), 'nadpis-sekce'),
                $s($n('mrizka', [], [
                    $n('text', ['html' => '<p>' . t('Write the first part of the text here. Two columns read well for longer descriptions of services or processes.') . '</p>']),
                    $n('text', ['html' => '<p>' . t('And the second part here. On phones the columns stack by themselves.') . '</p>']),
                ]), ['zaklad' => ['zobrazeni' => 'grid', 'sloupce' => '2', 'mezera' => 'xl'], 'tablet' => ['sloupce' => '1']]),
            ])],

            'galerie' => ['nazev' => t('Gallery'), 'popis' => t('A grid of six images – work samples, premises, products.'), 'stavba' => fn (): array => $n('sekce', [], [
                $t($n('nadpis', ['text' => t('Samples of our work')]), 'nadpis-sekce'),
                $s($n('mrizka', [], array_map(fn (int $i): array => $s($n('obrazek', ['alt' => t('Sample %s', (string) $i)]), ['zaklad' => ['sirka' => '100%', 'pomer_stran' => '1', 'prizpusobeni' => 'cover', 'zaobleni' => 'm']]), range(1, 6))),
                    ['zaklad' => ['zobrazeni' => 'grid', 'sloupce' => '3', 'mezera' => 's'], 'mobil' => ['sloupce' => '2']]),
            ])],

            'portfolio' => ['nazev' => t('Realizace'), 'popis' => t('Cards of finished projects with an image, name and location.'), 'stavba' => fn (): array => $n('sekce', [], [
                $t($n('nadpis', ['text' => t('Our projects')]), 'nadpis-sekce'),
                $s($n('mrizka', [], array_map(fn (array $d): array => $s($n('kontejner', [], [
                    $s($n('obrazek', ['alt' => $d[0]]), ['zaklad' => ['sirka' => '100%', 'pomer_stran' => '4/3', 'prizpusobeni' => 'cover', 'zaobleni' => 'm']]),
                    $z($n('nadpis', ['text' => $d[0]]), 'h3'),
                    $t($n('text', ['html' => '<p>' . $d[1] . '</p>']), 'podtitul'),
                ]), ['zaklad' => ['zobrazeni' => 'flex', 'smer' => 'column', 'mezera' => 'xs']]), [
                    [t('Family house'), t('[Place, year]')], [t('Office'), t('[Place, year]')], [t('Flat renovation'), t('[Place, year]')],
                ])), ['zaklad' => ['zobrazeni' => 'grid', 'sloupce' => 'auto:18rem', 'mezera' => 'l']]),
            ])],

            'video' => ['nazev' => t('Video'), 'popis' => t('A heading and a video across the content width.'), 'stavba' => fn (): array => $n('sekce', ['sirka' => 'uzka'], [
                $t($n('nadpis', ['text' => t('Video')]), 'nadpis-sekce'),
                $n('video', ['url' => '']),
            ])],

            /* ---------- trust ---------- */

            'reference-jedna' => ['nazev' => t('Big quote'), 'popis' => t('One prominent testimonial in the centre.'), 'stavba' => fn (): array => $s($n('sekce', ['sirka' => 'uzka'], [
                $s($n('citat', ['text' => t('[A customer’s words – a real quote, used with their permission]'), 'autor' => t('[Customer’s name]'), 'pozice' => t('[Role, company]')]),
                    ['zaklad' => ['velikost_pisma' => '2', 'zarovnani_textu' => 'center']]),
            ]), ['zaklad' => ['odsazeni_y' => '2xl', 'pozadi' => 'primarni-jemna']])],

            'recenze' => ['nazev' => t('Customer reviews'), 'popis' => t('Three short reviews with stars.'), 'stavba' => fn (): array => $n('sekce', [], [
                $t($n('nadpis', ['text' => t('Customer reviews')]), 'nadpis-sekce'),
                $s($n('mrizka', [], array_map(fn (array $d): array => $t($n('kontejner', [], [
                    $s($z($n('nadpis', ['text' => t('[rating]')]), 'p'), ['zaklad' => ['barva' => 'primarni', 'proklad' => '0.12em']]),
                    $n('text', ['html' => '<p>' . $d[0] . '</p>']),
                    $s($z($n('nadpis', ['text' => $d[1]]), 'p'), ['zaklad' => ['tloustka_pisma' => '600', 'velikost_pisma' => '-1']]),
                ]), 'karta'), [
                    [t('[A short review from a real customer]'), t('[Customer’s name]')], [t('[A short review from a real customer]'), t('[Customer’s name]')], [t('[A short review from a real customer]'), t('[Customer’s name]')],
                ])), ['zaklad' => ['zobrazeni' => 'grid', 'sloupce' => '3', 'mezera' => 'l'], 'tablet' => ['sloupce' => '1']]),
            ])],

            'loga' => ['nazev' => t('Client logos'), 'popis' => t('A row of logos of companies you work with.'), 'stavba' => fn (): array => $n('sekce', [], [
                $s($t($n('nadpis', ['text' => t('We work with')]), 'podtitul'), ['zaklad' => ['zarovnani_textu' => 'center', 'okraj_dole' => 'l']]),
                $s($n('mrizka', [], array_map(fn (int $i): array => $s($n('obrazek', ['alt' => t('Client logo %s', (string) $i)]), ['zaklad' => ['sirka' => '100%', 'vyska' => '3rem', 'prizpusobeni' => 'contain', 'pruhlednost' => '0.8']]), range(1, 6))),
                    ['zaklad' => ['zobrazeni' => 'grid', 'sloupce' => '6', 'mezera' => 'l', 'zarovnani' => 'center'], 'tablet' => ['sloupce' => '3']]),
            ])],

            'cisla-svetla' => ['nazev' => t('Numbers on a light background'), 'popis' => t('Three big numbers with labels, without a coloured band.'), 'stavba' => fn (): array => $n('sekce', [], [
                $s($n('mrizka', [], array_map(fn (array $d): array => $s($n('kontejner', [], [
                    $s($z($n('nadpis', ['text' => $d[0]]), 'p'), ['zaklad' => ['velikost_pisma' => '5', 'tloustka_pisma' => '800', 'barva' => 'primarni']]),
                    $t($n('text', ['html' => '<p>' . $d[1] . '</p>']), 'podtitul'),
                ]), ['zaklad' => ['zobrazeni' => 'flex', 'smer' => 'column', 'mezera' => '2xs']]), [
                    [t('[number]'), t('years of experience')], [t('[number]'), t('spokojených zákazníků')], [t('[number]'), t('guarantee on work')],
                ])), ['zaklad' => ['zobrazeni' => 'grid', 'sloupce' => '3', 'mezera' => 'l'], 'mobil' => ['sloupce' => '1']]),
            ])],

            'zaruky' => ['nazev' => t('Guarantees'), 'popis' => t('A strip with four short promises – delivery, guarantee, deadline, payment.'), 'stavba' => fn (): array => $s($n('sekce', [], [
                $s($n('mrizka', [], array_map(fn (array $d): array => $s($n('kontejner', [], [
                    $s($z($n('nadpis', ['text' => $d[0]]), 'p'), ['zaklad' => ['velikost_pisma' => '1', 'tloustka_pisma' => '700']]), $t($n('text', ['html' => '<p>' . $d[1] . '</p>']), 'podtitul'),
                ]), ['zaklad' => ['zobrazeni' => 'flex', 'smer' => 'column', 'mezera' => '2xs']]), [
                    [t('Free delivery'), t('Within 50 km.')], [t('5-year guarantee'), t('On all work.')], [t('Fixed deadline'), t('Or a discount for every day late.')], [t('Pay after handover'), t('No large deposits.')],
                ])), ['zaklad' => ['zobrazeni' => 'grid', 'sloupce' => '4', 'mezera' => 'l'], 'tablet' => ['sloupce' => '2']]),
            ]), ['zaklad' => ['odsazeni_y' => 'l', 'pozadi' => 'plocha']])],

            /* ---------- about the company ---------- */

            'pribeh' => ['nazev' => t('Our story'), 'popis' => t('An image and text about who you are and how you started.'), 'stavba' => fn (): array => $n('sekce', [], [
                $s($n('mrizka', [], [
                    $s($n('obrazek', ['alt' => t('Our team')]), ['zaklad' => ['sirka' => '100%', 'pomer_stran' => '3/4', 'prizpusobeni' => 'cover', 'zaobleni' => 'l'], 'tablet' => ['pomer_stran' => '4/3']]),
                    $s($n('kontejner', [], [
                        $n('nadpis', ['text' => t('We started in a small workshop')]),
                        $n('text', ['html' => '<p>' . t('Describe how the company started and what drives you. People buy from people – a story helps them decide.') . '</p><p>' . t('Add what makes you different and what you are proud of.') . '</p>']),
                    ]), ['zaklad' => ['zobrazeni' => 'flex', 'smer' => 'column', 'mezera' => 's']]),
                ]), ['zaklad' => ['zobrazeni' => 'grid', 'sloupce' => '2fr 3fr', 'mezera' => '2xl', 'zarovnani' => 'center'], 'tablet' => ['sloupce' => '1', 'mezera' => 'l']]),
            ])],

            'tym' => ['nazev' => t('Team'), 'popis' => t('Cards of people with a photo, name and role. For a larger team use a collection.'), 'stavba' => fn (): array => $n('sekce', [], [
                $t($n('nadpis', ['text' => t('Who works for you')]), 'nadpis-sekce'),
                $s($n('mrizka', [], array_map(fn (array $d): array => $s($n('kontejner', [], [
                    $s($n('obrazek', ['alt' => $d[0]]), ['zaklad' => ['sirka' => '100%', 'pomer_stran' => '1', 'prizpusobeni' => 'cover', 'zaobleni' => 'plne']]),
                    $z($n('nadpis', ['text' => $d[0]]), 'h3'), $t($n('text', ['html' => '<p>' . $d[1] . '</p>']), 'podtitul'),
                ]), ['zaklad' => ['zobrazeni' => 'flex', 'smer' => 'column', 'mezera' => 'xs', 'zarovnani_textu' => 'center', 'zarovnani' => 'center']]), [
                    [t('[Name]'), t('owner')], [t('[Name]'), t('design and measuring')], [t('[Name]'), t('project lead')],
                ])), ['zaklad' => ['zobrazeni' => 'grid', 'sloupce' => 'auto:12rem', 'mezera' => 'l']]),
            ])],

            'hodnoty' => ['nazev' => t('Our values'), 'popis' => t('Three or four principles you work by.'), 'stavba' => fn (): array => $n('sekce', [], [
                $t($n('nadpis', ['text' => t('What we stand for')]), 'nadpis-sekce'),
                $s($n('mrizka', [], array_map(fn (array $d): array => $s($n('kontejner', [], [$z($n('nadpis', ['text' => $d[0]]), 'h3'), $n('text', ['html' => '<p>' . $d[1] . '</p>'])]),
                    ['zaklad' => ['zobrazeni' => 'flex', 'smer' => 'column', 'mezera' => 'xs', 'linka_nahore' => '1px solid var(--ka-barva-linka)', 'odsazeni_y' => 's']]), [
                    [t('Honesty'), t('We say what we will do, and we do what we say.')], [t('Řemeslo'), t('We make every detail as if it were for ourselves.')], [t('Consideration'), t('For customers, neighbours and nature.')],
                ])), ['zaklad' => ['zobrazeni' => 'grid', 'sloupce' => '3', 'mezera' => 'l'], 'tablet' => ['sloupce' => '1']]),
            ])],

            'historie' => ['nazev' => t('History'), 'popis' => t('Company milestones with a year and a short description.'), 'stavba' => fn (): array => $n('sekce', ['sirka' => 'uzka'], [
                $t($n('nadpis', ['text' => t('Our journey')]), 'nadpis-sekce'),
                $s($n('kontejner', [], array_map(fn (array $d): array => $s($n('kontejner', [], [
                    $s($z($n('nadpis', ['text' => $d[0]]), 'p'), ['zaklad' => ['tloustka_pisma' => '800', 'barva' => 'primarni', 'velikost_pisma' => '1']]),
                    $n('text', ['html' => '<p>' . $d[1] . '</p>']),
                ]), ['zaklad' => ['zobrazeni' => 'grid', 'sloupce' => '6rem 1fr', 'mezera' => 'm', 'odsazeni_y' => 's', 'linka_dole' => '1px solid var(--ka-barva-linka)'], 'mobil' => ['sloupce' => '1', 'mezera' => '2xs']]), [
                    [t('[year]'), t('The company is founded in a family garage.')], [t('[year]'), t('A new workshop and the first employees.')], [t('[year]'), t('The five-hundredth finished project.')], [t('[year]'), t('Opening of the showroom.')],
                ])), ['zaklad' => ['zobrazeni' => 'flex', 'smer' => 'column']]),
            ])],

            'kariera' => ['nazev' => t('Careers'), 'popis' => t('An invitation to join the team with open positions and a link.'), 'stavba' => fn (): array => $n('sekce', [], [
                $s($n('mrizka', [], [
                    $s($n('kontejner', [], [
                        $n('nadpis', ['text' => t('Join us')]),
                        $t($n('text', ['html' => '<p>' . t('We are looking for skilled people who enjoy work done well.') . '</p>']), 'podtitul'),
                    ]), ['zaklad' => ['zobrazeni' => 'flex', 'smer' => 'column', 'mezera' => 's']]),
                    $t($n('kontejner', [], [
                        $z($n('nadpis', ['text' => t('Open positions')]), 'h3'),
                        $n('seznam', ['polozky' => t('Joiner') . "\n" . t('Montážník') . "\n" . t('Sales representative'), 'styl' => 'fajfky']),
                        $n('tlacitko', ['text' => t('Send your CV'), 'odkaz' => $url('Contact')]),
                    ]), 'karta'),
                ]), ['zaklad' => ['zobrazeni' => 'grid', 'sloupce' => '2', 'mezera' => '2xl', 'zarovnani' => 'center'], 'tablet' => ['sloupce' => '1', 'mezera' => 'l']]),
            ])],

            'pobocky' => ['nazev' => t('Branches'), 'popis' => t('Branch cards with address, phone and opening hours.'), 'stavba' => fn (): array => $n('sekce', [], [
                $t($n('nadpis', ['text' => t('Where to find us')]), 'nadpis-sekce'),
                $s($n('mrizka', [], array_map(fn (array $d): array => $t($n('kontejner', [], [
                    $z($n('nadpis', ['text' => $d[0]]), 'h3'),
                    $n('text', ['html' => '<p>' . $d[1] . '<br>' . $d[2] . '</p><p>' . $d[3] . '</p>']),
                ]), 'karta'), [
                    ...array_fill(0, 3, [t('[Town]'), t('[Street and number]'), t('[Postcode and town]'), t('[Opening hours]')]),
                ])), ['zaklad' => ['zobrazeni' => 'grid', 'sloupce' => 'auto:16rem', 'mezera' => 'l']]),
            ])],

            /* ---------- contact and calls to action ---------- */

            'vyzva-pruh' => ['nazev' => t('Call-to-action strip'), 'popis' => t('A narrow strip: a sentence on the left, a button on the right.'), 'stavba' => fn (): array => $s($n('sekce', [], [
                $s($n('kontejner', [], [
                    $s($z($n('nadpis', ['text' => t('Need advice? Give us a call.')]), 'p'), ['zaklad' => ['velikost_pisma' => '2', 'tloustka_pisma' => '700']]),
                    $n('tlacitko', ['text' => t('Contact us'), 'odkaz' => $url('Contact'), 'varianta' => 'sekundarni']),
                ]), ['zaklad' => ['zobrazeni' => 'flex', 'smer' => 'row', 'rozmisteni' => 'space-between', 'zarovnani' => 'center', 'mezera' => 'm', 'zalamovani' => 'wrap']]),
            ]), ['zaklad' => ['odsazeni_y' => 'l', 'pozadi' => 'primarni', 'barva' => 'na-primarni']])],

            'kontakt-formular' => ['nazev' => t('Contact with form'), 'popis' => t('Business details on the left, an enquiry form on the right.'), 'stavba' => fn (): array => $n('sekce', [], [
                $s($n('mrizka', [], [
                    $s($n('kontejner', [], [
                        $z($n('nadpis', ['text' => t('Contact details')]), 'h2'),
                        $n('udaje', ['udaj' => 'firma']), $z($n('udaje', ['udaj' => 'adresa']), 'address'),
                        $n('udaje', ['udaj' => 'telefon']), $n('udaje', ['udaj' => 'email']), $n('udaje', ['udaj' => 'hodiny']), $n('udaje', ['udaj' => 'mapa']),
                    ]), ['zaklad' => ['zobrazeni' => 'flex', 'smer' => 'column', 'mezera' => 'xs']]),
                    $t($n('kontejner', [], [$z($n('nadpis', ['text' => t('Write to us')]), 'h2'), $n('formular')]), 'karta'),
                ]), ['zaklad' => ['zobrazeni' => 'grid', 'sloupce' => '2fr 3fr', 'mezera' => '2xl'], 'tablet' => ['sloupce' => '1', 'mezera' => 'l']]),
            ])],

            'faq-dva' => ['nazev' => t('Questions in two columns'), 'popis' => t('Heading on the left, questions and answers on the right.'), 'stavba' => fn (): array => $n('sekce', [], [
                $s($n('mrizka', [], [
                    $s($n('kontejner', [], [$n('nadpis', ['text' => t('Frequently asked questions')]), $t($n('text', ['html' => '<p>' . t('Didn\'t find an answer? Write to us.') . '</p>']), 'podtitul')]),
                        ['zaklad' => ['zobrazeni' => 'flex', 'smer' => 'column', 'mezera' => 's']]),
                    $n('faq'),
                ]), ['zaklad' => ['zobrazeni' => 'grid', 'sloupce' => '1fr 2fr', 'mezera' => '2xl'], 'tablet' => ['sloupce' => '1', 'mezera' => 'l']]),
            ])],
        ];
    }

    /**
     * Starter sites for the installation: an appearance preset (DesignSystem::PRESETS) and sections for the pages Home, About us, Services, Contact.
     * A page without sections stays a text page. The „nadpis-stranky“ section gets the page title.
     */
    public const array SITES = [
        'firemni' => ['nazev' => 'Business website', 'popis' => 'A versatile services website: benefits, numbers, testimonials, news.', 'predvolba' => 'firemni', 'stranky' => [
            ['uvod', 'vyhody', 'cisla', 'reference', 'novinky', 'vyzva'], [], ['nadpis-stranky', 'sluzby', 'faq', 'vyzva'], ['nadpis-stranky', 'kontakt', 'poptavka'],
        ]],
        'remeslo' => ['nazev' => 'Crafts and services', 'popis' => 'Warm colours, how you work, projects and guarantees.', 'predvolba' => 'remeslo', 'stranky' => [
            ['uvod-obrazek', 'zaruky', 'proces', 'portfolio', 'reference', 'vyzva'], ['nadpis-stranky', 'pribeh', 'hodnoty', 'tym'],
            ['nadpis-stranky', 'strida', 'sluzby-seznam', 'faq', 'vyzva-pruh'], ['nadpis-stranky', 'kontakt-formular'],
        ]],
        'poradenstvi' => ['nazev' => 'Consulting and agency', 'popis' => 'An elegant look, clients, service packages and the team.', 'predvolba' => 'elegantni', 'stranky' => [
            ['uvod-stred', 'loga', 'vyhody-seznam', 'cisla-svetla', 'reference-jedna', 'vyzva'], ['nadpis-stranky', 'pribeh', 'tym', 'historie', 'kariera'],
            ['nadpis-stranky', 'sluzby', 'cenik', 'faq-dva'], ['nadpis-stranky', 'kontakt-formular', 'pobocky'],
        ]],
    ];

    /**
     * Templates of a new page („Nová stránka → Začít podle šablony“, i.e. New page → Start from a template): key => [name, sections].
     * The privacy policy has its own text.
     */
    public const array PAGE_TEMPLATES = [
        'o-nas' => ['About us', ['nadpis-stranky', 'pribeh', 'hodnoty', 'tym']],
        'sluzby' => ['Services', ['nadpis-stranky', 'sluzby', 'proces', 'faq', 'vyzva']],
        'landing' => ['Sales page (landing page)', ['uvod', 'vyhody', 'reference', 'cenik', 'faq', 'vyzva']],
        'reference' => ['Testimonials and projects', ['nadpis-stranky', 'portfolio', 'reference', 'vyzva']],
        'kariera' => ['Careers', ['nadpis-stranky', 'kariera', 'poptavka']],
        'kontakt' => ['Contact', ['nadpis-stranky', 'kontakt-formular']],
        'zasady' => ['Privacy policy', []],
        'tiraz' => ['Imprint (legal notice)', ['nadpis-stranky', 'tiraz']],
    ];

    /**
     * Skeleton of a privacy policy (HTML in the current site language). Since 1.9 it follows the site: sections only for
     * the features that are switched on (enquiries, newsletter, statistics, analytics and marketing codes, the consent
     * record, maps, a CRM webhook), and the company details, periods and services already filled in. What the site does
     * not know stays in square brackets. It is a template with a disclaimer at the top, never legal advice.
     */
    public static function privacyPolicyText(?\Kaleta\Core\Settings $s = null): string
    {
        $o = fn (string $heading, string ...$texts): string => '<h2>' . e(t($heading)) . '</h2>' . implode('', array_map(fn (string $x): string => '<p>' . $x . '</p>', array_filter($texts)));
        $x = fn (string $text, mixed ...$args): string => e(t($text, ...$args));
        $on = fn (string $extension): bool => $s === null ? $extension === 'poptavky' : \Kaleta\Core\Extensions::isEnabled($s, $extension);
        $get = fn (string $key): string => $s === null ? '' : trim($s->get($key));
        $address = trim($get('company_street') . ', ' . trim($get('company_postcode') . ' ' . $get('company_city')), ', ');

        $html = '<p><em>' . $x('This text is a starting template generated from the features switched on on the website, not legal advice. Check it against how you really process data and against the rules of your country before you publish it.') . '</em></p>'
            . '<p>' . $x('This policy explains how [COMPANY NAME], company ID [ID], registered at [ADDRESS], processes the personal data you entrust to us.') . '</p>';
        if ($on('poptavky')) {
            $months = (int) ($s?->int('enquiries_months') ?? 0);
            $html .= $o('What data we process', $x('Your name, e-mail, phone and the content of the message you fill in the enquiry form.'))
                . $o('Why and on what basis', $x('So that we can reply and prepare an offer – these are steps prior to entering into a contract and our legitimate interest in answering your enquiry.'))
                . $o('How long', $months > 0 ? str_replace(t('[NUMBER]'), (string) $months, $x('We delete enquiries automatically after [NUMBER] months unless they lead to a contract.')) : $x('We delete enquiries automatically after [NUMBER] months unless they lead to a contract.'));
        }
        if ($on('newsletter')) {
            // a named mailing service is a processor; a generic webhook is described by the administrator
            $service = (\Kaleta\Core\Newsletter::SERVICES[$get('newsletter_service')][1] ?? false) ? \Kaleta\Core\Newsletter::SERVICES[$get('newsletter_service')][0] : '';
            $html .= $o('Newsletter', $x('If you subscribe to our newsletter, we keep your e-mail address and the time of your consent. You confirm the subscription in an e-mail (double opt-in) and can unsubscribe with one click in every newsletter; after that we delete the address.'),
                $service !== '' ? $x('We send the newsletter through %s, who processes the addresses for us.', $service) : '');
        }
        $webhook = (string) parse_url($get('webhook_enquiries'), PHP_URL_HOST);
        $html .= $o('Who has access to the data', $x('Only us and our hosting provider [HOSTING NAME], who runs the website for us.'),
            $on('poptavky') && $webhook !== '' ? $x('We pass enquiries to %s, where we handle them further.', $webhook) : '');
        $analytics = [];
        if ($on('statistika')) {
            $analytics[] = $x('The website measures traffic without cookies. It uses third-party cookies only with your consent.');
        }
        if ($get('ga4_id') !== '') {
            $analytics[] = $x('With your consent, the website uses Google Analytics (Google Ireland Limited) to measure traffic; it stores cookies in your browser.');
        }
        if ($get('gtm_id') !== '') {
            $analytics[] = $x('With your consent, the website uses Google Tag Manager (Google Ireland Limited) to run analytics and advertising tags; they store cookies in your browser.');
        }
        if ($s !== null && \Kaleta\Core\Captcha::provider($s) !== null) {
            $analytics[] = $x('To protect the forms against spam, the website uses %s, which receives your IP address and details about your browser when you send a form.', \Kaleta\Core\Captcha::PROVIDERS[$get('captcha_provider')][0]);
        }
        if ($get('matomo_url') !== '') {
            $analytics[] = $x('The website measures traffic with Matomo, run at %s.', (string) parse_url($get('matomo_url'), PHP_URL_HOST));
        }
        if ($get('plausible_domain') !== '') {
            $analytics[] = $x('The website measures traffic with Plausible Analytics without cookies and without personal data.');
        }
        if ($get('marketing_code') !== '') {
            $analytics[] = $x('With your consent, the website loads marketing codes (for example advertising pixels) that store cookies.');
        }
        if ($s !== null && $s->bool('cookies_log') && $s->get('cookies_mode') === 'vestavena') {
            $analytics[] = $x('We keep a record of the consent you give in the cookie bar for %d months, without your name or IP address.', max(1, $s->int('cookies_log_months')));
        }
        if ($get('company_map') !== '') {
            $analytics[] = $x('Maps load from the map provider only after you click them.');
        }
        if ($analytics !== []) {
            $html .= $o('Cookies and analytics', ...$analytics);
        }
        $html .= $o('Your rights', $x('You have the right to access, rectify and erase your data, to restrict processing and to object. You can lodge a complaint with the data protection authority.'))
            . $o('Contact', $x('Write to us at [E-MAIL] or call [PHONE].'));

        // what the site already knows goes in; the rest stays in brackets for the administrator
        $email = $get('company_email'); // the public company e-mail – the site e-mail is never published

        // the placeholders are translated with the text ([NÁZEV FIRMY] in Czech), so they are looked up the same way
        $known = ['[COMPANY NAME]' => $get('company_name'), '[ID]' => $get('company_id'), '[ADDRESS]' => $address, '[E-MAIL]' => $email, '[PHONE]' => $get('company_phone')];
        $fill = [];
        foreach ($known as $placeholder => $value) {
            if ($value !== '') {
                $fill[e(t($placeholder))] = e($value);
            }
        }

        return strtr($html, $fill);
    }

    /** Replacement of a section with an element of a disabled extension: contact without a form has at least the company details and opening hours. */
    private const array REPLACEMENTS = ['kontakt-formular' => 'kontakt'];

    /**
     * Build of a starter site page from sections (in the installation language); the used classes are created.
     *
     * @param list<string> $section
     * @param list<string> $withoutTypes sections with these elements are left out or replaced (disabled extensions)
     * @param bool $withoutImages leave out empty images (the starter site from the installation has no photos, they would leave an empty space on the site)
     */
    public static function page(\Kaleta\Core\Db $db, array $section, string $title, string $language, array $withoutTypes = [], bool $withoutImages = false): array
    {
        [$build, $classes] = self::assemble($section, $title, $language, $withoutTypes, $withoutImages);
        self::createClasses($db, $classes);

        return $build;
    }

    /**
     * Build of a page from sections and the classes it uses (without writing to the database – see page()).
     *
     * @param list<string> $section
     * @param list<string> $withoutTypes
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    public static function assemble(array $section, string $title, string $language, array $withoutTypes = [], bool $withoutImages = false): array
    {
        $build = ['v' => Build::VERSION, 'deti' => []];
        $classes = [];
        foreach ($section as $key) {
            $s = self::section($key, $language);
            if ($s !== null && $withoutTypes !== [] && self::containsType($s['prvek'], $withoutTypes)) {
                $s = isset(self::REPLACEMENTS[$key]) && !in_array(self::REPLACEMENTS[$key], $section, true) ? self::section(self::REPLACEMENTS[$key], $language) : null;
            }
            $element = $s === null ? null : ($withoutImages ? self::withoutImages($s['prvek']) : $s['prvek']);
            if ($element === null) {
                continue;
            }
            if ($key === 'nadpis-stranky') {
                $element['deti'][0]['obsah']['text'] = e($title);
            }
            $build['deti'][] = $element;
            array_push($classes, ...$s['tridy']);
        }

        return [$build, array_values(array_unique($classes))];
    }

    /**
     * An element without empty images: a grid in which a single element remains (text next to an image) is replaced by that element;
     * an element in which only headings remain (client logos) is left out entirely – null.
     */
    private static function withoutImages(array $p): ?array
    {
        if ($p['typ'] === 'obrazek' && ($p['obsah']['src'] ?? '') === '') {
            return null;
        }
        if (!isset($p['deti'])) {
            return $p;
        }
        $children = array_values(array_filter(array_map(self::withoutImages(...), $p['deti'])));
        if (count($children) < count($p['deti'])) {
            if ($p['typ'] === 'mrizka' && count($children) === 1) {
                return $children[0];
            }
            if (array_filter($children, fn (array $d): bool => $d['typ'] !== 'nadpis') === []) {
                return null;
            }
        }
        $p['deti'] = $children;

        return $p;
    }

    /** Categories in the „Hotové sekce“ (ready-made sections) panel (key => name). */
    public const array CATEGORIES = ['uvod' => 'Úvod', 'obsah' => 'Services and content', 'duvera' => 'Trust', 'firma' => 'About the company', 'akce' => 'Contact and calls to action'];

    /** Section categories (the others are „obsah“). */
    private const array SECTION_CATEGORIES = [
        'uvod' => 'uvod', 'uvod-obrazek' => 'uvod', 'uvod-stred' => 'uvod', 'uvod-tmavy' => 'uvod', 'uvod-video' => 'uvod', 'nadpis-stranky' => 'uvod', 'tiraz' => 'firma',
        'reference' => 'duvera', 'reference-jedna' => 'duvera', 'recenze' => 'duvera', 'loga' => 'duvera', 'cisla' => 'duvera', 'cisla-svetla' => 'duvera', 'zaruky' => 'duvera',
        'pribeh' => 'firma', 'tym' => 'firma', 'hodnoty' => 'firma', 'historie' => 'firma', 'kariera' => 'firma', 'pobocky' => 'firma',
        'vyzva' => 'akce', 'vyzva-pruh' => 'akce', 'poptavka' => 'akce', 'kontakt' => 'akce', 'kontakt-formular' => 'akce', 'faq' => 'akce', 'faq-dva' => 'akce',
    ];

    /**
     * @param list<string>|null $extensions enabled extensions (null = all) – sections with elements of disabled ones (news, form) are not offered
     * @return list<array{klic:string, nazev:string, popis:string, kategorie:string}>
     */
    public static function listAll(?array $extensions = null): array
    {
        $disabled = Build::disabledTypes($extensions);
        $section = array_filter(self::sections(), fn (string $key): bool => $disabled === [] || !self::containsType(self::create($key)['prvek'] ?? [], $disabled), ARRAY_FILTER_USE_KEY);

        return array_map(fn (string $key, array $s): array => ['klic' => $key, 'nazev' => $s['nazev'], 'popis' => $s['popis'], 'kategorie' => self::SECTION_CATEGORIES[$key] ?? 'obsah'],
            array_keys($section), $section);
    }

    /** @param list<string> $types */
    private static function containsType(array $element, array $types): bool
    {
        if (in_array($element['typ'] ?? '', $types, true)) {
            return true;
        }
        foreach ($element['deti'] ?? [] as $d) {
            if (self::containsType($d, $types)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A new copy of a section (new ids) and the names of the classes it uses. The sample texts are in the language of the page the
     * section goes to (not in the admin language) – translations in the site dictionary system/jazyky/<code>.php.
     *
     * @return array{prvek: array<string, mixed>, tridy: list<string>}|null
     */
    public static function section(string $key, string $language = 'cs'): ?array
    {
        return \Kaleta\Core\Language::runWith($language, fn (): ?array => self::create($key));
    }

    /** @return array{prvek: array<string, mixed>, tridy: list<string>}|null */
    private static function create(string $key): ?array
    {
        $section = self::sections()[$key] ?? null;
        if ($section === null) {
            return null;
        }
        [$build] = Build::sanitize(['v' => 1, 'deti' => [($section['stavba'])()]]);
        $element = $build['deti'][0];
        $element['popis'] = $section['nazev'];
        $classes = [];
        $walk = function (array $p) use (&$walk, &$classes): void {
            foreach ($p['tridy'] ?? [] as $t) {
                $classes[$t] = true;
            }
            foreach ($p['deti'] ?? [] as $d) {
                $walk($d);
            }
        };
        $walk($element);

        return ['prvek' => $element, 'tridy' => array_keys($classes)];
    }

    /** A copy of an element with new ids throughout (a saved section inserted again must not repeat the ids in the build). */
    public static function withNewIds(array $element): array
    {
        $element['id'] = Build::newId();
        if (is_array($element['deti'] ?? null)) {
            $element['deti'] = array_map(fn (mixed $d): mixed => is_array($d) ? self::withNewIds($d) : $d, $element['deti']);
        }

        return $element;
    }

    /** Creates the missing library classes (never overwrites an existing class of the site). */
    public static function createClasses(\Kaleta\Core\Db $db, array $names): void
    {
        foreach ($names as $name) {
            if (isset(self::CLASSES[$name])) {
                $db->run('INSERT IGNORE INTO {tridy} (nazev, styl, zmeneno) VALUES (?, ?, ?)', [$name, (string) json_encode(self::CLASSES[$name], JSON_UNESCAPED_UNICODE), \Kaleta\Core\Clock::now()]);
            }
        }
    }
}
