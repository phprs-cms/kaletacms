<?php

declare(strict_types=1);

namespace MiroCMS\Front;

use MiroCMS\Core\App;
use MiroCMS\Core\Response;
use MiroCMS\Core\Rozsireni;

/**
 * Výdej reklamy na pozici: vybere jednu běžící reklamu (náhodně podle váhy), započítá zobrazení
 * a vrátí HTML s označením "Reklama". Prokliky bannerů jdou přes /r/<id>, kde se počítají.
 */
final class Reklama
{
    public function __construct(private readonly App $app)
    {
    }

    /** @param int|null $rubrika rubrika zobrazené stránky (výpis rubriky nebo článek) - kvůli reklamám cíleným na rubriku */
    public function html(string $pozice, ?int $rubrika = null): string
    {
        if (!Rozsireni::je($this->app->settings(), 'reklama') || $this->app->request->get('nahled') !== '') {
            return '';
        }
        $db = $this->app->db();
        $bezici = $db->all(
            'SELECT * FROM {reklama} WHERE pozice = ? AND aktivni = 1 AND (platna_od IS NULL OR platna_od <= NOW())
             AND (platna_do IS NULL OR platna_do > NOW()) AND (max_zobrazeni IS NULL OR zobrazeni < max_zobrazeni)
             AND (jen_rubrika IS NULL OR jen_rubrika = ?)',
            [$pozice, $rubrika ?? 0],
        );
        if ($bezici === []) {
            return '';
        }
        $los = random_int(1, (int) array_sum(array_column($bezici, 'vaha')));
        foreach ($bezici as $r) {
            if (($los -= (int) $r['vaha']) <= 0) {
                break;
            }
        }
        $db->run('UPDATE {reklama} SET zobrazeni = zobrazeni + 1 WHERE idr = ?', [$r['idr']]);

        if ($r['typ'] === 'kod') {
            $s = $this->app->settings();
            // kód reklamní sítě čeká na souhlas s marketingem stejně jako ostatní marketingové kódy
            $obsah = Seo::cekaNaSouhlas((string) $r['kod'], $s->get('cookies_rezim'));
        } else {
            $src = preg_match('#^(https?:)?/#', $r['obrazek']) ? $r['obrazek'] : $this->app->url($r['obrazek']);
            $obsah = '<a href="' . e($this->app->url('r/' . (int) $r['idr'])) . '" rel="sponsored noopener" target="_blank"><img src="' . e($src) . '" alt="' . e($r['nazev']) . '" loading="lazy"></a>';
        }

        // cílení na zařízení řeší styl (třídy jen-mobil / jen-pocitac z Front\Seo) - stránka z cache je pro všechny stejná
        return '<aside class="reklama reklama-' . e($pozice) . (in_array($r['zarizeni'], ['mobil', 'pocitac'], true) ? ' jen-' . $r['zarizeni'] : '') . '" aria-label="' . e(t('Reklama')) . '"><span class="reklama-oznaceni">' . e(t('Reklama')) . '</span>' . $obsah . '</aside>';
    }

    /** Proklik banneru: započítat a přesměrovat na cíl. */
    public function proklik(int $idr): Response
    {
        $r = $this->app->db()->one("SELECT idr, cil_url FROM {reklama} WHERE idr = ? AND typ = 'obrazek'", [$idr]);
        if ($r === null || !preg_match('#^https?://#i', $r['cil_url'])) {
            return Response::redirect($this->app->url(''));
        }
        if (!preg_match('/bot|crawl|spider|preview/i', (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''))) {
            $this->app->db()->run('UPDATE {reklama} SET kliky = kliky + 1 WHERE idr = ?', [$r['idr']]);
        }

        return new Response('', 302, ['Location' => $r['cil_url'], 'X-Robots-Tag' => 'noindex, nofollow']);
    }
}
