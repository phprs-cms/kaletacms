<?php

declare(strict_types=1);

namespace MiroCMS\Front;

use MiroCMS\Admin\Moduly\Rubriky;
use MiroCMS\Core\App;
use MiroCMS\Core\Response;

/**
 * Veřejné čtecí API (JSON). Vrací jen to, co je vidět i na webu: vydané články a zobrazené rubriky.
 *
 *   GET /api/clanky?strana=1&rubrika=<adresa>   seznam článků
 *   GET /api/clanky/<adresa>                    celý článek
 *   GET /api/rubriky                            strom rubrik
 */
final class Api
{
    public function __construct(private readonly App $app, private readonly Clanky $clanky)
    {
    }

    public function handle(string $path): Response
    {
        $koren = $this->app->request->origin() . $this->app->url('');
        $strucne = fn (array $c): array => [
            'id' => (int) $c['idc'], 'titulek' => $c['titulek'], 'adresa' => $koren . 'clanek/' . $c['seo_link'], 'api' => $koren . 'api/clanky/' . $c['seo_link'],
            'perex' => trim(strip_tags($c['uvod'])), 'obrazek' => $c['obrazek'] === '' ? null : (preg_match('#^https?://#i', $c['obrazek']) ? $c['obrazek'] : $this->app->request->origin() . $c['obrazek']),
            'rubrika' => ['nazev' => $c['tema_jm'], 'adresa' => $c['tema_seo']], 'autor' => $c['autor_jm'], 'vydano' => date('c', strtotime($c['datum'])),
        ];

        if ($path === '/api/clanky') {
            $strana = max(1, $this->app->request->getInt('strana', 1));
            $rubrika = $this->app->request->get('rubrika');
            $idt = $rubrika === '' ? null : $this->app->db()->value('SELECT idt FROM {topic} WHERE seo_link = ?', [$rubrika]);
            if ($rubrika !== '' && $idt === null) {
                return $this->json(['chyba' => t('Rubrika neexistuje.')], 404);
            }
            [$clanky, $celkem] = $idt === null ? $this->clanky->naHlavniStranku($strana) : $this->clanky->zRubriky((int) $idt, $strana);

            return $this->json(['celkem' => $celkem, 'strana' => $strana, 'na_stranku' => $this->clanky->naStranku(), 'clanky' => array_map($strucne, $clanky)]);
        }
        if (preg_match('#^/api/clanky/([a-z0-9-]+)$#', $path, $m)) {
            $c = $this->clanky->podleSeo($m[1]);

            return $c === null ? $this->json(['chyba' => t('Článek neexistuje.')], 404) : $this->json($strucne($c) + [
                'uvod_html' => $c['uvod'], 'text_html' => $c['text'], 'zamceno' => !empty($c['zamceno']), 'aktualizovano' => $c['aktualizovano'] ? date('c', strtotime($c['aktualizovano'])) : null,
                'stitky' => array_column($this->app->db()->all('SELECT s.nazev FROM {stitky} s JOIN {clanky_stitky} cs ON cs.ids = s.ids WHERE cs.idc = ?', [$c['idc']]), 'nazev'),
            ]);
        }
        if ($path === '/api/rubriky') {
            return $this->json(array_map(fn (array $r): array => ['nazev' => $r['nazev'], 'adresa' => $r['seo_link'], 'uroven' => $r['uroven'], 'clanku' => (int) $r['pocet_clanku']], Rubriky::strom($this->app->db(), true)));
        }

        return $this->json(['chyba' => t('Neznámá adresa API.')], 404);
    }

    private function json(mixed $data, int $status = 200): Response
    {
        return new Response((string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $status, [
            'Content-Type' => 'application/json; charset=utf-8', 'Access-Control-Allow-Origin' => '*', 'Cache-Control' => isset($_COOKIE[Ctenari::COOKIE]) ? 'private, no-store' : 'public, max-age=60',
        ]);
    }
}
