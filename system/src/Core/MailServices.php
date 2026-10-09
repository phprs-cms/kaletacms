<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Mail services with an SMTP relay (3.9): Settings → Mail → "Send through a mail service" fills the server, the port and
 * the encryption, and says what goes into the user name and the password – the service's SMTP credentials, never its API
 * key unless the service itself says so (SendGrid, Mailjet, Mailchimp Transactional, Postmark).
 *
 * Nothing here changes a working configuration: the provider is derived from the saved SMTP server (detect()), and the
 * remembered choice (setting smtp_provider) only says which option the form shows. Saving the form with a provider fills
 * the server, port and encryption only when the saved server is not that provider's (settle()) – a Brevo server on port
 * 2525 stays on 2525. "Other server" keeps the free form exactly as before.
 *
 * The newsletter integration (Core\Newsletter, setting newsletter_service) and the SMTP relay are separate credentials:
 * when both are the same account the Mail tab and Features say so and link to each other, but no key is ever copied
 * from one to the other – the owner pastes the SMTP key. Secrets are never shown or settable over MCP; MCP only learns
 * the provider's name (site_info, get_health).
 *
 * Sources (checked 9 Oct 2026; hosts and credentials as each service documents them):
 *  - Brevo: smtp-relay.brevo.com, 587 STARTTLS, SMTP login + SMTP key (the relay refuses API keys) –
 *    https://developers.brevo.com/docs/supabase-smtp-integration ; domain authentication (brevo-code TXT, DKIM brevo1/brevo2,
 *    no SPF include on shared IPs): Brevo help, "Authenticate your domain" (Senders, Domains & Dedicated IPs → Domains)
 *  - Mailgun: smtp.mailgun.org (US) and smtp.eu.mailgun.org (EU – the region the domain was created in), 587 STARTTLS,
 *    per-domain SMTP credentials – https://documentation.mailgun.com/docs/mailgun/user-manual/sending-messages/send-smtp ,
 *    https://documentation.mailgun.com/docs/mailgun/api-reference/api-overview (EU endpoints)
 *  - Amazon SES: email-smtp.<region>.amazonaws.com, 587 STARTTLS (465 TLS wrapper), SMTP credentials made in the SES
 *    console (not the AWS access keys) – https://docs.aws.amazon.com/ses/latest/dg/smtp-connect.html ,
 *    https://docs.aws.amazon.com/ses/latest/dg/smtp-credentials.html , https://docs.aws.amazon.com/general/latest/gr/ses.html
 *  - Postmark: smtp.postmarkapp.com (smtp-broadcasts.postmarkapp.com for broadcast streams), 587 STARTTLS, an SMTP token
 *    (Access Key / Secret Key) or the Server API token as both – https://postmarkapp.com/developer/user-guide/send-email-with-smtp
 *  - SendGrid: smtp.sendgrid.net, 587 STARTTLS, user "apikey", password = an API key with Mail Send –
 *    https://www.twilio.com/docs/sendgrid/for-developers/sending-email/integrating-with-the-smtp-api
 *  - MailerSend: smtp.mailersend.net, 587 STARTTLS, an SMTP user generated on the domain page – https://www.mailersend.com/help/smtp-relay
 *  - SMTP2GO: mail.smtp2go.com, 587 or 2525 STARTTLS (465 SSL), an SMTP user from Sending → SMTP Users –
 *    https://developers.smtp2go.com/docs/smtp-relay
 *  - Mailjet: in-v3.mailjet.com, 587 STARTTLS, user = API key, password = secret key –
 *    https://documentation.mailjet.com/hc/en-us/articles/360043229473 (also
 *    https://www.odoo.com/documentation/18.0/applications/general/email_communication/mailjet_api.html)
 *  - Mailchimp Transactional (Mandrill): smtp.mandrillapp.com, 587 STARTTLS, password = a Mandrill API key, the user name is
 *    not checked – https://mailchimp.com/developer/transactional/docs/smtp-integration/
 *  - Ecomail and SmartEmailing send transactional e-mail only through their APIs, there is no SMTP relay to choose –
 *    https://support.ecomail.cz/cs/articles/6197248-transakcni-e-maily , https://napoveda.smartemailing.cz/article/976-transakcni-e-maily
 */
final class MailServices
{
    /** The setting with the remembered choice: '' = not chosen yet (derived from the server), 'other' or a key of PROVIDERS. */
    public const string SETTING = 'smtp_provider';

    /** The option for any other SMTP server – the free form. */
    public const string OTHER = 'other';

    /** The values of the form's choice for the vyber: field type – "other" and every key of PROVIDERS (a unit test keeps them in step). */
    public const string CHOICES = 'other|brevo|mailgun|mailgun_eu|ses|postmark|sendgrid|mailersend|smtp2go|mailjet|mandrill';

    /** The {region} placeholder in Amazon SES's host. */
    private const string REGION = '{region}';

    /** The region Amazon SES gets when none is chosen – Frankfurt, close to most Kaleta sites. */
    public const string DEFAULT_REGION = 'eu-central-1';

    /** Amazon SES regions with an SMTP endpoint offered in the form (any other region in a saved host is recognised too). */
    public const array SES_REGIONS = ['eu-central-1' => 'EU (Frankfurt)', 'eu-west-1' => 'EU (Ireland)', 'eu-west-2' => 'EU (London)', 'eu-west-3' => 'EU (Paris)',
        'eu-north-1' => 'EU (Stockholm)', 'eu-south-1' => 'EU (Milan)', 'eu-central-2' => 'EU (Zurich)', 'us-east-1' => 'US East (N. Virginia)', 'us-east-2' => 'US East (Ohio)',
        'us-west-2' => 'US West (Oregon)', 'ca-central-1' => 'Canada (Central)', 'ap-southeast-2' => 'Asia Pacific (Sydney)', 'ap-northeast-1' => 'Asia Pacific (Tokyo)'];

    /**
     * The providers. host: the SMTP server (Amazon SES with {region}); hosts: other servers of the same service that count
     * as it; port and encryption (tls = STARTTLS, ssl = TLS from the start, as smtp_encryption); user and password: what to
     * paste (English source, translated by t()); dns: what to add to DNS; spf: the include for the SPF record, '' when the
     * service does not need one in the domain's own record; dkim: DKIM selectors the domain check also tries;
     * newsletter: the newsletter_service of the same account (Core\Newsletter::SERVICES), '' = none.
     *
     * @var array<string, array{name: string, host: string, hosts: list<string>, port: int, encryption: string, user: string, password: string, dns: string, spf: string, dkim: list<string>, newsletter: string, docs: string}>
     */
    public const array PROVIDERS = [
        'brevo' => ['name' => 'Brevo', 'host' => 'smtp-relay.brevo.com', 'hosts' => ['smtp-relay.sendinblue.com'], 'port' => 587, 'encryption' => 'tls',
            'user' => 'The SMTP login shown in Brevo under SMTP & API → SMTP (it ends in @smtp-brevo.com).',
            'password' => 'An SMTP key generated on the same page – not an API key: the Brevo relay refuses API keys.',
            'dns' => 'In Brevo → Senders, Domains → Domains, authenticate the sending domain: add the brevo-code TXT record and the two DKIM records (brevo1._domainkey and brevo2._domainkey) Brevo shows. With shared sending IPs no SPF include is needed.',
            'spf' => '', 'dkim' => ['brevo1', 'brevo2', 'mail'], 'newsletter' => 'brevo', 'docs' => 'https://developers.brevo.com/docs/supabase-smtp-integration'],
        'mailgun' => ['name' => 'Mailgun (US)', 'host' => 'smtp.mailgun.org', 'hosts' => [], 'port' => 587, 'encryption' => 'tls',
            'user' => 'The SMTP login of your sending domain from Mailgun → Sending → Domain settings → SMTP credentials, for example postmaster@mg.example.com.',
            'password' => 'The password of that SMTP login – not the Mailgun API key.',
            'dns' => 'Mailgun → Sending → Domains → DNS records lists the SPF (include:mailgun.org) and DKIM TXT records for the sending domain – add both.',
            'spf' => 'mailgun.org', 'dkim' => ['smtp', 'mx', 'pic', 'krs', 'k1'], 'newsletter' => '', 'docs' => 'https://documentation.mailgun.com/docs/mailgun/user-manual/sending-messages/send-smtp'],
        'mailgun_eu' => ['name' => 'Mailgun (EU)', 'host' => 'smtp.eu.mailgun.org', 'hosts' => [], 'port' => 587, 'encryption' => 'tls',
            'user' => 'The SMTP login of your sending domain from Mailgun → Sending → Domain settings → SMTP credentials, for example postmaster@mg.example.com.',
            'password' => 'The password of that SMTP login – not the Mailgun API key.',
            'dns' => 'Mailgun → Sending → Domains → DNS records lists the SPF (include:mailgun.org) and DKIM TXT records for the sending domain – add both.',
            'spf' => 'mailgun.org', 'dkim' => ['smtp', 'mx', 'pic', 'krs', 'k1'], 'newsletter' => '', 'docs' => 'https://documentation.mailgun.com/docs/mailgun/user-manual/sending-messages/send-smtp'],
        'ses' => ['name' => 'Amazon SES', 'host' => 'email-smtp.{region}.amazonaws.com', 'hosts' => [], 'port' => 587, 'encryption' => 'tls',
            'user' => 'The SMTP user name from Amazon SES → SMTP settings → Create SMTP credentials – not the AWS access key ID.',
            'password' => 'The SMTP password shown once with it – not the AWS secret access key.',
            'dns' => 'Verify the sending domain in Amazon SES with Easy DKIM: add the three CNAME records (…._domainkey) SES shows. include:amazonses.com in SPF is needed only with a custom MAIL FROM domain.',
            'spf' => '', 'dkim' => [], 'newsletter' => '', 'docs' => 'https://docs.aws.amazon.com/ses/latest/dg/smtp-connect.html'],
        'postmark' => ['name' => 'Postmark', 'host' => 'smtp.postmarkapp.com', 'hosts' => ['smtp-broadcasts.postmarkapp.com'], 'port' => 587, 'encryption' => 'tls',
            'user' => 'The Access Key of an SMTP token (Postmark → your server → the message stream → Settings), or the Server API token. For newsletters use a broadcast stream and the server smtp-broadcasts.postmarkapp.com.',
            'password' => 'The Secret Key of that SMTP token – or the same Server API token again, which Postmark accepts as both.',
            'dns' => 'Postmark → Sender signatures → DNS settings: add the DKIM TXT record (…pm._domainkey) and the Return-Path CNAME pointing to pm.mtasv.net. Postmark needs no SPF include.',
            'spf' => '', 'dkim' => [], 'newsletter' => '', 'docs' => 'https://postmarkapp.com/developer/user-guide/send-email-with-smtp'],
        'sendgrid' => ['name' => 'SendGrid', 'host' => 'smtp.sendgrid.net', 'hosts' => [], 'port' => 587, 'encryption' => 'tls',
            'user' => 'Literally apikey – the same for every account.',
            'password' => 'A SendGrid API key with the Mail Send permission: SendGrid uses the API key as the SMTP password.',
            'dns' => 'SendGrid → Settings → Sender authentication → Authenticate your domain: add the CNAME records it shows (em…, s1._domainkey and s2._domainkey); they cover SPF and DKIM.',
            'spf' => '', 'dkim' => ['s1', 's2'], 'newsletter' => '', 'docs' => 'https://www.twilio.com/docs/sendgrid/for-developers/sending-email/integrating-with-the-smtp-api'],
        'mailersend' => ['name' => 'MailerSend', 'host' => 'smtp.mailersend.net', 'hosts' => [], 'port' => 587, 'encryption' => 'tls',
            'user' => 'The SMTP user MailerSend generates on the domain page (Domains → your domain → SMTP).',
            'password' => 'The password of that SMTP user – shown only once; not an API token.',
            'dns' => 'MailerSend → Domains → your domain: add the SPF (include:_spf.mailersend.net), DKIM and Return-Path records it shows.',
            'spf' => '_spf.mailersend.net', 'dkim' => [], 'newsletter' => '', 'docs' => 'https://www.mailersend.com/help/smtp-relay'],
        'smtp2go' => ['name' => 'SMTP2GO', 'host' => 'mail.smtp2go.com', 'hosts' => ['mail-eu.smtp2go.com', 'mail-us.smtp2go.com', 'mail-au.smtp2go.com'], 'port' => 587, 'encryption' => 'tls',
            'user' => 'An SMTP user created in SMTP2GO → Sending → SMTP Users – not your dashboard login. If port 587 is blocked, use 2525.',
            'password' => 'The password of that SMTP user.',
            'dns' => 'SMTP2GO → Sending → Verified senders: add the sending domain and the CNAME records it shows (return path, DKIM and link tracking); they cover SPF.',
            'spf' => '', 'dkim' => [], 'newsletter' => '', 'docs' => 'https://developers.smtp2go.com/docs/smtp-relay'],
        'mailjet' => ['name' => 'Mailjet', 'host' => 'in-v3.mailjet.com', 'hosts' => ['in.mailjet.com'], 'port' => 587, 'encryption' => 'tls',
            'user' => 'Your Mailjet API key (Account settings → SMTP and Send API settings) – Mailjet uses it as the SMTP user name.',
            'password' => 'The secret key belonging to that API key.',
            'dns' => 'Mailjet → Account settings → Domains: add include:spf.mailjet.com to the SPF record and the DKIM TXT record (mailjet._domainkey) Mailjet shows.',
            'spf' => 'spf.mailjet.com', 'dkim' => ['mailjet'], 'newsletter' => '', 'docs' => 'https://documentation.mailjet.com/hc/en-us/articles/360043229473'],
        'mandrill' => ['name' => 'Mailchimp Transactional (Mandrill)', 'host' => 'smtp.mandrillapp.com', 'hosts' => [], 'port' => 587, 'encryption' => 'tls',
            'user' => 'Any name – Mailchimp does not check it and recommends your account’s primary contact e-mail.',
            'password' => 'A Mailchimp Transactional (Mandrill) API key from Settings → SMTP & API Info – a Mailchimp marketing API key does not work.',
            'dns' => 'Mailchimp Transactional → Domains → Sending domains: verify the domain and add the DKIM and SPF records it shows (include:spf.mandrillapp.com).',
            'spf' => 'spf.mandrillapp.com', 'dkim' => ['mandrill', 'mte1', 'mte2'], 'newsletter' => 'mailchimp', 'docs' => 'https://mailchimp.com/developer/transactional/docs/smtp-integration/'],
    ];

    /**
     * Newsletter services (Core\Newsletter::SERVICES) without an SMTP relay of the same account: what the Mail tab says
     * about them. MailerLite's transactional service is MailerSend, a separate account.
     */
    public const array NEWSLETTER_WITHOUT_SMTP = [
        'ecomail' => 'Ecomail sends transactional e-mail only through its API, not over SMTP – keep it for the newsletter and choose another service or your mailbox for the site’s mail.',
        'smartemailing' => 'SmartEmailing sends transactional e-mail only through its API, not over SMTP – keep it for the newsletter and choose another service or your mailbox for the site’s mail.',
        'mailerlite' => 'MailerLite has no SMTP relay of its own; its transactional service is MailerSend, a separate account with its own SMTP user.',
    ];

    /** The provider whose SMTP server this is, null for any other server (or none). Case and a trailing dot do not matter. */
    public static function detect(string $host): ?string
    {
        $host = strtolower(trim($host, ". \t"));
        if ($host === '') {
            return null;
        }
        foreach (self::PROVIDERS as $key => $provider) {
            if (self::matches($key, $host)) {
                return $key;
            }
        }

        return null;
    }

    /** Is the host the given provider's SMTP server? */
    public static function matches(string $key, string $host): bool
    {
        $provider = self::PROVIDERS[$key] ?? null;
        if ($provider === null) {
            return false;
        }
        $host = strtolower(trim($host, ". \t"));
        if (str_contains($provider['host'], self::REGION)) {
            return preg_match('/^' . str_replace(preg_quote(self::REGION, '/'), '[a-z]{2}(-gov)?-[a-z]+-\d', preg_quote($provider['host'], '/')) . '$/D', $host) === 1;
        }

        return $host === $provider['host'] || in_array($host, $provider['hosts'], true);
    }

    /** The provider's SMTP server; Amazon SES in the given region (an unknown region gives the default). */
    public static function host(string $key, string $region = ''): string
    {
        $host = self::PROVIDERS[$key]['host'] ?? '';

        return str_replace(self::REGION, preg_match('/^[a-z]{2}(-gov)?-[a-z]+-\d$/D', $region) === 1 ? $region : self::DEFAULT_REGION, $host);
    }

    /** The Amazon SES region of a saved host, '' when it is not an SES server. */
    public static function region(string $host): string
    {
        return preg_match('/^email-smtp\.([a-z]{2}(?:-gov)?-[a-z]+-\d)\.amazonaws\.com$/D', strtolower(trim($host, ". \t")), $m) === 1 ? $m[1] : '';
    }

    /**
     * The option the form shows: the remembered provider while the server is still its own (or empty), otherwise the
     * provider the server belongs to, otherwise "other". Derived only – nothing is saved.
     */
    public static function choice(Settings $s): string
    {
        $remembered = $s->get(self::SETTING);
        $host = $s->get('smtp_host');
        if (isset(self::PROVIDERS[$remembered]) && ($host === '' || self::matches($remembered, $host))) {
            return $remembered;
        }

        return self::detect($host) ?? self::OTHER;
    }

    /** The provider the site sends through right now: SMTP mode with a provider's server, otherwise null. */
    public static function current(Settings $s): ?string
    {
        return $s->get('mail_mode') === 'smtp' ? self::detect($s->get('smtp_host')) : null;
    }

    /** The provider's name for System status, MCP and the privacy record ('' = none). */
    public static function name(?string $key): string
    {
        return $key !== null ? (self::PROVIDERS[$key]['name'] ?? '') : '';
    }

    /**
     * After the Mail tab is saved with a provider chosen: when the saved server is not that provider's, the server, port
     * and encryption become the provider's (a form sent without JavaScript, or a switch from one service to another).
     * A server that already is the provider's stays exactly as it is – its port and encryption included. "Other" changes
     * nothing. The user name and the password are never touched.
     *
     * @return bool whether anything was changed
     */
    public static function settle(Settings $s, string $region = ''): bool
    {
        $server = self::server($s->get(self::SETTING), $s->get('smtp_host'), $region);
        if ($server === null) {
            return false;
        }
        $s->set('smtp_host', $server['host']);
        $s->set('smtp_port', (string) $server['port']);
        $s->set('smtp_encryption', $server['encryption']);

        return true;
    }

    /**
     * The server settle() writes for a choice and the saved host: null = keep what is saved ("other", an unknown choice,
     * or a host that already is the provider's – for Amazon SES in the chosen region, when one is given).
     *
     * @return array{host: string, port: int, encryption: string}|null
     */
    public static function server(string $key, string $host, string $region = ''): ?array
    {
        if (!isset(self::PROVIDERS[$key])) {
            return null;
        }
        $kept = $host !== '' && self::matches($key, $host)
            && ($key !== 'ses' || $region === '' || $region === self::region($host)); // another SES region chosen without JavaScript: a new endpoint

        return $kept ? null : ['host' => self::host($key, $region), 'port' => self::PROVIDERS[$key]['port'], 'encryption' => self::PROVIDERS[$key]['encryption']];
    }

    /**
     * What the Mail tab and Features say about the newsletter integration: its service's name, the provider of the same
     * account with an SMTP relay ('' = none) and, for a service without one, why (English source, '' = nothing to add).
     * null = no integration (or the webhook, which is no mail service).
     *
     * @return array{service: string, provider: string, note: string}|null
     */
    public static function newsletterLink(Settings $s): ?array
    {
        $service = $s->get('newsletter_service');
        if (!isset(Newsletter::SERVICES[$service]) || $service === 'webhook') {
            return null;
        }
        foreach (self::PROVIDERS as $key => $provider) {
            if ($provider['newsletter'] === $service) {
                return ['service' => Newsletter::SERVICES[$service][0], 'provider' => $key, 'note' => ''];
            }
        }

        return ['service' => Newsletter::SERVICES[$service][0], 'provider' => '', 'note' => self::NEWSLETTER_WITHOUT_SMTP[$service] ?? ''];
    }

    /**
     * DKIM selectors the domain check tries for the provider before the common ones (Core\DomainWatch).
     *
     * @return list<string>
     */
    public static function dkimSelectors(?string $key): array
    {
        return $key !== null ? (self::PROVIDERS[$key]['dkim'] ?? []) : [];
    }
}
