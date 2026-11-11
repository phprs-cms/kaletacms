<?php

declare(strict_types=1);

use Kaleta\Core\Auth;

/* ---------- 3.35 B1: approving a new full Claude connection needs a fresh proof (owner decision 11 Nov 2026) ---------- */
$i335aNow = 1_800_000_000;
check('3.35 B1: the session marker is verified_at and a proof counts for ten minutes – never one from the future', [
    Auth::VERIFIED_KEY, Auth::VERIFIED_WINDOW,
    Auth::verifiedWithin($i335aNow, $i335aNow), Auth::verifiedWithin($i335aNow - Auth::VERIFIED_WINDOW + 1, $i335aNow),
    Auth::verifiedWithin($i335aNow - Auth::VERIFIED_WINDOW, $i335aNow), Auth::verifiedWithin(null, $i335aNow), Auth::verifiedWithin($i335aNow + 5, $i335aNow),
], ['verified_at', 600, true, true, false, false, false]);
$i335aAuth = (string) file_get_contents(KALETA_ROOT . '/system/src/Core/Auth.php');
$i335aKernel = (string) file_get_contents(KALETA_ROOT . '/system/src/Admin/Kernel.php');
$i335aView = (string) file_get_contents(KALETA_ROOT . '/system/views/admin/oauth.php');
check('3.35 B1: every completed sign-in and a right current password set the marker; an ended sign-in drops it', [
    (bool) preg_match('/function startSignIn\(.*?\{[^}]*\$this->markVerified\(\);/s', $i335aAuth),
    (bool) preg_match("/matchingPassword\(\\\$typed, \(string\) \\\$user\['password'\]\) !== null\) \{\s*\\\$this->markVerified\(\);/", $i335aAuth),
    str_contains($i335aAuth, "['idu', 'otisk', 'login_at', 'last_seen', self::VERIFIED_KEY]"),
], [true, true, true]);
check('3.35 B1: the consent asks for the password only for full access, through confirmPassword, and keeps the request on a refusal', [
    str_contains($i335aKernel, "\$passwordError = \$access === 'full' ? self::fullAccessRefusal(\$app->auth()) : null;"), // 3.35 review M2: one rule, Auth::freshProof
    (bool) preg_match("/function fullAccessRefusal\\(.*?->freshProof\\(.*?, 'recent'\\)/s", $i335aKernel) && (bool) preg_match('/function freshProof\(.*?recentlyVerified\(\).*?confirmPassword\(/s', $i335aAuth),
    // the request is forgotten only when the consent is denied or a code is issued
    substr_count($i335aKernel, 'OAuth::forgetRequest($app->session, $nonce)'),
    str_contains($i335aView, 'id="oauth-heslo" name="soucasne"'), str_contains($i335aView, '<?php if (!$verified && !$passkeyOnly):'),
], [true, true, 2, true, true]);
check('3.35 B1: the refusal and the hint are translated', array_map(fn (string $code): array => Kaleta\Core\Language::runWith($code, fn (): array => [
    t(Kaleta\Admin\Kernel::FULL_ACCESS_PASSWORD) !== Kaleta\Admin\Kernel::FULL_ACCESS_PASSWORD, t('Needed only for “Can also publish”. Drafts and reading need no password.') !== 'Needed only for “Can also publish”. Drafts and reading need no password.',
], 'admin-'), ['cs', 'de', 'es']), [[true, true], [true, true], [true, true]]);
