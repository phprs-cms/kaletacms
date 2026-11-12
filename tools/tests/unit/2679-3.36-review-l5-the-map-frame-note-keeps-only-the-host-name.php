<?php

declare(strict_types=1);

// 3.36 review L5: the note about a map frame without a readable place carries only the letters of its host name – never
// quotes, angle brackets or escapes from someone else's markup
use Kaleta\Builder\HtmlConverter;

$l5Notes = fn (string $src): array => array_values(array_filter(HtmlConverter::convert('<section><iframe src="' . htmlspecialchars($src) . '"></iframe></section>')['hlaseni'],
    fn (string $m): bool => str_contains($m, 'add a Map element')));
check('3.36 review L5: the map-frame note keeps [a-z0-9.-] of the host only', [
    $l5Notes('https://frame.mapy.cz/s/abcdef'), $l5Notes('https://a"<b>X.mapy.cz/s/abcdef'), $l5Notes('https://ev%3Cil.mapy.cz/s/abcdef'),
], [
    ['A map frame without a readable place (frame.mapy.cz) – add a Map element with the address instead.'],
    ['A map frame without a readable place (abx.mapy.cz) – add a Map element with the address instead.'],
    ['A map frame without a readable place (ev3cil.mapy.cz) – add a Map element with the address instead.'],
]);
