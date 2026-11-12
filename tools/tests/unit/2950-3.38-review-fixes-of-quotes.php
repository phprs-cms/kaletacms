<?php

declare(strict_types=1);

use Kaleta\Core\QuoteDelivery;

/* ---------- 3.38 review: the fixes of the security review of internal prices and quotes ---------- */

// L1: the customer's token is 32 random bytes per link – never derived from the quote, its expiry or the secret key
$r338Tokens = array_map(fn (int $i): string => QuoteDelivery::newToken(), range(1, 200));
check('3.38 review L1: a link token is 64 hex characters of fresh randomness – 200 links, 200 different tokens', [
    count(array_filter($r338Tokens, fn (string $t): bool => preg_match('/^[a-f0-9]{64}$/D', $t) === 1)), count(array_unique($r338Tokens)),
    (new ReflectionClass(QuoteDelivery::class))->hasMethod('token')], [200, 200, false]);

// L2: no address under /quote/ is ever kept among the addresses not found (a mangled link still carries its token)
check('3.38 review L2: every path under /quote/ is private – mangled, with a language prefix, in upper case; other addresses are not', array_map(Kaleta\Core\NotFound::isPrivate(...), [
    'quote/' . str_repeat('a', 64) . '/', '/quote/' . str_repeat('b', 63), 'en/quote/x', '/de-at/Quote/x', 'QUOTE/abc', 'quotes/x', 'kontakt', 'blog/quote/x', 'quote']),
    [true, true, true, true, true, false, false, false, false]);

// L4: Claude's cap counts what a line really gets – its own discount and the whole-quote discount together
check('3.38 review L4: the combined discount of a line – 20 % + 20 % is 36 %, 10 % + 10 % is 19 %, none is none, rounded up, bounded', array_map(
    fn (array $p): int => Kaleta\Core\Quotes::combinedDiscount($p[0], $p[1]), [[2000, 2000], [1000, 1000], [0, 0], [0, 2000], [1500, 1000], [3333, 1], [10000, 5000], [-5, 20000]]),
    [3600, 1900, 0, 2000, 2350, 3334, 10000, 10000]);

// L6: when the site deletes old records, an anonymised quote goes too (it is anonymised only once when the site anonymises)
check('3.38 review L6: the retention in delete mode takes anonymised quotes as well; in anonymise mode never twice', [
    QuoteDelivery::due($c338Quotes, 36, '2026-11-12 10:00:00', true), QuoteDelivery::due($c338Quotes, 36, '2026-11-12 10:00:00')],
    [['draft-old', 'sent-old', 'done'], ['draft-old', 'sent-old']]);

// L7: who may read prices and quotes – Prices::forItem, Quotes::get, Quotes::of and anything of QuoteDelivery are called only by
// the files below (a walk over the whole tree, aliases followed: a new caller such as Front\Seo or Core\Assistant fails here)
$r338Callers = static function (string $root): array {
    $found = [];
    foreach (['src', 'views', '../extensions'] as $dir) {
        if (!is_dir($root . '/' . $dir)) {
            continue;
        }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS)) as $file) {
            if (!str_ends_with((string) $file, '.php')) {
                continue;
            }
            $code = (string) file_get_contents((string) $file);
            $names = []; // local name => core class
            if (preg_match('/^namespace Kaleta\\\\Core;/m', $code) === 1) {
                $names += ['Prices' => 'Prices', 'Quotes' => 'Quotes', 'QuoteDelivery' => 'QuoteDelivery'];
            }
            preg_match_all('/^use Kaleta\\\\Core\\\\(Prices|Quotes|QuoteDelivery)(?: as (\w+))?;/m', $code, $uses, PREG_SET_ORDER);
            foreach ($uses as $u) {
                $names[($u[2] ?? '') !== '' ? $u[2] : $u[1]] = $u[1];
            }
            preg_match_all('/(\\\\?Kaleta\\\\Core\\\\(?:Prices|Quotes|QuoteDelivery)|\b\w+)::(\w+)\s*\(/', $code, $calls, PREG_SET_ORDER);
            foreach ($calls as $c) {
                $class = str_contains($c[1], '\\') ? substr($c[1], strrpos($c[1], '\\') + 1) : ($names[$c[1]] ?? '');
                if ($class === 'QuoteDelivery' || ($class === 'Quotes' && in_array($c[2], ['get', 'of'], true)) || ($class === 'Prices' && $c[2] === 'forItem')) {
                    $found[substr((string) $file, strlen($root) + 1)][] = $class . '::' . $c[2];
                }
            }
        }
    }
    ksort($found);

    return array_map(fn (array $m): array => array_values(array_unique($m)), $found);
};
$r338Allowed = ['src/Admin/Modules/Quotes.php', 'src/Admin/QuoteSending.php', 'src/Core/Contacts.php', 'src/Core/Deals.php', 'src/Core/Inbox.php',
    'src/Core/MonthlyReport.php', 'src/Core/Notifications.php', 'src/Core/PersonalData.php', 'src/Core/Privacy.php', 'src/Core/Prices.php', 'src/Core/QuoteDelivery.php',
    'src/Core/Quotes.php', 'src/Core/Scheduler.php', 'src/Front/QuotePage.php', 'src/Mcp/Confirmation.php', 'src/Mcp/Handlers/QuoteTools.php', // 3.39 review M4: the summary of send_quote's confirmation
    'views/admin/quotes/detail.php', 'views/admin/quotes/send.php', 'views/admin/quotes/texts.php',
    'src/Front/QuotePdf.php']; // 3.40 A: the quote as a PDF formats its amounts as the page does (QuoteDelivery::money)
$r338Found = $r338Callers(KALETA_SYSTEM);
check('3.38 review L7: Prices::forItem, Quotes::get / of and QuoteDelivery are called only by the quote screens, sending, privacy, retention and their own classes – never by the site, SEO, search or the assistant', [
    array_values(array_diff(array_keys($r338Found), $r338Allowed)), isset($r338Found['src/Admin/Modules/Quotes.php']), isset($r338Found['src/Front/QuotePage.php']),
    array_values(array_filter(array_keys($r338Found), fn (string $f): bool => preg_match('#^src/(Front/(?!QuotePage|QuotePdf)|Core/(Seo|Search|Assistant|SiteExport|Blueprint))#', $f) === 1))],
    [[], true, true, []]);
