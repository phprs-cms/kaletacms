<?php

declare(strict_types=1);

/* ---------- 3.51 B2: the brake – 5 failed attempts at the mail server among the last 50 since the start or the resume ---------- */
use Kaleta\Core\Mailing as B730Mailing;

$b730Row = fn (string $reply, bool $sent = false): array => ['sent_at' => $sent ? '2026-11-22 10:00:00' : null, 'reply' => $reply];
$b730Rows = fn (int $n, string $reply, bool $sent = false): array => array_fill(0, $n, $b730Row($reply, $sent));

check('3.51 B2: the record of one attempt – stage, reply code, enhanced code; no code is empty; at most 32 characters', [
    B730Mailing::reply(['ok' => false, 'stage' => 'rcpt', 'code' => 550, 'enhanced' => '5.1.1']), B730Mailing::reply(['ok' => false, 'stage' => 'connect', 'code' => 0, 'enhanced' => '']),
    B730Mailing::reply(['ok' => false, 'stage' => 'local', 'code' => 0, 'enhanced' => '']), B730Mailing::reply(['ok' => true, 'stage' => 'body', 'code' => 250, 'enhanced' => '2.0.0']),
    strlen(B730Mailing::reply(['stage' => str_repeat('x', 40), 'code' => 550, 'enhanced' => '5.1.1']))],
    ['rcpt:550:5.1.1', 'connect::', 'local::', 'body:250:2.0.0', 32]);
check('3.51 D4: an address refusal is a permanent reply at RCPT with 5.1.1, 5.1.2, 5.1.3, 5.1.6 or 5.1.10 – not the sender codes (5.1.0, 5.1.7, 5.1.8), not 5.7.1, not a temporary 4xx, not after DATA', array_map(B730Mailing::isAddressRefusal(...), [
    'rcpt:550:5.1.1', 'rcpt:553:5.1.2', 'rcpt:550:5.1.3', 'rcpt:550:5.1.6', 'rcpt:550:5.1.10', 'rcpt:550:5.1.0', 'rcpt:550:5.1.7', 'rcpt:550:5.1.8', 'rcpt:550:5.7.1', 'rcpt:451:5.1.1', 'data:550:5.1.1', 'rcpt:550:5.1.100', '']),
    [true, true, true, true, true, false, false, false, false, false, false, false, false]);
check('3.51 B2: brakeTripped – 5 failures trip, 4 do not; a retry that succeeded (the row is delivered) does not count; only BRAKE_WINDOW rows are looked at', [
    B730Mailing::brakeTripped($b730Rows(5, 'connect:421:4.7.0')), B730Mailing::brakeTripped($b730Rows(4, 'rcpt:550:5.7.1')),
    B730Mailing::brakeTripped([...$b730Rows(4, 'rcpt:451:4.7.0'), $b730Row('body:250:2.0.0', true)]),
    B730Mailing::brakeTripped([...$b730Rows(B730Mailing::BRAKE_WINDOW, 'body:250:2.0.0', true), ...$b730Rows(5, 'rcpt:550:5.7.1')]),
    [B730Mailing::BRAKE_WINDOW, B730Mailing::BRAKE_FAILURES]],
    [true, false, false, false, [50, 5]]);
check('3.51 D5: five address refusals among deliveries do not trip (a stale list); five with no delivery do; other failures among deliveries still do; stage local never counts; a row never tried (empty reply) never counts', [
    B730Mailing::brakeTripped([...$b730Rows(5, 'rcpt:550:5.1.1'), ...$b730Rows(15, 'body:250:2.0.0', true)]),
    B730Mailing::brakeTripped($b730Rows(5, 'rcpt:550:5.1.1')),
    B730Mailing::brakeTripped([...$b730Rows(5, 'rcpt:550:5.7.1'), ...$b730Rows(15, 'body:250:2.0.0', true)]),
    B730Mailing::brakeTripped([...$b730Rows(3, 'rcpt:550:5.1.1'), ...$b730Rows(2, 'data:554:5.6.0'), $b730Row('body:250:2.0.0', true)]),
    B730Mailing::brakeTripped($b730Rows(50, 'local::')),
    B730Mailing::brakeTripped([...$b730Rows(4, 'connect::'), ...$b730Rows(10, '')])],
    [false, true, true, false, false, false]);
check('3.51 review (third round): a row the brake put back is marked by its reply PUT_BACK – no attempt record looks like it (no colon), and the brake never counts it', [
    str_contains(B730Mailing::PUT_BACK, ':'), B730Mailing::PUT_BACK !== '',
    B730Mailing::brakeTripped($b730Rows(B730Mailing::BRAKE_FAILURES, B730Mailing::PUT_BACK)),
    B730Mailing::brakeTripped([...$b730Rows(4, 'connect::'), $b730Row(B730Mailing::PUT_BACK)])],
    [false, true, false, false]);
