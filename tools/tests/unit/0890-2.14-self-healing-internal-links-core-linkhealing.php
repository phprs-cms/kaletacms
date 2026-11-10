<?php

declare(strict_types=1);

/* ---------- 2.14: self-healing internal links (Core\LinkHealing) ---------- */
$lh = fn (string $u, string $to = 'nove'): ?string => Kaleta\Core\LinkHealing::rewrite($u, 'stare', $to, 'https://example.com');
check('2.14 LinkHealing::rewrite – own paths in every form, nothing else', [
    $lh('/stare'), $lh('/stare/'), $lh('/en/stare#kontakt'), $lh('/stare?x=1'), $lh('https://example.com/stare'), $lh('/stare', ''), $lh('/stare#a', 'https://other.org/x/'),
    $lh('/stare-cenik'), $lh('/stare/podstranka'), $lh('https://other.org/stare'), $lh('stare'), $lh('/zz/stare'), $lh('/x/stare')],
    ['/nove', '/nove/', '/en/nove#kontakt', '/nove?x=1', 'https://example.com/nove', '/', 'https://other.org/x#a', null, null, null, null, null, null]);
check('2.14 LinkHealing::html – only href attributes, the rest and entities kept', [
    Kaleta\Core\LinkHealing::html('<p><a href="/stare?a=1&amp;b=2">Old</a> <img src="/stare"> <a class="x" href=\'/stare\'>x</a> /stare</p>', 'stare', 'nove')],
    ['<p><a href="/nove?a=1&amp;b=2">Old</a> <img src="/stare"> <a class="x" href=\'/nove\'>x</a> /stare</p>']);
$lhJson = '{"typ":"tlacitko","obsah":{"odkaz":"\/stare","text":"stare"},"deti":[{"obsah":{"html":"<a href=\"\/stare\">a<\/a>"}}],"prazdne":[]}';
check('2.14 LinkHealing::json – link values and HTML in any key; text that only mentions it and unchanged JSON stay byte for byte', [
    json_decode(Kaleta\Core\LinkHealing::json($lhJson, 'stare', 'nove'), true), Kaleta\Core\LinkHealing::json('{"a":"\/jine"}', 'stare', 'nove'), Kaleta\Core\LinkHealing::json('neplatne', 'stare', 'nove')],
    [['typ' => 'tlacitko', 'obsah' => ['odkaz' => '/nove', 'text' => 'stare'], 'deti' => [['obsah' => ['html' => '<a href="/nove">a</a>']]], 'prazdne' => []], '{"a":"\/jine"}', 'neplatne']);
