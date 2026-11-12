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
