<?php

declare(strict_types=1);

/* ---------- 2.8: domain and mail watch (Core\DomainWatch) – no network, the lookups are fixtures ---------- */
$watchClass = Kaleta\Core\DomainWatch::class;
check('DomainWatch: registrable domain – www., subdomains and two-level suffixes', array_map($watchClass::registrableDomain(...), ['www.example.cz', 'shop.firma.example.co.uk', 'Example.COM', 'www.example.com.au', 'example.cz.']), ['example.cz', 'example.co.uk', 'example.com', 'example.com.au', 'example.cz']);
check('DomainWatch: a public host is not an IP, localhost or a development suffix', array_map($watchClass::isPublicHost(...), ['www.example.cz', '127.0.0.1', 'localhost', 'web.test', 'kaleta.localhost', '::1', '']), [true, false, false, false, false, false, false]);
check('DomainWatch: SPF covers the SMTP server – include of a known provider, a/mx in the own domain, redirect', [
    $watchClass::spfCovers('v=spf1 include:_spf.google.com ~all', 'example.cz', 'smtp.gmail.com'),
    $watchClass::spfCovers('v=spf1 include:_spf.google.com ~all', 'example.cz', 'smtp.seznam.cz'),
    $watchClass::spfCovers('v=spf1 a mx -all', 'example.cz', 'mail.example.cz'),
    $watchClass::spfCovers('v=spf1 a mx -all', 'example.cz', 'smtp.seznam.cz'),
    $watchClass::spfCovers('v=spf1 redirect=_spf.seznam.cz', 'example.cz', 'smtp.seznam.cz'),
    $watchClass::spfCovers('v=spf1 ip4:93.184.216.34 -all', 'example.cz', 'smtp.seznam.cz'),
    $watchClass::spfCovers('google-site-verification=abc', 'example.cz', 'smtp.seznam.cz'),
], [true, false, true, false, true, null, null]);
check('DomainWatch: suggested SPF – provider include, own server, mail() from the web server', [
    $watchClass::suggestedSpf('example.cz', 'smtp.gmail.com', 'www.example.cz'), $watchClass::suggestedSpf('example.cz', 'mail.example.cz', 'www.example.cz'),
    $watchClass::suggestedSpf('example.cz', 'smtp.relay-xyz.io', 'www.example.cz'), $watchClass::suggestedSpf('example.cz', '', 'www.example.cz'), $watchClass::suggestedSpf('example.cz', '', 'web12.hosting.net'),
], ['v=spf1 mx include:_spf.google.com ~all', 'v=spf1 a mx ~all', 'v=spf1 mx include:relay-xyz.io ~all', 'v=spf1 a mx ~all', 'v=spf1 mx a:hosting.net ~all']);
check('DomainWatch: suggested DMARC and parsing', [$watchClass::suggestedDmarc('info@example.cz'), $watchClass::suggestedDmarc(''), $watchClass::parseDmarc('v=DMARC1; p=quarantine; rua=mailto:dmarc@example.cz; pct=100')],
    ['v=DMARC1; p=none; rua=mailto:info@example.cz', 'v=DMARC1; p=none', ['p' => 'quarantine', 'rua' => 'mailto:dmarc@example.cz']]);
$rdap = '{"objectClassName":"domain","ldhName":"example.cz","events":[{"eventAction":"registration","eventDate":"2010-05-01T10:00:00Z"},{"eventAction":"expiration","eventDate":"2027-03-14T10:00:00Z"}]}';
check('DomainWatch: RDAP expiry – the expiration event, none, invalid JSON', [$watchClass::rdapExpiry($rdap), $watchClass::rdapExpiry('{"events":[{"eventAction":"registration","eventDate":"2010-05-01T10:00:00Z"}]}'), $watchClass::rdapExpiry('nonsense')],
    [gmmktime(10, 0, 0, 3, 14, 2027), null, null]);
check('DomainWatch: thresholds – 21 days warning, 7 days error, unknown ok', array_map($watchClass::level(...), [60, 21, 20, 7, 6, 0, -3, null]), ['ok', 'ok', 'varovani', 'varovani', 'chyba', 'chyba', 'chyba', 'ok']);
$watchNow = 1_800_000_000;
$watchDns = fn (string $name, int $type): array => match ($name) {
    'example.cz' => [['type' => 'TXT', 'txt' => 'google-site-verification=abc'], ['type' => 'TXT', 'txt' => 'v=spf1 include:_spf.google.com ~all']],
    '_dmarc.example.cz' => [['type' => 'TXT', 'txt' => 'v=DMARC1; p=none; rua=mailto:dmarc@example.cz']],
    'google._domainkey.example.cz' => [['type' => 'TXT', 'txt' => 'v=DKIM1; k=rsa; p=MIGfMA0G']],
    default => [],
};
$watchHttp = fn (string $url): array => $url === 'https://rdap.org/domain/example.cz' ? [200, json_encode(['events' => [['eventAction' => 'expiration', 'eventDate' => gmdate('c', $watchNow + 100 * 86400)]]])] : [404, ''];
$watchSite = ['site_host' => 'www.example.cz', 'https' => true, 'mail_domain' => 'example.cz', 'smtp_host' => 'smtp.gmail.com', 'report_email' => 'info@example.cz'];
$watchResult = (new $watchClass($watchDns, $watchHttp, fn (string $host): int => $watchNow + 15 * 86400))->collect($watchSite, $watchNow);
check('DomainWatch: SPF, DMARC and DKIM found, SPF includes the SMTP server, days left', [$watchResult['mail']['spf'], $watchResult['mail']['spf_covers_smtp'], $watchResult['mail']['dmarc'] !== null, $watchResult['mail']['dkim'], $watchResult['tls']['days'], $watchResult['domain']['days'], $watchResult['domain']['name']],
    ['v=spf1 include:_spf.google.com ~all', true, true, 'google', 15, 100, 'example.cz']);
check('DomainWatch: rows – a certificate under 21 days is a warning, the rest ok', array_column($watchClass::rows($watchResult, false, $watchNow), 'stav', 'nazev'),
    [t('SPF record') => 'ok', t('DMARC record') => 'ok', t('DKIM signature') => 'ok', t('Certificate') => 'varovani', t('Domain registration') => 'ok']);
check('DomainWatch: handover lists only the certificate here', array_column($watchClass::handoverFindings($watchResult), 'key'), ['certificate']);
$watchBare = (new $watchClass(fn (): array => [], fn (): array => [404, ''], fn (): int => throw new RuntimeException('refused')))->collect(['site_host' => 'www.example.cz', 'https' => true, 'mail_domain' => 'example.cz', 'smtp_host' => ''], $watchNow);
check('DomainWatch: nothing in DNS – SPF and DMARC missing, DKIM not found, registry without RDAP is unknown, certificate error', [$watchBare['mail']['spf'], $watchBare['mail']['dmarc'], $watchBare['mail']['dkim'], $watchBare['domain']['expires'], $watchBare['domain']['error'], $watchBare['tls']['error']], [null, null, null, null, null, 'refused']);
check('DomainWatch: rows suggest the records to add; DKIM not found is a note, not a warning', [
    str_contains($watchClass::rows($watchBare, false, $watchNow)[0]['info'], 'v=spf1 a mx ~all'), str_contains($watchClass::rows($watchBare, false, $watchNow)[1]['info'], '_dmarc.example.cz'),
    array_column($watchClass::rows($watchBare, false, $watchNow), 'stav'),
], [true, true, ['varovani', 'varovani', 'ok', 'varovani', 'ok']]);
check('DomainWatch: handover lists the certain problems only (not DKIM, not an unknown registry)', array_column($watchClass::handoverFindings($watchBare), 'key'), ['spf', 'dmarc']);
$watchExpired = (new $watchClass($watchDns, fn (string $url): array => [200, json_encode(['events' => [['eventAction' => 'expiration', 'eventDate' => gmdate('c', $watchNow - 86400)]]])], fn (string $host): int => $watchNow - 3 * 86400))->collect($watchSite, $watchNow);
check('DomainWatch: an expired certificate and domain are errors and handover findings', [array_column($watchClass::rows($watchExpired, false, $watchNow), 'stav', 'nazev')[t('Certificate')], array_column($watchClass::rows($watchExpired, false, $watchNow), 'stav', 'nazev')[t('Domain registration')], array_column($watchClass::handoverFindings($watchExpired), 'key')],
    ['chyba', 'chyba', ['certificate', 'domain']]);
$watchFailed = (new $watchClass(fn (): false => false, $watchHttp, fn (string $host): int => $watchNow + 90 * 86400))->collect($watchSite, $watchNow);
check('DomainWatch: a failed DNS lookup is reported, never judged', [$watchFailed['mail']['error'], array_column($watchClass::rows($watchFailed, false, $watchNow), 'stav')[0], $watchClass::handoverFindings($watchFailed)], ['dns', 'varovani', []]);
$watchLocal = (new $watchClass(fn (): array => throw new RuntimeException('no network'), fn (): array => throw new RuntimeException('no network'), fn (): int => throw new RuntimeException('no network')))->collect(['site_host' => '127.0.0.1', 'https' => false, 'mail_domain' => 'example.cz', 'smtp_host' => ''], $watchNow);
check('DomainWatch: a site on a local address makes no request and is one ok row', [$watchLocal['local'] ?? false, $watchLocal['mail'], array_column($watchClass::rows($watchLocal, false, $watchNow), 'stav'), $watchClass::handoverFindings($watchLocal)], [true, null, ['ok'], []]);
check('DomainWatch: no result yet and the public demo are one ok row each', [array_column($watchClass::rows(null, false, $watchNow), 'stav'), array_column($watchClass::rows(null, true, $watchNow), 'stav'), $watchClass::handoverFindings(null)], [['ok'], ['ok'], []]);
check('DomainWatch: the cache lives in a setting, not editable, not exported', [isset(Kaleta\Core\Settings::DEFAULTS[$watchClass::SETTING]), Kaleta\Admin\Modules\Settings::verifyValue($watchClass::SETTING, 'x'), in_array($watchClass::SETTING, Kaleta\Core\SiteExport::SETTINGS, true)], [true, null, false]);
