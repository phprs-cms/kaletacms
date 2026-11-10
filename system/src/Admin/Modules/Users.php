<?php

declare(strict_types=1);

namespace Kaleta\Admin\Modules;

use Kaleta\Admin\Kernel;
use Kaleta\Admin\Module;
use Kaleta\Core\Auth;
use Kaleta\Core\Handover;
use Kaleta\Core\Response;

/**
 * Admin users: accounts, roles (administrator, editor, news author) and optionally manual access to sections.
 */
final class Users extends Module
{
    public const string IDENT = 'users';
    public const string NAME = 'Users';
    public const string GROUP = 'Administration';
    public const string ICON = 'uzivatele';
    public const bool ADMIN_ONLY = true;

    protected function actionList(): Response
    {
        $authors = $this->db->all('SELECT u.*, r.nazev AS nazev_role, (SELECT COUNT(*) FROM {novinky} c WHERE c.autor = u.idu AND c.smazano IS NULL) AS pocet_clanku FROM {uzivatele} u LEFT JOIN {role} r ON r.idr = u.role ORDER BY ' . $this->db->sortText('u.user'));
        $modules = [];
        foreach ($this->db->all('SELECT fk_id_user, ident_modulu FROM {uzivatele_prava}') as $r) {
            $modules[(int) $r['fk_id_user']][] = (string) $r['ident_modulu'];
        }
        foreach ($authors as &$a) {
            $a['shrnuti'] = self::summary((int) $a['admin'], $modules[(int) $a['idu']] ?? [], (bool) $a['blokovat'], $a['blokovano_automaticky']);
        }
        unset($a);

        // 3.14: who is linked to a record of the Team collection (their own e-mail signature in My account)
        $teamExists = \Kaleta\Builder\TeamRecord::collections($this->db) !== [];

        return $this->view('list', 'Users', ['authors' => $authors, 'teamExists' => $teamExists,
            'linked' => $teamExists ? \Kaleta\Builder\TeamRecord::linked($this->db, $authors) : [],
            // 3.24: who is responsible for pages, items or open enquiries – handed over before the account is blocked or deleted
            'owned' => Handover::ownedByAll($this->db)]);
    }

    protected function actionNew(): Response
    {
        // 3.23.1 (N-C9): ?role=<id> picks a custom role in advance – the hand-over checklist links a new user with the Client role
        $role = $this->request->getInt('role') > 0 ? $this->db->one('SELECT idr, uroven FROM {role} WHERE idr = ?', [$this->request->getInt('role')]) : null;

        return $this->form(['idu' => 0, 'user' => '', 'jmeno' => '', 'email' => '', 'url' => '', 'admin' => $role !== null ? (int) $role['uroven'] : Auth::AUTHOR,
            'role' => $role !== null ? (int) $role['idr'] : null, 'blokovat' => 0, 'team_item_id' => null]);
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
            // 3.14: the person's record in the Team collection; empty = automatic by the e-mail, anything else must be a person
            'team_item_id' => \Kaleta\Builder\TeamRecord::isChoice($this->db, $r->postInt('team_item_id')) ? $r->postInt('team_item_id') : null,
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
            // 3.31.2: one's own e-mail, password and second factor change only in My account, which asks for the current
            // password – here a signed-in session alone would do (the form shows them as a link to My account)
            $data['email'] = (string) $this->db->value('SELECT email FROM {uzivatele} WHERE idu = ?', [$id]);
        }
        if (!$data['blokovat']) {
            $data['pocet_chyb'] = 0;
            $data['blokovano_automaticky'] = null; // unblocking by hand ends an automatic block too
        }
        // an administrator saving the account confirms it is wanted: the check of unused accounts (Core\SecurityHygiene) counts from now
        $data['potvrzeno'] = date('Y-m-d H:i:s');
        // the user lost both the phone and the backup codes: the administrator turns off their two-factor sign-in (never
        // their own here – My account does that with the password)
        $totpReset = $id > 0 && !$isSelf && $r->postBool('totp_reset');

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
        $password = !$isSelf && is_string($_POST['password'] ?? null) && trim($_POST['password']) !== '' ? $_POST['password'] : '';
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
        // 3.24: the work the user is responsible for goes to someone else in the same step (the select next to Block account)
        $handOver = $id > 0 && array_sum(Handover::owned($this->db, $id)) > 0 ? $this->chosenRecipient($id, $r->post('hand_over_to')) : false;
        if ($handOver === null) {
            $errors['hand_over_to'] = 'This person cannot take the work over – choose someone who may open those sections.';
        }
        // 3.31.2: a new password or a turned-off second factor of an existing account takes it over as surely as a sign-in –
        // the acting administrator confirms it with their own current password (Auth::confirmPassword: the sign-in's
        // counter and lock). A new account, and every other change, needs no password. Recovery stays: an administrator
        // resets another user's factor and password this way; a locked-out sole administrator has the password-reset
        // e-mail and the backup codes.
        if ($errors === [] && $id > 0 && ($password !== '' || $totpReset)) {
            $check = $this->app->auth()->confirmPassword(is_string($_POST['soucasne'] ?? null) ? $_POST['soucasne'] : '');
            if ($check !== 'ok') {
                $errors['soucasne'] = $check === 'locked' ? Auth::CONFIRM_LOCKED : 'Enter your own current password to set this user\'s password or turn off their two-factor sign-in. Nothing was saved.';
            }
        }
        if ($errors !== []) {
            // the rest of the account as saved (the two-factor row, the automatic block) stays in the form
            unset($data['password']);

            return $this->form(['idu' => $id] + $data + ($id > 0 ? (array) $this->db->one('SELECT * FROM {uzivatele} WHERE idu = ?', [$id]) : []), $errors);
        }
        if ($totpReset) {
            $data['totp_tajemstvi'] = '';
            $data['totp_zalozni'] = null;
        }

        // access to sections follows from the role; a manual choice only when the administrator explicitly wants it
        $modules = match (true) {
            $data['role'] !== null => array_filter(explode(',', (string) $custom['moduly'])),
            $r->postBool('rucne') => array_intersect($r->postList('moduly'), [...array_map(fn (string $c): string => $c::IDENT, Kernel::MODULES), Auth::APPEARANCE_AREA]),
            default => self::defaultModules((int) $data['admin']),
        };

        $revokeTokens = !\Kaleta\Admin\Account::keepsTokens($r->post('zrusit_tokeny')); // 3.31.2 (N101): kept only when the box is unticked
        $this->db->transaction(function () use (&$id, $data, $modules, $revokeTokens, $totpReset): void {
            if ($id > 0) {
                $this->db->update('uzivatele', $data, ['idu' => $id]);
                if ($totpReset) {
                    $this->db->run('DELETE FROM {uzivatele_klice} WHERE idu = ?', [$id]); // passkeys depend on two-factor sign-in
                }
                if (isset($data['password'])) {
                    \Kaleta\Front\OAuth::revokeConnections($this->db, $id); // a new password ends the user's Claude connections (N39-1)
                    if ($revokeTokens) {
                        // 3.15 (N19): personal tokens too unless the administrator unticks it, as on a change in My account
                        $this->db->delete('api_tokeny', ['idu' => $id, 'druh' => 'token']);
                    }
                }
            } else {
                // 3.23.1 (N-C2): a new account gets an administration language – the one of the administrator creating it – so that
                // its e-mails (comments on drafts, requests) do not fall back to another language before the user picks one
                $id = $this->db->insert('uzivatele', $data + ['jazyk' => \Kaleta\Core\Language::code()]);
            }
            $this->db->delete('uzivatele_prava', ['fk_id_user' => $id]);
            foreach ($modules as $ident) {
                $this->db->insert('uzivatele_prava', ['fk_id_user' => $id, 'ident_modulu' => $ident]);
            }
        });

        if (is_array($handOver)) {
            $moved = Handover::handOver($this->db, $id, $handOver[0]);
            \Kaleta\Admin\ChangeLog::write($this->app, 'users', 'save', '#' . $id . ' ' . $data['user'] . ' – ' . t('now responsible: %s – %s', $handOver[1], Handover::describe($moved) ?: '0'));

            return $this->back(t('User saved. Now responsible: %s – %s.', $handOver[1], Handover::describe($moved) ?: '0'));
        }

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
        $client = preg_match('/^[a-f0-9]{32}$/D', $this->request->post('klient')) === 1 ? $this->request->post('klient') : ''; // an OAuth client id is 32 hex characters (column, 3.12)
        $removed = $token > 0 ? $this->db->delete('api_tokeny', ['idt' => $token, 'idu' => $id]) : ($client !== '' ? $this->db->delete('api_tokeny', ['idu' => $id, 'klient' => $client]) : 0);
        if ($client !== '' && $token <= 0) {
            \Kaleta\Front\OAuth::forgetRotations($this->db, $id, $client); // N318-3
        }

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
        if (in_array(Auth::APPEARANCE_AREA, $modules, true)) {
            $names[] = t(Roles::APPEARANCE_AREA_NAME); // 3.22 (C9)
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
        $this->removeAccount($id);

        return $this->back('The user has been deleted. Their news items remain, without an author.');
    }

    private function removeAccount(int $id): void
    {
        $this->db->delete('uzivatele', ['idu' => $id]);
        \Kaleta\Front\OAuth::forgetRotations($this->db, $id); // its tokens go with the account (foreign key), the records too (N318-3)
        Handover::forget();
    }

    /**
     * Hands a user's work over (3.24): the pages and collection items they are responsible for and their open enquiries
     * go to one person who may open those sections (or to nobody) – on its own, or in the same step as blocking or deleting
     * the account (then=block|delete), so a leaver's work never stays with a closed account. One change-log row.
     */
    protected function actionHandover(): Response
    {
        $id = $this->request->isPost() ? $this->request->postInt('idu') : $this->request->getInt('id');
        $user = $this->db->one('SELECT idu, user, jmeno, blokovat FROM {uzivatele} WHERE idu = ?', [$id]);
        if ($user === null) {
            return $this->error('User does not exist.', 404);
        }
        $isSelf = $id === $this->app->auth()->id();
        $then = in_array($this->request->isPost() ? $this->request->post('then') : $this->request->get('then'), ['block', 'delete'], true)
            ? ($this->request->isPost() ? $this->request->post('then') : $this->request->get('then')) : '';
        $owned = Handover::owned($this->db, $id);
        if (!$this->request->isPost()) {
            return $this->view('handover', 'Hand over work', ['author' => $user, 'owned' => $owned, 'isSelf' => $isSelf, 'then' => $isSelf ? '' : $then,
                'recipients' => Handover::eligible($this->db, $this->app->settings(), Handover::sectionsToTake($this->db, $id), $id)]);
        }
        if ($then !== '' && $isSelf) {
            return $this->back($then === 'delete' ? 'You cannot delete yourself.' : 'You cannot block your own account.', type: 'chyba');
        }
        $recipient = array_sum($owned) > 0 ? $this->chosenRecipient($id, $this->request->post('hand_over_to')) : [null, t('nobody')];
        if (!is_array($recipient)) {
            $this->app->session->flash('chyba', $recipient === false ? 'Choose who takes the work over.' : 'This person cannot take the work over – choose someone who may open those sections.');

            return Response::redirect($this->url('handover', ['id' => $id] + ($then !== '' ? ['then' => $then] : [])));
        }
        $moved = ['page' => 0, 'item' => 0, 'enquiry' => 0, 'task' => 0];
        $this->db->transaction(function () use ($id, $recipient, $then, &$moved): void {
            $moved = Handover::handOver($this->db, $id, $recipient[0]);
            if ($then === 'block') {
                $this->db->update('uzivatele', ['blokovat' => 1, 'potvrzeno' => date('Y-m-d H:i:s')], ['idu' => $id]);
            }
        });
        if ($then === 'delete') {
            $this->removeAccount($id);
        }
        $what = Handover::describe($moved);
        \Kaleta\Admin\ChangeLog::write($this->app, 'users', $then === '' ? 'handover' : $then, '#' . $id . ' ' . $user['user'] . ' – ' . t('now responsible: %s – %s', $recipient[1], $what ?: '0'));
        $message = $what !== '' ? t('Now responsible: %s – %s.', $recipient[1], $what) : t('Nothing to hand over.');

        return $this->back($message . ($then === 'block' ? ' ' . t('The account is blocked – it cannot sign in to the administration.') : ($then === 'delete' ? ' ' . t('The user has been deleted. Their news items remain, without an author.') : '')));
    }

    /**
     * The person a form names to take a user's work (3.24): [id or null for nobody, their name]; false = nothing chosen
     * (the select left at "keep"), null = someone who may not open the sections of that work.
     *
     * @return array{0: ?int, 1: string}|false|null
     */
    private function chosenRecipient(int $from, string $value): array|false|null
    {
        if ($value === '') {
            return false;
        }
        if ($value === '0') {
            return [null, t('nobody')];
        }
        $recipients = Handover::eligible($this->db, $this->app->settings(), Handover::sectionsToTake($this->db, $from), $from);

        return ctype_digit($value) && isset($recipients[(int) $value]) ? [(int) $value, $recipients[(int) $value]] : null;
    }

    /**
     * @param array<string, mixed> $author
     * @param array<string, string> $errors
     */
    private function form(array $author, array $errors = []): Response
    {
        $id = (int) $author['idu'];
        $configurable = Roles::configurable(); // the same sections as a role, the optional Appearance area too (3.22, C9)

        // the summary applies to the saved state - above the form it says what the user can do NOW (for a new user there is nothing to summarize)
        $summary = $id > 0 && !$this->request->isPost() ? self::summary(
            (int) $author['admin'],
            array_column($this->db->all('SELECT ident_modulu FROM {uzivatele_prava} WHERE fk_id_user = ?', [$id]), 'ident_modulu'),
            (bool) $author['blokovat'],
            $author['blokovano_automaticky'] ?? null,
        ) : '';

        $owned = $id > 0 ? Handover::owned($this->db, $id) : ['page' => 0, 'item' => 0, 'enquiry' => 0, 'task' => 0];

        return $this->view('form', $id ? 'Edit user' : 'New user', [
            'author' => $author,
            // 3.24: what the user is responsible for, and who could take it over when the account is blocked
            'owned' => $owned,
            'recipients' => array_sum($owned) > 0 ? Handover::eligible($this->db, $this->app->settings(), Handover::sectionsToTake($this->db, $id), $id) : [],
            'customRoles' => $this->db->all('SELECT idr, nazev, popis FROM {role} ORDER BY ' . $this->db->sortText('nazev')),
            'summary' => $summary,
            'errors' => $errors,
            // the user's Claude connections (Core\SecurityHygiene): the administrator revokes what is not needed any more
            'connections' => $id > 0 ? array_values(array_filter(\Kaleta\Core\SecurityHygiene::connections($this->db), fn (array $c): bool => (int) $c['idu'] === $id)) : [],
            'isSelf' => $id === $this->app->auth()->id(),
            // 3.14: the records an account can be linked to, and the one it is linked to now (by the choice or the e-mail)
            'teamChoices' => \Kaleta\Builder\TeamRecord::choices($this->db),
            'teamRecord' => $id > 0 ? \Kaleta\Builder\TeamRecord::forUser($this->db, $author) : null,
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
