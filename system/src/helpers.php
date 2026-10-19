<?php
/**
 * Global helper functions for templates and layouts.
 */

declare(strict_types=1);

/** Escaping output into HTML. */
function e(string|int|float|null $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Stored HTML (a news intro, a description) as one line of plain text for a meta description, structured data, feeds or
 * llms.txt: block ends become spaces, entities characters (&nbsp; from WordPress or a site import), whitespace one space;
 * $width > 0 shortens it with an ellipsis. The result is text, not HTML - escape it on output like any other text.
 */
function plain_text(string $html, int $width = 0): string
{
    $text = strip_tags((string) preg_replace('#</(p|h[1-6]|li|blockquote|figcaption|div)>|<br\s*/?>#i', ' ', $html));
    $text = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')));

    return $width > 0 ? mb_strimwidth($text, 0, $width, '…') : $text;
}

/**
 * A value people typed (a name, an e-mail, a title) printed inside the administration's left-to-right text: escaped and
 * isolated in <bdi>, so an Arabic name keeps its order and does not pull the punctuation around it (looks the same for Latin text).
 */
function bdi(string|int|float|null $value): string
{
    return '<bdi>' . e($value) . '</bdi>';
}

/** Translation of a template text into the site language: t('Read more'), t('Page %s of %s', 2, 5). See Core\Language. */
function t(string $text, string|int ...$values): string
{
    return Kaleta\Core\Language::t($text, ...$values);
}

/** Text without diacritics (for URLs and search): "Příliš žluťoučký" -> "Prilis zlutoucky". */
function remove_diacritics(string $text): string
{
    static $mapping = ['á' => 'a', 'ä' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'å' => 'a', 'č' => 'c', 'ć' => 'c', 'ç' => 'c', 'ď' => 'd', 'é' => 'e', 'ě' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ľ' => 'l', 'ĺ' => 'l', 'ň' => 'n', 'ń' => 'n', 'ñ' => 'n', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'ò' => 'o', 'õ' => 'o', 'ő' => 'o', 'ř' => 'r', 'ŕ' => 'r', 'š' => 's', 'ś' => 's', 'ť' => 't', 'ú' => 'u', 'ů' => 'u', 'ü' => 'u', 'ù' => 'u', 'û' => 'u', 'ű' => 'u', 'ý' => 'y', 'ÿ' => 'y', 'ž' => 'z', 'ź' => 'z', 'ż' => 'z', 'Á' => 'A', 'Ä' => 'A', 'À' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Å' => 'A', 'Č' => 'C', 'Ć' => 'C', 'Ç' => 'C', 'Ď' => 'D', 'É' => 'E', 'Ě' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E', 'Í' => 'I', 'Ì' => 'I', 'Î' => 'I', 'Ï' => 'I', 'Ľ' => 'L', 'Ĺ' => 'L', 'Ň' => 'N', 'Ń' => 'N', 'Ñ' => 'N', 'Ó' => 'O', 'Ô' => 'O', 'Ö' => 'O', 'Ò' => 'O', 'Õ' => 'O', 'Ő' => 'O', 'Ř' => 'R', 'Ŕ' => 'R', 'Š' => 'S', 'Ś' => 'S', 'Ť' => 'T', 'Ú' => 'U', 'Ů' => 'U', 'Ü' => 'U', 'Ù' => 'U', 'Û' => 'U', 'Ű' => 'U', 'Ý' => 'Y', 'Ÿ' => 'Y', 'Ž' => 'Z', 'Ź' => 'Z', 'Ż' => 'Z', 'ł' => 'l', 'Ł' => 'L', 'ß' => 'ss', 'đ' => 'd', 'Đ' => 'D', 'ø' => 'o', 'Ø' => 'O', 'æ' => 'ae', 'Æ' => 'AE'];

    return strtr($text, $mapping);
}

/**
 * Converting text to URL form: "Příliš žluťoučký kůň" -> "prilis-zlutoucky-kun". Arabic (and Persian) letters get a plain
 * Latin transliteration without vowels, "شريط لاصق" -> "shryt-lasq"; a title with nothing to transliterate gives "n-a"
 * (a new page, news item, category or item then takes its type and number, Core\Slug::fromId).
 */
function slugify(string $text, int $maxLength = 120): string
{
    $map = [
        'á' => 'a', 'ä' => 'a', 'č' => 'c', 'ď' => 'd', 'é' => 'e', 'ě' => 'e', 'í' => 'i',
        'ľ' => 'l', 'ĺ' => 'l', 'ň' => 'n', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'ŕ' => 'r',
        'ř' => 'r', 'š' => 's', 'ť' => 't', 'ú' => 'u', 'ů' => 'u', 'ü' => 'u', 'ý' => 'y',
        'ž' => 'z', 'ß' => 'ss',
    ];
    $text = strtr(mb_strtolower($text), $map);
    if (preg_match('/[\x{0600}-\x{06FF}]/u', $text) === 1) {
        $text = strtr((string) preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $text), [
            'ا' => 'a', 'أ' => 'a', 'إ' => 'i', 'آ' => 'a', 'ٱ' => 'a', 'ء' => '', 'ؤ' => 'u', 'ئ' => 'y', 'ب' => 'b', 'ت' => 't', 'ث' => 'th',
            'ج' => 'j', 'ح' => 'h', 'خ' => 'kh', 'د' => 'd', 'ذ' => 'dh', 'ر' => 'r', 'ز' => 'z', 'س' => 's', 'ش' => 'sh', 'ص' => 's',
            'ض' => 'd', 'ط' => 't', 'ظ' => 'z', 'ع' => '', 'غ' => 'gh', 'ف' => 'f', 'ق' => 'q', 'ك' => 'k', 'ل' => 'l', 'م' => 'm',
            'ن' => 'n', 'ه' => 'h', 'ة' => 'a', 'و' => 'w', 'ي' => 'y', 'ى' => 'a', 'پ' => 'p', 'چ' => 'ch', 'ژ' => 'zh', 'گ' => 'g',
            'ک' => 'k', 'ی' => 'y', '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7',
            '٨' => '8', '٩' => '9', '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]);
    }
    $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? '';
    $text = trim(substr($text, 0, $maxLength), '-');

    return $text !== '' ? $text : 'n-a';
}

/** Decimal number in the site language: 4,5 in Czech, Slovak and German, 4.5 in English and Arabic (Western digits). */
function format_number(float|int $number, int $decimals = 1): string
{
    return number_format((float) $number, $decimals, in_array(Kaleta\Core\Language::base(Kaleta\Core\Language::code()), ['en', 'ar', 'he'], true) ? '.' : ',', '');
}

/** Count with a thousands separator in the site language: 12 345 in Czech and Slovak, 12,345 in English and Arabic, 12.345 in German. */
function format_count(float|int $number, int $decimals = 0): string
{
    [$decimalSeparator, $thousandsSeparator] = match (Kaleta\Core\Language::base(Kaleta\Core\Language::code())) {
        'en', 'ar', 'he' => ['.', ','],
        'de' => [',', '.'],
        default => [',', "\u{00A0}"],
    };

    return number_format((float) $number, $decimals, $decimalSeparator, $thousandsSeparator);
}

/** Czech date: 18. 9. 2026, optionally with the time. */
function format_date(string|\DateTimeInterface|null $value, bool $withTime = false): string
{
    if ($value === null || $value === '') {
        return '';
    }
    $dt = $value instanceof \DateTimeInterface ? $value : new \DateTimeImmutable($value);

    // date form by language: the dictionary may provide the key "datum_format" (a format for date()), e.g. "j M Y" for English
    $format = t('datum_format');
    $format = $format === 'datum_format' ? 'j. n. Y' : $format;

    return $dt->format($withTime ? $format . ' H:i' : $format);
}

/** Date in words: "pátek 18. září 2026". */
function format_date_long(string|\DateTimeInterface|null $value = null): string
{
    $days = ['neděle', 'pondělí', 'úterý', 'středa', 'čtvrtek', 'pátek', 'sobota'];
    $months = [1 => 'ledna', 'února', 'března', 'dubna', 'května', 'června', 'července', 'srpna', 'září', 'října', 'listopadu', 'prosince'];
    $dt = $value instanceof \DateTimeInterface ? $value : new \DateTimeImmutable($value ?? 'now');
    // a language without its own dictionary: date in words by locale from the intl extension ("Freitag, 25. September 2026")
    if (($locale = \Kaleta\Core\Language::intlLocale()) !== null) {
        $text = (new \IntlDateFormatter($locale, \IntlDateFormatter::FULL, \IntlDateFormatter::NONE, $dt->getTimezone()))->format($dt);
        if (is_string($text) && $text !== '') {
            return $text;
        }
    }
    // the language dictionary may provide its own date form: the key "datum_slovy" = a format for date(), e.g. "l j F Y"
    $format = t('datum_slovy');
    if ($format !== 'datum_slovy') {
        return preg_replace_callback('/[A-Za-zÀ-ž]{3,}/u', fn (array $m): string => t($m[0]), $dt->format($format)) ?? $dt->format($format);
    }

    return t($days[(int) $dt->format('w')]) . ' ' . $dt->format('j') . '. ' . t($months[(int) $dt->format('n')]) . ' ' . $dt->format('Y');
}

/**
 * The response headers of the last HTTP request made with file_get_contents() (3.7, PHP 8.3): PHP 8.4 keeps them for
 * http_get_last_response_headers(), PHP 8.3 only in the calling scope's $http_response_header, which the caller passes:
 * last_response_headers($http_response_header ?? null). On 8.4+ the argument is not used.
 *
 * @param array<int, string>|null $fromScope
 * @return array<int, string>
 */
function last_response_headers(?array $fromScope): array
{
    return (function_exists('http_get_last_response_headers') ? http_get_last_response_headers() : $fromScope) ?? [];
}
