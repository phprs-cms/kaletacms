<?php
/**
 * 3.48 B (U70): "Settings of sections" – where the settings that live in the sections are (Admin\SectionSettings), for the
 * sections this person may open. Nothing to save here.
 *
 * @var Kaleta\Core\App $app
 * @var array<string, array{0: string, 1: list<array{0: string, 1: string}>}> $sectionLinks
 */
$sectionLinks ??= [];
?>
<p class="napoveda"><?= e(t('Some settings live with their section – quotes, applications, the stages of the pipeline, bookings. They are all listed here.')) ?></p>
<?php if ($sectionLinks === []): ?>
<p><?= e(t('No section with settings of its own is switched on.')) ?></p>
<?php else: ?>
<dl>
<?php foreach ($sectionLinks as $section => [$name, $links]): ?>
	<dt><?= e(t($name)) ?></dt>
	<dd><?= implode(' · ', array_map(fn (array $l): string => '<a href="' . e($app->url('admin.php?' . $l[1])) . '">' . e(t($l[0])) . '</a>', $links)) ?></dd>
<?php endforeach ?>
</dl>
<?php endif ?>
