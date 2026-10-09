<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Domain and mail watch (2.8, "runs itself"): once a day the site checks what nobody notices until it breaks.
 *
 *  - Mail deliverability: most "the form does not work" calls are mail landing in spam. The sending domain (the domain
 *    of the From address – mail_from, otherwise site_email) needs an SPF record, a DMARC record and a DKIM key; with an
 *    SMTP server the SPF record should include it.
 *  - The site's TLS certificate: days until it expires (a TLS handshake with the site's own host from site_url).
 *  - The domain registration: days until it expires, read from RDAP (https://rdap.org redirects to the registry).
 *
 * The result is a JSON in the setting "domain_watch" with the time of the check (Settings::DEFAULTS, internal).
 * No page view ever waits for DNS or a remote server: System status (Core\Health) and the site audit (Core\Audit) read
 * the cached result only. refresh() does the network work – the 2.8 scheduler calls it once a day, System status when the
 * cached result is older than a day or when the administrator presses "Check now". The public demo makes no requests.
 *
 * Limitations, on purpose: the registrable domain is computed from a short list of two-level public suffixes
 * (co.uk, com.au…) – not the whole public suffix list; DKIM is looked up only under common selectors, so a provider with
 * its own selector is reported as "not found", never as "missing"; SPF is read, not evaluated (include:/a/mx
 * mechanisms are matched against the SMTP host, ip4:/ip6: cannot be judged); registries that do not publish an expiry
 * date give "unknown"; a site on a local address or IP (development) is not checked at all.
 *
 * The DNS lookup, the HTTPS fetch and the TLS handshake are injectable callables, so unit tests feed fixtures.
 */
final class DomainWatch
{
    /** The setting the cached result lives in (JSON). */
    public const string SETTING = 'domain_watch';


    /** Days left on the certificate or the domain: under WARNING_DAYS a warning, under ERROR_DAYS an error. */
    public const int WARNING_DAYS = 21;
    public const int ERROR_DAYS = 7;

    /** DKIM selectors tried in turn (<selector>._domainkey.<domain>); the real selector is only known to the mail provider. */
    public const array DKIM_SELECTORS = ['default', 'google', 'selector1', 'selector2', 'k1', 'mail', 'dkim', 's1', 's2', 'smtp'];

    /** Common two-level public suffixes: the registrable domain of shop.example.co.uk is example.co.uk, not co.uk. */
    public const array TWO_LEVEL_SUFFIXES = ['co.uk', 'org.uk', 'me.uk', 'ac.uk', 'gov.uk', 'net.uk', 'ltd.uk', 'plc.uk', 'com.au', 'net.au', 'org.au', 'edu.au', 'gov.au',
        'co.nz', 'net.nz', 'org.nz', 'com.br', 'net.br', 'org.br', 'co.za', 'org.za', 'co.jp', 'ne.jp', 'or.jp', 'co.in', 'net.in', 'org.in', 'com.mx', 'com.ar', 'com.tr',
        'com.pl', 'net.pl', 'org.pl', 'co.il', 'org.il', 'com.sg', 'com.hk', 'com.cn', 'net.cn', 'org.cn', 'com.tw', 'co.kr', 'com.ua', 'co.id', 'com.my', 'com.ph', 'com.vn'];

    /** SPF include for well-known SMTP providers, by the registrable domain of the SMTP host. Others get include:<their domain>. */
    private const array SPF_INCLUDES = ['gmail.com' => '_spf.google.com', 'google.com' => '_spf.google.com', 'googlemail.com' => '_spf.google.com',
        'office365.com' => 'spf.protection.outlook.com', 'outlook.com' => 'spf.protection.outlook.com', 'seznam.cz' => 'spf.seznam.cz',
        'brevo.com' => 'spf.brevo.com', 'sendinblue.com' => 'spf.brevo.com', 'sendgrid.net' => 'sendgrid.net', 'mailgun.org' => 'mailgun.org',
        'amazonaws.com' => 'amazonses.com', 'postmarkapp.com' => 'spf.mtasv.net', 'mandrillapp.com' => 'spf.mandrillapp.com', 'mailjet.com' => 'spf.mailjet.com',
        'smtp2go.com' => 'spf.smtp2go.com', 'zoho.com' => 'zohomail.com', 'zoho.eu' => 'zohomail.eu', 'mailersend.net' => '_spf.mailersend.net'];

    private const int TIMEOUT = 5;
    private const int MAX_BYTES = 256 * 1024;
    private const int MAX_REDIRECTS = 3;

    /** @var callable(string, int): (list<array<string, mixed>>|false)  DNS lookup: name and DNS_* type, records or false on failure */
    private $dns;

    /** @var callable(string): array{0: int, 1: string}  HTTPS GET following redirects: status and body, throws RuntimeException */
    private $http;

    /** @var callable(string): int  TLS handshake with host:443: expiry of the certificate as a timestamp, throws RuntimeException */
    private $tls;

    public function __construct(?callable $dns = null, ?callable $http = null, ?callable $tls = null)
    {
        $this->dns = $dns ?? self::lookup(...);
        $this->http = $http ?? self::fetch(...);
        $this->tls = $tls ?? self::certificateExpiry(...);
    }

    /* ---------- the cached result ---------- */

    /**
     * The result of the last check, null when none has run yet.
     *
     * @return array<string, mixed>|null
     */
    public static function cached(Settings $s): ?array
    {
        $result = json_decode($s->get(self::SETTING), true);

        return is_array($result) && isset($result['checked']) ? $result : null;
    }

    /* ---------- the check ---------- */

    /**
     * Runs every check and stores the result in the setting. Called once a day by the scheduler and by "Check now".
     *
     * @return array<string, mixed> the stored result (see collect()); in the public demo only ['checked' => …, 'demo' => true], nothing is stored
     */
    public function refresh(App $app): array
    {
        if (Demo::active()) {
            return ['checked' => time(), 'demo' => true]; // no request leaves the public demo
        }
        $s = $app->settings();
        $from = $s->get('mail_from') !== '' ? $s->get('mail_from') : $s->get('site_email');
        $result = $this->collect([
            'site_host' => self::ascii((string) parse_url($s->get('site_url'), PHP_URL_HOST)),
            'https' => str_starts_with(strtolower($s->get('site_url')), 'https://'),
            'mail_domain' => self::ascii(substr((string) strrchr($from, '@'), 1)),
            'smtp_host' => $s->get('mail_mode') === 'smtp' ? self::ascii($s->get('smtp_host')) : '',
            'report_email' => $s->get('site_email'),
        ]);
        $s->set(self::SETTING, (string) json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $result;
    }

    /**
     * The checks themselves, without the database: what the site is (hosts and domains) in, the result out.
     *
     * @param array{site_host: string, https: bool, mail_domain: string, smtp_host: string, report_email?: string} $site report_email = where DMARC reports should go (the site e-mail)
     * @return array<string, mixed> checked (timestamp), site_host, report_email, local (true = nothing checked), mail, tls, domain
     */
    public function collect(array $site, ?int $now = null): array
    {
        $now ??= time();
        $result = ['checked' => $now, 'site_host' => $site['site_host'], 'report_email' => $site['report_email'] ?? '', 'mail' => null, 'tls' => null, 'domain' => null];
        if (!self::isPublicHost($site['site_host'])) {
            $result['local'] = true; // development on localhost or an IP: no certificate, no registration, mail is not delivered from here either

            return $result;
        }
        if ($site['mail_domain'] !== '' && self::isPublicHost($site['mail_domain'])) {
            $result['mail'] = $this->mail($site['mail_domain'], $site['smtp_host']);
        }
        if ($site['https']) {
            $result['tls'] = $this->certificate($site['site_host'], $now);
        }
        $result['domain'] = $this->domain(self::registrableDomain($site['site_host']), $now);

        return $result;
    }

    /**
     * SPF, DMARC and DKIM of the sending domain.
     *
     * @return array{domain: string, smtp_host: string, spf: ?string, spf_covers_smtp: ?bool, dmarc: ?string, dkim: ?string, error: ?string}
     */
    private function mail(string $domain, string $smtpHost): array
    {
        $out = ['domain' => $domain, 'smtp_host' => $smtpHost, 'spf' => null, 'spf_covers_smtp' => null, 'dmarc' => null, 'dkim' => null, 'error' => null];
        $records = $this->txt($domain);
        if ($records === null) {
            $out['error'] = 'dns'; // the resolver did not answer – a message, not a verdict (Health translates the marker)

            return $out;
        }
        foreach ($records as $record) {
            if (preg_match('/^v=spf1(\s|$)/i', $record)) {
                $out['spf'] = $record;
                break;
            }
        }
        if ($out['spf'] !== null && $smtpHost !== '') {
            $out['spf_covers_smtp'] = self::spfCovers($out['spf'], $domain, $smtpHost);
        }
        foreach ($this->txt('_dmarc.' . $domain) ?? [] as $record) {
            if (preg_match('/^v=DMARC1\s*(;|$)/i', $record)) {
                $out['dmarc'] = $record;
                break;
            }
        }
        // 3.9: a known mail service's own selectors first (Brevo signs with brevo1/brevo2, Mailjet with mailjet…)
        foreach (array_values(array_unique([...MailServices::dkimSelectors(MailServices::detect($smtpHost)), ...self::DKIM_SELECTORS])) as $selector) {
            foreach ($this->txt($selector . '._domainkey.' . $domain) ?? [] as $record) {
                if (preg_match('/(^|;)\s*(v=DKIM1|p=)/i', $record)) {
                    $out['dkim'] = $selector;
                    break 2;
                }
            }
        }

        return $out;
    }

    /** @return array{host: string, expires: ?int, days: ?int, error: ?string} */
    private function certificate(string $host, int $now): array
    {
        try {
            $expires = ($this->tls)($host);

            return ['host' => $host, 'expires' => $expires, 'days' => self::daysUntil($expires, $now), 'error' => null];
        } catch (\Throwable $e) {
            return ['host' => $host, 'expires' => null, 'days' => null, 'error' => $e->getMessage()];
        }
    }

    /** @return array{name: string, expires: ?int, days: ?int, error: ?string} expires null without an error = the registry does not publish it */
    private function domain(string $name, int $now): array
    {
        try {
            [$status, $body] = ($this->http)('https://rdap.org/domain/' . rawurlencode($name));
            if ($status === 404) {
                return ['name' => $name, 'expires' => null, 'days' => null, 'error' => null]; // the registry has no RDAP server
            }
            if ($status !== 200) {
                throw new \RuntimeException('HTTP ' . $status);
            }
            $expires = self::rdapExpiry($body);

            return ['name' => $name, 'expires' => $expires, 'days' => $expires === null ? null : self::daysUntil($expires, $now), 'error' => null];
        } catch (\Throwable $e) {
            return ['name' => $name, 'expires' => null, 'days' => null, 'error' => $e->getMessage()];
        }
    }

    /** @return list<string>|null the TXT records of a name; [] = none, null = the lookup failed */
    private function txt(string $name): ?array
    {
        if (!preg_match('/^[a-z0-9_.-]{1,253}$/iD', $name)) {
            return null;
        }
        try {
            $records = ($this->dns)($name, DNS_TXT);
        } catch (\Throwable) {
            $records = false;
        }
        if ($records === false) {
            return null;
        }
        $out = [];
        foreach ($records as $record) {
            if (isset($record['txt']) && is_string($record['txt'])) {
                $out[] = trim($record['txt']);
            }
        }

        return $out;
    }

    /* ---------- pure helpers (unit tests) ---------- */

    /** Something a registry, a certificate and a resolver know: not an IP, not localhost, not a development suffix. */
    public static function isPublicHost(string $host): bool
    {
        $host = strtolower(trim($host, '. '));

        return $host !== '' && str_contains($host, '.') && filter_var($host, FILTER_VALIDATE_IP) === false
            && preg_match('/^[a-z0-9.-]+$/D', $host) === 1 && !preg_match('/\.(localhost|local|test|internal|home|lan|invalid)$/', $host);
    }

    /** example.co.uk for www.shop.example.co.uk – a leading www. is dropped, two-level suffixes from TWO_LEVEL_SUFFIXES are kept whole. */
    public static function registrableDomain(string $host): string
    {
        $host = strtolower(trim($host, '. '));
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }
        $parts = explode('.', $host);
        if (count($parts) <= 2) {
            return $host;
        }
        $keep = in_array(implode('.', array_slice($parts, -2)), self::TWO_LEVEL_SUFFIXES, true) ? 3 : 2;

        return implode('.', array_slice($parts, -$keep));
    }

    /**
     * Does the SPF record seem to allow the SMTP server? Best effort, not an evaluation: include:/redirect= targets and a:/mx:
     * hosts are compared by registrable domain (a known provider also by its published include), bare a/mx count when the
     * SMTP server lives in the sending domain. null = cannot tell (only ip4:/ip6: or a non-SPF string).
     */
    public static function spfCovers(string $spf, string $domain, string $smtpHost): ?bool
    {
        if (!preg_match('/^v=spf1(\s|$)/i', trim($spf))) {
            return null;
        }
        $smtpBase = self::registrableDomain($smtpHost);
        $known = self::SPF_INCLUDES[$smtpBase] ?? null;
        $knownBase = $known !== null ? self::registrableDomain($known) : null;
        $undecidable = false;
        foreach (preg_split('/\s+/', trim($spf)) ?: [] as $term) {
            $term = strtolower(ltrim($term, '+'));
            if (preg_match('/^(include|redirect|a|mx|exists)[:=](.+?)(\/\d+)?$/', $term, $m)) {
                $targetBase = self::registrableDomain($m[2]);
                if ($targetBase === $smtpBase || ($knownBase !== null && $targetBase === $knownBase)) {
                    return true;
                }
            } elseif ($term === 'a' || $term === 'mx' || preg_match('/^(a|mx)\/\d+$/', $term)) {
                if ($smtpBase === self::registrableDomain($domain)) {
                    return true;
                }
            } elseif (str_starts_with($term, 'ip4:') || str_starts_with($term, 'ip6:')) {
                $undecidable = true; // the SMTP server may well be one of these addresses
            }
        }

        return $undecidable ? null : false;
    }

    /** The SPF record to add: the SMTP provider's include, or the site's own server when mail goes out from there. */
    public static function suggestedSpf(string $domain, string $smtpHost, string $siteHost): string
    {
        $domainBase = self::registrableDomain($domain);
        if ($smtpHost === '') {
            // the server's mail() function: the web server sends it
            return self::registrableDomain($siteHost) === $domainBase || $siteHost === '' ? 'v=spf1 a mx ~all' : 'v=spf1 mx a:' . self::registrableDomain($siteHost) . ' ~all';
        }
        $smtpBase = self::registrableDomain($smtpHost);
        if ($smtpBase === $domainBase) {
            return 'v=spf1 a mx ~all';
        }

        return 'v=spf1 mx include:' . (self::SPF_INCLUDES[$smtpBase] ?? $smtpBase) . ' ~all';
    }

    /** A DMARC starter record: monitor only (p=none), reports to the site e-mail. */
    public static function suggestedDmarc(string $reportEmail): string
    {
        return 'v=DMARC1; p=none' . ($reportEmail !== '' && filter_var($reportEmail, FILTER_VALIDATE_EMAIL) !== false ? '; rua=mailto:' . $reportEmail : '');
    }

    /** @return array{p: string, rua: string} the policy and the report address of a DMARC record ('' when absent) */
    public static function parseDmarc(string $record): array
    {
        $out = ['p' => '', 'rua' => ''];
        foreach (explode(';', $record) as $tag) {
            if (preg_match('/^\s*(p|rua)\s*=\s*(.+?)\s*$/i', $tag, $m)) {
                $out[strtolower($m[1])] = $m[2];
            }
        }

        return $out;
    }

    /** The expiry date from an RDAP domain response (the "expiration" event), null when the registry does not publish it. */
    public static function rdapExpiry(string $json): ?int
    {
        $data = json_decode($json, true);
        $events = is_array($data) && is_array($data['events'] ?? null) ? $data['events'] : [];
        foreach ($events as $event) {
            if (is_array($event) && ($event['eventAction'] ?? '') === 'expiration' && is_string($event['eventDate'] ?? null)) {
                $time = strtotime($event['eventDate']);

                return $time === false ? null : $time;
            }
        }

        return null;
    }

    public static function daysUntil(int $timestamp, int $now): int
    {
        return (int) floor(($timestamp - $now) / 86400);
    }

    /** ok | varovani | chyba by the days left; unknown is not a problem. */
    public static function level(?int $days): string
    {
        return match (true) {
            $days === null => 'ok',
            $days < self::ERROR_DAYS => 'chyba',
            $days < self::WARNING_DAYS => 'varovani',
            default => 'ok',
        };
    }

    /* ---------- for System status and the audit ---------- */

    /**
     * Rows for Core\Health::checks() from a cached result.
     *
     * @param array<string, mixed>|null $result the cached result (null = none yet)
     * @return list<array{skupina: string, nazev: string, stav: string, info: string}>
     */
    public static function rows(?array $result, bool $demo, int $now): array
    {
        $group = t('Domain and mail');
        $rows = [];
        $add = function (string $name, string $state, string $info) use (&$rows, $group): void {
            $rows[] = ['skupina' => $group, 'nazev' => $name, 'stav' => $state, 'info' => $info];
        };
        if ($demo) {
            $add(t('Checks'), 'ok', t('switched off in the public demo – no request leaves it'));

            return $rows;
        }
        if ($result === null) {
            $add(t('Checks'), 'ok', t('not checked yet – the background jobs check it once a day, or use Check now below'));

            return $rows;
        }
        if (!empty($result['local'])) {
            $add(t('Checks'), 'ok', t('the site runs on a local address (%s) – the certificate, the domain registration and the mail DNS records are checked once it has its public address', (string) ($result['site_host'] ?? '')));

            return $rows;
        }

        $mail = is_array($result['mail'] ?? null) ? $result['mail'] : null;
        if ($mail === null) {
            $add(t('Sending domain'), 'varovani', t('no sender address – fill in the site e-mail (Settings → General) so the mail records can be checked'));
        } elseif ($mail['error'] !== null) {
            $add(t('Sending domain'), 'varovani', t('the DNS records of %s could not be read (the server did not get an answer from its resolver)', (string) $mail['domain']));
        } else {
            $domain = (string) $mail['domain'];
            $smtp = (string) $mail['smtp_host'];
            $service = MailServices::detect($smtp); // 3.9: the mail service behind the SMTP server, if it is a known one
            if ($mail['spf'] === null) {
                $add(t('SPF record'), 'varovani', t('%s has no SPF record – receiving servers cannot tell that its mail is legitimate and often file it as spam. Add a TXT record on %s: %s', $domain, $domain, self::suggestedSpf($domain, $smtp, (string) ($result['site_host'] ?? ''))));
            } else {
                $covers = $mail['spf_covers_smtp'];
                // 3.9: a mail service that authenticates through its own return path and DKIM needs no include in the domain's SPF
                $noInclude = $covers === false && $service !== null && MailServices::PROVIDERS[$service]['spf'] === '';
                $add(t('SPF record'), $covers === false && !$noInclude ? 'varovani' : 'ok', $domain . ': ' . $mail['spf'] . match (true) {
                    $covers === true => ' – ' . t('includes the SMTP server %s', $smtp),
                    $noInclude => ' – ' . t('%s needs no include in this record – its DKIM and return-path records authenticate the mail', MailServices::name($service)),
                    $covers === false => ' – ' . t('does not seem to include the SMTP server %s; add %s (or the value your mail provider publishes)', $smtp, 'include:' . (self::SPF_INCLUDES[self::registrableDomain($smtp)] ?? self::registrableDomain($smtp))),
                    default => '',
                });
            }
            if ($mail['dmarc'] === null) {
                $add(t('DMARC record'), 'varovani', t('%s has no DMARC record – Gmail and Microsoft require it from senders. Add a TXT record on %s: %s', $domain, '_dmarc.' . $domain, self::suggestedDmarc((string) ($result['report_email'] ?? ''))));
            } else {
                $policy = self::parseDmarc((string) $mail['dmarc'])['p'];
                $add(t('DMARC record'), 'ok', $domain . ': ' . $mail['dmarc'] . ($policy === 'none' ? ' – ' . t('policy p=none only reports; once the reports look right, move to p=quarantine') : ''));
            }
            // not found under the common selectors is no proof – many providers use their own, so it is a note, not a warning
            $add(t('DKIM signature'), 'ok', match (true) {
                $mail['dkim'] !== null => t('key published under the selector %s', (string) $mail['dkim']),
                // 3.9: the chosen mail service says which records to add
                $service !== null => t('no DKIM key found under the common selectors. %s: %s', MailServices::name($service), t(MailServices::PROVIDERS[$service]['dns'])),
                default => t('no DKIM key found under the common selectors (%s). If your mail provider signs with another selector, all is well; otherwise turn DKIM signing on with the provider and publish its key in DNS', implode(', ', self::DKIM_SELECTORS)),
            });
        }

        $tls = is_array($result['tls'] ?? null) ? $result['tls'] : null;
        if ($tls !== null) {
            if ($tls['error'] !== null) {
                $add(t('Certificate'), 'varovani', t('could not be read from %s: %s', (string) $tls['host'] . ':443', (string) $tls['error']));
            } else {
                $days = (int) $tls['days'];
                $date = format_date((new \DateTimeImmutable())->setTimestamp((int) $tls['expires']));
                $add(t('Certificate'), self::level($days), ($days >= 0 ? t('expires in %d days (%s)', $days, $date) : t('expired %d days ago (%s) – browsers warn visitors away from the site', -$days, $date))
                    . ($days < self::WARNING_DAYS && $days >= 0 ? ' – ' . t('renew it, or check that the automatic renewal (Let’s Encrypt) still works') : ''));
            }
        }

        $registration = is_array($result['domain'] ?? null) ? $result['domain'] : null;
        if ($registration !== null) {
            $name = (string) $registration['name'];
            if ($registration['error'] !== null) {
                $add(t('Domain registration'), 'varovani', t('the expiry of %s could not be checked: %s', $name, (string) $registration['error']));
            } elseif ($registration['expires'] === null) {
                $add(t('Domain registration'), 'ok', t('%s – the registry does not publish the expiry date; check it with your registrar', $name));
            } else {
                $days = (int) $registration['days'];
                $date = format_date((new \DateTimeImmutable())->setTimestamp((int) $registration['expires']));
                $add(t('Domain registration'), self::level($days), ($days >= 0 ? t('%s expires in %d days (%s)', $name, $days, $date) : t('%s expired %d days ago (%s)', $name, -$days, $date))
                    . ($days < self::WARNING_DAYS ? ' – ' . t('renew the domain with your registrar – an expired domain takes the site and its mail down') : ''));
            }
        }

        return $rows;
    }

    /**
     * What the site audit lists before handing over (Core\Audit::handover): the certain problems only – DKIM under an
     * unknown selector or a registry without RDAP is not one.
     *
     * @param array<string, mixed>|null $result
     * @return list<array{key: string, message: string}>
     */
    public static function handoverFindings(?array $result): array
    {
        $findings = [];
        if ($result === null || !empty($result['local'])) {
            return $findings;
        }
        $mail = is_array($result['mail'] ?? null) ? $result['mail'] : null;
        if ($mail !== null && $mail['error'] === null) {
            if ($mail['spf'] === null) {
                $findings[] = ['key' => 'spf', 'message' => t('The sending domain %s has no SPF record – mail from the forms will land in spam. Add a TXT record: %s', (string) $mail['domain'], self::suggestedSpf((string) $mail['domain'], (string) $mail['smtp_host'], (string) ($result['site_host'] ?? '')))];
            }
            if ($mail['dmarc'] === null) {
                $findings[] = ['key' => 'dmarc', 'message' => t('The sending domain %s has no DMARC record – Gmail and Microsoft require it. Add a TXT record on %s: %s', (string) $mail['domain'], '_dmarc.' . $mail['domain'], self::suggestedDmarc((string) ($result['report_email'] ?? '')))];
            }
        }
        $tls = is_array($result['tls'] ?? null) ? $result['tls'] : null;
        if ($tls !== null && $tls['error'] === null && (int) $tls['days'] < self::WARNING_DAYS) {
            $findings[] = ['key' => 'certificate', 'message' => (int) $tls['days'] >= 0
                ? t('The site certificate expires in %d days – check that it renews.', (int) $tls['days'])
                : t('The site certificate has expired – browsers warn visitors away from the site.')];
        }
        $registration = is_array($result['domain'] ?? null) ? $result['domain'] : null;
        if ($registration !== null && $registration['error'] === null && $registration['expires'] !== null && (int) $registration['days'] < self::WARNING_DAYS) {
            $findings[] = ['key' => 'domain', 'message' => (int) $registration['days'] >= 0
                ? t('The domain %s expires in %d days – renew it with the registrar.', (string) $registration['name'], (int) $registration['days'])
                : t('The domain %s has expired – renew it with the registrar before someone else takes it.', (string) $registration['name'])];
        }

        return $findings;
    }

    /* ---------- the network (the defaults of the injectable callables) ---------- */

    /** @return list<array<string, mixed>>|false */
    private static function lookup(string $name, int $type): array|false
    {
        try {
            $records = @dns_get_record($name, $type);
        } catch (\Throwable) {
            return false;
        }

        return $records;
    }

    /**
     * HTTPS GET with at most MAX_REDIRECTS redirects, https only, a small body.
     *
     * @return array{0: int, 1: string}
     */
    private static function fetch(string $url): array
    {
        for ($i = 0; $i <= self::MAX_REDIRECTS; $i++) {
            if (!preg_match('#^https://[a-z0-9.-]+(:\d+)?/#i', $url)) {
                throw new \RuntimeException('only https:// addresses are followed');
            }
            [$status, $location, $body] = function_exists('curl_init') ? self::curlRequest($url) : self::streamRequest($url);
            if (!in_array($status, [301, 302, 303, 307, 308], true) || $location === '') {
                return [$status, $body];
            }
            $url = str_starts_with($location, '/') ? (string) parse_url($url, PHP_URL_SCHEME) . '://' . (string) parse_url($url, PHP_URL_HOST) . $location : $location;
        }
        throw new \RuntimeException('too many redirects');
    }

    /** @return array{0: int, 1: string, 2: string} status, Location header, body */
    private static function curlRequest(string $url): array
    {
        $location = '';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => self::TIMEOUT, CURLOPT_TIMEOUT => self::TIMEOUT * 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_MAXFILESIZE => self::MAX_BYTES,
            CURLOPT_HTTPHEADER => ['Accept: application/rdap+json, application/json', 'User-Agent: Kaleta/' . KALETA_VERSION],
            CURLOPT_HEADERFUNCTION => function ($ch, string $header) use (&$location): int {
                if (stripos($header, 'Location:') === 0) {
                    $location = trim(substr($header, 9));
                }

                return strlen($header);
            },
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch); // no curl_close(): a no-op since PHP 8.0 and deprecated in 8.5
        if (!is_string($body)) {
            throw new \RuntimeException($error !== '' ? $error : 'connection failed');
        }
        if (strlen($body) > self::MAX_BYTES) {
            throw new \RuntimeException('the response is unexpectedly large');
        }

        return [$status, $location, $body];
    }

    /** @return array{0: int, 1: string, 2: string} */
    private static function streamRequest(string $url): array
    {
        $context = stream_context_create(['http' => ['method' => 'GET', 'timeout' => self::TIMEOUT, 'follow_location' => 0, 'ignore_errors' => true,
            'header' => "Accept: application/rdap+json, application/json\r\nUser-Agent: Kaleta/" . KALETA_VERSION . "\r\n"]]);
        $stream = @fopen($url, 'rb', false, $context);
        if ($stream === false) {
            throw new \RuntimeException('connection failed');
        }
        // the response headers come from the stream's metadata (the $http_response_header variable is deprecated since PHP 8.5)
        $headers = stream_get_meta_data($stream)['wrapper_data'] ?? [];
        $body = (string) stream_get_contents($stream, self::MAX_BYTES + 1);
        fclose($stream);
        if (strlen($body) > self::MAX_BYTES) {
            throw new \RuntimeException('the response is unexpectedly large');
        }
        $status = 0;
        $location = '';
        foreach (is_array($headers) ? $headers : [] as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m)) {
                $status = (int) $m[1];
                $location = '';
            } elseif (stripos($header, 'Location:') === 0) {
                $location = trim(substr($header, 9));
            }
        }

        return [$status, $location, $body];
    }

    /** A TLS handshake with host:443 and the certificate's expiry; the chain is not verified – an expired certificate must be readable too. */
    private static function certificateExpiry(string $host): int
    {
        if (!function_exists('openssl_x509_parse')) {
            throw new \RuntimeException('the openssl extension is missing on the server');
        }
        $context = stream_context_create(['ssl' => ['capture_peer_cert' => true, 'verify_peer' => false, 'verify_peer_name' => false, 'SNI_enabled' => true, 'peer_name' => $host]]);
        $socket = @stream_socket_client('ssl://' . $host . ':443', $number, $error, self::TIMEOUT, STREAM_CLIENT_CONNECT, $context);
        if ($socket === false) {
            throw new \RuntimeException(is_string($error) && $error !== '' ? $error : 'connection failed');
        }
        $params = stream_context_get_params($socket);
        fclose($socket);
        $certificate = $params['options']['ssl']['peer_certificate'] ?? null;
        $parsed = $certificate !== null ? openssl_x509_parse($certificate) : false;
        if (!is_array($parsed) || !isset($parsed['validTo_time_t'])) {
            throw new \RuntimeException('the certificate could not be read');
        }

        return (int) $parsed['validTo_time_t'];
    }

    /** A host name as DNS wants it: lowercase, IDN in Punycode (when the intl extension is there). */
    private static function ascii(string $host): string
    {
        $host = strtolower(trim($host, '. '));
        if ($host !== '' && function_exists('idn_to_ascii') && preg_match('/[^\x00-\x7F]/', $host)) {
            $host = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: $host;
        }

        return $host;
    }
}
