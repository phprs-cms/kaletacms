<?php

declare(strict_types=1);

namespace MiroCMS\Admin;

use MiroCMS\Core\Passkey;
use MiroCMS\Core\Response;
use MiroCMS\Core\Rozsireni;
use MiroCMS\Core\Totp;

/**
 * Můj účet: vlastní jméno a e-mail, změna hesla, dvoufázové přihlášení. Dostupné každému přihlášenému.
 */
final class Ucet
{
    public function __construct(private readonly Kernel $kernel)
    {
    }

    public function handle(): Response
    {
        $app = $this->kernel->app;
        $r = $app->request;
        $db = $app->db();
        $user = $app->auth()->user();
        $data = ['zalozniKody' => [], 'noveTajemstvi' => '', 'novyToken' => ''];

        if ($r->isPost()) {
            $hlaska = null;
            switch ($r->postInt('smaz_token') > 0 ? 'token_smaz' : $r->post('co')) {
                case 'profil':
                    if ($r->post('email') !== '' && filter_var($r->post('email'), FILTER_VALIDATE_EMAIL) === false) {
                        $hlaska = ['chyba', 'E-mail nemá platný tvar.'];
                        break;
                    }
                    $db->update('uzivatele', ['jmeno' => mb_substr($r->post('jmeno'), 0, 100), 'email' => mb_substr($r->post('email'), 0, 190), 'url' => mb_substr($r->post('url'), 0, 255), 'pozice' => mb_substr($r->post('pozice'), 0, 100), 'foto' => mb_substr($r->post('foto'), 0, 255), 'bio' => mb_substr($r->post('bio'), 0, 1200),
                        'jazyk' => isset(\MiroCMS\Core\Jazyk::ADMINISTRACE[$r->post('jazyk')]) && $r->post('jazyk') !== 'cs' ? $r->post('jazyk') : ''], ['idu' => $user['idu']]);
                    $hlaska = ['ok', 'Údaje byly uloženy.'];
                    break;
                case 'heslo':
                    $nove = (string) ($_POST['nove'] ?? '');
                    $hlaska = match (true) {
                        !password_verify((string) ($_POST['soucasne'] ?? ''), $user['password']) => ['chyba', 'Současné heslo není správné.'],
                        mb_strlen($nove) < 10 => ['chyba', 'Nové heslo musí mít alespoň 10 znaků.'],
                        $nove !== (string) ($_POST['nove2'] ?? '') => ['chyba', 'Nová hesla se neshodují.'],
                        default => null,
                    };
                    if ($hlaska === null) {
                        $novyHash = password_hash($nove, PASSWORD_DEFAULT);
                        $db->update('uzivatele', ['password' => $novyHash], ['idu' => $user['idu']]);
                        $app->auth()->obnovPoZmeneHesla($novyHash); // ostatní přihlášení tohoto účtu tím končí
                        $zruseno = $r->postBool('zrusit_tokeny') ? $db->delete('api_tokeny', ['idu' => $user['idu']]) : 0;
                        Protokol::zapis($app, 'ucet', 'změna hesla' . ($zruseno > 0 ? ', zrušeny tokeny napojení (' . $zruseno . ')' : ''));
                        $hlaska = ['ok', $zruseno > 0 ? 'Heslo bylo změněno, ostatní přihlášení ukončena a tokeny napojení zrušeny.' : 'Heslo bylo změněno a ostatní přihlášení tohoto účtu ukončena.'];
                    }
                    break;
                case 'token_novy':
                    if (!Rozsireni::je($app->settings(), 'claude')) {
                        break;
                    }
                    $token = 'mirocms_' . bin2hex(random_bytes(24));
                    $db->insert('api_tokeny', ['idu' => $user['idu'], 'nazev' => mb_substr($r->post('nazev') ?: 'Claude', 0, 100), 'otisk' => hash('sha256', $token), 'vytvoren' => date('Y-m-d H:i:s')]);
                    Protokol::zapis($app, 'ucet', 'vytvořen token pro Claude');
                    // token se ukazuje jen teď - proto bez přesměrování
                    return $this->stranka(['novyToken' => $token] + $data);
                case 'token_smaz':
                    $db->delete('api_tokeny', ['idt' => $r->postInt('smaz_token'), 'idu' => $user['idu']]);
                    $hlaska = ['ok', 'Token byl zrušen.'];
                    break;
                case 'totp_start':
                    $app->session->set('totp_nove', Totp::noveTajemstvi());
                    break;
                case 'totp_potvrd':
                    $tajemstvi = (string) $app->session->get('totp_nove', '');
                    if ($tajemstvi === '' || !Totp::over($tajemstvi, $r->post('kod'))) {
                        $hlaska = ['chyba', 'Kód nesouhlasí. Zkontrolujte čas v telefonu a zkuste to znovu.'];
                        break;
                    }
                    [$kody, $json] = Totp::zalozniKody();
                    $db->update('uzivatele', ['totp_tajemstvi' => $tajemstvi, 'totp_zalozni' => $json], ['idu' => $user['idu']]);
                    $app->session->remove('totp_nove');
                    Protokol::zapis($app, 'ucet', 'zapnuto dvoufázové přihlášení');
                    // záložní kódy se ukazují jen teď - proto bez přesměrování
                    return $this->stranka(['zalozniKody' => $kody] + $data);
                case 'klic_moznosti':
                case 'klic_uloz':
                    return $this->klic($r->post('co') === 'klic_uloz');
                case 'klic_smaz':
                    $db->run('DELETE FROM {uzivatele_klice} WHERE idk = ? AND idu = ?', [$r->postInt('idk'), $user['idu']]);
                    Protokol::zapis($app, 'ucet', 'odebrán přihlašovací klíč');
                    $hlaska = ['ok', 'Přihlašovací klíč je odebrán.'];
                    break;
                case 'totp_vypni':
                    if (!password_verify((string) ($_POST['soucasne'] ?? ''), $user['password'])) {
                        $hlaska = ['chyba', 'Pro vypnutí zadejte správné heslo.'];
                        break;
                    }
                    $db->update('uzivatele', ['totp_tajemstvi' => '', 'totp_zalozni' => null], ['idu' => $user['idu']]);
                    $db->run('DELETE FROM {uzivatele_klice} WHERE idu = ?', [$user['idu']]); // klíče jsou náhrada kódu z aplikace - bez něj nemají smysl
                    Protokol::zapis($app, 'ucet', 'vypnuto dvoufázové přihlášení');
                    $hlaska = ['ok', 'Dvoufázové přihlášení je vypnuté.'];
                    break;
            }
            if ($hlaska !== null) {
                $app->session->flash(...$hlaska);

                return Response::redirect($app->url('admin.php?akce=ucet'));
            }
        }

        return $this->stranka(['noveTajemstvi' => (string) $app->session->get('totp_nove', '')] + $data);
    }

    /**
     * Registrace přihlašovacího klíče (otisk prstu, Face ID, bezpečnostní klíč) - volá ji skript image/klice.js.
     * Klíč jde přidat jen k účtu se zapnutým dvoufázovým přihlášením: je to pohodlnější náhrada kódu z aplikace,
     * kód a záložní kódy zůstávají jako záloha pro případ ztráty zařízení.
     */
    private function klic(bool $ulozit): Response
    {
        $app = $this->kernel->app;
        $user = $app->auth()->user();
        if ((string) $user['totp_tajemstvi'] === '') {
            return Response::json(['chyba' => t('Nejdřív zapněte dvoufázové přihlášení.')], 400);
        }
        $adresa = $app->settings()->get('adresa_webu') ?: $app->request->origin();
        if (!$ulozit) {
            $vyzva = Passkey::vyzva();
            $app->session->set('klic_registrace', $vyzva);

            return Response::json(Passkey::moznostiRegistrace(
                $vyzva, Passkey::rpId($adresa), $app->settings()->get('nazev_webu'),
                Passkey::b64(substr(hash('sha256', 'mirocms-klic|' . $adresa . '|' . $user['idu'], true), 0, 16)),
                (string) $user['user'], (string) $user['jmeno'],
                array_map(static fn (array $k): string => (string) $k['id_klice'], $app->auth()->kliceUctu((int) $user['idu'])),
            ));
        }
        $vyzva = (string) $app->session->get('klic_registrace', '');
        $app->session->remove('klic_registrace');
        try {
            $novy = Passkey::overRegistraci((array) json_decode((string) ($_POST['odpoved'] ?? ''), true), $vyzva, Passkey::puvod($adresa), Passkey::rpId($adresa));
        } catch (\RuntimeException $e) {
            return Response::json(['chyba' => t($e->getMessage())], 400);
        }
        $otisk = hash('sha256', Passkey::zB64($novy['id']));
        if ($app->db()->value('SELECT idk FROM {uzivatele_klice} WHERE otisk_id = ?', [$otisk]) !== null) {
            return Response::json(['chyba' => t('Tenhle klíč už je zaregistrovaný.')], 400);
        }
        $nazev = mb_substr(trim($app->request->post('nazev')), 0, 80);
        $app->db()->insert('uzivatele_klice', [
            'idu' => $user['idu'], 'nazev' => $nazev !== '' ? $nazev : t('Přihlašovací klíč'), 'otisk_id' => $otisk, 'id_klice' => $novy['id'],
            'verejny' => $novy['klic'], 'alg' => $novy['alg'], 'pocitadlo' => $novy['pocitadlo'], 'vytvoreno' => date('Y-m-d H:i:s'),
        ]);
        Protokol::zapis($app, 'ucet', 'přidán přihlašovací klíč', $nazev);
        $app->session->flash('ok', 'Přihlašovací klíč je přidán. Při příštím přihlášení ho můžete použít místo kódu z aplikace.');

        return Response::json(['ok' => true]);
    }

    /** @param array<string, mixed> $data */
    private function stranka(array $data): Response
    {
        $app = $this->kernel->app;
        $user = $app->db()->one('SELECT * FROM {uzivatele} WHERE idu = ?', [$app->auth()->id()]);

        return $this->kernel->page('Můj účet', $app->view->render('admin/ucet', $data + [
            'app' => $app, 'user' => $user, 'csrf' => $app->session->csrfField(),
            'uri' => $data['noveTajemstvi'] !== '' ? Totp::uri($data['noveTajemstvi'], $user['user'], $app->settings()->get('nazev_webu')) : '',
            'zbyvaKodu' => count((array) json_decode((string) $user['totp_zalozni'], true)),
            'claude' => Rozsireni::je($app->settings(), 'claude'),
            'klice' => $app->auth()->kliceUctu((int) $user['idu']),
            'tokeny' => $app->db()->all('SELECT * FROM {api_tokeny} WHERE idu = ? ORDER BY idt DESC', [$user['idu']]),
            'adresaMcp' => $app->request->origin() . $app->url('mcp'),
        ]));
    }
}
