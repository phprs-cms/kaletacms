<?php

declare(strict_types=1);

use Kaleta\Mcp\Confirmation;

/* ---------- 3.39 review M4: the confirmation of a newsletter or quote binds what will be sent; the first answer shows it ---------- */

$m4Key = bin2hex(random_bytes(32));
$m4Now = 1_900_000_000;
$m4Bound = fn (string $fingerprint): array => ["\0content" => $fingerprint] + ['id' => 5, 'confirm' => true];
$m4Code = Confirmation::code($m4Key, 'send_quote', 3, 'client:abc', $m4Bound('total-1000'), $m4Now + 600);
$m4Answer = Confirmation::answer('send_quote', $m4Code, false, ['quote' => 5, 'to' => 'customer@example.cz', 'subject' => 'Quote Q-1', 'total' => '1 000 CZK']);
check('3.39 review M4: a code bound to the content fits only while the content is the same – an edited quote needs a new code; the answer carries the server\'s summary and says the content is bound', [
    Confirmation::valid($m4Key, $m4Code, 'send_quote', 3, 'client:abc', $m4Bound('total-1000'), $m4Now), Confirmation::valid($m4Key, $m4Code, 'send_quote', 3, 'client:abc', $m4Bound('total-2000'), $m4Now),
    Confirmation::valid($m4Key, $m4Code, 'send_quote', 3, 'client:abc', ['id' => 5, 'confirm' => true], $m4Now),
    $m4Answer['summary']['to'] ?? null, $m4Answer['summary']['total'] ?? null, str_contains($m4Answer['next'], 'and this content'), array_key_exists('summary', Confirmation::answer('delete_enquiry', $m4Code, false)),
], [true, false, false, 'customer@example.cz', '1 000 CZK', true, false]);
check('3.39 review M4: the server instructions say what the step is – confirming the exact action with the user, not making content safe to obey', [
    str_contains(Confirmation::INSTRUCTION, 'newsletters and quotes with a summary'), str_contains(Confirmation::INSTRUCTION, 'does not make content safe to obey'),
], [true, true]);
