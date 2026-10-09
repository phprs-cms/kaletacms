<?php
/**
 * Enquiries from the site's forms.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Enquiries $module
 * @var string $csrf
 * @var list<array<string, mixed>> $enquiries
 * @var int $total
 * @var string $filter
 * @var int $pageNumber
 * @var int $perPage
 * @var int $months
 * @var string $expiry delete | anonymise – what happens after the retention period (2.14)
 * @var int $applicationMonths retention of applications to job openings (2.11)
 * @var array{0: string, 1: int}|null $suggestion [country, months] usually kept there
 * @var string $kind the triage kind shown ('' = all but spam, '-' = not sorted yet)
 * @var int $spam enquiries marked as spam
 * @var string $search
 * @var array<int, string> $users
 */
use Kaleta\Admin\Modules\Enquiries;

$pageCount = (int) ceil($total / $perPage);
$preview = function (string $data): string {
    $items = json_decode($data, true) ?: [];
    $text = implode(' · ', array_filter(array_map(fn (array $d): string => (string) $d[1], $items), fn (string $v): bool => $v !== '' && mb_strlen($v) > 1));

    return mb_strimwidth($text, 0, 140, '…');
};
?>
<nav class="zalozky" aria-label="<?= e(t('Enquiry status')) ?>">
<?php foreach (['' => 'Všechny', 'otevrene' => 'To do', 'moje' => 'Mine', 'vyrizene' => 'Resolved'] as $key => $name): ?>
	<a href="<?= e($module->url('', array_filter(['status' => $key]))) ?>"<?= $filter === $key ? ' class="aktivni" aria-current="true"' : '' ?>><?= e(t($name)) ?></a>
<?php endforeach ?>
</nav>
<nav class="zalozky" aria-label="<?= e(t('Kind')) ?>">
<?php foreach (['' => t('All kinds'), '-' => t('Not sorted')] + array_map('t', Kaleta\Core\Triage::CATEGORIES) as $key => $name): ?>
	<a href="<?= e($module->url('', array_filter(['status' => $filter, 'category' => $key]))) ?>"<?= $kind === $key ? ' class="aktivni" aria-current="true"' : '' ?>><?= e($name) ?><?= $key === 'spam' && $spam > 0 ? ' (' . $spam . ')' : '' ?></a>
<?php endforeach ?>
</nav>
<form method="get" action="<?= e($app->url('admin.php')) ?>" class="stred smltxt">
	<input type="hidden" name="module" value="enquiries"><input type="hidden" name="status" value="<?= e($filter) ?>">
	<label><?= e(t('Search (name, e-mail, text):')) ?> <input class="textpole" type="search" name="search" value="<?= e($search) ?>" size="24"></label>
	<input class="tl" type="submit" value="<?= e(t('Filtrovat')) ?>">
</form>
<br>
<?php if ($enquiries === [] && ($search !== '' || $filter !== '')): ?>
<?= $app->view->render('admin/empty', ['icon' => 'poptavky', 'heading' => t('No enquiry matches the filter.'), 'text' => t('Try another word or status.'), 'action' => [$module->url(), t('Clear filter')]]) ?>
<?php elseif ($enquiries === []): ?>
<?= $app->view->render('admin/empty', ['icon' => 'poptavky', 'heading' => t('No enquiries yet.'), 'text' => t('Add a Form element in the page builder (or the ready-made Enquiry form section) – submitted messages will appear here and arrive by email.'), 'action' => [$app->url('admin.php?module=pages'), t('Open pages')]]) ?>
<?php else: ?>
<form method="post" action="<?= e($module->url('bulk')) ?>">
<?= $csrf ?>
<div class="tab-obal">
<table class="vypis">
<thead><tr><th scope="col"><?= e(t('Date')) ?></th><th scope="col"><?= e(t('Form')) ?></th><th scope="col"><?= e(t('Content')) ?></th><th scope="col"><?= e(t('Status')) ?></th><th scope="col" class="stred"><?= e(t('Select')) ?></th></tr></thead>
<tbody>
<?php foreach ($enquiries as $p): ?>
<tr<?= (int) $p['stav'] === 2 ? ' class="nevydany"' : '' ?>>
	<td><a href="<?= e($module->url('detail', ['id' => $p['idp']])) ?>"><?= (int) $p['stav'] === 0 ? '<strong>' . e(format_date($p['datum'], true)) . '</strong>' : e(format_date($p['datum'], true)) ?></a></td>
	<td><?= e($p['formular']) ?><?= ($p['tema'] ?? '') !== '' ? '<br><small>' . e(t('Topic')) . ': <a href="' . e($p['stranka']) . '" target="_blank" rel="noopener">' . e($p['tema']) . '</a></small>' : '' ?><?= $p['email'] !== '' ? '<br><small>' . e($p['email']) . '</small>' : '' ?><?= $p['kategorie'] !== '' ? '<br><span class="stitek">' . e(t(Kaleta\Core\Triage::CATEGORIES[$p['kategorie']] ?? $p['kategorie'])) . '</span>' : '' ?><?= (int) $p['priorita'] === 3 ? ' <span class="stitek stitek-koncept">' . e(t('urgent')) . '</span>' : '' ?></td>
	<td><a href="<?= e($module->url('detail', ['id' => $p['idp']])) ?>"><?= e($preview((string) $p['data'])) ?></a></td>
	<td><span class="stitek<?= (int) $p['stav'] === 0 ? ' stitek-koncept' : ((int) $p['stav'] === 2 ? ' stitek-vydano' : '') ?>"><?= e(t(Enquiries::STATUSES[(int) $p['stav']])) ?></span><?= $p['prirazeno'] && isset($users[(int) $p['prirazeno']]) ? '<br><small>' . e($users[(int) $p['prirazeno']]) . '</small>' : '' ?></td>
	<td class="stred"><input type="checkbox" name="oznacene[]" value="<?= (int) $p['idp'] ?>" aria-label="<?= e(t('Select')) ?>: #<?= (int) $p['idp'] ?>"></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<p class="media-hromadne"><?= e(t('With selected:')) ?>
	<button class="tl" type="submit" name="provest" value="vyridit"><?= e(t('Označit jako vyřízené')) ?></button>
	<button class="navigace nebezpecne" type="submit" name="provest" value="smazat" data-potvrdit="<?= e(t('Delete the selected enquiries including attachments? This cannot be undone.')) ?>"><?= e(t('Smazat')) ?></button></p>
</form>
<?php if ($pageCount > 1): ?>
<p class="strankovani">
<?php for ($s = 1; $s <= $pageCount; $s++): ?>
	<?= $s === $pageNumber ? '<strong>[' . $s . ']</strong>' : '<a href="' . e($module->url('', array_filter(['status' => $filter]) + ['page' => $s])) . '">' . $s . '</a>' ?>
<?php endfor ?>
</p>
<?php endif ?>
<p class="navigace-radek"><a class="navigace" href="<?= e($module->url('csv')) ?>"><?= e(t('Download all enquiries (CSV)')) ?></a></p>
<?php endif ?>
<?php if ($app->auth()->isAdmin()): ?>
<form class="formular" method="post" action="<?= e($module->url('settings')) ?>" data-potvrdit="<?= e(t('Enquiries older than the given number of months will be permanently deleted right away – including attachments. Save anyway?')) ?>">
<?= $csrf ?>
<div class="radek"><label for="mesice"><?= e(t('Delete enquiries older than')) ?></label><div><input class="textpole" type="number" id="mesice" name="mesice" value="<?= $months ?>" min="0" max="120" size="4"> <?= e(t('months')) ?>
<span class="napoveda"><?= e(t('Enquiries contain personal data – they should not be kept longer than necessary. 0 = keep forever.')) ?></span></div></div>
<div class="radek"><span class="popisek"><?= e(t('After that period')) ?></span><div class="volby">
<?php foreach (['delete' => 'delete them including attachments', 'anonymise' => 'anonymise them – the row stays for statistics (date, form, page, topic, kind) without the name, e-mail, phone, message and attachments'] as $key => $labelText): ?>
	<label><input type="radio" name="po_uplynuti" value="<?= $key ?>"<?= $expiry === $key ? ' checked' : '' ?>> <?= e(t($labelText)) ?></label>
<?php endforeach ?>
</div></div>
<div class="radek"><label for="mesice-uchazeci"><?= e(t('Delete job applications after')) ?></label><div><input class="textpole" type="number" id="mesice-uchazeci" name="mesice_uchazeci" value="<?= $applicationMonths ?>" min="0" max="120" size="4"> <?= e(t('months')) ?> <input class="tl" type="submit" value="<?= e(t('Uložit')) ?>">
<span class="napoveda"><?= e(t('Applications sent from the pages of a Job openings collection carry CVs and are usually kept only for a limited time after the selection. 0 = like other enquiries.')) ?><?= $suggestion !== null ? ' ' . e(t('Usual practice in %s: %d months – check with your lawyer.', $suggestion[0], $suggestion[1])) : '' ?></span></div></div>
<div class="radek"><span></span><div><label><input type="checkbox" name="triage_assistant" value="1"<?= $app->settings()->bool('triage_assistant') ? ' checked' : '' ?>> <?= e(t('Sort new enquiries with the writing assistant')) ?></label>
<span class="napoveda"><?= e(t('The assistant suggests the kind, the priority and a reply; the text of each enquiry is then sent to the AI provider chosen in Features – mention it in your privacy policy. Claude can sort enquiries over its connection without this.')) ?></span></div></div>
</form>
<p><a class="navigace" href="<?= e($module->url('personal')) ?>"><?= e(t('Personal data request')) ?></a> – <?= e(t('find, export or erase everything about one e-mail address')) ?></p>
<?php endif ?>
