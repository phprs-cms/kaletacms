<?php

declare(strict_types=1);

// 3.37 review M1: the fallback signature takes the account photo only from the site's own media – a remote image in the
// free-text photo field of My account would load in every reply and tell its owner who opened it
use Kaleta\Builder\UserSignature;

$r337Site = ['name' => 'Hartsteel & Sons', 'url' => 'https://hartsteel.example/', 'base' => 'https://hartsteel.example', 'phone' => '',
    'address' => '', 'color' => '#0055aa', 'text_font' => '', 'heading_font' => '', 'logo' => ''];
$r337User = ['jmeno' => 'Olivia Hart', 'pozice' => 'Sales', 'phone' => '', 'email' => 'olivia@hartsteel.example'];
$r337Remote = UserSignature::fallback(['foto' => 'https://tracker.example/p.png'] + $r337User, $r337Site, '');
$r337Sneaky = UserSignature::fallback(['foto' => 'media/../../etc/x.png'] + $r337User, $r337Site, '');
$r337Own = UserSignature::fallback(['foto' => 'media/olivia.jpg'] + $r337User, $r337Site, '');
check('3.37 review M1: a remote or escaping photo is left out of the fallback signature, a photo from the site media stays', [
    str_contains($r337Remote['html'], 'tracker.example'), str_contains($r337Remote['html'], '<img'),
    str_contains($r337Sneaky['html'], '<img'),
    str_contains($r337Own['html'], 'src="https://hartsteel.example/media/olivia.jpg"'),
], [false, false, false, true]);
