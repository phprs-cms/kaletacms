<?php

declare(strict_types=1);

namespace MiroCMS\Front;

use MiroCMS\Core\Antispam;
use MiroCMS\Core\App;
use MiroCMS\Core\Posta;
use MiroCMS\Core\Response;
use MiroCMS\Core\View;

/**
 * Newsletter na webu: přihlášení k odběru s potvrzením e-mailem (double opt-in) a odhlášení jedním kliknutím.
 */
final class Newsletter
{
    public function __construct(private readonly App $app, private readonly View $view)
    {
    }

    /** Formulář do bloku Newsletter. */
    public function formularHtml(): string
    {
        $stav = $this->app->request->get('newsletter');

        return $this->view->render('blok_nws', [
            'akce' => $this->app->url('newsletter'),
            'pole' => (new Antispam($this->app->db(), $this->app->settings()))->pole('newsletter'),
            'zpet' => $this->app->request->path(),
            'zprava' => ['ok' => t('Poslali jsme vám e-mail – odběr potvrďte kliknutím na odkaz v něm.'), 'chyba' => t('Zadejte prosím platný e-mail a zkuste to znovu.')][$stav] ?? '',
        ]);
    }

    public function prihlas(): Response
    {
        $r = $this->app->request;
        $db = $this->app->db();
        $antispam = new Antispam($db, $this->app->settings());
        $zpet = preg_match('#^/[a-z0-9/_-]*$#i', $r->post('zpet')) ? ltrim($r->post('zpet'), '/') : '';
        $cil = fn (string $stav): Response => Response::redirect($this->app->url($zpet) . '?newsletter=' . $stav, 303);
        $email = mb_strtolower(mb_substr($r->post('email'), 0, 190));

        $duvod = $antispam->over($r, 'newsletter');
        if ($duvod === 'robot') {
            return $cil('ok');
        }
        if ($duvod !== null || filter_var($email, FILTER_VALIDATE_EMAIL) === false || $antispam->pocet($r->ip(), 'newsletter', 0, 60) >= 5) {
            return $cil('chyba');
        }
        $antispam->zapis($r->ip(), 'newsletter', 0);
        $odberatel = $db->one('SELECT * FROM {odberatele} WHERE email = ?', [$email]);
        if ($odberatel === null) {
            $token = bin2hex(random_bytes(16));
            $db->insert('odberatele', ['email' => $email, 'token' => $token, 'prihlasen' => date('Y-m-d H:i:s'), 'jazyk' => \MiroCMS\Core\Jazyk::sloupecWebu()]);
        } else {
            $token = $odberatel['token'];
        }
        // potvrzovací e-mail chodí i opakovaně přihlášenému - odpověď webu tak neprozradí, kdo už odběratelem je
        if ($odberatel === null || !$odberatel['potvrzen']) {
            $web = $this->app->settings();
            $adresa = $this->app->request->origin() . $this->app->url('newsletter/potvrdit/' . $token);
            // čtenář se přihlašuje sám, takže jazyk zobrazené verze webu je i jazyk příjemce
            Posta::odesli($web, $email, t('Potvrďte odběr') . ' – ' . $web->get('nazev_webu'),
                t('Dobrý den,') . "\n\n" . t('odběr novinek z webu %s potvrdíte kliknutím na tento odkaz:', $web->get('nazev_webu')) . "\n{$adresa}\n\n" . t('Pokud jste se k odběru nepřihlásili, e-mail ignorujte – nic vám chodit nebude.') . "\n");
        }

        return $cil('ok');
    }

    /** @return array{0:string, 1:string} nadpis a text stránky */
    public function potvrd(string $token): array
    {
        $n = $this->app->db()->run('UPDATE {odberatele} SET potvrzen = 1 WHERE token = ?', [$token])->rowCount();
        $existuje = $n > 0 || $this->app->db()->value('SELECT ido FROM {odberatele} WHERE token = ?', [$token]) !== null;

        return $existuje ? [t('Odběr je potvrzený'), t('Děkujeme. Novinky vám budou chodit e-mailem; odhlásit se můžete odkazem v každé zprávě.')] : [t('Odkaz neplatí'), t('Přihlaste se prosím k odběru znovu.')];
    }

    /** @return array{0:string, 1:string} */
    public function odhlas(string $token): array
    {
        $this->app->db()->delete('odberatele', ['token' => $token]);

        return [t('Odběr je zrušený'), t('Váš e-mail jsme ze seznamu odstranili. Další zprávy už nepřijdou.')];
    }
}
