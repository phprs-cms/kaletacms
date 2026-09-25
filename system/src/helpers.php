<?php
/**
 * Globální pomocné funkce pro šablony a layouty.
 */

declare(strict_types=1);

/** Ošetření výstupu do HTML. */
function e(string|int|float|null $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Překlad textu šablony do jazyka webu: t('Číst dál'), t('Strana %s z %s', 2, 5). Viz Core\Jazyk. */
function t(string $text, string|int ...$hodnoty): string
{
    return Kaleta\Core\Jazyk::t($text, ...$hodnoty);
}

/** Text bez háčků a čárek (pro adresy a hledání): "Příliš žluťoučký" -> "Prilis zlutoucky". */
function bez_diakritiky(string $text): string
{
    static $mapa = ['á' => 'a', 'ä' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'å' => 'a', 'č' => 'c', 'ć' => 'c', 'ç' => 'c', 'ď' => 'd', 'é' => 'e', 'ě' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ľ' => 'l', 'ĺ' => 'l', 'ň' => 'n', 'ń' => 'n', 'ñ' => 'n', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'ò' => 'o', 'õ' => 'o', 'ő' => 'o', 'ř' => 'r', 'ŕ' => 'r', 'š' => 's', 'ś' => 's', 'ť' => 't', 'ú' => 'u', 'ů' => 'u', 'ü' => 'u', 'ù' => 'u', 'û' => 'u', 'ű' => 'u', 'ý' => 'y', 'ÿ' => 'y', 'ž' => 'z', 'ź' => 'z', 'ż' => 'z', 'Á' => 'A', 'Ä' => 'A', 'À' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Å' => 'A', 'Č' => 'C', 'Ć' => 'C', 'Ç' => 'C', 'Ď' => 'D', 'É' => 'E', 'Ě' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E', 'Í' => 'I', 'Ì' => 'I', 'Î' => 'I', 'Ï' => 'I', 'Ľ' => 'L', 'Ĺ' => 'L', 'Ň' => 'N', 'Ń' => 'N', 'Ñ' => 'N', 'Ó' => 'O', 'Ô' => 'O', 'Ö' => 'O', 'Ò' => 'O', 'Õ' => 'O', 'Ő' => 'O', 'Ř' => 'R', 'Ŕ' => 'R', 'Š' => 'S', 'Ś' => 'S', 'Ť' => 'T', 'Ú' => 'U', 'Ů' => 'U', 'Ü' => 'U', 'Ù' => 'U', 'Û' => 'U', 'Ű' => 'U', 'Ý' => 'Y', 'Ÿ' => 'Y', 'Ž' => 'Z', 'Ź' => 'Z', 'Ż' => 'Z', 'ł' => 'l', 'Ł' => 'L', 'ß' => 'ss', 'đ' => 'd', 'Đ' => 'D', 'ø' => 'o', 'Ø' => 'O', 'æ' => 'ae', 'Æ' => 'AE'];

    return strtr($text, $mapa);
}

/** Převod textu na URL tvar: "Příliš žluťoučký kůň" -> "prilis-zlutoucky-kun". */
function slugify(string $text, int $maxLength = 120): string
{
    $map = [
        'á' => 'a', 'ä' => 'a', 'č' => 'c', 'ď' => 'd', 'é' => 'e', 'ě' => 'e', 'í' => 'i',
        'ľ' => 'l', 'ĺ' => 'l', 'ň' => 'n', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'ŕ' => 'r',
        'ř' => 'r', 'š' => 's', 'ť' => 't', 'ú' => 'u', 'ů' => 'u', 'ü' => 'u', 'ý' => 'y',
        'ž' => 'z', 'ß' => 'ss',
    ];
    $text = strtr(mb_strtolower($text), $map);
    $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? '';
    $text = trim(substr($text, 0, $maxLength), '-');

    return $text !== '' ? $text : 'n-a';
}

/** Desetinné číslo v jazyce webu: 4,5 česky, slovensky a německy, 4.5 anglicky. */
function cislo(float|int $cislo, int $desetinna = 1): string
{
    return number_format((float) $cislo, $desetinna, Kaleta\Core\Jazyk::kod() === 'en' ? '.' : ',', '');
}

/** Počet s oddělovačem tisíců v jazyce webu: 12 345 česky a slovensky, 12,345 anglicky, 12.345 německy. */
function pocet(float|int $cislo, int $desetinna = 0): string
{
    [$carka, $tisice] = match (Kaleta\Core\Jazyk::kod()) {
        'en' => ['.', ','],
        'de' => [',', '.'],
        default => [',', "\u{00A0}"],
    };

    return number_format((float) $cislo, $desetinna, $carka, $tisice);
}

/** České datum: 18. 9. 2026, volitelně s časem. */
function datum(string|\DateTimeInterface|null $value, bool $withTime = false): string
{
    if ($value === null || $value === '') {
        return '';
    }
    $dt = $value instanceof \DateTimeInterface ? $value : new \DateTimeImmutable($value);

    // tvar data podle jazyka: slovník smí dát klíč "datum_format" (formát pro date()), např. "j M Y" pro angličtinu
    $format = t('datum_format');
    $format = $format === 'datum_format' ? 'j. n. Y' : $format;

    return $dt->format($withTime ? $format . ' H:i' : $format);
}

/** Datum slovy: "pátek 18. září 2026". */
function datum_slovy(string|\DateTimeInterface|null $value = null): string
{
    $dny = ['neděle', 'pondělí', 'úterý', 'středa', 'čtvrtek', 'pátek', 'sobota'];
    $mesice = [1 => 'ledna', 'února', 'března', 'dubna', 'května', 'června', 'července', 'srpna', 'září', 'října', 'listopadu', 'prosince'];
    $dt = $value instanceof \DateTimeInterface ? $value : new \DateTimeImmutable($value ?? 'now');
    // slovník jazyka může dát vlastní tvar data: klíč "datum_slovy" = formát pro date(), např. "l j F Y"
    $format = t('datum_slovy');
    if ($format !== 'datum_slovy') {
        return preg_replace_callback('/[A-Za-zÀ-ž]{3,}/u', fn (array $m): string => t($m[0]), $dt->format($format)) ?? $dt->format($format);
    }

    return t($dny[(int) $dt->format('w')]) . ' ' . $dt->format('j') . '. ' . t($mesice[(int) $dt->format('n')]) . ' ' . $dt->format('Y');
}
