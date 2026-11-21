<?php

declare(strict_types=1);

/* ---------- 3.50 A2: a signature's photo only from Media, never the sign-in name, the template for administrators (N133, N134, N135) ---------- */
use Kaleta\Builder\EmailSignature as A510Signature;
use Kaleta\Builder\UserSignature as A510User;

$a510Site = ['name' => 'Novak Joinery', 'url' => 'https://example.com/', 'base' => 'https://example.com', 'phone' => '+420 123 456 789', 'address' => '',
    'color' => '#123456', 'text_font' => '', 'heading_font' => '', 'logo' => ''];
$a510Collection = ['pole' => [['klic' => 'foto', 'typ' => 'obrazek'], ['klic' => 'pozice', 'typ' => 'text'], ['klic' => 'email', 'typ' => 'text']]];
$a510Photo = fn (string $photo): string => preg_match('/<img src="([^"]*)"/', A510Signature::render($a510Collection, ['nazev' => 'Jana', 'data' => ['foto' => $photo, 'email' => 'jana@example.com']], $a510Site)['html'], $m) === 1 ? $m[1] : '';
check('3.50 N133: a Team record\'s photo goes into mail only from the site\'s media – a remote image (a tracking pixel), a path out of media/ or a script address is left out', [
    $a510Photo('media/2026/11/jana.jpg'), $a510Photo('/media/2026/11/jana.webp'), $a510Photo('https://tracker.example/pixel.gif'), $a510Photo('//tracker.example/p.png'),
    $a510Photo('media/../config.php'), $a510Photo('javascript:alert(1)'), $a510Photo('')],
    ['https://example.com/media/2026/11/jana.jpg', 'https://example.com/media/2026/11/jana.webp', '', '', '', '', '']);
check('3.50 N134: without a display name the signature names the local part of the e-mail, else the company – never the sign-in name', [
    A510User::values(['jmeno' => 'Jana Nováková', 'user' => 'admin', 'email' => 'jana@example.com'], $a510Site)['name'],
    A510User::values(['jmeno' => '', 'user' => 'admin', 'email' => 'jana.novakova@example.com'], $a510Site)['name'],
    A510User::values(['jmeno' => '  ', 'user' => 'admin', 'email' => 'not-an-address'], $a510Site)['name'],
    str_contains(A510User::fallback(['jmeno' => '', 'user' => 'admin', 'email' => ''], $a510Site, '')['text'], 'admin')],
    ['Jana Nováková', 'jana.novakova', 'Novak Joinery', false]);
check('3.50 N135: the signature template is one of the Business details only administrators see and save', in_array('email_signature_template', Kaleta\Admin\Modules\Business::LEGAL, true), true);
