<?php
/**
 * Merge two contacts (3.28, Core\Contacts::merge) – administrators only and never automatic: find the other contact, see
 * both side by side and what moves, swap which one is kept, then confirm. The one merged in is deleted; its enquiries,
 * bookings, subscription, log, comments and tasks move to the one kept, which keeps its address; a note keeps the history.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Contacts $module
 * @var string $csrf
 * @var array<string, mixed> $keep the contact kept
 * @var array<string, mixed>|null $other the contact merged into it
 * @var string $search
 * @var list<array<string, mixed>> $found contacts matching the search
 * @var array<int, array<string, mixed>> $sides id => what each brings (Contacts::mergeSide)
 */
use Kaleta\Core\Contacts as Customers;

$card = function (array $c, array $side, bool $kept) use ($module): string {
    $rows = [[t('E-mail'), $c['email']], [t('Phone'), $c['phone']], [t('Company'), (string) ($c['company_name'] ?? '')], [t('Responsible person'), $side['owner']],
        [t('First contact'), $c['first_seen_at'] !== null ? format_date($c['first_seen_at']) : ''], [t('Last activity'), $c['last_activity_at'] !== null ? format_date($c['last_activity_at']) : '']];
    $html = '<section class="slouceni-karta' . ($kept ? ' slouceni-zustava' : '') . '" data-merge-side="' . ($kept ? 'keep' : 'other') . '">'
        . '<p class="slouceni-role"><span class="stitek' . ($kept ? ' stitek-vydano' : ' stitek-koncept') . '">' . e(t($kept ? 'Kept' : 'Merged in and deleted')) . '</span></p>'
        . '<h2><a href="' . e($module->url('detail', ['id' => $c['id']])) . '">' . bdi(Customers::displayName($c)) . '</a> <small>#' . (int) $c['id'] . '</small></h2><dl class="poptavka">';
    foreach ($rows as [$label, $value]) {
        $html .= '<dt>' . e($label) . '</dt><dd>' . ($value !== '' ? bdi($value) : '<span class="napoveda">—</span>') . '</dd>';
    }
    $html .= '<dt>' . e(t('Has')) . '</dt><dd>' . e(t('Enquiries: %d', $side['enquiries'])) . ' · ' . e(t('Bookings: %d', $side['bookings'])) . ' · '
        . e(t($side['subscribed'] ? 'Subscribed to the newsletter' : 'Not subscribed to the newsletter')) . '<br>' . e(t('Log entries: %d', $side['activities'])) . ' · '
        . e(t('Comments: %d', $side['comments'])) . ' · ' . e(t('Tasks: %d', $side['tasks'])) . '</dd></dl></section>';

    return $html;
};
?>
<p class="navigace-radek"><a class="navigace" href="<?= e($module->url('detail', ['id' => $keep['id']])) ?>">← <?= e(Customers::displayName($keep)) ?></a></p>
<?php if ($other === null): ?>
<p class="napoveda"><?= e(t('Two records of one person – for example a private and a work e-mail address. Kaleta never merges two different addresses by itself. Find the other contact:')) ?></p>
<form method="get" action="<?= e($app->url('admin.php')) ?>" class="zakaznici-filtr" data-merge-search>
	<input type="hidden" name="module" value="contacts"><input type="hidden" name="action" value="merge"><input type="hidden" name="id" value="<?= (int) $keep['id'] ?>">
	<label class="zakaznici-hledat"><span><?= e(t('Search')) ?></span> <input class="textpole" type="search" name="search" value="<?= e($search) ?>" placeholder="<?= e(t('Name, e-mail, phone or company')) ?>" required></label>
	<p class="zakaznici-filtr-tlacitka"><button class="tl" type="submit"><?= e(t('Find')) ?></button></p>
</form>
<?php if ($search !== '' && $found === []): ?>
<p class="napoveda" data-merge-none><?= e(t('No other contact matches.')) ?></p>
<?php elseif ($found !== []): ?>
<ul class="slouceni-nalezene" data-merge-found>
<?php foreach ($found as $c): ?>
	<li><a href="<?= e($module->url('merge', ['id' => $keep['id'], 'with' => $c['id']])) ?>"><?= bdi(Customers::displayName($c)) ?></a>
		<span class="napoveda"><?= e(implode(' · ', array_filter([$c['name'] !== '' ? $c['email'] : '', (string) ($c['company_name'] ?? ''), $c['last_activity_at'] !== null ? format_date($c['last_activity_at']) : '']))) ?></span></li>
<?php endforeach ?>
</ul>
<?php endif ?>
<?php else: ?>
<div class="slouceni" data-merge-compare>
<?= $card($keep, $sides[$keep['id']], true) ?>
<?= $card($other, $sides[$other['id']], false) ?>
</div>
<p><a class="navigace" href="<?= e($module->url('merge', ['id' => $other['id'], 'with' => $keep['id']])) ?>" data-merge-swap><?= e(t('Keep the other one instead')) ?></a>
	<a class="navigace" href="<?= e($module->url('merge', ['id' => $keep['id']])) ?>"><?= e(t('Choose another contact')) ?></a></p>
<form class="formular slouceni-potvrdit" method="post" action="<?= e($module->url('merge')) ?>" data-merge-form data-potvrdit="<?= e(t('Merge the two contacts? This cannot be undone.')) ?>">
	<?= $csrf ?><input type="hidden" name="id" value="<?= (int) $keep['id'] ?>"><input type="hidden" name="with" value="<?= (int) $other['id'] ?>">
	<p><?= e(t('The enquiries, bookings and subscription, the log, the comments and the tasks of “%2$s” move to “%1$s”, which keeps its address; its empty name, phone, company, responsible person or language is filled in from the other one and the summaries are joined. “%2$s” is then deleted; a note keeps what was merged.',
	    Customers::displayName($keep), Customers::displayName($other))) ?></p>
	<p><label><input type="checkbox" name="confirmed" value="1" required> <?= e(t('Yes, these are the same person – merge them.')) ?></label></p>
	<p class="tlacitka"><button class="tl" type="submit"><?= e(t('Merge')) ?></button></p>
</form>
<?php endif ?>
