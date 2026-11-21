<?php

declare(strict_types=1);

/* ---------- 3.50 A2: a new scheme or port of the site address asks for the passkey confirmation like a new host (N137 residual) ---------- */
use Kaleta\Core\SignInPolicy as A512Policy;

check('3.50 N137: originChanges – scheme, host or port; a default port written out, case and a trailing slash change nothing; a first address changes nothing', [
    A512Policy::originChanges('https://example.com', 'http://example.com'), A512Policy::originChanges('https://example.com', 'https://example.com:8443'),
    A512Policy::originChanges('https://example.com', 'https://www.example.com'), A512Policy::originChanges('https://example.com', 'https://example.com:443'),
    A512Policy::originChanges('https://example.com', 'HTTPS://Example.com/'), A512Policy::originChanges('', 'https://example.com'),
    A512Policy::hostChanges('https://example.com', 'http://example.com')],
    [true, true, true, false, false, false, false]);
check('3.50 N137: the scheme-or-port refusal is its own sentence, translated (cs, de, es)', [
    Kaleta\Admin\Modules\Settings::ORIGIN_CHANGE_CONFIRM !== Kaleta\Admin\Modules\Settings::HOST_CHANGE_CONFIRM,
    ...array_map(fn (string $code): bool => Kaleta\Core\Language::runWith($code, fn (): string => t(Kaleta\Admin\Modules\Settings::ORIGIN_CHANGE_CONFIRM), 'admin-') !== Kaleta\Admin\Modules\Settings::ORIGIN_CHANGE_CONFIRM, ['cs', 'de', 'es'])],
    [true, true, true, true]);
