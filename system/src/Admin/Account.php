<?php

declare(strict_types=1);

namespace Kaleta\Admin;

use Kaleta\Core\Passkey;
use Kaleta\Core\SignInPolicy;
use Kaleta\Core\Response;
use Kaleta\Core\Extensions;
use Kaleta\Core\Totp;

/**
 * My account: own name and e-mail, password change, two-factor sign-in, the own e-mail signature (3.14, tab=signature) and
 * the colleagues (3.37, tab=colleagues).
 * Available to every signed-in user.
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

        if ($r->isPost() && in_array($r->post('krok'), ['passkey_confirm_options', 'passkey_confirm'], true)) {
            return $this->confirmWithPasskey();
        }
        if ($r->isPost()) {
            $message = null;
            switch ($r->postInt('smaz_token') > 0 ? 'token_smaz' : ($r->post('odpojit_klient') !== '' ? 'aplikace_odpojit' : ($r->post('tool_set_switch') !== '' ? 'tool_set' : $r->post('co')))) {
                case 'profil':
                    if ($r->post('email') !== '' && filter_var($r->post('email'), FILTER_VALIDATE_EMAIL) === false) {
                        $message = ['chyba', 'The e-mail address is not valid.'];
                        break;
                    }
                    $phone = \Kaleta\Core\Colleagues::phone($r->post('phone'));
                    if ($phone === null) {
                        $message = ['chyba', 'The phone number may contain only digits, spaces and + ( ) / . -'];
                        break;
                    }
                    $email = mb_substr($r->post('email'), 0, 190);
                    $emailChanged = $email !== (string) $user['email'];
                    // the e-mail is where a password reset goes: changing it needs the current password, like a new password
                    // does – a stolen session alone must not take the account over (3.3.3, N56)
                    if ($emailChanged && ($message = $this->passwordRefused('Enter your current password to change the e-mail address. Nothing was saved.')) !== null) {
                        break;
                    }
                    $db->update('uzivatele', ['jmeno' => mb_substr($r->post('jmeno'), 0, 100), 'email' => $email, 'url' => mb_substr($r->post('url'), 0, 255), 'pozice' => mb_substr($r->post('pozice'), 0, 100), 'phone' => $phone, 'foto' => mb_substr($r->post('foto'), 0, 255), 'bio' => mb_substr($r->post('bio'), 0, 1200),
                        // admin language, Czech explicitly too – an empty value would mean the site language
                        'jazyk' => isset(\Kaleta\Core\Language::ADMIN_LANGUAGES[$r->post('jazyk')]) ? $r->post('jazyk') : '',
                        // form of address in the German administration: '' = formal
                        'register' => $r->post('register') === 'informal' ? 'informal' : ''], ['idu' => $user['idu']]);
                    if ($emailChanged) {
                        \Kaleta\Admin\PasswordReset::cancel($db, (int) $user['idu']); // 3.41 (N113): a link sent to the old address ends
                        ChangeLog::write($app, 'ucet', 'email_change');
                        $this->noticeOfNewEmail($user, $email);
                    }
                    $message = ['ok', 'Details saved.'];
                    break;
                case 'notifications':
                    // 3.26: how notifications reach me by e-mail (Core\Inbox) – the bell shows them whatever I choose
                    $db->update('uzivatele', ['notify_email' => \Kaleta\Core\Inbox::emailMode($r->post('notify_email'))], ['idu' => $user['idu']]);
                    $message = ['ok', 'Your choice is saved.'];
                    break;
                case 'heslo':
                    $newItems = (string) ($_POST['nove'] ?? '');
                    // the new password is checked first: a too short or mistyped one does not cost an attempt of the current one
                    $message = match (true) {
                        mb_strlen($newItems) < 10 => ['chyba', 'The new password must be at least 10 characters long.'],
                        $newItems !== (string) ($_POST['nove2'] ?? '') => ['chyba', 'The new passwords do not match.'],
                        default => $this->passwordRefused('The current password is not correct.'),
                    };
                    if ($message === null) {
                        $newHash = password_hash($newItems, PASSWORD_DEFAULT);
                        $db->update('uzivatele', ['password' => $newHash, 'obnova_otisk' => '', 'obnova_cas' => null], ['idu' => $user['idu']]); // 3.41 (N113): with any reset link
                        $app->auth()->refreshAfterPasswordChange($newHash); // this ends the other sign-ins of this account
                        // apps connected over OAuth (Claude) always go – their refresh token lives a year (N39-1). Personal tokens
                        // go too (3.31.2, N101) unless the form says to keep them: the unticked box sends zrusit_tokeny=0, and a
                        // request without the field revokes – a token would otherwise outlive the password it was made under
                        $revoked = \Kaleta\Front\OAuth::revokeConnections($db, (int) $user['idu'])
                            + (self::keepsTokens($r->post('zrusit_tokeny')) ? 0 : $db->delete('api_tokeny', ['idu' => $user['idu'], 'druh' => 'token']));
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
                    // 3.31.2 (N101): a token works without the password and the second factor, at /mcp, with this account's
                    // role – a signed-in session alone must not mint one (an unattended computer, a stolen cookie)
                    if (($message = $this->passwordRefused('Enter your current password to create a token. Nothing was created.')) !== null) {
                        break;
                    }
                    $token = 'kaleta_' . bin2hex(random_bytes(24));
                    $access = \Kaleta\Front\OAuth::access($r->post('access') ?: 'drafts'); // no choice sent: drafts only (3.11, N311-3)
                    // 3.10: a personal token lists all tools unless the person picks the essentials (Claude Code and scripts)
                    $toolSet = \Kaleta\Mcp\Catalog::toolSet($r->post('tool_set'));
                    $toolSet = in_array($toolSet, \Kaleta\Mcp\Catalog::offered($app->settings()), true) ? $toolSet : 'all'; // 3.36: the fleet set only on a fleet site
                    // a token with an expiry ends by itself (2.8); one without works until it is revoked and System status reports it
                    $days = in_array($r->postInt('platnost', 365), self::TOKEN_LIFETIMES, true) ? $r->postInt('platnost', 365) : 365;
                    $db->insert('api_tokeny', ['idu' => $user['idu'], 'nazev' => mb_substr($r->post('nazev') ?: 'Claude', 0, 100), 'access' => $access, 'tool_set' => $toolSet, 'otisk' => hash('sha256', $token), 'vytvoren' => date('Y-m-d H:i:s'),
                        'expirace' => $days > 0 ? date('Y-m-d H:i:s', time() + $days * 86400) : null]);
                    ChangeLog::write($app, 'ucet', 'claude_token', $access . ($toolSet !== 'all' ? ', ' . $toolSet : '') . ($days > 0 ? ', ' . $days . ' days' : ', no expiry'));
                    // the token is shown only now - hence no redirect
                    return $this->page(['newToken' => $token] + $data);
                case 'token_smaz':
                    $db->delete('api_tokeny', ['idt' => $r->postInt('smaz_token'), 'idu' => $user['idu']]);
                    $message = ['ok', 'Token revoked.'];
                    break;
                case 'tool_set':
                    // 3.10: an existing connection switches between the essentials and all tools – "<set>:token:<id>" for a
                    // personal token, "<set>:app:<client_id>" for every token of a connected application (a refresh copies it);
                    // 3.36: to any tool set (it only decides what tools/list shows first – never what the connection may run)
                    $parts = explode(':', $r->post('tool_set_switch'), 3);
                    $toolSet = \Kaleta\Mcp\Catalog::toolSet($parts[0]);
                    if (!in_array($toolSet, \Kaleta\Mcp\Catalog::offered($app->settings()), true)) { // 3.36: the fleet set only on a fleet site
                        $message = ['chyba', 'This tool set is not offered on this site. Nothing was changed.'];
                        break;
                    }
                    $changed = match ($parts[1] ?? '') {
                        'token' => $db->run("UPDATE {api_tokeny} SET tool_set = ? WHERE idt = ? AND idu = ? AND druh = 'token'", [$toolSet, (int) ($parts[2] ?? 0), $user['idu']])->rowCount(),
                        // a client id is 32 hex characters (the column is ASCII since 3.12 – other text would be an SQL error)
                        'app' => preg_match('/^[a-f0-9]{32}$/D', (string) ($parts[2] ?? '')) === 1
                            ? $db->run('UPDATE {api_tokeny} SET tool_set = ? WHERE klient = ? AND idu = ?', [$toolSet, (string) $parts[2], $user['idu']])->rowCount() : 0,
                        default => 0,
                    };
                    if ($changed > 0) {
                        ChangeLog::write($app, 'ucet', 'claude_tool_set', $toolSet);
                    }
                    $message = ['ok', match ($toolSet) {
                        'essentials' => 'Done – Claude will load the essentials in the next conversation it starts and find the other tools when it needs them.',
                        'work' => 'Done – Claude will load the work tools in the next conversation it starts and find the other tools when it needs them.',
                        'migration' => 'Done – Claude will load the migration tools in the next conversation it starts and find the other tools when it needs them.',
                        'fleet' => 'Done – Claude will load the fleet tools in the next conversation it starts and find the other tools when it needs them.',
                        default => 'Done – Claude will see all tools in the next conversation it starts.',
                    }];
                    break;
                case 'aplikace_odpojit':
                    if (preg_match('/^[a-f0-9]{32}$/D', $r->post('odpojit_klient')) === 1) { // an OAuth client id is 32 hex characters (3.12)
                        $db->delete('api_tokeny', ['klient' => $r->post('odpojit_klient'), 'idu' => $user['idu']]);
                        \Kaleta\Front\OAuth::forgetRotations($db, (int) $user['idu'], $r->post('odpojit_klient')); // N318-3
                    }
                    ChangeLog::write($app, 'ucet', 'odpojena aplikace');
                    $message = ['ok', 'The application is disconnected – it will not get into the website until you allow it again.'];
                    break;
                case 'totp_start':
                case 'totp_potvrd':
                    // 3.31.2 (N100): a session alone never sets or replaces the second factor. An account that has one keeps
                    // it until it is turned off (that asks for the password, below) – so the owner's app and backup codes
                    // cannot be swapped under them; setting one up asks for the current password, like a new passkey does.
                    // Recovery stays as it was: a backup code at sign-in, or an administrator resets it in Users.
                    if ((string) $user['totp_tajemstvi'] !== '') {
                        $app->session->remove('totp_nove');
                        $message = ['chyba', 'Two-factor sign-in is already on. To set it up again, turn it off first.'];
                        break;
                    }
                    if ($r->post('co') === 'totp_start') {
                        $app->session->set('totp_nove', Totp::newSecret());
                        break;
                    }
                    if (($message = $this->passwordRefused('Enter your current password to turn on two-factor sign-in. Nothing was changed.')) !== null) {
                        break;
                    }
                    $secret = (string) $app->session->get('totp_nove', '');
                    if ($secret === '' || !Totp::verify($secret, $r->post('kod'))) {
                        $message = ['chyba', 'The code does not match. Check the time on your phone and try again.'];
                        break;
                    }
                    [$codes, $json] = Totp::backupCodes();
                    if (!$app->auth()->replaceBackupCodes((int) $user['idu'], $json, false, ['totp_tajemstvi' => \Kaleta\Core\Vault::sealSeed($app->db(), $app->settings(), $secret)])) { // 3.35 review L4: compare-and-swap
                        // 3.35 re-review N3: nothing was stored (the list kept changing) – no codes to show, nothing logged
                        $message = ['chyba', self::CODES_NOT_SAVED];
                        break;
                    }
                    $app->session->remove('totp_nove');
                    ChangeLog::write($app, 'ucet', 'two_factor_on');
                    $app->auth()->newEpoch((int) $user['idu'], true); // 3.41 (N116): the other sign-ins end
                    // the backup codes are shown only now - hence no redirect
                    return $this->page(['backupCodes' => $codes] + $data);
                case 'klic_moznosti':
                case 'klic_uloz':
                    return $this->key($r->post('co') === 'klic_uloz', $user);
                case 'klic_smaz':
                    // 3.35: removing a passkey needs a fresh confirmation, like adding one – the password, or a passkey or
                    // backup-code step a few minutes ago – so a stolen session cannot strip the owner's passkeys
                    if (($message = $this->freshnessRefused('Enter your current password to remove a passkey. Nothing was removed.')) !== null) {
                        break;
                    }
                    $name = (string) $db->value('SELECT nazev FROM {uzivatele_klice} WHERE idk = ? AND idu = ?', [$r->postInt('idk'), $user['idu']]);
                    $db->run('DELETE FROM {uzivatele_klice} WHERE idk = ? AND idu = ?', [$r->postInt('idk'), $user['idu']]);
                    $app->auth()->newEpoch((int) $user['idu'], true); // 3.41 (N116)
                    ChangeLog::write($app, 'ucet', 'passkey_remove', $name);
                    $message = ['ok', 'The passkey has been removed.'];
                    break;
                case 'klic_prejmenuj':
                    // 3.35: a name is only a label for the owner – renaming needs no password
                    $name = mb_substr(trim($r->post('nazev')), 0, 80);
                    if ($name !== '') {
                        $db->run('UPDATE {uzivatele_klice} SET nazev = ? WHERE idk = ? AND idu = ?', [$name, $r->postInt('idk'), $user['idu']]);
                    }
                    $message = ['ok', 'The passkey has been renamed.'];
                    break;
                case 'passkey_only':
                    // 3.35: the account's own "passkey only" – both ways need a fresh confirmation; it applies once the account
                    // has a working passkey (Core\SignInPolicy), so it can be ticked only then
                    $on = $r->postBool('passkey_only');
                    if (($message = $this->freshnessRefused('Enter your current password to change how you sign in. Nothing was changed.')) !== null) {
                        break;
                    }
                    if ($on && !SignInPolicy::hasWorkingPasskey($app->auth()->workingKeys((int) $user['idu']))) {
                        $message = ['chyba', 'Register a passkey that signs in without a password first.'];
                        break;
                    }
                    $db->update('uzivatele', ['passkey_only' => $on ? 1 : 0], ['idu' => $user['idu']]);
                    $app->auth()->newEpoch((int) $user['idu'], true); // 3.41 (N116)
                    ChangeLog::write($app, 'ucet', $on ? 'passkey_only_on' : 'passkey_only_off');
                    $message = ['ok', $on ? 'Done – you sign in with your passkey only. A backup code after the password stays your way back if you lose it.' : 'Done – you can sign in with your password and the second step again.'];
                    break;
                case 'backup_codes_new':
                    // 3.35 (owner decision 11 Nov 2026): an account with a passkey and without the authenticator app gets new
                    // backup codes here – a fresh confirmation first (a passkey or backup-code step a few minutes ago, or the
                    // current password); the new codes replace all the old ones and are shown once (no redirect). An account
                    // with the app gets new codes by turning the app off and on again, as before.
                    if (!self::mayRenewBackupCodes($user, $app->auth()->accountKeys((int) $user['idu']))) {
                        $message = ['chyba', self::RENEW_CODES_REFUSAL];
                        break;
                    }
                    if (($message = $this->freshnessRefused('Enter your current password to create new backup codes. Nothing was changed.')) !== null) {
                        break;
                    }
                    [$codes, $json] = Totp::backupCodes();
                    if (!$app->auth()->replaceBackupCodes((int) $user['idu'], $json)) { // 3.35 review L4: compare-and-swap of the stored list
                        $message = ['chyba', self::CODES_NOT_SAVED]; // 3.35 re-review N3: nothing stored, nothing shown or logged
                        break;
                    }
                    $app->auth()->newEpoch((int) $user['idu'], true); // 3.41 review L2: new codes are a second-factor change – the other sign-ins end
                    ChangeLog::write($app, 'ucet', 'backup_codes_new');

                    return $this->page(['backupCodes' => $codes, 'codesFromPasskey' => true, 'codesRenewed' => true] + $data);
                case 'totp_vypni':
                    if (($message = $this->passwordRefused('Enter the correct password to turn it off.')) !== null) {
                        break;
                    }
                    // 3.35: passkeys no longer depend on the app – they stay, and with them the backup codes (the way back
                    // when a passkey is lost); an account without passkeys loses the codes with the app, as before
                    $keepsCodes = $app->auth()->accountKeys((int) $user['idu']) !== [];
                    $db->update('uzivatele', ['totp_tajemstvi' => ''] + ($keepsCodes ? [] : ['totp_zalozni' => null]), ['idu' => $user['idu']]);
                    $app->auth()->newEpoch((int) $user['idu'], true); // 3.41 (N116)
                    ChangeLog::write($app, 'ucet', 'two_factor_off');
                    $message = ['ok', $keepsCodes ? 'Two-factor sign-in with the app is turned off. Your passkeys and backup codes stay.' : 'Two-factor sign-in is turned off.'];
                    break;
            }
            if ($message !== null) {
                $app->session->flash(...$message);

                return Response::redirect($app->url('admin.php?action=account'));
            }
        }

        if ($r->get('tab') === 'signature') {
            return $this->signature();
        }
        if ($r->get('tab') === 'colleagues') {
            return $this->colleagues();
        }

        // 3.35: backup codes created with the first passkey are shown once, on the page the registration returns to
        $shown = $app->session->get('backup_codes_show');
        $app->session->remove('backup_codes_show');
        if (is_array($shown) && $shown !== []) {
            $data['backupCodes'] = array_values(array_map('strval', $shown));
            $data['codesFromPasskey'] = true;
        }

        return $this->page(['newSecret' => (string) $app->session->get('totp_nove', '')] + $data);
    }

    /** 3.35: why new backup codes are refused – the account has no passkey, or it has the authenticator app. */
    /** 3.35 re-review N3: the backup codes could not be stored (the stored list kept changing under the compare-and-swap). */
    public const string CODES_NOT_SAVED = 'The backup codes could not be saved because your account was being changed at the same moment. Nothing was changed – please try again.';

    public const string RENEW_CODES_REFUSAL = 'New backup codes are made here for an account with a passkey and without an authenticator app. With the app, turn two-factor sign-in off and on again.';

    /**
     * 3.35: may the account create new backup codes in My account – it has a passkey and no authenticator app? Pure.
     *
     * @param array<string, mixed> $user
     * @param list<array<string, mixed>> $keys
     */
    public static function mayRenewBackupCodes(array $user, array $keys): bool
    {
        return $keys !== [] && (string) ($user['totp_tajemstvi'] ?? '') === '';
    }

    /**
     * A fresh confirmation for changes of passkeys and of how the account signs in (3.35): a passkey or backup-code step in
     * the last few minutes (Auth::isFreshlyVerified), otherwise the current password (passwordRefused – the sign-in's
     * counter and lock). null = confirmed.
     *
     * @return array{0: string, 1: string}|null
     */
    private function freshnessRefused(string $wrong): ?array
    {
        return $this->proofRefused($wrong, 'strong');
    }

    /**
     * The current password typed in the form, checked by Auth::confirmPassword (3.31.2 – the same counter and lock as the
     * sign-in): null when it is right, otherwise the message to show – $wrong, or that the account is locked for a while.
     *
     * @return array{0: string, 1: string}|null
     */
    private function passwordRefused(string $wrong): ?array
    {
        return $this->proofRefused($wrong, 'password');
    }

    /**
     * 3.35 review M2: both checks above go through Auth::freshProof – an account that signs in with a passkey only
     * (SignInPolicy::passkeyOnly) is never confirmed by its password here: only by a strong proof (a passkey or
     * backup-code step a few minutes ago, or "Confirm with your passkey"), the same rule as the OAuth consent.
     *
     * @param 'password'|'strong' $accepts
     * @return array{0: string, 1: string}|null
     */
    private function proofRefused(string $wrong, string $accepts): ?array
    {
        return match ($this->kernel->app->auth()->freshProof(is_string($_POST['soucasne'] ?? null) ? $_POST['soucasne'] : '', $accepts)) {
            'ok' => null,
            'locked' => ['chyba', \Kaleta\Core\Auth::CONFIRM_LOCKED],
            'passkey' => ['chyba', \Kaleta\Core\Auth::PASSKEY_PROOF],
            default => ['chyba', $wrong],
        };
    }

    /**
     * 3.35 review M2: "Confirm with your passkey" (image/klice.js, data-klic-potvrdit) – the strong fresh proof of an
     * account that signs in with a passkey only, for My account and for the forms of Settings and Users that point here:
     * a challenge for the account's own passkeys, then their signature (Auth::confirmWithPasskey – the sign-in's counter,
     * markVerified(true)). The page the button is on reloads with the proof.
     */
    private function confirmWithPasskey(): Response
    {
        $app = $this->kernel->app;
        $siteUrl = $app->settings()->get('site_url') ?: $app->request->origin();
        if ($app->request->post('krok') === 'passkey_confirm_options') {
            $options = $app->auth()->confirmChallenge($siteUrl);

            return Response::json($options ?? ['chyba' => t('This account has no passkey.')], $options === null ? 400 : 200);
        }
        $result = $app->auth()->confirmWithPasskey((array) json_decode((string) ($_POST['odpoved'] ?? ''), true), $siteUrl, \Kaleta\Core\Firewall::visitorKey($app->request, $app->settings()));
        if ($result === 'ok') {
            ChangeLog::write($app, 'ucet', 'passkey_confirm');
        }

        return Response::json($result === 'ok' ? ['ok' => true] : ['chyba' => t($result === 'locked' ? Kernel::PASSKEY_CONFIRM_LOCKED : self::PASSKEY_CONFIRM_FAILED)], $result === 'ok' ? 200 : 401);
    }

    /** 3.35 review M2: the answer of a passkey confirmation in My account that did not succeed. */
    public const string PASSKEY_CONFIRM_FAILED = 'The passkey did not confirm it. Try again, or sign in again with your passkey.';

    /**
     * Whether a password change keeps the account's personal tokens (3.31.2, N101): only when the form explicitly says so –
     * the box "also revoke connection tokens" unticked sends "0" from the hidden field before it. Ticked ("1") or no field at
     * all (another client, an old form) revokes. Used by My account and by Users.
     */
    public static function keepsTokens(string $posted): bool
    {
        return $posted === '0';
    }

    /**
     * My e-mail signature (3.14): the signature from the signed-in user's own record in the Team collection
     * (Builder\TeamRecord – chosen by an administrator, or by the e-mail), for every signed-in user, without access to
     * Collections; 3.37: without a record, the fallback signature from the account and the company details
     * (Builder\UserSignature). Nothing in the request selects the record: only the account decides, so nobody sees another person's.
     */
    private function signature(): Response
    {
        $app = $this->kernel->app;
        $db = $app->db();
        $user = (array) $db->one('SELECT * FROM {uzivatele} WHERE idu = ?', [$app->auth()->id()]);
        $signature = \Kaleta\Builder\UserSignature::forUser($app, $user);

        return $this->kernel->page('My e-mail signature', $app->view->render('admin/account_signature', [
            'app' => $app,
            'teamExists' => \Kaleta\Builder\TeamRecord::collections($db) !== [],
            'record' => $signature['record'],
            'signature' => $signature,
        ]));
    }

    /**
     * Colleagues (3.37, Core\Colleagues): everyone with an active account – name, position, work phone, e-mail and photo –
     * for every signed-in user, whatever sections they may open.
     */
    private function colleagues(): Response
    {
        $app = $this->kernel->app;

        return $this->kernel->page('Colleagues', $app->view->render('admin/account_colleagues', [
            'app' => $app,
            'colleagues' => \Kaleta\Core\Colleagues::all($app->db()),
        ]));
    }

    /**
     * Registration of a passkey (fingerprint, Face ID, security key) - called by the script image/klice.js.
     * 3.35: any account may add one (two-factor sign-in is no longer a precondition). Every new passkey is discoverable and
     * verifies the user, so it signs in without a password and is the second step after the password; its user handle
     * is the account's opaque passkey_handle. The first passkey of an account without backup codes brings eight of them
     * (shown once) – the way back when the device is lost.
     * The challenge is issued only after a fresh confirmation (3.3.3, N56; 3.35): the current password, or a passkey or
     * backup-code step a few minutes ago – a stolen session alone must not add the attacker's own key. Saving needs that
     * challenge, so it needs the confirmation too. "Register again" ($_POST nahradit) replaces a passkey of the account
     * registered as the second step before 3.35 by the new one.
     *
     * @param array<string, mixed> $user
     */
    private function key(bool $save, array $user): Response
    {
        $app = $this->kernel->app;
        $db = $app->db();
        $url = $app->settings()->get('site_url') ?: $app->request->origin();
        if (!$save) {
            if (($refused = $this->freshnessRefused('Enter your current password to add a passkey.')) !== null) {
                return Response::json(['chyba' => t($refused[1])], 403);
            }
            $handle = (string) $user['passkey_handle'];
            if ($handle === '') {
                // 3.35 review L3: written only while the account still has none, then read back – two option requests at
                // the same moment (two tabs, two devices) end with one handle, never a passkey with a handle the account lost
                $db->run("UPDATE {uzivatele} SET passkey_handle = ? WHERE idu = ? AND passkey_handle = ''", [Passkey::newUserHandle(), $user['idu']]);
                $handle = (string) $db->value('SELECT passkey_handle FROM {uzivatele} WHERE idu = ?', [$user['idu']]);
            }
            $challenge = Passkey::challenge();
            $app->session->set('klic_registrace', ['challenge' => $challenge, 'at' => time()]);
            // only passkeys that already sign in without a password are excluded: the device that holds an older second-step
            // passkey of the account may register again
            $existing = array_values(array_filter($app->auth()->accountKeys((int) $user['idu']), static fn (array $k): bool => !empty($k['discoverable'])));

            return Response::json(Passkey::registrationOptions(
                $challenge, Passkey::rpId($url), $app->settings()->get('site_name'), $handle,
                (string) $user['user'], (string) $user['jmeno'],
                array_map(static fn (array $k): string => (string) $k['id_klice'], $existing),
            ) + ['extensions' => ['credProps' => true]]);
        }
        $pending = $app->session->get('klic_registrace');
        $app->session->remove('klic_registrace');
        // a challenge of the old form (a string, a page opened before 3.35) or one older than 5 minutes is not valid
        $challenge = is_array($pending) && time() - (int) ($pending['at'] ?? 0) < 300 ? (string) ($pending['challenge'] ?? '') : '';
        $response = (array) json_decode((string) ($_POST['odpoved'] ?? ''), true);
        try {
            $new = Passkey::verifyRegistration($response, $challenge, Passkey::origin($url), Passkey::rpId($url), true);
        } catch (\RuntimeException $e) {
            return Response::json(['chyba' => t($e->getMessage())], 400);
        }
        if (($response['rk'] ?? '') === '0') {
            // the browser said the device did not keep the passkey (credProps): it could not sign in without a password
            return Response::json(['chyba' => t('This device cannot keep a passkey that signs in without a password. Try another device or a password manager with passkeys.')], 400);
        }
        $hash = hash('sha256', Passkey::fromB64($new['id']));
        if ($db->value('SELECT idk FROM {uzivatele_klice} WHERE otisk_id = ?', [$hash]) !== null) {
            return Response::json(['chyba' => t('This key is already registered.')], 400);
        }
        $name = mb_substr(trim($app->request->post('nazev')), 0, 80);
        $replaced = $app->request->postInt('nahradit') > 0
            ? $db->one('SELECT idk, nazev FROM {uzivatele_klice} WHERE idk = ? AND idu = ? AND discoverable = 0', [$app->request->postInt('nahradit'), $user['idu']]) : null;
        if ($name === '' && $replaced !== null) {
            $name = (string) $replaced['nazev'];
        }
        $firstCodes = SignInPolicy::backupCodesLeft($user) === 0 ? Totp::backupCodes() : null;
        $db->transaction(function () use ($db, $app, $user, $name, $hash, $new, $replaced, &$firstCodes, $url): void {
            $db->insert('uzivatele_klice', [
                'idu' => $user['idu'], 'nazev' => $name !== '' ? $name : t('Passkey'), 'otisk_id' => $hash, 'id_klice' => $new['id'],
                'verejny' => $new['klic'], 'alg' => $new['alg'], 'pocitadlo' => $new['pocitadlo'], 'vytvoreno' => date('Y-m-d H:i:s'), 'discoverable' => 1,
                'rp_id' => Passkey::rpId($url), // 3.35 review M3: the host the passkey is bound to (SignInPolicy::keysForHost)
            ]);
            if ($replaced !== null) {
                $db->run('DELETE FROM {uzivatele_klice} WHERE idk = ?', [$replaced['idk']]);
            }
            if ($firstCodes !== null && !$app->auth()->replaceBackupCodes((int) $user['idu'], $firstCodes[1], true)) {
                $firstCodes = null; // 3.35 review L4: codes made meanwhile (another tab) stay – nothing new to show
            }
        });
        $app->auth()->newEpoch((int) $user['idu'], true); // 3.41 (N116): the other sign-ins of the account end
        ChangeLog::write($app, 'ucet', 'passkey_add', $name . ($replaced !== null ? ' (' . t('registered again') . ')' : ''));
        if ($firstCodes !== null) {
            $app->session->set('backup_codes_show', $firstCodes[0]);
        }
        $app->session->flash('ok', 'The passkey has been added. Next time you can sign in with it alone – without your user name and password – and it is the second step after your password.');

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
        $user = (array) $app->db()->one('SELECT * FROM {uzivatele} WHERE idu = ?', [$app->auth()->id()]);
        $keys = $app->auth()->accountKeys((int) $user['idu']);
        $working = $app->auth()->workingKeys((int) $user['idu']); // 3.35 review M3: only the passkeys of this host count

        return $this->kernel->page('My account', $app->view->render('admin/account', $data + [
            'app' => $app, 'user' => $user, 'csrf' => $app->session->csrfField(),
            'uri' => $data['newSecret'] !== '' ? Totp::uri($data['newSecret'], $user['user'], $app->settings()->get('site_name')) : '',
            'codesLeft' => \Kaleta\Core\BackupCodes::count($user['totp_zalozni'] !== null ? (string) $user['totp_zalozni'] : null), // 3.35 review L4: both formats
            'claude' => Extensions::isEnabled($app->settings(), 'claude'),
            'keys' => $keys,
            // 3.35: passkeys and how the account signs in (Core\SignInPolicy)
            'fresh' => $app->auth()->isFreshlyVerified(),
            'passkeyOnly' => SignInPolicy::passkeyOnly($user, $working, $app->settings()),
            'sitePasskeyOnly' => $app->settings()->get('signin_passkey_only') === '1' && !SignInPolicy::escapeHatch(),
            'workingPasskey' => SignInPolicy::hasWorkingPasskey($working),
            'siteRpId' => SignInPolicy::siteRpId($app->settings()), // 3.35 review M3: a passkey of another host is marked in the list
            'renewCodes' => self::mayRenewBackupCodes($user, $keys), // 3.35: "New backup codes"
            'passkeyHost' => SignInPolicy::hostMatches((string) ($app->request->serverValues()['HTTP_HOST'] ?? ''), $app->settings()->get('site_url') ?: $app->request->origin()),
            'tokens' => $app->db()->all("SELECT * FROM {api_tokeny} WHERE idu = ? AND druh = 'token' ORDER BY idt DESC", [$user['idu']]),
            // apps connected via OAuth (the Claude connector): one item per client, valid while it has a refresh token
            // the tool set of an application is the one of its newest token (3.10; a new consent may have chosen another)
            'apps' => $app->db()->all("SELECT klient, MAX(nazev) AS nazev, ELT(MIN(FIELD(access, 'full', 'drafts', 'read')), 'full', 'drafts', 'read') AS access, SUBSTRING_INDEX(GROUP_CONCAT(tool_set ORDER BY idt DESC), ',', 1) AS tool_set, MIN(vytvoren) AS vytvoren, MAX(pouzit) AS pouzit FROM {api_tokeny} WHERE idu = ? AND klient IS NOT NULL AND expirace > ? GROUP BY klient ORDER BY MIN(vytvoren) DESC",
                [$user['idu'], date('Y-m-d H:i:s')]),
            'mcpUrl' => $app->request->origin() . $app->url('mcp'),
        ]));
    }
}
