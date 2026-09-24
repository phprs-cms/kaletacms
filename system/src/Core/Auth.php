<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Přihlášení do administrace a práva.
 *
 * Role: autor píše vlastní novinky (vydat je smí jen s "právem vydávat"), editor spravuje
 * obsah a vydává novinky všech, administrátor navíc uživatele a nastavení. Přístup k modulům se
 * u autorů a redaktorů nastavuje jednotlivě; autor může mít nadřízeného editora.
 */
final class Auth
{
    public const int AUTOR = 0;
    public const int EDITOR = 1;
    public const int ADMIN = 2;

    public const array TYPY = [self::AUTOR => 'autor', self::EDITOR => 'editor', self::ADMIN => 'správce'];

    /** Po tolika chybných heslech nebo kódech v řadě se účet na 15 minut zamkne (sám se zase odemkne). */
    private const int MAX_CHYB = 10;

    /** @var array<string, mixed>|null|false false = ještě nenačteno */
    private array|null|false $user = false;

    /** @var list<string>|null */
    private ?array $moduly = null;

    public function __construct(private readonly Db $db, private readonly Session $session)
    {
    }

    /** @return string|null text chyby, null = přihlášeno */
    public function login(string $login, string $password, string $ip): ?string
    {
        // Zpomalení hádání hesel: nejvýše 10 pokusů z jedné IP za 15 minut
        $pokusu = (int) $this->db->value(
            "SELECT COUNT(*) FROM {kontrola_ip} WHERE typ = 'login' AND ip_adresa = ? AND cas > NOW() - INTERVAL 15 MINUTE",
            [Antispam::otisk($ip)],
        );
        if ($pokusu >= 10) {
            return t('Příliš mnoho pokusů o přihlášení. Zkuste to znovu za 15 minut.');
        }

        $user = $this->db->one('SELECT * FROM {uzivatele} WHERE user = ?', [$login]);
        // Hash se ověřuje i pro neexistujícího uživatele, aby se z doby odezvy nedalo poznat, že účet neexistuje
        $hash = $user['password'] ?? '$2y$12$6C4TPEcYRJ/rRw6iWsrlxu0aH1i91pzK/8KiwqEW3Pa6sTj89Q3Zu';
        $ok = password_verify($password, $hash) && $user !== null;

        if (!$ok) {
            $this->db->insert('kontrola_ip', ['ip_adresa' => Antispam::otisk($ip), 'typ' => 'login', 'cas' => date('Y-m-d H:i:s')]);
            if ($user !== null) {
                // po 10 chybách v řadě se účet zamkne na 15 minut - ne natrvalo, jinak by kdokoli mohl správce webu vyřadit z provozu
                $chyb = (int) $user['pocet_chyb'] + 1;
                $this->db->update('uzivatele', $chyb >= self::MAX_CHYB
                    ? ['pocet_chyb' => 0, 'zamceno_do' => date('Y-m-d H:i:s', time() + 900)]
                    : ['pocet_chyb' => $chyb], ['idu' => $user['idu']]);
            }

            return t('Chybné jméno nebo heslo.');
        }
        if ($user['blokovat']) {
            return t('Účet je zablokován. Obraťte se na administrátora.');
        }
        if ($user['zamceno_do'] !== null && strtotime($user['zamceno_do']) > time()) {
            return t('Účet je po řadě chybných pokusů dočasně zamčený. Zkuste to znovu za 15 minut.');
        }

        if (password_needs_rehash($user['password'], PASSWORD_DEFAULT)) {
            $this->db->update('uzivatele', ['password' => password_hash($password, PASSWORD_DEFAULT)], ['idu' => $user['idu']]);
        }
        $this->db->update('uzivatele', ['pocet_chyb' => 0, 'posledni_login' => date('Y-m-d H:i:s')], ['idu' => $user['idu']]);

        $this->session->regenerate();
        if ($user['totp_tajemstvi'] !== '') {
            // heslo sedí, ale účet má dvoufázové přihlášení: přihlášení dokončí až kód z aplikace
            $this->session->set('idu_ceka', ['idu' => (int) $user['idu'], 'cas' => time()]);

            return null;
        }
        $this->session->set('idu', (int) $user['idu']);
        $this->session->set('otisk', self::otiskHesla((string) $this->db->value('SELECT password FROM {uzivatele} WHERE idu = ?', [$user['idu']])));
        $this->user = false;

        return null;
    }

    /**
     * Otisk hesla uložený v session: po změně hesla přestanou platit všechna ostatní přihlášení téhož účtu
     * (ukradená session, zapomenutý počítač). Sám o sobě nic neprozrazuje - je to zkrácený hash už hashovaného hesla.
     */
    public static function otiskHesla(string $hash): string
    {
        return substr(hash('sha256', 'kaleta-session|' . $hash), 0, 24);
    }

    /** Po změně vlastního hesla: tohle přihlášení zůstává platné, ostatní ne. */
    public function obnovPoZmeneHesla(string $novyHash): void
    {
        $this->session->regenerate();
        $this->session->set('otisk', self::otiskHesla($novyHash));
        $this->user = false;
    }

    /** Heslo bylo zadáno správně a čeká se na kód z ověřovací aplikace (nejdéle 5 minut). */
    public function cekaNaKod(): bool
    {
        $ceka = $this->session->get('idu_ceka');

        return is_array($ceka) && time() - (int) $ceka['cas'] < 300;
    }

    /** Druhý krok přihlášení: kód z aplikace, nebo jednorázový záložní kód. @return string|null text chyby */
    public function overKod(string $kod, string $ip): ?string
    {
        if (!$this->cekaNaKod()) {
            return t('Přihlášení vypršelo, začněte prosím znovu.');
        }
        $pokusu = (int) $this->db->value("SELECT COUNT(*) FROM {kontrola_ip} WHERE typ = 'login' AND ip_adresa = ? AND cas > NOW() - INTERVAL 15 MINUTE", [Antispam::otisk($ip)]);
        if ($pokusu >= 10) {
            return t('Příliš mnoho pokusů. Zkuste to znovu za 15 minut.');
        }
        $user = $this->db->one('SELECT * FROM {uzivatele} WHERE idu = ? AND blokovat = 0', [(int) $this->session->get('idu_ceka')['idu']]);
        if ($user !== null && $user['zamceno_do'] !== null && strtotime($user['zamceno_do']) > time()) {
            $this->session->remove('idu_ceka');

            return t('Účet je po řadě chybných pokusů dočasně zamčený. Zkuste to znovu za 15 minut.');
        }
        $zalozni = $user === null ? null : Totp::pouzijZalozni($user['totp_zalozni'], $kod);
        if ($user === null || (!Totp::over($user['totp_tajemstvi'], $kod) && $zalozni === null)) {
            $this->db->insert('kontrola_ip', ['ip_adresa' => Antispam::otisk($ip), 'typ' => 'login', 'cas' => date('Y-m-d H:i:s')]);
            if ($user !== null) {
                // chybné kódy se počítají na účet, ne jen na IP adresu: kdo zná heslo, nesmí kódy zkoušet z mnoha adres
                $chyb = (int) $user['pocet_chyb'] + 1;
                $this->db->update('uzivatele', $chyb >= self::MAX_CHYB
                    ? ['pocet_chyb' => 0, 'zamceno_do' => date('Y-m-d H:i:s', time() + 900)]
                    : ['pocet_chyb' => $chyb], ['idu' => $user['idu']]);
            }

            return t('Kód není správný.');
        }
        $this->db->update('uzivatele', ['pocet_chyb' => 0], ['idu' => $user['idu']]);
        if ($zalozni !== null) {
            $this->db->update('uzivatele', ['totp_zalozni' => $zalozni], ['idu' => $user['idu']]);
        }
        $this->session->remove('idu_ceka');
        $this->session->regenerate();
        $this->session->set('idu', (int) $user['idu']);
        $this->session->set('otisk', self::otiskHesla((string) $user['password']));
        $this->user = false;

        return null;
    }

    /** Má účet, který čeká na druhý krok, zaregistrované přihlašovací klíče? */
    public function cekaSKlici(): bool
    {
        return $this->cekaNaKod() && $this->kliceUctu((int) $this->session->get('idu_ceka')['idu']) !== [];
    }

    /** @return list<array<string, mixed>> přihlašovací klíče účtu */
    public function kliceUctu(int $idu): array
    {
        return $this->db->all('SELECT * FROM {uzivatele_klice} WHERE idu = ? ORDER BY idk', [$idu]);
    }

    /**
     * Druhý krok přihlášení klíčem, 1. část: výzva pro zařízení. Platí jen pro účet, který právě zadal správné heslo.
     *
     * @return array<string, mixed>|null nastavení pro navigator.credentials.get(), null = není na co čekat
     */
    public function vyzvaKlice(string $adresaWebu): ?array
    {
        if (!$this->cekaSKlici()) {
            return null;
        }
        $vyzva = Passkey::vyzva();
        $this->session->set('klic_vyzva', $vyzva);

        return Passkey::moznostiPrihlaseni($vyzva, Passkey::rpId($adresaWebu), array_map(static fn (array $k): string => (string) $k['id_klice'], $this->kliceUctu((int) $this->session->get('idu_ceka')['idu'])));
    }

    /**
     * Druhý krok přihlášení klíčem, 2. část: ověření podpisu. Neúspěch se počítá stejně jako chybný kód.
     *
     * @param array<string, mixed> $odpoved
     * @return string|null text chyby, null = přihlášeno
     */
    public function overKlic(array $odpoved, string $adresaWebu, string $ip): ?string
    {
        if (!$this->cekaNaKod()) {
            return t('Přihlášení vypršelo, začněte prosím znovu.');
        }
        $pokusu = (int) $this->db->value("SELECT COUNT(*) FROM {kontrola_ip} WHERE typ = 'login' AND ip_adresa = ? AND cas > NOW() - INTERVAL 15 MINUTE", [Antispam::otisk($ip)]);
        if ($pokusu >= 10) {
            return t('Příliš mnoho pokusů. Zkuste to znovu za 15 minut.');
        }
        $vyzva = (string) $this->session->get('klic_vyzva', '');
        $this->session->remove('klic_vyzva'); // výzva platí na jeden pokus
        $user = $this->db->one('SELECT * FROM {uzivatele} WHERE idu = ? AND blokovat = 0', [(int) $this->session->get('idu_ceka')['idu']]);
        if ($user !== null && $user['zamceno_do'] !== null && strtotime($user['zamceno_do']) > time()) {
            $this->session->remove('idu_ceka');

            return t('Účet je po řadě chybných pokusů dočasně zamčený. Zkuste to znovu za 15 minut.');
        }
        $klic = $user === null ? null : $this->db->one('SELECT * FROM {uzivatele_klice} WHERE idu = ? AND otisk_id = ?', [$user['idu'], hash('sha256', Passkey::zB64((string) ($odpoved['id'] ?? '')))]);
        try {
            if ($klic === null) {
                throw new \RuntimeException('Tenhle klíč k účtu nepatří.');
            }
            $pocitadlo = Passkey::overPrihlaseni($odpoved, $vyzva, Passkey::puvod($adresaWebu), Passkey::rpId($adresaWebu), (string) $klic['verejny'], (int) $klic['pocitadlo']);
        } catch (\RuntimeException $e) {
            $this->db->insert('kontrola_ip', ['ip_adresa' => Antispam::otisk($ip), 'typ' => 'login', 'cas' => date('Y-m-d H:i:s')]);
            if ($user !== null) {
                $chyb = (int) $user['pocet_chyb'] + 1;
                $this->db->update('uzivatele', $chyb >= self::MAX_CHYB
                    ? ['pocet_chyb' => 0, 'zamceno_do' => date('Y-m-d H:i:s', time() + 900)]
                    : ['pocet_chyb' => $chyb], ['idu' => $user['idu']]);
            }

            return t($e->getMessage());
        }
        $this->db->update('uzivatele_klice', ['pocitadlo' => $pocitadlo, 'pouzito' => date('Y-m-d H:i:s')], ['idk' => $klic['idk']]);
        $this->db->update('uzivatele', ['pocet_chyb' => 0], ['idu' => $user['idu']]);
        $this->session->remove('idu_ceka');
        $this->session->regenerate();
        $this->session->set('idu', (int) $user['idu']);
        $this->session->set('otisk', self::otiskHesla((string) $user['password']));
        $this->user = false;

        return null;
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
            // bez cookie session není kdo by byl přihlášený - a session se kvůli dotazu nezakládá (web zůstává cachovatelný)
            if (!isset($_COOKIE['kaleta'])) {
                return $this->user = null;
            }
            $id = $this->session->get('idu');
            $this->user = is_int($id)
                ? $this->db->one('SELECT * FROM {uzivatele} WHERE idu = ? AND blokovat = 0', [$id])
                : null;
            if ($this->user !== null) {
                $otisk = $this->session->get('otisk');
                if ($otisk === null) {
                    $this->session->set('otisk', self::otiskHesla((string) $this->user['password'])); // přihlášení z doby před touto kontrolou
                } elseif (!hash_equals(self::otiskHesla((string) $this->user['password']), (string) $otisk)) {
                    $this->session->remove('idu'); // heslo se od přihlášení změnilo
                    $this->user = null;
                }
            }
        }

        return $this->user;
    }

    /** Přihlášení bez session - pro požadavky ověřené tokenem (MCP). */
    public function prihlasJako(array $user): void
    {
        $this->user = $user;
        $this->moduly = null;
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

    public function smiVydavat(): bool
    {
        return $this->isAdmin() || $this->isEditor();
    }

    /** Má přihlášený uživatel přístup k modulu? Admin vždy; ostatní podle ka_uzivatele_prava. */
    public function maModul(string $ident, bool $proVsechny = false): bool
    {
        if ($this->user() === null) {
            return false;
        }
        if ($this->isAdmin() || $proVsechny) {
            return true;
        }
        $this->moduly ??= array_column(
            $this->db->all('SELECT ident_modulu FROM {uzivatele_prava} WHERE fk_id_user = ?', [$this->id()]),
            'ident_modulu',
        );

        return in_array($ident, $this->moduly, true);
    }

    /**
     * Smí přihlášený upravit tuto novinku? Stejná pravidla jako v administraci: modul Novinky, autor smí jen své
     * a vydanou novinku jen ten, kdo smí vydávat.
     *
     * @param array<string, mixed> $clanek řádek ka_novinky
     */
    public function smiUpravitClanek(array $clanek): bool
    {
        if (!$this->maModul('novinky')) {
            return false;
        }
        $autori = $this->spravovaniAutori();

        return ($autori === null || in_array((int) $clanek['autor'], $autori, true)) && (empty($clanek['visible']) || $this->smiVydavat());
    }

    /**
     * Část podmínky WHERE (začíná „ AND“, nebo je prázdná), která výpis novinek omezí na to, co přihlášený smí vidět:
     * autor jen své novinky, editor a správce všechny.
     */
    public function articleScope(string $alias = ''): string
    {
        $autori = $this->spravovaniAutori();

        return $autori === null ? '' : ' AND ' . $alias . 'autor IN (' . implode(',', array_map(intval(...), $autori)) . ')';
    }

    /**
     * ID autorů, jejichž novinky smí uživatel spravovat: autor jen sebe, editor a správce všechny (null = bez omezení).
     *
     * @return list<int>|null
     */
    public function spravovaniAutori(): ?array
    {
        return $this->isAdmin() || $this->isEditor() ? null : [$this->id()];
    }
}
