<?php

declare(strict_types=1);

namespace Kaleta\Admin\Modules;

use Kaleta\Admin\Kernel;
use Kaleta\Admin\Module;
use Kaleta\Core\Auth;
use Kaleta\Core\Response;

/**
 * Admin users: accounts, roles (administrator, editor, news author) and optionally manual access to sections.
 */
final class Users extends Module
{
    public const string IDENT = 'users';
    public const string NAME = 'Uživatelé';
    public const string GROUP = 'Administration';
    public const string ICON = 'uzivatele';
    public const bool ADMIN_ONLY = true;

    protected function actionList(): Response
    {
        $authors = $this->db->all('SELECT u.*, r.nazev AS nazev_role, (SELECT COUNT(*) FROM {novinky} c WHERE c.autor = u.idu AND c.smazano IS NULL) AS pocet_clanku FROM {uzivatele} u LEFT JOIN {role} r ON r.idr = u.role ORDER BY u.user');
        $modules = [];
        foreach ($this->db->all('SELECT fk_id_user, ident_modulu FROM {uzivatele_prava}') as $r) {
            $modules[(int) $r['fk_id_user']][] = (string) $r['ident_modulu'];
        }
        foreach ($authors as &$a) {
            $a['shrnuti'] = self::summary((int) $a['admin'], $modules[(int) $a['idu']] ?? [], (bool) $a['blokovat'], $a['blokovano_automaticky']);
        }
        unset($a);

        return $this->view('list', 'Uživatelé', ['authors' => $authors]);
    }

    protected function actionNew(): Response
    {
        return $this->form(['idu' => 0, 'user' => '', 'jmeno' => '', 'email' => '', 'url' => '', 'admin' => Auth::AUTHOR, 'role' => null, 'blokovat' => 0]);
    }

    protected function actionEdit(): Response
    {
        $author = $this->db->one('SELECT * FROM {uzivatele} WHERE idu = ?', [$this->request->getInt('id')]);

        return $author === null ? $this->error('User does not exist.', 404) : $this->form($author);
    }

    protected function actionSave(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back();
        }
        $r = $this->request;
        $id = $r->postInt('idu');
        $isSelf = $id === $this->app->auth()->id();
        $data = [
            'user' => $r->post('user'),
            'jmeno' => $r->post('jmeno'),
            'email' => $r->post('email'),
            'url' => $r->post('url'),
            'admin' => array_key_exists($r->postInt('admin'), Auth::TYPES) ? $r->postInt('admin') : Auth::AUTHOR,
            'role' => null,
            'blokovat' => (int) $r->postBool('blokovat'),
        ];
        // custom role (value "r<id>"): the role determines both the level and the sections
        $custom = preg_match('/^r(\d+)$/D', $r->post('admin'), $m) ? $this->db->one('SELECT * FROM {role} WHERE idr = ?', [(int) $m[1]]) : null;
        if ($custom !== null) {
            $data['admin'] = (int) $custom['uroven'];
            $data['role'] = (int) $custom['idr'];
        }
        if ($isSelf) {
            // an administrator must not take away their own permissions or block themselves - they would lock themselves out of the admin
            $data['admin'] = Auth::ADMIN;
            $data['role'] = null;
            $data['blokovat'] = 0;
        }
        if (!$data['blokovat']) {
            $data['pocet_chyb'] = 0;
            $data['blokovano_automaticky'] = null; // unblocking by hand ends an automatic block too
        }
        // an administrator saving the account confirms it is wanted: the check of unused accounts (Core\SecurityHygiene) counts from now
        $data['potvrzeno'] = date('Y-m-d H:i:s');
        if ($r->postBool('totp_reset')) {
            // the user lost both the phone and the backup codes: the administrator disables their two-factor sign-in
            $data['totp_tajemstvi'] = '';
            $data['totp_zalozni'] = null;
            $this->app->db()->run('DELETE FROM {uzivatele_klice} WHERE idu = ?', [$id]); // passkeys depend on two-factor sign-in
        }

        $errors = [];
        if (!preg_match('/^[a-zA-Z0-9._-]{2,40}$/D', $data['user'])) {
            $errors['user'] = 'Username: 2-40 characters, only letters without diacritics, digits, period, hyphen and underscore.';
        } elseif ($this->db->value('SELECT idu FROM {uzivatele} WHERE user = ? AND idu <> ?', [$data['user'], $id]) !== null) {
            $errors['user'] = 'Another user already has this username.';
        }
        if ($data['email'] !== '' && filter_var($data['email'], FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'The e-mail address is not valid.';
        }
        // the password as typed, like My account, the reset and the installer (3.3.3, N61); only blanks mean "no change"
        $password = is_string($_POST['password'] ?? null) && trim($_POST['password']) !== '' ? $_POST['password'] : '';
        $invite = $id === 0 && $r->postBool('pozvat');
        if ($invite && $data['email'] === '') {
            $errors['email'] = 'An invitation needs an e-mail.';
        }
        if ($invite && $password === '') {
            // the invited user sets the password themselves from the link in the e-mail; until then they cannot sign in (nobody knows the random password)
            $data['password'] = password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT);
        } elseif ($password !== '' || $id === 0) {
            if (mb_strlen($password) < 10) {
                $errors['password'] = 'The password must be at least 10 characters long.';
            } else {
                $data['password'] = password_hash($password, PASSWORD_DEFAULT);
            }
        }
        if ($errors !== []) {
            return $this->form(['idu' => $id] + $data, $errors);
        }

        // access to sections follows from the role; a manual choice only when the administrator explicitly wants it
        $modules = match (true) {
            $data['role'] !== null => array_filter(explode(',', (string) $custom['moduly'])),
            $r->postBool('rucne') => array_intersect($r->postList('moduly'), array_map(fn (string $c): string => $c::IDENT, Kernel::MODULES)),
            default => self::defaultModules((int) $data['admin']),
        };

        $this->db->transaction(function () use (&$id, $data, $modules): void {
            if ($id > 0) {
                $this->db->update('uzivatele', $data, ['idu' => $id]);
                if (isset($data['password'])) {
                    \Kaleta\Front\OAuth::revokeConnections($this->db, $id); // a new password ends the user's Claude connections (N39-1)
                }
            } else {
                $id = $this->db->insert('uzivatele', $data);
            }
            $this->db->delete('uzivatele_prava', ['fk_id_user' => $id]);
            foreach ($modules as $ident) {
                $this->db->insert('uzivatele_prava', ['fk_id_user' => $id, 'ident_modulu' => $ident]);
            }
        });

        if ($invite) {
            (new \Kaleta\Admin\PasswordReset($this->app))->sendLink(['idu' => $id] + $data, 'pozvanka');

            return $this->back(t('The user has been created and the invitation sent to %s.', $data['email']));
        }

        return $this->back('User saved.');
    }

    /** The administrator sends the user a link to set a new password (valid for 3 days). */
    protected function actionPasswordLink(): Response
    {
        $user = $this->request->isPost() ? $this->db->one("SELECT * FROM {uzivatele} WHERE idu = ? AND email <> '' AND blokovat = 0", [$this->request->postInt('idu')]) : null;
        if ($user === null) {
            return $this->back('The user has no e-mail or is blocked.', '', [], 'chyba');
        }
        (new \Kaleta\Admin\PasswordReset($this->app))->sendLink($user, 'spravce');

        return $this->back(t('The new password link has been sent to %s.', $user['email']));
    }

    /**
     * The automatic suspension blocked the account (Core\SecurityHygiene): back to normal, with a fresh confirmation so that
     * the next daily run does not block it again right away.
     */
    protected function actionReactivate(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back();
        }
        $id = $this->request->postInt('idu');
        $user = $this->db->one('SELECT idu, blokovat FROM {uzivatele} WHERE idu = ?', [$id]);
        if ($user === null || !$user['blokovat']) {
            return $this->back('The account is not blocked.', type: 'chyba');
        }
        $this->db->update('uzivatele', ['blokovat' => 0, 'blokovano_automaticky' => null, 'pocet_chyb' => 0, 'zamceno_do' => null, 'potvrzeno' => date('Y-m-d H:i:s')], ['idu' => $id]);

        return $this->back('The account has been reactivated – the user can sign in again.');
    }

    /** Revokes one Claude connection of the user: a personal token (idt) or a connected application (klient). */
    protected function actionRevokeConnection(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back();
        }
        $id = $this->request->postInt('idu');
        $token = $this->request->postInt('idt');
        $client = $this->request->post('klient');
        $removed = $token > 0 ? $this->db->delete('api_tokeny', ['idt' => $token, 'idu' => $id]) : ($client !== '' ? $this->db->delete('api_tokeny', ['idu' => $id, 'klient' => $client]) : 0);

        return $this->back($removed > 0 ? 'The connection has been revoked – Claude can no longer sign in with it.' : 'The connection no longer exists.', 'edit', ['id' => $id], $removed > 0 ? 'ok' : 'chyba');
    }

    /**
     * The user's permissions in one sentence - after saving, the administrator needs to see what came out of the role and
     * sections together.
     *
     * @param list<string> $modules identifiers of the modules they have access to
     * @param string|null $autoBlocked when the automatic suspension blocked the account (ka_uzivatele.blokovano_automaticky)
     */
    public static function summary(int $role, array $modules, bool $blocked = false, ?string $autoBlocked = null): string
    {
        if ($blocked && $autoBlocked !== null) {
            return t('Blocked automatically on %s – nobody had used the account for %d days. An administrator can reactivate it.', format_date($autoBlocked), \Kaleta\Core\SecurityHygiene::ACCOUNT_DAYS);
        }
        if ($blocked) {
            return t('The account is blocked – it cannot sign in to the administration.');
        }
        if ($role >= Auth::ADMIN) {
            return t('May do everything, including site settings and user management.');
        }
        $parts = [];
        if (!in_array('novinky', $modules, true)) {
            $parts[] = t('Does not write news');
        } elseif ($role >= Auth::EDITOR) {
            $parts[] = t('Writes, edits and publishes news by all authors');
        } else {
            $parts[] = t('Writes and edits their own news; an editor publishes it');
        }
        $names = [];
        foreach (Kernel::MODULES as $class) {
            if ($class::IDENT !== 'news' && !$class::ADMIN_ONLY && !$class::FOR_ALL_USERS && $class::SHARES_PERMISSION_OF === '' && in_array($class::IDENT, $modules, true)) {
                $names[] = t($class::NAME);
            }
        }
        $sentence = implode(', ', $parts) . '.';
        if ($names !== []) {
            $sentence .= ' ' . t('Also has access to: %s.', implode(', ', $names));
        }

        return $sentence;
    }

    /**
     * Sections the role has access to when the administrator does not set them manually: author only News, editor all content.
     *
     * @return list<string>
     */
    public static function defaultModules(int $role): array
    {
        $modules = [];
        foreach (Kernel::MODULES as $class) {
            if ($class::ADMIN_ONLY || $class::FOR_ALL_USERS || $class::SHARES_PERMISSION_OF !== '') {
                continue;
            }
            if ($role >= Auth::EDITOR || $class::IDENT === 'news') {
                $modules[] = $class::IDENT;
            }
        }

        return $modules;
    }

    protected function actionDelete(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back();
        }
        $id = $this->request->postInt('idu');
        if ($id === $this->app->auth()->id()) {
            return $this->back('You cannot delete yourself.', type: 'chyba');
        }
        $this->db->delete('uzivatele', ['idu' => $id]);

        return $this->back('The user has been deleted. Their news items remain, without an author.');
    }

    /**
     * @param array<string, mixed> $author
     * @param array<string, string> $errors
     */
    private function form(array $author, array $errors = []): Response
    {
        $id = (int) $author['idu'];
        $configurable = [];
        foreach (Kernel::MODULES as $class) {
            if (!$class::ADMIN_ONLY && !$class::FOR_ALL_USERS && $class::SHARES_PERMISSION_OF === '') {
                $configurable[$class::IDENT] = $class::NAME;
            }
        }

        // the summary applies to the saved state - above the form it says what the user can do NOW (for a new user there is nothing to summarize)
        $summary = $id > 0 && !$this->request->isPost() ? self::summary(
            (int) $author['admin'],
            array_column($this->db->all('SELECT ident_modulu FROM {uzivatele_prava} WHERE fk_id_user = ?', [$id]), 'ident_modulu'),
            (bool) $author['blokovat'],
            $author['blokovano_automaticky'] ?? null,
        ) : '';

        return $this->view('form', $id ? 'Edit user' : 'New user', [
            'author' => $author,
            'customRoles' => $this->db->all('SELECT idr, nazev, popis FROM {role} ORDER BY nazev'),
            'summary' => $summary,
            'errors' => $errors,
            // the user's Claude connections (Core\SecurityHygiene): the administrator revokes what is not needed any more
            'connections' => $id > 0 ? array_values(array_filter(\Kaleta\Core\SecurityHygiene::connections($this->db), fn (array $c): bool => (int) $c['idu'] === $id)) : [],
            'isSelf' => $id === $this->app->auth()->id(),
            'modules' => $configurable,
            'hasModules' => $this->request->isPost()
                ? $this->request->postList('moduly')
                : array_column($this->db->all('SELECT ident_modulu FROM {uzivatele_prava} WHERE fk_id_user = ?', [$id]), 'ident_modulu'),
            'manual' => $this->request->isPost() ? $this->request->postBool('rucne') : ($id > 0 && (int) $author['admin'] !== Auth::ADMIN && (function () use ($id, $author): bool {
                $hasNow = array_column($this->db->all('SELECT ident_modulu FROM {uzivatele_prava} WHERE fk_id_user = ?', [$id]), 'ident_modulu');
                $defaults = self::defaultModules((int) $author['admin']);
                sort($hasNow);
                sort($defaults);

                return $hasNow !== $defaults;
            })()),
        ]);
    }
}
