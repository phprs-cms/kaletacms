<?php

declare(strict_types=1);

namespace Kaleta\Builder;

use Kaleta\Front\SiteIdentity;

/**
 * Which writing systems the fonts of the design system have letters for (3.12). A site with an Arabic, Hebrew, Cyrillic or
 * Greek language version whose heading or text font has no letters of that script shows them in whatever font the
 * visitor's device falls back to – readable, but not the chosen design. Site appearance and the site audit warn and
 * suggest fonts that have them.
 *
 * The fonts Kaleta offers are system font stacks (Front\SiteIdentity) and the site's own WOFF2 files; neither comes with
 * subset data, so this is a small static map: the scripts the fonts named first in each offered stack cover, and the
 * scripts of well-known families a custom font may be (matched by the name the administrator gave it). A custom font of
 * a family not in the map is reported as unknown – Kaleta cannot read the file's character map (WOFF2 needs Brotli).
 */
final class FontScripts
{
    /** Language (the base code, without the region) => its script; languages written in Latin are not listed. */
    public const array LANGUAGES = [
        'ar' => 'arabic', 'fa' => 'arabic', 'ur' => 'arabic', 'he' => 'hebrew', 'yi' => 'hebrew',
        'bg' => 'cyrillic', 'mk' => 'cyrillic', 'ru' => 'cyrillic', 'sr' => 'cyrillic', 'uk' => 'cyrillic', 'el' => 'greek',
    ];

    /**
     * The scripts each offered stack covers besides Latin, by the fonts named before the generic fallback: the device's
     * system font (system-ui) has every script on every current system; Georgia, Helvetica and Arial, Menlo and Consolas,
     * Cambria have Cyrillic and Greek; Bodoni and the rounded faces have Latin only.
     */
    public const array STACKS = [
        'vychozi' => ['arabic', 'hebrew', 'cyrillic', 'greek'],
        'moderni' => ['arabic', 'hebrew', 'cyrillic', 'greek'],
        'grotesk' => ['cyrillic', 'greek'],
        'klasicke' => ['cyrillic', 'greek'],
        'patkove' => ['cyrillic', 'greek'],
        'knizni' => ['cyrillic', 'greek'],
        'strojove' => ['cyrillic', 'greek'],
        'elegantni' => [],
        'zaoblene' => [],
        'arabske' => ['arabic'],
    ];

    /** Families a custom font may be (lower case) => the scripts they cover besides Latin (their Google Fonts subsets). */
    public const array FAMILIES = [
        'noto sans arabic' => ['arabic'], 'noto naskh arabic' => ['arabic'], 'noto kufi arabic' => ['arabic'], 'ibm plex sans arabic' => ['arabic'],
        'cairo' => ['arabic'], 'tajawal' => ['arabic'], 'almarai' => ['arabic'], 'amiri' => ['arabic'], 'readex pro' => ['arabic'],
        'vazirmatn' => ['arabic'], 'changa' => ['arabic'], 'alexandria' => ['arabic'], 'el messiri' => ['arabic', 'cyrillic'],
        'noto sans hebrew' => ['hebrew'], 'heebo' => ['hebrew'], 'assistant' => ['hebrew'], 'frank ruhl libre' => ['hebrew'],
        'open sans' => ['hebrew', 'cyrillic', 'greek'], 'noto sans' => ['cyrillic', 'greek'], 'noto serif' => ['cyrillic', 'greek'],
        'inter' => ['cyrillic', 'greek'], 'roboto' => ['cyrillic', 'greek'], 'ibm plex sans' => ['cyrillic', 'greek'], 'source sans 3' => ['cyrillic', 'greek'],
        'pt sans' => ['cyrillic'], 'pt serif' => ['cyrillic'], 'montserrat' => ['cyrillic'], 'raleway' => ['cyrillic'], 'nunito' => ['cyrillic'],
        'playfair display' => ['cyrillic'], 'merriweather' => ['cyrillic'], 'lora' => ['cyrillic'],
        'lato' => [], 'poppins' => [], 'bricolage grotesque' => [], 'dm sans' => [], 'work sans' => [],
    ];

    /** Families to upload as a custom font (SIL Open Font License) for a script. */
    public const array SUGGESTED = [
        'arabic' => ['Noto Sans Arabic', 'IBM Plex Sans Arabic', 'Cairo', 'Tajawal'],
        'hebrew' => ['Noto Sans Hebrew', 'Heebo', 'Assistant'],
        'cyrillic' => ['Noto Sans', 'Inter', 'Roboto'],
        'greek' => ['Noto Sans', 'Inter', 'Roboto'],
    ];

    /**
     * The script of a language code (en-gb → en, sr → cyrillic); null = written in Latin or not checked.
     */
    public static function script(string $language): ?string
    {
        return self::LANGUAGES[strtolower(explode('-', $language)[0])] ?? null;
    }

    /**
     * The scripts a chosen font covers besides Latin; null = a custom font of a family the map does not know.
     *
     * @param array<string, mixed> $ds the design system (DesignSystem::load)
     * @return list<string>|null
     */
    public static function covered(array $ds, string $key): ?array
    {
        if (preg_match('/^vlastni-([1-3])$/D', $key, $m) === 1) {
            $name = mb_strtolower(trim((string) ($ds['vlastni_pisma'][(int) $m[1] - 1]['nazev'] ?? '')));
            $best = null;
            foreach (self::FAMILIES as $family => $scripts) {
                // the family name itself or followed by a word (Inter Variable, Cairo Bold) – the longest match wins
                if (($name === $family || str_starts_with($name, $family . ' ')) && ($best === null || strlen($family) > strlen($best))) {
                    $best = $family;
                }
            }

            return $best === null ? null : self::FAMILIES[$best];
        }

        return self::STACKS[$key] ?? [];
    }

    /**
     * What is missing: for each language version in a script the heading or text font lacks, one row.
     *
     * @param array<string, mixed> $ds the design system (DesignSystem::load)
     * @param list<string> $languages the site's languages (the default one and the additional ones)
     * @return list<array{role: string, font: string, script: string, languages: list<string>, known: bool, offered: list<string>, upload: list<string>}>
     *         role = headings | text; font = the name shown in Site appearance; offered = keys of offered fonts that have
     *         the script (for this role); upload = families to upload as a custom font
     */
    public static function problems(array $ds, array $languages): array
    {
        $byScript = [];
        foreach (array_unique($languages) as $language) {
            $script = self::script((string) $language);
            if ($script !== null) {
                $byScript[$script][] = (string) $language;
            }
        }
        $out = [];
        foreach (['headings' => ['pismo_titulky', SiteIdentity::TITLE_FONTS], 'text' => ['pismo_text', SiteIdentity::TEXT_FONTS]] as $role => [$field, $offered]) {
            $key = (string) ($ds[$field] ?? 'vychozi');
            $covered = self::covered($ds, $key);
            foreach ($byScript as $script => $codes) {
                if ($covered !== null && in_array($script, $covered, true)) {
                    continue;
                }
                $out[] = ['role' => $role, 'font' => self::fontName($ds, $key, $offered), 'script' => $script, 'languages' => $codes, 'known' => $covered !== null,
                    'offered' => array_values(array_filter(array_keys($offered), fn (string $k): bool => $k !== 'vychozi' && in_array($script, self::STACKS[$k], true))),
                    'upload' => self::SUGGESTED[$script]];
            }
        }

        return $out;
    }

    /** The name of a chosen font as Site appearance shows it (an offered stack translated, a custom font by its name). @param array<string, array{0: string, 1: string, 2: string}> $offered */
    private static function fontName(array $ds, string $key, array $offered): string
    {
        if (preg_match('/^vlastni-([1-3])$/D', $key, $m) === 1) {
            return (string) ($ds['vlastni_pisma'][(int) $m[1] - 1]['nazev'] ?? $key);
        }

        return t($offered[$key][0] ?? $key);
    }

    /**
     * One problem in words, for Site appearance, the site audit and Claude.
     *
     * @param array{role: string, font: string, script: string, languages: list<string>, known: bool, offered: list<string>, upload: list<string>} $problem
     * @param array<string, array{0: string, 1: string, 2: string}> $offered the offered fonts of the role (for their names)
     */
    public static function message(array $problem, array $offered): string
    {
        $script = match ($problem['script']) {
            'arabic' => t('Arabic'), 'hebrew' => t('Hebrew'), 'cyrillic' => t('Cyrillic'), 'greek' => t('Greek'), default => $problem['script'],
        };
        $versions = implode(', ', array_map(fn (string $code): string => \Kaleta\Core\Language::AVAILABLE[$code][0] ?? $code, $problem['languages']));
        $text = match (true) {
            $problem['known'] && $problem['role'] === 'headings' => t('The heading font %s has no %s letters, so the %s version shows them in a fallback font of the visitor’s device.', $problem['font'], $script, $versions),
            $problem['known'] => t('The text font %s has no %s letters, so the %s version shows them in a fallback font of the visitor’s device.', $problem['font'], $script, $versions),
            $problem['role'] === 'headings' => t('Kaleta cannot tell whether the heading font %s has %s letters for the %s version – check the languages the font supports.', $problem['font'], $script, $versions),
            default => t('Kaleta cannot tell whether the text font %s has %s letters for the %s version – check the languages the font supports.', $problem['font'], $script, $versions),
        };

        return $text . ' ' . t('Fonts with them: %s; or upload one as a custom font (e.g. %s).', implode(', ', array_map(fn (string $k): string => t($offered[$k][0] ?? $k), $problem['offered'])), implode(', ', $problem['upload']));
    }
}
