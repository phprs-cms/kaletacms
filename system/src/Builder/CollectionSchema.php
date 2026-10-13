<?php

declare(strict_types=1);

namespace Kaleta\Builder;

/**
 * Structured data of collection item pages (1.9): a collection says which schema.org type its items are – a service,
 * a person, a product, an event, a question with an answer or a local business (a branch, 2.11) – and which of its fields
 * fill which properties. Item pages then carry that JSON-LD next to the site and company data (Front\Seo). Nothing is
 * guessed: a property without a field is left out, and an offer needs both a price and a currency.
 *
 * Stored in ka_kolekce.schema_org as {"typ": "Service", "pole": {"price": "cena", …}, "mena": "EUR"}.
 */
final class CollectionSchema
{
    /** type => [label, property => label] – the name, the address, the description and the image come from the item itself */
    public const array TYPES = [
        'Service' => ['Service', ['serviceType' => 'Kind of service', 'areaServed' => 'Area served', 'price' => 'Price']],
        'Person' => ['Person', ['jobTitle' => 'Job title', 'email' => 'E-mail', 'telephone' => 'Phone', 'sameAs' => 'Profile link']],
        'Product' => ['Product', ['brand' => 'Brand', 'sku' => 'Product code (SKU)', 'price' => 'Price']],
        'Event' => ['Event', ['startDate' => 'Start', 'endDate' => 'End', 'location' => 'Place', 'address' => 'Address of the place', 'online' => 'Online link', 'price' => 'Price']],
        'FAQPage' => ['Question and answer', ['answer' => 'Answer']],
        // 2.11: validThrough comes from the item's "true until", the hiring organization from Business details (Core\Jobs)
        'JobPosting' => ['Job opening', ['description' => 'Description', 'employmentType' => 'Employment type', 'jobLocation' => 'Location (town)',
            'baseSalary' => 'Salary from', 'baseSalaryMax' => 'Salary to', 'salaryUnit' => 'Salary unit (per month / per hour)']],
        // a branch or store: the geo comes from a location field, the hours from a text written like the company hours
        'LocalBusiness' => ['Local business (branch, store)', ['address' => 'Address', 'telephone' => 'Phone', 'email' => 'E-mail', 'geo' => 'Location (latitude, longitude)', 'openingHours' => 'Opening hours']],
    ];

    /** schema.org employment types by words in the text of the field (Czech, Slovak, German, Polish, French, Spanish, Italian, English), checked in this order. */
    private const array EMPLOYMENT_TYPES = [
        'INTERN' => '/intern|staz|praktik|stage|tirocin|practic/',
        'TEMPORARY' => '/tempor|brigad|docasn|befristet|zeitlich|tymczas|dorywcz|interim/',
        'CONTRACTOR' => '/contract|kontrakt|freelanc|zivnost|\bico\b|werkvertrag|selbstst|freiberuf|\bb2b\b|umowa|autonom/',
        'PART_TIME' => '/part|zkracen|castecn|polovic|skraten|teilzeit|niepeln|partiel|parcial|medio tiempo/',
        'FULL_TIME' => '/full|plny|\bhpp\b|vollzeit|pelny|plein|completo|pieno/',
    ];

    /** schema.org unitText of a salary by words in the text of the field; anything else is left out. */
    private const array SALARY_UNITS = [
        'HOUR' => '/hour|hodin|stund|godzin|heure|\bhora|\bora\b|orari/',
        'DAY' => '/\bday|\bden\b|denn|\btag\b|dzien|dniow|jour|\bdia\b|giorn/',
        'WEEK' => '/week|tyden|tydn|woche|tygod|semaine|semana|settiman/',
        'MONTH' => '/month|mesic|mesac|monat|miesi|mois|\bmes\b|mensual|mese\b|mensil/',
        'YEAR' => '/year|\brok\b|rocn|jahr|rocz|\ban\b|annee|annuel|\bano\b|anual|anno|annu|\bp\.\s?a\./',
    ];

    /**
     * The setting from a form or from Claude, checked against the collection's fields; null = no structured data.
     *
     * @param list<array{klic: string, popisek: string, typ: string}> $fields
     * @return array{typ: string, pole: array<string, string>, mena: string}|null
     */
    public static function sanitize(mixed $input, array $fields): ?array
    {
        $type = is_array($input) && is_string($input['typ'] ?? null) ? $input['typ'] : '';
        if (!isset(self::TYPES[$type])) {
            return null;
        }
        $keys = array_column($fields, 'klic');
        $map = [];
        foreach (self::TYPES[$type][1] as $property => $_) {
            $key = $input['pole'][$property] ?? '';
            if (is_string($key) && in_array($key, $keys, true)) {
                $map[$property] = $key;
            }
        }
        $currency = is_string($input['mena'] ?? null) ? strtoupper(trim($input['mena'])) : '';

        return ['typ' => $type, 'pole' => $map, 'mena' => preg_match('/^[A-Z]{3}$/D', $currency) ? $currency : ''];
    }

    /** @return array{typ: string, pole: array<string, string>, mena: string}|null the stored setting of a collection row */
    public static function of(array $collection): ?array
    {
        $stored = json_decode((string) ($collection['schema_org'] ?? ''), true);

        return is_array($stored) ? self::sanitize($stored, (array) ($collection['pole'] ?? [])) : null;
    }

    /**
     * JSON-LD node of one item page, or null when the collection has no type.
     *
     * @param array<string, mixed> $collection with decoded "pole"
     * @param array<string, mixed> $item with decoded "data" (the whole row: a job posting reads datum and valid_until)
     * @param string $issuerId @id of the company node (the provider of a service, the organiser of an event)
     * @param array<string, mixed> $issuer the company node itself (Front\Company::schema) – the hiring organization of a job posting
     * @param string $language language of the item (the day names of a branch's opening hours)
     */
    public static function forItem(array $collection, array $item, string $url, string $description, string $image, string $issuerId, array $issuer = [], string $language = ''): ?array
    {
        $schema = self::of($collection);
        if ($schema === null) {
            return null;
        }
        $value = function (string $property) use ($schema, $item): string {
            $key = $schema['pole'][$property] ?? '';
            $v = $key === '' ? '' : (string) ($item['data'][$key] ?? '');

            return trim(html_entity_decode(strip_tags($v), ENT_QUOTES | ENT_HTML5));
        };
        $name = (string) $item['nazev'];
        if ($schema['typ'] === 'JobPosting') {
            return self::jobPosting($schema, $item, $value, $url, $description, $image, $issuer);
        }
        if ($schema['typ'] === 'FAQPage') {
            $answer = $value('answer');

            return $answer === '' ? null : ['@type' => 'FAQPage', 'url' => $url, 'mainEntity' => [
                ['@type' => 'Question', 'name' => $name, 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $answer]],
            ]];
        }
        if ($schema['typ'] === 'Event' && self::date($value('startDate')) === '') {
            return null; // an event without a start is not an event for search engines
        }
        $price = str_replace([' ', ','], ['', '.'], $value('price'));
        $offer = is_numeric($price) && $schema['mena'] !== '' ? ['@type' => 'Offer', 'price' => $price, 'priceCurrency' => $schema['mena'], 'url' => $url] : null;
        $node = ['@type' => $schema['typ'], 'name' => $name, 'url' => $url, 'description' => $description, 'image' => $image];

        return array_filter($node + match ($schema['typ']) {
            'Service' => ['serviceType' => $value('serviceType'), 'areaServed' => $value('areaServed'), 'provider' => ['@id' => $issuerId], 'offers' => $offer],
            'Person' => ['jobTitle' => $value('jobTitle'), 'email' => $value('email'), 'telephone' => $value('telephone'),
                'sameAs' => preg_match('#^https?://#', $value('sameAs')) ? $value('sameAs') : '', 'worksFor' => ['@id' => $issuerId]],
            'Product' => ['brand' => $value('brand') !== '' ? ['@type' => 'Brand', 'name' => $value('brand')] : null, 'sku' => $value('sku'), 'offers' => $offer],
            'Event' => ['startDate' => self::date($value('startDate')), 'endDate' => self::date($value('endDate')), 'eventStatus' => 'https://schema.org/EventScheduled'] + self::eventPlace($value('location'), $value('address'), $value('online'))
                + ['organizer' => ['@id' => $issuerId], 'offers' => $offer],
            // the branch belongs to the company; hours that do not parse are left out rather than guessed
            'LocalBusiness' => ['address' => $value('address'), 'telephone' => $value('telephone'), 'email' => $value('email'), 'geo' => self::geo($value('geo')),
                'openingHoursSpecification' => \Kaleta\Core\Hours::specification($value('openingHours'), $language) ?: null, 'parentOrganization' => ['@id' => $issuerId]],
        }, fn (mixed $v): bool => $v !== '' && $v !== null);
    }

    /**
     * Where an event happens (2.11): a Place by its name and address, a VirtualLocation by its link, or both – with the
     * attendance mode search engines expect. Nothing known = nothing said.
     *
     * @return array<string, mixed>
     */
    private static function eventPlace(string $name, string $address, string $online): array
    {
        $place = $name !== '' || $address !== '' ? ['@type' => 'Place', 'name' => $name !== '' ? $name : $address, 'address' => $address !== '' ? $address : $name] : null;
        $virtual = preg_match('#^https://#', $online) === 1 ? ['@type' => 'VirtualLocation', 'url' => $online] : null;
        if ($place === null && $virtual === null) {
            return [];
        }

        return [
            'eventAttendanceMode' => 'https://schema.org/' . ($place !== null && $virtual !== null ? 'Mixed' : ($virtual !== null ? 'Online' : 'Offline')) . 'EventAttendanceMode',
            'location' => $place !== null && $virtual !== null ? [$place, $virtual] : ($place ?? $virtual),
        ];
    }

    /**
     * A job posting (2.11) as Google reads it: title, datePosted, validThrough (the item's "true until" – without it search
     * engines cannot tell an open job from an expired one, so the site audit asks for it), the hiring organization from
     * Business details, the place from the location field and the company country, the salary as a MonetaryAmount with the
     * collection currency. Whatever is missing is left out – nothing is guessed.
     *
     * @param array{typ: string, pole: array<string, string>, mena: string} $schema
     * @param array<string, mixed> $item the item row
     * @param callable(string): string $value plain text of the field mapped to a property
     * @param array<string, mixed> $issuer the company node (Front\Company::schema)
     * @return array<string, mixed>
     */
    private static function jobPosting(array $schema, array $item, callable $value, string $url, string $description, string $image, array $issuer): array
    {
        $organization = array_filter(['@type' => 'Organization', 'name' => (string) ($issuer['legalName'] ?? $issuer['name'] ?? ''), 'sameAs' => (string) ($issuer['url'] ?? ''), 'logo' => (string) ($issuer['logo'] ?? '')]);
        $locality = $value('jobLocation');
        $country = (string) ($issuer['address']['addressCountry'] ?? '');
        $min = self::number($value('baseSalary'));
        $max = self::number($value('baseSalaryMax'));
        $salary = null;
        if (($min !== null || $max !== null) && $schema['mena'] !== '') {
            // one figure is a value, two are a range; the unit only when the text says per month, per hour…
            $amount = $min !== null && $max !== null && $min !== $max ? ['minValue' => $min, 'maxValue' => $max] : ['value' => $min ?? $max];
            $salary = ['@type' => 'MonetaryAmount', 'currency' => $schema['mena'], 'value' => ['@type' => 'QuantitativeValue'] + $amount + array_filter(['unitText' => self::salaryUnit($value('salaryUnit'))])];
        }

        return array_filter([
            '@type' => 'JobPosting',
            'title' => (string) $item['nazev'],
            'url' => $url,
            'description' => $value('description') !== '' ? $value('description') : $description,
            'image' => $image,
            'datePosted' => self::date((string) ($item['datum'] ?? '')),
            'validThrough' => self::date((string) ($item['valid_until'] ?? '')),
            'employmentType' => self::employmentType($value('employmentType')),
            'hiringOrganization' => isset($organization['name']) ? $organization : null,
            'jobLocation' => $locality !== '' ? ['@type' => 'Place', 'address' => array_filter(['@type' => 'PostalAddress', 'addressLocality' => $locality, 'addressCountry' => $country])] : null,
            'baseSalary' => $salary,
        ], fn (mixed $v): bool => $v !== '' && $v !== null);
    }

    /** The schema.org employment type when the field text is recognisable, otherwise the text itself (schema.org allows Text); '' when empty. */
    public static function employmentType(string $text): string
    {
        $normalized = mb_strtolower(remove_diacritics(trim($text)));
        foreach (self::EMPLOYMENT_TYPES as $type => $pattern) {
            if ($normalized !== '' && preg_match($pattern, $normalized) === 1) {
                return $type;
            }
        }

        return trim($text);
    }

    /** MONTH, HOUR, DAY, WEEK or YEAR when the unit text says so; '' when it cannot be told (then no unit is published). */
    public static function salaryUnit(string $text): string
    {
        $normalized = mb_strtolower(remove_diacritics(trim($text)));
        foreach (self::SALARY_UNITS as $unit => $pattern) {
            if ($normalized !== '' && preg_match($pattern, $normalized) === 1) {
                return $unit;
            }
        }

        return '';
    }

    /** A number field ("35 000", "35000,50") as a float for schema.org; null when empty or not a number. */
    private static function number(string $v): ?float
    {
        $v = str_replace([' ', "\u{a0}", ','], ['', '', '.'], $v);

        return is_numeric($v) ? (float) $v : null;
    }

    /**
     * GeoCoordinates from a location field ("latitude, longitude", Collections::cleanLocation); null when it is empty or not a location.
     *
     * @return array{'@type': string, latitude: float, longitude: float}|null
     */
    public static function geo(string $location): ?array
    {
        $clean = Collections::cleanLocation($location);
        if ($clean === null || $clean === '') {
            return null;
        }
        [$lat, $lng] = explode(', ', $clean);

        return ['@type' => 'GeoCoordinates', 'latitude' => (float) $lat, 'longitude' => (float) $lng];
    }

    /** A date field (YYYY-MM-DD) or a full date and time for schema.org; anything else is left out. */
    private static function date(string $v): string
    {
        $time = preg_match('/^\d{4}-\d{2}-\d{2}/', $v) ? strtotime($v) : false;

        return $time === false ? '' : (strlen($v) === 10 ? $v : date('c', $time));
    }
}
