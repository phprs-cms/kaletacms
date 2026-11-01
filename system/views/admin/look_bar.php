<?php
/**
 * The draft look (Core\Look) on every admin screen: one line (3.6) – what the draft touches, Review (the whole-site preview)
 * and Publish; the details toggle opens what exactly changes (colours with a swatch) and Discard. 3.12 (UX310-17): each draft
 * menu says what visitors see before and after (items, what leaves the menu, what is new), and Publish asks first when
 * items would leave a menu.
 *
 * @var Kaleta\Core\App $app
 * @var list<string> $summary  what the draft changes apart from the menus
 * @var list<array{menu: string, before: int, after: int, removed: list<string>, added: list<string>, hidden: int}> $menus  Look::menuChanges
 * @var list<string> $areas
 * @var string $csrf
 * @var bool $readOnly  3.23: a user with the Appearance area who is not an administrator – the preview, no Publish or Discard
 */
$appearance = fn (string $action): string => $app->url('admin.php?module=appearance&action=' . $action);
$menus ??= [];
$readOnly ??= false;
$lines = [...$summary, ...array_map(Kaleta\Core\Look::menuChangeText(...), $menus)];
$leaving = array_values(array_filter($menus, fn (array $m): bool => $m['removed'] !== []));
?>
<div class="hlaska hlaska-varovani vzhled-koncept" id="vzhled-koncept" role="region" aria-label="<?= e(t('Unpublished look changes')) ?>">
	<details class="vzhled-koncept-detail">
		<summary><strong><?= e(t('Unpublished changes:')) ?></strong> <?= e(implode(', ', $areas)) ?></summary>
		<p><?= e(t($summary === [] ? 'Visitors keep seeing the current menus until you publish.' : 'Visitors still see the published look until you publish.')) ?></p>
<?php if ($lines !== []): ?>
		<ul>
<?php foreach ($lines as $line): ?>
			<li><?= preg_replace('/#[0-9a-f]{6}\b/', '<i class="vzhled-vzorek" style="background:$0" aria-hidden="true"></i>$0', e($line)) ?></li>
<?php endforeach ?>
		</ul>
<?php endif ?>
<?php if ($readOnly): ?>
		<p><?= e(t('An administrator publishes the look – until then visitors see the published one.')) ?></p>
<?php else: ?>
		<form class="vradku" method="post" action="<?= e($appearance('discard_look')) ?>" data-potvrdit="<?= e(t('Discard the unpublished look changes?')) ?>"><?= $csrf ?><button class="navigace nebezpecne" type="submit"><?= e(t('Discard')) ?></button></form>
<?php endif ?>
	</details>
	<div class="vzhled-koncept-akce">
<?php if ($readOnly): // the draft look on the home page (Front\Kernel::startSitePreview, ?preview=vzhled) – only for whoever has the area ?>
		<a class="navigace" href="<?= e($app->url('') . '?preview=vzhled') ?>" target="_blank" rel="noopener" title="<?= e(t('Preview the draft look on the home page')) ?>"><?= e(t('Review')) ?></a>
<?php else: ?>
		<a class="navigace" href="<?= e($appearance('preview_site')) ?>" target="_blank" rel="noopener" title="<?= e(t('Preview the whole site')) ?>"><?= e(t('Review')) ?></a>
		<form class="vradku" method="post" action="<?= e($appearance('publish_look')) ?>"<?php if ($leaving !== []): ?> data-potvrdit="<?= e(t('Publish the changes? %s', implode(' ', array_map(Kaleta\Core\Look::menuChangeText(...), $leaving)))) ?>"<?php endif ?>><?= $csrf ?><button class="tl" type="submit"><?= e(t('Publish')) ?></button></form>
<?php endif ?>
	</div>
</div>
