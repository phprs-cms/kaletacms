<?php

declare(strict_types=1);

namespace Kaleta\Stavitel\Prvky;

use Kaleta\Core\Antispam;
use Kaleta\Stavitel\Kontext;
use Kaleta\Stavitel\Prvek;

/**
 * Přihlášení k odběru novinek e-mailem (rozšíření Newsletter). Adresa se uloží až po potvrzení odkazem z e-mailu
 * (double opt-in); seznam odběratelů jde z administrace vyexportovat do rozesílacího nástroje.
 */
final class Newsletter extends Prvek
{
    public const string TYP = 'newsletter';
    public const string NAZEV = 'Odběr novinek';
    public const string POPIS = 'Pole pro e-mail s potvrzením odběru – adresy najdete v administraci v Odběratelích.';
    public const string IKONA = 'newsletter';
    public const string SKUPINA = 'Dynamické';
    public const string ROZSIRENI = 'newsletter';
    public const array ZNACKY = ['form'];

    public static function vlastnosti(): array
    {
        return [
            'tlacitko' => ['typ' => 'text', 'popisek' => 'Tlačítko', 'vychozi' => t('Odebírat'), 'max' => 40],
            'souhlas' => ['typ' => 'text', 'popisek' => 'Text pod polem', 'vychozi' => t('Pošleme vám jen novinky a nabídky. Odhlásit se můžete jedním kliknutím v každém e-mailu.'), 'max' => 300],
        ];
    }

    public static function zakladniCss(): string
    {
        return '.ka-newsletter { display: grid; gap: var(--ka-mezera-xs); max-width: 32rem; }
.ka-newsletter-radek { display: flex; flex-wrap: wrap; gap: var(--ka-mezera-xs); }
.ka-newsletter input[type="email"] { flex: 1 1 14rem; min-width: 0; padding: 0.65em 0.9em; border: 1px solid var(--ka-barva-linka); border-radius: var(--ka-zaobleni); background: var(--ka-barva-pozadi); color: inherit; font: inherit; }
.ka-newsletter button { padding: 0.65em 1.2em; border: 0; border-radius: var(--ka-zaobleni); background: var(--ka-barva-primarni); color: var(--ka-barva-na-primarni); font: inherit; font-weight: 600; cursor: pointer; }
.ka-newsletter small { color: var(--ka-barva-tlumeny); }
.ka-newsletter-hlaska { margin: 0; font-weight: 600; }';
    }

    public static function vykresli(array $p, string $a, string $deti, Kontext $k): string
    {
        $o = $p['obsah'];
        $r = $k->app->request;
        $id = 'nl-' . $p['id'];
        $vysledek = $r->get('odber');
        $hlaska = match ($vysledek) {
            'ok' => t('Děkujeme! Poslali jsme vám e-mail s odkazem – odběr potvrďte kliknutím na něj.'),
            'chyba' => t('Zkontrolujte prosím e-mailovou adresu.'),
            'limit' => t('Příliš mnoho pokusů za sebou. Zkuste to prosím za chvíli.'),
            default => '',
        };
        $antispam = new Antispam($k->app->db(), $k->app->settings());
        // kotva pro návrat po odeslání: id prvku (kotva nebo styl), jinak vlastní
        $kotva = preg_match('/ id="([^"]*)"/', $a, $m) ? $m[1] : $id;
        if ($kotva === $id) {
            $a = ' id="' . e($id) . '"' . $a;
        }

        return '<form' . Text::sTridou($a, 'ka-newsletter') . ' method="post" action="' . e($k->url('odber')) . '">'
            . ($hlaska !== '' ? '<p class="ka-newsletter-hlaska" role="status">' . e($hlaska) . '</p>' : '')
            . '<label class="ka-jen-ctecka" for="' . e($id) . '-email">' . e(t('Váš e-mail')) . '</label>'
            . '<div class="ka-newsletter-radek"><input type="email" id="' . e($id) . '-email" name="email" autocomplete="email" required maxlength="190" placeholder="' . e(t('vas@email.cz')) . '">'
            . '<button type="submit">' . e($o['tlacitko']) . '</button></div>'
            . ($o['souhlas'] !== '' ? '<small>' . e($o['souhlas']) . '</small>' : '')
            . '<input type="hidden" name="zpet" value="' . e($k->app->url($r->path())) . '"><input type="hidden" name="kotva" value="' . e($kotva) . '">'
            . $antispam->pole('odber') . '</form>';
    }
}
