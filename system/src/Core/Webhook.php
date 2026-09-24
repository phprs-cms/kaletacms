<?php

declare(strict_types=1);

namespace MiroCMS\Core;

/**
 * Webhook po vydání novinky: pošle údaje o novince na adresu z Nastavení (Make, Zapier, IFTTT, n8n...).
 * Přes takovou službu jde novinku automaticky sdílet na Facebook, X, Mastodon, Bluesky, do Slacku apod.
 */
final class Webhook
{
    public static function clanekVydan(App $app, int $idc): void
    {
        $adresa = $app->settings()->get('webhook_url');
        if (!preg_match('#^https://#i', $adresa)) {
            return;
        }
        $c = $app->db()->one(
            'SELECT c.*, t.nazev AS kategorie FROM {novinky} c JOIN {kategorie} t ON t.idt = c.tema WHERE c.idc = ? AND c.visible = 1 AND c.datum <= NOW() AND c.noindex = 0',
            [$idc],
        );
        if ($c === null) {
            return; // koncept nebo novinka naplánovaná do budoucna
        }
        $koren = $app->request->origin() . $app->request->basePath() . '/'; // soubory jsou společné všem jazykům
        $data = [
            'udalost' => 'novinka_vydana', 'web' => $app->settings()->get('nazev_webu'), 'titulek' => $c['titulek'],
            'adresa' => $app->request->origin() . $app->urlNovinky($c['seo_link'], $c['jazyk']), 'perex' => trim(strip_tags($c['uvod'])), 'kategorie' => $c['kategorie'],
            'obrazek' => $c['obrazek'] === '' ? '' : (preg_match('#^https?://#i', $c['obrazek']) ? $c['obrazek'] : rtrim($koren, '/') . '/' . ltrim($c['obrazek'], '/')),
            'stitky' => array_column($app->db()->all('SELECT s.nazev FROM {stitky} s JOIN {novinky_stitky} cs ON cs.ids = s.ids WHERE cs.idc = ?', [$idc]), 'nazev'),
            'vydano' => date('c', strtotime($c['datum'])),
        ];
        @file_get_contents($adresa, false, stream_context_create(['http' => [
            'method' => 'POST', 'timeout' => 4, 'ignore_errors' => true,
            'header' => "Content-Type: application/json; charset=utf-8\r\nUser-Agent: MiroCMS/" . MIROCMS_VERSION . "\r\n",
            'content' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]]));
    }
}
