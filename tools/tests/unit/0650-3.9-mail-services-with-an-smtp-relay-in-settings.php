<?php

declare(strict_types=1);

/* ---------- 3.9: mail services with an SMTP relay in Settings → Mail (Core\MailServices) ---------- */
use Kaleta\Core\MailServices;
$mailCs = require KALETA_SYSTEM . '/jazyky/admin-cs.php';
$mailDe = require KALETA_SYSTEM . '/jazyky/admin-de.php';
$mailProblems = [];
foreach (MailServices::PROVIDERS as $key => $provider) {
    $host = MailServices::host($key);
    if (preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)+$/D', $host) !== 1 || !MailServices::matches($key, $host) || MailServices::detect($host) !== $key) { $mailProblems[] = $key . ': host ' . $host; }
    foreach ($provider['hosts'] as $other) {
        if (MailServices::detect($other) !== $key) { $mailProblems[] = $key . ': other host ' . $other; }
    }
    if ($provider['port'] < 1 || $provider['port'] > 65535 || !in_array($provider['encryption'], ['tls', 'ssl'], true)) { $mailProblems[] = $key . ': port or encryption'; }
    if (!str_starts_with($provider['docs'], 'https://') || ($provider['newsletter'] !== '' && !isset(Kaleta\Core\Newsletter::SERVICES[$provider['newsletter']]))) { $mailProblems[] = $key . ': docs or newsletter'; }
    if ($provider['spf'] !== '' && preg_match('/^[a-z0-9_.-]+\.[a-z]{2,}$/D', $provider['spf']) !== 1) { $mailProblems[] = $key . ': spf'; }
    foreach (['user', 'password', 'dns'] as $text) {
        if (!isset($mailCs[$provider[$text]], $mailDe[$provider[$text]])) { $mailProblems[] = $key . ': ' . $text . ' not translated'; }
    }
}
foreach (MailServices::NEWSLETTER_WITHOUT_SMTP as $service => $note) {
    if (!isset(Kaleta\Core\Newsletter::SERVICES[$service], $mailCs[$note], $mailDe[$note])) { $mailProblems[] = $service . ': note'; }
}
check('3.9 MailServices: every provider has a valid server it is detected by, a port and STARTTLS/SSL, an https guide, a known newsletter service and translated hints; the choices list them all',
    [$mailProblems, MailServices::CHOICES, Kaleta\Core\Settings::DEFAULTS[MailServices::SETTING], Kaleta\Admin\Modules\Settings::verifyValue(MailServices::SETTING, 'brevo'), Kaleta\Admin\Modules\Settings::verifyValue(MailServices::SETTING, 'evil')],
    [[], MailServices::OTHER . '|' . implode('|', array_keys(MailServices::PROVIDERS)), '', 'brevo', null]);
check('3.9 MailServices::detect – by the server, any case and a trailing dot; Mailgun by region, SES in any region; a look-alike or another server is no provider',
    array_map(MailServices::detect(...), ['smtp-relay.brevo.com', 'SMTP-Relay.Brevo.com.', 'smtp-relay.sendinblue.com', 'smtp.mailgun.org', 'smtp.eu.mailgun.org', 'email-smtp.eu-west-1.amazonaws.com',
        'email-smtp.us-gov-west-1.amazonaws.com', 'smtp-broadcasts.postmarkapp.com', 'smtp.sendgrid.net', 'smtp.mailersend.net', 'mail.smtp2go.com', 'mail-eu.smtp2go.com', 'in-v3.mailjet.com', 'smtp.mandrillapp.com',
        'smtp.gmail.com', 'smtp.seznam.cz', '', 'evil-smtp-relay.brevo.com', 'smtp-relay.brevo.com.evil.cz', 'email-smtp.eu-west-1.amazonaws.com.evil.cz', 'email-smtp.amazonaws.com']),
    ['brevo', 'brevo', 'brevo', 'mailgun', 'mailgun_eu', 'ses', 'ses', 'postmark', 'sendgrid', 'mailersend', 'smtp2go', 'smtp2go', 'mailjet', 'mandrill', null, null, null, null, null, null, null]);
check('3.9 MailServices: the Amazon SES server by region (an invalid region gives Frankfurt) and the region of a saved server',
    [MailServices::host('ses', 'eu-west-1'), MailServices::host('ses', 'evil.cz/x'), MailServices::host('ses'), MailServices::host('brevo', 'eu-west-1'), MailServices::region('email-smtp.us-east-2.amazonaws.com'), MailServices::region('smtp.gmail.com')],
    ['email-smtp.eu-west-1.amazonaws.com', 'email-smtp.eu-central-1.amazonaws.com', 'email-smtp.eu-central-1.amazonaws.com', 'smtp-relay.brevo.com', 'us-east-2', '']);
// what the save does after the Mail tab: a provider without its server gets it, a server that already is the provider's is kept
// (a Brevo server on port 2525 stays on 2525 – settle() writes nothing), "other" never changes a working configuration
check('3.9 MailServices::server – the provider fills an empty or another service\'s server, keeps its own (and the SES region), other keeps everything',
    [MailServices::server('postmark', ''), MailServices::server('brevo', 'smtp.sendgrid.net'), MailServices::server('brevo', 'smtp-relay.brevo.com'), MailServices::server('brevo', 'SMTP-RELAY.BREVO.COM'),
        MailServices::server('ses', 'email-smtp.eu-west-1.amazonaws.com', 'eu-west-1'), MailServices::server('ses', 'email-smtp.eu-west-1.amazonaws.com', 'us-east-1'), MailServices::server('ses', '', 'eu-north-1'),
        MailServices::server('other', 'smtp.example.cz'), MailServices::server('other', ''), MailServices::server('', 'smtp.example.cz'), MailServices::server('nonsense', '')],
    [['host' => 'smtp.postmarkapp.com', 'port' => 587, 'encryption' => 'tls'], ['host' => 'smtp-relay.brevo.com', 'port' => 587, 'encryption' => 'tls'], null, null,
        null, ['host' => 'email-smtp.us-east-1.amazonaws.com', 'port' => 587, 'encryption' => 'tls'], ['host' => 'email-smtp.eu-north-1.amazonaws.com', 'port' => 587, 'encryption' => 'tls'],
        null, null, null, null]);
// the option the form shows and the provider the site sends through: derived, never saved; a remembered choice that no longer fits the server gives way to the server
check('3.9 MailServices::choice and ::current – derived from the saved server; an existing custom server is "other"; mail() has no provider',
    [MailServices::choice($reportSettings(['mail_mode' => 'smtp', 'smtp_host' => 'smtp-relay.brevo.com'])), MailServices::choice($reportSettings(['mail_mode' => 'smtp', 'smtp_host' => 'smtp.firma.cz'])),
        MailServices::choice($reportSettings(['smtp_provider' => 'postmark', 'smtp_host' => ''])), MailServices::choice($reportSettings(['smtp_provider' => 'postmark', 'smtp_host' => 'smtp.sendgrid.net'])),
        MailServices::choice($reportSettings(['smtp_provider' => 'other', 'smtp_host' => 'smtp.firma.cz'])), MailServices::choice($reportSettings([])),
        MailServices::current($reportSettings(['mail_mode' => 'smtp', 'smtp_host' => 'smtp.eu.mailgun.org'])), MailServices::current($reportSettings(['mail_mode' => 'mail', 'smtp_host' => 'smtp.eu.mailgun.org'])),
        MailServices::current($reportSettings(['mail_mode' => 'smtp', 'smtp_host' => 'smtp.firma.cz'])), MailServices::name('mandrill'), MailServices::name(null)],
    ['brevo', 'other', 'postmark', 'sendgrid', 'other', 'other', 'mailgun_eu', null, null, 'Mailchimp Transactional (Mandrill)', '']);
check('3.9 MailServices::newsletterLink – the same account (Brevo, Mailchimp → Mandrill), a service without SMTP says why, none and the webhook say nothing',
    [MailServices::newsletterLink($reportSettings(['newsletter_service' => 'brevo'])), MailServices::newsletterLink($reportSettings(['newsletter_service' => 'mailchimp']))['provider'] ?? null,
        MailServices::newsletterLink($reportSettings(['newsletter_service' => 'ecomail']))['note'] ?? null, MailServices::newsletterLink($reportSettings(['newsletter_service' => ''])),
        MailServices::newsletterLink($reportSettings(['newsletter_service' => 'webhook']))],
    [['service' => 'Brevo', 'provider' => 'brevo', 'note' => ''], 'mandrill', MailServices::NEWSLETTER_WITHOUT_SMTP['ecomail'], null, null]);
// the domain check knows the service: Brevo's own DKIM selector is found, and an SPF record without Brevo's include is fine (Brevo needs none)
$watchBrevoDns = fn (string $name, int $type): array => match ($name) {
    'example.cz' => [['type' => 'TXT', 'txt' => 'v=spf1 a mx ~all']],
    'brevo1._domainkey.example.cz' => [['type' => 'TXT', 'txt' => 'k=rsa; p=MIGfMA0G']],
    default => [],
};
$watchBrevo = (new $watchClass($watchBrevoDns, $watchHttp, fn (string $host): int => $watchNow + 90 * 86400))->collect(['smtp_host' => 'smtp-relay.brevo.com'] + $watchSite, $watchNow);
$watchMailjet = (new $watchClass(fn (string $name, int $type): array => $name === 'example.cz' ? [['type' => 'TXT', 'txt' => 'v=spf1 a mx ~all']] : [], $watchHttp, fn (string $host): int => $watchNow + 90 * 86400))
    ->collect(['smtp_host' => 'in-v3.mailjet.com'] + $watchSite, $watchNow);
$watchMailjetRows = array_column($watchClass::rows($watchMailjet, false, $watchNow), null, 'nazev');
check('3.9 DomainWatch with a mail service: its DKIM selector is tried, SPF without an include is fine for Brevo but a warning for Mailjet, and the DKIM row says what to add',
    [$watchBrevo['mail']['dkim'], $watchBrevo['mail']['spf_covers_smtp'], array_column($watchClass::rows($watchBrevo, false, $watchNow), 'stav', 'nazev')[t('SPF record')],
        $watchMailjetRows[t('SPF record')]['stav'], str_contains($watchMailjetRows[t('SPF record')]['info'], 'include:spf.mailjet.com'), str_contains($watchMailjetRows[t('DKIM signature')]['info'], 'mailjet._domainkey')],
    ['brevo1', false, 'ok', 'varovani', true, true]);
