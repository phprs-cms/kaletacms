<?php

declare(strict_types=1);

namespace MiroCMS\Admin\Moduly;

use MiroCMS\Admin\Modul;
use MiroCMS\Core\Obrazky;
use MiroCMS\Core\Response;
use MiroCMS\Front\Layouty;
use MiroCMS\Stavitel\DesignSystem;

/**
 * Vzhled webu: šablona, logo a design systém (barvy, písma, velikosti, šířka, zaoblení) s živým náhledem úvodní stránky.
 * Z design systému berou tokeny šablona i stavitel, takže změna tady přebarví celý web.
 */
final class Vzhled extends Modul
{
    public const string IDENT = 'vzhled';
    public const string NAZEV = 'Vzhled webu';
    public const string SKUPINA = 'Vzhled';
    public const string IKONA = 'identita';
    public const bool JEN_ADMIN = true;

    protected function akceVypis(): Response
    {
        $web = $this->app->settings();
        $ds = DesignSystem::nacti($web);

        return $this->view('vypis', 'Vzhled webu', [
            'layouty' => Layouty::seznam(),
            'ds' => $ds,
            'kontrasty' => DesignSystem::kontrasty($ds),
            'predvolby' => array_map(fn (string $k): array => ['nazev' => DesignSystem::PREDVOLBY[$k][0], 'popis' => DesignSystem::PREDVOLBY[$k][1], 'ds' => DesignSystem::predvolba($k)], array_combine(array_keys(DesignSystem::PREDVOLBY), array_keys(DesignSystem::PREDVOLBY))),
            'hodnoty' => ['layout' => $web->get('layout'), 'logo_webu' => $web->get('logo_webu'), 'favicon' => $web->get('favicon'), 'tmavy_rezim' => $web->get('tmavy_rezim'), 'nazev_webu' => $web->get('nazev_webu')],
        ]);
    }

    protected function akceUloz(): Response
    {
        if (!$this->request->isPost()) {
            return $this->zpet();
        }
        $r = $this->request;
        $web = $this->app->settings();
        if (isset(Layouty::seznam()[$r->post('layout')])) {
            $web->set('layout', $r->post('layout'));
        }
        $web->set('logo_webu', mb_substr($r->post('logo_webu'), 0, 255));
        $ikona = mb_substr($r->post('favicon'), 0, 255);
        if ($ikona !== $web->get('favicon') || ($ikona !== '' && !is_file(MIROCMS_ROOT . '/media/ikona-180.png'))) {
            // ikony pro telefony a instalaci webu se připraví z ikony jednou při uložení
            $ok = $ikona !== '' && preg_match('#^/?(?:[A-Za-z0-9_.-]+/){0,3}(media/[A-Za-z0-9/_.-]+)$#', $ikona, $m) && !str_contains($m[1], '..') && Obrazky::ikony(MIROCMS_ROOT . '/' . $m[1]);
            if (!$ok) {
                array_map(fn (int $n): bool => @unlink(MIROCMS_ROOT . '/media/ikona-' . $n . '.png'), Obrazky::IKONY);
            }
        }
        $web->set('favicon', $ikona);
        $web->set('tmavy_rezim', $r->post('tmavy_rezim') === 'auto' ? 'auto' : 'vypnuto');
        $web->set('design_system', (string) json_encode($this->zFormulare(), JSON_UNESCAPED_SLASHES));
        // starší klíče Identity: od uložení design systému se nečtou, ať nemate export ani jiné nástroje
        $web->set('brand_akcent', '');
        $web->set('brand_pismo_titulky', 'vychozi');
        $web->set('brand_pismo_text', 'vychozi');
        \MiroCMS\Front\Cache::vymaz();

        return $this->zpet('Vzhled webu byl uložen.');
    }

    /** Živý náhled: CSS tokenů a kontrola čitelnosti pro rozpracovaný formulář (JSON). Nic neukládá. */
    protected function akceNahled(): Response
    {
        $ds = $this->zFormulare();

        return Response::json(['css' => DesignSystem::css($ds), 'kontrasty' => array_map(fn (array $k): array => ['popis' => t($k['popis'])] + $k, DesignSystem::kontrasty($ds))]);
    }

    /** @return array<string, mixed> */
    private function zFormulare(): array
    {
        $ds = is_array($_POST['ds'] ?? null) ? $_POST['ds'] : [];
        // velikosti se ve formuláři zadávají v pixelech, design systém je drží v rem
        foreach (['zaklad_min', 'zaklad_max', 'sirka', 'sirka_textu'] as $klic) {
            if (isset($ds[$klic]) && is_numeric($ds[$klic])) {
                $ds[$klic] = (float) $ds[$klic] / 16;
            }
        }

        return DesignSystem::vycisti($ds);
    }
}
