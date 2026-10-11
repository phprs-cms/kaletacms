<?php

declare(strict_types=1);

namespace Kaleta\Builder\Elements;

use Kaleta\Builder\Context;
use Kaleta\Builder\Element;

/**
 * A detail from Settings (address, phone, company ID, opening hours, copyright, social networks…) – filled in once and changed everywhere.
 * The company in „Nastavení → Firma“ (Business details), the site in „Nastavení → Základní“ (Settings → General).
 */
final class CompanyDetails extends Element
{
    public const string TYPE = 'udaje';
    public const string NAME = 'Business details'; // 3.11 (UX310-21): the same name as the section in the sidebar
    public const string DESCRIPTION = 'Address, phone, email, company ID, opening hours, map, copyright or social networks from Settings.';
    public const string ICON = 'udaje';
    public const string GROUP = 'Dynamic';
    public const array HTML_TAGS = ['p', 'div', 'span', 'address'];

    /** Marker of opening hours not filled in on the site: a container in which only a heading remains besides it is left out (Container::render). */
    public const string EMPTY_HOURS = '<!--ka-prazdne-hodiny-->';

    public static function properties(): array
    {
        return ['udaj' => ['typ' => 'vyber', 'popisek' => 'Údaj', 'vychozi' => 'copyright', 'moznosti' => [
            'adresa' => 'Adresa', 'telefon' => 'Phone', 'email' => 'Email', 'hodiny' => 'Opening hours', 'otevreno' => 'Open now (and until when)', 'mapa' => 'Map link',
            'firma' => 'Registered name and company ID', 'tiraz' => 'Imprint (all details of the operator)', 'copyright' => '© year and site name', 'nazev' => 'Site name', 'popis' => 'Site description',
            'text_paticky' => 'Text patičky', 'site' => 'Sociální sítě', 'rss' => 'RSS link',
        ]]];
    }

    public static function baseCss(): string
    {
        return '.ka-hodiny { margin: 0; padding: 0; list-style: none; }
.ka-udaj:is(address) { font-style: normal; }
.ka-site { display: flex; flex-wrap: wrap; gap: var(--ka-mezera-xs) var(--ka-mezera-s); margin: 0; padding: 0; list-style: none; }
.ka-site a, .ka-udaj a { color: inherit; }
.ka-tiraz { display: grid; grid-template-columns: max-content 1fr; gap: var(--ka-mezera-2xs) var(--ka-mezera-m); margin: 0; }
.ka-tiraz dt { font-weight: 600; }
.ka-tiraz dd { margin: 0; }
@media (max-width: 600px) { .ka-tiraz { grid-template-columns: 1fr; } .ka-tiraz dd { margin-block-end: var(--ka-mezera-xs); } }';
    }

    public static function render(array $p, string $a, string $children, Context $k): string
    {
        $siteSettings = $k->app->settings();
        $z = $p['znacka'];
        $wrapper = fn (string $html): string => $html === '' && !$k->editor ? '' : '<' . $z . Text::withClass($a, 'ka-udaj') . '>' . ($html !== '' ? $html : e(t('(fill in under Business details)'))) . '</' . $z . '>';

        return match ($p['obsah']['udaj']) {
            'copyright' => $wrapper('&copy; ' . date('Y') . ' ' . e($siteSettings->get('site_name'))),
            'nazev' => $wrapper(e($siteSettings->get('site_name'))),
            'popis' => $wrapper(e($siteSettings->get('site_description'))),
            'text_paticky' => $wrapper(e($siteSettings->get('footer_text'))),
            'email' => $wrapper(($mail = $siteSettings->get('company_email')) !== '' ? '<a href="mailto:' . e($mail) . '">' . e($mail) . '</a>' : ''),
            'rss' => \Kaleta\Core\Extensions::isEnabled($siteSettings, 'novinky') ? $wrapper('<a href="' . e($k->url('rss.xml')) . '">RSS</a>') : '', // no RSS without news
            'adresa' => $wrapper(implode('<br>', array_map(e(...), \Kaleta\Front\Company::address($siteSettings)))),
            'telefon' => $wrapper($siteSettings->get('company_phone') !== '' ? '<a href="tel:' . e((string) preg_replace('/[^\d+]/', '', $siteSettings->get('company_phone'))) . '">' . e($siteSettings->get('company_phone')) . '</a>' : ''),
            'mapa' => $wrapper($siteSettings->get('company_map') !== '' ? '<a href="' . e($siteSettings->get('company_map')) . '" target="_blank" rel="noopener">' . e(t('Show on map')) . '</a>' : ''),
            'firma' => $wrapper(implode('<br>', array_map(e(...), array_filter([
                $siteSettings->get('company_name'),
                trim(($siteSettings->get('company_id') !== '' ? t('Company ID') . ' ' . $siteSettings->get('company_id') : '') . ($siteSettings->get('company_vat_id') !== '' ? ', ' . t('VAT ID') . ' ' . $siteSettings->get('company_vat_id') : ''), ', '),
            ])))),
            'hodiny' => ($rows = [...\Kaleta\Front\Company::openingHoursLines($siteSettings), ...self::upcomingExceptions($k)]) !== []
                ? '<ul' . Text::withClass($a, 'ka-hodiny') . '>' . implode('', array_map(fn (string $r): string => '<li>' . e($r) . '</li>', $rows)) . '</ul>'
                : ($k->editor ? $wrapper('') : self::EMPTY_HOURS),
            // open now, until when / when it opens next – with the exceptions (2.10); the page must not be cached for long
            'otevreno' => $wrapper(e(\Kaleta\Core\Hours::statusText($k->app))),
            'site' => self::networks($siteSettings, $a, $k),
            'tiraz' => self::imprint($siteSettings, $a, $k),
            default => '',
        };
    }

    /**
     * Exceptions to the opening hours in the next 30 days, as lines under the regular hours (2.10).
     *
     * @return list<string>
     */
    private static function upcomingExceptions(Context $k): array
    {
        $limit = date('Y-m-d', strtotime('+30 days'));

        return array_map(\Kaleta\Core\Hours::describe(...), array_values(array_filter(\Kaleta\Core\Hours::exceptions($k->app->db()), fn (array $e): bool => $e['from'] <= $limit)));
    }

    /**
     * Imprint (Impressum): who operates the site – business name, registered office, identification numbers, registry entry,
     * representation and contact. Outputs only the details filled in under „Nastavení → Firma“ (Business details).
     */
    private static function imprint(\Kaleta\Core\Settings $siteSettings, string $a, Context $k): string
    {
        $phone = $siteSettings->get('company_phone');
        $mail = $siteSettings->get('company_email');
        $rows = array_filter([
            t('Operator') => e($siteSettings->get('company_name') !== '' ? $siteSettings->get('company_name') : $siteSettings->get('site_name')),
            t('Registered office') => implode('<br>', array_map(e(...), \Kaleta\Front\Company::address($siteSettings))),
            t('Company ID') => e($siteSettings->get('company_id')),
            t('VAT ID') => e($siteSettings->get('company_vat_id')),
            t('Commercial register') => e($siteSettings->get('company_register')),
            t('Represented by') => e($siteSettings->get('company_representative')),
            t('Phone') => $phone !== '' ? '<a href="tel:' . e((string) preg_replace('/[^\d+]/', '', $phone)) . '">' . e($phone) . '</a>' : '',
            t('Email') => $mail !== '' ? '<a href="mailto:' . e($mail) . '">' . e($mail) . '</a>' : '',
        ], fn (string $h): bool => $h !== '');
        if (count($rows) < 2 && $k->editor) {
            return '<p' . $a . '>' . e(t('(fill in under Business details)')) . '</p>';
        }

        return '<dl' . Text::withClass($a, 'ka-tiraz') . '>' . implode('', array_map(fn (string $n, string $h): string => '<dt>' . e($n) . '</dt><dd>' . $h . '</dd>', array_keys($rows), $rows)) . '</dl>';
    }

    private static function networks(\Kaleta\Core\Settings $siteSettings, string $a, Context $k): string
    {
        $networks = array_filter(['LinkedIn' => $siteSettings->get('social_linkedin'), 'Facebook' => $siteSettings->get('social_facebook'), 'Instagram' => $siteSettings->get('social_instagram'), 'YouTube' => $siteSettings->get('social_youtube'), 'X' => $siteSettings->get('social_x')]);
        if ($networks === []) {
            return $k->editor ? '<p' . $a . '>' . e(t('Add social networks under Settings.')) . '</p>' : '';
        }

        return '<ul' . Text::withClass($a, 'ka-site') . '>' . implode('', array_map(fn (string $n, string $u): string => '<li><a href="' . e($u) . '" rel="me noopener" target="_blank">' . e($n) . '</a></li>', array_keys($networks), $networks)) . '</ul>';
    }
}
