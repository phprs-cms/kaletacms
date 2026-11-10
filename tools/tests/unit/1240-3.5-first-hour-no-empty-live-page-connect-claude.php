<?php

declare(strict_types=1);

/* ---------- 3.5 "First hour": no empty live page, Connect Claude first, the Bookings set-up ---------- */
$pagesModule = Kaleta\Admin\Modules\Pages::class;
check('3.5 UXA-02 Pages::visibility: a page with nothing to show waits hidden for its build; a visible page and hiding are unchanged', [
    $pagesModule::visibility(true, false, false), $pagesModule::visibility(true, false, true), $pagesModule::visibility(true, true, false), $pagesModule::visibility(false, false, false)],
    [['zobrazit' => 0, 'show_on_publish' => 1], ['zobrazit' => 1, 'show_on_publish' => 0], ['zobrazit' => 1, 'show_on_publish' => 0], ['zobrazit' => 0, 'show_on_publish' => 0]]);
check('3.5 UXA-02 Pages::hasContent: a published build or text counts, an image too; empty paragraphs do not', [
    $pagesModule::hasContent(['stavba' => null, 'text' => '']), $pagesModule::hasContent(['stavba' => null, 'text' => "<p> </p>\n<p></p>"]),
    $pagesModule::hasContent(['stavba' => null, 'text' => '<p><img src="/media/a.jpg" alt=""></p>']), $pagesModule::hasContent(['stavba' => '{"v":1,"deti":[]}', 'text' => '']),
    $pagesModule::hasContent(['stavba' => null, 'text' => '<p>Hello</p>'])], [false, false, true, true, true]);
$migration79 = (string) file_get_contents(KALETA_SYSTEM . '/sql/migrace/0080-page-show-on-publish.sql');
check('3.5 UXA-02: migration 0080 adds show_on_publish (existing pages 0 = as before), as the schema does; the first published build applies it', [
    KALETA_DB_VERSION >= 79, str_contains($migration79, 'ADD COLUMN show_on_publish BOOL NOT NULL DEFAULT 0 AFTER zobrazit'), str_contains($schemaSql, 'show_on_publish BOOL NOT NULL DEFAULT 0'),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Builder/Publisher.php'), 'SET zobrazit = 1, show_on_publish = 0, zverejnit_od = NULL WHERE ids = ? AND show_on_publish = 1')],
    [true, true, true, true]);
check('3.5 UXA-07/20: the connector address needs HTTPS; the first MCP call is remembered, a token nobody used is not a connection', [
    Kaleta\Core\AskClaude::secure('https://example.com/mcp'), Kaleta\Core\AskClaude::secure('http://example.com/mcp'), Kaleta\Core\Settings::DEFAULTS['claude_first_used'],
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Mcp/Server.php'), "set('claude_first_used', date('Y-m-d H:i:s'))"),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Core/AskClaude.php'), "WHERE pouzit IS NOT NULL OR druh = 'obnova'")],
    [true, false, '', true, true]);
$starterHours = array_map(static fn (array $ranges): string => Kaleta\Core\Hours::rangesText($ranges), Kaleta\Core\Booking::STARTER_HOURS);
check('3.5 UXA-08: a new person on a site without opening hours starts Mon–Fri 9–17, and the hours pass the person form', [
    array_keys(Kaleta\Core\Booking::STARTER_HOURS), Kaleta\Core\Booking::parseHours($starterHours) === Kaleta\Core\Booking::STARTER_HOURS, Kaleta\Core\Booking::FREE_DAYS],
    [[1, 2, 3, 4, 5], true, 14]);

