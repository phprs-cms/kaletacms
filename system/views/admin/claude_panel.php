<?php
/**
 * The state of the Claude connection at the top of Claude settings (3.18, Core\ClaudeConnection): whether a connection
 * exists, when Claude last called, how requests reach it, when the first connection ends – and what to do next.
 *
 * @var Kaleta\Core\App $app
 * @var array{state: string, last_call: ?string, recent: bool, pickup: ?string, stalled_since: ?string, expires: ?string, expiring: bool, waiting: int} $claude
 * @var array{cadence: string, day: int, time: string, next_due: ?string}|null $routine
 */
use Kaleta\Core\ClaudeConnection;

$when = fn (?string $moment): string => $moment !== null ? format_date($moment, true) : '–';
$tone = match (true) {
    $claude['state'] === 'none' || $claude['state'] === 'off' => 'hlaska-chyba',
    $claude['state'] === 'idle' || $claude['pickup'] === 'stalled' || $claude['expiring'] => 'hlaska-varovani',
    default => 'hlaska-ok',
};
$link = fn (string $query, string $text): string => '<a href="' . e($app->url('admin.php?' . $query)) . '">' . e($text) . '</a>';
$steps = [];
if ($claude['state'] === 'off') {
    $steps[] = e(t('Switch the Claude connection on in Features.')) . ' ' . $link('module=extensions', t('Features'));
} elseif ($claude['state'] === 'none') {
    $steps[] = e(t('Connect Claude: in the Claude app add the address below as a custom connector – or create a personal token for Claude Code.')) . ' <a href="#mcp-adresa-nastaveni">' . e(t('How to connect')) . '</a>';
} else {
    if ($claude['pickup'] === 'stalled') {
        $steps[] = e(t('The scheduled run that picks up requests is not running – check the routine in Claude.')) . ' ' . $link('module=schedules', t('Scheduled runs'));
    } elseif ($claude['pickup'] === 'manual') {
        $steps[] = e(t('Requests are picked up only when someone asks Claude. To have them picked up automatically, set up a scheduled run.')) . ' ' . $link('module=schedules', t('Scheduled runs'));
    }
    if ($claude['state'] === 'idle') {
        $steps[] = e(t('Claude has not called the site for %d days. If you still use it, ask it something about the site – the connection stays valid.', ClaudeConnection::ACTIVE_DAYS));
    }
    if ($claude['expiring']) {
        $steps[] = e(t('A connection ends %s – connect Claude again before then (personal tokens: create a new one in My account).', $when($claude['expires'])));
    }
}
?>
<section class="hlaska claude-panel <?= $tone ?>" aria-labelledby="claude-panel-nadpis">
	<h2 id="claude-panel-nadpis"><?= e(match ($claude['state']) {
        'active' => t('Claude is connected'),
        'idle' => t('Claude is connected, but idle'),
        'off' => t('The Claude connection is switched off'),
        default => t('Claude is not connected'),
    }) ?></h2>
	<dl>
		<dt><?= e(t('Last call')) ?></dt>
		<dd><?= e($claude['last_call'] !== null ? $when($claude['last_call']) : t('never')) ?></dd>
		<dt><?= e(t('Requests')) ?></dt>
		<dd><?= e(match ($claude['pickup']) {
            'automatic' => $routine !== null && $routine['next_due'] !== null ? t('picked up automatically by a scheduled run – next %s', $when($routine['next_due'])) : t('picked up automatically by a scheduled run'),
            'stalled' => t('the scheduled run that picks them up is not running (last missed or failed %s)', $when($claude['stalled_since'])),
            'manual' => t('picked up when someone asks Claude in the Claude app'),
            default => t('nothing picks them up – they wait'),
        }) ?><?= $claude['waiting'] > 0 ? ' · ' . e(t('%d new waiting', $claude['waiting'])) : '' ?></dd>
		<dt><?= e(t('Connection ends')) ?></dt>
		<dd><?= e($claude['expires'] !== null ? $when($claude['expires']) : ($claude['state'] === 'idle' || $claude['state'] === 'active' ? t('never') : '–')) ?></dd>
	</dl>
<?php if ($steps !== []): ?>
	<ul>
<?php foreach ($steps as $step): ?>
		<li><?= $step ?></li>
<?php endforeach ?>
	</ul>
<?php endif ?>
</section>
