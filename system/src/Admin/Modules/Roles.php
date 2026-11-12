<?php

declare(strict_types=1);

namespace Kaleta\Admin\Modules;

use Kaleta\Admin\Kernel;
use Kaleta\Admin\Module;
use Kaleta\Core\Auth;
use Kaleta\Core\Db;
use Kaleta\Core\Response;

/**
 * Custom roles (Uživatelé → Role, Users → Roles): a named set of admin sections and a level – e.g. "Obchodník" (Sales) with
 * only Enquiries, or "Marketing" with Pages, Media and News. Saving a role overwrites the permissions of all its members;
 * deleting a role leaves the members their permissions, they are just no longer linked.
 */
final class Roles extends Module
{
    public const string IDENT = 'roles';
    public const string NAME = 'Roles';
    public const string GROUP = 'Administration';
    public const string ICON = 'uzivatele';
    public const bool ADMIN_ONLY = true;
    public const string PARENT = 'users';

    /** Levels of a custom role (administrator cannot be a custom role – that is the built-in role Administrator). */
    public const array LEVELS = [
        Auth::AUTHOR => ['Writes own content', 'Only own news and without publishing – an editor publishes.'],
        Auth::EDITOR => ['Manages everyone\'s content', 'Edits and publishes news by all authors.'],
    ];

    protected function actionList(): Response
    {
        $role = $this->db->all('SELECT r.*, (SELECT COUNT(*) FROM {uzivatele} u WHERE u.role = r.idr) AS clenu FROM {role} r ORDER BY ' . $this->db->sortText('r.nazev'));

        return $this->view('list', 'Roles', ['role' => $role, 'names' => self::configurable()]);
    }

    /**
     * Ready-made roles (2.4) – what an agency hands a client most often. A starting point: the form can still be changed.
     * key => [name, description, level, sections]
     * 3.23.1 (N-C1, owner decision D1 of 30 October): the Client gets the Appearance area (menu, footer) and Business details
     * (opening hours) – the client's most frequent edits. Roles saved before keep their sections. 3.28: whoever answers
     * enquiries has Customers too (as migration 0114 gives every role with Enquiries); 3.31: and the Pipeline of their deals
     * (as migration 0117 gives every role with Customers). 3.34: HR – job applications with the jobs collection, and nothing
     * of the customers (the section Applications is never a default of a level).
     */
    public const array PRESETS = [
        'client' => ['Client', 'Edits pages, news, collection items, the menu, the footer and the opening hours, answers enquiries, sees the statistics and asks Claude for changes – design, settings and users stay with the agency.',
            Auth::EDITOR, ['pages', 'news', 'collections', 'categories', 'tags', 'enquiries', 'contacts', 'pipeline', 'stats', 'requests', 'business', Auth::APPEARANCE_AREA]],
        'writer' => ['Writer', 'Writes news for someone else to publish.', Auth::AUTHOR, ['news', 'tags']],
        'hr' => ['HR', 'Handles job applications – their statuses, replies, notes and tasks – and edits the job openings in Collections; sees nothing of enquiries or customers.', Auth::EDITOR, ['applications', 'collections', 'requests']],
        'office' => ['Enquiries only', 'Handles enquiries from the site forms and the newsletter subscribers, and asks Claude for changes.', Auth::AUTHOR, ['enquiries', 'contacts', 'pipeline', 'subscribers', 'requests']],
    ];

    protected function actionNew(): Response
    {
        $preset = self::PRESETS[$this->request->get('preset')] ?? null;

        return $this->form($preset === null ? ['idr' => 0, 'nazev' => '', 'popis' => '', 'uroven' => Auth::AUTHOR, 'moduly' => '']
            : ['idr' => 0, 'nazev' => t($preset[0]), 'popis' => t($preset[1]), 'uroven' => $preset[2], 'moduly' => implode(',', array_intersect($preset[3], array_keys(self::configurable())))]);
    }

    protected function actionEdit(): Response
    {
        $role = $this->db->one('SELECT * FROM {role} WHERE idr = ?', [$this->request->getInt('id')]);

        return $role === null ? $this->error('The role does not exist.', 404) : $this->form($role);
    }

    protected function actionSave(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back();
        }
        $r = $this->request;
        $id = $r->postInt('idr');
        $data = [
            'nazev' => mb_substr($r->post('nazev'), 0, 60),
            'popis' => mb_substr($r->post('popis'), 0, 200),
            'uroven' => isset(self::LEVELS[$r->postInt('uroven')]) ? $r->postInt('uroven') : Auth::AUTHOR,
            'moduly' => implode(',', array_values(array_intersect($r->postList('moduly'), array_keys(self::configurable())))),
        ];
        $errors = [];
        if ($data['nazev'] === '') {
            $errors['nazev'] = 'Fill in the role name.';
        } elseif (in_array(mb_strtolower($data['nazev']), array_map(fn (string $n): string => mb_strtolower(t($n)), ['News author', 'Editor', 'Administrator']), true)
            || $this->db->value('SELECT idr FROM {role} WHERE nazev = ? AND idr <> ?', [$data['nazev'], $id]) !== null) {
            $errors['nazev'] = 'A role with this name already exists.';
        }
        if ($data['moduly'] === '') {
            $errors['moduly'] = 'Select at least one section.';
        }
        // 3.35 re-review N2: a role that gets every section passes it to its members at once (applyToMembers) – the same
        // power as every section ticked by hand in Users, so the same fresh proof of the acting administrator (Users::elevates)
        if ($errors === [] && self::grantsEverySection($id > 0 ? (string) $this->db->value('SELECT moduly FROM {role} WHERE idr = ?', [$id]) : null, $data['moduly'], array_keys(self::configurable()))) {
            $check = $this->app->auth()->freshProof(is_string($_POST['soucasne'] ?? null) ? $_POST['soucasne'] : '');
            if ($check !== 'ok') {
                $errors['soucasne'] = match ($check) {
                    'locked' => Auth::CONFIRM_LOCKED,
                    'passkey' => Auth::PASSKEY_PROOF,
                    default => self::EVERY_SECTION_NEEDS_PASSWORD,
                };
            }
        }
        if ($errors !== []) {
            return $this->form(['idr' => $id] + $data, $errors);
        }
        $this->db->transaction(function (Db $db) use (&$id, $data): void {
            if ($id > 0) {
                $db->update('role', $data, ['idr' => $id]);
            } else {
                $id = $db->insert('role', $data);
            }
            self::applyToMembers($db, $id);
        });

        return $this->back('The role has been saved.');
    }

    /** 3.35 re-review N2: the answer when a role would get every section without the acting administrator's proof. */
    public const string EVERY_SECTION_NEEDS_PASSWORD = 'Enter your own current password to give this role every section. Nothing was saved.';

    /**
     * 3.35 re-review N2: does saving give the role every section – a new role ($before null) or one that lacked one? Pure.
     *
     * @param list<string> $all every section a role can have
     */
    public static function grantsEverySection(?string $before, string $after, array $all): bool
    {
        $now = array_filter(explode(',', $after));
        if (array_diff($all, $now) !== []) {
            return false;
        }

        return $before === null || array_diff($all, array_filter(explode(',', $before))) !== [];
    }

    protected function actionDelete(): Response
    {
        if ($this->request->isPost()) {
            // members keep their current permissions, only the role no longer overwrites them on the next change
            $this->db->run('UPDATE {uzivatele} SET role = NULL WHERE role = ?', [$this->request->postInt('idr')]);
            $this->db->delete('role', ['idr' => $this->request->postInt('idr')]);
        }

        return $this->back('The role has been deleted. Its members kept their current access.');
    }

    /** Overwrites the role's permissions for all its members (level and sections). An administrator is not changed – they always have everything. */
    public static function applyToMembers(Db $db, int $idr): void
    {
        $role = $db->one('SELECT * FROM {role} WHERE idr = ?', [$idr]);
        if ($role === null) {
            return;
        }
        foreach ($db->all('SELECT idu FROM {uzivatele} WHERE role = ? AND admin < ?', [$idr, Auth::ADMIN]) as $u) {
            $db->update('uzivatele', ['admin' => (int) $role['uroven']], ['idu' => (int) $u['idu']]);
            $db->delete('uzivatele_prava', ['fk_id_user' => (int) $u['idu']]);
            foreach (array_filter(explode(',', (string) $role['moduly'])) as $ident) {
                $db->insert('uzivatele_prava', ['fk_id_user' => (int) $u['idu'], 'ident_modulu' => $ident]);
            }
        }
    }

    /** Label of the optional Appearance area (3.22, C9) in the role and the user form. */
    public const string APPEARANCE_AREA_NAME = 'Appearance: menu, site parts, pop-ups and components';

    /** Label of the optional area for changing internal prices (3.38, Auth::PRICES_AREA) in the role and the user form. */
    public const string PRICES_AREA_NAME = 'Quotes: change internal prices';

    /**
     * Sections for which access is set: ident => name. Since 3.22 (C9) the last one is the optional Appearance area
     * (Auth::APPEARANCE_AREA), one checkbox for Menu, Site parts, Pop-ups and Components; it is never a default of a level (the ready-made Client role ticks it since 3.23.1).
     *
     * @return array<string, string>
     */
    public static function configurable(): array
    {
        $section = [];
        foreach (Kernel::MODULES as $class) {
            if (!$class::ADMIN_ONLY && !$class::FOR_ALL_USERS && $class::SHARES_PERMISSION_OF === '') {
                $section[$class::IDENT] = $class::NAME;
            }
        }

        return $section + [Auth::APPEARANCE_AREA => self::APPEARANCE_AREA_NAME, Auth::PRICES_AREA => self::PRICES_AREA_NAME]; // 3.38: the prices area
    }

    /**
     * @param array<string, mixed> $role
     * @param array<string, string> $errors
     */
    private function form(array $role, array $errors = []): Response
    {
        $auth = $this->app->auth();

        return $this->view('form', (int) $role['idr'] > 0 ? 'Edit role' : 'New role', [
            'role' => $role, 'errors' => $errors, 'section' => self::configurable(),
            'passkeyOnly' => $auth->isPasskeyOnly(), 'fresh' => $auth->isFreshlyVerified(), // 3.35 re-review N2: the proof for every section
            'selected' => array_filter(explode(',', (string) $role['moduly'])),
            'members' => (int) $role['idr'] > 0 ? $this->db->all('SELECT idu, user, jmeno FROM {uzivatele} WHERE role = ? ORDER BY ' . $this->db->sortText('jmeno') . ', user', [(int) $role['idr']]) : [],
        ]);
    }
}
