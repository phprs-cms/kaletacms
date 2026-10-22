<?php
/**
 * A new request to Claude (2.15): the title, what should change, what it is about (a page, a news item, a collection
 * item, or a pasted address) and up to five files – they are saved to Media, so Claude can put them on the site.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Requests $module
 * @var string $csrf
 * @var array<int, string> $pages ids => title
 * @var array<int, string> $news idc => title
 * @var array<int, string> $items idp => "collection – item"
 * @var int $maxAttachments
 * @var string $limit the upload limit of the server
 * @var array{state: string, last_call: ?string, pickup: ?string, stalled_since: ?string} $claude whether requests reach Claude now (3.18)
 */
$reachable = Kaleta\Core\ClaudeConnection::reachable($claude);
?>
<p class="navigace-radek"><a class="navigace" href="<?= e($module->url()) ?>">← <?= e(t('All requests')) ?></a></p>
<?= $app->view->render('admin/claude_notice', ['app' => $app, 'claude' => $claude]) ?>
<form class="formular" method="post" action="<?= e($module->url('save')) ?>" enctype="multipart/form-data">
<?= $csrf ?>
<fieldset>
<legend><?= e(t('What should change')) ?></legend>
<div class="radek"><label for="req-title"><?= e(t('Title')) ?></label><div><input class="textpole siroke" id="req-title" name="title" required maxlength="190" placeholder="<?= e(t('e.g. Opening hours on Monday')) ?>"></div></div>
<div class="radek"><label for="req-text"><?= e(t('Request')) ?></label><div><textarea class="textbox" id="req-text" name="text" rows="8" required maxlength="<?= Kaleta\Core\Requests::MAX_TEXT ?>" placeholder="<?= e(t('Write it as you would to a colleague: what, where on the site, and by when it matters.')) ?>"></textarea>
	<span class="napoveda"><?= e(t('Claude reads it as a job to do as drafts – it never publishes anything by itself; you or an administrator review and publish the drafts.')) ?></span></div></div>
<div class="radek"><label for="req-about"><?= e(t('It is about')) ?></label><div><select id="req-about" name="about">
	<option value=""><?= e(t('— the site in general —')) ?></option>
<?php if ($pages !== []): ?>
	<optgroup label="<?= e(t('Pages')) ?>"><?php foreach ($pages as $id => $title): ?><option value="page:<?= (int) $id ?>"><?= e($title) ?></option><?php endforeach ?></optgroup>
<?php endif ?>
<?php if ($news !== []): ?>
	<optgroup label="<?= e(t('News')) ?>"><?php foreach ($news as $id => $title): ?><option value="news:<?= (int) $id ?>"><?= e($title) ?></option><?php endforeach ?></optgroup>
<?php endif ?>
<?php if ($items !== []): ?>
	<optgroup label="<?= e(t('Collection items')) ?>"><?php foreach ($items as $id => $title): ?><option value="item:<?= (int) $id ?>"><?= e($title) ?></option><?php endforeach ?></optgroup>
<?php endif ?>
</select>
	<input class="textpole siroke" type="text" name="about_url" maxlength="500" placeholder="<?= e(t('or paste the address, e.g. /price-list')) ?>" aria-label="<?= e(t('Address of the page it is about')) ?>"></div></div>
<div class="radek"><label for="req-files"><?= e(t('Attachments')) ?></label><div><input type="file" id="req-files" name="prilohy[]" multiple>
	<span class="napoveda"><?= e(t('Up to %d files – a PDF, a photo, a document (%s each at most). They are saved to Media, so Claude can place them on the site.', $maxAttachments, $limit)) ?></span></div></div>
</fieldset>
<p class="tlacitka"><button class="<?= $reachable ? 'tl' : 'navigace' ?>" type="submit"><?= e($reachable ? t('Save the request') : t('Save for later')) ?></button></p>
</form>
