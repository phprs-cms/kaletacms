<?php

declare(strict_types=1);

namespace Kaleta\Front;

use Kaleta\Core\Antispam;
use Kaleta\Core\App;
use Kaleta\Core\Posta;
use Kaleta\Core\Response;

/**
 * Odběr novinek (rozšíření Newsletter): přihlášení z prvku Odběr novinek, potvrzení a odhlášení odkazem z e-mailu.
 * Adresa se počítá za odběratele až po potvrzení (double opt-in). Kdo se přihlásí podruhé, dostane jen nový odkaz –
 * web nikomu neprozradí, jestli adresa už v seznamu je.
 */
final class Odber
{
    private const int LIMIT = 5; // přihlášení z jedné adresy za 10 minut

    public function __construct(private readonly App $app)
    {
    }

    /** POST z prvku: uloží nebo obnoví nepotvrzenou adresu a pošle odkaz. @return string výsledek pro hlášku prvku (ok | chyba | limit) */
    public function prihlas(): string
    {
        $r = $this->app->request;
        $antispam = new Antispam($this->app->db(), $this->app->settings());
        $duvod = $antispam->over($r, 'odber');
        if ($duvod === 'robot') {
            return 'ok';
        }
        if ($duvod !== null) {
            return 'chyba';
        }
        if ($antispam->pocet($r->ip(), 'odber', 0, 10) >= self::LIMIT) {
            return 'limit';
        }
        $antispam->zapis($r->ip(), 'odber', 0);
        $email = mb_strtolower(trim($r->post('email')));
        if (mb_strlen($email) > 190 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return 'chyba';
        }
        $db = $this->app->db();
        $odberatel = $db->one('SELECT * FROM {odberatele} WHERE email = ?', [$email]);
        if ($odberatel !== null && (int) $odberatel['stav'] === 1) {
            return 'ok'; // už odebírá – nic dalšího neposíláme
        }
        $token = bin2hex(random_bytes(16));
        if ($odberatel === null) {
            $db->insert('odberatele', ['email' => $email, 'token' => $token, 'datum' => date('Y-m-d H:i:s'), 'zdroj' => mb_substr($r->post('zpet'), 0, 255)]);
        } else {
            $db->update('odberatele', ['token' => $token, 'datum' => date('Y-m-d H:i:s')], ['ido' => (int) $odberatel['ido']]);
        }
        $web = $this->app->settings();
        $odkaz = $this->adresa('odber?potvrdit=' . $token);
        Posta::odesli($web, $email, t('Potvrďte odběr novinek – %s', $web->get('nazev_webu')),
            t('Dobrý den,') . "\n\n" . t('pro potvrzení odběru novinek webu %s klikněte na odkaz:', $web->get('nazev_webu')) . "\n" . $odkaz . "\n\n"
            . t('Pokud jste o odběr nežádali, e-mail ignorujte – bez potvrzení vám nic posílat nebudeme.') . "\n");

        return 'ok';
    }

    /**
     * Odkaz z e-mailu (?potvrdit= / ?odhlasit=). Otevření odkazu (GET) jen ukáže tlačítko – poštovní skenery odkazů
     * (Safe Links apod.) by jinak odběr samy potvrdily nebo odběratele odhlásily. Změna proběhne až odesláním (POST),
     * odhlášení i jedním klepnutím z poštovního klienta (List-Unsubscribe-Post).
     *
     * @return array{0: string, 1: string} titulek a obsah stránky (HTML)
     */
    public function odkaz(): array
    {
        $r = $this->app->request;
        $db = $this->app->db();
        $akce = preg_match('/^[a-f0-9]{32}$/', $r->get('potvrdit')) ? 'potvrdit' : (preg_match('/^[a-f0-9]{32}$/', $r->get('odhlasit')) ? 'odhlasit' : '');
        $o = $akce !== '' ? $db->one('SELECT * FROM {odberatele} WHERE token = ?', [$r->get($akce)]) : null;
        if ($o === null) {
            return [t('Odkaz už neplatí'), '<p>' . e(t('Odkaz je neplatný nebo už byl použitý. Pokud chcete novinky odebírat, přihlaste se prosím znovu.')) . '</p>'];
        }
        if (!$r->isPost()) {
            [$nadpis, $text, $tlacitko] = $akce === 'potvrdit'
                ? [t('Potvrzení odběru'), t('Potvrďte prosím, že chcete dostávat novinky na %s.', $o['email']), t('Potvrdit odběr')]
                : [t('Odhlášení odběru'), t('Opravdu už nechcete dostávat novinky na %s?', $o['email']), t('Odhlásit odběr')];

            return [$nadpis, '<p>' . e($text) . '</p><form method="post" action="' . e($this->app->url('odber') . '?' . $akce . '=' . $o['token']) . '"><p><button class="tlacitko" type="submit">' . e($tlacitko) . '</button></p></form>'];
        }
        if ($akce === 'odhlasit') {
            $db->delete('odberatele', ['ido' => (int) $o['ido']]);
            if ((int) $o['stav'] === 1) {
                \Kaleta\Core\Newsletter::zarad($this->app, (string) $o['email'], 'odebrat'); // i z mailingové služby
            }

            return [t('Odhlášeno'), '<p>' . e(t('Adresu %s jsme ze seznamu odběratelů smazali.', $o['email'])) . '</p>'];
        }
        if ((int) $o['stav'] === 0) {
            $db->update('odberatele', ['stav' => 1, 'potvrzeno' => date('Y-m-d H:i:s')], ['ido' => (int) $o['ido']]);
            \Kaleta\Core\Newsletter::zarad($this->app, (string) $o['email'], 'pridat'); // do mailingové služby, odešle úklid na pozadí
        }

        return [t('Odběr je potvrzený'), '<p>' . e(t('Děkujeme, novinky vám budeme posílat na %s. Odhlásit se můžete odkazem v každém e-mailu.', $o['email'])) . '</p>'];
    }

    /** Odkaz pro odhlášení do rozesílacího nástroje (export odběratelů). */
    public static function odkazOdhlaseni(App $app, string $token): string
    {
        return (new self($app))->adresa('odber?odhlasit=' . $token);
    }

    private function adresa(string $cesta): string
    {
        return rtrim($this->app->settings()->get('adresa_webu') ?: $this->app->request->origin(), '/') . $this->app->url($cesta);
    }
}
