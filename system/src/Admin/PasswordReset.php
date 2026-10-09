<?php

declare(strict_types=1);

namespace Kaleta\Admin;

use Kaleta\Core\Antispam;
use Kaleta\Core\App;
use Kaleta\Core\Firewall;
use Kaleta\Core\Language;
use Kaleta\Core\Mail;
use Kaleta\Core\Response;

/**
 * Reset of a forgotten admin password with a link from an e-mail (admin.php?action=password).
 *
 * - The response to a request is always the same, whether the account exists or not - the page does not reveal who manages the site.
 *   The e-mail is queued and sent after the response (3.3.3), so the time of the answer does not reveal it either.
 * - The database holds only a hash of the token; the link is valid for an hour and can be used once.
 * - The reset does NOT DISABLE two-factor sign-in: whoever gains access to the e-mail still cannot sign in without the code from the app.
 * - A password change ends all other sign-ins of the account (the password hash in the session stops matching).
 */
final class PasswordReset
{
    private const int LINK_LIFETIME = 3600;

    public function __construct(private readonly App $app)
    {
    }

    public function handle(): Response
    {
        $r = $this->app->request;
        $token = $r->isPost() ? $r->post('token') : $r->get('token');

        return $token !== '' ? $this->setNewPassword($token) : $this->handleRequest();
    }

    private function handleRequest(): Response
    {
        $app = $this->app;
        $sent = false;
        $error = null;
        if ($app->request->isPost()) {
            // counted by the visitor's address behind the proxy, an IPv6 address by its /64 (3.3.3, N54)
            $ip = Antispam::hash(Firewall::visitorKey($app->request, $app->settings()));
            $attempts = (int) $app->db()->value("SELECT COUNT(*) FROM {kontrola_ip} WHERE typ = 'obnova' AND ip_adresa = ? AND cas > NOW() - INTERVAL 15 MINUTE", [$ip]);
            if ($attempts >= 5) {
                $error = t('Too many requests. Try again in 15 minutes.');
            } else {
                $app->db()->insert('kontrola_ip', ['ip_adresa' => $ip, 'typ' => 'obnova', 'cas' => date('Y-m-d H:i:s')]);
                $who = trim($app->request->post('kdo'));
                $user = $who === '' ? null : $app->db()->one("SELECT * FROM {uzivatele} WHERE (user = ? OR email = ?) AND blokovat = 0 AND email <> '' LIMIT 1", [$who, $who]);
                if ($user !== null) {
                    $this->sendLink($user);
                }
                $sent = true;
            }
        }

        return $this->page(['step' => 'zadost', 'sent' => $sent, 'error' => $error], $error === null ? 200 : 429);
    }

    /**
     * Link to set the password by e-mail: at the user's request (valid for an hour), or as an invitation of a new user
     * or on the administrator's instruction (valid for 3 days – the link is "valid" by the reset time lying in the future).
     *
     * @param array<string, mixed> $user
     */
    public function sendLink(array $user, string $reason = 'zadost'): void
    {
        $app = $this->app;
        $token = bin2hex(random_bytes(32));
        $time = $reason === 'zadost' ? time() : time() + 71 * 3600;
        $app->db()->update('uzivatele', ['obnova_otisk' => hash('sha256', $token), 'obnova_cas' => date('Y-m-d H:i:s', $time)], ['idu' => $user['idu']]);
        $link = rtrim($app->settings()->get('site_url') ?: $app->request->origin(), '/') . $app->url('admin.php?action=password&token=' . $token);
        $language = (string) ($user['jazyk'] ?? '') !== '' ? (string) $user['jazyk'] : Language::defaults($app->settings());
        $siteSettings = $app->settings()->get('site_name');
        [$subject, $text] = Language::runWith($language, fn (): array => match ($reason) {
            'pozvanka' => [t('Invitation to the administration') . ' – ' . $siteSettings,
                t('Hello,') . "\n\n" . t('you have been given access to the administration of the website %s. Your username is %s.', $siteSettings, (string) $user['user'])
                    . "\n\n" . t('Set your password at this address (valid for 3 days, single use):') . "\n" . $link],
            'spravce' => [t('New administration password') . ' – ' . $siteSettings,
                t('Hello,') . "\n\n" . t('the administrator of %s has sent you a link to set a new password for the account %s.', $siteSettings, (string) $user['user'])
                    . "\n\n" . t('Set your password at this address (valid for 3 days, single use):') . "\n" . $link],
            default => [t('New administration password') . ' – ' . $siteSettings,
                t('Hello,') . "\n\n" . t('someone (most likely you) asked for a new password for the account %s in the administration of %s.', (string) $user['user'], $siteSettings)
                    . "\n\n" . t('Set a new password at this address (valid for one hour, can be used once):') . "\n" . $link
                    . "\n\n" . t('If you did not ask for a new password, delete this e-mail – your password stays unchanged.')],
        }, 'admin-', Language::normalizeRegister((string) ($user['register'] ?? ''))); // the recipient's form of address, not that of whoever sent the link
        if ($reason === 'zadost') {
            // a request from the sign-in page: the e-mail goes to the queue and out right after the response (admin.php,
            // Mail::afterResponse), so the answer takes as long whether the account exists or not (3.3.3, N59)
            Mail::later($app->settings(), (string) $user['email'], $subject, $text);
        } else {
            Mail::send($app->settings(), (string) $user['email'], $subject, $text);
        }
        ChangeLog::write($app, 'prihlaseni', 'obnova-hesla', t($reason === 'pozvanka' ? 'invitation sent, account: %s' : 'link sent, account: %s', (string) $user['user']));
    }

    private function setNewPassword(string $token): Response
    {
        $app = $this->app;
        $user = preg_match('/^[a-f0-9]{64}$/D', $token) === 1
            ? $app->db()->one('SELECT * FROM {uzivatele} WHERE obnova_otisk = ? AND blokovat = 0 AND obnova_cas > ?', [hash('sha256', $token), date('Y-m-d H:i:s', time() - self::LINK_LIFETIME)])
            : null;
        if ($user === null) {
            return $this->page(['step' => 'neplatny', 'sent' => false, 'error' => t('The link has expired or has already been used. Request a new one.')], 400);
        }
        $error = null;
        if ($app->request->isPost()) {
            $password = (string) ($_POST['password'] ?? '');
            if (mb_strlen($password) < 10) {
                $error = t('The password must be at least 10 characters long.');
            } elseif ($password !== (string) ($_POST['password2'] ?? '')) {
                $error = t('The passwords do not match.');
            } else {
                $app->db()->update('uzivatele', [
                    'password' => password_hash($password, PASSWORD_DEFAULT), 'obnova_otisk' => '', 'obnova_cas' => null, 'pocet_chyb' => 0, 'zamceno_do' => null,
                ], ['idu' => $user['idu']]);
                // whoever resets the password may have lost the account: connection tokens (MCP) stop being valid
                $app->db()->delete('api_tokeny', ['idu' => $user['idu']]);
                ChangeLog::write($app, 'prihlaseni', 'obnova-hesla', t('password changed, connection tokens revoked, account: %s', (string) $user['user']));
                return Response::redirect($app->url('admin.php?password_changed=1'));
            }
        }

        return $this->page(['step' => 'heslo', 'sent' => false, 'error' => $error, 'token' => $token, 'account' => (string) $user['user']], $error === null ? 200 : 422);
    }

    /** @param array<string, mixed> $data */
    private function page(array $data, int $status): Response
    {
        return Response::html($this->app->view->render('admin/password', ['app' => $this->app, 'token' => '', 'account' => ''] + $data), $status);
    }
}
