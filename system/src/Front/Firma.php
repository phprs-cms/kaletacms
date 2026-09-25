<?php

declare(strict_types=1);

namespace Kaleta\Front;

use Kaleta\Core\Settings;

/**
 * Údaje o firmě z Nastavení → Firma: adresa, IČO, telefon, otevírací doba, mapa. Používá je prvek Údaje firmy (web)
 * a strukturovaná data schema.org (Organization / LocalBusiness) pro vyhledávače a AI asistenty.
 *
 * Otevírací doba se zadává lidsky, řádek po řádku („Po–Pá 8:00–17:00“, „So 9–12“, „Ne zavřeno“); na webu se vypíše,
 * jak byla napsaná, pro strukturovaná data se rozebere.
 */
final class Firma
{
    /** Typy podniku schema.org (klíč => popisek v Nastavení). */
    public const array TYPY = [
        'Organization' => 'firma bez provozovny pro zákazníky',
        'LocalBusiness' => 'provozovna (obecně)',
        'HomeAndConstructionBusiness' => 'řemeslo a stavebnictví',
        'ProfessionalService' => 'profesní služby (poradenství, agentura)',
        'LegalService' => 'právní služby',
        'AccountingService' => 'účetnictví a daně',
        'MedicalBusiness' => 'zdravotnictví a péče',
        'AutomotiveBusiness' => 'autoservis a auta',
        'Store' => 'obchod',
        'FoodEstablishment' => 'restaurace, kavárna',
        'LodgingBusiness' => 'ubytování',
        'SportsActivityLocation' => 'sport a fitness',
        'EducationalOrganization' => 'škola a kurzy',
    ];

    /** Dny v týdnu: zkratky (česky i anglicky) → schema.org. */
    private const array DNY = [
        'po' => 'Monday', 'ut' => 'Tuesday', 'st' => 'Wednesday', 'ct' => 'Thursday', 'pa' => 'Friday', 'so' => 'Saturday', 'ne' => 'Sunday',
        'mo' => 'Monday', 'tu' => 'Tuesday', 'we' => 'Wednesday', 'th' => 'Thursday', 'fr' => 'Friday', 'sa' => 'Saturday', 'su' => 'Sunday',
    ];

    /** Adresa na řádcích (ulice; PSČ a město; země, jen když není česká). @return list<string> */
    public static function adresa(Settings $s): array
    {
        return array_values(array_filter([
            $s->get('firma_ulice'),
            trim($s->get('firma_psc') . ' ' . $s->get('firma_mesto')),
            $s->get('firma_zeme') !== '' && $s->get('firma_zeme') !== 'CZ' ? $s->get('firma_zeme') : '',
        ], fn (string $r): bool => $r !== ''));
    }

    /** @return list<string> řádky otevírací doby, jak je zadal správce */
    public static function radkyHodin(Settings $s): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/', $s->get('firma_hodiny')) ?: []), fn (string $r): bool => $r !== ''));
    }

    /**
     * Rozebere otevírací dobu pro schema.org. Neznámý řádek = null (Nastavení ho odmítne s hláškou).
     *
     * @return list<array{dny: list<string>, od: string, do: string}>|null
     */
    public static function hodiny(string $text): ?array
    {
        $vysledek = [];
        foreach (array_filter(array_map('trim', preg_split('/\R/', $text) ?: [])) as $radek) {
            $r = mb_strtolower(bez_diakritiky(str_replace(['–', '—', '−'], '-', $radek)));
            if (!preg_match('/^([a-z]{2})[a-z.]*\s*(?:-\s*([a-z]{2})[a-z.]*)?\s*:?\s+(.+)$/', $r, $m) || !isset(self::DNY[$m[1]]) || ($m[2] !== '' && !isset(self::DNY[$m[2]]))) {
                return null;
            }
            $dny = self::rozsahDnu($m[1], $m[2]);
            if (preg_match('/^(zavreno|closed|-)$/', trim($m[3]))) {
                continue;
            }
            foreach (preg_split('/\s*[,;]\s*/', trim($m[3])) ?: [] as $usek) {
                if (!preg_match('/^(\d{1,2})(?:[:.](\d{2}))?\s*-\s*(\d{1,2})(?:[:.](\d{2}))?$/', $usek, $c) || (int) $c[1] > 24 || (int) $c[3] > 24) {
                    return null;
                }
                $vysledek[] = ['dny' => $dny, 'od' => sprintf('%02d:%s', $c[1], $c[2] !== '' ? $c[2] : '00'), 'do' => sprintf('%02d:%s', $c[3], ($c[4] ?? '') !== '' ? $c[4] : '00')];
            }
        }

        return $vysledek;
    }

    /** @return list<string> */
    private static function rozsahDnu(string $od, string $do): array
    {
        $poradi = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
        $a = array_search(self::DNY[$od], $poradi, true);
        $b = $do === '' ? $a : array_search(self::DNY[$do], $poradi, true);

        return $b >= $a ? array_slice($poradi, (int) $a, (int) $b - (int) $a + 1) : array_merge(array_slice($poradi, (int) $a), array_slice($poradi, 0, (int) $b + 1));
    }

    /**
     * Firma pro schema.org (vydavatel webu i samostatný uzel na úvodní stránce).
     *
     * @param callable(string): string $absolutni převod adresy obrázku na absolutní
     * @return array<string, mixed>
     */
    public static function schema(Settings $s, string $web, callable $absolutni): array
    {
        $typ = isset(self::TYPY[$s->get('firma_typ')]) ? $s->get('firma_typ') : 'Organization';
        $adresa = array_filter(['@type' => 'PostalAddress', 'streetAddress' => $s->get('firma_ulice'), 'addressLocality' => $s->get('firma_mesto'),
            'postalCode' => $s->get('firma_psc'), 'addressCountry' => $s->get('firma_zeme')]);
        [$lat, $lng] = array_map('trim', explode(',', $s->get('firma_gps'), 2)) + [1 => ''];

        return array_filter([
            '@type' => $typ,
            '@id' => $web . '#firma',
            'name' => $s->get('nazev_webu'),
            'legalName' => $s->get('firma_nazev'),
            'url' => $web,
            'logo' => $s->get('logo_webu') !== '' ? $absolutni($s->get('logo_webu')) : null,
            'image' => $s->get('logo_webu') !== '' && $typ !== 'Organization' ? $absolutni($s->get('logo_webu')) : null,
            'description' => $s->get('popis_webu'),
            'email' => $s->get('firma_email'),
            'telephone' => $s->get('firma_telefon'),
            'vatID' => $s->get('firma_dic'),
            'identifier' => $s->get('firma_ico') !== '' ? ['@type' => 'PropertyValue', 'propertyID' => 'IČO', 'value' => $s->get('firma_ico')] : null,
            'address' => count($adresa) > 1 ? $adresa : null,
            'geo' => is_numeric($lat) && is_numeric($lng) ? ['@type' => 'GeoCoordinates', 'latitude' => (float) $lat, 'longitude' => (float) $lng] : null,
            'hasMap' => $s->get('firma_mapa'),
            'openingHoursSpecification' => $typ !== 'Organization' ? (array_map(fn (array $h): array => ['@type' => 'OpeningHoursSpecification', 'dayOfWeek' => $h['dny'], 'opens' => $h['od'], 'closes' => $h['do']],
                self::hodiny($s->get('firma_hodiny')) ?? []) ?: null) : null,
            'sameAs' => array_values(array_filter(array_map($s->get(...), ['soc_facebook', 'soc_instagram', 'soc_x', 'soc_youtube', 'soc_linkedin']))) ?: null,
        ], fn (mixed $v): bool => $v !== null && $v !== '');
    }
}
