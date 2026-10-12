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
     */
    public const array PRESETS = [
        'client' => ['Client', 'Edits pages, news and collection items, answers enquiries, sees the statistics and asks Claude for changes – the look, settings and users stay with the agency.',
            Auth::EDITOR, ['pages', 'news', 'collections', 'categories', 'tags', 'enquiries', 'stats', 'requests']],
        'writer' => ['Writer', 'Writes news for someone else to publish.', Auth::AUTHOR, ['news', 'tags']],
        'office' => ['Enquiries only', 'Handles enquiries from the site forms and the newsletter subscribers, and asks Claude for changes.', Auth::AUTHOR, ['enquiries', 'subscribers', 'requests']],
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

    /** @return array<string, string> sections for which access is set: ident => name */
    public static function configurable(): array
    {
        $section = [];
        foreach (Kernel::MODULES as $class) {
            if (!$class::ADMIN_ONLY && !$class::FOR_ALL_USERS && $class::SHARES_PERMISSION_OF === '') {
                $section[$class::IDENT] = $class::NAME;
            }
        }

        return $section;
    }

    /**
     * @param array<string, mixed> $role
     * @param array<string, string> $errors
     */
    private function form(array $role, array $errors = []): Response
    {
        return $this->view('form', (int) $role['idr'] > 0 ? 'Edit role' : 'New role', [
            'role' => $role, 'errors' => $errors, 'section' => self::configurable(),
            'selected' => array_filter(explode(',', (string) $role['moduly'])),
            'members' => (int) $role['idr'] > 0 ? $this->db->all('SELECT idu, user, jmeno FROM {uzivatele} WHERE role = ? ORDER BY ' . $this->db->sortText('jmeno') . ', user', [(int) $role['idr']]) : [],
        ]);
    }
}
