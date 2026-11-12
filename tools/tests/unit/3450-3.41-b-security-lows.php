<?php

declare(strict_types=1);

use Kaleta\Builder\Elements\GoogleReviews;

/* ---------- 3.41 b: the remaining low findings of the deep security audit of 10 November (N113–N123) ---------- */

// N122: a Google review with "<!--<script" or "</script>" never leaves the JSON-LD tag
$s341Review = GoogleReviews::jsonLdScript(['@type' => 'Review', 'reviewBody' => 'Great <!--<script> and </script><b>x</b>']);
check('3.41 N122: the review JSON-LD carries no < or > of a review – the tag ends where it should and the JSON reads back', [
    substr_count($s341Review, '<'), substr_count($s341Review, '>'), substr_count($s341Review, '</script>'),
    json_decode(substr($s341Review, strlen('<script type="application/ld+json">'), -strlen('</script>')), true)['reviewBody'] ?? null,
], [2, 2, 1, 'Great <!--<script> and </script><b>x</b>']);

// N123: a percent-encoded or escaped host is no redirect target and no host of Claude's; real Claude addresses still are
check('3.41 N123: isClaudeHost refuses an escaped host that ends with .claude.ai; the real hosts and loopback pass', array_map(Kaleta\Front\OAuth::isClaudeHost(...), [
    'evil.example%2f.claude.ai', 'evil.example/.claude.ai', 'evil.example\\.claude.ai', 'claude.ai', 'Claude.AI', 'www.claude.com', 'localhost', '127.0.0.1', '[::1]', 'claude.ai.evil.example']),
    [false, false, false, true, true, true, true, true, true, false]);
check('3.41 N123: isValidRedirectUri refuses an escaped host; the callbacks of Claude, Claude Code and an https app still pass', array_map(Kaleta\Front\OAuth::isValidRedirectUri(...), [
    'https://evil.example%2f.claude.ai/cb', 'https://evil.example%2F.claude.ai/api/mcp/auth_callback', 'https://claude.ai/api/mcp/auth_callback', 'http://localhost:53682/callback',
    'http://127.0.0.1:8080/cb', 'http://[::1]:9000/cb', 'https://app.example.com/oauth/callback', 'https://xn--bcher-kva.example/cb']),
    [false, false, true, true, true, true, true, true]);

// N119: the update source – https only; plain http to localhost only on a test copy; every redirect hop checked the same way
check('3.41 N119: sourceAllowed – https always, http://127.0.0.1 and localhost only on a test copy, never another http host, a user name or a backslash', [
    Kaleta\Core\Updater::sourceAllowed('https://kaletacms.com/aktualizace.json', false), Kaleta\Core\Updater::sourceAllowed('http://127.0.0.1:9601/aktualizace.json', false),
    Kaleta\Core\Updater::sourceAllowed('http://localhost/aktualizace.json', false), Kaleta\Core\Updater::sourceAllowed('http://127.0.0.1:9601/aktualizace.json', true),
    Kaleta\Core\Updater::sourceAllowed('http://10.0.0.5/aktualizace.json', true), Kaleta\Core\Updater::sourceAllowed('https://user:pw@example.com/a.json', false),
    Kaleta\Core\Updater::sourceAllowed('https://example.com\\@10.0.0.5/a.json', false), Kaleta\Core\Updater::sourceAllowed('file:///etc/passwd', true), Kaleta\Core\Updater::sourceAllowed('ftp://example.com/a.zip', false),
], [true, false, false, true, false, false, false, false, false]);
check('3.41 N119: redirectTarget resolves an absolute, a protocol-relative, a rooted and a relative Location against the current address', [
    Kaleta\Core\Updater::redirectTarget('https://a.example/x/aktualizace.json', 'http://10.0.0.5/'), Kaleta\Core\Updater::redirectTarget('https://a.example/x/y.json', '//b.example/z.zip'),
    Kaleta\Core\Updater::redirectTarget('https://a.example:8443/x/y.json', '/pkg/k.zip'), Kaleta\Core\Updater::redirectTarget('https://a.example/x/y.json', 'k-3.41.zip'),
], ['http://10.0.0.5/', 'https://b.example/z.zip', 'https://a.example:8443/pkg/k.zip', 'https://a.example/x/k-3.41.zip']);

// N120 (the export's random name: the smoke sections that export look for it): the server check reports a served canary or schema.sql, never a soft 404
$s341Answers = ['https://w.example/storage/cache/kaleta-canary.txt' => [200, 'kaleta-canary'], 'https://w.example/system/sql/schema.sql' => [200, '<html>Not found</html>']];
$s341Rules = Kaleta\Core\ServerRules::check('https://w.example/', fn (string $url): array => $s341Answers[$url] ?? [404, '']);
$s341Closed = Kaleta\Core\ServerRules::check('https://w.example/', fn (string $url): array => [403, 'Forbidden']);
$s341Down = Kaleta\Core\ServerRules::check('https://w.example/', fn (string $url): array => [0, '']);
check('3.41 N120: ServerRules::check – a served canary is open, a 200 soft 404 is not; 403 everywhere is closed; no answer is an error, not "closed"', [
    $s341Rules['open'], $s341Rules['error'], $s341Closed['open'], $s341Closed['error'], $s341Down['open'], $s341Down['error'] !== ''], [['storage/cache/kaleta-canary.txt'], '', [], '', [], true]);

// N121: the console's signed answer belongs to one heartbeat – this site and the request's time
check('3.41 N121: Link::answerBinding – bound for this site and time; another site, an old time or a half binding is refused; no binding is legacy until a bound answer was seen', [
    Kaleta\Fleet\Link::answerBinding(['ok' => true, 'site_id' => 7, 'ts' => 1700000000, 'update_allowed' => '3.41.0'], 7, 1700000000, false),
    Kaleta\Fleet\Link::answerBinding(['ok' => true, 'site_id' => 8, 'ts' => 1700000000], 7, 1700000000, false),
    Kaleta\Fleet\Link::answerBinding(['ok' => true, 'site_id' => 7, 'ts' => 1699999000], 7, 1700000000, true),
    Kaleta\Fleet\Link::answerBinding(['ok' => true, 'site_id' => 7], 7, 1700000000, false),
    Kaleta\Fleet\Link::answerBinding(['ok' => true, 'update_allowed' => '3.41.0'], 7, 1700000000, false),
    Kaleta\Fleet\Link::answerBinding(['ok' => true, 'update_allowed' => '3.41.0'], 7, 1700000000, true),
], ['bound', 'refused', 'refused', 'refused', 'legacy', 'refused']);

// N113: which links an anonymous request must not replace
$s341Now = strtotime('2026-11-12 10:00:00');
check('3.41 N113: PasswordReset::linkPending – an invitation (future time) and a request link younger than 15 minutes are pending; an older or used one is not', array_map(
    fn (array $u): bool => Kaleta\Admin\PasswordReset::linkPending($u, $s341Now), [
        ['obnova_otisk' => 'x', 'obnova_cas' => '2026-11-15 09:00:00'], ['obnova_otisk' => 'x', 'obnova_cas' => '2026-11-12 09:50:00'],
        ['obnova_otisk' => 'x', 'obnova_cas' => '2026-11-12 09:40:00'], ['obnova_otisk' => '', 'obnova_cas' => '2026-11-15 09:00:00'], ['obnova_otisk' => 'x', 'obnova_cas' => null]]),
    [true, true, false, false, false]);

// N114: a backup never carries the unsent body of a message with a live link; other queued messages keep theirs
check('3.41 N114: Mail::forBackup drops the body and the next try of a message with a link, keeps every other row as it is', [
    Kaleta\Core\Mail::forBackup(['idp' => 1, 'telo' => '{"text":"token-s341","expires":1900000000}', 'dalsi_pokus' => '2026-11-12 10:00:00', 'chyba' => 'x']),
    Kaleta\Core\Mail::forBackup(['idp' => 2, 'telo' => '{"text":"hello"}', 'dalsi_pokus' => '2026-11-12 10:00:00']),
    Kaleta\Core\Mail::forBackup(['idp' => 3, 'telo' => null, 'dalsi_pokus' => null]),
], [['idp' => 1, 'telo' => null, 'dalsi_pokus' => null, 'chyba' => 'x'], ['idp' => 2, 'telo' => '{"text":"hello"}', 'dalsi_pokus' => '2026-11-12 10:00:00'], ['idp' => 3, 'telo' => null, 'dalsi_pokus' => null]]);

// N118: Kaleta's block in the root .htaccess – an update replaces it and keeps the owner's own lines around it
$s341Shipped = (string) file_get_contents(KALETA_ROOT . '/.htaccess');
$s341Block = Kaleta\Core\Updater::htaccessBlock($s341Shipped);
$s341Newer = str_replace("Options -Indexes\n", "Options -Indexes\nRewriteRule ^s341/ - [F,L]\n", $s341Shipped);
$s341NewBlock = Kaleta\Core\Updater::htaccessBlock($s341Newer);
$s341Newer = $s341NewBlock === null ? $s341Newer : (string) preg_replace('/sha256:[a-f0-9]{16}/', 'sha256:' . substr(hash('sha256', substr($s341Newer, $s341NewBlock['start'] + strlen("# BEGIN Kaleta\n"), strrpos($s341Newer, '# END Kaleta') - $s341NewBlock['start'] - strlen("# BEGIN Kaleta\n"))), 0, 16), $s341Newer);
$s341Own = "RewriteCond %{HTTPS} off\nRewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [R=301,L]\n" . $s341Shipped . "# own footer\n";
$s341Merged = Kaleta\Core\Updater::mergeHtaccess($s341Own, $s341Newer);
check('3.41 N118: the shipped .htaccess has one intact Kaleta block; an update replaces the block and keeps the own lines above and below', [
    $s341Block !== null && $s341Block['intact'], $s341Merged !== null && str_starts_with($s341Merged, "RewriteCond %{HTTPS} off\n") && str_ends_with($s341Merged, "# own footer\n"),
    substr_count((string) $s341Merged, 'RewriteRule ^s341/ - [F,L]'), Kaleta\Core\Updater::htaccessBlock((string) $s341Merged)['intact'] ?? null,
], [true, true, 1, true]);
check('3.41 N118: an edit inside the block, a file without the block (before 3.41) or two blocks are never merged – the .kaleta-nova path stays', [
    Kaleta\Core\Updater::mergeHtaccess(str_replace('Options -Indexes', 'Options -Indexes +FollowSymLinks', $s341Own), $s341Newer),
    Kaleta\Core\Updater::mergeHtaccess("# Kaleta\nOptions -Indexes\n", $s341Newer), Kaleta\Core\Updater::mergeHtaccess($s341Own . $s341Shipped, $s341Newer),
    Kaleta\Core\Updater::mergeHtaccess(str_replace("\n", "\r\n", $s341Own), $s341Newer) !== null,
], [null, null, null, true]);
check('3.41 N118: missingHtaccessLines lists the new rule lines only – no comments, blanks or lines the file has', Kaleta\Core\Updater::missingHtaccessLines(
    "# mine\nOptions -Indexes\n  RewriteEngine On\n", "# Kaleta\nOptions -Indexes\n\nRewriteEngine On\nRewriteRule ^tools/ - [F,L]\n# note\nRewriteRule ^tools/ - [F,L]\n"), ['RewriteRule ^tools/ - [F,L]']);
