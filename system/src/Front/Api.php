<?php

declare(strict_types=1);

namespace Kaleta\Front;

use Kaleta\Core\App;
use Kaleta\Core\Jazyk;
use Kaleta\Core\Response;

/**
 * Veřejné čtecí API (JSON). Vrací jen to, co je vidět i na webu: vydané novinky, kategorie a zobrazené stránky.
 *
 *   GET /api/novinky?strana=1&kategorie=<adresa>   seznam novinek
 *   GET /api/novinky/<adresa>                      celá novinka
 *   GET /api/kategorie                             kategorie novinek
 *   GET /api/stranky                               stránky webu (bez textu)
 */
final class Api
{
    public function __construct(private readonly App $app, private readonly Clanky $novinky)
    {
    }

    public function handle(string $path): Response
    {
        $koren = $this->app->request->origin() . $this->app->url('');
        $strucne = fn (array $c): array => [
            'id' => (int) $c['idc'], 'titulek' => $c['titulek'], 'adresa' => $koren . 'novinky/' . $c['seo_link'], 'api' => $koren . 'api/novinky/' . $c['seo_link'],
            'perex' => trim(strip_tags($c['uvod'])), 'obrazek' => $c['obrazek'] === '' ? null : (preg_match('#^https?://#i', $c['obrazek']) ? $c['obrazek'] : $this->app->request->origin() . $c['obrazek']),
            'kategorie' => ['nazev' => $c['tema_jm'], 'adresa' => $c['tema_seo']], 'autor' => $c['autor_jm'], 'vydano' => date('c', strtotime($c['datum'])),
        ];

        if ((str_starts_with($path, '/api/novinky') || $path === '/api/kategorie') && !\Kaleta\Core\Rozsireni::je($this->app->settings(), 'novinky')) {
            return $this->json(['chyba' => t('Novinky jsou na tomto webu vypnuté.')], 404);
        }
        if ($path === '/api/novinky') {
            $strana = max(1, $this->app->request->getInt('strana', 1));
            $kategorie = $this->app->request->get('kategorie');
            $idt = $kategorie === '' ? null : $this->app->db()->value('SELECT idt FROM {kategorie} WHERE seo_link = ?', [$kategorie]);
            if ($kategorie !== '' && $idt === null) {
                return $this->json(['chyba' => t('Kategorie neexistuje.')], 404);
            }
            [$novinky, $celkem] = $idt === null ? $this->novinky->vypis($strana) : $this->novinky->zKategorie((int) $idt, $strana);

            return $this->json(['celkem' => $celkem, 'strana' => $strana, 'na_stranku' => $this->novinky->naStranku(), 'novinky' => array_map($strucne, $novinky)]);
        }
        if (preg_match('#^/api/novinky/([a-z0-9-]+)$#', $path, $m)) {
            $c = $this->novinky->podleSeo($m[1]);

            return $c === null ? $this->json(['chyba' => t('Novinka neexistuje.')], 404) : $this->json($strucne($c) + [
                'uvod_html' => $c['uvod'], 'text_html' => $c['text'], 'aktualizovano' => $c['aktualizovano'] ? date('c', strtotime($c['aktualizovano'])) : null,
                'stitky' => array_column($this->app->db()->all('SELECT s.nazev FROM {stitky} s JOIN {novinky_stitky} cs ON cs.ids = s.ids WHERE cs.idc = ?', [$c['idc']]), 'nazev'),
            ]);
        }
        if ($path === '/api/kategorie') {
            return $this->json(array_map(
                fn (array $r): array => ['nazev' => $r['nazev'], 'adresa' => $r['seo_link'], 'novinek' => (int) $r['pocet_clanku']],
                \Kaleta\Admin\Moduly\Kategorie::seznam($this->app->db(), Jazyk::sloupecWebu()),
            ));
        }
        if ($path === '/api/stranky') {
            return $this->json(array_map(
                fn (array $s): array => ['titulek' => $s['titulek'], 'adresa' => $koren . $s['seo_link'], 'popis' => $s['popis']],
                $this->app->db()->all('SELECT titulek, seo_link, popis FROM {stranky} WHERE zobrazit = 1 AND jazyk = ? ORDER BY poradi, titulek', [Jazyk::sloupecWebu()]),
            ));
        }

        return $this->json(['chyba' => t('Neznámá adresa API.')], 404);
    }

    private function json(mixed $data, int $status = 200): Response
    {
        return new Response((string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $status, [
            'Content-Type' => 'application/json; charset=utf-8', 'Access-Control-Allow-Origin' => '*', 'Cache-Control' => 'public, max-age=60',
        ]);
    }
}
