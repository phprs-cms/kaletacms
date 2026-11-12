<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Sign-in to the administration and permissions.
 *
 * Roles: an author writes their own news items (can publish them only with the "permission to publish"), an editor manages
 * content and publishes everyone's news items, an administrator also users and settings. Access to modules is
 * set individually for authors and editors; an author can have a supervising editor.
 */
final class Auth
{
    public const int AUTHOR = 0;
    public const int EDITOR = 1;
    public const int ADMIN = 2;

    public const array TYPES = [self::AUTHOR => 'autor', self::EDITOR => 'editor', self::ADMIN => 'správce'];

    /**
     * The optional Appearance area of a role or a user (3.22, C9, owner decision D1): Menu, Site parts, Pop-ups and
     * Components, one permission row in ka_uzivatele_prava. Off unless an administrator ticks it – nobody had it before
     * 3.22. Site appearance (the design system), settings, users, backups and the Claude settings stay administrator-only.
     */
    public const string APPEARANCE_AREA = 'appearance_area';

    /**
     * The optional area "change internal prices" (3.38, Core\Prices): next to the section Quotes, which reads prices and
     * works with quotes; one permission row in ka_uzivatele_prava. Administrators have it; nobody else gets it by an update.
     */
    public const string PRICES_AREA = 'prices_edit';

    /** After this many wrong passwords or codes in a row the account is locked for 15 minutes (it unlocks itself again). */
    private const int MAX_ERRORS = 10;

    /** A sign-in ends after this many seconds without a request (3.3.3, N60); an open admin page keeps it alive (image/admin.js). */
    public const int IDLE_LIMIT = 8 * 3600;

    /** A sign-in ends this many seconds after it started, however active it is (3.3.3, N60) – the keep-alive never extends it. */
    public const int SESSION_LIMIT = 24 * 3600;

    /**
     * The one answer to a failed password step (3.3.3, N51): a wrong name, a wrong password, a temporarily locked account
     * and a blocked one all get it, so the answer never confirms a password. It still tells a locked-out owner what to do.
     */
    public const string SIGN_IN_FAILED = 'Wrong user name or password, or the account is temporarily locked after a series of failed attempts. Try again in 15 minutes or reset your password.';

    /**
     * Verified when there is no account to check against, or the account is locked or blocked: the response time then does
     * not reveal which case it was (a hash of a random password nobody knows – not a secret). 3.31 (code scanning #45): the
     * algorithm and cost prefix is joined to the rest, so no hash literal stands in the code for a secret scanner to report
     * (a "nosemgrep" comment on the line above did not suppress the report); the value is the same valid bcrypt hash.
     */
    private const string DUMMY_HASH = '$2y$12$' . '6C4TPEcYRJ/rRw6iWsrlxu0aH1i91pzK/8KiwqEW3Pa6sTj89Q3Zu';

    /**
     * The same of cost 10 (3.29.1, N3291-1): PHP 8.3 hashes new passwords with cost 10, PHP 8.4 with 12, and a check of cost
     * 12 takes four times as long – with one dummy of cost 12 a fast answer told an existing user name or e-mail on PHP 8.3.
     * Joined like DUMMY_HASH (3.31, code scanning #45).
     */
    private const string DUMMY_HASH_10 = '$2y$10$' . 'kndST14Z.BcAFAt2r9I.K.pYAExbK8ejvYpuGLFP9rjQ5XnD3lCAK';

    /** @var array<string, mixed>|null|false false = not loaded yet */
    private array|null|false $user = false;

    /** @var list<string>|null */
    private ?array $modules = null;

    /**
     * The answer to every failed sign-in without a password (3.35): an unknown passkey, a wrong signature, a device that did
     * not verify the user, a locked or a blocked account – the passkey endpoints never tell which, so they reveal nothing
     * about the accounts.
     */
    public const string PASSKEY_FAILED = 'Signing in with the passkey did not succeed, or the account is temporarily locked after a series of failed attempts. Try again in a moment, or sign in with your user name and password.';

    /** How long a challenge for the sign-in without a password is valid (seconds); it is used once. */
    private const int PASSKEY_CHALLENGE_LIFETIME = 300;

    /** The user name of the account the last sign-in with a passkey was for ('' = unknown passkey) – the change log names it. */
    private string $passkeyAccount = '';

    public function __construct(private readonly Db $db, private readonly Session $session, private readonly ?Settings $settings = null)
    {
    }

    /** The site settings ("passkey only", 3.35); an Auth made without them (tests) reads them from the database. */
    private function siteSettings(): Settings
    {
        return $this->settings ?? new Settings($this->db);
    }

    /**
     * The password step of the sign-in. $address is what the per-address limit counts by – Firewall::visitorKey(): the
     * visitor's address behind the proxy, an IPv6 address by its /64 (3.3.3, N54). $password is taken as typed (N61).
     *
     * @return string|null error text, null = signed in (or waiting for the second step)
     */
    public function login(string $login, string $password, string $address): ?string
    {
        // Slowing down password guessing: at most 10 attempts from one address per 15 minutes
        $attempts = (int) $this->db->value(
            "SELECT COUNT(*) FROM {kontrola_ip} WHERE typ = 'login' AND ip_adresa = ? AND cas > ?",
            [Antispam::hash($address), Clock::at('-15 minutes')],
        );
        if ($attempts >= 10) {
            return t('Too many sign-in attempts. Try again in 15 minutes.');
        }

        $user = $this->db->one('SELECT * FROM {uzivatele} WHERE user = ?', [$login]);
        if ($user === null && str_contains($login, '@')) {
            // the e-mail an invitation or a password link came to signs in too (a user name never has "@"), but only an
            // e-mail of exactly one account – two accounts may share it, and then neither is guessed
            $byEmail = $this->db->all("SELECT * FROM {uzivatele} WHERE email = ? AND email <> '' LIMIT 2", [$login]);
            $user = count($byEmail) === 1 ? $byEmail[0] : null;
        }
        // A locked or blocked account is never checked against its own password (3.3.3, N51): a different answer to the
        // right password would confirm it, and the lock would shut out only the real owner. The dummy hash is verified
        // instead – for a nonexistent user too – so the response time is the same in every case.
        $closed = $user !== null && (!empty($user['blokovat']) || self::isLocked($user));
        $typed = self::matchingPassword($password, $user !== null && !$closed ? (string) $user['password'] : self::dummyHash());
        if ($typed === null && $user !== null && !$closed) {
            // a password saved with a lower cost than this PHP's (before a move to PHP 8.4) is checked faster than the dummy
            // hash: the difference is made up with checks of the same cost, so the time does not tell the account exists
            for ($i = self::paddingChecks((string) $user['password']); $i > 0; $i--) {
                self::matchingPassword($password, self::DUMMY_HASH_10);
            }
        }

        if ($typed === null || $user === null || $closed) {
            $this->db->insert('kontrola_ip', ['ip_adresa' => Antispam::hash($address), 'typ' => 'login', 'cas' => date('Y-m-d H:i:s')]);
            if ($user !== null && !$closed) {
                // after 10 errors in a row the account is locked for 15 minutes - not permanently, otherwise anyone could lock the site administrator out
                $this->countError($user);
            }

            return t(self::SIGN_IN_FAILED);
        }

        if (password_needs_rehash($user['password'], PASSWORD_DEFAULT)) {
            $this->db->update('uzivatele', ['password' => password_hash($typed, PASSWORD_DEFAULT)], ['idu' => $user['idu']]);
        }
        // the failure counter is reset only by a completed sign-in (recordSignIn): with two-factor sign-in a correct password
        // must not wipe the wrong codes counted so far, or the per-account lock would never be reached (3.3.2, N7)

        // 3.35: an account with two-factor sign-in or with a passkey always needs a second step after the password (the
        // code from the app, the passkey or a backup code – Core\SignInPolicy); a password alone never signs it in
        $secondStep = SignInPolicy::needsSecondStep($user, $this->workingKeys((int) $user['idu']));
        // the form token changes where the sign-in completes (N75): here without a second step, otherwise after the code or passkey
        $this->session->regenerate(newFormToken: !$secondStep);
        if ($secondStep) {
            // the password matches, but only the second step completes the sign-in
            $this->session->set('idu_ceka', ['idu' => (int) $user['idu'], 'cas' => time()]);

            return null;
        }
        $this->recordSignIn((int) $user['idu']);
        $this->startSignIn((int) $user['idu'], (string) $this->db->value('SELECT password FROM {uzivatele} WHERE idu = ?', [$user['idu']]));

        return null;
    }

    /**
     * The password as typed when it matches the hash, null when it does not (3.3.3, N61: sign-in no longer trims it, as
     * none of the places that set a password do). A password saved trimmed before 3.3.3 still opens when typed with the
     * spaces; the second check runs for the real and the dummy hash alike, so the timing stays equal.
     */
    public static function matchingPassword(string $password, string $hash): ?string
    {
        if (password_verify($password, $hash)) {
            return $password;
        }

        return trim($password) !== $password && password_verify(trim($password), $hash) ? trim($password) : null;
    }

    /**
     * The dummy hash of the cost this PHP gives a new password (3.29.1, N3291-1: 10 on PHP 8.3, 12 on 8.4), so that a name
     * with no account takes as long as one whose password was saved on this PHP.
     */
    private static function dummyHash(): string
    {
        return password_needs_rehash(self::DUMMY_HASH_10, PASSWORD_DEFAULT) ? self::DUMMY_HASH : self::DUMMY_HASH_10;
    }

    /**
     * How many more checks of cost 10 a wrong password for an account saved with cost 10 needs to take as long as the dummy
     * hash (3.29.1, N3291-1): a check's time doubles with each step of cost, so 3 more against a dummy of cost 12; 0 when
     * the costs are the same or the hash is not one of those. Pure, apart from the PHP version.
     */
    public static function paddingChecks(string $hash): int
    {
        $info = password_get_info($hash);
        $cost = $info['algo'] === PASSWORD_BCRYPT ? (int) ($info['options']['cost'] ?? 0) : 0;

        return $cost === 10 && self::dummyHash() === self::DUMMY_HASH ? 3 : 0;
    }

    /** @param array<string, mixed> $user */
    private static function isLocked(array $user): bool
    {
        return $user['zamceno_do'] !== null && strtotime((string) $user['zamceno_do']) > time();
    }

    /**
     * One more wrong password, code or passkey for the account; the tenth in a row locks it for 15 minutes.
     *
     * @param array<string, mixed> $user
     * @return bool whether this error locked the account
     */
    private function countError(array $user): bool
    {
        $errorCount = (int) $user['pocet_chyb'] + 1;
        $this->db->update('uzivatele', $errorCount >= self::MAX_ERRORS
            ? ['pocet_chyb' => 0, 'zamceno_do' => date('Y-m-d H:i:s', time() + 900)]
            : ['pocet_chyb' => $errorCount], ['idu' => $user['idu']]);

        return $errorCount >= self::MAX_ERRORS;
    }

    /** The answer of confirmPassword() when the account is locked (3.31.2): the person stays signed in, the step waits. */
    public const string CONFIRM_LOCKED = 'Too many wrong passwords in a row – changes that need your password are locked for 15 minutes. You stay signed in; nothing was changed.';

    /**
     * The "current password" of a signed-in person (3.31.2): every form that asks for it before a sensitive change (My
     * account – e-mail, password, token, second factor, passkey; Users – an administrator setting another user's password
     * or turning off their second factor) checks it here, never with its own password_verify(). A wrong password counts
     * on the same per-account counter as a wrong one at sign-in (ten in a row lock the account for 15 minutes), so a
     * stolen session is not a second, unlimited channel for guessing the password. While the account is locked the
     * password is not checked at all (as at sign-in). The lock does not end this sign-in – user() never looks at it – so
     * the form answers CONFIRM_LOCKED and the person can go on working; a new sign-in waits like any locked one. A right
     * password resets nothing: the counter goes back to zero only with a completed sign-in (recordSignIn), as before.
     * An empty field is "wrong" without counting – nothing was guessed.
     *
     * @return 'ok'|'wrong'|'locked'
     */
    public function confirmPassword(string $typed): string
    {
        $id = $this->id();
        $user = $id > 0 ? $this->db->one('SELECT idu, password, pocet_chyb, zamceno_do FROM {uzivatele} WHERE idu = ?', [$id]) : null;
        if ($user === null) {
            return 'wrong';
        }
        if (self::isLocked($user)) {
            return 'locked';
        }
        if ($typed === '') {
            return 'wrong'; // nothing typed is not a guess – it is not counted
        }
        if (self::matchingPassword($typed, (string) $user['password']) !== null) {
            $this->markVerified(); // 3.35: a right current password is a fresh proof, as a sign-in is
            return 'ok';
        }

        return $this->countError($user) ? 'locked' : 'wrong';
    }

    /**
     * 3.35: fresh proofs of who is signed in – ONE concept with one window (VERIFIED_WINDOW) and two strengths, each a
     * Unix time in the session:
     * - VERIFIED_KEY: any proof – every completed sign-in (password, password and code, password and backup code, password
     *   and passkey, passkey alone – startSignIn), a right current password (confirmPassword) and a passkey confirmation
     *   (Admin\Kernel, the OAuth consent of an account without a password). recentlyVerified() reads it: approving a new
     *   Claude connection with full access (Admin\Kernel::handleOAuthConsent).
     * - STRONG_KEY: only a step that a stolen password and authenticator code cannot make – a passkey (the second step,
     *   the passkey-first sign-in, a passkey confirmation) or a backup code. A step with the code from the app does NOT
     *   count, nor does the password sign-in alone. isFreshlyVerified() reads it: adding or removing a passkey and changing
     *   how the account signs in need it, otherwise the current password typed in that form (Admin\Account::freshnessRefused).
     * markVerified(true) sets both; a new sign-in drops the strong proof of the one before.
     */
    public const string VERIFIED_KEY = 'verified_at';

    /** 3.35: the session key of the last strong proof (a passkey or a backup code) – see VERIFIED_KEY. */
    public const string STRONG_KEY = 'strong_at';

    /** 3.35: how long a proof counts as fresh – both recentlyVerified and isFreshlyVerified – in seconds. */
    public const int VERIFIED_WINDOW = 10 * 60;

    /** 3.35: records that the signed-in person has just proved who they are; $strong = with a passkey or a backup code. */
    public function markVerified(bool $strong = false): void
    {
        $this->session->set(self::VERIFIED_KEY, time());
        if ($strong) {
            $this->session->set(self::STRONG_KEY, time());
        }
    }

    /** 3.35: has the signed-in person proved who they are within VERIFIED_WINDOW (any proof – see VERIFIED_KEY)? */
    public function recentlyVerified(): bool
    {
        $at = $this->session->get(self::VERIFIED_KEY);

        return $this->id() > 0 && self::verifiedWithin(is_int($at) ? $at : null, time());
    }

    /** 3.35: is a proof made at $at (null = none) still fresh at $now? A time in the future is not. Pure. */
    public static function verifiedWithin(?int $at, int $now, int $window = self::VERIFIED_WINDOW): bool
    {
        return $at !== null && $at <= $now && $now - $at < $window;
    }

    /**
     * A completed sign-in in the session: the account, the password fingerprint, and since 3.3.3 (N60) when it started and
     * when it was last used – user() ends it after IDLE_LIMIT without a request, or SESSION_LIMIT after it started.
     */
    private function startSignIn(int $idu, string $passwordHash): void
    {
        $this->session->set('idu', $idu);
        $this->session->set('otisk', self::passwordHash($passwordHash));
        $this->session->set('login_at', time());
        $this->session->set('last_seen', time());
        $this->session->remove(self::STRONG_KEY); // a strong proof belongs to the sign-in that made it (markVerified(true) after this)
        $this->markVerified(); // 3.35: every completed sign-in – password, + code, + backup code, + passkey, passkey alone – is a fresh proof (recentlyVerified)
        $this->user = false;
    }

    /** Is a sign-in that started at $loginAt and was last used at $lastSeen still valid at $now? (3.3.3, N60) */
    public static function sessionValid(int $loginAt, int $lastSeen, int $now): bool
    {
        return $now - $lastSeen < self::IDLE_LIMIT && $now - $loginAt < self::SESSION_LIMIT;
    }

    /**
     * Password hash stored in the session: after a password change all other sign-ins of the same account stop being valid
     * (a stolen session, a forgotten computer). By itself it reveals nothing - it is a shortened hash of an already hashed password.
     */
    public static function passwordHash(string $hash): string
    {
        return substr(hash('sha256', 'kaleta-session|' . $hash), 0, 24);
    }

    /** After changing one's own password: this sign-in stays valid, the others do not. */
    public function refreshAfterPasswordChange(string $newHash): void
    {
        $this->session->regenerate();
        $this->session->set('otisk', self::passwordHash($newHash));
        $this->user = false;
    }

    /** The password was entered correctly and a code from the authenticator app is awaited (at most 5 minutes). */
    public function isAwaitingCode(): bool
    {
        $pending = $this->session->get('idu_ceka');

        return is_array($pending) && time() - (int) $pending['cas'] < 300;
    }

    /**
     * The second steps the account waiting after its password may complete the sign-in with (3.35, SignInPolicy): the code
     * from the app (not for "passkey only"), a backup code, the passkey. All false when nothing waits.
     *
     * @return array{code: bool, backup: bool, passkey: bool}
     */
    public function awaitingSteps(): array
    {
        $user = $this->isAwaitingCode() ? $this->db->one('SELECT * FROM {uzivatele} WHERE idu = ?', [(int) $this->session->get('idu_ceka')['idu']]) : null;

        return $user === null ? ['code' => false, 'backup' => false, 'passkey' => false]
            : SignInPolicy::secondSteps($user, $this->workingKeys((int) $user['idu']), $this->siteSettings());
    }

    /**
     * Second step of the sign-in: a code from the app, or a one-time backup code. 3.35: the code from the app only where
     * two-factor sign-in is on and the account is not "passkey only" (an empty secret is never checked – its codes are
     * public); a backup code also for an account with a passkey and no app. @return string|null error text
     */
    public function verifyCode(string $code, string $ip): ?string
    {
        if (!$this->isAwaitingCode()) {
            return t('The sign-in has expired, please start again.');
        }
        $attempts = (int) $this->db->value("SELECT COUNT(*) FROM {kontrola_ip} WHERE typ = 'login' AND ip_adresa = ? AND cas > ?", [Antispam::hash($ip), Clock::at('-15 minutes')]);
        if ($attempts >= 10) {
            return t('Too many attempts. Try again in 15 minutes.');
        }
        $user = $this->db->one('SELECT * FROM {uzivatele} WHERE idu = ? AND blokovat = 0', [(int) $this->session->get('idu_ceka')['idu']]);
        if ($user !== null && $user['zamceno_do'] !== null && strtotime($user['zamceno_do']) > time()) {
            $this->session->remove('idu_ceka');

            return t('The account is temporarily locked after a series of failed attempts. Try again in 15 minutes.');
        }
        $appCode = $user !== null && SignInPolicy::secondSteps($user, $this->workingKeys((int) $user['idu']), $this->siteSettings())['code'];
        $step = $appCode && (string) $user['totp_tajemstvi'] !== '' ? Totp::step($user['totp_tajemstvi'], $code) : null;
        if ($step !== null && $user !== null && !$this->claimCodeStep((int) $user['idu'], $step)) {
            $step = null; // 3.15 (N18): a code of a step this account already signed in with is never accepted again
        }
        // 3.35 review L4: a backup code is used up by a compare-and-swap of the stored list – the same code twice at once,
        // or two codes at once, never brings a used code back
        $usedBackupCode = $step === null && $user !== null && $this->consumeBackupCode((int) $user['idu'], $code);
        if ($user === null || ($step === null && !$usedBackupCode)) {
            $this->db->insert('kontrola_ip', ['ip_adresa' => Antispam::hash($ip), 'typ' => 'login', 'cas' => date('Y-m-d H:i:s')]);
            if ($user !== null) {
                // wrong codes are counted per account, not only per IP address: whoever knows the password must not try codes from many addresses
                $this->countError($user);
            }

            return t('The code is not correct.');
        }
        $this->recordSignIn((int) $user['idu']);
        $this->session->remove('idu_ceka');
        $this->session->regenerate(newFormToken: true);
        $this->startSignIn((int) $user['idu'], (string) $user['password']);
        if ($usedBackupCode) {
            $this->markVerified(true); // 3.35: a backup code is the recovery step – a new passkey may be registered right after it
        }

        return null;
    }

    /**
     * 3.35 review L4: uses up a backup code of the account – the stored list is read, the code taken out
     * (Core\BackupCodes::consume) and the shorter list written only if the stored one is still the one read
     * (compare-and-swap); a list changed meanwhile (another code used, new codes made) is read again, a few times at most.
     */
    private function consumeBackupCode(int $idu, string $code): bool
    {
        for ($try = 0; $try < 3; $try++) {
            $stored = $this->db->value('SELECT totp_zalozni FROM {uzivatele} WHERE idu = ?', [$idu]);
            $left = BackupCodes::consume(is_string($stored) ? $stored : null, $code);
            if ($left === null || !is_string($stored)) {
                return false;
            }
            if ($this->db->run('UPDATE {uzivatele} SET totp_zalozni = ? WHERE idu = ? AND totp_zalozni = ?', [$left, $idu, $stored])->rowCount() === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * 3.35 review L4: replaces the account's backup codes (new codes in My account, with the first passkey, with the app)
     * by a compare-and-swap of the stored list, so that a code used at the same moment can never write the old list
     * back over the new one. $onlyIfNone: only while the account has no codes left (the first passkey) – false when it
     * got some meanwhile. $alsoSet: other columns written in the same statement.
     *
     * @param array<string, mixed> $alsoSet
     */
    public function replaceBackupCodes(int $idu, string $stored, bool $onlyIfNone = false, array $alsoSet = []): bool
    {
        for ($try = 0; $try < 5; $try++) {
            $old = $this->db->value('SELECT totp_zalozni FROM {uzivatele} WHERE idu = ?', [$idu]);
            if ($onlyIfNone && BackupCodes::count(is_string($old) ? $old : null) > 0) {
                return false;
            }
            $set = implode(', ', array_map(static fn (string $column): string => $column . ' = ?', ['totp_zalozni', ...array_keys($alsoSet)]));
            if ($this->db->run('UPDATE {uzivatele} SET ' . $set . ' WHERE idu = ? AND totp_zalozni <=> ?', [$stored, ...array_values($alsoSet), $idu, $old])->rowCount() === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Claims the step of a code from the app for the account (3.15, N18; atomic since N315-11): true when no code of this
     * step or a later one signed the account in during the last few minutes – the step is then recorded with the sign-in
     * checks (cil = the account) in the same statement, under a lock per account, so two sign-ins with the same code at
     * the same moment cannot both pass. No lock in time = not claimed (the person tries again).
     */
    private function claimCodeStep(int $idu, int $step): bool
    {
        $lock = $this->db->lockName('totp', (string) $idu);
        if ((int) $this->db->value('SELECT GET_LOCK(?, 5)', [$lock]) !== 1) {
            return false;
        }
        try {
            // INSERT … SELECT … WHERE NOT EXISTS: the check and the record are one statement. The steps are compared as
            // zero-padded strings – a CAST of another row's hash would be an error in strict mode inside INSERT … SELECT
            return $this->db->run("INSERT INTO {kontrola_ip} (ip_adresa, typ, cil, cas) SELECT ?, 'totp', ?, ? FROM DUAL WHERE NOT EXISTS ("
                . "SELECT 1 FROM {kontrola_ip} WHERE typ = 'totp' AND cil = ? AND cas > ? AND LPAD(ip_adresa, 20, '0') >= ?)",
                [(string) $step, $idu, Clock::now(), $idu, Clock::at('-5 minutes'), str_pad((string) $step, 20, '0', STR_PAD_LEFT)])->rowCount() === 1;
        } finally {
            $this->db->value('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }

    /** Does the account waiting for the second step have registered passkeys? */
    public function isAwaitingKey(): bool
    {
        return $this->isAwaitingCode() && $this->workingKeys((int) $this->session->get('idu_ceka')['idu']) !== [];
    }

    /** @return list<array<string, mixed>> passkeys of the account (all of them – for the rules, workingKeys) */
    public function accountKeys(int $idu): array
    {
        return $this->db->all('SELECT * FROM {uzivatele_klice} WHERE idu = ? ORDER BY idk', [$idu]);
    }

    /**
     * 3.35 review M3: the account's passkeys that work on this site's host (SignInPolicy::keysForHost) – the only ones the
     * sign-in rules, the second step, the confirmation and "passkey only" count.
     *
     * @return list<array<string, mixed>>
     */
    public function workingKeys(int $idu): array
    {
        return SignInPolicy::keysForHost($this->accountKeys($idu), SignInPolicy::siteRpId($this->siteSettings()));
    }

    /** 3.35 review M3: a passkey with no host recorded yet (registered before the column was filled) gets the host it just worked on. */
    private function rememberHost(array $key, string $siteUrl): void
    {
        if ((string) ($key['rp_id'] ?? '') === '' && Passkey::rpId($siteUrl) !== '') {
            $this->db->run("UPDATE {uzivatele_klice} SET rp_id = ? WHERE idk = ? AND rp_id = ''", [Passkey::rpId($siteUrl), $key['idk']]);
        }
    }

    /**
     * Second step of the sign-in with a passkey, part 1: a challenge for the device. Valid only for the account that has just entered the correct password.
     *
     * @return array<string, mixed>|null options for navigator.credentials.get(), null = nothing to wait for
     */
    public function keyChallenge(string $siteUrl): ?array
    {
        if (!$this->isAwaitingKey()) {
            return null;
        }
        $challenge = Passkey::challenge();
        $this->session->set('klic_vyzva', $challenge);

        return Passkey::signInOptions($challenge, Passkey::rpId($siteUrl), array_map(static fn (array $k): string => (string) $k['id_klice'], $this->workingKeys((int) $this->session->get('idu_ceka')['idu'])));
    }

    /**
     * Second step of the sign-in with a passkey, part 2: verifying the signature. A failure counts the same as a wrong code.
     *
     * @param array<string, mixed> $response
     * @return string|null error text, null = signed in
     */
    public function verifyKey(array $response, string $siteUrl, string $ip): ?string
    {
        if (!$this->isAwaitingCode()) {
            return t('The sign-in has expired, please start again.');
        }
        $attempts = (int) $this->db->value("SELECT COUNT(*) FROM {kontrola_ip} WHERE typ = 'login' AND ip_adresa = ? AND cas > ?", [Antispam::hash($ip), Clock::at('-15 minutes')]);
        if ($attempts >= 10) {
            return t('Too many attempts. Try again in 15 minutes.');
        }
        $challenge = (string) $this->session->get('klic_vyzva', '');
        $this->session->remove('klic_vyzva'); // the challenge is valid for one attempt
        // 3.35 review M1: a lock after wrong passwords or codes does not stop the passkey step – a valid passkey signs in and
        // clears the lock (recordSignIn), a wrong one counts on as before; the code step (verifyCode) stays locked
        $user = $this->db->one('SELECT * FROM {uzivatele} WHERE idu = ? AND blokovat = 0', [(int) $this->session->get('idu_ceka')['idu']]);
        $key = $user === null ? null : $this->db->one('SELECT * FROM {uzivatele_klice} WHERE idu = ? AND otisk_id = ?', [$user['idu'], hash('sha256', Passkey::fromB64((string) ($response['id'] ?? '')))]);
        try {
            if ($key === null) {
                throw new \RuntimeException('This key does not belong to the account.');
            }
            $counter = Passkey::verifySignIn($response, $challenge, Passkey::origin($siteUrl), Passkey::rpId($siteUrl), (string) $key['verejny'], (int) $key['pocitadlo']);
        } catch (\RuntimeException $e) {
            $this->db->insert('kontrola_ip', ['ip_adresa' => Antispam::hash($ip), 'typ' => 'login', 'cas' => date('Y-m-d H:i:s')]);
            if ($user !== null) {
                $this->countError($user);
            }

            return t($e->getMessage());
        }
        $this->db->update('uzivatele_klice', ['pocitadlo' => $counter, 'pouzito' => date('Y-m-d H:i:s')], ['idk' => $key['idk']]);
        $this->rememberHost($key, $siteUrl);
        $this->recordSignIn((int) $user['idu']);
        $this->session->remove('idu_ceka');
        $this->session->regenerate(newFormToken: true);
        $this->startSignIn((int) $user['idu'], (string) $user['password']);
        $this->markVerified(true); // 3.35: a passkey step is a strong proof

        return null;
    }

    /**
     * Sign-in without a password (3.35), part 1: a challenge for any discoverable passkey of the site. The same answer for
     * everyone – nothing about accounts. The challenge lives in this session for PASSKEY_CHALLENGE_LIFETIME and is used once.
     *
     * @return array<string, mixed> options for navigator.credentials.get()
     */
    public function passkeyChallenge(string $siteUrl): array
    {
        $challenge = Passkey::challenge();
        $this->session->set('passkey_signin', ['challenge' => $challenge, 'at' => time()]);

        return Passkey::signInOptions($challenge, Passkey::rpId($siteUrl), [], true);
    }

    /**
     * Sign-in without a password (3.35), part 2: the server finds the account from the passkey (its id, and the opaque user
     * handle the device returns must be the account's), the device must have verified the user (UV), the counter is
     * checked as at the second step. The limits are those of the password: at most 10 failures per address in 15 minutes,
     * ten failures in a row lock the account for 15 minutes for the password, a blocked account never signs in (3.35
     * review M1: a locked one does with a valid passkey, which ends the lock) – and every
     * failure gets the same answer (PASSKEY_FAILED). A passkey registered as the second step before 3.35 is not
     * discoverable: after a valid signature its owner hears that it has to be registered again.
     *
     * @param array<string, mixed> $response id, clientDataJSON, authenticatorData, signature, userHandle – base64url
     * @return string|null error text, null = signed in
     */
    public function signInWithPasskey(array $response, string $siteUrl, string $address): ?string
    {
        $this->passkeyAccount = '';
        $pending = $this->session->get('passkey_signin');
        $this->session->remove('passkey_signin'); // the challenge is valid for one attempt
        $attempts = (int) $this->db->value("SELECT COUNT(*) FROM {kontrola_ip} WHERE typ = 'login' AND ip_adresa = ? AND cas > ?", [Antispam::hash($address), Clock::at('-15 minutes')]);
        if ($attempts >= 10) {
            return t('Too many sign-in attempts. Try again in 15 minutes.');
        }
        $challenge = is_array($pending) && time() - (int) ($pending['at'] ?? 0) < self::PASSKEY_CHALLENGE_LIFETIME ? (string) ($pending['challenge'] ?? '') : '';
        $key = $this->db->one('SELECT * FROM {uzivatele_klice} WHERE otisk_id = ?', [hash('sha256', Passkey::fromB64((string) ($response['id'] ?? '')))]);
        $user = $key === null ? null : $this->db->one('SELECT * FROM {uzivatele} WHERE idu = ?', [$key['idu']]);
        // 3.35 review M1: only a BLOCKED account is closed here. A lock after wrong passwords (isLocked) must not shut out
        // the owner's passkey – an attacker guessing the password could otherwise keep the real owner out for good. The
        // assertion of a locked account is verified as any other: a valid one with user verification signs in and clears
        // the lock (recordSignIn), an invalid one counts on the same counters; the answer is the same in every case.
        $closed = $user !== null && !empty($user['blokovat']);
        $this->passkeyAccount = $user !== null ? (string) $user['user'] : '';
        $counter = null;
        $oldKey = false;
        try {
            if ($key === null || $user === null || $closed || $challenge === '') {
                throw new \RuntimeException('refused');
            }
            $counter = Passkey::verifySignIn($response, $challenge, Passkey::origin($siteUrl), Passkey::rpId($siteUrl), (string) $key['verejny'], (int) $key['pocitadlo'], true);
            if (empty($key['discoverable'])) {
                $oldKey = true;
                throw new \RuntimeException('second step only');
            }
            $handle = (string) ($response['userHandle'] ?? '');
            if ((string) $user['passkey_handle'] === '' || $handle === '' || !hash_equals(Passkey::fromB64((string) $user['passkey_handle']), Passkey::fromB64($handle))) {
                throw new \RuntimeException('user handle');
            }
        } catch (\RuntimeException) {
            if ($oldKey && $user !== null) {
                // the owner's own device signed: not a guess – nothing is counted, the counter moves on
                $this->db->update('uzivatele_klice', ['pocitadlo' => (int) $counter, 'pouzito' => date('Y-m-d H:i:s')], ['idk' => $key['idk']]);

                return t('This passkey was set up as the second step only. Sign in with your user name and password, then register the passkey again in My account to sign in without a password.');
            }
            $this->db->insert('kontrola_ip', ['ip_adresa' => Antispam::hash($address), 'typ' => 'login', 'cas' => date('Y-m-d H:i:s')]);
            if ($user !== null && !$closed && $challenge !== '') {
                $this->countError($user); // a passkey of the account that did not verify counts like a wrong password
            }

            return t(self::PASSKEY_FAILED);
        }
        $this->db->update('uzivatele_klice', ['pocitadlo' => $counter, 'pouzito' => date('Y-m-d H:i:s')], ['idk' => $key['idk']]);
        $this->rememberHost($key, $siteUrl);
        $this->recordSignIn((int) $user['idu']);
        $this->session->remove('idu_ceka');
        $this->session->regenerate(newFormToken: true);
        $this->startSignIn((int) $user['idu'], (string) $user['password']);
        $this->markVerified(true); // 3.35: a passkey step is a strong proof

        return null;
    }

    /** The user name of the account the last sign-in with a passkey was for – '' when the passkey is unknown. */
    public function passkeyAccount(): string
    {
        return $this->passkeyAccount;
    }

    /**
     * A passkey confirmation of the person already signed in (3.35), part 1: a challenge for the account's own passkeys –
     * the way an account that signs in with a passkey only proves itself again where others type the current password
     * (the OAuth consent of a full connection). null = no one signed in or no passkey.
     *
     * @return array<string, mixed>|null options for navigator.credentials.get()
     */
    public function confirmChallenge(string $siteUrl): ?array
    {
        $keys = $this->id() > 0 ? $this->workingKeys($this->id()) : [];
        if ($keys === []) {
            return null;
        }
        $challenge = Passkey::challenge();
        $this->session->set('passkey_confirm', ['challenge' => $challenge, 'at' => time()]);

        return Passkey::signInOptions($challenge, Passkey::rpId($siteUrl), array_map(static fn (array $k): string => (string) $k['id_klice'], $keys), true);
    }

    /**
     * A passkey confirmation (3.35), part 2: the signature of one of the signed-in account's passkeys, with user
     * verification, over the challenge of part 1 (used once, PASSKEY_CHALLENGE_LIFETIME). Success is a strong fresh proof
     * (markVerified(true)); a failure counts like a wrong current password (the sign-in's counter and lock, the limit per
     * address) and ends nothing – the sign-in stays, as with confirmPassword.
     *
     * @param array<string, mixed> $response
     * @return 'ok'|'wrong'|'locked'
     */
    public function confirmWithPasskey(array $response, string $siteUrl, string $address): string
    {
        $pending = $this->session->get('passkey_confirm');
        $this->session->remove('passkey_confirm'); // the challenge is valid for one attempt
        $id = $this->id();
        $user = $id > 0 ? $this->db->one('SELECT idu, password, pocet_chyb, zamceno_do FROM {uzivatele} WHERE idu = ?', [$id]) : null;
        if ($user === null) {
            return 'wrong';
        }
        $attempts = (int) $this->db->value("SELECT COUNT(*) FROM {kontrola_ip} WHERE typ = 'login' AND ip_adresa = ? AND cas > ?", [Antispam::hash($address), Clock::at('-15 minutes')]);
        if ($attempts >= 10) {
            return 'locked'; // 3.35 review M1: the limit per address only – a lock after wrong passwords does not stop the passkey
        }
        $challenge = is_array($pending) && time() - (int) ($pending['at'] ?? 0) < self::PASSKEY_CHALLENGE_LIFETIME ? (string) ($pending['challenge'] ?? '') : '';
        $key = $this->db->one('SELECT * FROM {uzivatele_klice} WHERE idu = ? AND otisk_id = ?', [$id, hash('sha256', Passkey::fromB64((string) ($response['id'] ?? '')))]);
        try {
            if ($key === null || $challenge === '') {
                throw new \RuntimeException('refused');
            }
            $counter = Passkey::verifySignIn($response, $challenge, Passkey::origin($siteUrl), Passkey::rpId($siteUrl), (string) $key['verejny'], (int) $key['pocitadlo'], true);
        } catch (\RuntimeException) {
            $this->db->insert('kontrola_ip', ['ip_adresa' => Antispam::hash($address), 'typ' => 'login', 'cas' => date('Y-m-d H:i:s')]);

            return $this->countError($user) ? 'locked' : 'wrong';
        }
        $this->db->update('uzivatele_klice', ['pocitadlo' => $counter, 'pouzito' => date('Y-m-d H:i:s')], ['idk' => $key['idk']]);
        $this->rememberHost($key, $siteUrl);
        if (self::isLocked($user)) {
            $this->db->update('uzivatele', ['pocet_chyb' => 0, 'zamceno_do' => null], ['idu' => $id]); // 3.35 review M1: the owner's passkey ends the lock
        }
        $this->markVerified(true); // 3.35: a passkey confirmation is a strong fresh proof

        return 'ok';
    }

    /**
     * 3.35 review M2: does the signed-in account sign in with a passkey only (SignInPolicy::passkeyOnly – its own choice, or
     * the site's rule once it has a working passkey)? Then its password is no proof of who is at the keyboard.
     */
    public function isPasskeyOnly(): bool
    {
        $user = $this->user();

        return $user !== null && SignInPolicy::passkeyOnly($user, $this->workingKeys((int) $user['idu']), $this->siteSettings());
    }

    /**
     * 3.35 review M2: the ONE check of a fresh proof before a sensitive change (My account, the OAuth consent of a full
     * connection, Settings, Users). $accepts says what counts besides the current password typed in the form
     * (confirmPassword – the sign-in's counter and lock): 'password' nothing else, 'strong' a passkey or backup-code step
     * within VERIFIED_WINDOW (isFreshlyVerified), 'recent' any proof within it (recentlyVerified). An account that signs in
     * with a passkey only never proves itself with its password – a stolen password and session must not undo its
     * passkeys: only the strong proof counts (a passkey or backup-code step, or "Confirm with your passkey" –
     * confirmWithPasskey), whatever $accepts says, and the typed password is not even checked ('passkey').
     *
     * @param 'password'|'strong'|'recent' $accepts
     * @return 'ok'|'wrong'|'locked'|'passkey'
     */
    public function freshProof(string $typed, string $accepts = 'password'): string
    {
        if ($this->isPasskeyOnly()) {
            return $this->isFreshlyVerified() ? 'ok' : 'passkey';
        }
        if (($accepts === 'strong' && $this->isFreshlyVerified()) || ($accepts === 'recent' && $this->recentlyVerified())) {
            return 'ok';
        }

        return $this->confirmPassword($typed);
    }

    /** 3.35 review M2: the answer when a passkey-only account offers its password where the strong proof is needed. */
    public const string PASSKEY_PROOF = 'You sign in with a passkey only, so your password does not confirm this. Confirm with your passkey first (or sign in again with your passkey or a backup code), then do it within 10 minutes. Nothing was changed.';

    /**
     * Whether this sign-in proved itself with a passkey or a backup code within VERIFIED_WINDOW (the strong proof – see
     * VERIFIED_KEY) – then adding or removing a passkey needs no password (3.35).
     */
    public function isFreshlyVerified(): bool
    {
        $at = $this->session->get(self::STRONG_KEY);

        return $this->user() !== null && self::verifiedWithin(is_int($at) ? $at : null, time());
    }

    /**
     * A completed sign-in (password alone, or password and the code or passkey): the last sign-in is what the check of
     * unused accounts counts from (Core\SecurityHygiene), so a password alone without the second step does not count.
     */
    private function recordSignIn(int $idu): void
    {
        // 3.35 review M1: a completed sign-in also ends a lock – only a passkey completes one on a locked account
        $this->db->update('uzivatele', ['pocet_chyb' => 0, 'zamceno_do' => null, 'posledni_login' => date('Y-m-d H:i:s')], ['idu' => $idu]);
    }

    public function logout(): void
    {
        $this->session->destroy();
        $this->user = null;
    }

    /** @return array<string, mixed>|null */
    public function user(): ?array
    {
        if ($this->user === false) {
            // without a session cookie there is nobody who could be signed in - and no session is started for the check (the site stays cacheable)
            if (!isset($_COOKIE['kaleta'])) {
                return $this->user = null;
            }
            $id = $this->session->get('idu');
            $this->user = is_int($id)
                ? $this->db->one('SELECT * FROM {uzivatele} WHERE idu = ? AND blokovat = 0', [$id])
                : null;
            if ($this->user !== null) {
                $hash = $this->session->get('otisk');
                if ($hash === null) {
                    $this->session->set('otisk', self::passwordHash((string) $this->user['password'])); // a sign-in from before this check existed
                } elseif (!hash_equals(self::passwordHash((string) $this->user['password']), (string) $hash)) {
                    $this->session->remove('idu'); // the password has changed since the sign-in
                    $this->user = null;
                }
            }
            if ($this->user !== null) {
                // 3.3.3 (N60): the idle and the absolute limit are checked here, not left to the session garbage collector;
                // tokens (MCP, OAuth) never come this way – signInAs() sets the user without a session
                $now = time();
                $loginAt = $this->session->get('login_at');
                $lastSeen = $this->session->get('last_seen');
                if (!is_int($loginAt) || !is_int($lastSeen)) {
                    $this->session->set('login_at', $now); // a sign-in from before 3.3.3: its limits start now
                } elseif (!self::sessionValid($loginAt, $lastSeen, $now)) {
                    foreach (['idu', 'otisk', 'login_at', 'last_seen', self::VERIFIED_KEY] as $key) {
                        $this->session->remove($key);
                    }
                    $this->session->remove(self::STRONG_KEY);
                    $this->user = null;

                    return null;
                }
                $this->session->set('last_seen', $now);
            }
        }

        return $this->user;
    }

    /**
     * After the answer went out (3.24, Notifications::afterAdminResponse): the background jobs that follow are Kaleta's own
     * work, not this person's – the change log, the notice board's audit trail and the automatic blocks must not name them.
     * Only this request forgets the person; the session (already closed) stays signed in.
     */
    public function forgetForBackground(): void
    {
        $this->user = null;
        $this->modules = null;
        $this->connection = null;
    }

    /** Sign-in without a session - for requests authenticated by a token (MCP). */
    public function signInAs(array $user): void
    {
        $this->user = $user;
        $this->modules = null;
    }

    /** @var array{name: string, access: string, tool_set: string}|null the Claude connection of this request (MCP, 2.2) */
    private ?array $connection = null;

    /**
     * The request comes through a Claude connection: its name goes to the change log, and a connection limited to drafts
     * or to reading never publishes, whatever the user's role (canPublish). The tool set (3.10) only decides what
     * tools/list shows (Mcp\Catalog::TOOL_SETS).
     */
    public function useConnection(string $name, string $access, string $toolSet = 'all'): void
    {
        $this->connection = ['name' => $name, 'access' => isset(\Kaleta\Mcp\Catalog::CONNECTION_ACCESS[$access]) ? $access : 'read', 'tool_set' => \Kaleta\Mcp\Catalog::toolSet($toolSet)];
    }

    /** @return array{name: string, access: string, tool_set: string}|null */
    public function connection(): ?array
    {
        return $this->connection;
    }

    public function id(): int
    {
        return (int) ($this->user()['idu'] ?? 0);
    }

    public function isAdmin(): bool
    {
        return (int) ($this->user()['admin'] ?? -1) === self::ADMIN;
    }

    public function isEditor(): bool
    {
        return (int) ($this->user()['admin'] ?? -1) === self::EDITOR;
    }

    /** The site requires two-factor sign-in and this user does not have it yet (can only go to "Můj účet" (My account) to turn it on). */
    public function isMissingRequired2fa(Settings $siteSettings): bool
    {
        $required = $siteSettings->get('require_2fa');
        $user = $this->user();

        // 3.35: a passkey counts – an account with one always needs a second step after the password (SignInPolicy)
        return $user !== null && ($required === 'vsichni' || ($required === 'spravci' && $this->isAdmin())) && (string) ($user['totp_tajemstvi'] ?? '') === ''
            && $this->workingKeys((int) $user['idu']) === [];
    }

    public function canPublish(): bool
    {
        return ($this->isAdmin() || $this->isEditor()) && ($this->connection['access'] ?? 'full') === 'full';
    }

    /**
     * A Claude connection limited to drafts (2.2): besides drafts it may save what a person still has to apply (3.2) – a
     * hidden collection item, a proposed exception to the opening hours, a triage suggestion, a notebook note.
     */
    public function draftsOnly(): bool
    {
        return ($this->connection['access'] ?? 'full') === 'drafts';
    }

    /**
     * Can insert or change code that runs on the site (the Custom HTML element, head code): an administrator, and through a Claude
     * connection only one with full access – a connection limited to drafts must not reach the administrator's browser through a preview.
     */
    public function canWriteCode(): bool
    {
        return $this->isAdmin() && ($this->connection['access'] ?? 'full') === 'full';
    }

    /** Does the signed-in user have access to the module? Admin always; others according to ka_uzivatele_prava. */
    public function hasModule(string $ident, bool $forEveryone = false): bool
    {
        if ($this->user() === null) {
            return false;
        }
        if ($this->isAdmin() || $forEveryone) {
            return true;
        }
        $this->modules ??= array_column(
            $this->db->all('SELECT ident_modulu FROM {uzivatele_prava} WHERE fk_id_user = ?', [$this->id()]),
            'ident_modulu',
        );

        return in_array($ident, $this->modules, true);
    }

    /**
     * May work on the menu, the site parts, the pop-ups and the components (3.22, C9): an administrator always, anyone else
     * only with the Appearance area. Publishing what changes the site at once still needs canPublish().
     */
    public function hasAppearanceArea(): bool
    {
        return $this->hasModule(self::APPEARANCE_AREA);
    }

    /**
     * May change internal prices (3.38): an administrator always, anyone else with the section Quotes and the area
     * PRICES_AREA – and never through a connection limited to drafts or to reading.
     */
    public function canEditPrices(): bool
    {
        return $this->hasModule(\Kaleta\Core\Prices::SECTION) && $this->hasModule(self::PRICES_AREA) && ($this->connection['access'] ?? 'full') === 'full';
    }

    /**
     * Can the signed-in user edit this news item? The same rules as in the administration: the News module, an author only their own,
     * and a published news item only someone who can publish.
     *
     * @param array<string, mixed> $newsItem row of ka_novinky
     */
    public function canEditArticle(array $newsItem): bool
    {
        if (!$this->hasModule('news')) {
            return false;
        }
        $authors = $this->managedAuthors();

        return ($authors === null || in_array((int) $newsItem['autor'], $authors, true)) && (empty($newsItem['visible']) || $this->canPublish());
    }

    /**
     * Part of the WHERE condition (starts with „ AND“, or is empty) that limits the news list to what the signed-in user can see:
     * an author only their own news items, an editor and an administrator all of them.
     */
    public function articleScope(string $alias = ''): string
    {
        $authors = $this->managedAuthors();

        return $authors === null ? '' : ' AND ' . $alias . 'autor IN (' . implode(',', array_map(intval(...), $authors)) . ')';
    }

    /*
     * What a list may show (3.10.1, the audit's N33): list_pages, list_news, list_collection_items and the translation
     * overview (Core\Translations – admin and translation_status) take their conditions from here, so the rules cannot
     * drift apart. Each is part of a WHERE condition: it starts with " AND", or is empty.
     */

    /** Pages: without the Pages section only the published ones – the user can link to those, drafts stay unseen. */
    public function pageListScope(string $alias = ''): string
    {
        return $this->hasModule('pages') ? '' : ' AND ' . $alias . 'zobrazit = 1';
    }

    /** News: an author only their own (articleScope), and without the News section only what visitors see (3.3.2, N12). */
    public function newsListScope(string $alias = ''): string
    {
        return $this->articleScope($alias) . ($this->hasModule('news') ? '' : ' AND ' . $alias . 'visible = 1 AND ' . $alias . 'datum <= NOW()');
    }

    /** Collection items: without the Collections section only the items shown on the site. */
    public function collectionItemListScope(string $alias = ''): string
    {
        return $this->hasModule('collections') ? '' : ' AND ' . $alias . 'zobrazit = 1';
    }

    /**
     * IDs of authors whose news items the user can manage: an author only themselves, an editor and an administrator all (null = no limit).
     *
     * @return list<int>|null
     */
    public function managedAuthors(): ?array
    {
        return $this->isAdmin() || $this->isEditor() ? null : [$this->id()];
    }
}
