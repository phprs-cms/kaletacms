<?php

declare(strict_types=1);

namespace MiroCMS\Stavitel;

use MiroCMS\Core\Db;
use MiroCMS\Core\WpObsah;

/**
 * Kolekce – vlastní typy obsahu (reference, tým, produkty, pobočky…): definice polí, položky a hodnoty pro stavitel.
 *
 * Ve staviteli je vypisuje prvek Výpis kolekce: jeho vnitřek se zopakuje pro každou položku a zástupné značky {{pole}}
 * v textech, obrázcích a odkazech se nahradí hodnotami položky. Vždy jsou k dispozici {{nazev}}, {{url}} (detail) a {{datum}}.
 */
final class Kolekce
{
    /** Typy polí (klíč => popisek). */
    public const array TYPY_POLI = ['text' => 'krátký text', 'radky' => 'delší text', 'html' => 'formátovaný text', 'obrazek' => 'obrázek', 'odkaz' => 'odkaz', 'cislo' => 'číslo', 'datum' => 'datum'];

    /** Vestavěné hodnoty každé položky – vlastní pole je mít nesmí. */
    public const array VESTAVENE = ['nazev', 'url', 'datum', 'seo'];

    public const string VZOR_ZNACKY = '/\{\{([a-z][a-z0-9_]{0,30})\}\}/';

    /** @return list<array<string, mixed>> */
    public static function vsechny(Db $db): array
    {
        return array_map(self::rozbal(...), $db->all('SELECT * FROM {kolekce} ORDER BY nazev'));
    }

    /** @return array<string, mixed>|null */
    public static function podleSeo(Db $db, string $seo): ?array
    {
        $r = $db->one('SELECT * FROM {kolekce} WHERE seo_link = ?', [$seo]);

        return $r === null ? null : self::rozbal($r);
    }

    /** @return array<string, mixed>|null */
    public static function podleId(Db $db, int $idk): ?array
    {
        $r = $db->one('SELECT * FROM {kolekce} WHERE idk = ?', [$idk]);

        return $r === null ? null : self::rozbal($r);
    }

    private static function rozbal(array $r): array
    {
        $r['pole'] = json_decode((string) $r['pole'], true) ?: [];

        return $r;
    }

    /**
     * Definice polí z formuláře nebo od AI: klíč jen malá písmena, číslice a podtržítko (vznikne z popisku), známý typ.
     *
     * @return list<array{klic: string, popisek: string, typ: string}>
     */
    public static function vycistiPole(mixed $vstup): array
    {
        $pole = [];
        $klice = [];
        foreach (is_array($vstup) ? $vstup : [] as $p) {
            $popisek = mb_substr(trim(strip_tags((string) ($p['popisek'] ?? ''))), 0, 80);
            if ($popisek === '') {
                continue;
            }
            $klic = (string) ($p['klic'] ?? '');
            $klic = preg_match('/^[a-z][a-z0-9_]{0,30}$/', $klic) ? $klic : substr(str_replace('-', '_', slugify($popisek, 30)), 0, 30);
            if (!preg_match('/^[a-z]/', $klic)) {
                $klic = 'pole_' . $klic;
            }
            while (in_array($klic, self::VESTAVENE, true) || isset($klice[$klic])) {
                $klic .= '_2';
            }
            $klice[$klic] = true;
            $pole[] = ['klic' => $klic, 'popisek' => $popisek, 'typ' => isset(self::TYPY_POLI[$p['typ'] ?? '']) ? $p['typ'] : 'text'];
        }

        return array_slice($pole, 0, 30);
    }

    /**
     * Hodnoty položky podle definice polí. Neplatná hodnota se zahodí a nahlásí.
     *
     * @param list<array{klic: string, popisek: string, typ: string}> $pole
     * @param array<string, string> $chyby
     * @return array<string, string>
     */
    public static function vycistiData(array $pole, array $vstup, array &$chyby = []): array
    {
        $data = [];
        foreach ($pole as $p) {
            $h = trim((string) (is_scalar($vstup[$p['klic']] ?? null) ? $vstup[$p['klic']] : ''));
            $cista = match ($p['typ']) {
                'text' => mb_substr(strip_tags(str_replace(["\r", "\n"], ' ', $h)), 0, 500),
                'radky' => mb_substr(strip_tags(str_replace("\r\n", "\n", $h)), 0, 5000),
                'html' => WpObsah::bezpecneHtml(mb_substr($h, 0, 100000)),
                'obrazek' => $h === '' || preg_match('#^(https://[^\s"\'<>]{1,500}|/?([A-Za-z0-9_.-]+/){0,3}media/[A-Za-z0-9/_.-]{1,300})$#', $h) ? $h : null,
                'odkaz' => $h === '' || (WpObsah::bezpecnaAdresa($h) && !preg_match('/[\s"<>]/', $h)) ? mb_substr($h, 0, 500) : null,
                'cislo' => $h === '' || is_numeric(str_replace([' ', ','], ['', '.'], $h)) ? str_replace(' ', '', $h) : null,
                'datum' => $h === '' || (preg_match('/^\d{4}-\d{2}-\d{2}$/', $h) && strtotime($h) !== false) ? $h : null,
                default => '',
            };
            if ($cista === null) {
                $chyby[$p['klic']] = $p['popisek'];
                $cista = '';
            }
            $data[$p['klic']] = $cista;
        }

        return $data;
    }

    /**
     * Viditelné položky kolekce v jazyce webu.
     *
     * @return list<array<string, mixed>>
     */
    public static function polozky(Db $db, int $idk, string $jazyk, int $pocet, string $razeni = 'poradi'): array
    {
        $poradi = match ($razeni) {
            'nazev' => 'nazev',
            'nejnovejsi' => 'datum DESC, idp DESC',
            default => 'poradi, nazev',
        };

        return array_map(function (array $r): array {
            $r['data'] = json_decode((string) $r['data'], true) ?: [];

            return $r;
        }, $db->all('SELECT * FROM {kolekce_polozky} WHERE idk = ? AND zobrazit = 1 AND jazyk = ? ORDER BY ' . $poradi . ' LIMIT ?', [$idk, $jazyk, max(1, min(100, $pocet))]));
    }

    /**
     * Hodnoty pro zástupné značky: klíč => [hodnota, typ].
     *
     * @param callable(string): string $url adresa uvnitř webu
     * @return array<string, array{0: string, 1: string}>
     */
    public static function hodnoty(array $kolekce, array $polozka, callable $url): array
    {
        $h = [
            'nazev' => [(string) $polozka['nazev'], 'text'],
            'url' => [$kolekce['detail'] ? $url($kolekce['seo_link'] . '/' . $polozka['seo_link']) : '', 'odkaz'],
            'datum' => [datum((string) $polozka['datum']), 'text'],
            'seo' => [(string) $polozka['seo_link'], 'text'],
        ];
        foreach ($kolekce['pole'] as $p) {
            $h[$p['klic']] = [(string) ($polozka['data'][$p['klic']] ?? ''), $p['typ']];
        }

        return $h;
    }

    /** Ukázkové hodnoty pro editor, když kolekce ještě nemá položky: popisky polí v hranatých závorkách. */
    public static function ukazka(array $kolekce): array
    {
        $h = ['nazev' => ['[' . t('Název') . ']', 'text'], 'url' => ['#', 'odkaz'], 'datum' => [datum(date('Y-m-d H:i:s')), 'text'], 'seo' => ['', 'text']];
        foreach ($kolekce['pole'] as $p) {
            $h[$p['klic']] = [in_array($p['typ'], ['obrazek', 'odkaz'], true) ? '' : '[' . $p['popisek'] . ']', $p['typ']];
        }

        return $h;
    }

    /**
     * Dosadí hodnoty do pole obsahu prvku podle typu cílového pole (text se escapuje až při vykreslení, inline a html hned).
     *
     * @param array<string, array{0: string, 1: string}> $hodnoty
     */
    public static function dosad(string $text, string $cil, array $hodnoty): string
    {
        if (!str_contains($text, '{{')) {
            return $text;
        }
        if ($cil === 'html') {
            // odstavec jen se značkou formátovaného nebo delšího textu se nahradí celý (jinak by vzniklo <p><p>…</p></p>)
            $text = (string) preg_replace_callback('#<p>\s*\{\{([a-z][a-z0-9_]{0,30})\}\}\s*</p>#', function (array $m) use ($hodnoty): string {
                [$h, $typ] = $hodnoty[$m[1]] ?? ['', 'text'];

                return match ($typ) {
                    'html' => $h,
                    'radky' => $h === '' ? '' : '<p>' . nl2br(e($h), false) . '</p>',
                    default => $h === '' ? '' : '<p>' . e($h) . '</p>',
                };
            }, $text);
        }
        $vysledek = (string) preg_replace_callback(self::VZOR_ZNACKY, function (array $m) use ($cil, $hodnoty): string {
            [$h, $typ] = $hodnoty[$m[1]] ?? ['', 'text'];
            $prosty = $typ === 'html' ? trim(html_entity_decode(strip_tags($h), ENT_QUOTES | ENT_HTML5)) : $h;

            return match ($cil) {
                'html' => $typ === 'html' ? $h : ($typ === 'radky' ? nl2br(e($h), false) : e($h)),
                'inline' => $typ === 'radky' ? nl2br(e($h), false) : e($prosty),
                default => $prosty,
            };
        }, $text);
        if ($cil === 'odkaz' && $vysledek !== '' && !WpObsah::bezpecnaAdresa($vysledek)) {
            return '';
        }
        if ($cil === 'obrazek' && $vysledek !== '' && !preg_match('#^(https://[^\s"\'<>]{1,500}|/?([A-Za-z0-9_.-]+/){0,3}media/[A-Za-z0-9/_.-]{1,300})$#', $vysledek)) {
            return '';
        }

        return $vysledek;
    }

    /** Šablona detailu položky, dokud ji správce neupraví ve staviteli: nadpis, obrázek a všechna pole pod sebou. */
    public static function vychoziSablona(array $kolekce): array
    {
        $n = Stavba::novy(...);
        $deti = [['znacka' => 'h1'] + $n('nadpis', ['text' => '{{nazev}}'])];
        foreach ($kolekce['pole'] as $p) {
            $deti[] = match ($p['typ']) {
                'obrazek' => $n('obrazek', ['src' => '{{' . $p['klic'] . '}}', 'alt' => '{{nazev}}']),
                'odkaz' => $n('tlacitko', ['text' => $p['popisek'], 'odkaz' => '{{' . $p['klic'] . '}}', 'varianta' => 'obrys']),
                'html', 'radky' => $n('text', ['html' => '{{' . $p['klic'] . '}}']),
                default => $n('text', ['html' => '<p><strong>' . e($p['popisek']) . ':</strong> {{' . $p['klic'] . '}}</p>']),
            };
        }

        return Stavba::vycisti(['v' => Stavba::VERZE, 'deti' => [$n('sekce', ['sirka' => 'uzka'], [
            ['styl' => ['zaklad' => ['zobrazeni' => 'flex', 'smer' => 'column', 'mezera' => 'm']]] + $n('kontejner', [], $deti),
        ])]])[0];
    }
}
