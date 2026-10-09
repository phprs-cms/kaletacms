<?php

declare(strict_types=1);

namespace Kaleta\Admin;

use Kaleta\Core\Passkey;
use Kaleta\Core\Response;
use Kaleta\Core\Extensions;
use Kaleta\Core\Totp;

/**
 * My account: own name and e-mail, password change, two-factor sign-in. Available to every signed-in user.
 */
final class Account
{
    /** How long a new personal token is valid, in days (0 = no expiry); the default is a year (2.8). */
    public const array TOKEN_LIFETIMES = [30, 90, 365, 0];

    public function __construct(private readonly Kernel $kernel)
    {
    }

    public function handle(): Response
    {
        $app = $this->kernel->app;
        $r = $app->request;
        $db = $app->db();
        $user = $app->auth()->user();
        $data = ['backupCodes' => [], 'newSecret' => '', 'newToken' => ''];

        if ($r->isPost()) {
            $message = null;
            switch ($r->postInt('smaz_token') > 0 ? 'token_smaz' : ($r->post('odpojit_klient') !== '' ? 'aplikace_odpojit' : $r->post('co'))) {
                case 'profil':
                    if ($r->post('email') !== '' && filter_var($r->post('email'), FILTER_VALIDATE_EMAIL) === false) {
                        $message = ['chyba', 'The e-mail address is not valid.'];
                        break;
                    }
                    $email = mb_substr($r->post('email'), 0, 190);
                    $emailChanged = $email !== (string) $user['email'];
                    // the e-mail is where a password reset goes: changing it needs the current password, like a new password
                    // does – a stolen session alone must not take the account over (3.3.3, N56)
                    if ($emailChanged && !password_verify((string) ($_POST['soucasne'] ?? ''), $user['password'])) {
                        $message = ['chyba', 'Enter your current password to change the e-mail address. Nothing was saved.'];
                        break;
                    }
                    $db->update('uzivatele', ['jmeno' => mb_substr($r->post('jmeno'), 0, 100), 'email' => $email, 'url' => mb_substr($r->post('url'), 0, 255), 'pozice' => mb_substr($r->post('pozice'), 0, 100), 'foto' => mb_substr($r->post('foto'), 0, 255), 'bio' => mb_substr($r->post('bio'), 0, 1200),
                        // admin language, Czech explicitly too – an empty value would mean the site language
                        'jazyk' => isset(\Kaleta\Core\Language::ADMIN_LANGUAGES[$r->post('jazyk')]) ? $r->post('jazyk') : '',
                        // form of address in the German administration: '' = formal
                        'register' => $r->post('register') === 'informal' ? 'informal' : ''], ['idu' => $user['idu']]);
                    if ($emailChanged) {
                        ChangeLog::write($app, 'ucet', 'email_change');
                        $this->noticeOfNewEmail($user, $email);
                    }
                    $message = ['ok', 'Details saved.'];
                    break;
                case 'heslo':
                    $newItems = (string) ($_POST['nove'] ?? '');
                    $message = match (true) {
                        !password_verify((string) ($_POST['soucasne'] ?? ''), $user['password']) => ['chyba', 'The current password is not correct.'],
                        mb_strlen($newItems) < 10 => ['chyba', 'The new password must be at least 10 characters long.'],
                        $newItems !== (string) ($_POST['nove2'] ?? '') => ['chyba', 'The new passwords do not match.'],
                        default => null,
                    };
                    if ($message === null) {
                        $newHash = password_hash($newItems, PASSWORD_DEFAULT);
                        $db->update('uzivatele', ['password' => $newHash], ['idu' => $user['idu']]);
                        $app->auth()->refreshAfterPasswordChange($newHash); // this ends the other sign-ins of this account
                        // apps connected over OAuth (Claude) always go – their refresh token lives a year (N39-1); personal tokens on request
                        $revoked = \Kaleta\Front\OAuth::revokeConnections($db, (int) $user['idu'])
                            + ($r->postBool('zrusit_tokeny') ? $db->delete('api_tokeny', ['idu' => $user['idu'], 'druh' => 'token']) : 0);
                        ChangeLog::write($app, 'ucet', 'password_change', $revoked > 0 ? t('connection tokens revoked: %d', $revoked) : '');
                        $message = ['ok', $revoked > 0 ? 'The password has been changed, other sign-ins ended and connection tokens revoked.' : 'The password has been changed and other sign-ins of this account have been ended.'];
                    }
                    break;
                case 'token_novy':
                    if (!Extensions::isEnabled($app->settings(), 'claude')) {
                        break;
                    }
                    if ($app->auth()->isMissingRequired2fa($app->settings())) {
                        $message = ['chyba', 'The website requires two-step sign-in – you can create a token once you turn it on.'];
                        break;
                    }
                    $token = 'kaleta_' . bin2hex(random_bytes(24));
                    $access = \Kaleta\Front\OAuth::access($r->post('access') ?: 'full');
                    // a token with an expiry ends by itself (2.8); one without works until it is revoked and System status reports it
                    $days = in_array($r->postInt('platnost', 365), self::TOKEN_LIFETIMES, true) ? $r->postInt('platnost', 365) : 365;
                    $db->insert('api_tokeny', ['idu' => $user['idu'], 'nazev' => mb_substr($r->post('nazev') ?: 'Claude', 0, 100), 'access' => $access, 'otisk' => hash('sha256', $token), 'vytvoren' => date('Y-m-d H:i:s'),
                        'expirace' => $days > 0 ? date('Y-m-d H:i:s', time() + $days * 86400) : null]);
                    ChangeLog::write($app, 'ucet', 'claude_token', $access . ($days > 0 ? ', ' . $days . ' days' : ', no expiry'));
                    // the token is shown only now - hence no redirect
                    return $this->page(['newToken' => $token] + $data);
                case 'token_smaz':
                    $db->delete('api_tokeny', ['idt' => $r->postInt('smaz_token'), 'idu' => $user['idu']]);
                    $message = ['ok', 'Token revoked.'];
                    break;
                case 'aplikace_odpojit':
                    $db->delete('api_tokeny', ['klient' => $r->post('odpojit_klient'), 'idu' => $user['idu']]);
                    ChangeLog::write($app, 'ucet', 'odpojena aplikace');
                    $message = ['ok', 'The application is disconnected – it will not get into the website until you allow it again.'];
                    break;
                case 'totp_start':
                    $app->session->set('totp_nove', Totp::newSecret());
                    break;
                case 'totp_potvrd':
                    $secret = (string) $app->session->get('totp_nove', '');
                    if ($secret === '' || !Totp::verify($secret, $r->post('kod'))) {
                        $message = ['chyba', 'The code does not match. Check the time on your phone and try again.'];
                        break;
                    }
                    [$codes, $json] = Totp::backupCodes();
                    $db->update('uzivatele', ['totp_tajemstvi' => $secret, 'totp_zalozni' => $json], ['idu' => $user['idu']]);
                    $app->session->remove('totp_nove');
                    ChangeLog::write($app, 'ucet', 'two_factor_on');
                    // the backup codes are shown only now - hence no redirect
                    return $this->page(['backupCodes' => $codes] + $data);
                case 'klic_moznosti':
                case 'klic_uloz':
                    return $this->key($r->post('co') === 'klic_uloz', $user);
                case 'klic_smaz':
                    $db->run('DELETE FROM {uzivatele_klice} WHERE idk = ? AND idu = ?', [$r->postInt('idk'), $user['idu']]);
                    ChangeLog::write($app, 'ucet', 'passkey_remove');
                    $message = ['ok', 'The passkey has been removed.'];
                    break;
                case 'totp_vypni':
                    if (!password_verify((string) ($_POST['soucasne'] ?? ''), $user['password'])) {
                        $message = ['chyba', 'Enter the correct password to turn it off.'];
                        break;
                    }
                    $db->update('uzivatele', ['totp_tajemstvi' => '', 'totp_zalozni' => null], ['idu' => $user['idu']]);
                    $db->run('DELETE FROM {uzivatele_klice} WHERE idu = ?', [$user['idu']]); // keys replace the code from the app - without it they make no sense
                    ChangeLog::write($app, 'ucet', 'two_factor_off');
                    $message = ['ok', 'Two-factor sign-in is turned off.'];
                    break;
            }
            if ($message !== null) {
                $app->session->flash(...$message);

                return Response::redirect($app->url('admin.php?action=account'));
            }
        }

        return $this->page(['newSecret' => (string) $app->session->get('totp_nove', '')] + $data);
    }

    /**
     * Registration of a passkey (fingerprint, Face ID, security key) - called by the script image/klice.js.
     * A key can be added only to an account with two-factor sign-in enabled: it is a more convenient replacement of the
     * code from the app, the code and the backup codes remain as a fallback in case the device is lost.
     * The challenge is issued only to whoever types the current password (3.3.3, N56): a stolen session alone must not add
     * the attacker's own key. Saving needs that challenge, so it needs the password too.
     *
     * @param array<string, mixed> $user
     */
    private function key(bool $save, array $user): Response
    {
        $app = $this->kernel->app;
        if ((string) $user['totp_tajemstvi'] === '') {
            return Response::json(['chyba' => t('Turn on two-factor sign-in first.')], 400);
        }
        $url = $app->settings()->get('site_url') ?: $app->request->origin();
        if (!$save) {
            if (!password_verify((string) ($_POST['soucasne'] ?? ''), $user['password'])) {
                return Response::json(['chyba' => t('Enter your current password to add a passkey.')], 403);
            }
            $challenge = Passkey::challenge();
            $app->session->set('klic_registrace', $challenge);

            return Response::json(Passkey::registrationOptions(
                $challenge, Passkey::rpId($url), $app->settings()->get('site_name'),
                Passkey::b64(substr(hash('sha256', 'kaleta-klic|' . $url . '|' . $user['idu'], true), 0, 16)),
                (string) $user['user'], (string) $user['jmeno'],
                array_map(static fn (array $k): string => (string) $k['id_klice'], $app->auth()->accountKeys((int) $user['idu'])),
            ));
        }
        $challenge = (string) $app->session->get('klic_registrace', '');
        $app->session->remove('klic_registrace');
        try {
            $new = Passkey::verifyRegistration((array) json_decode((string) ($_POST['odpoved'] ?? ''), true), $challenge, Passkey::origin($url), Passkey::rpId($url));
        } catch (\RuntimeException $e) {
            return Response::json(['chyba' => t($e->getMessage())], 400);
        }
        $hash = hash('sha256', Passkey::fromB64($new['id']));
        if ($app->db()->value('SELECT idk FROM {uzivatele_klice} WHERE otisk_id = ?', [$hash]) !== null) {
            return Response::json(['chyba' => t('This key is already registered.')], 400);
        }
        $name = mb_substr(trim($app->request->post('nazev')), 0, 80);
        $app->db()->insert('uzivatele_klice', [
            'idu' => $user['idu'], 'nazev' => $name !== '' ? $name : t('Passkey'), 'otisk_id' => $hash, 'id_klice' => $new['id'],
            'verejny' => $new['klic'], 'alg' => $new['alg'], 'pocitadlo' => $new['pocitadlo'], 'vytvoreno' => date('Y-m-d H:i:s'),
        ]);
        ChangeLog::write($app, 'ucet', 'passkey_add', $name);
        $app->session->flash('ok', 'The passkey has been added. Next time you sign in you can use it instead of the code from the app.');

        return Response::json(['ok' => true]);
    }

    /**
     * The old address learns that the account's e-mail changed (3.3.3, N56) – whoever took over a session cannot quietly
     * move the password reset to their own mailbox. In the account's admin language; nothing to send when it had none.
     *
     * @param array<string, mixed> $user the account before the change
     */
    private function noticeOfNewEmail(array $user, string $newEmail): void
    {
        $app = $this->kernel->app;
        $old = (string) $user['email'];
        if ($old === '') {
            return;
        }
        $site = $app->settings()->get('site_name');
        $language = (string) ($user['jazyk'] ?? '') !== '' ? (string) $user['jazyk'] : \Kaleta\Core\Language::defaults($app->settings());
        [$subject, $text] = \Kaleta\Core\Language::runWith($language, fn (): array => [
            t('The e-mail address of your account was changed') . ' – ' . $site,
            t('Hello,') . "\n\n" . t('the e-mail address of the account %s in the administration of %s was changed to %s.', (string) $user['user'], $site, $newEmail !== '' ? $newEmail : '–')
                . "\n\n" . t('If you did not change it, tell the administrator of the site at once – someone else may be using your account.'),
        ], 'admin-');
        \Kaleta\Core\Mail::send($app->settings(), $old, $subject, $text);
    }

    /** @param array<string, mixed> $data */
    private function page(array $data): Response
    {
        $app = $this->kernel->app;
        $user = $app->db()->one('SELECT * FROM {uzivatele} WHERE idu = ?', [$app->auth()->id()]);

        return $this->kernel->page('My account', $app->view->render('admin/account', $data + [
            'app' => $app, 'user' => $user, 'csrf' => $app->session->csrfField(),
            'uri' => $data['newSecret'] !== '' ? Totp::uri($data['newSecret'], $user['user'], $app->settings()->get('site_name')) : '',
            'codesLeft' => count((array) json_decode((string) $user['totp_zalozni'], true)),
            'claude' => Extensions::isEnabled($app->settings(), 'claude'),
            'keys' => $app->auth()->accountKeys((int) $user['idu']),
            'tokens' => $app->db()->all("SELECT * FROM {api_tokeny} WHERE idu = ? AND druh = 'token' ORDER BY idt DESC", [$user['idu']]),
            // apps connected via OAuth (the Claude connector): one item per client, valid while it has a refresh token
            'apps' => $app->db()->all("SELECT klient, MAX(nazev) AS nazev, MAX(access) AS access, MIN(vytvoren) AS vytvoren, MAX(pouzit) AS pouzit FROM {api_tokeny} WHERE idu = ? AND klient IS NOT NULL AND expirace > ? GROUP BY klient ORDER BY MIN(vytvoren) DESC",
                [$user['idu'], date('Y-m-d H:i:s')]),
            'mcpUrl' => $app->request->origin() . $app->url('mcp'),
        ]));
    }
}
