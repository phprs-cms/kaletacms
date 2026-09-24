<?php

declare(strict_types=1);

namespace MiroCMS\Admin;

use MiroCMS\Core\Antispam;
use MiroCMS\Core\App;
use MiroCMS\Core\Jazyk;
use MiroCMS\Core\Posta;
use MiroCMS\Core\Response;

/**
 * Obnova zapomenutého hesla do administrace odkazem z e-mailu (admin.php?akce=heslo).
 *
 * - Odpověď na žádost je vždy stejná, ať účet existuje nebo ne - stránka neprozradí, kdo web spravuje.
 * - V databázi je jen otisk tokenu; odkaz platí hodinu a jde použít jednou.
 * - Dvoufázové přihlášení obnova NEVYPÍNÁ: kdo získá přístup do e-mailu, bez kódu z aplikace se stejně nepřihlásí.
 * - Změna hesla ukončí všechna ostatní přihlášení účtu (otisk hesla v session přestane sedět).
 */
final class ObnovaHesla
{
    private const int PLATNOST = 3600;

    public function __construct(private readonly App $app)
    {
    }

    public function handle(): Response
    {
        $r = $this->app->request;
        $token = $r->isPost() ? $r->post('token') : $r->get('token');

        return $token !== '' ? $this->noveHeslo($token) : $this->zadost();
    }

    private function zadost(): Response
    {
        $app = $this->app;
        $odeslano = false;
        $chyba = null;
        if ($app->request->isPost()) {
            $ip = Antispam::otisk($app->request->ip());
            $pokusu = (int) $app->db()->value("SELECT COUNT(*) FROM {kontrola_ip} WHERE typ = 'obnova' AND ip_adresa = ? AND cas > NOW() - INTERVAL 15 MINUTE", [$ip]);
            if ($pokusu >= 5) {
                $chyba = t('Příliš mnoho žádostí. Zkuste to znovu za 15 minut.');
            } else {
                $app->db()->insert('kontrola_ip', ['ip_adresa' => $ip, 'typ' => 'obnova', 'cas' => date('Y-m-d H:i:s')]);
                $kdo = trim($app->request->post('kdo'));
                $user = $kdo === '' ? null : $app->db()->one("SELECT * FROM {uzivatele} WHERE (user = ? OR email = ?) AND blokovat = 0 AND email <> '' LIMIT 1", [$kdo, $kdo]);
                if ($user !== null) {
                    $this->posliOdkaz($user);
                }
                $odeslano = true;
            }
        }

        return $this->stranka(['krok' => 'zadost', 'odeslano' => $odeslano, 'chyba' => $chyba], $chyba === null ? 200 : 429);
    }

    /** @param array<string, mixed> $user */
    private function posliOdkaz(array $user): void
    {
        $app = $this->app;
        $token = bin2hex(random_bytes(32));
        $app->db()->update('uzivatele', ['obnova_otisk' => hash('sha256', $token), 'obnova_cas' => date('Y-m-d H:i:s')], ['idu' => $user['idu']]);
        $odkaz = rtrim($app->settings()->get('adresa_webu') ?: $app->request->origin(), '/') . $app->url('admin.php?akce=heslo&token=' . $token);
        $jazyk = (string) $user['jazyk'] !== '' ? (string) $user['jazyk'] : Jazyk::vychozi($app->settings());
        [$predmet, $text] = Jazyk::docasne($jazyk, fn (): array => [
            t('Nové heslo do administrace') . ' – ' . $app->settings()->get('nazev_webu'),
            t('Dobrý den,') . "\n\n" . t('někdo (nejspíš vy) požádal o nové heslo k účtu %s v administraci webu %s.', (string) $user['user'], $app->settings()->get('nazev_webu'))
                . "\n\n" . t('Nové heslo nastavíte na této adrese (platí hodinu a jde použít jednou):') . "\n" . $odkaz
                . "\n\n" . t('Pokud jste o nové heslo nežádali, e-mail smažte – heslo zůstává beze změny.'),
        ], 'admin-');
        Posta::odesli($app->settings(), (string) $user['email'], $predmet, $text);
        Protokol::zapis($app, 'prihlaseni', 'obnova-hesla', 'odeslán odkaz, účet: ' . $user['user']);
    }

    private function noveHeslo(string $token): Response
    {
        $app = $this->app;
        $user = preg_match('/^[a-f0-9]{64}$/', $token) === 1
            ? $app->db()->one('SELECT * FROM {uzivatele} WHERE obnova_otisk = ? AND blokovat = 0 AND obnova_cas > ?', [hash('sha256', $token), date('Y-m-d H:i:s', time() - self::PLATNOST)])
            : null;
        if ($user === null) {
            return $this->stranka(['krok' => 'neplatny', 'odeslano' => false, 'chyba' => t('Odkaz už neplatí nebo byl použit. Požádejte o nový.')], 400);
        }
        $chyba = null;
        if ($app->request->isPost()) {
            $heslo = (string) ($_POST['password'] ?? '');
            if (mb_strlen($heslo) < 10) {
                $chyba = t('Heslo musí mít alespoň 10 znaků.');
            } elseif ($heslo !== (string) ($_POST['password2'] ?? '')) {
                $chyba = t('Hesla se neshodují.');
            } else {
                $app->db()->update('uzivatele', [
                    'password' => password_hash($heslo, PASSWORD_DEFAULT), 'obnova_otisk' => '', 'obnova_cas' => null, 'pocet_chyb' => 0, 'zamceno_do' => null,
                ], ['idu' => $user['idu']]);
                Protokol::zapis($app, 'prihlaseni', 'obnova-hesla', 'heslo změněno, účet: ' . $user['user']);
                return Response::redirect($app->url('admin.php?heslo=zmeneno'));
            }
        }

        return $this->stranka(['krok' => 'heslo', 'odeslano' => false, 'chyba' => $chyba, 'token' => $token, 'ucet' => (string) $user['user']], $chyba === null ? 200 : 422);
    }

    /** @param array<string, mixed> $data */
    private function stranka(array $data, int $status): Response
    {
        return Response::html($this->app->view->render('admin/heslo', ['app' => $this->app, 'token' => '', 'ucet' => ''] + $data), $status);
    }
}
