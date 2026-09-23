<?php

declare(strict_types=1);

namespace MiroCMS\Admin;

use MiroCMS\Core\App;
use MiroCMS\Core\Db;
use MiroCMS\Core\Request;
use MiroCMS\Core\Response;

/**
 * Předek modulů administrace.
 *
 * Akce z adresy (admin.php?modul=clanky&akce=edit) volá metodu akceEdit().
 * Výchozí akce je "vypis". Nový modul = jedna třída + šablony ve views/admin/<ident>/.
 */
abstract class Modul
{
    /** Identifikátor v adrese a v tabulce práv. */
    public const string IDENT = '';

    /** Titulek v menu. */
    public const string NAZEV = '';

    /** Skupina v menu: Obsah | Vzhled | Správa. */
    public const string SKUPINA = 'Obsah';

    /** Ikona v menu (klíč do sady ve views/admin/ikony.php). */
    public const string IKONA = 'clanek';

    /** Klíč rozšíření (Core\Rozsireni), ke kterému modul patří; prázdné = jádro, nejde vypnout. */
    public const string ROZSIRENI = '';

    /** Modul vidí jen admin (autoři, konfigurace...). */
    public const bool JEN_ADMIN = false;

    /** Modul je dostupný všem přihlášeným bez nastavování práv. */
    public const bool PRO_VSECHNY = false;

    protected readonly App $app;
    protected readonly Db $db;
    protected readonly Request $request;

    public function __construct(protected readonly Kernel $kernel)
    {
        $this->app = $kernel->app;
        $this->db = $this->app->db();
        $this->request = $this->app->request;
    }

    public function handle(string $akce): Response
    {
        $method = 'akce' . str_replace('_', '', ucwords($akce, '_'));
        if (!preg_match('/^[a-z][a-z_]*$/', $akce) || !method_exists($this, $method)) {
            return $this->chyba('Neznámá akce.', 404);
        }

        return $this->$method();
    }

    /** @param array<string, mixed> $data */
    protected function view(string $sablona, string $nadpis, array $data = []): Response
    {
        $data += ['app' => $this->app, 'modul' => $this, 'csrf' => $this->app->session->csrfField()];

        return $this->kernel->page($nadpis, $this->app->view->render('admin/' . static::IDENT . '/' . $sablona, $data));
    }

    protected function chyba(string $text, int $status = 400): Response
    {
        return $this->kernel->page('Chyba', $this->app->view->render('admin/chyba', ['text' => $text]), $status);
    }

    public function app(): App
    {
        return $this->app;
    }

    /** @param array<string, scalar> $params */
    public function url(string $akce = '', array $params = []): string
    {
        $query = ['modul' => static::IDENT] + ($akce !== '' ? ['akce' => $akce] : []) + $params;

        return $this->app->url('admin.php?' . http_build_query($query));
    }

    /** Přesměrování zpět do modulu s hláškou (vzor Post/Redirect/Get). */
    /** Návrat na stránku webu po úpravě „přímo na webu“: jen místní cesta pod kořenem webu, nikdy cizí adresa. */
    protected function zpetNaWeb(string $cil, string $dodatek = ''): Response
    {
        $koren = $this->app->request->basePath() . '/';
        $mistni = str_starts_with($cil, $koren) && !str_starts_with($cil, '//') && !str_contains($cil, '\\') && !preg_match('#[\r\n]|^/[/\\\\]#', $cil);

        return Response::redirect(($mistni ? strtok($cil, '?#') : $koren) . ($mistni ? $dodatek : ''), 303);
    }

    protected function zpet(string $hlaska = '', string $akce = '', array $params = [], string $typ = 'ok'): Response
    {
        if ($hlaska !== '') {
            $this->app->session->flash($typ, $hlaska);
        }

        return Response::redirect($this->url($akce, $params));
    }
}
