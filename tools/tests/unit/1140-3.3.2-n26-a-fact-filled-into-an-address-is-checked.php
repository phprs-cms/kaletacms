<?php

declare(strict_types=1);

/* ---------- 3.3.2 (N26): a fact filled into an address is checked like any other link ---------- */
$n26Cache = new ReflectionProperty(Kaleta\Core\Facts::class, 'cache');
$n26Fact = fn (string $key, string $type, string $value): array => ['key' => $key, 'label' => $key, 'type' => $type, 'value' => $value, 'display' => $value, 'schema' => '', 'source' => '', 'updated' => null, 'builtIn' => false, 'translated' => false];
$n26Cache->setValue(null, [Kaleta\Core\Language::siteColumn() => ['promo' => $n26Fact('promo', 'text', 'javascript:alert(document.domain)'), 'shop' => $n26Fact('shop', 'url', 'https://shop.example/a?b=1&c=2'),
    'phone' => $n26Fact('phone', 'phone', '+420 123 456 789'), 'tricky' => $n26Fact('tricky', 'text', ' JaVa'), 'entity' => $n26Fact('entity', 'text', 'jav&#x61;script:alert(1)'),
    'data' => $n26Fact('data', 'text', 'data:text/html,<script>alert(1)</script>'), 'name' => $n26Fact('name', 'text', 'A "quoted" <name>')]]);
// the harness of the audit: a text fact "javascript:…" behind a Button
[$n26Build] = Kaleta\Builder\Build::sanitize(['deti' => [['typ' => 'tlacitko', 'obsah' => ['text' => 'Promo', 'odkaz' => '{{fact.promo}}']], ['typ' => 'tlacitko', 'obsah' => ['text' => 'Call', 'odkaz' => 'tel:{{fact.phone}}']],
    ['typ' => 'tlacitko', 'obsah' => ['text' => 'Shop', 'odkaz' => '{{ fact.shop }}']]]]);
$n26Button = $n25Html($n26Build);
$n26Fill = fn (string $html): string => Kaleta\Core\Facts::fill($html, $n25App);
check('3.3.2 N26: a Button linked to a text fact "javascript:…" renders no javascript: link; a phone and a web address fact still work', [
    (bool) preg_match('/href="\s*javascript:/i', $n26Button), substr_count($n26Button, 'href="#">Promo</a>'), str_contains($n26Button, 'href="tel:+420 123 456 789">Call'), str_contains($n26Button, 'href="https://shop.example/a?b=1&amp;c=2">Shop')],
    [false, 1, true, true]);
check('3.3.2 N26: page, news and Custom HTML – every quoting, an image, a form, a token split around a scheme, entities, data: and a ">" in an earlier attribute; text and <code> as before', [
    $n26Fill('<p><a href="{{fact.promo}}">a</a><a href=\'{{fact.promo}}\'>b</a><a href={{ fact.promo }}>c</a><a HREF = "{{fact.promo}}">d</a></p>'),
    $n26Fill('<img src="{{fact.promo}}" alt="{{fact.name}}"><form action="{{fact.promo}}"><button formaction="{{fact.data}}">x</button></form>'),
    $n26Fill('<a href="{{fact.tricky}}script:alert(1)">e</a><a href="{{fact.entity}}">f</a><a title="x>y" href="{{fact.promo}}">g</a><a href="/{{fact.promo}}">h</a>'),
    $n26Fill('<p>{{fact.promo}} {{fact.name}}</p><code><a href="{{fact.promo}}">i</a></code>')],
    ['<p><a href="#">a</a><a href="#">b</a><a href="#">c</a><a HREF="#">d</a></p>',
     '<img src="" alt="A &quot;quoted&quot; &lt;name&gt;"><form action=""><button formaction="">x</button></form>',
     '<a href="#">e</a><a href="jav&amp;#x61;script:alert(1)">f</a><a title="x>y" href="#">g</a><a href="/javascript:alert(document.domain)">h</a>',
     '<p>javascript:alert(document.domain) A &quot;quoted&quot; &lt;name&gt;</p><code><a href="{{fact.promo}}">i</a></code>']);
