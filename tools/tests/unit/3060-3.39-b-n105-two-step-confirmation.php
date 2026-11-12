<?php

declare(strict_types=1);

use Kaleta\Mcp\Catalog;
use Kaleta\Mcp\Confirmation;

/* ---------- 3.39 B (N105 part 2): two-step confirmation of the tools that e-mail outside the team or erase for good ---------- */

check('3.39 N105: when a call acts – confirm=true, send, every newsletter call but unscheduling, every delete_enquiry; other tools never', [
    Confirmation::needed('reply_to_enquiry', ['id' => 1, 'confirm' => true]), Confirmation::needed('reply_to_enquiry', ['id' => 1]), Confirmation::needed('reply_to_enquiry', ['confirm' => 'true']),
    Confirmation::needed('send_quote', ['confirm' => false]), Confirmation::needed('request_testimonial', ['send' => true]), Confirmation::needed('request_testimonial', ['send' => false]),
    Confirmation::needed('send_newsletter', ['id' => 3]), Confirmation::needed('send_newsletter', ['id' => 3, 'unschedule' => true]), Confirmation::needed('delete_enquiry', ['id' => 2]),
    Confirmation::needed('delete_popup', ['id' => 2]), Confirmation::needed('update_settings', ['confirm' => true]),
], [true, false, false, false, true, false, true, false, true, false, false]);
check('3.39 N105: every tool of the list is a site tool that writes, and every tool that e-mails people outside the team is on it', [
    array_values(array_filter(array_keys(Confirmation::TOOLS), fn (string $t): bool => !in_array(Catalog::access($t), ['write', 'destructive'], true))),
    array_values(array_diff(array_diff(Catalog::OPEN_WORLD, ['upload_file', 'import_website', 'migration_report', 'read_old_page', 'import_wordpress', 'save_collection_items']), array_keys(Confirmation::TOOLS))),
], [[], []]);
$n105Key = bin2hex(random_bytes(32));
$n105Now = 1_900_000_000;
$n105Args = ['id' => 7, 'text' => 'Hello', 'confirm' => true, 'nested' => ['b' => 2, 'a' => 1]];
$n105Code = Confirmation::code($n105Key, 'reply_to_enquiry', 3, 'client:abc', $n105Args, $n105Now + 600);
check('3.39 N105: the code fits only the same tool, user, connection and arguments (key order, the reason and the code itself do not count)', [
    Confirmation::valid($n105Key, $n105Code, 'reply_to_enquiry', 3, 'client:abc', ['nested' => ['a' => 1, 'b' => 2], 'confirm' => true, 'text' => 'Hello', 'id' => 7, 'reason' => 'asked', 'confirmation' => 'x'], $n105Now),
    Confirmation::valid($n105Key, $n105Code, 'reply_to_enquiry', 3, 'client:abc', ['text' => 'Hello!'] + $n105Args, $n105Now),
    Confirmation::valid($n105Key, $n105Code, 'reply_to_enquiry', 4, 'client:abc', $n105Args, $n105Now),
    Confirmation::valid($n105Key, $n105Code, 'reply_to_enquiry', 3, 'token:9', $n105Args, $n105Now),
    Confirmation::valid($n105Key, $n105Code, 'reply_to_application', 3, 'client:abc', $n105Args, $n105Now),
    Confirmation::valid(bin2hex(random_bytes(32)), $n105Code, 'reply_to_enquiry', 3, 'client:abc', $n105Args, $n105Now),
], [true, false, false, false, false, false]);
check('3.39 N105: the code works for ten minutes – not after its expiry, never one that claims a later expiry, never a malformed one', [
    Confirmation::valid($n105Key, $n105Code, 'reply_to_enquiry', 3, 'client:abc', $n105Args, $n105Now + 600),
    Confirmation::valid($n105Key, $n105Code, 'reply_to_enquiry', 3, 'client:abc', $n105Args, $n105Now + 601),
    Confirmation::valid($n105Key, Confirmation::code($n105Key, 'reply_to_enquiry', 3, 'client:abc', $n105Args, $n105Now + 3600), 'reply_to_enquiry', 3, 'client:abc', $n105Args, $n105Now),
    Confirmation::valid($n105Key, 'yes', 'reply_to_enquiry', 3, 'client:abc', $n105Args, $n105Now),
    preg_match('/^\d{10}-[a-f0-9]{8}-[a-f0-9]{32}$/D', $n105Code), // a fresh nonce: two codes for the same call differ
    $n105Code !== Confirmation::code($n105Key, 'reply_to_enquiry', 3, 'client:abc', $n105Args, $n105Now + 600),
], [true, false, false, false, 1, true]);
$n105Answer = Confirmation::answer('delete_enquiry', $n105Code, true);
$n105Listed = Kaleta\Mcp\Server::withConfirmation(['name' => 'reply_to_enquiry', 'inputSchema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]]]);
check('3.39 N105: the answer does nothing and says so (a refused code says why), and tools/list adds the optional confirmation parameter only to these tools', [
    $n105Answer['confirmation_required'], $n105Answer['done'], isset($n105Answer['confirmation_refused']), $n105Answer['confirmation'], str_contains($n105Answer['next'], 'never the user\'s yes'),
    array_keys($n105Listed['inputSchema']['properties']), Kaleta\Mcp\Server::withConfirmation(['name' => 'list_pages', 'inputSchema' => ['type' => 'object', 'properties' => []]])['inputSchema']['properties'],
    substr_count(Kaleta\Mcp\Translator::instructions(), Confirmation::INSTRUCTION),
], [true, false, true, $n105Code, true, ['id', 'confirmation'], [], 1]);
