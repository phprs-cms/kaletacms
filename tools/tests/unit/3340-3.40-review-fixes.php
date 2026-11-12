<?php

declare(strict_types=1);

use Kaleta\Front\QuotePdf;

/* ---------- 3.40 review: the fixes of the security review of quote PDFs, quotes II and applications II ---------- */

// M1: no address under /application-consent/ is ever kept among the addresses not found (a mangled link still carries its token)
check('3.40 review M1: every path under /application-consent/ is private – mangled, with a language prefix, in any case; /quote/ still is, other addresses are not', array_map(Kaleta\Core\NotFound::isPrivate(...), [
    'application-consent/' . str_repeat('a', 64) . '.', '/application-consent/' . str_repeat('b', 64) . ')', 'application-consent/' . str_repeat('c', 64) . '/',
    'en/application-consent/x', '/de-at/Application-Consent/x', 'APPLICATION-CONSENT/abc', 'quote/x', 'application-consents/x', 'application-consent', 'jobs/application-consent/x']),
    [true, true, true, true, true, true, true, false, false, false]);

// M3: a set keeps its currency – free lines keep their price only in a quote of that currency, otherwise none and a warning
$r340Set = ['name' => 'Laser R340', 'currency' => 'CZK', 'lines' => [
    ['kind' => 'free', 'code' => '', 'name' => 'Installation', 'unit' => 'h', 'unit_price' => '5000.00', 'vat_rate' => '21', 'quantity' => '2', 'discount_percent' => '0'],
    ['kind' => 'product', 'item_id' => 7, 'variant_code' => '', 'quantity' => '1', 'discount_percent' => '0'],
    ['kind' => 'own', 'code' => 'SRV', 'name' => 'Service', 'quantity' => '1', 'discount_percent' => '0']]];
check('3.40 review M3: into a CZK quote the free line keeps 5000; into a EUR quote it goes without a price (product and own row priced by the list either way)', [
    array_column(Kaleta\Core\QuoteLineSets::inputs($r340Set, 'CZK'), 'unit_price'), array_column(Kaleta\Core\QuoteLineSets::inputs($r340Set, 'EUR'), 'unit_price'),
    array_key_exists('unit_price', Kaleta\Core\QuoteLineSets::inputs($r340Set, 'EUR')[0]), array_column(Kaleta\Core\QuoteLineSets::inputs($r340Set, 'EUR'), 'name')],
    [['5000.00'], [null], true, ['Installation', 'Service']]);
check('3.40 review M3: the warning names the set and both currencies – only on a mismatch with a priced free line; a set without a currency never passes prices on', [
    Kaleta\Core\QuoteLineSets::currencyWarning($r340Set, 'CZK'), str_contains((string) Kaleta\Core\QuoteLineSets::currencyWarning($r340Set, 'EUR'), 'Laser R340'),
    str_contains((string) Kaleta\Core\QuoteLineSets::currencyWarning($r340Set, 'EUR'), 'CZK') && str_contains((string) Kaleta\Core\QuoteLineSets::currencyWarning($r340Set, 'EUR'), 'EUR'),
    Kaleta\Core\QuoteLineSets::currencyWarning(['lines' => array_slice($r340Set['lines'], 1)] + $r340Set, 'EUR'),
    Kaleta\Core\QuoteLineSets::inputs(['currency' => ''] + $r340Set, 'CZK')[0]['unit_price']],
    [null, true, true, null, null]);

// L4: whether the customer's page offers the PDF is answered from the text – the same answer as drawing it, without drawing
$r340Cases = [$u340Snapshot, ['language' => 'en'] + $u340Snapshot, ['language' => 'ar'] + $u340Snapshot, ['customer' => ['name' => 'Иван Петров'] + $u340Snapshot['customer']] + $u340Snapshot,
    ['terms' => 'Délai : 4 semaines – Größe ½ – Łódź – Ő'] + $u340Snapshot, ['lines' => [['name' => '中文'] + $u340Lines[0]]] + $u340Snapshot];
check('3.40 review L4: QuotePdf::available agrees with render for Czech, English, Arabic, Cyrillic, other European letters and Chinese', [
    array_map(fn (array $snap): bool => QuotePdf::available($u340App, $snap), $r340Cases),
    array_map(fn (array $snap): bool => QuotePdf::render($u340App, $snap, $u340Sent) !== null, $r340Cases)], [[true, true, false, false, true, false], [true, true, false, false, true, false]]);

// L5: a JPEG over Pdf::JPEG_BYTES is not embedded as it is – it is scaled and saved again (smaller), a small one stays as it is
if (function_exists('imagecreatetruecolor') && function_exists('imagejpeg')) {
    $r340Img = imagecreatetruecolor(2000, 1500); // waves with a little noise: about 4 MB as a JPEG of quality 95, far less at 800 px
    mt_srand(340);
    for ($r340Y = 0; $r340Y < 1500; $r340Y += 2) {
        for ($r340X = 0; $r340X < 2000; $r340X += 2) {
            imagefilledrectangle($r340Img, $r340X, $r340Y, $r340X + 1, $r340Y + 1, ((int) (127 + 127 * sin($r340X / 9)) << 16) | ((int) (127 + 127 * sin($r340Y / 7)) << 8)
                | ((int) (127 + 127 * sin(($r340X + $r340Y) / 13)) ^ mt_rand(0, 15)));
        }
    }
    ob_start();
    imagejpeg($r340Img, null, 95);
    $r340Big = (string) ob_get_clean();
    $r340Small = imagecreatetruecolor(120, 40);
    ob_start();
    imagejpeg($r340Small, null, 90);
    $r340SmallJpeg = (string) ob_get_clean();
    $r340Embedded = function (string $jpeg): ?array {
        $pdf = new Kaleta\Core\Pdf();
        $pdf->addPage();
        $size = $pdf->image($jpeg, 10, 10, 160, 48);
        $images = (new ReflectionProperty(Kaleta\Core\Pdf::class, 'images'))->getValue($pdf);

        return $size === null ? null : [$images[0]['filter'], strlen($images[0]['data']), max($images[0]['w'], $images[0]['h'])];
    };
    $r340B = $r340Embedded($r340Big);
    $r340S = $r340Embedded($r340SmallJpeg);
    check('3.40 review L5: a ' . round(strlen($r340Big) / 1e6, 1) . ' MB JPEG logo goes in rescaled to 800 px and under 300 kB; a small JPEG goes in byte for byte', [
        strlen($r340Big) > Kaleta\Core\Pdf::JPEG_BYTES && strlen($r340Big) <= Kaleta\Core\Pdf::IMAGE_BYTES, $r340B !== null && ($r340B[0] === 'DCTDecode' && $r340B[1] <= Kaleta\Core\Pdf::JPEG_BYTES && $r340B[2] <= Kaleta\Core\Pdf::IMAGE_SIDE),
        $r340S === null ? null : [$r340S[0], $r340S[1] === strlen($r340SmallJpeg)]], [true, true, ['DCTDecode', true]]);
}

// L6: System status knows every webhook address setting (a new one is listed in Health::WEBHOOK_SETTINGS, or this fails)
check('3.40 review L6: Health::WEBHOOK_SETTINGS are exactly the webhook address settings – webhook_applications included', [
    array_values(array_filter(array_keys(Kaleta\Core\Settings::DEFAULTS), fn (string $k): bool => str_starts_with($k, 'webhook_') && !in_array($k, ['webhook_secret', 'webhook_test_url'], true))),
    in_array('webhook_applications', Kaleta\Core\Health::WEBHOOK_SETTINGS, true)], [Kaleta\Core\Health::WEBHOOK_SETTINGS, true]);
