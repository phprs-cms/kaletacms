<?php

declare(strict_types=1);

namespace MiroCMS\Front;

use MiroCMS\Core\Db;
use MiroCMS\Core\Obrazky;
use MiroCMS\Core\Settings;

/**
 * Čtení novinek pro web (tabulka mc_novinky). Na webu je vidět jen novinka vydaná (visible = 1), jejíž datum vydání už nastalo.
 */
final class Clanky
{
    private const string SELECT = "
        SELECT c.*, t.nazev AS tema_jm, t.seo_link AS tema_seo,
               NULLIF(u.jmeno, '') AS autor_jm, -- přihlašovací jméno se na webu neukazuje; bez vyplněného jména se autor nevypisuje
               u.pozice AS autor_pozice, u.foto AS autor_foto, u.bio AS autor_bio, u.url AS autor_url
        FROM {novinky} c
        JOIN {kategorie} t ON t.idt = c.tema
        LEFT JOIN {uzivatele} u ON u.idu = c.autor";

    /**
     * Sloupce pro výpisy: bez dlouhých textů (text, FAQ), které výpis netiskne. Klíče v poli zůstávají (prázdné),
     * aby šablony nepadaly. Nový sloupec mc_novinky, který má být vidět ve výpisech, je potřeba doplnit i sem.
     */
    private const string SLOUPCE_VYPISU = "c.idc, c.seo_link, c.titulek, c.uvod, '' AS text, c.obrazek, c.tema, c.autor, c.datum, c.visible, c.t_slova, c.noindex, '' AS faq, c.visit,
        c.zmeneno, c.aktualizovano, c.jazyk, c.preklad_z";

    private const string VYDANE = 'c.visible = 1 AND c.datum <= NOW()';

    /** Podmínka "vydaná novinka v jazyce právě zobrazené verze webu". */
    private readonly string $vydane;

    /** @param string $zaklad cesta k instalaci ("" nebo "/web") - doplňuje se před adresy obrázků z media/ */
    public function __construct(private readonly Db $db, private readonly Settings $settings, private readonly string $zaklad = '')
    {
        $this->vydane = self::VYDANE . " AND c.jazyk = '" . \MiroCMS\Core\Jazyk::sloupecWebu() . "'";
    }

    /**
     * Úprava novinky před předáním šabloně: adresa hlavního obrázku z media/ dostane cestu k instalaci.
     *
     * @param array<string, mixed> $clanek
     * @return array<string, mixed>
     */
    private function priprav(array $clanek): array
    {
        if ($clanek['obrazek'] !== '' && !preg_match('#^(https?:)?/#', $clanek['obrazek'])) {
            $clanek['obrazek'] = $this->zaklad . '/' . $clanek['obrazek'];
        }
        // responzivní obrázky: hlavní obrázek i obrázky v textu dostanou srcset z variant, které vznikly při nahrání
        $clanek['obrazek_srcset'] = Obrazky::srcset(ltrim(substr($clanek['obrazek'], strlen($this->zaklad)), '/'), $this->zaklad);
        foreach (['uvod', 'text'] as $cast) {
            if (str_contains($clanek[$cast], 'media/')) {
                $clanek[$cast] = preg_replace_callback('#<img\b(?![^>]*\bsrcset=)([^>]*?)\bsrc="([^"]*?(media/\d{4}/\d{2}/[^"]+))"#i', function (array $m): string {
                    $srcset = Obrazky::srcset($m[3], $this->zaklad);

                    return $srcset === '' ? $m[0] : '<img' . $m[1] . 'src="' . $m[2] . '" srcset="' . e($srcset) . '" sizes="(max-width: 800px) 100vw, 800px"';
                }, $clanek[$cast]) ?? $clanek[$cast];
            }
        }

        return $clanek;
    }

    public function naStranku(): int
    {
        return max(1, $this->settings->int('pocet_clanku'));
    }

    /**
     * Vydané novinky od nejnovější.
     *
     * @return array{0: list<array<string, mixed>>, 1: int} novinky a jejich celkový počet
     */
    public function vypis(int $strana, ?int $limit = null, bool $sTextem = false): array
    {
        return $this->dotaz($this->vydane, [], 'c.datum DESC, c.idc DESC', $strana, $limit, $sTextem);
    }

    /** @return array{0: list<array<string, mixed>>, 1: int} */
    public function zKategorie(int $idt, int $strana, ?int $limit = null): array
    {
        return $this->dotaz($this->vydane . ' AND c.tema = ?', [$idt], 'c.datum DESC, c.idc DESC', $strana, $limit);
    }

    /** @return array{0: list<array<string, mixed>>, 1: int} */
    public function seStitkem(int $ids, int $strana): array
    {
        return $this->dotaz($this->vydane . ' AND EXISTS (SELECT 1 FROM {novinky_stitky} cs WHERE cs.idc = c.idc AND cs.ids = ?)', [$ids], 'c.datum DESC, c.idc DESC', $strana);
    }

    /** @return array{0: list<array<string, mixed>>, 1: int} */
    public function hledej(string $q, int $strana): array
    {
        // index bez diakritiky (Core\Hledani): "nabrezi" najde "nábřeží"; krátká slova a části slov se hledají v titulku
        \MiroCMS\Core\Hledani::dopln($this->db); // novinky z doby před indexem se doplní samy
        $like = '%' . addcslashes($q, '%_\\') . '%';
        $dotaz = \MiroCMS\Core\Hledani::dotaz($q);
        if ($dotaz === '') {
            return $this->dotaz($this->vydane . ' AND c.titulek LIKE ?', [$like], 'c.datum DESC, c.idc DESC', $strana);
        }

        return $this->dotaz(
            $this->vydane . ' AND (MATCH(c.hledani) AGAINST (? IN BOOLEAN MODE) OR c.titulek LIKE ?)',
            [$dotaz, $like],
            'c.datum DESC, c.idc DESC',
            $strana,
        );
    }

    /** @return array<string, mixed>|null */
    public function podleSeo(string $seo, bool $iNevydany = false): ?array
    {
        $clanek = $this->db->one(self::SELECT . ' WHERE c.seo_link = ? AND c.smazano IS NULL' . ($iNevydany ? '' : ' AND ' . self::VYDANE), [$seo]);
        if ($clanek === null) {
            return null;
        }
        // popisek, autor a alt hlavního obrázku: z novinky, jinak z knihovny médií
        $knihovna = $clanek['obrazek'] !== '' && !preg_match('#^(https?:)?//#', $clanek['obrazek'])
            ? $this->db->one('SELECT nazev, popis, autor FROM {media} WHERE obr_poloha = ? LIMIT 1', [ltrim($clanek['obrazek'], '/')]) : null;
        $popis = $clanek['obrazek_popis'] !== '' ? $clanek['obrazek_popis'] : (string) ($knihovna['popis'] ?? '');
        $autor = $clanek['obrazek_autor'] !== '' ? $clanek['obrazek_autor'] : (string) ($knihovna['autor'] ?? '');
        $clanek['obrazek_alt'] = (string) ($knihovna['nazev'] ?? '') !== '' ? (string) $knihovna['nazev'] : $popis;
        $casti = array_filter([e($popis), $autor !== '' ? '<span class="clanek-foto-autor">' . e(t('Foto: %s', $autor)) . '</span>' : '']);
        $clanek['obrazek_popisek_html'] = $casti === [] ? '' : '<figcaption class="clanek-popisek">' . implode(' ', $casti) . '</figcaption>';

        return $this->priprav($clanek);
    }

    /**
     * Podobné novinky: nejdřív podle počtu společných štítků, potom novější ze stejné kategorie.
     *
     * @param array<string, mixed> $clanek
     * @return list<array<string, mixed>>
     */
    public function podobne(array $clanek, int $pocet = 4): array
    {
        return $this->db->all(
            'SELECT c.titulek, c.seo_link, c.datum, COUNT(cs.ids) AS shoda
             FROM {novinky} c LEFT JOIN {novinky_stitky} cs ON cs.idc = c.idc AND cs.ids IN (SELECT ids FROM {novinky_stitky} WHERE idc = ?)
             WHERE ' . $this->vydane . ' AND c.idc <> ? AND (c.tema = ? OR cs.ids IS NOT NULL) AND c.datum > NOW() - INTERVAL 2 YEAR
             GROUP BY c.idc, c.titulek, c.seo_link, c.datum ORDER BY shoda DESC, c.datum DESC LIMIT ?',
            [$clanek['idc'], $clanek['idc'], $clanek['tema'], $pocet],
        );
    }

    /** @return array{0: list<array<string, mixed>>, 1: int} */
    private function dotaz(string $where, array $params, string $order, int $strana, ?int $limit = null, bool $sTextem = false): array
    {
        // pevný počet (RSS, kanály, API) = nikdo nestránkuje, celkový počet se nepočítá
        $celkem = $limit !== null ? 0 : (int) $this->db->value("SELECT COUNT(*) FROM {novinky} c WHERE {$where}", $params);
        $limit ??= $this->naStranku();
        $strana = max(1, min($strana, 100000));
        $clanky = $this->db->all(
            ($sTextem ? self::SELECT : str_replace('SELECT c.*,', 'SELECT ' . self::SLOUPCE_VYPISU . ',', self::SELECT)) . " WHERE {$where} ORDER BY {$order} LIMIT ? OFFSET ?",
            [...$params, $limit, ($strana - 1) * $limit],
        );

        return [array_map($this->priprav(...), $clanky), $celkem];
    }
}
