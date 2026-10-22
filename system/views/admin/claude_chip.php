<?php
/**
 * Whether Claude is connected, next to the account on every screen (3.18, Core\ClaudeConnection) – for whoever may use Ask
 * Claude or the Claude settings. The text says the state (never the colour alone); the moment goes to a smaller part that
 * is hidden on narrow screens, on a phone only the dot shows and the text stays for screen readers.
 *  - connected (a call in the last 7 days): green;
 *  - idle (a valid connection, no call in 7 days) and "requests wait" (the scheduled run does not run): amber;
 *  - not connected: red ring.
 *
 * @var Kaleta\Core\App $app
 * @var array{state: string, last_call: ?string, pickup: ?string, stalled_since: ?string, waiting: int} $claude
 * @var string $url where the chip leads (Claude settings, or Ask Claude for whoever may not open them)
 */
$when = fn (?string $moment): string => $moment !== null ? format_date($moment, true) : '';
[$kind, $label, $detail] = match (true) {
    $claude['state'] === 'none' || $claude['state'] === 'off' => ['none', t('Claude not connected'), ''],
    $claude['pickup'] === 'stalled' => ['stalled', $claude['waiting'] > 0 ? t('Claude: requests wait') : t('Claude: scheduled run stopped'),
        $claude['stalled_since'] !== null ? t('last missed or failed %s', $when($claude['stalled_since'])) : ''],
    $claude['state'] === 'active' => ['active', t('Claude connected'), t('last call %s', $when($claude['last_call']))],
    default => ['idle', t('Claude idle'), $claude['last_call'] !== null ? t('last call %s', $when($claude['last_call'])) : t('no call yet')],
};
?>
<a class="claude-stav claude-stav-<?= e($kind) ?>" href="<?= e($url) ?>" title="<?= e($label . ($detail !== '' ? ' – ' . $detail : '')) ?>" data-claude-stav="<?= e($claude['state']) ?>"><span class="claude-stav-tecka" aria-hidden="true"></span><span class="claude-stav-text"><?= e($label) ?><?php if ($detail !== ''): ?><span class="claude-stav-kdy"> – <?= e($detail) ?></span><?php endif ?></span></a>
