<?php

declare(strict_types=1);

namespace Kaleta\Front;

use Kaleta\Core\Settings;

/**
 * Company details from "Nastavení → Firma" (Business details): address, company ID, phone, opening hours, map. Used by
 * the Company details element (site) and by schema.org structured data (Organization / LocalBusiness) for search engines
 * and AI assistants.
 *
 * Opening hours are entered in human form, line by line („Po–Pá 8:00–17:00“, „So 9–12“, „Ne zavřeno“); the site prints
 * them as written, for structured data they are parsed.
 */
final class Company
{
    /** schema.org business types (key => label in Settings). */
    public const array TYPES = [
        'Organization' => 'company without premises for customers',
        'LocalBusiness' => 'business premises (general)',
        'HomeAndConstructionBusiness' => 'crafts and construction',
        'ProfessionalService' => 'professional services (consulting, agency)',
        'LegalService' => 'legal services',
        'AccountingService' => 'accounting and tax',
        'MedicalBusiness' => 'health and care',
        'AutomotiveBusiness' => 'car service and cars',
        'Store' => 'obchod',
        'FoodEstablishment' => 'restaurant, café',
        'LodgingBusiness' => 'ubytování',
        'SportsActivityLocation' => 'sport and fitness',
        'EducationalOrganization' => 'school and courses',
    ];

    /** Days of the week: abbreviations (Czech and English) → schema.org. */
    private const array DAYS = [
        'po' => 'Monday', 'ut' => 'Tuesday', 'st' => 'Wednesday', 'ct' => 'Thursday', 'pa' => 'Friday', 'so' => 'Saturday', 'ne' => 'Sunday',
        'mo' => 'Monday', 'tu' => 'Tuesday', 'we' => 'Wednesday', 'th' => 'Thursday', 'fr' => 'Friday', 'sa' => 'Saturday', 'su' => 'Sunday',
    ];

    /** Address in lines (street; postcode and city; country, only when it is not Czech). @return list<string> */
    public static function address(Settings $s): array
    {
        return array_values(array_filter([
            $s->get('company_street'),
            trim($s->get('company_postcode') . ' ' . $s->get('company_city')),
            $s->get('company_country') !== '' && $s->get('company_country') !== 'CZ' ? $s->get('company_country') : '',
        ], fn (string $r): bool => $r !== ''));
    }

    /** @return list<string> opening hours lines as the administrator entered them */
    public static function openingHoursLines(Settings $s): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/u', $s->get('company_hours')) ?: []), fn (string $r): bool => $r !== ''));
    }

    /**
     * Parses opening hours for schema.org. An unknown line = null (Settings rejects it with a message).
     *
     * @return list<array{dny: list<string>, od: string, do: string}>|null
     */
    public static function parseOpeningHours(string $text): ?array
    {
        $result = [];
        foreach (array_filter(array_map('trim', preg_split('/\R/u', $text) ?: [])) as $row) {
            $r = mb_strtolower(remove_diacritics(str_replace(['–', '—', '−'], '-', $row)));
            if (!preg_match('/^([a-z]{2})[a-z.]*\s*(?:-\s*([a-z]{2})[a-z.]*)?\s*:?\s+(.+)$/', $r, $m) || !isset(self::DAYS[$m[1]]) || ($m[2] !== '' && !isset(self::DAYS[$m[2]]))) {
                return null;
            }
            $days = self::dayRange($m[1], $m[2]);
            if (preg_match('/^(zavreno|closed|-)$/', trim($m[3]))) {
                continue;
            }
            foreach (preg_split('/\s*[,;]\s*/', trim($m[3])) ?: [] as $segment) {
                if (!preg_match('/^(\d{1,2})(?:[:.](\d{2}))?\s*-\s*(\d{1,2})(?:[:.](\d{2}))?$/', $segment, $c) || (int) $c[1] > 24 || (int) $c[3] > 24) {
                    return null;
                }
                $result[] = ['dny' => $days, 'od' => sprintf('%02d:%s', $c[1], $c[2] !== '' ? $c[2] : '00'), 'do' => sprintf('%02d:%s', $c[3], ($c[4] ?? '') !== '' ? $c[4] : '00')];
            }
        }

        return $result;
    }

    /** @return list<string> */
    private static function dayRange(string $from, string $to): array
    {
        $order = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
        $a = array_search(self::DAYS[$from], $order, true);
        $b = $to === '' ? $a : array_search(self::DAYS[$to], $order, true);

        return $b >= $a ? array_slice($order, (int) $a, (int) $b - (int) $a + 1) : array_merge(array_slice($order, (int) $a), array_slice($order, 0, (int) $b + 1));
    }

    /**
     * Company for schema.org (publisher of the site as well as a separate node on the home page).
     *
     * @param callable(string): string $absoluteUrl converts an image URL to an absolute one
     * @return array<string, mixed>
     */
    public static function schema(Settings $s, string $siteSettings, callable $absoluteUrl): array
    {
        $type = isset(self::TYPES[$s->get('company_type')]) ? $s->get('company_type') : 'Organization';
        $url = array_filter(['@type' => 'PostalAddress', 'streetAddress' => $s->get('company_street'), 'addressLocality' => $s->get('company_city'),
            'postalCode' => $s->get('company_postcode'), 'addressCountry' => $s->get('company_country')]);
        [$lat, $lng] = array_map('trim', explode(',', $s->get('company_gps'), 2)) + [1 => ''];

        return array_filter([
            '@type' => $type,
            '@id' => $siteSettings . '#firma',
            'name' => $s->get('site_name'),
            'legalName' => $s->get('company_name'),
            'url' => $siteSettings,
            'logo' => $s->get('logo') !== '' ? $absoluteUrl($s->get('logo')) : null,
            'image' => $s->get('logo') !== '' && $type !== 'Organization' ? $absoluteUrl($s->get('logo')) : null,
            'description' => $s->get('site_description'),
            'email' => $s->get('company_email'),
            'telephone' => $s->get('company_phone'),
            'vatID' => $s->get('company_vat_id'),
            'identifier' => $s->get('company_id') !== '' ? ['@type' => 'PropertyValue', 'propertyID' => $s->get('company_country') === 'CZ' ? 'Company ID' : 'Company ID', 'value' => $s->get('company_id')] : null,
            'address' => count($url) > 1 ? $url : null,
            'geo' => is_numeric($lat) && is_numeric($lng) ? ['@type' => 'GeoCoordinates', 'latitude' => (float) $lat, 'longitude' => (float) $lng] : null,
            'hasMap' => $s->get('company_map'),
            'openingHoursSpecification' => $type !== 'Organization' ? (\Kaleta\Core\Hours::specification($s->get('company_hours')) ?: null) : null,
            'sameAs' => array_values(array_filter(array_map($s->get(...), ['social_facebook', 'social_instagram', 'social_x', 'social_youtube', 'social_linkedin']))) ?: null,
        ], fn (mixed $v): bool => $v !== null && $v !== '');
    }
}
