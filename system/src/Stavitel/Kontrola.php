<?php

declare(strict_types=1);

namespace Kaleta\Stavitel;

/**
 * Kontrola stavby před publikováním pro Clauda (MCP) – stejná pravidla jako v builderu (image/stavitel.js, kontrola()):
 * tlačítka bez odkazu, obrázky bez souboru či popisu a u stránek osnova nadpisů. Kontrast textu tu chybí, ten potřebuje
 * vykreslenou stránku a hlídá ho builder v prohlížeči.
 */
final class Kontrola
{
    public const int MAX = 12;

    /**
     * @param array<string, mixed> $stavba vyčištěná stavba
     * @param bool $nadpisy hlídat osnovu nadpisů (stránka má mít jeden h1 a nepřeskakovat úrovně)
     * @return list<array{id: ?string, zprava: string}>
     */
    public static function stavby(array $stavba, bool $nadpisy): array
    {
        $nalezy = [];
        $osnova = [];
        $znacky = static fn (mixed $x): bool => is_string($x) && str_contains($x, '{{');
        $projdi = static function (array $deti) use (&$projdi, &$nalezy, &$osnova, $znacky): void {
            foreach ($deti as $p) {
                if (!is_array($p)) {
                    continue;
                }
                $o = is_array($p['obsah'] ?? null) ? $p['obsah'] : [];
                $id = isset($p['id']) ? (string) $p['id'] : null;
                $typ = $p['typ'] ?? '';
                if ($typ === 'tlacitko' && in_array($o['odkaz'] ?? '', ['', '#'], true)) {
                    $nalezy[] = ['id' => $id, 'zprava' => t('Tlačítko „%s“ nikam nevede – doplňte odkaz.', self::text($o['text'] ?? ''))];
                }
                if ($typ === 'obrazek' && ($o['src'] ?? '') === '') {
                    $nalezy[] = ['id' => $id, 'zprava' => t('Obrázek není vybraný – na webu se nezobrazí.')];
                } elseif ($typ === 'obrazek' && ($o['alt'] ?? '') === '' && !$znacky($o['src'])) {
                    $nalezy[] = ['id' => $id, 'zprava' => t('Obrázek nemá popis pro nevidomé (alt).')];
                }
                // nadpis se značkou p (velké číslo, štítek) do osnovy nepatří
                if ($typ === 'nadpis' && preg_match('/^h([1-6])$/', (string) ($p['znacka'] ?? 'h2'), $m)) {
                    $osnova[] = [$id, (int) $m[1], self::text($o['text'] ?? '')];
                }
                if (is_array($p['deti'] ?? null)) {
                    $projdi($p['deti']);
                }
            }
        };
        $projdi(is_array($stavba['deti'] ?? null) ? $stavba['deti'] : []);
        if ($nadpisy) {
            $h1 = array_values(array_filter($osnova, static fn (array $n): bool => $n[1] === 1));
            if ($h1 === []) {
                $nalezy[] = ['id' => $osnova[0][0] ?? null, 'zprava' => t('Stránka nemá hlavní nadpis (h1) – vyhledávače i čtečky podle něj poznají, o čem je.')];
            }
            if (count($h1) > 1) {
                $nalezy[] = ['id' => $h1[1][0], 'zprava' => t('Stránka má víc hlavních nadpisů (h1) – nechte jen jeden.')];
            }
            foreach ($osnova as $i => $n) {
                if ($i > 0 && $n[1] > $osnova[$i - 1][1] + 1) {
                    $nalezy[] = ['id' => $n[0], 'zprava' => t('Nadpis „%s“ přeskakuje úroveň (h%d → h%d).', mb_substr($n[2], 0, 40), $osnova[$i - 1][1], $n[1])];
                }
            }
        }

        return array_slice($nalezy, 0, self::MAX);
    }

    private static function text(mixed $html): string
    {
        return trim(html_entity_decode(strip_tags((string) $html), ENT_QUOTES | ENT_HTML5));
    }
}
