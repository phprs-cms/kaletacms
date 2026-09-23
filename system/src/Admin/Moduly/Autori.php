<?php

declare(strict_types=1);

namespace MiroCMS\Admin\Moduly;

use MiroCMS\Admin\Kernel;
use MiroCMS\Admin\Modul;
use MiroCMS\Core\Auth;
use MiroCMS\Core\Response;

/**
 * Editace autorů: účty administrace, práva k modulům, právo vydávat a vazby nadřízený - podřízený.
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
        $moduly = $rubriky = $cizi = [];
        foreach ($this->db->all('SELECT fk_id_user, ident_modulu FROM {user_prava}') as $r) {
            $moduly[(int) $r['fk_id_user']][] = (string) $r['ident_modulu'];
        }
        foreach ($this->db->all('SELECT ur.idu, t.nazev FROM {user_rubriky} ur JOIN {topic} t ON t.idt = ur.idt ORDER BY t.nazev') as $r) {
            $rubriky[(int) $r['idu']][] = (string) $r['nazev'];
        }
        foreach ($this->db->all('SELECT fk_id_nadrizeny, COUNT(*) AS pocet FROM {vazby_prava} GROUP BY fk_id_nadrizeny') as $r) {
            $cizi[(int) $r['fk_id_nadrizeny']] = (int) $r['pocet'];
        }
        foreach ($autori as &$a) {
            $id = (int) $a['idu'];
            $a['shrnuti'] = self::shrnuti((int) $a['admin'], (bool) $a['pravo_vydavat'], $moduly[$id] ?? [], $rubriky[$id] ?? [], $cizi[$id] ?? 0, (bool) $a['blokovat']);
        }
        unset($a);

        return $this->view('vypis', 'Uživatelé', ['autori' => $autori]);
    }

    protected function akceNovy(): Response
    {
        return $this->formular(['idu' => 0, 'user' => '', 'jmeno' => '', 'email' => '', 'url' => '', 'admin' => Auth::AUTOR, 'pravo_vydavat' => 0, 'blokovat' => 0]);
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
            'pravo_vydavat' => (int) $r->postBool('pravo_vydavat'),
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
            $chyby['user'] = 'Toto přihlašovací jméno už používá jiný autor.';
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
        $podrizeni = array_filter(array_map(intval(...), $r->postList('podrizeni')), fn (int $p): bool => $p > 0 && $p !== $id);

        $this->db->transaction(function () use (&$id, $data, $moduly, $podrizeni): void {
            if ($id > 0) {
                $this->db->update('user', $data, ['idu' => $id]);
            } else {
                $id = $this->db->insert('user', $data);
            }
            $this->db->delete('user_prava', ['fk_id_user' => $id]);
            foreach ($moduly as $ident) {
                $this->db->insert('user_prava', ['fk_id_user' => $id, 'ident_modulu' => $ident]);
            }
            // omezení na rubriky: nic nezaškrtnuto = všechny rubriky
            $this->db->delete('user_rubriky', ['idu' => $id]);
            foreach (array_unique(array_filter(array_map(intval(...), $this->request->postList('rubriky')))) as $idt) {
                if ((int) $data['admin'] < \MiroCMS\Core\Auth::ADMIN && $this->db->value('SELECT idt FROM {topic} WHERE idt = ?', [$idt]) !== null) {
                    $this->db->insert('user_rubriky', ['idu' => $id, 'idt' => $idt]);
                }
            }
            $this->db->delete('vazby_prava', ['fk_id_nadrizeny' => $id]);
            foreach ($podrizeni as $p) {
                $this->db->insert('vazby_prava', ['fk_id_nadrizeny' => $id, 'fk_id_podrizeny' => $p]);
            }
        });

        return $this->zpet('Uživatel byl uložen.');
    }

    /**
     * Sekce, do kterých má role přístup, když je administrátor nenastaví ručně.
     *
     * @return list<string>
     */
    /**
     * Oprávnění uživatele jednou větou - role, vydávání, cizí články, rubriky a moduly jsou ve formuláři na čtyřech místech
     * a správce po uložení potřebuje vidět, co z nich dohromady vyšlo.
     *
     * @param list<string> $moduly identifikátory modulů, ke kterým má přístup
     * @param list<string> $rubriky názvy rubrik, na které je omezen (prázdné = všechny)
     */
    public static function shrnuti(int $role, bool $vydava, array $moduly, array $rubriky, int $cizichAutoru, bool $blokovan = false): string
    {
        if ($blokovan) {
            return t('Účet je zablokovaný – do administrace se nepřihlásí.');
        }
        if ($role >= Auth::ADMIN) {
            return t('Smí všechno včetně nastavení webu a správy uživatelů.');
        }
        $casti = [];
        if (!in_array('clanky', $moduly, true)) {
            $casti[] = t('Nepíše články');
        } elseif ($role >= Auth::REDAKTOR) {
            $casti[] = t('Píše, upravuje a vydává články všech autorů');
        } else {
            $casti[] = $cizichAutoru > 0 ? t('Píše vlastní články a upravuje i články dalších autorů (%d)', $cizichAutoru) : t('Píše a upravuje vlastní články');
            $casti[] = $vydava ? t('vydává je sám') : t('nevydává – vydání schvaluje redakce');
        }
        if ($rubriky !== []) {
            $casti[] = t('jen v rubrikách: %s', implode(', ', array_slice($rubriky, 0, 4)) . (count($rubriky) > 4 ? '…' : ''));
        }
        $nazvy = [];
        foreach (Kernel::MODULY as $class) {
            if ($class::IDENT !== 'clanky' && !$class::JEN_ADMIN && !$class::PRO_VSECHNY && in_array($class::IDENT, $moduly, true)) {
                $nazvy[] = t($class::NAZEV);
            }
        }
        $veta = implode(', ', $casti) . '.';
        if ($nazvy !== []) {
            $veta .= ' ' . t('Dál má přístup k: %s.', implode(', ', $nazvy));
        }

        return $veta;
    }

    public static function vychoziModuly(int $role): array
    {
        $moduly = [];
        foreach (Kernel::MODULY as $class) {
            if ($class::JEN_ADMIN || $class::PRO_VSECHNY) {
                continue;
            }
            if ($role >= Auth::REDAKTOR || $class::IDENT === 'clanky') {
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

        return $this->zpet('Uživatel byl smazán. Jeho články zůstaly zachovány bez autora.');
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
            (int) $autor['admin'], (bool) $autor['pravo_vydavat'],
            array_column($this->db->all('SELECT ident_modulu FROM {user_prava} WHERE fk_id_user = ?', [$id]), 'ident_modulu'),
            array_column($this->db->all('SELECT t.nazev FROM {user_rubriky} ur JOIN {topic} t ON t.idt = ur.idt WHERE ur.idu = ? ORDER BY t.nazev', [$id]), 'nazev'),
            (int) $this->db->value('SELECT COUNT(*) FROM {vazby_prava} WHERE fk_id_nadrizeny = ?', [$id]),
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
            'ostatni' => $this->db->pairs('SELECT idu, IF(jmeno = \'\', user, CONCAT(jmeno, \' (\', user, \')\')) FROM {user} WHERE idu <> ? ORDER BY user', [$id]),
            'maPodrizene' => $this->request->isPost()
                ? array_map(intval(...), $this->request->postList('podrizeni'))
                : array_map(intval(...), array_column($this->db->all('SELECT fk_id_podrizeny FROM {vazby_prava} WHERE fk_id_nadrizeny = ?', [$id]), 'fk_id_podrizeny')),
        ]);
    }
}
