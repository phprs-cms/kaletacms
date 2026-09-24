<?php

declare(strict_types=1);

namespace Kaleta\Admin\Moduly;

use Kaleta\Admin\Kernel;
use Kaleta\Admin\Modul;
use Kaleta\Core\Auth;
use Kaleta\Core\Db;
use Kaleta\Core\Response;

/**
 * Vlastní role (Uživatelé → Role): pojmenovaná sada sekcí administrace a úroveň – třeba „Obchodník“ jen s Poptávkami
 * nebo „Marketing“ se Stránkami, Médii a Novinkami. Uložení role přepíše práva všem jejím členům; smazání role
 * členům práva nechá, jen už nejsou svázaná.
 */
final class Role extends Modul
{
    public const string IDENT = 'role';
    public const string NAZEV = 'Role';
    public const string SKUPINA = 'Správa';
    public const string IKONA = 'uzivatele';
    public const bool JEN_ADMIN = true;
    public const string NADRAZENY = 'users';

    /** Úrovně vlastní role (správce vlastní rolí být nemůže – to je vestavěná role Správce). */
    public const array UROVNE = [
        Auth::AUTOR => ['Píše vlastní obsah', 'Novinky jen své a bez vydávání – vydává editor.'],
        Auth::EDITOR => ['Spravuje obsah všech', 'Upravuje a vydává novinky všech autorů.'],
    ];

    protected function akceVypis(): Response
    {
        $role = $this->db->all('SELECT r.*, (SELECT COUNT(*) FROM {uzivatele} u WHERE u.role = r.idr) AS clenu FROM {role} r ORDER BY r.nazev');

        return $this->view('vypis', 'Role', ['role' => $role, 'nazvy' => self::nastavitelne()]);
    }

    protected function akceNovy(): Response
    {
        return $this->formular(['idr' => 0, 'nazev' => '', 'popis' => '', 'uroven' => Auth::AUTOR, 'moduly' => '']);
    }

    protected function akceEdit(): Response
    {
        $role = $this->db->one('SELECT * FROM {role} WHERE idr = ?', [$this->request->getInt('id')]);

        return $role === null ? $this->chyba('Role neexistuje.', 404) : $this->formular($role);
    }

    protected function akceUloz(): Response
    {
        if (!$this->request->isPost()) {
            return $this->zpet();
        }
        $r = $this->request;
        $id = $r->postInt('idr');
        $data = [
            'nazev' => mb_substr($r->post('nazev'), 0, 60),
            'popis' => mb_substr($r->post('popis'), 0, 200),
            'uroven' => isset(self::UROVNE[$r->postInt('uroven')]) ? $r->postInt('uroven') : Auth::AUTOR,
            'moduly' => implode(',', array_values(array_intersect($r->postList('moduly'), array_keys(self::nastavitelne())))),
        ];
        $chyby = [];
        if ($data['nazev'] === '') {
            $chyby['nazev'] = 'Vyplňte název role.';
        } elseif (in_array(mb_strtolower($data['nazev']), array_map(fn (string $n): string => mb_strtolower(t($n)), ['Autor novinek', 'Editor', 'Správce']), true)
            || $this->db->value('SELECT idr FROM {role} WHERE nazev = ? AND idr <> ?', [$data['nazev'], $id]) !== null) {
            $chyby['nazev'] = 'Role s tímto názvem už existuje.';
        }
        if ($data['moduly'] === '') {
            $chyby['moduly'] = 'Vyberte aspoň jednu sekci.';
        }
        if ($chyby !== []) {
            return $this->formular(['idr' => $id] + $data, $chyby);
        }
        $this->db->transaction(function (Db $db) use (&$id, $data): void {
            if ($id > 0) {
                $db->update('role', $data, ['idr' => $id]);
            } else {
                $id = $db->insert('role', $data);
            }
            self::prenesNaCleny($db, $id);
        });

        return $this->zpet('Role byla uložena.');
    }

    protected function akceSmaz(): Response
    {
        if ($this->request->isPost()) {
            // členové si ponechají dosavadní práva, jen už je role při další změně nepřepíše
            $this->db->run('UPDATE {uzivatele} SET role = NULL WHERE role = ?', [$this->request->postInt('idr')]);
            $this->db->delete('role', ['idr' => $this->request->postInt('idr')]);
        }

        return $this->zpet('Role byla smazána. Její členové si ponechali dosavadní přístup.');
    }

    /** Práva role přepíše všem jejím členům (úroveň i sekce). Správce se nemění – ten má vždy vše. */
    public static function prenesNaCleny(Db $db, int $idr): void
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

    /** @return array<string, string> sekce, ke kterým se přístup nastavuje: ident => název */
    public static function nastavitelne(): array
    {
        $sekce = [];
        foreach (Kernel::MODULY as $class) {
            if (!$class::JEN_ADMIN && !$class::PRO_VSECHNY) {
                $sekce[$class::IDENT] = $class::NAZEV;
            }
        }

        return $sekce;
    }

    /**
     * @param array<string, mixed> $role
     * @param array<string, string> $chyby
     */
    private function formular(array $role, array $chyby = []): Response
    {
        return $this->view('formular', (int) $role['idr'] > 0 ? 'Úprava role' : 'Nová role', [
            'role' => $role, 'chyby' => $chyby, 'sekce' => self::nastavitelne(),
            'vybrane' => array_filter(explode(',', (string) $role['moduly'])),
            'clenove' => (int) $role['idr'] > 0 ? $this->db->all('SELECT idu, user, jmeno FROM {uzivatele} WHERE role = ? ORDER BY user', [(int) $role['idr']]) : [],
        ]);
    }
}
