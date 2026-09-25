<?php

declare(strict_types=1);

namespace Kaleta\Stavitel\Prvky;

use Kaleta\Core\Antispam;
use Kaleta\Stavitel\Kontext;
use Kaleta\Stavitel\Prvek;

/**
 * Poptávkový / kontaktní formulář. Odesílá se na /formular (Front\Formulare): server vezme pole z publikované stavby
 * (ne z prohlížeče), ověří je, uloží poptávku (Administrace → Poptávky) a pošle upozornění e-mailem.
 * Ochrana bez cookies a CAPTCHA (Core\Antispam), takže stránka s formulářem zůstává v cache.
 */
final class Formular extends Prvek
{
    public const string TYP = 'formular';
    public const string ROZSIRENI = 'poptavky';
    public const string NAZEV = 'Formulář';
    public const string POPIS = 'Poptávka nebo dotaz – odeslané zprávy najdete v Poptávkách a přijdou i e-mailem.';
    public const string IKONA = 'formular';
    public const string SKUPINA = 'Dynamické';
    public const array ZNACKY = ['form'];

    /** Typy polí formuláře. */
    public const array TYPY_POLI = ['text' => 'text', 'email' => 'e-mail', 'tel' => 'telefon', 'textarea' => 'delší text', 'vyber' => 'výběr ze seznamu',
        'volba' => 'volba jedné možnosti (přepínače)', 'datum' => 'datum', 'cislo' => 'číslo', 'soubor' => 'příloha (soubor)', 'souhlas' => 'zaškrtnutí (souhlas)'];

    /** Přílohy formuláře: povolené typy a největší velikost jednoho souboru. */
    /** Telefon v atributu pattern (prohlížeč ho čte s příznakem v – závorky, lomítko a pomlčka ve třídě musí být escapované). */
    public const string VZOR_TELEFONU = '[+\\(\\)\\d\\s\\/.\\-]{6,30}';

    public const array PRIPONY_PRILOH = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'heic', 'doc', 'docx', 'xls', 'xlsx', 'odt', 'ods', 'txt', 'zip', 'dwg', 'dxf'];
    public const int MAX_PRILOHA = 10 * 1024 * 1024;

    public static function vlastnosti(): array
    {
        return [
            'nazev' => ['typ' => 'text', 'popisek' => 'Název formuláře (v Poptávkách a v e-mailu)', 'vychozi' => t('Poptávka'), 'max' => 120],
            'pole' => ['typ' => 'polozky', 'popisek' => 'Pole formuláře', 'max' => 20, 'pole' => [
                'popisek' => ['typ' => 'text', 'popisek' => 'Popisek', 'vychozi' => '', 'max' => 200],
                'typ' => ['typ' => 'vyber', 'popisek' => 'Typ', 'vychozi' => 'text', 'moznosti' => self::TYPY_POLI],
                'povinne' => ['typ' => 'prepinac', 'popisek' => 'Povinné', 'vychozi' => false],
                'moznosti' => ['typ' => 'radky', 'popisek' => 'Možnosti výběru (každá na řádek)', 'vychozi' => '', 'max' => 2000, 'kdyz' => ['typ' => 'vyber']],
                'moznosti_volby' => ['typ' => 'radky', 'popisek' => 'Možnosti (každá na řádek)', 'vychozi' => '', 'max' => 2000, 'kdyz' => ['typ' => 'volba']],
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
            'dekovna' => ['typ' => 'odkaz', 'popisek' => 'Po odeslání přejít na stránku (prázdné = poděkování na místě formuláře)', 'vychozi' => ''],
            'potvrzeni' => ['typ' => 'prepinac', 'popisek' => 'Poslat odesílateli potvrzení e-mailem (jen poděkování, bez obsahu zprávy)', 'vychozi' => false],
        ];
    }

    public static function zakladniCss(): string
    {
        // kotva po odeslání míří na formulář: odstup, aby nad ním byl vidět i nadpis a nezakrylo ho přilepené záhlaví
        return '.ka-formular { display: grid; gap: var(--ka-mezera-s); }
.ka-formular, .ka-formular-hotovo { scroll-margin-top: 6rem; }
.ka-pole { display: grid; gap: var(--ka-mezera-2xs); margin: 0; }
.ka-pole > label { font-weight: 600; }
.ka-pole input:not([type="checkbox"]), .ka-pole select, .ka-pole textarea { box-sizing: border-box; width: 100%; padding: 0.7em 0.9em; border: 1px solid var(--ka-barva-linka); border-radius: var(--ka-zaobleni); background: var(--ka-barva-pozadi); color: var(--ka-barva-text); font: inherit; }
.ka-pole textarea { min-height: 8em; resize: vertical; }
.ka-pole :focus-visible { outline: 2px solid var(--ka-barva-primarni); outline-offset: 1px; }
.ka-pole-souhlas label { display: flex; gap: var(--ka-mezera-xs); align-items: flex-start; }
.ka-pole-souhlas input { margin-block-start: 0.3em; accent-color: var(--ka-barva-primarni); }
.ka-pole-zasady { display: inline-block; margin-inline-start: 1.6em; font-size: var(--ka-krok--1); }
.ka-povinne { color: var(--ka-barva-primarni); }
.ka-pole fieldset { display: grid; gap: var(--ka-mezera-2xs); margin: 0; padding: 0; border: 0; }
.ka-pole legend { margin-block-end: var(--ka-mezera-2xs); padding: 0; font-weight: 600; }
.ka-pole fieldset label { display: flex; gap: var(--ka-mezera-xs); align-items: center; font-weight: 400; }
.ka-pole fieldset input { accent-color: var(--ka-barva-primarni); }
.ka-pole [aria-invalid="true"] { border-color: #c4281c !important; }
.ka-pole-napoveda { color: var(--ka-barva-tlumeny); font-size: var(--ka-krok--1); }
.ka-pole-chyba { color: color-mix(in oklch, #c4281c 80%, var(--ka-barva-text)); font-size: var(--ka-krok--1); }
.ka-formular-hotovo, .ka-formular-chyba { margin: 0; padding: var(--ka-mezera-m); border-radius: var(--ka-zaobleni); }
.ka-formular-hotovo { background: var(--ka-barva-primarni-jemna); color: var(--ka-barva-text); }
.ka-formular-chyba { background: color-mix(in oklch, #c4281c 12%, var(--ka-barva-pozadi)); color: color-mix(in oklch, #c4281c 80%, var(--ka-barva-text)); }';
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
            'pole' => t('Zkontrolujte prosím označené pole.'),
            'limit' => t('Z vaší adresy přišlo v krátké době příliš mnoho zpráv. Zkuste to prosím později.'),
            'rychle' => t('Formulář odešel dřív, než jsme stihli ověřit, že ho posílá člověk. Počkejte prosím chvilku a odešlete ho znovu.'),
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
            // data-odeslano: image/web.js ohlásí konverzi (událost kaleta:odeslano a dataLayer, když na webu je)
            return '<div' . Text::sTridou($a, 'ka-formular-hotovo') . $id . ' role="status" data-odeslano="' . e($o['nazev']) . '"><p>' . e($o['dekujeme']) . '</p></div>';
        }
        $k->typy['tlacitko'] = true; // tlačítko formuláře vypadá jako prvek Tlačítko
        $html = $vysledek !== '' ? '<p class="ka-formular-chyba" role="alert">' . e(self::hlaseni($vysledek)) . '</p>' : '';
        $chybne = $vysledek === 'pole' ? $r->getInt('pole', -1) : -1;
        foreach ($o['pole'] as $i => $pole) {
            $html .= self::pole($pole, $i, $p['id'], $i === $chybne, $k->app->settings()->get('cookies_zasady_url'));
        }
        $antispam = new Antispam($k->app->db(), $k->app->settings());

        // data-formular: po chybě image/web.js vrátí do polí, co návštěvník vyplnil (drží to jen jeho prohlížeč)
        $soubory = in_array('soubor', array_column($o['pole'], 'typ'), true) ? ' enctype="multipart/form-data"' : '';

        return '<form' . Text::sTridou($a, 'ka-formular') . $id . ' method="post" action="' . e($k->url('formular')) . '"' . $soubory . ' data-formular="' . e($p['id']) . '"' . ($vysledek !== '' ? ' data-obnovit' : '') . '>'
            . '<input type="hidden" name="zdroj" value="' . e($k->zdroj) . '"><input type="hidden" name="prvek" value="' . e($p['id']) . '">'
            . '<input type="hidden" name="zpet" value="' . e($k->app->url($r->path())) . '">'
            . $antispam->pole('formular|' . $k->zdroj . '|' . $p['id'])
            . $html
            . '<p class="ka-pole"><button class="ka-tlacitko ka-tlacitko--primarni" type="submit">' . e($o['tlacitko']) . '</button></p></form>';
    }

    private static function pole(array $pole, int $i, string $prvek, bool $chyba = false, string $zasady = ''): string
    {
        $id = 'f-' . $prvek . '-' . $i;
        $jmeno = 'p' . $i;
        $povinne = $pole['povinne'] ? ' required' : '';
        $hvezda = $pole['povinne'] ? ' <span class="ka-povinne" aria-hidden="true">*</span>' : '';
        $popisek = e($pole['popisek']);
        // pole, které server odmítl: označené a s hláškou, na kterou odkazuje aria-describedby
        $oznaceni = $chyba ? ' aria-invalid="true" aria-describedby="' . $id . '-chyba" autofocus' : '';
        $hlaska = $chyba ? '<span class="ka-pole-chyba" id="' . $id . '-chyba">' . e($pole['typ'] === 'email' ? t('Zadejte platnou e-mailovou adresu.') : t('Toto pole je potřeba vyplnit správně.')) . '</span>' : '';
        if ($pole['typ'] === 'souhlas') {
            $odkaz = $zasady !== '' ? ' <a class="ka-pole-zasady" href="' . e($zasady) . '" target="_blank">' . e(t('Zásady ochrany osobních údajů')) . '</a>' : '';

            return '<p class="ka-pole ka-pole-souhlas"><label><input type="checkbox" name="' . $jmeno . '" value="1"' . $povinne . $oznaceni . '> <span>' . $popisek . $hvezda . '</span></label>' . $odkaz . $hlaska . '</p>';
        }
        if ($pole['typ'] === 'volba') {
            $volby = '';
            foreach (self::moznosti($pole) as $j => $m) {
                $volby .= '<label><input type="radio" name="' . $jmeno . '" value="' . e($m) . '"' . ($j === 0 ? $povinne . $oznaceni : '') . '> ' . e($m) . '</label>';
            }

            return '<div class="ka-pole"><fieldset><legend>' . $popisek . $hvezda . '</legend>' . $volby . '</fieldset>' . $hlaska . '</div>';
        }
        $label = '<label for="' . $id . '">' . $popisek . $hvezda . '</label>';
        $vstup = match ($pole['typ']) {
            'textarea' => '<textarea id="' . $id . '" name="' . $jmeno . '" maxlength="5000"' . $povinne . $oznaceni . '></textarea>',
            'vyber' => '<select id="' . $id . '" name="' . $jmeno . '"' . $povinne . $oznaceni . '><option value="">' . e(t('— vyberte —')) . '</option>'
                . implode('', array_map(fn (string $m): string => '<option>' . e($m) . '</option>', self::moznosti($pole))) . '</select>',
            'datum' => '<input id="' . $id . '" name="' . $jmeno . '" type="date"' . $povinne . $oznaceni . '>',
            'cislo' => '<input id="' . $id . '" name="' . $jmeno . '" type="number" step="any" inputmode="decimal"' . $povinne . $oznaceni . '>',
            'soubor' => '<input id="' . $id . '" name="' . $jmeno . '" type="file" accept=".' . implode(',.', self::PRIPONY_PRILOH) . '"' . $povinne . $oznaceni . '>'
                . '<small class="ka-pole-napoveda">' . e(t('Nejvýš %d MB: PDF, obrázek, dokument nebo ZIP.', (int) (self::MAX_PRILOHA / 1048576))) . '</small>',
            // telefon: stejné pravidlo jako na serveru (Front\Formulare), prohlížeč ho zkontroluje hned; vzor platí i s příznakem v
            'tel' => '<input id="' . $id . '" name="' . $jmeno . '" type="tel" autocomplete="tel" maxlength="30" pattern="' . self::VZOR_TELEFONU . '" title="' . e(t('Telefonní číslo, například +420 123 456 789.')) . '"' . $povinne . $oznaceni . '>',
            default => '<input id="' . $id . '" name="' . $jmeno . '" type="' . ($pole['typ'] === 'email' ? 'email" autocomplete="email' : 'text' . self::autocomplete($pole['popisek'])) . '" maxlength="300"' . $povinne . $oznaceni . '>',
        };

        return '<p class="ka-pole">' . $label . $vstup . $hlaska . '</p>';
    }

    /**
     * Automatické vyplnění textového pole podle popisku (WCAG 1.3.5): jméno a firma. Typ pole zůstává „text“,
     * aby fungovaly i dříve postavené formuláře.
     */
    private static function autocomplete(string $popisek): string
    {
        return match (true) {
            (bool) preg_match('/^(vaše |celé |your |full )?(jméno|name)\b/iu', trim($popisek)) => '" autocomplete="name',
            (bool) preg_match('/^(firma|společnost|název firmy|company|organi[sz]ation)\b/iu', trim($popisek)) => '" autocomplete="organization',
            default => '',
        };
    }

    /** @return list<string> možnosti výběru nebo přepínačů */
    public static function moznosti(array $pole): array
    {
        $text = ($pole['typ'] ?? '') === 'volba' ? (string) ($pole['moznosti_volby'] ?? '') : (string) $pole['moznosti'];

        return array_values(array_filter(array_map('trim', explode("\n", $text)), fn (string $m): bool => $m !== ''));
    }
}
