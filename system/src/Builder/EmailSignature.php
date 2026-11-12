<?php

declare(strict_types=1);

namespace Kaleta\Builder;

use Kaleta\Core\App;

/**
 * E-mail signatures from people records (2.10). Every person in a people collection – the ready-made Team
 * (system/presets/people.php), a collection with the schema.org type Person, or any collection with a photo and a phone or
 * an e-mail – gets a signature in the brand look, generated from the record: when the record changes, copying the
 * signature again makes it current. The fields are found by their type and by words in their key or label, so the
 * preset works in every admin language and so do user-made collections.
 *
 * The output is what mail clients can show: one table with inline styles only (Outlook, Gmail and Apple Mail ignore
 * most of CSS), absolute image URLs, at most 600 px wide, plus a plain-text version. The colours of the text are fixed
 * dark greys – a signature sits in a white e-mail body, not on the site's background – and only the brand colour, the
 * fonts and the logo come from the design system. The absence ("On leave") and About fields never get in: a
 * signature says who the person is, not where they are this week.
 */
final class EmailSignature
{
    /** Words in a field's key or label (lowercase, without diacritics) that make it the person's role. */
    private const array ROLE_WORDS = ['role', 'rolle', 'rollen', 'funkce', 'pozice', 'position', 'funktion', 'jobtitle', 'profese', 'beruf'];

    public const int PHOTO_SIZE = 72;

    /**
     * Which fields of a collection a signature uses: the photo is the first image field, the role, the phone and the
     * e-mail come from the schema.org Person mapping of the collection first and then from the key or label of a short
     * text field. A field that is not there is null.
     *
     * @param array<string, mixed> $collection with decoded "pole" (and "schema_org" when it has one)
     * @return array{photo: ?string, role: ?string, phone: ?string, email: ?string}
     */
    public static function fields(array $collection): array
    {
        $found = ['photo' => null, 'role' => null, 'phone' => null, 'email' => null];
        $schema = CollectionSchema::of($collection);
        if ($schema !== null && $schema['typ'] === 'Person') {
            foreach (['role' => 'jobTitle', 'phone' => 'telephone', 'email' => 'email'] as $what => $property) {
                $found[$what] = $schema['pole'][$property] ?? null;
            }
        }
        foreach (is_array($collection['pole'] ?? null) ? $collection['pole'] : [] as $p) {
            $key = (string) ($p['klic'] ?? '');
            $type = (string) ($p['typ'] ?? 'text');
            if ($key === '' || in_array($key, $found, true)) {
                continue;
            }
            if ($type === 'obrazek') {
                $found['photo'] ??= $key;
                continue;
            }
            if ($type !== 'text') {
                continue; // a role, a phone or an address is a short text
            }
            $words = [...self::words($key), ...self::words((string) ($p['popisek'] ?? ''))];
            foreach (['role', 'phone', 'email'] as $what) {
                if ($found[$what] === null && self::names($what, $words)) {
                    $found[$what] = $key;
                    break;
                }
            }
        }

        return $found;
    }

    /** Does the collection hold people? The schema.org type Person, or a photo together with a phone or an e-mail. */
    public static function isPeople(array $collection): bool
    {
        $schema = CollectionSchema::of($collection);
        if ($schema !== null && $schema['typ'] === 'Person') {
            return true;
        }
        $fields = self::fields($collection);

        return $fields['photo'] !== null && ($fields['phone'] !== null || $fields['email'] !== null);
    }

    /**
     * The signature of one person with the site's data: name, website, brand colour, fonts and logo from the settings
     * and the design system.
     *
     * @param array<string, mixed> $collection with decoded "pole"
     * @param array<string, mixed> $item with decoded "data"
     * @return array{html: string, text: string}
     */
    public static function forItem(App $app, array $collection, array $item): array
    {
        return self::render($collection, $item, self::site($app));
    }

    /**
     * The site's data a signature uses (3.37: shared with the fallback signature of Builder\UserSignature): the company,
     * the website, the company phone and address, the brand colour, the fonts and a raster logo.
     *
     * @return array{name: string, url: string, base: string, phone: string, address: string, color: string, text_font: string, heading_font: string, logo: string}
     */
    public static function site(App $app): array
    {
        $s = $app->settings();
        $base = rtrim($s->get('site_url') ?: $app->request->origin(), '/') . $app->request->basePath();
        $ds = DesignSystem::load($s);
        $logo = $s->get('logo');

        return [
            'name' => $s->get('site_name'),
            'url' => $base . '/',
            'base' => $base,
            'phone' => $s->get('company_phone'),
            'address' => implode(', ', array_filter([trim($s->get('company_street')), trim($s->get('company_postcode') . ' ' . $s->get('company_city'))])),
            'color' => $ds['barvy']['primarni'],
            'text_font' => DesignSystem::fontFamily($ds, (string) $ds['pismo_text'], false),
            'heading_font' => DesignSystem::fontFamily($ds, (string) $ds['pismo_titulky'], true),
            // mail clients do not show SVG – a vector logo is left out and the site name stands in its place
            'logo' => preg_match('/\.(png|jpe?g|gif|webp)$/i', $logo) ? self::absolute($logo, $base) : '',
        ];
    }

    /**
     * Renders the signature from the record and the site data. Everything from the record and the settings is escaped.
     *
     * @param array<string, mixed> $collection with decoded "pole"
     * @param array<string, mixed> $item with decoded "data"
     * @param array{name: string, url: string, base: string, phone: string, address: string, color: string, text_font: string, heading_font: string, logo: string} $site
     *   url = the website (absolute, for the link), base = absolute prefix of media paths, phone = the company phone when
     *   the person has none, address = one line or empty, logo = absolute URL of a raster logo or empty
     * @return array{html: string, text: string}
     */
    public static function render(array $collection, array $item, array $site): array
    {
        $keys = self::fields($collection);
        $data = is_array($item['data'] ?? null) ? $item['data'] : [];
        $value = fn (?string $key): string => $key === null ? '' : trim(strip_tags((string) ($data[$key] ?? '')));
        $name = trim((string) ($item['nazev'] ?? ''));
        $role = $value($keys['role']);
        $phone = $value($keys['phone']) !== '' ? $value($keys['phone']) : trim($site['phone']);
        $email = filter_var($value($keys['email']), FILTER_VALIDATE_EMAIL) !== false ? $value($keys['email']) : '';
        $photo = self::absolute($value($keys['photo']), $site['base']);
        $url = preg_match('#^https?://[^\s"<>]+$#D', $site['url']) ? $site['url'] : '';
        $host = (string) preg_replace('#^https?://|/$#', '', $url);
        $address = trim($site['address']);
        $company = trim($site['name']);

        $color = preg_match('/^#[0-9a-f]{6}$/iD', $site['color']) ? strtolower($site['color']) : '#121212';
        $fallback = 'system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif';
        $font = e($site['text_font'] !== '' ? $site['text_font'] : $fallback);
        $headingFont = e($site['heading_font'] !== '' ? $site['heading_font'] : $fallback);
        $dark = '#1f2328';
        $muted = '#5b6170';
        $line = fn (string $content, string $style = ''): string => '<tr><td style="padding:0 0 4px 0;font-family:' . $font . ';font-size:14px;line-height:1.5;color:' . $dark . ';' . $style . '">' . $content . '</td></tr>';

        $rows = [$line(e($name), 'font-family:' . $headingFont . ';font-size:17px;line-height:1.3;font-weight:700;')];
        if ($role !== '') {
            $rows[] = $line(e($role), 'color:' . $muted . ';');
        }
        $contact = [];
        if ($phone !== '') {
            $contact[] = '<a href="tel:' . e((string) preg_replace('/[^+\d]/', '', $phone)) . '" style="color:' . $dark . ';text-decoration:none;">' . e($phone) . '</a>';
        }
        if ($email !== '') {
            $contact[] = '<a href="mailto:' . e($email) . '" style="color:' . $color . ';text-decoration:none;">' . e($email) . '</a>';
        }
        if ($contact !== []) {
            $rows[] = $line(implode(' &nbsp;·&nbsp; ', $contact), 'padding-top:6px;');
        }
        if ($site['logo'] !== '') {
            $logo = '<img src="' . e($site['logo']) . '" alt="' . e($company) . '" height="28" style="display:block;height:28px;width:auto;border:0;">';
            $rows[] = $line($url !== '' ? '<a href="' . e($url) . '" style="text-decoration:none;">' . $logo . '</a>' : $logo, 'padding-top:8px;');
        }
        $companyParts = [];
        if ($company !== '') {
            $companyParts[] = $url !== '' ? '<a href="' . e($url) . '" style="color:' . $color . ';font-weight:700;text-decoration:none;">' . e($company) . '</a>' : '<strong style="color:' . $color . ';">' . e($company) . '</strong>';
        }
        if ($host !== '') {
            $companyParts[] = '<a href="' . e($url) . '" style="color:' . $muted . ';text-decoration:none;">' . e($host) . '</a>';
        }
        if ($companyParts !== []) {
            $rows[] = $line(implode(' &nbsp;·&nbsp; ', $companyParts), $site['logo'] !== '' ? '' : 'padding-top:8px;');
        }
        if ($address !== '') {
            $rows[] = $line(e($address), 'color:' . $muted . ';font-size:13px;');
        }

        $size = self::PHOTO_SIZE;
        $html = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;max-width:600px;"><tr>';
        if ($photo !== '') {
            $html .= '<td style="padding:0 16px 0 0;vertical-align:top;"><img src="' . e($photo) . '" width="' . $size . '" height="' . $size . '" alt="' . e($name) . '" style="display:block;width:' . $size . 'px;height:' . $size . 'px;border-radius:' . intdiv($size, 2) . 'px;border:0;"></td>';
        }
        $html .= '<td style="padding:0 0 0 14px;vertical-align:top;border-left:3px solid ' . $color . ';">'
            . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;">' . implode('', $rows) . '</table>'
            . '</td></tr></table>';

        $text = array_filter([
            $name,
            $role,
            implode(' · ', array_filter([$phone, $email])),
            implode(' · ', array_filter([$company, $host])),
            $address,
        ], fn (string $row): bool => $row !== '');

        return ['html' => $html, 'text' => implode("\n", $text)];
    }

    /** An absolute URL of an image from the record or the settings: a site path gets the site's address in front. */
    public static function absolute(string $path, string $base): string
    {
        if ($path === '') {
            return '';
        }

        return preg_match('~^https?://~', $path) ? $path : rtrim($base, '/') . '/' . ltrim($path, '/');
    }

    /** @return list<string> lowercase ASCII words of a key or label, and all of them joined ("job_title" also as "jobtitle") */
    private static function words(string $text): array
    {
        $ascii = str_replace('-', '_', slugify($text, 80));
        $words = array_values(array_filter(explode('_', $ascii), fn (string $w): bool => $w !== ''));

        return [...$words, str_replace('_', '', $ascii)];
    }

    /** @param list<string> $words */
    private static function names(string $what, array $words): bool
    {
        foreach ($words as $w) {
            $hit = match ($what) {
                'role' => in_array($w, self::ROLE_WORDS, true),
                'phone' => str_starts_with($w, 'tel') || str_starts_with($w, 'mobil') || str_contains($w, 'phone') || $w === 'handy',
                default => $w === 'mail' || str_contains($w, 'email'),
            };
            if ($hit) {
                return true;
            }
        }

        return false;
    }
}
