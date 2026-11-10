<?php

declare(strict_types=1);

/* ---------- 2.16: shared design kit of a fleet (Fleet\Kit) ---------- */
$kit = Kaleta\Fleet\Kit::class;
$kitManifest = $kit::compose(['barvy' => ['primarni' => '#AA0000', 'text' => 'red'], 'vlastni_pisma' => [['nazev' => 'X', 'soubor' => 'media/x.woff2']], 'pismo_titulky' => 'vlastni-1', 'nonsense' => 1],
    ['kit-band' => ['styl' => [], 'css' => 'padding: 2rem; background: url(http://evil/x.png); color: red'], 'Bad Name' => ['styl' => [], 'css' => 'color: red']],
    [['nazev' => 'Kit card', 'vlastnosti' => '[{"klic":"titulek","popisek":"Title","typ":"text","vychozi":"Hi"}]', 'stavba' => json_encode(['v' => 1, 'deti' => [['typ' => 'sekce', 'deti' => [
            ['typ' => 'nadpis', 'obsah' => ['text' => 'Hi'], 'atributy' => ['onclick' => 'alert(1)', 'data-x' => 'y']], ['typ' => 'html', 'obsah' => ['kod' => '<script>alert(1)</script>']]]]]]), 'stavba_koncept' => null],
        ['nazev' => 'Kit card', 'vlastnosti' => '[]', 'stavba' => '{"v":1,"deti":[{"typ":"sekce"}]}', 'stavba_koncept' => null], // the same key again: the first one stays
        ['nazev' => '', 'vlastnosti' => '[]', 'stavba' => '{"v":1,"deti":[{"typ":"sekce"}]}', 'stavba_koncept' => null], // no name, no key
        ['nazev' => 'Only code', 'vlastnosti' => '[]', 'stavba' => '{"v":1,"deti":[{"typ":"html","obsah":{"kod":"<script>x()</script>"}}]}', 'stavba_koncept' => null]], // nothing survives without admin rights
    [['nazev' => 'Kit banner', 'prvek' => '{"typ":"sekce","deti":[{"typ":"text","obsah":{"html":"<p>Hello<script>x()</script></p>"}}]}'], ['nazev' => 'Broken', 'prvek' => '{"typ":"nonsense"}']]);
check('2.16 Kit::compose – the design system is sanitized and comes without custom fonts, a class needs a valid name and keeps only safe declarations, a component loses the custom HTML element and a script attribute, a repeated or empty key and a build with nothing left are dropped, a section is cleaned like a build', [
    $kitManifest['design_system']['barvy']['primarni'], $kitManifest['design_system']['barvy']['text'], $kitManifest['design_system']['vlastni_pisma'], $kitManifest['design_system']['pismo_titulky'], isset($kitManifest['design_system']['nonsense']),
    array_keys($kitManifest['classes']), $kitManifest['classes']['kit-band']['css'],
    array_column($kitManifest['components'], 'key'), count($kitManifest['components'][0]['stavba']['deti'][0]['deti']), $kitManifest['components'][0]['stavba']['deti'][0]['deti'][0]['atributy'], array_column($kitManifest['components'][0]['properties'], 'klic'),
    array_column($kitManifest['sections'], 'key'), $kitManifest['sections'][0]['prvek']['deti'][0]['obsah']['html'], $kit::summary($kitManifest)],
    ['#aa0000', '#16181d', [], 'moderni', false, ['kit-band'], 'padding: 2rem; color: red;', ['kit-card'], 1, ['data-x' => 'y'], ['titulek'], ['kit-banner'], '<p>Hello</p>', 'design system, 1 class, 1 component, 1 section']);
check('2.16 Kit::sanitize – unknown keys and wrong shapes are dropped, the result always has the three lists; a sanitized manifest sanitizes to itself except for fresh element ids',
    [$kit::sanitize(['foo' => 1, 'classes' => 'x', 'components' => 'y']), $kit::sanitize(null), preg_replace('/"id":"[a-z0-9]+"/', '', $kit::encode($kit::sanitize($kitManifest))) === preg_replace('/"id":"[a-z0-9]+"/', '', $kit::encode($kitManifest))],
    [['classes' => [], 'components' => [], 'sections' => []], ['classes' => [], 'components' => [], 'sections' => []], true]);
check('2.16 Kit::announced – only a well-formed announcement of a newer version is fetched', [
    $kit::announced(['version' => 2, 'sha256' => str_repeat('a', 64)], 1), $kit::announced(['version' => 1, 'sha256' => str_repeat('a', 64)], 1), $kit::announced(['version' => '2', 'sha256' => str_repeat('a', 64)], 1),
    $kit::announced(['version' => 2, 'sha256' => 'xyz'], 1), $kit::announced(['version' => 2], 1), $kit::announced(null, 0), $kit::announced(['version' => 5_000_000, 'sha256' => str_repeat('a', 64)], 0)],
    [['version' => 2, 'sha256' => str_repeat('a', 64)], null, null, null, null, null, null]);
// the console signs the kit with its own key; the site checks the signature and the announced hash before it reads the manifest
$kitSettings = static function (): Kaleta\Core\Settings {
    $s = (new ReflectionClass(Kaleta\Core\Settings::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty(Kaleta\Core\Settings::class, 'values'))->setValue($s, ['site_key_secret' => base64_encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()))]);
    (new ReflectionProperty(Kaleta\Core\Settings::class, 'db'))->setValue($s, new Kaleta\Core\Db('mysql:host=127.0.0.1;dbname=none', '', '')); // never connects: the key is set

    return $s;
};
$kitConsole = $kitSettings();
$kitJson = $kit::encode($kitManifest);
$kitSha = hash('sha256', $kitJson);
$kitBody = (string) json_encode(['ok' => true, 'version' => 3, 'sha256' => $kitSha, 'manifest' => $kitJson], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$kitAnswer = fn (string $body, string $signature): array => ['status' => 200, 'body' => $body, 'json' => json_decode($body, true), 'signature' => $signature, 'error' => ''];
$kitSignature = Kaleta\Fleet\Keys::sign($kitConsole, $kitBody);
$kitRefused = function (array $answer, string $key, int $version, string $sha): ?string {
    try {
        Kaleta\Fleet\Kit::verifyAnswer($answer, $key, $version, $sha);

        return null;
    } catch (RuntimeException $e) {
        return explode(' – ', $e->getMessage())[0];
    }
};
$kitTampered = str_replace('#aa0000', '#bb0000', $kitBody);
$kitForged = (string) json_encode(['ok' => true, 'version' => 3, 'sha256' => hash('sha256', str_replace('#aa0000', '#bb0000', $kitJson)), 'manifest' => str_replace('#aa0000', '#bb0000', $kitJson)]);
check('2.16 Kit::verifyAnswer – the signed kit with the announced hash passes; a tampered body, another console\'s key, a different version, a manifest that does not hash to the announcement, or no answer are refused', [
    $kit::verifyAnswer($kitAnswer($kitBody, $kitSignature), Kaleta\Fleet\Keys::publicKey($kitConsole), 3, $kitSha)['components'][0]['key'],
    $kitRefused($kitAnswer($kitTampered, $kitSignature), Kaleta\Fleet\Keys::publicKey($kitConsole), 3, $kitSha),
    $kitRefused($kitAnswer($kitBody, $kitSignature), Kaleta\Fleet\Keys::publicKey($kitSettings()), 3, $kitSha),
    $kitRefused($kitAnswer($kitBody, $kitSignature), Kaleta\Fleet\Keys::publicKey($kitConsole), 4, $kitSha),
    $kitRefused($kitAnswer($kitForged, Kaleta\Fleet\Keys::sign($kitConsole, $kitForged)), Kaleta\Fleet\Keys::publicKey($kitConsole), 3, $kitSha),
    $kitRefused(['status' => 0, 'body' => '', 'json' => null, 'signature' => '', 'error' => 'No answer.'], Kaleta\Fleet\Keys::publicKey($kitConsole), 3, $kitSha),
    isset(Kaleta\Core\Events::TYPES['fleet.kit_received']), isset(Kaleta\Core\Events::TYPES['fleet.kit_refused']), isset(Kaleta\Core\Events::TYPES['fleet.kit_published'])],
    ['kit-card', 'The kit is not signed by the console', 'The kit is not signed by the console', 'The console handed over a different kit version than it announced', 'The kit does not match the hash the console announced', 'The console did not hand over the kit (No answer.).', true, true, true]);

