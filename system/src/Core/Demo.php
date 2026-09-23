<?php

declare(strict_types=1);

namespace MiroCMS\Core;

/**
 * Ukázkový obsah: rubriky, články a obrázky smyšleného magazínu ze system/demo/, aby nový web hned vypadal jako živý.
 * Nahrává se z instalátoru nebo z Nastavení → Základní; tamtéž ho jde jedním kliknutím zase smazat.
 *
 * Co vzniklo, drží nastavení "demo_obsah" (JSON: clanky, rubriky, obrazky = čísla záznamů; soubory = cesty v media/).
 * Obrázky se kopírují do media/RRRR/MM/ a zapisují do Médií stejně jako nahrané – fungují tak náhledy, srcset i mazání.
 */
final class Demo
{
    private const string SLOZKA = MIROCMS_SYSTEM . '/demo';

    /** Jazyky, ve kterých ukázkový obsah existuje; ostatní (slovenština) dostanou češtinu. */
    public const array JAZYKY = ['cs', 'en', 'de'];

    public static function jeNahrany(Settings $nastaveni): bool
    {
        return self::zaznam($nastaveni) !== null;
    }

    /**
     * Založí rubriky, články a obrázky. Obsah je ve výchozím jazyce webu (sloupec jazyk = '').
     *
     * @param string $jazyk kód jazyka obsahu (cs, sk, en, de)
     * @param int|null $autor účet, kterému budou patřit články bez hostujícího autora
     */
    public static function nahraj(Db $db, Settings $nastaveni, string $jazyk, ?int $autor): void
    {
        if (self::jeNahrany($nastaveni)) {
            return;
        }
        $data = require self::SLOZKA . '/obsah.php';
        $texty = $data[in_array($jazyk, self::JAZYKY, true) ? $jazyk : 'cs'];
        $zaznam = ['clanky' => [], 'rubriky' => [], 'obrazky' => [], 'soubory' => []];

        $rubriky = [];
        $hodnost = 300;
        foreach ($texty['rubriky'] as $klic => $nazev) {
            $rubriky[$klic] = $db->insert('topic', ['nazev' => $nazev, 'seo_link' => self::volnaAdresa($db, 'topic', $nazev), 'popis' => '', 'hodnost' => $hodnost]);
            $zaznam['rubriky'][] = $rubriky[$klic];
            $hodnost -= 50;
        }

        $sablona = $db->value("SELECT ids FROM {cla_sab} WHERE soubor_cla_sab = 'standard'");
        $ted = time();
        foreach ($data['clanky'] as $klic => $spolecne) {
            $clanek = $texty['clanky'][$klic];
            $obrazek = '';
            if ($spolecne['obrazek'] !== '') {
                $obrazek = self::nahrajObrazek($db, $spolecne['obrazek'], $clanek['titulek'], $autor, $zaznam);
            }
            $idc = $db->insert('clanky', [
                'seo_link' => self::volnaAdresa($db, 'clanky', $clanek['titulek']),
                'titulek' => $clanek['titulek'],
                'uvod' => $clanek['uvod'],
                'text' => $clanek['text'],
                'shrnuti' => $clanek['shrnuti'] ?? null,
                'obrazek' => $obrazek,
                'tema' => $rubriky[$spolecne['rubrika']],
                'autor' => $autor,
                'externi_autor' => $spolecne['autor'],
                'datum' => date('Y-m-d H:i:s', $ted - $spolecne['pred_hodinami'] * 3600),
                'visible' => 1,
                'priority' => $spolecne['pripnout'] ? 250 : 0, // připnutý článek = otvírák hlavní stránky
                'sablona' => $sablona === null ? null : (int) $sablona,
                'oznameno' => date('Y-m-d H:i:s', $ted), // ukázka se neoznamuje (webhook, IndexNow, Web Push)
            ]);
            Hledani::indexuj($db, $idc);
            \MiroCMS\Admin\Moduly\Galerie::zapisPouziti($db, $idc, $obrazek);
            $zaznam['clanky'][] = $idc;
        }

        $nastaveni->set('demo_obsah', (string) json_encode($zaznam));
    }

    /**
     * Smaže ukázkové články, rubriky, které po nich zůstaly prázdné, a obrázky, které nepoužívá žádný jiný článek.
     * Co si redakce mezitím smazala sama, se přeskočí.
     */
    public static function smaz(Db $db, Settings $nastaveni): void
    {
        $zaznam = self::zaznam($nastaveni);
        if ($zaznam === null) {
            return;
        }
        foreach ($zaznam['clanky'] as $idc) {
            $db->delete('clanky', ['idc' => (int) $idc]); // komentáře, revize, štítky a použití obrázků mizí s článkem (cizí klíče)
        }
        foreach ($zaznam['rubriky'] as $idt) {
            if ((int) $db->value('SELECT COUNT(*) FROM {clanky} WHERE tema = ?', [(int) $idt]) === 0) {
                // případné podrubriky se posunou o úroveň výš, stejně jako při mazání rubriky v administraci
                $db->run('UPDATE {topic} SET id_predka = ? WHERE id_predka = ?', [$db->value('SELECT id_predka FROM {topic} WHERE idt = ?', [(int) $idt]), (int) $idt]);
                $db->delete('topic', ['idt' => (int) $idt]);
            }
        }
        foreach ($zaznam['obrazky'] as $ido) {
            $obr = $db->one('SELECT ido, obr_poloha, nahl_poloha FROM {imggal_obr} WHERE ido = ?', [(int) $ido]);
            if ($obr === null || !in_array($obr['obr_poloha'], $zaznam['soubory'], true)) {
                continue;
            }
            if ((int) $db->value('SELECT COUNT(*) FROM {imggal_pouziti} WHERE ido = ?', [(int) $ido]) > 0) {
                continue; // obrázek si redakce vzala do vlastního článku - zůstává
            }
            Obrazky::smaz($obr['obr_poloha'], $obr['nahl_poloha']);
            $db->delete('imggal_obr', ['ido' => (int) $ido]);
        }
        $nastaveni->set('demo_obsah', '');
    }

    /** @return array{clanky: list<int>, rubriky: list<int>, obrazky: list<int>, soubory: list<string>}|null */
    private static function zaznam(Settings $nastaveni): ?array
    {
        $zaznam = json_decode($nastaveni->get('demo_obsah'), true);

        return is_array($zaznam) ? $zaznam + ['clanky' => [], 'rubriky' => [], 'obrazky' => [], 'soubory' => []] : null;
    }

    /** Adresa (seo_link) z názvu; když už ji má jiná rubrika nebo článek, dostane pořadové číslo. */
    private static function volnaAdresa(Db $db, string $tabulka, string $nazev): string
    {
        $zaklad = slugify($nazev, 100);
        $adresa = $zaklad;
        for ($i = 2; (int) $db->value('SELECT COUNT(*) FROM {' . $tabulka . '} WHERE seo_link = ?', [$adresa]) > 0; $i++) {
            $adresa = $zaklad . '-' . $i;
        }

        return $adresa;
    }

    /**
     * Zkopíruje ilustraci do media/RRRR/MM/demo-<soubor>, vytvoří náhled a zapíše ji do Médií.
     * Vrací cestu pro sloupec "obrazek"; prázdný řetězec, když do media/ nejde zapisovat (článek pak bude bez obrázku).
     *
     * @param array{clanky: list<int>, rubriky: list<int>, obrazky: list<int>, soubory: list<string>} $zaznam
     */
    private static function nahrajObrazek(Db $db, string $soubor, string $nazev, ?int $autor, array &$zaznam): string
    {
        $zdroj = self::SLOZKA . '/img/' . $soubor;
        $slozka = 'media/' . date('Y/m');
        $cil = $slozka . '/demo-' . $soubor;
        if (!is_file($zdroj) || (!is_dir(MIROCMS_ROOT . '/' . $slozka) && !@mkdir(MIROCMS_ROOT . '/' . $slozka, 0775, true)) || !@copy($zdroj, MIROCMS_ROOT . '/' . $cil)) {
            return '';
        }
        if ($db->value('SELECT ido FROM {imggal_obr} WHERE obr_poloha = ?', [$cil]) !== null) {
            return $cil; // obrázek zůstal v Médiích z dřívějšího nahrání (redakce ho použila jinde) - druhý záznam nezakládáme
        }
        [$sirka, $vyska] = getimagesize($zdroj) ?: [0, 0];
        // náhled pro Média a srcset; bez rozšíření GD poslouží jako náhled obrázek sám
        $nahled = $cil;
        $nahledSirka = (int) $sirka;
        $obr = function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($zdroj) : false;
        $maly = $obr === false ? false : imagescale($obr, Obrazky::NAHLED_STRANA);
        if ($maly !== false && imagejpeg($maly, MIROCMS_ROOT . '/' . $slozka . '/demo-' . basename($soubor, '.jpg') . '-nahled.jpg', 85)) {
            $nahled = $slozka . '/demo-' . basename($soubor, '.jpg') . '-nahled.jpg';
            $nahledSirka = Obrazky::NAHLED_STRANA;
        }
        $zaznam['obrazky'][] = $db->insert('imggal_obr', [
            'vlastnik' => $autor, 'nazev' => mb_substr($nazev, 0, 150), 'obr_poloha' => $cil, 'obr_width' => (int) $sirka, 'obr_height' => (int) $vyska,
            'obr_vel' => (int) filesize($zdroj), 'nahl_poloha' => $nahled, 'nahl_width' => $nahledSirka, 'nahl_height' => (int) round($vyska * $nahledSirka / max(1, $sirka)),
            'datum' => date('Y-m-d H:i:s'),
        ]);
        $zaznam['soubory'][] = $cil;

        return $cil;
    }
}
