<?php

declare(strict_types=1);

/* ---------- 3.54 review: the build source of a site part in a version with a long code, a consent wording without placeholders ---------- */
use Kaleta\Builder\Collections as R5090Collections;
use Kaleta\Builder\SiteParts as R5090Parts;

// cast:<type>:<language>[:<variant>] as Front\Kernel writes it – a long code (en-gb, pt-br, zh-hant) is a language too
check('3.54 review: SiteParts::parseSource reads the default version, two-letter and long codes, with and without a variant',
    [R5090Parts::parseSource('cast:paticka:'), R5090Parts::parseSource('cast:paticka:en'), R5090Parts::parseSource('cast:paticka:en-gb'),
        R5090Parts::parseSource('cast:hlavicka:pt-br:promo'), R5090Parts::parseSource('cast:paticka:zh-hant:landing-1'), R5090Parts::parseSource('cast:paticka::promo')],
    [['paticka', '', ''], ['paticka', 'en', ''], ['paticka', 'en-gb', ''], ['hlavicka', 'pt-br', 'promo'], ['paticka', 'zh-hant', 'landing-1'], ['paticka', '', 'promo']]);
check('3.54 review: SiteParts::parseSource refuses an unknown part, a code Kaleta does not offer, a malformed variant and other sources',
    [R5090Parts::parseSource('cast:sidebar:en'), R5090Parts::parseSource('cast:paticka:xx-yy'), R5090Parts::parseSource('cast:paticka:en:Promo'),
        R5090Parts::parseSource('cast:paticka:en:' . str_repeat('a', 41)), R5090Parts::parseSource('stranka:5'), R5090Parts::parseSource('cast:paticka')],
    [null, null, null, null, null, null]);

// a consent wording is shown and stored alike: never filled with an item's or a component's values, {{…}} left out
check('3.54 review: Collections::withoutPlaceholders leaves out {{placeholders}} and tidies the spaces around them; a text without one only loses its outer spaces',
    [R5090Collections::withoutPlaceholders('Send me news about {{nazev}}.'), R5090Collections::withoutPlaceholders('News on {{nazev}} and {{cena}} too'),
        R5090Collections::withoutPlaceholders('  Plain  wording '), R5090Collections::withoutPlaceholders('{{nazev}}')],
    ['Send me news about.', 'News on and too', 'Plain  wording', '']);
check('3.54 review: the Newsletter element\'s text under the field – the default when missing, empty when the author emptied it, without placeholders',
    [Kaleta\Builder\Elements\Newsletter::consentText([]) === Kaleta\Builder\Elements\Newsletter::properties()['souhlas']['vychozi'],
        Kaleta\Builder\Elements\Newsletter::consentText(['souhlas' => '']), Kaleta\Builder\Elements\Newsletter::consentText(['souhlas' => 'News from {{firma}} by e-mail.'])],
    [true, '', 'News from by e-mail.']);
