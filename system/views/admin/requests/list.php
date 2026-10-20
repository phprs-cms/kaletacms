<?php
/**
 * Requests to Claude (2.15): the team's inbox – open requests first (new, in progress), then done and declined.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Requests $module
 * @var string $csrf
 * @var list<array<string, mixed>> $requests with 'author' and decoded 'attachments'
 * @var string $status the filter ('' = all)
 * @var int $open new and in progress together
 * @var array{state: string, last_call: ?string, pickup: ?string, stalled_since: ?string} $claude whether requests reach Claude now (3.18)
 * @var array{cadence: string, day: int, time: string, next_due: ?string}|null $routine the scheduled run that picks them up
 */
use Kaleta\Core\ClaudeConnection;
use Kaleta\Core\Requests;

// 3.18: a new request that waits over WAIT_HOURS while nothing picks requests up is highlighted
$overdue = ClaudeConnection::blocked($claude) ? date('Y-m-d H:i:s', time() - ClaudeConnection::WAIT_HOURS * 3600) : null;

$tag = fn (string $status): string => '<span class="stitek' . match ($status) { 'done' => ' stitek-vydano', 'new' => ' stitek-koncept', 'declined' => ' stitek-chyba', default => '' } . '">' . e(t(Requests::STATUSES[$status] ?? $status)) . '</span>';
?>
<p class="hlaska"><?= e(t('Write what should change on the site – "change the opening hours on Monday", "add this PDF to the price list" – and Claude does it as drafts the next time it works on the site. Its notes and the links to the drafts appear in the request; a person reviews and publishes them.')) ?></p>
<?= $app->view->render('admin/claude_notice', ['app' => $app, 'claude' => $claude, 'routine' => $routine, 'class' => 'requests-stav']) ?>
<p><a class="tl" href="<?= e($module->url('new')) ?>"><?= e(t('New request')) ?></a></p>
<nav class="zalozky" aria-label="<?= e(t('Request status')) ?>">
<?php foreach (['' => t('Všechny')] + array_map('t', Requests::STATUSES) as $key => $name): ?>
	<a href="<?= e($module->url('', array_filter(['status' => $key]))) ?>"<?= $status === $key ? ' class="aktivni" aria-current="true"' : '' ?>><?= e($name) ?></a>
<?php endforeach ?>
</nav>
<?php if ($requests === [] && $status !== ''): ?>
<?= $app->view->render('admin/empty', ['icon' => 'komentare', 'heading' => t('No request has this status.'), 'text' => '', 'action' => [$module->url(), t('Clear filter')]]) ?>
<?php elseif ($requests === []): ?>
<?= $app->view->render('admin/empty', ['icon' => 'komentare', 'heading' => t('No requests yet.'), 'text' => t('Write the first one: what should change, on which page, with the files Claude needs.'), 'action' => [$module->url('new'), t('New request')]]) ?>
<?php else: ?>
<p class="smltxt"><?= e(t('%d open, %d shown.', $open, count($requests))) ?></p>
<div class="tab-obal"><table class="vypis">
<thead><tr><th scope="col"><?= e(t('Request')) ?></th><th scope="col"><?= e(t('Author')) ?></th><th scope="col"><?= e(t('Status')) ?></th><th scope="col"><?= e(t('Written')) ?></th><th scope="col"><?= e(t('Last change')) ?></th></tr></thead>
<tbody>
<?php foreach ($requests as $r): ?>
<?php $late = $overdue !== null && $r['status'] === 'new' && (string) $r['created_at'] < $overdue; ?>
<tr<?= in_array($r['status'], ['done', 'declined'], true) ? ' class="nevydany"' : ($late ? ' class="ceka-dlouho"' : '') ?>>
	<td><a href="<?= e($module->url('detail', ['id' => (int) $r['id']])) ?>"><strong><?= e((string) $r['title']) ?></strong></a><?= $late ? ' <span class="stitek stitek-chyba">' . e(t('waiting over %d hours', ClaudeConnection::WAIT_HOURS)) . '</span>' : '' ?><?= $r['attachments'] !== [] ? ' <span class="smltxt">(' . e(t('%d attachments', count($r['attachments']))) . ')</span>' : '' ?><br><span class="smltxt"><?= e(mb_strimwidth((string) $r['text'], 0, 120, '…')) ?></span></td>
	<td><?= e((string) $r['author']) ?></td>
	<td><?= $tag((string) $r['status']) ?></td>
	<td><?= e(format_date((string) $r['created_at'], true)) ?></td>
	<td><?= e(format_date((string) $r['updated_at'], true)) ?></td>
</tr>
<?php endforeach ?>
</tbody></table></div>
<?php endif ?>
