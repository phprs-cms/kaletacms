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
