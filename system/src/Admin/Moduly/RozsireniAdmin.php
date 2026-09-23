<?php

declare(strict_types=1);

namespace MiroCMS\Admin\Moduly;

use MiroCMS\Core\Response;

/**
 * Rozšíření jako samostatná položka hlavní nabídky (dřív záložka Nastavení).
 *
 * Obrazovka i ukládání jsou tytéž jako u záložek Nastavení - modul jen drží pevnou „záložku“ rozsireni,
 * vykresluje šablony ze složky config/ a vrací se na vlastní adresu (admin.php?modul=rozsireni).
 */
final class RozsireniAdmin extends Konfigurace
{
    public const string IDENT = 'rozsireni';
    public const string NAZEV = 'Rozšíření';
    public const string IKONA = 'rozsireni';

    protected function zalozka(string $zalozka): string
    {
        return 'rozsireni';
    }

    protected function view(string $sablona, string $nadpis, array $data = []): Response
    {
        $data += ['app' => $this->app, 'modul' => $this, 'csrf' => $this->app->session->csrfField()];

        return $this->kernel->page(self::NAZEV, $this->app->view->render('admin/config/' . $sablona, $data));
    }
}
