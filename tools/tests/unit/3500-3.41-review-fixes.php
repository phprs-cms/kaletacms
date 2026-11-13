<?php

declare(strict_types=1);

use Kaleta\Core\ServerRules;

/* ---------- 3.41 review: the findings of the security review of 3.41 (M1, L1–L8) ---------- */

// L1: a site address that redirects is followed once on the same host; a redirect elsewhere is inconclusive, never "refused"
$r341Https = ServerRules::check('http://w.example/', fn (string $url): array => str_starts_with($url, 'http://')
    ? [301, '', str_replace('http://', 'https://', $url)] : [403, 'Forbidden']);
$r341Open = ServerRules::check('http://w.example/', fn (string $url): array => str_starts_with($url, 'http://')
    ? [301, '', str_replace('http://w.', 'https://www.w.', $url)] : [200, 'kaleta-canary CREATE TABLE']);
$r341Away = ServerRules::check('https://w.example/', fn (string $url): array => [302, '', 'https://elsewhere.example/x']);
check('3.41 review L1: http → https on the same host is followed (closed / open); a redirect to another host is a "redirect", not closed', [
    $r341Https['open'], $r341Https['redirect'] ?? '', $r341Open['open'], $r341Away['open'], $r341Away['redirect'] ?? '',
], [[], '', ['storage/cache/kaleta-canary.txt', 'system/sql/schema.sql'], [], 'https://elsewhere.example/x']);
check('3.41 review L1: sameHostRedirect resolves a relative Location and refuses another host or scheme', [
    ServerRules::sameHostRedirect('http://w.example/a', 301, '/b'),
    ServerRules::sameHostRedirect('http://w.example/a', 301, 'https://evil.example/a'),
    ServerRules::sameHostRedirect('http://w.example/a', 301, 'ftp://w.example/a'),
    ServerRules::sameHostRedirect('http://w.example/a', 200, 'https://w.example/a'),
], ['http://w.example/b', null, null, null]);
