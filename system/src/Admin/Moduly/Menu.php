<?php

declare(strict_types=1);

namespace Kaleta\Admin\Moduly;

use Kaleta\Admin\Modul;
use Kaleta\Core\Jazyk;
use Kaleta\Core\Menu as MenuWebu;
use Kaleta\Core\Response;

/**
 * Vzhled → Menu: hlavní menu a menu v patičce pro každou jazykovou verzi. Položky jsou stránky, vlastní odkazy,
 * novinky a skupiny, pod každou jedna úroveň podmenu. Dokud hlavní menu nikdo neuloží, skládá se ze stránek „v menu“.
 */
final class Menu extends Modul
{
    public const string IDENT = 'menu';
    public const string NAZEV = 'Menu';
    public const string SKUPINA = 'Vzhled';
    public const string IKONA = 'menu';
    public const bool JEN_ADMIN = true;

    protected function akceVypis(): Response
    {
        [$umisteni, $jazyk] = $this->volba();
        $ulozene = MenuWebu::nacti($this->db, $umisteni, $jazyk);
        $stranky = $this->db->all('SELECT ids, titulek, zobrazit, v_menu FROM {stranky} WHERE jazyk = ? AND smazano IS NULL ORDER BY poradi, titulek', [$jazyk]);
        // automatické hlavní menu se v editoru ukáže tak, jak ho vidí návštěvník – uložením se z něj stane vlastní
        $polozky = $ulozene ?? ($umisteni === 'hlavni'
            ? [...array_map(fn (array $s): array => ['typ' => 'stranka', 'ids' => (int) $s['ids'], 'text' => ''], array_values(array_filter($stranky, fn (array $s): bool => $s['zobrazit'] && $s['v_menu']))), ['typ' => 'novinky', 'text' => '']]
            : []);
        $web = $this->app->settings();
        $jazyky = array_merge([''], Jazyk::dalsi($web));

        return $this->view('vypis', 'Menu', [
            'umisteni' => $umisteni, 'jazyk' => $jazyk, 'automaticke' => $ulozene === null, 'polozky' => $polozky,
            'stranky' => array_map(fn (array $s): array => ['ids' => (int) $s['ids'], 'titulek' => $s['titulek'], 'skryta' => !$s['zobrazit']], $stranky),
            'jazyky' => array_combine($jazyky, array_map(fn (string $j): string => Jazyk::DOSTUPNE[Jazyk::obsahu($web, $j)][0], $jazyky)),
        ]);
    }

    protected function akceUloz(): Response
    {
        [$umisteni, $jazyk] = $this->volba();
        if ($this->request->isPost()) {
            $polozky = json_decode((string) ($_POST['polozky'] ?? ''), true);
            if (!is_array($polozky)) {
                return $this->zpet('Menu se nepodařilo uložit – zkuste to prosím znovu.', '', ['umisteni' => $umisteni, 'jazyk' => $jazyk], 'chyba');
            }
            MenuWebu::uloz($this->db, $umisteni, $jazyk, $polozky);
        }

        return $this->zpet('Menu bylo uloženo.', '', ['umisteni' => $umisteni, 'jazyk' => $jazyk]);
    }

    /** Hlavní menu se vrátí k automatickému skládání ze stránek „v menu“; menu v patičce se vyprázdní. */
    protected function akceAutomaticky(): Response
    {
        [$umisteni, $jazyk] = $this->volba();
        if ($this->request->isPost()) {
            MenuWebu::uloz($this->db, $umisteni, $jazyk, null);
        }

        return $this->zpet($umisteni === 'hlavni' ? 'Menu se zase skládá samo ze stránek zařazených do navigace.' : 'Menu v patičce je prázdné.', '', ['umisteni' => $umisteni, 'jazyk' => $jazyk]);
    }

    /** @return array{0: string, 1: string} umístění a jazyk (sloupec) z adresy */
    private function volba(): array
    {
        $umisteni = $this->request->get('umisteni');
        $jazyk = $this->request->get('jazyk');

        return [isset(MenuWebu::UMISTENI[$umisteni]) ? $umisteni : 'hlavni', in_array($jazyk, Jazyk::dalsi($this->app->settings()), true) ? $jazyk : ''];
    }
}
