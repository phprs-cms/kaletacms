<?php
/**
 * Import from another system (3.0, Import\Batch), step 2: what the export contains, what will not be converted, and the
 * mapping – what becomes a news item, a page, a category or a tag, whose the posts will be. Nothing has been written yet.
 *
 * @var Kaleta\Admin\Modules\Transfer $module
 * @var Kaleta\Core\App $app
 * @var string $csrf
 * @var array<string, mixed> $state  import state (Import\Batch::newState)
 * @var class-string<Kaleta\Import\Source> $source
 * @var list<string> $languages  language versions of the site, the first one is the default
 * @var list<array{idt:int, nazev:string, jazyk:string}> $categories  news categories
 * @var list<array{idu:int, jmeno:string, user:string}> $users  users the authors can be mapped to
 * @var bool $redirectsEnabled
 */
$p = $state['prehled'];
$m = $state['mapovani'];
$statuses = ['published' => 'published', 'draft' => 'drafts', 'scheduled' => 'scheduled'];
$byStatus = function (array $counts) use ($statuses): string {
    $parts = [];
    foreach ($counts as $s => $count) {
        $parts[] = (int) $count . ' ' . t($statuses[$s] ?? $s);
    }

    return implode(', ', $parts);
};
$choices = fn (string $name, array $options, string $current): string => '<select id="' . $name . '" name="' . $name . '">' . implode('', array_map(
    fn (string $value, string $label): string => '<option value="' . e($value) . '"' . ($value === $current ? ' selected' : '') . '>' . e(t($label)) . '</option>',
    array_keys($options), $options)) . '</select>';
?>
<?= $app->view->render('admin/transfer/steps', ['step' => 2]) ?>
<p><?= e(t('File %s – %s export of “%s”. Nothing has been imported yet; this is only an overview of what the file contains.', $state['soubor'], $source::name(), $state['web']['nazev'] !== '' ? $state['web']['nazev'] : $state['web']['adresa'])) ?></p>
<div class="dlazdice">
	<div class="dlazdice-polozka"><strong><?= (int) array_sum($p['clanky']) ?></strong><span><?= e(t('Posts')) ?><?= $p['clanky'] !== [] ? ': ' . e($byStatus($p['clanky'])) : '' ?></span></div>
	<div class="dlazdice-polozka"><strong><?= (int) array_sum($p['stranky']) ?></strong><span><?= e(t('Pages')) ?><?= $p['stranky'] !== [] ? ': ' . e($byStatus($p['stranky'])) : '' ?></span></div>
	<div class="dlazdice-polozka"><strong><?= (int) $p['rubriky'] ?></strong><span><?= e(t('Categories (those with posts are created)')) ?></span></div>
	<div class="dlazdice-polozka"><strong><?= (int) $p['stitky'] ?></strong><span><?= e(t('Tags')) ?></span></div>
	<div class="dlazdice-polozka"><strong><?= (int) $p['obrazky'] ?></strong><span><?= e(t('Images in texts')) ?></span></div>
	<div class="dlazdice-polozka"><strong><?= (int) $p['autori'] ?></strong><span><?= e(t('Authors')) ?></span></div>
</div>
<?php foreach (['post' => 'The first posts', 'page' => 'The first pages'] as $kind => $label): ?>
<?php if ($p['tituly'][$kind] !== []): ?>
<p><strong><?= e(t($label)) ?>:</strong> <?= e(implode(' · ', $p['tituly'][$kind])) ?></p>
<?php endif ?>
<?php endforeach ?>

<div class="hlaska hlaska-varovani">
<p><strong><?= e(t('What will not be converted')) ?></strong></p>
<ul>
	<li><?= e(t('User accounts and passwords – choose below whose the posts will be.')) ?></li>
<?php foreach ($p['poznamky'] as $note): ?>
	<li><?= e(t($note)) ?></li>
<?php endforeach ?>
<?php if ($p['bloky'] !== []): ?>
	<li><?= e(t('Content blocks the import cannot convert – they are left out, the text around them stays:')) ?> <?= e(implode(', ', array_map(fn (string $block, int $count): string => $block . ' ' . $count . '×', array_keys($p['bloky']), $p['bloky']))) ?></li>
<?php endif ?>
<?php foreach ($p['varovani'] as $code => $count): ?>
	<li><?= e(Kaleta\Import\Preview::describe((string) $code, (int) $count)) ?></li>
<?php endforeach ?>
	<li><?= e(t('Images stay on the old site for now; after the import you can download them to your site with one button.')) ?></li>
</ul>
</div>

<form class="formular" method="post" action="<?= e($module->url('source_run')) ?>">
<?= $csrf ?>
<input type="hidden" name="soubor" value="<?= e($state['soubor']) ?>">
<fieldset>
<legend><?= e(t('What becomes what')) ?></legend>
<?php if ($state['web']['adresa'] === ''): ?>
<div class="radek"><label for="site_url"><?= e(t('Address of the old site')) ?></label><div><input class="textpole siroke" type="url" id="site_url" name="site_url" value="<?= e($m['site_url']) ?>" placeholder="https://www.example.com" maxlength="300">
	<span class="napoveda"><?= e(t('The export does not carry it. Images are downloaded from this address and the redirects from the old addresses count on it.')) ?></span></div></div>
<?php endif ?>
<div class="radek"><label for="posts"><?= e(t('Posts (%s)', (int) array_sum($p['clanky']))) ?></label><div><?= $choices('posts', ['news' => 'news items', 'skip' => 'do not import'], $m['posts']) ?></div></div>
<div class="radek"><label for="pages"><?= e(t('Pages (%s)', (int) array_sum($p['stranky']))) ?></label><div><?= $choices('pages', ['page' => 'pages', 'news' => 'news items', 'skip' => 'do not import'], $m['pages']) ?></div></div>
<div class="radek"><label for="categories"><?= e(t('Categories')) ?></label><div><?= $choices('categories', ['category' => 'news categories (the first one of a post)', 'tag' => 'tags', 'skip' => 'do not import'], $m['categories']) ?></div></div>
<div class="radek"><label for="tags"><?= e(t('Tags')) ?></label><div><?= $choices('tags', ['tag' => 'tags', 'skip' => 'do not import'], $m['tags']) ?></div></div>
<div class="radek"><label for="default_category"><?= e(t('Put posts without a category into')) ?></label><div><select id="default_category" name="default_category">
	<option value="0"><?= e(t('a new “Uncategorised” category')) ?></option>
<?php foreach ($categories as $r): ?>
	<option value="<?= (int) $r['idt'] ?>"<?= (int) $r['idt'] === (int) $m['default_category'] ? ' selected' : '' ?>><?= e($r['nazev']) ?><?= $r['jazyk'] !== '' ? ' (' . e($r['jazyk']) . ')' : '' ?></option>
<?php endforeach ?>
</select></div></div>
<?php if (count($languages) > 1): ?>
<div class="radek"><label for="language"><?= e(t('Language version')) ?></label><div><select id="language" name="language">
<?php foreach ($languages as $i => $code): ?>
	<option value="<?= $i === 0 ? '' : e($code) ?>"<?= ($i === 0 ? '' : $code) === $m['language'] ? ' selected' : '' ?>><?= e(Kaleta\Core\Language::AVAILABLE[$code][0] ?? $code) ?><?= $i === 0 ? ' – ' . e(t('default site language')) : '' ?></option>
<?php endforeach ?>
</select><span class="napoveda"><?= e(t('Which language version of the site the new categories and pages belong to.')) ?></span></div></div>
<?php endif ?>
<div class="radek"><span class="popisek"><?= e(t('Options')) ?></span><div class="volby">
	<label><input type="checkbox" name="drafts" value="1"<?= $m['drafts'] ? ' checked' : '' ?>> <?= e(t('drafts too (%s) – as hidden news items and pages', (int) (($p['clanky']['draft'] ?? 0) + ($p['stranky']['draft'] ?? 0)))) ?></label>
	<label><input type="checkbox" name="builder" value="1"<?= $m['builder'] ? ' checked' : '' ?>> <?= e(t('pages straight into the builder – edit them visually; the original text stays as a backup')) ?></label>
	<label><input type="checkbox" name="redirects" value="1"<?= $m['redirects'] ? ' checked' : '' ?>> <?= e(t('redirects from old addresses to new ones')) ?></label>
</div></div>
<?php if (!$redirectsEnabled): ?>
<p class="napoveda"><?= e(t('The redirects will be saved but only take effect once you turn on the Redirects feature.')) ?></p>
<?php endif ?>
</fieldset>
<?php if ($state['slovnik']['autori'] !== []): ?>
<fieldset>
<legend><?= e(t('Authors')) ?></legend>
<p class="napoveda"><?= e(t('No accounts are created. Each author’s posts belong to the user you choose; by default to you.')) ?></p>
<?php foreach ($state['slovnik']['autori'] as $key => $name): $field = 'author_' . substr(sha1((string) $key), 0, 12); ?>
<div class="radek"><label for="<?= e($field) ?>"><?= e($name) ?></label><div><select id="<?= e($field) ?>" name="<?= e($field) ?>">
	<option value="0"><?= e(t('me (the importing user)')) ?></option>
<?php foreach ($users as $u): ?>
	<option value="<?= (int) $u['idu'] ?>"<?= (int) $u['idu'] === (int) ($m['authors'][$key] ?? 0) ? ' selected' : '' ?>><?= e($u['jmeno'] !== '' ? $u['jmeno'] : $u['user']) ?></option>
<?php endforeach ?>
</select></div></div>
<?php endforeach ?>
</fieldset>
<?php endif ?>
<p class="napoveda"><?= $module->hint(t('Imported news is not announced: no webhook or IndexNow. Before a larger import, create a database backup in Settings → Backups and updates.')) ?></p>
<p class="tlacitka"><input class="tl" type="submit" value="<?= e(t('Start import')) ?>"> <a class="navigace" href="<?= e($module->url()) ?>"><?= e(t('Back')) ?></a></p>
</form>
