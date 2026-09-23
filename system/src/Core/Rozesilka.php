<?php

declare(strict_types=1);

namespace MiroCMS\Core;

/**
 * Rozesílka newsletteru: jedno vydání = předmět, úvod a vybrané články. Rozesílá se po dávkách - ručně z administrace,
 * nebo na pozadí (naplánovaná vydání a automatický výběr nových článků).
 *
 * Statistika je jen souhrnná: počet otevření (obrázek 1×1) a prokliků (odkazy vedou přes /newsletter/k/…).
 * Nic se neváže na konkrétního odběratele.
 */
final class Rozesilka
{
    public const int DAVKA = 40;

    public static function posli(App $app, int $idn, string $email, string $token): bool
    {
        $web = $app->settings();
        $db = $app->db();
        $v = $db->one('SELECT * FROM {newsletter} WHERE idn = ?', [$idn]);
        if ($v === null) {
            return false;
        }
        $ids = array_filter(array_map(intval(...), explode(',', $v['clanky'])));
        $clanky = $ids === [] ? [] : $db->all('SELECT titulek, seo_link, uvod, obrazek, jazyk FROM {clanky} WHERE idc IN (' . implode(',', $ids) . ') ORDER BY FIELD(idc, ' . implode(',', $ids) . ')');
        $koren = $app->request->origin() . $app->request->basePath() . '/';
        $odhlasit = $koren . 'newsletter/odhlasit/' . $token;
        $adresa = fn (array $c): string => $koren . ($c['jazyk'] !== '' ? $c['jazyk'] . '/' : '') . 'clanek/' . $c['seo_link'];
        // odkaz přes počítadlo prokliků; podpis brání zneužití adresy k přesměrování jinam
        $sledovany = fn (string $cil): string => $koren . 'newsletter/k/' . $idn . '?u=' . rawurlencode($cil) . '&p=' . self::podpis($app, $idn . '|' . $cil);
        // texty e-mailu jsou v jazyce vydání – ne v jazyce toho, kdo rozesílku zrovna spustil (redaktor, návštěvník spouštějící úlohy na pozadí)
        return Jazyk::docasne($v['jazyk'] !== '' ? $v['jazyk'] : Jazyk::vychozi($web), function () use ($app, $web, $v, $koren, $odhlasit, $sledovany, $adresa, $clanky, $idn, $email): bool {
            $html = $app->view->render('admin/newsletter/email', [
                'web' => $web, 'vydani' => $v, 'koren' => $koren, 'odhlasit' => $odhlasit,
                'clanky' => array_map(fn (array $c): array => $c + ['adresa' => $sledovany($adresa($c))], $clanky),
                'pixel' => $koren . 'newsletter/o/' . $idn . '.gif',
            ]);
            $text = $v['uvod'] . "\n\n" . implode("\n\n", array_map(fn (array $c): string => $c['titulek'] . "\n" . trim(strip_tags($c['uvod'])) . "\n" . $adresa($c), $clanky)) . "\n\n--\n" . Jazyk::t('Odhlásit odběr') . ": {$odhlasit}\n";

            return Posta::odesli($web, $email, $v['predmet'], $text, $html, ['List-Unsubscribe' => "<{$odhlasit}>", 'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click']);
        });
    }

    /** Jedna dávka rozesílky vydání. Vrací true, když je rozesláno všem. */
    public static function davka(App $app, int $idn): bool
    {
        $db = $app->db();
        $v = $db->one('SELECT * FROM {newsletter} WHERE idn = ? AND odeslano IS NULL', [$idn]);
        if ($v === null) {
            return true;
        }
        $davka = $db->all('SELECT * FROM {odberatele} WHERE potvrzen = 1 AND jazyk = ? AND ido > ? ORDER BY ido LIMIT ' . self::DAVKA, [$v['jazyk'], (int) $v['posledni']]);
        foreach ($davka as $o) {
            // ukazatel se posouvá před odesláním: souběžná dávka nepošle stejnému člověku dvakrát
            if ($db->run('UPDATE {newsletter} SET posledni = ?, pocet = pocet + 1 WHERE idn = ? AND posledni < ?', [$o['ido'], $idn, $o['ido']])->rowCount() === 1) {
                self::posli($app, $idn, $o['email'], $o['token']);
            }
        }
        if (count($davka) < self::DAVKA) {
            $db->update('newsletter', ['odeslano' => date('Y-m-d H:i:s')], ['idn' => $idn]);

            return true;
        }

        return false;
    }

    /** Úlohy na pozadí: založí automatické vydání, když nastal jeho čas, a pošle další dávku čekajících vydání. */
    public static function naPozadi(App $app): void
    {
        if (!Rozsireni::je($app->settings(), 'newsletter')) {
            return;
        }
        self::automat($app);
        $ceka = $app->db()->value('SELECT idn FROM {newsletter} WHERE odeslano IS NULL AND odeslat_v IS NOT NULL AND odeslat_v <= NOW() ORDER BY idn LIMIT 1');
        if ($ceka !== null) {
            self::davka($app, (int) $ceka);
        }
    }

    /** Automatický výběr: v nastavený den a hodinu vznikne vydání z článků vydaných od posledního newsletteru. */
    private static function automat(App $app): void
    {
        $s = $app->settings();
        $rezim = $s->get('newsletter_auto');
        if (!in_array($rezim, ['tydne', 'denne'], true) || $s->get('newsletter_auto_posledni') === date('Y-m-d') || (int) date('G') < $s->int('newsletter_hodina')
            || ($rezim === 'tydne' && (int) date('N') !== $s->int('newsletter_den'))) {
            return;
        }
        $s->set('newsletter_auto_posledni', date('Y-m-d')); // nejdřív označit: souběžná návštěva nezaloží druhé vydání
        $db = $app->db();
        // každá jazyková verze má vlastní vydání: své články, své odběratele
        foreach (['', ...Jazyk::dalsi($s)] as $jazyk) {
            $od = (string) ($db->value('SELECT MAX(vytvoreno) FROM {newsletter} WHERE jazyk = ?', [$jazyk]) ?? date('Y-m-d H:i:s', time() - ($rezim === 'tydne' ? 7 : 1) * 86400));
            $od = max($od, date('Y-m-d H:i:s', time() - 14 * 86400));
            $ids = array_column($db->all('SELECT idc FROM {clanky} WHERE visible = 1 AND datum <= NOW() AND datum > ? AND typ_clanku = 1 AND noindex = 0 AND jazyk = ? ORDER BY priority DESC, visit DESC, datum DESC LIMIT 8', [$od, $jazyk]), 'idc');
            if ($ids === [] || (int) $db->value('SELECT COUNT(*) FROM {odberatele} WHERE potvrzen = 1 AND jazyk = ?', [$jazyk]) === 0) {
                continue; // nic nového, nebo není komu psát
            }
            $prvni = (string) $db->value('SELECT titulek FROM {clanky} WHERE idc = ?', [$ids[0]]);
            $dalsi = ['' => ' a další – ', 'sk' => ' a ďalšie – ', 'en' => ' and more – ', 'de' => ' und mehr – '][$jazyk === '' ? (Jazyk::vychozi($s) === 'cs' ? '' : Jazyk::vychozi($s)) : $jazyk] ?? ' – ';
            $db->insert('newsletter', [
                'predmet' => mb_substr($prvni . (count($ids) > 1 ? $dalsi : ' – ') . $s->proJazyk('nazev_webu', $jazyk), 0, 200), 'uvod' => $jazyk === '' ? $s->get('newsletter_uvod') : '',
                'clanky' => implode(',', $ids), 'vytvoreno' => date('Y-m-d H:i:s'), 'auto' => 1, 'odeslat_v' => date('Y-m-d H:i:s'), 'jazyk' => $jazyk,
            ]);
        }
    }

    public static function podpis(App $app, string $data): string
    {
        return substr(hash_hmac('sha256', 'newsletter|' . $data, (new Antispam($app->db(), $app->settings()))->klic()), 0, 24);
    }
}
