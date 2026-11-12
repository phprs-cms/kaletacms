<?php

declare(strict_types=1);

namespace Kaleta\Front;

use Kaleta\Builder\DesignSystem;
use Kaleta\Core\App;
use Kaleta\Core\Language;
use Kaleta\Core\Money;
use Kaleta\Core\Pdf;
use Kaleta\Core\QuoteDelivery;
use Kaleta\Core\Response;

/**
 * The customer's quote as a PDF (3.40, design A7 "second release", A-9) – the same content as the page Front\QuotePage
 * draws from the frozen snapshot: the logo (a raster image), the supplier and customer blocks, the lines with their
 * header repeated on every page, the totals per VAT rate, the terms, the footer text and "Page x of y" on A4, in the
 * site's colours (the design system's text and primary colour) and the standard font closest to its fonts (serif or
 * sans-serif). Written by the small own writer Core\Pdf – no third-party library.
 *
 *  - A quote in a right-to-left language, or one whose text the standard fonts cannot draw (Greek, Cyrillic, Chinese …,
 *    Core\Pdf::supports), gets no PDF: render() returns null, the page offers only "Print or save as PDF" and the e-mail
 *    goes with the link alone. available() answers that from the text alone, without drawing (3.40 review L4: the
 *    customer's page decides whether to show the PDF link on every view).
 *  - Never public otherwise: the customer downloads it with the same token rules as the page (/quote/<token>.pdf,
 *    Front\QuotePage), the admin from the quote (Admin\QuoteSending, action pdf); always Content-Disposition attachment,
 *    never cached, noindex.
 */
final class QuotePdf
{
    private const float LEFT = 50.0;
    private const float RIGHT = 545.28;
    private const float TOP = 50.0;
    private const float BOTTOM = 780.0;
    private const float SIZE = 9.5;
    private const float LEADING = 13.0;

    private Pdf $pdf;
    private float $y = self::TOP;
    private string $regular;
    private string $bold;
    private string $headingBold;
    /** @var array{0: float, 1: float, 2: float} */
    private array $ink;
    /** @var array{0: float, 1: float, 2: float} */
    private array $muted;
    /** @var array{0: float, 1: float, 2: float} */
    private array $accent;
    /** @var array{0: float, 1: float, 2: float} */
    private array $rule;
    /** @var list<array{label: string, width: float}> the columns of the lines table */
    private array $columns = [];

    /**
     * The PDF of a snapshot in the quote's language, or null when the quote's language is right to left or its text cannot
     * be drawn with the standard fonts.
     *
     * @param array<string, mixed> $snapshot the frozen content (Quotes::snapshot; a draft's live one for the admin)
     * @param array{status: string, replaced_by: ?string, draft: bool} $state QuotePage::state
     */
    public static function render(App $app, array $snapshot, array $state): ?string
    {
        if (!self::available($app, $snapshot)) {
            return null;
        }
        $s = $app->settings();
        $language = Language::ofContent($s, (string) ($snapshot['language'] ?? ''));

        return Language::runWith($language, fn (): ?string => (new self($app, $snapshot, $language))->draw($app, $snapshot, $state), '', $s->get('german_register'));
    }

    /**
     * Can the snapshot be drawn as a PDF – without drawing it (3.40 review L4)? No for a right-to-left language; otherwise
     * every text of the snapshot, the site's name, an amount in its currency, a date and the labels in its language must
     * be drawable with the standard fonts (Core\Pdf::supports).
     *
     * @param array<string, mixed> $snapshot
     */
    public static function available(App $app, array $snapshot): bool
    {
        $s = $app->settings();
        $language = Language::ofContent($s, (string) ($snapshot['language'] ?? ''));
        if (Language::isRtl($language)) {
            return false;
        }

        return Language::runWith($language, function () use ($s, $snapshot): bool {
            $texts = [$s->get('site_name'), t('Quotation %s', '1'), t('Valid until'), t('Total including VAT'), t('Total without VAT'), t('Page %1$s of %2$s', '1', '2'), format_date('2026-12-31'),
                str_replace('₹', 'INR ', QuoteDelivery::money('1234.50', (string) ($snapshot['currency'] ?? '')))];
            array_walk_recursive($snapshot, function (mixed $v) use (&$texts): void {
                if (is_string($v)) {
                    $texts[] = $v;
                }
            });

            return Pdf::supports(implode(' ', $texts));
        }, '', $s->get('german_register'));
    }

    /**
     * What Core\QuoteDelivery::send attaches to the e-mail: the sent quote's PDF, or null when it has none.
     *
     * @return \Closure(array<string, mixed>): ?array{name: string, type: string, data: string}
     */
    public static function attachment(App $app): \Closure
    {
        return static function (array $quote) use ($app): ?array {
            $pdf = is_array($quote['snapshot'] ?? null) ? self::render($app, $quote['snapshot'], QuotePage::state($app, $quote)) : null;

            return $pdf !== null ? ['name' => self::filename(is_string($quote['number'] ?? null) ? $quote['number'] : null, (int) $quote['id']), 'type' => 'application/pdf', 'data' => $pdf] : null;
        };
    }

    /** The file name of a quote's PDF: its number ("2026-0012.pdf"), else "quote-<id>.pdf"; only letters, digits, dot, dash and underscore. */
    public static function filename(?string $number, int $id): string
    {
        $safe = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $number), '.-');

        return ($safe !== '' ? $safe : 'quote-' . $id) . '.pdf';
    }

    /** The download: always an attachment, never cached or indexed, no referrer. */
    public static function response(string $pdf, string $filename): Response
    {
        return new Response($pdf, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Content-Length' => (string) strlen($pdf), 'Cache-Control' => 'private, no-store', 'X-Robots-Tag' => 'noindex, nofollow', 'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; sandbox"]);
    }

    /** @param array<string, mixed> $snapshot */
    private function __construct(App $app, array $snapshot, string $language)
    {
        $ds = DesignSystem::load($app->settings());
        $text = self::family(DesignSystem::fontFamily($ds, (string) $ds['pismo_text'], false));
        $heading = self::family(DesignSystem::fontFamily($ds, (string) $ds['pismo_titulky'], true));
        $this->regular = Pdf::font($text);
        $this->bold = Pdf::font($text, true);
        $this->headingBold = Pdf::font($heading, true);
        $colors = is_array($ds['barvy'] ?? null) ? $ds['barvy'] : [];
        $ink = (string) ($colors['text'] ?? '#16181d');
        $ink = DesignSystem::contrast($ink, '#ffffff') >= 4.5 ? $ink : '#16181d'; // paper is white whatever the site's background
        $primary = (string) ($colors['primarni'] ?? '');
        $this->ink = Pdf::color($ink);
        $this->accent = preg_match('/^#[0-9a-f]{6}$/iD', $primary) === 1 && DesignSystem::contrast($primary, '#ffffff') >= 3 ? Pdf::color($primary) : $this->ink;
        $this->muted = self::blend($this->ink, 0.62);
        $this->rule = self::blend($this->ink, 0.2);
        $number = (string) ($snapshot['number'] ?? '');
        $this->pdf = new Pdf($number !== '' ? t('Quotation %s', $number) : t('Quotation'), (string) (($snapshot['supplier'] ?? [])['name'] ?? ''), Language::tag($language));
    }

    /** @param array<string, mixed> $snapshot @param array{status: string, replaced_by: ?string, draft: bool} $state */
    private function draw(App $app, array $snapshot, array $state): ?string
    {
        $s = $app->settings();
        $currency = (string) ($snapshot['currency'] ?? '');
        $money = fn (mixed $v): string => str_replace('₹', 'INR ', QuoteDelivery::money(is_scalar($v) ? (string) $v : null, $currency));
        $mode = (string) ($snapshot['vat_mode'] ?? 'standard');
        $vatColumns = ($snapshot['vat_payer'] ?? true) !== false && $mode !== 'none';
        $number = (string) ($snapshot['number'] ?? '');
        $heading = $number !== '' ? t('Quotation %s', $number) : t('Quotation');
        $supplier = is_array($snapshot['supplier'] ?? null) ? $snapshot['supplier'] : [];
        $customer = is_array($snapshot['customer'] ?? null) ? $snapshot['customer'] : [];
        $lines = array_values(array_filter(is_array($snapshot['lines'] ?? null) ? $snapshot['lines'] : [], 'is_array'));
        $totals = is_array($snapshot['totals'] ?? null) ? $snapshot['totals'] : [];
        $siteName = (string) ($supplier['name'] ?? '') !== '' ? (string) $supplier['name'] : $s->get('site_name');
        $pdf = $this->pdf;
        $pdf->addPage();

        // the head: logo and supplier on the left, the title and dates on the right
        $left = self::TOP;
        $logo = self::logo($s->get('logo'));
        if ($logo !== null && ($size = $pdf->image($logo, self::LEFT, $left, 160, 48)) !== null) {
            $left += $size[1] + 10;
        }
        $left = $this->block(self::LEFT, $left + 10, 250, [[$siteName, $this->bold, 11.0, $this->ink]], self::lines([
            trim((string) ($supplier['street'] ?? '')),
            trim(trim((string) ($supplier['postcode'] ?? '')) . ' ' . trim((string) ($supplier['city'] ?? ''))),
            trim((string) ($supplier['country'] ?? '')),
            (string) ($supplier['reg_no'] ?? '') !== '' ? t('Reg. No.: %s', (string) $supplier['reg_no']) : '',
            (string) ($supplier['vat_id'] ?? '') !== '' ? t('VAT No.: %s', (string) $supplier['vat_id']) : '',
            trim((string) ($supplier['register'] ?? '')),
            trim((string) ($supplier['phone'] ?? '')),
            trim((string) ($supplier['email'] ?? '')),
        ]), 8.5);
        $right = self::TOP + 16;
        foreach (Pdf::wrap($heading, $this->headingBold, 18, 240) as $row) {
            $pdf->text(self::RIGHT, $right, $row, $this->headingBold, 18, $this->accent, 'right');
            $right += 22;
        }
        foreach (Pdf::wrap(trim((string) ($snapshot['title'] ?? '')), $this->regular, 10.5, 240) as $row) {
            if ($row !== '') {
                $pdf->text(self::RIGHT, $right, $row, $this->regular, 10.5, $this->ink, 'right');
                $right += 14;
            }
        }
        $right += 4;
        foreach ([[t('Date'), (string) ($snapshot['sent_at'] ?? '')], [t('Valid until'), (string) ($snapshot['valid_until'] ?? '')]] as [$label, $date]) {
            if ($date !== '') {
                $pdf->text(self::RIGHT, $right, $label . ': ' . format_date($date), $this->regular, 9, $this->ink, 'right');
                $right += 13;
            }
        }
        $this->y = max($left, $right) + 14;

        $notice = self::notice($state, (string) ($snapshot['valid_until'] ?? ''));
        if ($notice !== '') {
            $warning = $state['status'] === 'expired' || $state['draft'];
            $pdf->rect(self::LEFT, $this->y - 11, self::RIGHT - self::LEFT, 17, $warning ? [1.0, 0.94, 0.92] : self::blend($this->ink, 0.06));
            $pdf->text(self::LEFT + 6, $this->y + 1, $notice, $this->bold, 9, $warning ? [0.7, 0.14, 0.09] : $this->ink);
            $this->y += 26;
        }

        // the customer
        $company = trim((string) ($customer['company'] ?? ''));
        $name = trim((string) ($customer['name'] ?? ''));
        $pdf->text(self::LEFT, $this->y, mb_strtoupper(t('Customer')), $this->bold, 7.5, $this->muted);
        $this->y = $this->block(self::LEFT, $this->y + 14, 300, [[$company !== '' ? $company : $name, $this->bold, 10.5, $this->ink]], self::lines([
            $company !== '' ? $name : '',
            ...preg_split('/\R/u', trim((string) ($customer['address'] ?? ''))) ?: [],
            (string) ($customer['reg_no'] ?? '') !== '' ? t('Reg. No.: %s', (string) $customer['reg_no']) : '',
            (string) ($customer['vat_id'] ?? '') !== '' ? t('VAT No.: %s', (string) $customer['vat_id']) : '',
            (string) ($customer['email'] ?? ''),
        ]), 9.0) + 12;

        $this->paragraphs((string) ($snapshot['intro'] ?? ''), $this->regular, self::SIZE, $this->ink);

        // the lines, their header on every page
        $discounts = array_filter($lines, fn (array $l): bool => Money::percent($l['discount_percent'] ?? '0') > 0) !== [];
        $this->columns = [['label' => t('Item'), 'width' => 0.0], ['label' => t('Quantity'), 'width' => 62.0], ['label' => t('Unit price'), 'width' => 78.0]];
        if ($discounts) {
            $this->columns[] = ['label' => t('Discount'), 'width' => 50.0];
        }
        if ($vatColumns) {
            $this->columns[] = ['label' => t('VAT'), 'width' => 40.0];
        }
        $this->columns[] = ['label' => t('Total'), 'width' => 84.0];
        $this->columns[0]['width'] = self::RIGHT - self::LEFT - array_sum(array_column($this->columns, 'width'));
        $this->y += 4;
        $this->tableHeader();
        $subtotal = 0;
        foreach ($lines as $l) {
            $subtotal += (int) Money::fromDecimal($l['line_net'] ?? null);
            $rate = $mode === 'reverse_charge' ? '0' : Money::short((string) ($l['vat_rate'] ?? '0'));
            $cells = [self::number(Money::short((string) ($l['quantity'] ?? '1'))) . ((string) ($l['unit'] ?? '') !== '' ? "\u{00A0}" . (string) $l['unit'] : ''), $money($l['unit_price'] ?? null)];
            if ($discounts) {
                $cells[] = Money::percent($l['discount_percent'] ?? '0') > 0 ? self::number(Money::short((string) $l['discount_percent'])) . "\u{00A0}%" : '';
            }
            if ($vatColumns) {
                $cells[] = self::number($rate) . "\u{00A0}%";
            }
            $cells[] = $money($l['line_net'] ?? null);
            $this->row((string) ($l['name'] ?? ''), (string) ($l['code'] ?? ''), (string) ($l['description'] ?? ''), $cells);
        }
        $this->y += 8;

        // the totals: the sum of the lines, the whole-quote discount, VAT per rate, the total
        $net = (int) Money::fromDecimal($totals['net'] ?? '0');
        $rows = [];
        $discountPoints = Money::percent($snapshot['discount_percent'] ?? '0');
        if ($discountPoints > 0) {
            $rows[] = [t('Subtotal'), $money(Money::toDecimal($subtotal)), false];
            $rows[] = [t('Discount %s %%', self::number(Money::short((string) $snapshot['discount_percent']))), $money('-' . Money::toDecimal(max(0, $subtotal - $net))), false];
        }
        if ($vatColumns) {
            $rows[] = [t('Total without VAT'), $money($totals['net'] ?? null), false];
            foreach (array_values(array_filter(is_array($totals['rates'] ?? null) ? $totals['rates'] : [], 'is_array')) as $r) {
                $rows[] = [$mode === 'reverse_charge' ? t('VAT 0 % (reverse charge)') : t('VAT %s %% of %s', self::number(Money::short((string) ($r['rate'] ?? '0'))), $money((string) ($r['net'] ?? '0'))),
                    $money($mode === 'reverse_charge' ? '0.00' : ($r['vat'] ?? '0')), false];
            }
            $rows[] = [t('Total including VAT'), $money($totals['gross'] ?? null), true];
        } else {
            $rows[] = [t('Total'), $money($totals['net'] ?? null), true];
        }
        $this->room(count($rows) * 16 + 30);
        $x = self::RIGHT - 270;
        foreach ($rows as [$label, $amount, $grand]) {
            if ($grand) {
                $pdf->line($x, $this->y - 9, self::RIGHT, $this->y - 9, 1.0, $this->accent);
                $this->y += 6;
            }
            $font = $grand ? $this->bold : $this->regular;
            $size = $grand ? 11.0 : self::SIZE;
            $pdf->text($x, $this->y, $label, $font, $size, $this->ink);
            $pdf->text(self::RIGHT, $this->y, $amount, $font, $size, $this->ink, 'right');
            $this->y += $grand ? 18 : 15;
        }
        $this->y += 4;
        $this->paragraphs(match (true) {
            !$vatColumns && ($snapshot['vat_payer'] ?? true) === false => t('We are not a VAT payer.'),
            !$vatColumns => t('The prices are final, without VAT.'),
            $mode === 'reverse_charge' => t('Reverse charge: the customer accounts for the VAT. Prices are without VAT.'),
            default => t('Prices are without VAT; VAT is computed per rate.'),
        }, $this->regular, 8.0, $this->muted);

        if (trim((string) ($snapshot['terms'] ?? '')) !== '') {
            $this->y += 6;
            $this->room(40);
            $pdf->text(self::LEFT, $this->y, t('Terms'), $this->headingBold, 11, $this->ink);
            $this->y += 16;
            $this->paragraphs((string) $snapshot['terms'], $this->regular, self::SIZE, $this->ink);
        }
        if (trim((string) ($snapshot['footer'] ?? '')) !== '') {
            $this->y += 8;
            $this->room(20);
            $pdf->line(self::LEFT, $this->y - 10, self::RIGHT, $this->y - 10, 0.5, $this->rule);
            $this->paragraphs((string) $snapshot['footer'], $this->regular, 8.0, $this->muted);
        }

        // page numbers and the quote's name at the foot of every page, once the number of pages is known
        $count = $pdf->pageCount();
        for ($page = 0; $page < $count; $page++) {
            $pdf->onPage($page);
            $pdf->line(self::LEFT, 800, self::RIGHT, 800, 0.5, $this->rule);
            $pdf->text(self::LEFT, 812, $heading . ' – ' . $siteName, $this->regular, 7.5, $this->muted);
            $pdf->text(self::RIGHT, 812, t('Page %1$s of %2$s', (string) ($page + 1), (string) $count), $this->regular, 7.5, $this->muted, 'right');
        }
        $out = $pdf->output();

        return $pdf->missing === 0 ? $out : null;
    }

    /** The header row of the lines table (again at the top of every following page). */
    private function tableHeader(): void
    {
        $x = self::LEFT;
        foreach ($this->columns as $i => $column) {
            $this->pdf->text($i === 0 ? $x : $x + $column['width'], $this->y, $column['label'], $this->bold, 8.0, $this->muted, $i === 0 ? 'left' : 'right');
            $x += $column['width'];
        }
        $this->pdf->line(self::LEFT, $this->y + 5, self::RIGHT, $this->y + 5, 1.0, $this->accent);
        $this->y += 18;
    }

    /**
     * One line of the quote: the name (and code) and its description in the first column, the figures right aligned; a
     * new page with the header when it does not fit, a very long description continues on the next page.
     *
     * @param list<string> $cells
     */
    private function row(string $name, string $code, string $description, array $cells): void
    {
        $width = $this->columns[0]['width'] - 10;
        $nameRows = Pdf::wrap($name . ($code !== '' ? "\u{00A0}\u{00A0}" . $code : ''), $this->regular, self::SIZE, $width);
        $descriptionRows = trim($description) !== '' ? Pdf::wrap($description, $this->regular, 8.0, $width) : [];
        $height = count($nameRows) * self::LEADING + count($descriptionRows) * 10.5;
        if ($this->y + min($height, 120) > self::BOTTOM) {
            $this->newPage(true);
        }
        $x = self::LEFT + $this->columns[0]['width'];
        foreach ($cells as $i => $cell) {
            $x += $this->columns[$i + 1]['width'];
            $this->pdf->text($x, $this->y, $cell, $this->regular, self::SIZE, $this->ink, 'right');
        }
        foreach ($nameRows as $i => $row) {
            // the code is drawn muted after the name when both fit one line
            if ($i === count($nameRows) - 1 && $code !== '' && str_ends_with($row, "\u{00A0}\u{00A0}" . $code)) {
                $plain = mb_substr($row, 0, mb_strlen($row) - mb_strlen($code));
                $this->pdf->text(self::LEFT, $this->y, $plain, $this->regular, self::SIZE, $this->ink);
                $this->pdf->text(self::LEFT + Pdf::width($plain, $this->regular, self::SIZE), $this->y, $code, $this->regular, 8.0, $this->muted);
            } else {
                $this->pdf->text(self::LEFT, $this->y, $row, $this->regular, self::SIZE, $this->ink);
            }
            $this->y += self::LEADING;
        }
        foreach ($descriptionRows as $row) {
            if ($this->y > self::BOTTOM) {
                $this->newPage(true);
            }
            $this->pdf->text(self::LEFT, $this->y - 2, $row, $this->regular, 8.0, $this->muted);
            $this->y += 10.5;
        }
        $this->pdf->line(self::LEFT, $this->y - 8, self::RIGHT, $this->y - 8, 0.4, $this->rule);
        $this->y += 6;
    }

    /** Plain text as paragraphs (an empty line between them), wrapped to the page, continuing on new pages. @param array{0: float, 1: float, 2: float} $color */
    private function paragraphs(string $text, string $font, float $size, array $color): void
    {
        $text = trim($text);
        if ($text === '') {
            return;
        }
        $leading = $size * 1.4;
        foreach (preg_split('/\R{2,}/u', $text) ?: [] as $paragraph) {
            foreach (Pdf::wrap(trim($paragraph), $font, $size, self::RIGHT - self::LEFT) as $row) {
                $this->room($leading);
                $this->pdf->text(self::LEFT, $this->y, $row, $font, $size, $color);
                $this->y += $leading;
            }
            $this->y += $size * 0.6;
        }
    }

    /**
     * A block of a name and lines (the supplier, the customer) from ($x, $y); returns the y under it.
     *
     * @param list<array{0: string, 1: string, 2: float, 3: array{0: float, 1: float, 2: float}}> $head
     * @param list<string> $lines
     */
    private function block(float $x, float $y, float $width, array $head, array $lines, float $size): float
    {
        foreach ($head as [$text, $font, $headSize, $color]) {
            foreach ($text !== '' ? Pdf::wrap($text, $font, $headSize, $width) : [] as $row) {
                $this->pdf->text($x, $y, $row, $font, $headSize, $color);
                $y += $headSize * 1.35;
            }
        }
        foreach ($lines as $line) {
            foreach (Pdf::wrap($line, $this->regular, $size, $width) as $row) {
                $this->pdf->text($x, $y, $row, $this->regular, $size, $this->muted);
                $y += $size * 1.4;
            }
        }

        return $y;
    }

    /** A new page when less than $height is left on this one. */
    private function room(float $height): void
    {
        if ($this->y + $height > self::BOTTOM) {
            $this->newPage(false);
        }
    }

    private function newPage(bool $withHeader): void
    {
        $this->pdf->addPage();
        $this->y = self::TOP + 10;
        if ($withHeader) {
            $this->tableHeader();
        }
    }

    /** The status line, as the page says it. @param array{status: string, replaced_by: ?string, draft: bool} $state */
    private static function notice(array $state, string $validUntil): string
    {
        return match (true) {
            $state['draft'] => t('Draft – not sent yet.'),
            $state['replaced_by'] !== null => t('This quotation was replaced by quotation %s.', $state['replaced_by']),
            $state['status'] === 'expired' => $validUntil !== '' ? t('This quotation expired on %s.', format_date($validUntil)) : t('This quotation has expired.'),
            $state['status'] === 'accepted' => t('This quotation was accepted.'),
            $state['status'] === 'declined' => t('This quotation was declined.'),
            default => '',
        };
    }

    /** The site's logo file when it is a raster image under media/, or null (an SVG, an outside address, a missing file). */
    private static function logo(string $path): ?string
    {
        if ($path === '' || preg_match('#^(https?:)?//#i', $path) === 1 || preg_match('/\.(png|jpe?g|gif|webp)$/iD', $path) !== 1) {
            return null;
        }
        $media = realpath(KALETA_ROOT . '/media');
        $file = realpath(KALETA_ROOT . '/' . ltrim($path, '/'));
        if ($media === false || $file === false || !str_starts_with($file, $media . DIRECTORY_SEPARATOR) || !is_file($file) || filesize($file) > Pdf::IMAGE_BYTES) {
            return null;
        }
        $bytes = file_get_contents($file);

        return is_string($bytes) ? $bytes : null;
    }

    /** The PDF family closest to a CSS font stack: serif, monospace or sans-serif. */
    private static function family(string $stack): string
    {
        return match (true) {
            str_ends_with(trim($stack), 'monospace') => 'mono',
            str_ends_with(trim($stack), 'serif') && !str_ends_with(trim($stack), 'sans-serif') => 'serif',
            default => 'sans',
        };
    }

    /** @param array{0: float, 1: float, 2: float} $color @return array{0: float, 1: float, 2: float} a colour mixed with white (share = how much of the colour stays) */
    private static function blend(array $color, float $share): array
    {
        return [$color[0] * $share + (1 - $share), $color[1] * $share + (1 - $share), $color[2] * $share + (1 - $share)];
    }

    /** A number in the current language's decimal separator. */
    private static function number(string $decimal): string
    {
        return str_replace('.', \Kaleta\Core\Deals::moneyFormat()['decimal'], $decimal);
    }

    /** @param list<string> $lines @return list<string> the lines that are not empty */
    private static function lines(array $lines): array
    {
        return array_values(array_filter(array_map('trim', $lines), fn (string $l): bool => $l !== ''));
    }
}
