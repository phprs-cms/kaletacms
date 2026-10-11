<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Spam protection for visitors' forms without cookies and without CAPTCHA:
 *  - a signed timestamp (a form cannot be sent sooner than a few seconds, nor after hours),
 *  - a hidden field that a human does not see and a bot fills in (honeypot),
 *  - a limit on the number of actions from one IP address.
 */
final class Antispam
{
    /** A form sent sooner is rejected (bot); image/web.js delays sending by the remainder (attribute data-cekat). */
    public const int MIN_SECONDS = 4;
    private const int MAX_SECONDS = 4 * 3600;

    public function __construct(private readonly Db $db, private readonly Settings $settings)
    {
    }

    /** Secret key of the installation; created on first use. */
    public function key(): string
    {
        $key = $this->settings->get('secret_key');
        if ($key === '') {
            $key = bin2hex(random_bytes(32));
            $this->settings->set('secret_key', $key);
        }

        return $key;
    }

    /** Hidden fields for a form: the signed time of issue and a bot trap. */
    public function fields(string $purpose): string
    {
        $time = (string) time();

        return '<input type="hidden" name="as_cas" value="' . $time . '" data-cekat="' . self::MIN_SECONDS . '"><input type="hidden" name="as_podpis" value="' . hash_hmac('sha256', $purpose . '|' . $time, $this->key()) . '">'
            . '<div style="position:absolute;inset-inline-start:-9999px" aria-hidden="true"><label>' . e(t('Leave this field empty')) . ' <input type="text" name="web_adresa" tabindex="-1" autocomplete="off"></label></div>';
    }

    /** @return string|null reason for rejection (already translated to the site language; 'robot' is a marker, not text), null = OK */
    public function verify(Request $request, string $purpose): ?string
    {
        return match ($this->reason($request, $purpose)) {
            null => null,
            'robot' => 'robot',
            'rychle' => t('That was too fast. Please try again in a few seconds.'),
            'vyprselo' => t('The form has expired. Reload the page and try again.'),
            default => t('The form could not be verified. Reload the page and try again.'),
        };
    }

    /** @return 'robot'|'podpis'|'rychle'|'vyprselo'|null code of the rejection reason (builder forms choose their message by it), null = OK */
    public function reason(Request $request, string $purpose): ?string
    {
        if ($request->post('web_adresa') !== '') {
            return 'robot';
        }
        $time = $request->postInt('as_cas');
        if (!hash_equals(hash_hmac('sha256', $purpose . '|' . $time, $this->key()), $request->post('as_podpis'))) {
            return 'podpis';
        }
        $age = time() - $time;
        if ($age < self::MIN_SECONDS) {
            return 'rychle';
        }

        return $age > self::MAX_SECONDS ? 'vyprselo' : null;
    }

    /** How many times the IP address has already performed the given action in the last $minutes. */
    public function count(string $ip, string $type, int $target, int $minutes): int
    {
        return (int) $this->db->value(
            'SELECT COUNT(*) FROM {kontrola_ip} WHERE typ = ? AND cil = ? AND ip_adresa = ? AND cas > NOW() - INTERVAL ? MINUTE',
            [$type, $target, self::hash($ip), $minutes],
        );
    }

    public function write(string $ip, string $type, int $target): void
    {
        $this->db->insert('kontrola_ip', ['ip_adresa' => self::hash($ip), 'typ' => $type, 'cil' => $target, 'cas' => date('Y-m-d H:i:s')]);
        if (random_int(1, 50) === 1) {
            $this->db->run("DELETE FROM {kontrola_ip} WHERE cas < NOW() - INTERVAL 40 DAY");
        }
    }

    /**
     * The network an address stands for when counting tries (3.3.2): an IPv4 address as it is, an IPv6 address by its
     * /64 prefix – one connection usually gets a whole /64, so counting single IPv6 addresses would count nothing.
     */
    public static function network(string $ip): string
    {
        $packed = @inet_pton($ip);
        if ($packed === false || strlen($packed) !== 16) {
            return $ip;
        }
        if (str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")) {
            return (string) inet_ntop(substr($packed, 12)); // an IPv4 address written as IPv6 (::ffff:1.2.3.4)
        }

        return (string) inet_ntop(substr($packed, 0, 8) . str_repeat("\0", 8)) . '/64';
    }

    /** The table does not store the IP address, only its hash. */
    public static function hash(string $ip): string
    {
        return substr(hash('sha256', 'kaleta|' . $ip), 0, 40);
    }
}
