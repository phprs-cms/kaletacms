<?php

declare(strict_types=1);

/* ---------- 2.12: calls and e-mail clicks counted as conversions (Core\Conversions) ---------- */
$clickType = Kaleta\Core\Conversions::type(...);
$clickPath = Kaleta\Core\Conversions::path(...);
check('2.12 Conversions::type – tel, mailto and whatsapp only, case and spaces forgiven, anything else is not counted',
    [$clickType('tel'), $clickType(' MAILTO '), $clickType('whatsapp'), $clickType('fax'), $clickType(''), $clickType('tel:+420'), $clickType('click_phone')], ['tel', 'mailto', 'whatsapp', null, null, null, null]);
check('2.12 Conversions::path – an absolute path without the query string and the fragment, at most 255 characters; anything else is a forged request',
    [$clickPath('/kontakt'), $clickPath('/kontakt?utm_source=x#telefon'), $clickPath(' /en/contact '), $clickPath('/'), $clickPath('kontakt'), $clickPath('https://example.com/kontakt'), $clickPath('/kon takt'), $clickPath("/a\nb"), $clickPath(''),
        $clickPath('/' . str_repeat('a', 254)), $clickPath('/' . str_repeat('a', 255))],
    ['/kontakt', '/kontakt', '/en/contact', '/', null, null, null, null, null, '/' . str_repeat('a', 254), null]);
$clickLink = fn (string $href): int => preg_match(Kaleta\Core\Conversions::LINK_PATTERN, '<p><a href="' . $href . '">x</a></p>');
check('2.12 Conversions::LINK_PATTERN – the links the script counts (tel:, mailto:, wa.me, api.whatsapp.com, whatsapp:), not an ordinary link; KEYS name every type',
    [$clickLink('tel:+420123456789'), $clickLink('mailto:info@example.com'), $clickLink('https://wa.me/420123456789'), $clickLink('https://api.whatsapp.com/send?phone=1'), $clickLink('whatsapp://send?phone=1'), $clickLink('https://example.com/tel:'), $clickLink('/kontakt'),
        array_keys(Kaleta\Core\Conversions::KEYS), in_array('konverze', Kaleta\Admin\Modules\Pages::RESERVED_SLUGS, true)],
    [1, 1, 1, 1, 1, 0, 0, Kaleta\Core\Conversions::TYPES, true]);
check('2.12 Conversions::total – every type together, missing keys are zero', [Kaleta\Core\Conversions::total(['calls' => 2, 'emails' => 1, 'whatsapp' => 4]), Kaleta\Core\Conversions::total(['path' => '/x', 'calls' => 1]), Kaleta\Core\Conversions::total([])], [7, 1, 0]);

