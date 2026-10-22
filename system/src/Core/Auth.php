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
     * not reveal which case it was (a hash of a random password nobody knows – not a secret).
     */
    // nosemgrep: generic.secrets.security.detected-bcrypt-hash.detected-bcrypt-hash
    private const string DUMMY_HASH = '$2y$12$6C4TPEcYRJ/rRw6iWsrlxu0aH1i91pzK/8KiwqEW3Pa6sTj89Q3Zu';

    /** @var array<string, mixed>|null|false false = not loaded yet */
    private array|null|false $user = false;

    /** @var list<string>|null */
    private ?array $modules = null;

    public function __construct(private readonly Db $db, private readonly Session $session)
    {
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
        // A locked or blocked account is never checked against its own password (3.3.3, N51): a different answer to the
        // right password would confirm it, and the lock would shut out only the real owner. The dummy hash is verified
        // instead – for a nonexistent user too – so the response time is the same in every case.
        $closed = $user !== null && (!empty($user['blokovat']) || self::isLocked($user));
        $typed = self::matchingPassword($password, $user !== null && !$closed ? (string) $user['password'] : self::DUMMY_HASH);

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

        $this->session->regenerate();
        if ($user['totp_tajemstvi'] !== '') {
            // the password matches, but the account has two-factor sign-in: only the code from the app completes the sign-in
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

    /** @param array<string, mixed> $user */
    private static function isLocked(array $user): bool
    {
        return $user['zamceno_do'] !== null && strtotime((string) $user['zamceno_do']) > time();
    }

    /** One more wrong password, code or passkey for the account; the tenth in a row locks it for 15 minutes. @param array<string, mixed> $user */
    private function countError(array $user): void
    {
        $errorCount = (int) $user['pocet_chyb'] + 1;
        $this->db->update('uzivatele', $errorCount >= self::MAX_ERRORS
            ? ['pocet_chyb' => 0, 'zamceno_do' => date('Y-m-d H:i:s', time() + 900)]
            : ['pocet_chyb' => $errorCount], ['idu' => $user['idu']]);
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

    /** Second step of the sign-in: a code from the app, or a one-time backup code. @return string|null error text */
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
        $backupCodes = $user === null ? null : Totp::useBackupCode($user['totp_zalozni'], $code);
        $step = $user === null ? null : Totp::step($user['totp_tajemstvi'], $code);
        if ($step !== null && $user !== null && !$this->claimCodeStep((int) $user['idu'], $step)) {
            $step = null; // 3.15 (N18): a code of a step this account already signed in with is never accepted again
        }
        if ($user === null || ($step === null && $backupCodes === null)) {
            $this->db->insert('kontrola_ip', ['ip_adresa' => Antispam::hash($ip), 'typ' => 'login', 'cas' => date('Y-m-d H:i:s')]);
            if ($user !== null) {
                // wrong codes are counted per account, not only per IP address: whoever knows the password must not try codes from many addresses
                $this->countError($user);
            }

            return t('The code is not correct.');
        }
        $this->recordSignIn((int) $user['idu']);
        if ($backupCodes !== null) {
            $this->db->update('uzivatele', ['totp_zalozni' => $backupCodes], ['idu' => $user['idu']]);
        }
        $this->session->remove('idu_ceka');
        $this->session->regenerate();
        $this->startSignIn((int) $user['idu'], (string) $user['password']);

        return null;
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
        return $this->isAwaitingCode() && $this->accountKeys((int) $this->session->get('idu_ceka')['idu']) !== [];
    }

    /** @return list<array<string, mixed>> passkeys of the account */
    public function accountKeys(int $idu): array
    {
        return $this->db->all('SELECT * FROM {uzivatele_klice} WHERE idu = ? ORDER BY idk', [$idu]);
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

        return Passkey::signInOptions($challenge, Passkey::rpId($siteUrl), array_map(static fn (array $k): string => (string) $k['id_klice'], $this->accountKeys((int) $this->session->get('idu_ceka')['idu'])));
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
        $user = $this->db->one('SELECT * FROM {uzivatele} WHERE idu = ? AND blokovat = 0', [(int) $this->session->get('idu_ceka')['idu']]);
        if ($user !== null && $user['zamceno_do'] !== null && strtotime($user['zamceno_do']) > time()) {
            $this->session->remove('idu_ceka');

            return t('The account is temporarily locked after a series of failed attempts. Try again in 15 minutes.');
        }
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
        $this->recordSignIn((int) $user['idu']);
        $this->session->remove('idu_ceka');
        $this->session->regenerate();
        $this->startSignIn((int) $user['idu'], (string) $user['password']);

        return null;
    }

    /**
     * A completed sign-in (password alone, or password and the code or passkey): the last sign-in is what the check of
     * unused accounts counts from (Core\SecurityHygiene), so a password alone without the second step does not count.
     */
    private function recordSignIn(int $idu): void
    {
        $this->db->update('uzivatele', ['pocet_chyb' => 0, 'posledni_login' => date('Y-m-d H:i:s')], ['idu' => $idu]);
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
                    foreach (['idu', 'otisk', 'login_at', 'last_seen'] as $key) {
                        $this->session->remove($key);
                    }
                    $this->user = null;

                    return null;
                }
                $this->session->set('last_seen', $now);
            }
        }

        return $this->user;
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

        return $user !== null && ($required === 'vsichni' || ($required === 'spravci' && $this->isAdmin())) && (string) ($user['totp_tajemstvi'] ?? '') === '';
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
