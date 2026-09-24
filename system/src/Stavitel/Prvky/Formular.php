<?php

declare(strict_types=1);

namespace MiroCMS\Stavitel\Prvky;

use MiroCMS\Core\Antispam;
use MiroCMS\Stavitel\Kontext;
use MiroCMS\Stavitel\Prvek;

/**
 * Poptávkový / kontaktní formulář. Odesílá se na /formular (Front\Formulare): server vezme pole z publikované stavby
 * (ne z prohlížeče), ověří je, uloží poptávku (Administrace → Poptávky) a pošle upozornění e-mailem.
 * Ochrana bez cookies a CAPTCHA (Core\Antispam), takže stránka s formulářem zůstává v cache.
 */
final class Formular extends Prvek
{
    public const string TYP = 'formular';
    public const string NAZEV = 'Formulář';
    public const string POPIS = 'Poptávka nebo dotaz – odeslané zprávy najdete v Poptávkách a přijdou i e-mailem.';
    public const string IKONA = 'formular';
    public const string SKUPINA = 'Dynamické';
    public const array ZNACKY = ['form'];

    /** Typy polí formuláře. */
    public const array TYPY_POLI = ['text' => 'text', 'email' => 'e-mail', 'tel' => 'telefon', 'textarea' => 'delší text', 'vyber' => 'výběr', 'souhlas' => 'zaškrtnutí (souhlas)'];

    public static function vlastnosti(): array
    {
        return [
            'nazev' => ['typ' => 'text', 'popisek' => 'Název formuláře (v Poptávkách a v e-mailu)', 'vychozi' => t('Poptávka'), 'max' => 120],
            'pole' => ['typ' => 'polozky', 'popisek' => 'Pole formuláře', 'max' => 20, 'pole' => [
                'popisek' => ['typ' => 'text', 'popisek' => 'Popisek', 'vychozi' => '', 'max' => 200],
                'typ' => ['typ' => 'vyber', 'popisek' => 'Typ', 'vychozi' => 'text', 'moznosti' => self::TYPY_POLI],
                'povinne' => ['typ' => 'prepinac', 'popisek' => 'Povinné', 'vychozi' => false],
                'moznosti' => ['typ' => 'radky', 'popisek' => 'Možnosti výběru (každá na řádek)', 'vychozi' => '', 'max' => 2000, 'kdyz' => ['typ' => 'vyber']],
            ], 'vychozi' => [
                ['popisek' => t('Jméno'), 'typ' => 'text', 'povinne' => true, 'moznosti' => ''],
                ['popisek' => t('E-mail'), 'typ' => 'email', 'povinne' => true, 'moznosti' => ''],
                ['popisek' => t('Telefon'), 'typ' => 'tel', 'povinne' => false, 'moznosti' => ''],
                ['popisek' => t('Co pro vás můžeme udělat?'), 'typ' => 'textarea', 'povinne' => true, 'moznosti' => ''],
                ['popisek' => t('Souhlasím se zpracováním osobních údajů za účelem vyřízení poptávky.'), 'typ' => 'souhlas', 'povinne' => true, 'moznosti' => ''],
            ]],
            'tlacitko' => ['typ' => 'text', 'popisek' => 'Text tlačítka', 'vychozi' => t('Odeslat poptávku'), 'max' => 80],
            'dekujeme' => ['typ' => 'text', 'popisek' => 'Poděkování po odeslání', 'vychozi' => t('Děkujeme, zprávu jsme dostali. Ozveme se vám co nejdřív.'), 'max' => 400],
            'prijemce' => ['typ' => 'text', 'popisek' => 'E-mail pro upozornění (prázdné = e-mail webu z Nastavení)', 'vychozi' => '', 'max' => 190],
        ];
    }

    public static function zakladniCss(): string
    {
        return '.mc-formular { display: grid; gap: var(--mc-mezera-s); }
.mc-pole { display: grid; gap: var(--mc-mezera-2xs); margin: 0; }
.mc-pole > label { font-weight: 600; }
.mc-pole input:not([type="checkbox"]), .mc-pole select, .mc-pole textarea { box-sizing: border-box; width: 100%; padding: 0.7em 0.9em; border: 1px solid var(--mc-barva-linka); border-radius: var(--mc-zaobleni); background: var(--mc-barva-pozadi); color: var(--mc-barva-text); font: inherit; }
.mc-pole textarea { min-height: 8em; resize: vertical; }
.mc-pole :focus-visible { outline: 2px solid var(--mc-barva-primarni); outline-offset: 1px; }
.mc-pole-souhlas label { display: flex; gap: var(--mc-mezera-xs); align-items: flex-start; }
.mc-pole-souhlas input { margin-block-start: 0.3em; accent-color: var(--mc-barva-primarni); }
.mc-povinne { color: var(--mc-barva-primarni); }
.mc-formular-hotovo, .mc-formular-chyba { margin: 0; padding: var(--mc-mezera-m); border-radius: var(--mc-zaobleni); }
.mc-formular-hotovo { background: var(--mc-barva-primarni-jemna); color: var(--mc-barva-text); }
.mc-formular-chyba { background: color-mix(in oklch, #c4281c 12%, var(--mc-barva-pozadi)); color: color-mix(in oklch, #c4281c 80%, var(--mc-barva-text)); }';
    }

    /** Kotva formuláře (kam se po odeslání vrátí stránka): stejná jako id, které formulář dostane při vykreslení. */
    public static function kotva(array $p): string
    {
        return $p['kotva'] ?? (!empty($p['styl']) ? 's-' . $p['id'] : 'formular-' . $p['id']);
    }

    /** Hlášení po odeslání podle kódu v adrese (?formular=<id>&vysledek=<kód>) – text nikdy nejde z adresy. */
    public static function hlaseni(string $kod): string
    {
        return match ($kod) {
            'pole' => t('Zkontrolujte prosím vyplnění povinných polí a e-mailové adresy.'),
            'limit' => t('Z vaší adresy přišlo v krátké době příliš mnoho zpráv. Zkuste to prosím později.'),
            'overeni' => t('Formulář se nepodařilo ověřit. Obnovte stránku a zkuste to znovu.'),
            default => t('Zprávu se nepodařilo odeslat. Zkuste to prosím znovu.'),
        };
    }

    public static function vykresli(array $p, string $a, string $deti, Kontext $k): string
    {
        $o = $p['obsah'];
        $r = $k->app->request;
        $vysledek = $r->get('formular') === $p['id'] ? $r->get('vysledek') : '';
        $id = str_contains($a, ' id="') ? '' : ' id="' . e(self::kotva($p)) . '"';
        if ($vysledek === 'ok') {
            return '<div' . Text::sTridou($a, 'mc-formular-hotovo') . $id . ' role="status"><p>' . e($o['dekujeme']) . '</p></div>';
        }
        $k->typy['tlacitko'] = true; // tlačítko formuláře vypadá jako prvek Tlačítko
        $html = $vysledek !== '' ? '<p class="mc-formular-chyba" role="alert">' . e(self::hlaseni($vysledek)) . '</p>' : '';
        foreach ($o['pole'] as $i => $pole) {
            $html .= self::pole($pole, $i, $p['id']);
        }
        $antispam = new Antispam($k->app->db(), $k->app->settings());

        return '<form' . Text::sTridou($a, 'mc-formular') . $id . ' method="post" action="' . e($k->url('formular')) . '">'
            . '<input type="hidden" name="zdroj" value="' . e($k->zdroj) . '"><input type="hidden" name="prvek" value="' . e($p['id']) . '">'
            . '<input type="hidden" name="zpet" value="' . e($r->path()) . '">'
            . $antispam->pole('formular|' . $k->zdroj . '|' . $p['id'])
            . $html
            . '<p class="mc-pole"><button class="mc-tlacitko mc-tlacitko--primarni" type="submit">' . e($o['tlacitko']) . '</button></p></form>';
    }

    private static function pole(array $pole, int $i, string $prvek): string
    {
        $id = 'f-' . $prvek . '-' . $i;
        $jmeno = 'p' . $i;
        $povinne = $pole['povinne'] ? ' required' : '';
        $hvezda = $pole['povinne'] ? ' <span class="mc-povinne" aria-hidden="true">*</span>' : '';
        $popisek = e($pole['popisek']);
        if ($pole['typ'] === 'souhlas') {
            return '<p class="mc-pole mc-pole-souhlas"><label><input type="checkbox" name="' . $jmeno . '" value="1"' . $povinne . '> <span>' . $popisek . $hvezda . '</span></label></p>';
        }
        $label = '<label for="' . $id . '">' . $popisek . $hvezda . '</label>';
        $vstup = match ($pole['typ']) {
            'textarea' => '<textarea id="' . $id . '" name="' . $jmeno . '" maxlength="5000"' . $povinne . '></textarea>',
            'vyber' => '<select id="' . $id . '" name="' . $jmeno . '"' . $povinne . '><option value="">' . e(t('— vyberte —')) . '</option>'
                . implode('', array_map(fn (string $m): string => '<option>' . e($m) . '</option>', self::moznosti($pole))) . '</select>',
            default => '<input id="' . $id . '" name="' . $jmeno . '" type="' . ($pole['typ'] === 'email' ? 'email" autocomplete="email' : ($pole['typ'] === 'tel' ? 'tel" autocomplete="tel' : 'text')) . '" maxlength="300"' . $povinne . '>',
        };

        return '<p class="mc-pole">' . $label . $vstup . '</p>';
    }

    /** @return list<string> */
    public static function moznosti(array $pole): array
    {
        return array_values(array_filter(array_map('trim', explode("\n", (string) $pole['moznosti'])), fn (string $m): bool => $m !== ''));
    }
}
