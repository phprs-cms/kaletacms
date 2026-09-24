<?php

declare(strict_types=1);

namespace MiroCMS\Front;

use MiroCMS\Core\Antispam;
use MiroCMS\Core\App;

/**
 * Vlastní měření návštěvnosti bez cookies.
 * Návštěvník = otisk (IP + prohlížeč + sůl platná jeden den); samotná IP se nikam neukládá
 * a otisky starší než dva dny se mažou, takže čtenáře nejde sledovat v čase.
 */
final class Statistika
{
    private const string ROBOTI = '/bot|crawl|spider|slurp|preview|monitor|curl|wget|python|java\/|http|scan|check|feed|lighthouse|headless/i';

    public static function zaznamenej(App $app, ?int $idc): void
    {
        $server = $_SERVER;
        $ua = (string) ($server['HTTP_USER_AGENT'] ?? '');
        if (!\MiroCMS\Core\Rozsireni::je($app->settings(), 'statistika') || !$app->settings()->bool('statistika') || $ua === '' || preg_match(self::ROBOTI, $ua) || $app->request->get('nahled') !== '') {
            return;
        }
        $db = $app->db();
        $dnes = date('Y-m-d');
        $sul = (new Antispam($db, $app->settings()))->klic() . $dnes;
        $otisk = substr(hash('sha256', $sul . '|' . $app->request->ip() . '|' . $ua), 0, 32);

        $novy = $db->run('INSERT IGNORE INTO {stat_navstevnici} (den, otisk) VALUES (?, ?)', [$dnes, $otisk])->rowCount() === 1;
        $db->run('INSERT INTO {stat_dny} (den, navstevy, zobrazeni) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE navstevy = navstevy + VALUES(navstevy), zobrazeni = zobrazeni + 1', [$dnes, (int) $novy]);
        if ($idc !== null) {
            $db->run('INSERT INTO {stat_novinky} (den, idc, pocet) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE pocet = pocet + 1', [$dnes, $idc]);
        }
        $zdroj = strtolower((string) parse_url((string) ($server['HTTP_REFERER'] ?? ''), PHP_URL_HOST));
        $zdroj = preg_replace('/^www\./', '', $zdroj) ?? '';
        // vlastní web je nastavená adresa webu, ne hlavička Host – tu si může klient napsat, jak chce
        $vlastni = preg_replace('/^www\./', '', strtolower((string) parse_url($app->request->origin(), PHP_URL_HOST))) ?? '';
        if ($novy && $zdroj !== '' && $zdroj !== $vlastni) {
            $db->run('INSERT INTO {stat_zdroje} (den, zdroj, pocet) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE pocet = pocet + 1', [$dnes, mb_substr($zdroj, 0, 100)]);
        }
        if (random_int(1, 200) === 1) {
            $db->run('DELETE FROM {stat_navstevnici} WHERE den < CURDATE() - INTERVAL 1 DAY');
        }
    }
}
