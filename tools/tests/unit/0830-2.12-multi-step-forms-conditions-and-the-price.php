<?php

declare(strict_types=1);

/* ---------- 2.12: multi-step forms, conditions and the price estimate (Builder\Elements\Form) ---------- */
use Kaleta\Builder\Elements\Form as FormElement;

$calcFields = [
    ['popisek' => 'Typ', 'typ' => 'volba', 'moznosti_volby' => "Okna | 1200\nDveře | 9 900\nPoradenství"],
    ['popisek' => 'Počet', 'typ' => 'cislo', 'cena_za_jednotku' => '1 500'],
    ['popisek' => 'Doplňky', 'typ' => 'zaskrtnuti', 'moznosti_zaskrtnuti' => "Montáž | 2000\nOdvoz | 500"],
    ['popisek' => 'Barva dveří', 'typ' => 'vyber', 'moznosti' => "Bílá\nDub | 3000", 'kdyz_pole' => 'typ', 'kdyz_hodnota' => 'Dveře'],
    ['popisek' => 'Odstín', 'typ' => 'text', 'kdyz_pole' => 'Barva dveří', 'kdyz_hodnota' => '*'],
    ['popisek' => 'Krok 2', 'typ' => 'krok'],
    ['popisek' => 'Odhad', 'typ' => 'odhad', 'zaklad' => '500', 'mena' => 'Kč'],
];
check('2.12 Form::optionPrices – "Label | price" lines, the visitor sees and sends only the label', [FormElement::optionPrices($calcFields[0]), FormElement::options($calcFields[2])],
    [['Okna' => 1200.0, 'Dveře' => 9900.0, 'Poradenství' => 0.0], ['Montáž', 'Odvoz']]);
$calcAnswers = [0 => 'Okna', 1 => '4', 2 => ['Montáž'], 3 => 'Dub', 4 => 'tmavý'];
$calcVisible = FormElement::visible($calcFields, $calcAnswers);
check('2.12 Form::visible – a condition on another answer, a chain of conditions, a missing field hides it', [$calcVisible[3], $calcVisible[4], $calcVisible[0],
    FormElement::visible($calcFields, [0 => 'Dveře', 3 => 'Dub'])[4], FormElement::visible([['popisek' => 'A', 'typ' => 'text', 'kdyz_pole' => 'Nikde', 'kdyz_hodnota' => 'x']], [])[0]],
    [false, false, true, true, false]);
check('2.12 Form::estimate – the base, chosen and ticked options and number × unit price, only of shown fields', [FormElement::estimate($calcFields, $calcAnswers, $calcVisible, 500.0),
    FormElement::estimate($calcFields, [0 => 'Dveře', 1 => '', 2 => [], 3 => 'Dub'], FormElement::visible($calcFields, [0 => 'Dveře', 3 => 'Dub']), 0.0)], [500.0 + 1200 + 4 * 1500 + 2000, 9900.0 + 3000]);
check('2.12 Form::money – whole amounts without decimals, the currency after a no-break space', [FormElement::money(9700.0, 'Kč'), FormElement::money(12.5, ''), FormElement::price('1 200,50')],
    [format_count(9700) . "\u{a0}Kč", format_count(12.5, 2), 1200.5]);

