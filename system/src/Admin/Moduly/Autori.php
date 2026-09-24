<?php

declare(strict_types=1);

namespace MiroCMS\Admin\Moduly;

use MiroCMS\Admin\Kernel;
use MiroCMS\Admin\Modul;
use MiroCMS\Core\Auth;
use MiroCMS\Core\Response;

/**
 * Uživatelé administrace: účty, role (správce, editor, autor novinek) a případně ruční přístup do sekcí.
 */
final class Autori extends Modul
{
    public const string IDENT = 'users';
    public const string NAZEV = 'Uživatelé';
    public const string SKUPINA = 'Správa';
    public const string IKONA = 'uzivatele';
    public const bool JEN_ADMIN = true;

    protected function akceVypis(): Response
    {
        $autori = $this->db->all('SELECT u.*, (SELECT COUNT(*) FROM {clanky} c WHERE c.autor = u.idu AND c.smazano IS NULL) AS pocet_clanku FROM {user} u ORDER BY u.user');
        $moduly = [];
        foreach ($this->db->all('SELECT fk_id_user, ident_modulu FROM {user_prava}') as $r) {
            $moduly[(int) $r['fk_id_user']][] = (string) $r['ident_modulu'];
        }
        foreach ($autori as &$a) {
            $a['shrnuti'] = self::shrnuti((int) $a['admin'], $moduly[(int) $a['idu']] ?? [], (bool) $a['blokovat']);
        }
        unset($a);

        return $this->view('vypis', 'Uživatelé', ['autori' => $autori]);
    }

    protected function akceNovy(): Response
    {
        return $this->formular(['idu' => 0, 'user' => '', 'jmeno' => '', 'email' => '', 'url' => '', 'admin' => Auth::AUTOR, 'blokovat' => 0]);
    }

    protected function akceEdit(): Response
    {
        $autor = $this->db->one('SELECT * FROM {user} WHERE idu = ?', [$this->request->getInt('id')]);

        return $autor === null ? $this->chyba('Uživatel neexistuje.', 404) : $this->formular($autor);
    }

    protected function akceUloz(): Response
    {
        if (!$this->request->isPost()) {
            return $this->zpet();
        }
        $r = $this->request;
        $id = $r->postInt('idu');
        $sam = $id === $this->app->auth()->id();
        $data = [
            'user' => $r->post('user'),
            'jmeno' => $r->post('jmeno'),
            'email' => $r->post('email'),
            'url' => $r->post('url'),
            'admin' => array_key_exists($r->postInt('admin'), Auth::TYPY) ? $r->postInt('admin') : Auth::AUTOR,
            'blokovat' => (int) $r->postBool('blokovat'),
        ];
        if ($sam) {
            // admin si nesmí sám sobě vzít práva ani se zablokovat - zamkl by si administraci
            $data['admin'] = Auth::ADMIN;
            $data['blokovat'] = 0;
        }
        if (!$data['blokovat']) {
            $data['pocet_chyb'] = 0;
        }
        if ($r->postBool('totp_reset')) {
            // uživatel ztratil telefon i záložní kódy: administrátor mu dvoufázové přihlášení vypne
            $data['totp_tajemstvi'] = '';
            $data['totp_zalozni'] = null;
            $this->app->db()->run('DELETE FROM {user_klice} WHERE idu = ?', [$id]); // přihlašovací klíče stojí na dvoufázovém přihlášení
        }

        $chyby = [];
        if (!preg_match('/^[a-zA-Z0-9._-]{2,40}$/', $data['user'])) {
            $chyby['user'] = 'Přihlašovací jméno: 2-40 znaků, jen písmena bez diakritiky, číslice, tečka, pomlčka a podtržítko.';
        } elseif ($this->db->value('SELECT idu FROM {user} WHERE user = ? AND idu <> ?', [$data['user'], $id]) !== null) {
            $chyby['user'] = 'Toto přihlašovací jméno už používá jiný uživatel.';
        }
        if ($data['email'] !== '' && filter_var($data['email'], FILTER_VALIDATE_EMAIL) === false) {
            $chyby['email'] = 'E-mail nemá platný tvar.';
        }
        $heslo = $r->post('password');
        if ($heslo !== '' || $id === 0) {
            if (mb_strlen($heslo) < 10) {
                $chyby['password'] = 'Heslo musí mít alespoň 10 znaků.';
            } else {
                $data['password'] = password_hash($heslo, PASSWORD_DEFAULT);
            }
        }
        if ($chyby !== []) {
            return $this->formular(['idu' => $id] + $data, $chyby);
        }

        // přístup do sekcí plyne z role; ruční výběr jen když o něj administrátor výslovně stojí
        $moduly = $r->postBool('rucne')
            ? array_intersect($r->postList('moduly'), array_map(fn (string $c): string => $c::IDENT, Kernel::MODULY))
            : self::vychoziModuly((int) $data['admin']);

        $this->db->transaction(function () use (&$id, $data, $moduly): void {
            if ($id > 0) {
                $this->db->update('user', $data, ['idu' => $id]);
            } else {
                $id = $this->db->insert('user', $data);
            }
            $this->db->delete('user_prava', ['fk_id_user' => $id]);
            foreach ($moduly as $ident) {
                $this->db->insert('user_prava', ['fk_id_user' => $id, 'ident_modulu' => $ident]);
            }
        });

        return $this->zpet('Uživatel byl uložen.');
    }

    /**
     * Oprávnění uživatele jednou větou - správce po uložení potřebuje vidět, co z role a sekcí dohromady vyšlo.
     *
     * @param list<string> $moduly identifikátory modulů, ke kterým má přístup
     */
    public static function shrnuti(int $role, array $moduly, bool $blokovan = false): string
    {
        if ($blokovan) {
            return t('Účet je zablokovaný – do administrace se nepřihlásí.');
        }
        if ($role >= Auth::ADMIN) {
            return t('Smí všechno včetně nastavení webu a správy uživatelů.');
        }
        $casti = [];
        if (!in_array('novinky', $moduly, true)) {
            $casti[] = t('Nepíše novinky');
        } elseif ($role >= Auth::EDITOR) {
            $casti[] = t('Píše, upravuje a vydává novinky všech autorů');
        } else {
            $casti[] = t('Píše a upravuje vlastní novinky, vydává je editor');
        }
        $nazvy = [];
        foreach (Kernel::MODULY as $class) {
            if ($class::IDENT !== 'novinky' && !$class::JEN_ADMIN && !$class::PRO_VSECHNY && in_array($class::IDENT, $moduly, true)) {
                $nazvy[] = t($class::NAZEV);
            }
        }
        $veta = implode(', ', $casti) . '.';
        if ($nazvy !== []) {
            $veta .= ' ' . t('Dál má přístup k: %s.', implode(', ', $nazvy));
        }

        return $veta;
    }

    /**
     * Sekce, do kterých má role přístup, když je správce nenastaví ručně: autor jen Novinky, editor veškerý obsah.
     *
     * @return list<string>
     */
    public static function vychoziModuly(int $role): array
    {
        $moduly = [];
        foreach (Kernel::MODULY as $class) {
            if ($class::JEN_ADMIN || $class::PRO_VSECHNY) {
                continue;
            }
            if ($role >= Auth::EDITOR || $class::IDENT === 'novinky') {
                $moduly[] = $class::IDENT;
            }
        }

        return $moduly;
    }

    protected function akceSmaz(): Response
    {
        if (!$this->request->isPost()) {
            return $this->zpet();
        }
        $id = $this->request->postInt('idu');
        if ($id === $this->app->auth()->id()) {
            return $this->zpet('Nemůžete smazat sám sebe.', typ: 'chyba');
        }
        $this->db->delete('user', ['idu' => $id]);

        return $this->zpet('Uživatel byl smazán. Jeho novinky zůstaly zachované bez autora.');
    }

    /**
     * @param array<string, mixed> $autor
     * @param array<string, string> $chyby
     */
    private function formular(array $autor, array $chyby = []): Response
    {
        $id = (int) $autor['idu'];
        $nastavitelne = [];
        foreach (Kernel::MODULY as $class) {
            if (!$class::JEN_ADMIN && !$class::PRO_VSECHNY) {
                $nastavitelne[$class::IDENT] = $class::NAZEV;
            }
        }

        // shrnutí platí pro uložený stav - nad formulářem říká, co uživatel smí TEĎ (u nového uživatele není co shrnovat)
        $shrnuti = $id > 0 && !$this->request->isPost() ? self::shrnuti(
            (int) $autor['admin'],
            array_column($this->db->all('SELECT ident_modulu FROM {user_prava} WHERE fk_id_user = ?', [$id]), 'ident_modulu'),
            (bool) $autor['blokovat'],
        ) : '';

        return $this->view('formular', $id ? 'Úprava uživatele' : 'Nový uživatel', [
            'autor' => $autor,
            'shrnuti' => $shrnuti,
            'chyby' => $chyby,
            'sam' => $id === $this->app->auth()->id(),
            'moduly' => $nastavitelne,
            'maModuly' => $this->request->isPost()
                ? $this->request->postList('moduly')
                : array_column($this->db->all('SELECT ident_modulu FROM {user_prava} WHERE fk_id_user = ?', [$id]), 'ident_modulu'),
            'rucne' => $this->request->isPost() ? $this->request->postBool('rucne') : ($id > 0 && (int) $autor['admin'] !== Auth::ADMIN && (function () use ($id, $autor): bool {
                $ma = array_column($this->db->all('SELECT ident_modulu FROM {user_prava} WHERE fk_id_user = ?', [$id]), 'ident_modulu');
                $vychozi = self::vychoziModuly((int) $autor['admin']);
                sort($ma);
                sort($vychozi);

                return $ma !== $vychozi;
            })()),
        ]);
    }
}
