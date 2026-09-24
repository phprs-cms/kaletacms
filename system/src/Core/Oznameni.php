<?php

declare(strict_types=1);

namespace MiroCMS\Core;

/**
 * Oznámení o vydání novinky: webhook a IndexNow. Volá se hned po vydání v administraci a také po návštěvách
 * webu (nejvýše jednou za minutu) - díky tomu se oznámí i novinky naplánované do budoucna a novinky vydané
 * přes Claude (MCP), jakmile jejich čas nastane. Na pozadí se přitom odešle i fronta pošty a zkontrolují odkazy.
 */
final class Oznameni
{
    /** Po odeslání stránky návštěvníkovi (index.php). */
    public static function naPozadi(App $app): void
    {
        $s = $app->settings();
        if (time() - $s->int('oznameni_kontrola') < 60) {
            return;
        }
        $s->set('oznameni_kontrola', (string) time());
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        ignore_user_abort(true);
        try {
            self::zpracuj($app);
            Posta::zpracujFrontu($s);
            Odkazy::naPozadi($app);
        } catch (\Throwable) {
            // oznámení nesmí shodit web; další pokus proběhne při příští návštěvě
        }
    }

    /** Oznámí všechny vydané a dosud neoznámené novinky (nejvýš 2 dny staré, aby se po výpadku nerozeslal archiv). */
    public static function zpracuj(App $app): void
    {
        $db = $app->db();
        $clanky = $db->all('SELECT idc, seo_link, jazyk, noindex FROM {clanky} WHERE visible = 1 AND datum <= NOW() AND oznameno IS NULL ORDER BY datum LIMIT 5');
        foreach ($clanky as $c) {
            // nejdřív označit: kdyby oznámení spadlo, nesmí se opakovat donekonečna
            if ($db->run('UPDATE {clanky} SET oznameno = NOW() WHERE idc = ? AND oznameno IS NULL', [$c['idc']])->rowCount() === 0) {
                continue;
            }
            \MiroCMS\Front\Cache::vymaz(); // naplánovaná novinka právě vyšla - výpis z cache ji ještě nezná
            if ($c['noindex'] || (int) $db->value('SELECT datum < NOW() - INTERVAL 2 DAY FROM {clanky} WHERE idc = ?', [$c['idc']]) === 1) {
                continue;
            }
            Webhook::clanekVydan($app, (int) $c['idc']);
            (new \MiroCMS\Front\Seo($app))->indexNow($app->urlNovinky($c['seo_link'], $c['jazyk']));
        }
    }
}
