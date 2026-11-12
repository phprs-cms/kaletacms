<?php

declare(strict_types=1);

use Kaleta\Core\Clock;
use Kaleta\Core\Language;
use Kaleta\Core\QuoteDelivery;

/* ---------- 3.38 C: the customer's document, sending a quote, the expiry job, the retention and personal data ---------- */

$c338Settings = static function (array $values): Kaleta\Core\Settings {
    $s = (new ReflectionClass(Kaleta\Core\Settings::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty(Kaleta\Core\Settings::class, 'values'))->setValue($s, $values + ['site_name' => 'Firma', 'site_language' => 'cs', 'quote_link_days' => '30']);

    return $s;
};
$c338Db = (new ReflectionClass(Kaleta\Core\Db::class))->newInstanceWithoutConstructor(); // never reached: the secret key is in the settings
$c338Key = str_repeat('k', 16) . 'quote' . str_repeat('3', 11); // built at run time (no key-shaped literal)

// the link: an HMAC of the quote and the moment its link ends – not guessable, one per expiry, only its hash is stored
Clock::freeze('2026-11-12 10:15:30');
$c338S = $c338Settings(['secret_key' => $c338Key]);
$c338Expiry = QuoteDelivery::linkExpiry($c338S, '2026-12-01');
$c338Token = QuoteDelivery::token($c338Db, $c338S, 7, (string) $c338Expiry);
check('3.38 C: a link ends quote_link_days after the validity at the time of day it was made; a past validity counts from today; a dead link is never made', [
    $c338Expiry, QuoteDelivery::linkExpiry($c338S, '2026-01-01'), QuoteDelivery::linkExpiry($c338S, null), QuoteDelivery::linkExpiry($c338Settings(['quote_link_days' => '0']), null)],
    ['2026-12-31 10:15:30', '2026-12-12 10:15:30', '2026-12-12 10:15:30', '2026-11-13 10:15:30']);
check('3.38 C: the token is 64 hex characters, the same for the same quote and moment (the admin shows it again), another for another quote, moment or key', [
    preg_match('/^[a-f0-9]{64}$/D', $c338Token), $c338Token === QuoteDelivery::token($c338Db, $c338S, 7, (string) $c338Expiry),
    $c338Token === QuoteDelivery::token($c338Db, $c338S, 8, (string) $c338Expiry), $c338Token === QuoteDelivery::token($c338Db, $c338S, 7, '2026-12-31 10:15:31'),
    $c338Token === QuoteDelivery::token($c338Db, $c338Settings(['secret_key' => strrev($c338Key)]), 7, (string) $c338Expiry)], [1, true, false, false, false]);
check('3.38 C: what is not a token is refused before the database (a guess of another shape, upper case, a path)', [
    QuoteDelivery::byToken($c338Db, ''), QuoteDelivery::byToken($c338Db, strtoupper($c338Token)), QuoteDelivery::byToken($c338Db, substr($c338Token, 1)), QuoteDelivery::byToken($c338Db, '../' . substr($c338Token, 3))],
    [null, null, null, null]);

// retention (A-11): drafts after 6 months; others quotes_months after the last status change; anonymised never again
$c338Quotes = ['draft-old' => ['status' => 'draft', 'updated_at' => '2026-05-11 09:00:00', 'anonymised_at' => null],
    'draft-new' => ['status' => 'draft', 'updated_at' => '2026-05-13 09:00:00', 'anonymised_at' => null],
    'sent-old' => ['status' => 'accepted', 'updated_at' => '2023-11-11 23:00:00', 'anonymised_at' => null],
    'sent-new' => ['status' => 'declined', 'updated_at' => '2023-11-13 00:00:00', 'anonymised_at' => null],
    'done' => ['status' => 'expired', 'updated_at' => '2020-01-01 00:00:00', 'anonymised_at' => '2024-01-01 00:00:00']];
check('3.38 C (A-11): the retention takes drafts after 6 months, the rest 36 months after the last status change, nothing twice; 0 months = only drafts', [
    QuoteDelivery::due($c338Quotes, 36, '2026-11-12 10:00:00'), QuoteDelivery::due($c338Quotes, 0, '2026-11-12 10:00:00'),
    QuoteDelivery::due(['x' => ['status' => 'draft', 'updated_at' => '2026-02-28 12:00:00', 'anonymised_at' => null]], 36, '2026-08-31 12:00:00')],
    [['draft-old', 'sent-old'], ['draft-old'], []]);

// the default texts per language: their own variant, an empty one falls back to the default language's
$c338Texts = $c338Settings(['quote_terms' => 'Platnost 30 dní.', 'quote_terms_en' => 'Valid for 30 days.', 'quote_footer' => 'Firma s.r.o.', 'quote_footer_de' => '']);
check('3.38 C: default texts per language – the variant of the language, the default language\'s for an empty or missing one', [
    QuoteDelivery::defaultText($c338Texts, 'terms', ''), QuoteDelivery::defaultText($c338Texts, 'terms', 'en'), QuoteDelivery::defaultText($c338Texts, 'footer', 'de'),
    QuoteDelivery::defaultText($c338Texts, 'intro', 'en'), QuoteDelivery::textLanguages($c338Texts), array_keys(QuoteDelivery::TEXTS)],
    ['Platnost 30 dní.', 'Valid for 30 days.', 'Firma s.r.o.', '', [''], ['intro', 'terms', 'footer']]);

// money for the customer: always two decimals in the language of the quote, the currency as the site shows it
check('3.38 C: amounts on the document and in the e-mail – two decimals, the separators and currency of the quote\'s language', [
    Language::runWith('cs', fn (): string => QuoteDelivery::money('12500.5', 'CZK')), Language::runWith('en', fn (): string => QuoteDelivery::money('1250.00', 'EUR')),
    Language::runWith('de', fn (): string => QuoteDelivery::money('1234567.89', 'EUR')), Language::runWith('cs', fn (): string => QuoteDelivery::money(null, 'CZK')),
    Language::runWith('en', fn (): string => QuoteDelivery::money('-5.10', 'USD'))],
    ["12\u{00A0}500,50\u{00A0}Kč", '€1,250.00', "1.234.567,89\u{00A0}€", '', '-$5.10']);

// the e-mail (A-13): the message, the number, the validity, the total and the link; the signature under "-- "
$c338Quote = ['number' => '2026-0012', 'valid_until' => '2026-12-01', 'currency' => 'CZK', 'vat_mode' => 'standard', 'snapshot' => ['vat_payer' => true],
    'totals' => ['net' => '1000.00', 'vat' => '210.00', 'gross' => '1210.00', 'rates' => [], 'unpriced' => 0]];
[$c338Plain, $c338Html] = Language::runWith('en', fn (): array => QuoteDelivery::mail($c338Quote, "Hello,\n\nhere it is.", 'https://firma.example/quote/' . $c338Token, ['text' => 'Jana', 'html' => '<p>Jana</p>']));
check('3.38 C (A-13): the e-mail carries the message, the number, the validity, the total with and without VAT, the link and the signature – in text and HTML', [
    str_starts_with($c338Plain, "Hello,\n\nhere it is.\n\n"), str_contains($c338Plain, 'Quotation number: 2026-0012'), str_contains($c338Plain, 'Total including VAT: CZK' . "\u{00A0}" . '1,210.00'),
    str_contains($c338Plain, 'Total without VAT: CZK' . "\u{00A0}" . '1,000.00'), str_contains($c338Plain, 'Open the quotation: https://firma.example/quote/' . $c338Token), str_ends_with($c338Plain, "\n\n-- \nJana"),
    str_contains($c338Html, 'href="https://firma.example/quote/' . $c338Token . '"'), str_contains($c338Html, '<p>Jana</p>'), str_contains($c338Html, '<script')],
    [true, true, true, true, true, true, true, true, false]);
$c338None = Language::runWith('cs', fn (): array => QuoteDelivery::facts(['vat_mode' => 'none', 'valid_until' => null] + $c338Quote));
check('3.38 C: without VAT the e-mail names one total; without a validity no validity line', $c338None, [['Číslo nabídky', '2026-0012'], ['Celkem', "1\u{00A0}000,00\u{00A0}Kč"]]);
check('3.38 C: the default subject has {number} for the number it gets when sent', Language::runWith('en', fn (): string => QuoteDelivery::defaultSubject(['title' => 'Laser'])), 'Quotation {number} – Laser');
Clock::freeze(null);

// the catalog: send_quote is a real e-mail on a full-access connection, outside the essentials and the work set, English only
$c338Catalog = Kaleta\Mcp\Catalog::TOOLS['send_quote'] ?? null;
check('3.38 C: send_quote – destructive, the feature quotes, open world, site neutral, on demand only, English on both sides, confirm required', [
    $c338Catalog, in_array('send_quote', Kaleta\Mcp\Catalog::OPEN_WORLD, true), in_array('send_quote', Kaleta\Mcp\Catalog::SITE_NEUTRAL, true), in_array('send_quote', Kaleta\Mcp\Catalog::ON_DEMAND, true),
    in_array('send_quote', Kaleta\Mcp\Catalog::ESSENTIALS, true), in_array('send_quote', Kaleta\Mcp\Catalog::WORK, true), in_array('send_quote', Kaleta\Mcp\Catalog::IDEMPOTENT, true),
    [in_array('send_quote', Kaleta\Mcp\Translator::names(), true), Kaleta\Mcp\Translator::czech('send_quote')], Kaleta\Mcp\Catalog::allows('drafts', 'send_quote'), Kaleta\Mcp\Catalog::allows('full', 'send_quote'),
    (array_values(array_filter(Kaleta\Mcp\Tools::definitions(), fn (array $t): bool => $t['name'] === 'send_quote'))[0]['inputSchema']['required'] ?? null)],
    [['destructive', 'quotes'], true, true, true, false, false, false, [true, 'send_quote'], false, true, ['id', 'confirm']]);

// the bell names a quote by its number only; the job and the clean-up are wired in
check('3.38 C: a quote is a target of the bell – parsed, named by its number, two kinds (expires soon, expired); the job "quotes" runs on visits too', [
    Kaleta\Core\Inbox::parse('quote:12'), Kaleta\Core\Inbox::parse('quote:0'), Language::runWith('en', fn (): string => Kaleta\Core\Inbox::where(['kind' => 'quote', 'id' => 12, 'title' => '2026-0012']), 'admin-'),
    Language::runWith('en', fn (): array => [Kaleta\Core\Inbox::what('quote_expiring', ''), Kaleta\Core\Inbox::what('quote_expired', '')], 'admin-'),
    array_slice(Kaleta\Core\Scheduler::JOBS['quotes'] ?? [], 0, 2), Kaleta\Core\QuoteDelivery::DRAFT_MONTHS, Kaleta\Core\QuoteDelivery::EXPIRING_DAYS],
    [['quote', 12], null, 'Quote 2026-0012', ['A quote expires in a few days', 'A quote has expired'], [3600, 'any'], 6, 3]);
check('3.38 C: robots.txt keeps the document out (the source of the rule) and the path is reserved for the news', [in_array('quote', Kaleta\Core\Routes::NEWS_RESERVED, true), Kaleta\Core\Routes::systemSlugError('quote') !== null], [true, true]);

// the document's labels are visitor texts in every visitor dictionary (Arabic included); the admin texts in cs, de and es
$c338Visitor = ['Quotation', 'Quotation %s', 'Print or save as PDF', 'Customer', 'Item', 'Quantity', 'Unit price', 'Discount', 'VAT', 'Total', 'Subtotal', 'Total without VAT', 'Total including VAT',
    'Valid until', 'Date', 'Terms', 'Reg. No.: %s', 'VAT No.: %s', 'Discount %s %%', 'VAT %s %% of %s', 'VAT 0 % (reverse charge)', 'We are not a VAT payer.', 'The prices are final, without VAT.',
    'Reverse charge: the customer accounts for the VAT. Prices are without VAT.', 'Prices are without VAT; VAT is computed per rate.', 'Draft – not sent yet.',
    'This quotation was replaced by quotation %s.', 'This quotation expired on %s.', 'This quotation has expired.', 'This quotation was accepted.', 'This quotation was declined.', 'This quotation is valid until %s.',
    'Hello,', 'thank you for your interest. You will find our quotation at the link below – you can print it or save it as a PDF.', 'Kind regards', 'Quotation number', 'Open the quotation', 'Open the quotation: %s'];
$c338Same = ['es' => ['Total', 'Subtotal'], 'fr' => ['Total', 'Date'], 'pl' => ['VAT']]; // the same word as the English source
check('3.38 C: the document and the customer\'s e-mail speak every visitor language (cs, de, es, fr, it, pl, pt, sk, hu, zh, zh-hant, ar)', array_values(array_filter(
    ['cs', 'de', 'es', 'fr', 'it', 'pl', 'pt', 'sk', 'hu', 'zh', 'zh-hant', 'ar'],
    fn (string $code): bool => array_diff($c338Visitor, array_keys(require KALETA_SYSTEM . '/jazyky/' . $code . '.php'), $c338Same[$code] ?? []) !== [])), []);
