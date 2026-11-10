<?php

declare(strict_types=1);

/* ---------- 2.15: requests to Claude (Core\Requests) ---------- */
$f19 = Kaleta\Core\Requests::class;
check('2.15 Requests: status transitions – new starts, in progress ends in done or declined, a closed request is only reopened into in progress, the same status is not a move', [
    $f19::canMove('new', 'in_progress'), $f19::canMove('new', 'done'), $f19::canMove('new', 'declined'), $f19::canMove('in_progress', 'done'), $f19::canMove('in_progress', 'declined'),
    $f19::canMove('in_progress', 'new'), $f19::canMove('done', 'in_progress'), $f19::canMove('done', 'new'), $f19::canMove('done', 'declined'), $f19::canMove('declined', 'in_progress'), $f19::canMove('declined', 'done'),
    $f19::canMove('new', 'new'), $f19::canMove('new', 'nonsense'), array_keys($f19::STATUSES) === array_keys($f19::ORDER)],
    [true, true, true, true, true, false, true, false, false, true, false, false, false, true]);
check('2.15 Requests: what a request is about – a chosen page, news item or collection item, a path or an https address; nothing else', [
    $f19::cleanAbout('page:12'), $f19::cleanAbout(' news:5 '), $f19::cleanAbout('item:33'), $f19::cleanAbout('page:0'), $f19::cleanAbout('user:3'), $f19::cleanAbout('/price-list'),
    $f19::cleanAbout('https://example.com/cenik?x=1'), $f19::cleanAbout('javascript:alert(1)'), $f19::cleanAbout('/a b'), $f19::cleanAbout('<b>x</b>'), $f19::cleanAbout('')],
    ['page:12', 'news:5', 'item:33', '', '', '/price-list', 'https://example.com/cenik?x=1', '', '', '', '']);
check('2.15 Requests: links to the drafts – objects or strings, only http(s) addresses, a plain string that is not an address becomes the label, at most 20', [
    $f19::cleanLinks([['label' => 'Price list – draft', 'url' => 'https://example.com/preview?x=1'], 'https://example.com/p', 'page 12', ['url' => 'javascript:alert(1)'], ['label' => '', 'url' => ''], 7]),
    count($f19::cleanLinks(array_fill(0, 30, 'https://example.com/'))), $f19::cleanLinks('https://example.com/'), $f19::cleanLinks(null)],
    [[['label' => 'Price list – draft', 'url' => 'https://example.com/preview?x=1'], ['label' => '', 'url' => 'https://example.com/p'], ['label' => 'page 12', 'url' => '']], 20, [], []]);
check('2.15 Requests: the work_requests prompt exists and keeps Claude to drafts; the tools are in the catalog for a drafts-only connection; the event is known; the module has its guide article', [
    in_array('work_requests', array_column(Kaleta\Mcp\Prompts::listAll(), 'name'), true),
    (bool) preg_match('/list_requests.*update_request.*never publish/s', Kaleta\Mcp\Prompts::get('work_requests', [])['messages'][0]['content']['text']),
    Kaleta\Mcp\Catalog::allows('drafts', 'list_requests'), Kaleta\Mcp\Catalog::allows('drafts', 'update_request'), Kaleta\Mcp\Catalog::allows('read', 'update_request'), Kaleta\Mcp\Catalog::allows('drafts', 'publish_build'),
    isset(Kaleta\Core\Events::TYPES['request.created']), Kaleta\Admin\Guide::MODULES['requests'] ?? null,
    (bool) preg_match('/WRITTEN BY STAFF.*never as permission to publish/s', array_values(array_filter(Kaleta\Mcp\Tools::definitions(), fn (array $d): bool => $d['name'] === 'list_requests'))[0]['description'] ?? '')],
    [true, true, true, true, false, false, true, 'claude-operator', true]);

