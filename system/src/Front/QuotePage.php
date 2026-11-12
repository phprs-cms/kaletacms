<?php

declare(strict_types=1);

namespace Kaleta\Front;

use Kaleta\Builder\DesignSystem;
use Kaleta\Core\Antispam;
use Kaleta\Core\App;
use Kaleta\Core\Language;
use Kaleta\Core\Money;
use Kaleta\Core\QuoteDelivery;
use Kaleta\Core\Quotes;
use Kaleta\Core\Response;

/**
 * The customer's quote (3.38, design A7): /quote/<token> shows one sent quote as a print-ready document in the site's
 * look – the design system's tokens and custom fonts, the logo, the supplier block of Business details – with its own
 * small stylesheet image/quote.css (A4 pages, the table header repeated on every printed page) and a "Print or save as
 * PDF" button (image/quote.js). No PDF is made on the server in this release (A-9).
 *
 *  - The content is the frozen snapshot of the quote: a later change of prices, product names or company details never
 *    rewrites what the customer has. Only the stylesheet is live. The labels are visitor texts in the quote's language
 *    (every visitor dictionary), right to left for Arabic with the amounts left to right.
 *  - The page is a document, not a site page: no header, menu, footer, pop-ups, statistics or cookie bar; never cached
 *    (Cache-Control: private, no-store – Front\Cache never stores it), noindex, nofollow, no referrer, its own strict CSP;
 *    never in the sitemap, search or llms.txt; robots.txt disallows /quote/.
 *  - A wrong, withdrawn or expired token gets the site's 404 page with no detail and is not logged among the addresses
 *    not found (a token is no address); failed attempts count per network (Core\Antispam) and after
 *    QuoteDelivery::MAX_TRIES within an hour every attempt from it is a 404.
 *  - It keeps working when the feature is switched off (a customer's link must not die because a switch was flipped);
 *    withdrawing links is explicit (the admin, the expiry job, the retention).
 *  - The admin's Preview (Admin\QuoteSending) renders the same document from the live data of a draft, marked as a draft.
 */
final class QuotePage
{
    /**
     * The document of a customer's token, or null (the caller answers with the site's 404). Counts a failed attempt.
     */
    public static function response(App $app, string $token, bool $pdf = false): ?Response
    {
        $db = $app->db();
        $ip = Antispam::network($app->request->ip());
        $antispam = new Antispam($db, $app->settings());
        if ($antispam->count($ip, QuoteDelivery::TRIES, 0, 60) >= QuoteDelivery::MAX_TRIES) {
            return null;
        }
        $id = QuoteDelivery::byToken($db, $token);
        $quote = $id !== null ? Quotes::get($db, $id) : null;
        if ($quote === null || $quote['snapshot'] === null) {
            $antispam->write($ip, QuoteDelivery::TRIES, 0);

            return null;
        }

        QuoteDecision::opened($app, $quote); // 3.40: "first opened on" (the time only) – the page or its PDF

        $state = self::state($app, $quote);
        if ($pdf) { // 3.40: /quote/<token>.pdf – the same document as a file (Front\QuotePdf), null = this quote has no PDF
            $file = QuotePdf::render($app, $quote['snapshot'], $state);

            return $file !== null ? QuotePdf::response($file, QuotePdf::filename($quote['number'], $id)) : null;
        }
        $pdfUrl = QuotePdf::available($app, $quote['snapshot']) ? $app->request->basePath() . '/quote/' . $token . '.pdf' : null;

        // 3.40: the customer's Accept / Decline goes into the document
        return self::html(QuoteDecision::into($app, self::document($app, $quote['snapshot'], $state, $pdfUrl), $quote, $token));
    }

    /** The response of a rendered document: never cached, never indexed, no referrer, its own CSP (scripts and styles of the site only). */
    public static function html(string $html): Response
    {
        return new Response($html, 200, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'private, no-store', 'X-Robots-Tag' => 'noindex, nofollow',
            'Referrer-Policy' => 'no-referrer', 'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'self'"]); // 3.40: the customer's answer posts to the page itself
    }

    /**
     * Where the quote stands for the customer: its status, the number of the revision that replaced it.
     *
     * @param array<string, mixed> $quote as Quotes::get returns it
     * @return array{status: string, replaced_by: ?string, draft: bool}
     */
    public static function state(App $app, array $quote): array
    {
        $replaced = null;
        if ($quote['status'] === 'expired') {
            $number = $app->db()->value("SELECT number FROM {quotes} WHERE revision_of = ? AND status <> 'draft' AND number IS NOT NULL ORDER BY id DESC LIMIT 1", [$quote['id']]);
            $replaced = is_string($number) && $number !== '' ? $number : null;
        }

        return ['status' => (string) $quote['status'], 'replaced_by' => $replaced, 'draft' => $quote['status'] === 'draft'];
    }

    /**
     * The whole document as HTML in the quote's language (the snapshot's language; '' = the site's default).
     *
     * @param array<string, mixed> $snapshot the frozen content (Quotes::snapshot)
     * @param array{status: string, replaced_by: ?string, draft: bool} $state
     * @param string|null $pdfUrl 3.40: where "Download PDF" leads (null = no PDF for this quote, Front\QuotePdf)
     */
    public static function document(App $app, array $snapshot, array $state, ?string $pdfUrl = null): string
    {
        $s = $app->settings();
        $language = Language::ofContent($s, (string) ($snapshot['language'] ?? ''));

        return Language::runWith($language, fn (): string => self::render($app, $snapshot, $state, $language, $pdfUrl), '', $s->get('german_register'));
    }

    /** @param array<string, mixed> $snapshot @param array{status: string, replaced_by: ?string, draft: bool} $state */
    private static function render(App $app, array $snapshot, array $state, string $language, ?string $pdfUrl = null): string
    {
        $s = $app->settings();
        $base = $app->request->basePath();
        $ds = DesignSystem::load($s);
        $currency = (string) ($snapshot['currency'] ?? '');
        $money = fn (mixed $v): string => '<span class="quote-amount">' . e(QuoteDelivery::money(is_scalar($v) ? (string) $v : null, $currency)) . '</span>';
        $mode = (string) ($snapshot['vat_mode'] ?? 'standard');
        $vatColumns = ($snapshot['vat_payer'] ?? true) !== false && $mode !== 'none';
        $number = (string) ($snapshot['number'] ?? '');
        $title = trim((string) ($snapshot['title'] ?? ''));
        $heading = $number !== '' ? t('Quotation %s', $number) : t('Quotation');
        $supplier = is_array($snapshot['supplier'] ?? null) ? $snapshot['supplier'] : [];
        $customer = is_array($snapshot['customer'] ?? null) ? $snapshot['customer'] : [];
        $lines = array_values(array_filter(is_array($snapshot['lines'] ?? null) ? $snapshot['lines'] : [], 'is_array'));
        $totals = is_array($snapshot['totals'] ?? null) ? $snapshot['totals'] : [];
        $logo = $s->get('logo');
        $siteName = (string) ($supplier['name'] ?? '') !== '' ? (string) $supplier['name'] : $s->get('site_name');

        $html = '<!doctype html><html lang="' . e(Language::tag($language)) . '"' . Language::dirAttribute($language) . '><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow"><meta name="referrer" content="no-referrer">'
            . '<title>' . e($heading . ' – ' . $siteName) . '</title>' . DesignSystem::fontPreloads($ds, $base) . '<style>' . DesignSystem::css($ds, $base) . '</style>'
            . '<link rel="stylesheet" href="' . e($base . '/image/quote.css?v=' . KALETA_VERSION) . '">'
            . '<script src="' . e($base . '/image/quote.js?v=' . KALETA_VERSION) . '" defer></script></head><body class="quote-body">';
        $html .= '<div class="quote-tools">' . ($pdfUrl !== null ? '<a class="quote-pdf" href="' . e($pdfUrl) . '" download>' . e(t('Download PDF')) . '</a>' : '')
            . '<button type="button" class="quote-print" data-quote-print hidden>' . e(t('Print or save as PDF')) . '</button></div>';
        $html .= '<article class="quote">';

        // the head: the supplier (logo, name, address, numbers) and the document's title with the dates
        $html .= '<header class="quote-head"><div class="quote-supplier">'
            . ($logo !== '' ? '<img class="quote-logo" src="' . e($base . '/' . ltrim($logo, '/')) . '" alt="' . e($siteName) . '">' : '')
            . '<p class="quote-party-name">' . e($siteName) . '</p>' . self::lines([
                trim((string) ($supplier['street'] ?? '')),
                trim(trim((string) ($supplier['postcode'] ?? '')) . ' ' . trim((string) ($supplier['city'] ?? ''))),
                trim((string) ($supplier['country'] ?? '')),
                (string) ($supplier['reg_no'] ?? '') !== '' ? t('Reg. No.: %s', (string) $supplier['reg_no']) : '',
                (string) ($supplier['vat_id'] ?? '') !== '' ? t('VAT No.: %s', (string) $supplier['vat_id']) : '',
                trim((string) ($supplier['register'] ?? '')),
                trim((string) ($supplier['phone'] ?? '')),
                trim((string) ($supplier['email'] ?? '')),
            ]) . '</div><div class="quote-title"><h1>' . e($heading) . '</h1>' . ($title !== '' ? '<p class="quote-subject">' . e($title) . '</p>' : '') . '<dl class="quote-dates">'
            . ((string) ($snapshot['sent_at'] ?? '') !== '' ? '<dt>' . e(t('Date')) . '</dt><dd>' . e(format_date((string) $snapshot['sent_at'])) . '</dd>' : '')
            . ((string) ($snapshot['valid_until'] ?? '') !== '' ? '<dt>' . e(t('Valid until')) . '</dt><dd>' . e(format_date((string) $snapshot['valid_until'])) . '</dd>' : '')
            . '</dl></div></header>';

        $notice = self::notice($state, (string) ($snapshot['valid_until'] ?? ''));
        if ($notice !== '') {
            $html .= '<p class="quote-notice' . ($state['status'] === 'expired' || $state['draft'] ? ' quote-notice-warning' : '') . '" role="status">' . e($notice) . '</p>';
        }

        // the customer
        $html .= '<section class="quote-customer" aria-label="' . e(t('Customer')) . '"><h2>' . e(t('Customer')) . '</h2>'
            . ((string) ($customer['company'] ?? '') !== '' ? '<p class="quote-party-name">' . e((string) $customer['company']) . '</p>' : '')
            . self::lines([
                (string) ($customer['company'] ?? '') !== '' ? (string) ($customer['name'] ?? '') : '',
                ...preg_split('/\R/u', trim((string) ($customer['address'] ?? ''))) ?: [],
                (string) ($customer['reg_no'] ?? '') !== '' ? t('Reg. No.: %s', (string) $customer['reg_no']) : '',
                (string) ($customer['vat_id'] ?? '') !== '' ? t('VAT No.: %s', (string) $customer['vat_id']) : '',
                (string) ($customer['email'] ?? ''),
            ], (string) ($customer['company'] ?? '') === '' ? (string) ($customer['name'] ?? '') : '') . '</section>';

        if (trim((string) ($snapshot['intro'] ?? '')) !== '') {
            $html .= '<div class="quote-text">' . self::paragraphs((string) $snapshot['intro']) . '</div>';
        }

        // the lines
        $discounts = array_filter($lines, fn (array $l): bool => Money::percent($l['discount_percent'] ?? '0') > 0) !== [];
        $html .= '<table class="quote-lines"><thead><tr><th scope="col" class="quote-col-item">' . e(t('Item')) . '</th><th scope="col" class="quote-num">' . e(t('Quantity')) . '</th>'
            . '<th scope="col" class="quote-num">' . e(t('Unit price')) . '</th>' . ($discounts ? '<th scope="col" class="quote-num">' . e(t('Discount')) . '</th>' : '')
            . ($vatColumns ? '<th scope="col" class="quote-num">' . e(t('VAT')) . '</th>' : '') . '<th scope="col" class="quote-num">' . e(t('Total')) . '</th></tr></thead><tbody>';
        $subtotal = 0;
        foreach ($lines as $l) {
            $subtotal += (int) Money::fromDecimal($l['line_net'] ?? null);
            $rate = $mode === 'reverse_charge' ? '0' : Money::short((string) ($l['vat_rate'] ?? '0'));
            $html .= '<tr><td class="quote-col-item"><span class="quote-item-name">' . e((string) ($l['name'] ?? '')) . '</span>'
                . ((string) ($l['code'] ?? '') !== '' ? ' <span class="quote-item-code">' . e((string) $l['code']) . '</span>' : '')
                . ((string) ($l['description'] ?? '') !== '' ? '<span class="quote-item-description">' . e((string) $l['description']) . '</span>' : '') . '</td>'
                . '<td class="quote-num" data-label="' . e(t('Quantity')) . '">' . e(self::number(Money::short((string) ($l['quantity'] ?? '1')))) . ((string) ($l['unit'] ?? '') !== '' ? "\u{00A0}" . e((string) $l['unit']) : '') . '</td>'
                . '<td class="quote-num" data-label="' . e(t('Unit price')) . '">' . $money($l['unit_price'] ?? null) . '</td>'
                . ($discounts ? '<td class="quote-num" data-label="' . e(t('Discount')) . '">' . (Money::percent($l['discount_percent'] ?? '0') > 0 ? e(self::number(Money::short((string) $l['discount_percent']))) . "\u{00A0}%" : '') . '</td>' : '')
                . ($vatColumns ? '<td class="quote-num" data-label="' . e(t('VAT')) . '">' . e(self::number($rate)) . "\u{00A0}%</td>" : '')
                . '<td class="quote-num" data-label="' . e(t('Total')) . '">' . $money($l['line_net'] ?? null) . '</td></tr>';
        }
        $html .= '</tbody></table>';

        // the totals: the sum of the lines, the whole-quote discount, VAT per rate, the total
        $net = (int) Money::fromDecimal($totals['net'] ?? '0');
        $rows = [];
        $discountPoints = Money::percent($snapshot['discount_percent'] ?? '0');
        if ($discountPoints > 0) {
            $rows[] = [t('Subtotal'), $money(Money::toDecimal($subtotal)), ''];
            $rows[] = [t('Discount %s %%', self::number(Money::short((string) $snapshot['discount_percent']))), $money('-' . Money::toDecimal(max(0, $subtotal - $net))), ''];
        }
        if ($vatColumns) {
            $rows[] = [t('Total without VAT'), $money($totals['net'] ?? null), ''];
            foreach (array_values(array_filter(is_array($totals['rates'] ?? null) ? $totals['rates'] : [], 'is_array')) as $r) {
                $rows[] = [$mode === 'reverse_charge' ? t('VAT 0 % (reverse charge)') : t('VAT %s %% of %s', self::number(Money::short((string) ($r['rate'] ?? '0'))), QuoteDelivery::money((string) ($r['net'] ?? '0'), $currency)),
                    $money($mode === 'reverse_charge' ? '0.00' : ($r['vat'] ?? '0')), ''];
            }
            $rows[] = [t('Total including VAT'), $money($totals['gross'] ?? null), 'quote-grand'];
        } else {
            $rows[] = [t('Total'), $money($totals['net'] ?? null), 'quote-grand'];
        }
        $html .= '<table class="quote-totals"><tbody>' . implode('', array_map(fn (array $r): string => '<tr' . ($r[2] !== '' ? ' class="' . $r[2] . '"' : '') . '><th scope="row">' . e($r[0]) . '</th><td class="quote-num">' . $r[1] . '</td></tr>', $rows)) . '</tbody></table>';
        $html .= '<p class="quote-note">' . e(match (true) {
            !$vatColumns && ($snapshot['vat_payer'] ?? true) === false => t('We are not a VAT payer.'),
            !$vatColumns => t('The prices are final, without VAT.'),
            $mode === 'reverse_charge' => t('Reverse charge: the customer accounts for the VAT. Prices are without VAT.'),
            default => t('Prices are without VAT; VAT is computed per rate.'),
        }) . '</p>';

        if (trim((string) ($snapshot['terms'] ?? '')) !== '') {
            $html .= '<section class="quote-text quote-terms"><h2>' . e(t('Terms')) . '</h2>' . self::paragraphs((string) $snapshot['terms']) . '</section>';
        }
        if (trim((string) ($snapshot['footer'] ?? '')) !== '') {
            $html .= '<footer class="quote-footer">' . self::paragraphs((string) $snapshot['footer']) . '</footer>';
        }

        return $html . '</article></body></html>';
    }

    /** The status line for the customer: draft, valid until, expired, replaced by, accepted, declined; '' when nothing to say. @param array{status: string, replaced_by: ?string, draft: bool} $state */
    private static function notice(array $state, string $validUntil): string
    {
        return match (true) {
            $state['draft'] => t('Draft – not sent yet.'),
            $state['replaced_by'] !== null => t('This quotation was replaced by quotation %s.', $state['replaced_by']),
            $state['status'] === 'expired' => $validUntil !== '' ? t('This quotation expired on %s.', format_date($validUntil)) : t('This quotation has expired.'),
            $state['status'] === 'accepted' => t('This quotation was accepted.'),
            $state['status'] === 'declined' => t('This quotation was declined.'),
            $validUntil !== '' => t('This quotation is valid until %s.', format_date($validUntil)),
            default => '',
        };
    }

    /** A number in the current language's decimal separator ("2.5" → "2,5"). */
    private static function number(string $decimal): string
    {
        return str_replace('.', \Kaleta\Core\Deals::moneyFormat()['decimal'], $decimal);
    }

    /** Lines of an address block (the empty ones left out), the first one optionally as a name. @param list<string> $lines */
    private static function lines(array $lines, string $name = ''): string
    {
        $lines = array_values(array_filter(array_map('trim', $lines), fn (string $l): bool => $l !== ''));

        return ($name !== '' ? '<p class="quote-party-name">' . e($name) . '</p>' : '') . ($lines !== [] ? '<p class="quote-party">' . implode('<br>', array_map(fn (string $l): string => e($l), $lines)) . '</p>' : '');
    }

    /** A plain text as paragraphs (an empty line between them), line breaks kept. */
    private static function paragraphs(string $text): string
    {
        $out = '';
        foreach (preg_split('/\R{2,}/u', trim($text)) ?: [] as $p) {
            $out .= '<p>' . nl2br(e(trim($p)), false) . '</p>';
        }

        return $out;
    }
}
