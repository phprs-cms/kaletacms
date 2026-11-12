<?php

declare(strict_types=1);

// 3.35 review M3, L6: a passkey is bound to the host it was registered on – only the passkeys of the host of site_url count
// in the sign-in rules; a new host of the site address needs a confirmation and a fresh proof; the address is normalised
use Kaleta\Core\Passkey;
use Kaleta\Core\SignInPolicy;

$r353Keys = [['idk' => 1, 'rp_id' => 'www.example.com', 'discoverable' => 1], ['idk' => 2, 'rp_id' => 'example.com', 'discoverable' => 1], ['idk' => 3, 'rp_id' => '', 'discoverable' => 0]];
check('3.35 review M3: keysForHost – the keys of the host and those with no host recorded; every key while no host is known', [
    array_column(SignInPolicy::keysForHost($r353Keys, 'example.com'), 'idk'), array_column(SignInPolicy::keysForHost($r353Keys, 'www.example.com'), 'idk'),
    array_column(SignInPolicy::keysForHost($r353Keys, 'new.example.org'), 'idk'), array_column(SignInPolicy::keysForHost($r353Keys, ''), 'idk'),
    SignInPolicy::hasWorkingPasskey(SignInPolicy::keysForHost([['rp_id' => 'old.example', 'discoverable' => 1]], 'new.example')),
    SignInPolicy::needsSecondStep(['totp_tajemstvi' => ''], SignInPolicy::keysForHost([['rp_id' => 'old.example', 'discoverable' => 1]], 'new.example')),
], [[2, 3], [1, 3], [3], [1, 2, 3], false, false]);
check('3.35 review M3: hostChanges – another domain, www added or removed; a first address, the scheme or the port are no host change', [
    SignInPolicy::hostChanges('https://example.com', 'https://www.example.com'), SignInPolicy::hostChanges('https://www.example.com', 'https://example.com'),
    SignInPolicy::hostChanges('https://example.com', 'https://example.org'), SignInPolicy::hostChanges('', 'https://example.com'),
    SignInPolicy::hostChanges('http://example.com', 'https://example.com:8443'), SignInPolicy::hostChanges('https://Example.com.', 'https://example.com'),
], [true, true, true, false, false, false]);
check('3.35 review L6: normaliseSiteUrl – no default port, trailing dot or slash; lower case; anything else as typed', [
    SignInPolicy::normaliseSiteUrl('https://www.example.com:443/'), SignInPolicy::normaliseSiteUrl('HTTP://Example.COM.:80'), SignInPolicy::normaliseSiteUrl('https://example.com:8443'),
    SignInPolicy::normaliseSiteUrl(' https://example.com. '), SignInPolicy::normaliseSiteUrl('https://example.com/path'), SignInPolicy::normaliseSiteUrl('ftp://example.com'),
    SignInPolicy::normaliseSiteUrl('http://example.com:443'),
], ['https://www.example.com', 'http://example.com', 'https://example.com:8443', 'https://example.com', 'https://example.com/path', 'ftp://example.com', 'http://example.com:443']);
check('3.35 review L6: Passkey::rpId and origin never carry a default port or a trailing dot', [
    Passkey::rpId('https://example.com.:443'), Passkey::origin('https://example.com.:443'), Passkey::origin('http://localhost:8080'), Passkey::origin('HTTPS://Example.com'),
], ['example.com', 'https://example.com', 'http://localhost:8080', 'https://example.com']);

$r353Auth = (string) file_get_contents(KALETA_SYSTEM . '/src/Core/Auth.php');
$r353Account = (string) file_get_contents(KALETA_SYSTEM . '/src/Admin/Account.php');
$r353Settings = (string) file_get_contents(KALETA_SYSTEM . '/src/Admin/Modules/Settings.php');
check('[source-pin] 3.35 review M3: the rules read only the passkeys of this host (Auth::workingKeys); a registration records its host', [
    substr_count($r353Auth, '->workingKeys('), substr_count($r353Auth, 'SignInPolicy::passkeyOnly($user, $this->accountKeys('), substr_count($r353Auth, '$this->rememberHost($key, $siteUrl);'),
    str_contains($r353Account, "'rp_id' => Passkey::rpId(\$url),"), str_contains($r353Account, "SignInPolicy::passkeyOnly(\$user, \$working, \$app->settings())"),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/sql/schema.sql'), "rp_id      VARCHAR(255) NOT NULL DEFAULT ''"),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/sql/migrace/0121-passkey-sign-in.sql'), "ALTER TABLE ka_uzivatele_klice ADD COLUMN rp_id VARCHAR(255) NOT NULL DEFAULT '';"),
], [8, 0, 3, true, true, true, true]);
check('[source-pin] 3.35 review M3: a new host of the site address needs the confirmation and Auth::freshProof (recent), never the passkey-only password', [
    (bool) preg_match("/function signInChangeRefusal\(.*?SignInPolicy::hostChanges\(.*?postBool\('site_url_confirm'\).*?->freshProof\(.*?'recent'\)/s", $r353Settings),
    str_contains($r353Settings, "\$value = \\Kaleta\\Core\\SignInPolicy::normaliseSiteUrl(\$value);"),
], [true, true]);
check('3.35 review M3: the texts are translated (cs, de, es)', array_map(fn (string $code): array => Kaleta\Core\Language::runWith($code, fn (): array => [
    t(Kaleta\Admin\Modules\Settings::HOST_CHANGE_CONFIRM) !== Kaleta\Admin\Modules\Settings::HOST_CHANGE_CONFIRM, t(Kaleta\Admin\Modules\Settings::PROOF_PASSWORD) !== Kaleta\Admin\Modules\Settings::PROOF_PASSWORD,
    t(Kaleta\Admin\Modules\Settings::PROOF_PASSKEY) !== Kaleta\Admin\Modules\Settings::PROOF_PASSKEY, t('Passkeys of another address') !== 'Passkeys of another address',
], 'admin-'), ['cs', 'de', 'es']), [[true, true, true, true], [true, true, true, true], [true, true, true, true]]);
