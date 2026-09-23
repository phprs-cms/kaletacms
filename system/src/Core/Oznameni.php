<?php

declare(strict_types=1);

namespace MiroCMS\Core;

/**
 * Oznámení o vydání článku: webhook, IndexNow a Web Push. Volá se hned po vydání v administraci
 * a také po návštěvách webu (nejvýše jednou za minutu) - díky tomu se oznámí i články naplánované do
 * budoucna a články vydané přes Claude (MCP), jakmile jejich čas nastane.
 */
final class Oznameni
{
    /** Po odeslání stránky čtenáři (index.php). */
    public static function naPozadi(App $app): void
    {
        $s = $app->settings();
        $push = new Push($app->db(), $s);
        $ceka = !in_array($s->get('push_ukazatel'), ['', 'hotovo'], true);
        if (!$ceka && time() - $s->int('oznameni_kontrola') < 60) {
            return;
        }
        $s->set('oznameni_kontrola', (string) time());
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        ignore_user_abort(true);
        try {
            self::zpracuj($app);
            $push->rozesli();
            Posta::zpracujFrontu($s);
            Rozesilka::naPozadi($app);
            Odkazy::naPozadi($app);
        } catch (\Throwable) {
            // oznámení nesmí shodit web; další pokus proběhne při příští návštěvě
        }
    }

    /** Oznámí všechny vydané a dosud neoznámené články (nejvýš 2 dny staré, aby se po výpadku nerozeslal archiv). */
    public static function zpracuj(App $app): void
    {
        $db = $app->db();
        $clanky = $db->all('SELECT idc, titulek, uvod, seo_link, jazyk, obrazek, noindex, pristup FROM {clanky} WHERE visible = 1 AND datum <= NOW() AND oznameno IS NULL ORDER BY datum LIMIT 5');
        foreach ($clanky as $c) {
            // nejdřív označit: kdyby oznámení spadlo, nesmí se opakovat donekonečna
            if ($db->run('UPDATE {clanky} SET oznameno = NOW() WHERE idc = ? AND oznameno IS NULL', [$c['idc']])->rowCount() === 0) {
                continue;
            }
            \MiroCMS\Front\Cache::vymaz(); // naplánovaný článek právě vyšel - hlavní stránka z cache ho ještě nezná
            if ($c['noindex'] || (int) $db->value('SELECT datum < NOW() - INTERVAL 2 DAY FROM {clanky} WHERE idc = ?', [$c['idc']]) === 1) {
                continue;
            }
            Webhook::clanekVydan($app, (int) $c['idc']);
            (new \MiroCMS\Front\Seo($app))->indexNow($app->urlClanku($c['seo_link'], $c['jazyk']));
            $push = new Push($db, $app->settings());
            if ($push->zapnuto()) {
                $koren = $app->request->origin() . $app->request->basePath() . '/'; // soubory jsou společné všem jazykům
                $push->oznam([
                    'titulek' => $c['titulek'],
                    'text' => mb_strimwidth(trim(html_entity_decode(strip_tags($c['uvod']), ENT_QUOTES | ENT_HTML5)), 0, 160, '…'),
                    'url' => $app->request->origin() . $app->urlClanku($c['seo_link'], $c['jazyk']),
                    'obrazek' => $c['obrazek'] === '' ? '' : (preg_match('#^https?://#i', $c['obrazek']) ? $c['obrazek'] : rtrim($koren, '/') . '/' . ltrim($c['obrazek'], '/')),
                ]);
                $push->rozesli();
            }
        }
    }
}
