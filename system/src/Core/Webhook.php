<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Webhooky: po vydání novinky a po nové poptávce pošle údaje na adresy z Nastavení (Make, Zapier, IFTTT, n8n, CRM…).
 * Přes takovou službu jde novinku automaticky sdílet na sítě a poptávku založit v CRM nebo poslat do Slacku.
 */
final class Webhook
{
    /** Nová poptávka z formuláře webu → adresa z Nastavení (CRM, Make, Zapier, n8n, Slack…). */
    public static function poptavka(App $app, int $idp, string $formular, array $data, string $email, string $stranka): void
    {
        $adresa = $app->settings()->get('webhook_poptavky');
        if (!preg_match('#^https://#i', $adresa)) {
            return;
        }
        self::posli($adresa, [
            'udalost' => 'nova_poptavka', 'web' => $app->settings()->get('nazev_webu'), 'id' => $idp, 'formular' => $formular, 'email' => $email,
            'stranka' => $app->request->origin() . $stranka, 'prijato' => date('c'),
            'pole' => array_map(fn (array $d): array => ['popisek' => $d[0], 'hodnota' => $d[1]], $data),
        ]);
    }

    /** @param array<string, mixed> $data */
    private static function posli(string $adresa, array $data): void
    {
        @file_get_contents($adresa, false, stream_context_create(['http' => [
            'method' => 'POST', 'timeout' => 4, 'ignore_errors' => true,
            'header' => "Content-Type: application/json; charset=utf-8\r\nUser-Agent: Kaleta/" . KALETA_VERSION . "\r\n",
            'content' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]]));
    }

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
        self::posli($adresa, $data);
    }
}
