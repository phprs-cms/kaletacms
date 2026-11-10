<?php

declare(strict_types=1);

/* ---------- 2.11: product catalogue (Builder\Products) ---------- */
use Kaleta\Builder\Products;

check('2.11 Products::cleanParameters – "Name: value" lines, tags gone; a line without a value is not valid', [Products::cleanParameters("Weight:12 kg\n\n <b>Width</b>: 60 cm "), Products::cleanParameters('Weight'), Products::cleanParameters('')],
    ["Weight: 12 kg\nWidth: 60 cm", null, '']);
check('2.11 Products::cleanVariants – name | code | price, the empty end trimmed; too many parts or no name are not valid', [Products::cleanVariants("S | A-1 | 1 200 Kč\nM\nL | | from 900"), Products::cleanVariants('| X'), Products::cleanVariants('a | b | c | d')],
    ["S | A-1 | 1 200 Kč\nM\nL |  | from 900", null, null]);
check('2.11 Products tables – escaped, a column only when some variant has it', [Products::parametersTable('Weight: 12 & "13" kg'), Products::variantsTable("S\nM"), Products::parametersTable('')],
    ['<table class="ka-parametry"><tbody><tr><th scope="row">Weight</th><td>12 &amp; &quot;13&quot; kg</td></tr></tbody></table>', '<table class="ka-varianty"><thead><tr><th scope="col">' . t('Variant') . '</th></tr></thead><tbody><tr><td>S</td></tr><tr><td>M</td></tr></tbody></table>', '']);
check('2.11 Products::comparison – every parameter name once in the order it first appears, values side by side', Products::comparison('p', [['data' => ['p' => "Weight: 12 kg\nWidth: 60 cm"]], ['data' => ['p' => "Width: 80 cm\nMotor: 2 kW"]]]),
    [['Weight', ['12 kg', '']], ['Width', ['60 cm', '80 cm']], ['Motor', ['', '2 kW']]]);
check('2.11 Products::fields – only a collection made from the products preset', [Products::fields(['preset' => 'people', 'pole' => []]), Products::fields(['preset' => 'products', 'pole' => [['klic' => 'variants', 'typ' => 'varianty']]])['variants'] ?? null],
    [null, 'variants']);

