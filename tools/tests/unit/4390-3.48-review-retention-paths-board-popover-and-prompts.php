<?php

declare(strict_types=1);

use Kaleta\Admin\Modules\Settings as SettingsModule;
use Kaleta\Core\Retention;

/* ---------- 3.48 review: what the Settings save defers the retention screen can confirm, the moved applications, the board's popover, the prompts ---------- */

// H (off-site backups): a period whose shorter value actionSave sends to the confirmation screen is one that screen parses –
// otherwise the value is neither saved nor confirmable (backup_remote_days, set on the Backups tab, went nowhere)
$r348Lost = [];
foreach (Retention::PERIODS as $r348Row) {
    if ($r348Row['setting'] === '') {
        continue;
    }
    $r348Valid = (string) max($r348Row['min'], 1);
    [$r348Proposed] = Retention::parse([$r348Row['setting'] => $r348Valid]);
    if (Retention::confirmable($r348Row['setting']) !== isset($r348Proposed[$r348Row['setting']])) {
        $r348Lost[] = $r348Row['setting'];
    }
}
check('3.48 review: every period deferred to the retention screen is one that screen parses; off-site backups are saved from their own tab', [
    $r348Lost, Retention::confirmable('backup_remote_days'), Retention::confirmable('enquiries_expiry'), Retention::confirmable('enquiries_months'), Retention::parse(['enquiries_expiry' => 'delete'])[0]],
    [[], false, true, true, ['enquiries_expiry' => 'delete']]);

// M (moved applications): a save of the Privacy tab confirms their retention only when the administrator acted on that period
check('3.48 review: the Privacy tab confirms the moved applications only for the ticked confirmation or a changed period, never with an invalid field, never while a shorter one waits', [
    SettingsModule::confirmsApplications('cookies', [], false, null, '6', false), // another field only (feature off: no row)
    SettingsModule::confirmsApplications('cookies', [], false, '6', '6', false), // the row posted unchanged
    SettingsModule::confirmsApplications('cookies', [], true, '6', '6', false), // ticked
    SettingsModule::confirmsApplications('cookies', [], false, '12', '6', false), // a longer period saved
    SettingsModule::confirmsApplications('cookies', ['captcha_site_key'], true, '6', '6', false), // ticked, the save had an invalid field
    SettingsModule::confirmsApplications('cookies', [], true, '3', '6', true), // shorter: confirmed on its own screen
    SettingsModule::confirmsApplications('general', [], true, '6', '6', false)],
    [false, false, true, true, false, false, false]);

// H (the board): a popover the board script resolves by selector is the only element with that attribute – a chip that
// carries it as well comes first in the page and throws on showPopover (the reason chip of a rejected application)
preg_match_all("/popover: '\\[(data-[a-z-]+)\\]'/", (string) file_get_contents(KALETA_ROOT . '/image/admin.js'), $r348Pops);
$r348Clash = [];
foreach ($r348Pops[1] as $r348Attr) {
    foreach ([...glob(KALETA_SYSTEM . '/views/admin/*.php') ?: [], ...glob(KALETA_SYSTEM . '/views/admin/*/*.php') ?: []] as $r348View) {
        preg_match_all('/<[a-z]+\b[^>]*\b' . preg_quote($r348Attr, '/') . '(?=[\s>=])[^>]*>/', (string) file_get_contents($r348View), $r348Tags);
        foreach ($r348Tags[0] as $r348Tag) {
            if (!preg_match('/\spopover(?=[\s>=])/', $r348Tag)) {
                $r348Clash[] = basename(dirname($r348View)) . '/' . basename($r348View) . ': ' . $r348Attr;
            }
        }
    }
}
check('3.48 review: each board popover selector of admin.js matches popovers only (two of them)', [count($r348Pops[1]), $r348Clash], [2, []]);

// L (set_up_quotes): the runbook works with Customers off – every sentence naming save_contact says it needs Customers
$r348Prompt = Kaleta\Mcp\Prompts::get('set_up_quotes', [])['messages'][0]['content']['text'];
$r348Sentences = array_filter(preg_split('/(?<=[.!?])\s+/', (string) preg_replace('/\s+/', ' ', $r348Prompt)) ?: [], fn (string $x): bool => str_contains($x, '`save_contact`')); // the runbook's own text (the runner list at the end names every tool)
check('3.48 review: set_up_quotes – the first test quote takes a customer by name and e-mail; save_contact only where Customers is on', [
    str_contains($r348Prompt, '`save_quote` with a `customer`'), array_values(array_filter($r348Sentences, fn (string $x): bool => !str_contains($x, 'Customers')))], [true, []]);
