<?php
/**
 * A customer's timeline (3.28, Core\Contacts::timeline), newest first: an icon per kind, what it was (a form, a subject, a
 * service – data, not translated), who and when, its status, a link to the enquiry, booking or task. On a company's page
 * each entry names its contact. The author of a logged entry (or an administrator) may delete it.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Contacts $module
 * @var string $csrf
 * @var list<array<string, mixed>> $timeline
 * @var int $contactId the contact whose log entries may be deleted here (0 = none – a company's page, an anonymised contact)
 * @var array<int, ?int> $authors activity id => author id
 * @var bool $admin
 * @var int $me
 */
use Kaleta\Admin\Modules\Contacts;
use Kaleta\Admin\Modules\Enquiries;

$icon = require __DIR__ . '/../icons.php';
$status = ['new' => Enquiries::STATUSES[0], 'read' => Enquiries::STATUSES[1], 'resolved' => Enquiries::STATUSES[2], 'sent' => 'E-mail sent', 'queued' => 'Waiting in the mail queue',
    'failed' => 'Not delivered', 'overdue' => 'overdue', 'today' => 'today'] + Kaleta\Core\Booking::STATUSES;
$taskStatus = ['done' => 'Done', 'overdue' => 'overdue', 'today' => 'today'];
$linked = ['enquiry', 'reply', 'booking', 'subscription', 'task'];
?>
<ol class="upozorneni zakaznik-udalosti" data-timeline-list>
<?php foreach ($timeline as $entry): [$iconKey, $label] = Contacts::KINDS[$entry['kind']] ?? ['clanek', $entry['kind']];
    $statusKey = (string) ($entry['status'] ?? '');
    $statusText = $entry['kind'] === 'task' ? ($taskStatus[$statusKey] ?? '') : ($entry['kind'] === 'subscription' ? ($statusKey === 'confirmed' ? 'confirmed' : 'waiting for confirmation') : ($status[$statusKey] ?? ''));
    $text = (string) ($entry['text'] ?? '');
    if ($entry['kind'] === 'booking' && $text !== '') {
        $text = format_date($text, true); // the appointment
    } elseif ($entry['kind'] === 'task' && $text !== '') {
        $text = t('due %s', format_date($text, true));
    }
    $activityId = str_starts_with((string) $entry['ref'], 'activity:') ? (int) substr((string) $entry['ref'], 9) : 0;
    $mayDelete = $activityId > 0 && $contactId > 0 && ($admin || ($me > 0 && ($authors[$activityId] ?? null) === $me)); ?>
	<li class="upozorneni-polozka zakaznik-udalost zakaznik-udalost-<?= e($entry['kind']) ?>" data-timeline-entry="<?= e($entry['kind']) ?>">
		<span class="upozorneni-ikona"><?= $icon($iconKey) ?></span>
		<div class="upozorneni-text">
			<p class="upozorneni-co"><strong><?= e(t($label)) ?></strong><?= ($entry['title'] ?? '') !== '' ? ' · ' . bdi((string) $entry['title']) : '' ?><?= ($entry['by'] ?? '') !== '' ? ' · ' . e((string) $entry['by']) : '' ?>
				<?= $statusText !== '' ? '<span class="stitek' . ($statusKey === 'failed' || $statusKey === 'overdue' ? ' stitek-chyba' : ($statusKey === 'new' || $statusKey === 'pending' || $statusKey === 'queued' ? ' stitek-koncept' : '')) . '">' . e(t($statusText)) . '</span>' : '' ?></p>
<?php if (isset($entry['contact'])): ?>
			<p class="upozorneni-kde"><a href="<?= e($module->url('detail', ['id' => $entry['contact_id']])) ?>"><?= bdi((string) $entry['contact']) ?></a></p>
<?php endif ?>
<?php if ($text !== ''): ?>
			<p class="upozorneni-vynatek"><?= e($text) ?></p>
<?php endif ?>
<?php if (in_array($entry['kind'], $linked, true) && ($entry['path'] ?? '') !== ''): ?>
			<p class="upozorneni-kde"><a href="<?= e($app->url((string) $entry['path'])) ?>"><?= e(t(match ($entry['kind']) { 'booking' => 'Open the booking', 'subscription' => 'Open the subscribers', 'task' => 'Open in My work', default => 'Open the enquiry' })) ?></a></p>
<?php endif ?>
<?php if ($mayDelete): ?>
			<form class="vradku zakaznik-smazat-zapis" method="post" action="<?= e($module->url('activity')) ?>" data-activity-delete data-potvrdit="<?= e(t('Delete this entry? This cannot be undone.')) ?>">
				<?= $csrf ?><input type="hidden" name="id" value="<?= $contactId ?>"><input type="hidden" name="op" value="delete"><input type="hidden" name="activity" value="<?= $activityId ?>">
				<button class="navigace nebezpecne" type="submit"><?= e(t('Delete')) ?></button></form>
<?php endif ?>
		</div>
		<span class="upozorneni-cas"><?= e(format_date((string) $entry['at'], true)) ?></span>
	</li>
<?php endforeach ?>
</ol>
