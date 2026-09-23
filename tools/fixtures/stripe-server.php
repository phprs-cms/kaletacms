<?php
/**
 * Náhražka API Stripe pro tools/test.sh (php -S 127.0.0.1:<port> tools/fixtures/stripe-server.php) - testy tak neběží přes síť.
 * Umí jen tři volání, která dělá Core\Stripe, a hlídá jejich tvar: špatný požadavek vrací chybu jako skutečné API.
 * V adrese platební stránky vrací, co dostala, aby to test mohl ověřit z přesměrování.
 */

declare(strict_types=1);

header('Content-Type: application/json');
$cesta = (string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
$metoda = (string) $_SERVER['REQUEST_METHOD'];
$chyba = static function (int $kod, string $zprava): never {
    http_response_code($kod);
    echo json_encode(['error' => ['type' => 'invalid_request_error', 'message' => $zprava]]);
    exit;
};
if (!preg_match('/^Bearer (sk|rk)_test_[A-Za-z0-9]+$/', (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''))) {
    $chyba(401, 'Invalid API Key provided');
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}(\.[a-z]+)?$/', (string) ($_SERVER['HTTP_STRIPE_VERSION'] ?? ''))) {
    $chyba(400, 'Invalid Stripe API version');
}
if ($metoda === 'POST' && $cesta === '/v1/checkout/sessions') {
    $p = $_POST;
    if (($p['mode'] ?? '') !== 'subscription' || !isset($p['line_items'][0]['price'], $p['client_reference_id'], $p['success_url'], $p['cancel_url'])
        || ($p['line_items'][0]['quantity'] ?? '') !== '1' || ($p['subscription_data']['metadata']['idct'] ?? '') !== $p['client_reference_id'] || isset($p['customer']) === isset($p['customer_email'])) {
        $chyba(400, 'Missing required param');
    }
    echo json_encode(['id' => 'cs_test_nahrazka', 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_nahrazka?' . http_build_query([
        'ctenar' => $p['client_reference_id'], 'cena' => $p['line_items'][0]['price'], 'zakaznik' => $p['customer'] ?? $p['customer_email'], 'jazyk' => $p['locale'] ?? '', 'navrat' => $p['success_url'],
    ])]);
} elseif ($metoda === 'POST' && $cesta === '/v1/billing_portal/sessions') {
    if (!preg_match('/^cus_\w+$/', (string) ($_POST['customer'] ?? '')) || !isset($_POST['return_url'])) {
        $chyba(400, 'Missing required param: customer');
    }
    echo json_encode(['id' => 'bps_test_nahrazka', 'object' => 'billing_portal.session', 'url' => 'https://billing.stripe.com/p/session/test_nahrazka?zakaznik=' . $_POST['customer']]);
} elseif ($metoda === 'GET' && preg_match('#^/v1/subscriptions/(sub_\w+)$#', $cesta, $m)) {
    // čtenář právě v portálu předplatné zrušil ke konci období
    echo json_encode(['id' => $m[1], 'object' => 'subscription', 'status' => 'active', 'cancel_at_period_end' => true, 'customer' => 'cus_TestZakaznik1']);
} else {
    $chyba(404, 'Unrecognized request URL');
}
