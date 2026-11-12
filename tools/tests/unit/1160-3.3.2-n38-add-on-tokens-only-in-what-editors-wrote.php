<?php

declare(strict_types=1);

/* ---------- 3.3.2 (N38): add-on tokens only in what editors wrote, never in what a visitor sent ---------- */
$reg->addToken('naive', 'echo', fn (array $a): string => (string) ($a['x'] ?? 'NAIVE')); // an add-on that prints its attribute as it is
$n38Query = '<input type="search" name="q" value="' . e('{{ext.naive.echo x="<img src=x onerror=alert(1)>"}}') . '">';
$n38Visitor = $n38Query . '<p>' . e('{{ext.naive.echo}}') . '</p>';
$n38Context = new Kaleta\Builder\Context($n25App);
$n38Context->content = $n38Visitor;
$n38Wrapper = Kaleta\Builder\Build::html(['deti' => [['id' => 't1', 'typ' => 'text', 'znacka' => 'div', 'obsah' => ['html' => '<p>{{ext.naive.echo x="own"}}</p>']], ['id' => 'o1', 'typ' => 'obsah', 'znacka' => 'div', 'obsah' => []]]], $n38Context);
$kernelSource = (string) file_get_contents(KALETA_SYSTEM . '/src/Front/Kernel.php');
preg_match('/private function page\(.*?\n    }\n/s', $kernelSource, $pageMethod);
check('[source-pin] 3.3.2 N38: an escaped-quote token (a visitor\'s search query) is never run; a site-part wrapper fills its own tokens but not the page content it wraps; the page as a whole is not filled', [
    Kaleta\Extension\Registry::fillTokens($n38Query), str_contains($n38Wrapper, '<p>own</p>'), str_contains($n38Wrapper, $n38Visitor), str_contains($n38Wrapper, '<img'), str_contains($n38Wrapper, 'NAIVE'),
    $n38Context->content === $n38Visitor, ($pageMethod[0] ?? '') !== '' && !str_contains($pageMethod[0], 'fillTokens('), substr_count($kernelSource, 'self::authored(') >= 5],
    [$n38Query, true, true, false, false, true, true, true]);
$n26Cache->setValue(null, []);
