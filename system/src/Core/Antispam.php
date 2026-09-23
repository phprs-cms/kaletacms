<?php

declare(strict_types=1);

namespace MiroCMS\Core;

/**
 * Ochrana formulářů čtenářů bez cookies a bez CAPTCHA:
 *  - podepsaná časová značka (formulář nejde odeslat dřív než za pár vteřin ani po hodinách),
 *  - skryté pole, které člověk nevidí a robot vyplní (honeypot),
 *  - omezení počtu akcí z jedné IP adresy.
 */
final class Antispam
{
    private const int MIN_SEKUND = 4;
    private const int MAX_SEKUND = 4 * 3600;

    public function __construct(private readonly Db $db, private readonly Settings $settings)
    {
    }

    /** Tajný klíč instalace; vznikne při prvním použití. */
    public function klic(): string
    {
        $klic = $this->settings->get('tajny_klic');
        if ($klic === '') {
            $klic = bin2hex(random_bytes(32));
            $this->settings->set('tajny_klic', $klic);
        }

        return $klic;
    }

    /** Skrytá pole do formuláře: podepsaný čas vystavení a past na roboty. */
    public function pole(string $ucel): string
    {
        $cas = (string) time();

        return '<input type="hidden" name="as_cas" value="' . $cas . '"><input type="hidden" name="as_podpis" value="' . hash_hmac('sha256', $ucel . '|' . $cas, $this->klic()) . '">'
            . '<div style="position:absolute;left:-9999px" aria-hidden="true"><label>Toto pole nevyplňujte <input type="text" name="web_adresa" tabindex="-1" autocomplete="off"></label></div>';
    }

    /** @return string|null důvod odmítnutí (už přeložený do jazyka webu; 'robot' je značka, ne text), null = v pořádku */
    public function over(Request $request, string $ucel): ?string
    {
        if ($request->post('web_adresa') !== '') {
            return 'robot';
        }
        $cas = $request->postInt('as_cas');
        if (!hash_equals(hash_hmac('sha256', $ucel . '|' . $cas, $this->klic()), $request->post('as_podpis'))) {
            return t('Formulář se nepodařilo ověřit. Obnovte stránku a zkuste to znovu.');
        }
        $stari = time() - $cas;
        if ($stari < self::MIN_SEKUND) {
            return t('To bylo příliš rychlé. Zkuste to prosím znovu za pár vteřin.');
        }

        return $stari > self::MAX_SEKUND ? t('Platnost formuláře vypršela. Obnovte stránku a zkuste to znovu.') : null;
    }

    /** Kolikrát už IP adresa danou akci za posledních $minut provedla. */
    public function pocet(string $ip, string $typ, int $cil, int $minut): int
    {
        return (int) $this->db->value(
            'SELECT COUNT(*) FROM {kontrola_ip} WHERE typ = ? AND cil = ? AND ip_adresa = ? AND cas > NOW() - INTERVAL ? MINUTE',
            [$typ, $cil, self::otisk($ip), $minut],
        );
    }

    public function zapis(string $ip, string $typ, int $cil): void
    {
        $this->db->insert('kontrola_ip', ['ip_adresa' => self::otisk($ip), 'typ' => $typ, 'cil' => $cil, 'cas' => date('Y-m-d H:i:s')]);
        if (random_int(1, 50) === 1) {
            $this->db->run("DELETE FROM {kontrola_ip} WHERE cas < NOW() - INTERVAL 40 DAY");
        }
    }

    /** Do tabulky se neukládá IP adresa, jen její otisk. */
    public static function otisk(string $ip): string
    {
        return substr(hash('sha256', 'mirocms|' . $ip), 0, 40);
    }
}
