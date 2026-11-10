<?php

declare(strict_types=1);

/* ---------- 2.15: guardrails for Claude and the reason of a change (Core\Guardrails) ---------- */
check('2.15 Guardrails::targetPage – a page tool with an id is that page; another build target or another tool is none', [
    Kaleta\Core\Guardrails::targetPage('uprav_stranku', ['id' => 12]), Kaleta\Core\Guardrails::targetPage('stavba_uloz', ['id' => '7']), Kaleta\Core\Guardrails::targetPage('stavba_uloz', ['id' => 7, 'komponenta' => 3]),
    Kaleta\Core\Guardrails::targetPage('stavba_uloz', ['cast' => 'hlavicka']), Kaleta\Core\Guardrails::targetPage('uprav_novinku', ['id' => 12]), Kaleta\Core\Guardrails::targetPage('publikuj_stavbu', ['id' => 0])],
    [12, 7, null, null, null, null]);
check('2.15 Guardrails::reason – one line of plain text, at most 255 characters; anything else is none', [
    Kaleta\Core\Guardrails::reason("  Request #4:\n<b>new</b> hours  "), mb_strlen(Kaleta\Core\Guardrails::reason(str_repeat('a', 400))), Kaleta\Core\Guardrails::reason(['x']), Kaleta\Core\Guardrails::reason(null)],
    ['Request #4: new hours', 255, '', '']);
$withReason = Kaleta\Mcp\Server::withReason(['name' => 'update_page', 'inputSchema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]]]);
check('2.15 Server::withReason – write tools offer a reason, read tools do not', [array_keys($withReason['inputSchema']['properties']),
    Kaleta\Mcp\Server::withReason(['name' => 'get_page', 'inputSchema' => ['type' => 'object', 'properties' => []]])['inputSchema']['properties']], [['id', 'reason'], []]);
