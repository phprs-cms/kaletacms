<?php
/**
 * News item editor: text on the left, settings on the right (one below the other on a narrow screen).
 *
 * @var Kaleta\Admin\Modules\News $module
 * @var string $csrf
 * @var array<string, mixed> $newsItem
 * @var array<string, string> $errors
 * @var list<array<string, mixed>> $category
 * @var array<int, string> $authors
 * @var bool $canPublish
 * @var bool $assistant  the AI assistant is enabled and has a key
 * @var list<string> $translationLanguages  languages the news item can be translated into (only for a saved news item in the default language)
 * @var array<string, int> $translations  existing translations: language => news item number
 * @var array{cas:string, data:string}|null $draftOnServer  unsaved work stored on the server (from another device)
 * @var bool $siteLanguages  the site has other language versions
 * @var string $original  url of the news item this one is a translation of
 * @var string $tags  comma-separated tags
 * @var list<string> $allTags
 * @var list<array<string, mixed>> $versions
 * @var list<array<string, mixed>>|null $socialDrafts  social post drafts (2.13, Core\SocialDrafts); null = the news item is not published
 */
$dt = fn (?string $v): string => $v ? date('Y-m-d\TH:i', strtotime($v)) : '';
$error = fn (string $field): string => isset($errors[$field]) ? '<span class="chyba-pole" role="alert">' . e(t($errors[$field])) . '</span>' : '';
?>
<p class="navigace-radek"><a class="navigace" href="<?= e($module->url()) ?>"><?= e(t('Back to the news list')) ?></a></p>

<?php if (!empty($draftOnServer)): ?>
<script type="application/json" id="koncept-server"><?= json_encode(['cas' => strtotime($draftOnServer['cas']) * 1000, 'pole' => json_decode($draftOnServer['data'], true)], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php endif ?>
<form class="formular formular-clanek" method="post" action="<?= e($module->url('save')) ?>" data-koncept="novinka-<?= (int) $newsItem['idc'] ?>" data-koncept-url="<?= e($module->url('draft')) ?>"<?= $assistant ? ' data-asistent="' . e($module->url('assistant')) . '"' : '' ?>>
<?= $csrf ?>
<input type="hidden" name="idc" value="<?= (int) $newsItem['idc'] ?>">

<div class="clanek-hlavni">
	<div class="radek pres-celou">
		<label for="titulek"><?= e(t('Titulek')) ?></label>
		<input class="textpole siroke titulek-pole" type="text" id="titulek" name="titulek" value="<?= e($newsItem['titulek']) ?>" maxlength="255" required placeholder="<?= e(t('News item title')) ?>"><?= $error('titulek') ?>
	</div>
	<div class="radek pres-celou">
		<label for="uvod"><?= e(t('Lead paragraph')) ?></label>
		<textarea class="textbox" id="uvod" name="uvod" rows="5" data-editor="maly"><?= e($newsItem['uvod']) ?></textarea><?= $error('uvod') ?>
		<span class="napoveda"><?= e(t('Shown in lists and at the start of the news item – do not repeat it in the text.')) ?></span>
	</div>
	<div class="radek pres-celou">
		<label for="text"><?= e(t('Text')) ?></label>
		<textarea class="textbox vysoky" id="text" name="text" rows="20" data-editor><?= e($newsItem['text']) ?></textarea><?= $error('text') ?>
		<span class="napoveda"><?= e(t('To embed a video, put its address (YouTube, Vimeo) on a line of its own. It loads for the visitor only after a click.')) ?></span>
	</div>
</div>

<aside class="clanek-nastaveni">
<fieldset>
<legend><?= e(t('Publishing')) ?></legend>
<div class="radek">
	<label for="stav"><?= e(t('Status')) ?></label>
	<div><select id="stav" name="stav">
		<option value="koncept"<?= !$newsItem['visible'] ? ' selected' : '' ?>><?= e(t('Draft')) ?></option>
<?php if ($canPublish): ?>
		<option value="vydany"<?= $newsItem['visible'] ? ' selected' : '' ?>><?= e(t('Vydaná')) ?></option>
<?php endif ?>
	</select>
<?php if (!$canPublish): ?>
	<span class="napoveda"><?= e(t('An editor or site administrator publishes the news item.')) ?></span>
<?php endif ?>
	</div>
</div>
<div class="radek">
	<label for="datum"><?= e(t('Publish date')) ?></label>
	<div><input class="textpole" type="datetime-local" id="datum" name="datum" value="<?= e($dt($newsItem['datum'])) ?>" required>
	<span class="napoveda"><?= e(t('A future date = the news item is published automatically at that time.')) ?></span></div>
</div>
<div class="radek">
	<label for="valid_until"><?= e(t('True until')) ?></label>
	<div><input class="textpole" type="date" id="valid_until" name="valid_until" value="<?= e((string) ($newsItem['valid_until'] ?? '')) ?>">
	<span class="napoveda"><?= e(t('After this day the news item hides itself. Empty = always.')) ?></span></div>
</div>
<div class="radek">
	<label for="review_by"><?= e(t('Review by')) ?></label>
	<div><input class="textpole" type="date" id="review_by" name="review_by" value="<?= e((string) ($newsItem['review_by'] ?? '')) ?>">
	<span class="napoveda"><?= e(t('On this day the site audit and the alert e-mail remind you to check it.')) ?></span></div>
</div>
<?php if ($newsItem['visible']): ?>
<div class="radek"><span class="popisek"></span><div class="volby"><label><input type="checkbox" name="oznacit_aktualizaci" value="1"> <?= e(t('Mark as updated (with today\'s date)')) ?></label></div></div>
<?php endif ?>
<?php $readOnly = $newsItem['visible'] && !$canPublish; /* a published news item is edited only by an editor – the author sees it but cannot save it */ ?>
<?php if ($readOnly): ?>
<p class="napoveda"><?= e(t('This news item is published – only an editor or administrator can save changes to it. Ask them to edit it.')) ?></p>
<?php endif ?>
<p class="tlacitka ulozit-lista">
	<button class="tl" type="submit" name="po_ulozeni" value="vypis"<?= $readOnly ? ' disabled' : '' ?>><?= e(t('Uložit')) ?></button>
	<button class="tl" type="submit" name="po_ulozeni" value="zustat"<?= $readOnly ? ' disabled' : '' ?>><?= e(t('Save and continue')) ?></button>
<?php if ($newsItem['idc']): ?>
	<a class="navigace" href="<?= e($module->app()->url('novinky/' . $newsItem['seo_link'] . '?preview=1')) ?>" target="_blank" rel="noopener"><?= e(t('Preview')) ?></a>
<?php endif ?>
</p>
</fieldset>

<fieldset>
<legend><?= e(t('Classification')) ?></legend>
<?php if (count($category) < 2): ?>
<input type="hidden" name="tema" value="<?= (int) ($category[0]['idt'] ?? $newsItem['tema']) ?>">
<?php else: ?>
<div class="radek">
	<label for="tema"><?= e(t('Categories')) ?></label>
	<div><select id="tema" name="tema" required>
<?php foreach ($category as $k): ?>
		<option value="<?= (int) $k['idt'] ?>"<?= (int) $newsItem['tema'] === (int) $k['idt'] ? ' selected' : '' ?>><?= e($k['nazev']) ?></option>
<?php endforeach ?>
	</select><?= $error('tema') ?></div>
</div>
<?php endif ?>
<?php if (count($authors) < 2): ?>
<input type="hidden" name="autor" value="<?= (int) (array_key_first($authors) ?? $newsItem['autor']) ?>">
<?php else: ?>
<div class="radek">
	<label for="autor"><?= e(t('Author')) ?></label>
	<div><select id="autor" name="autor">
<?php foreach ($authors as $userId => $displayName): ?>
		<option value="<?= (int) $userId ?>"<?= (int) $newsItem['autor'] === (int) $userId ? ' selected' : '' ?>><?= e($displayName) ?></option>
<?php endforeach ?>
	</select><?= $error('autor') ?></div>
</div>
<?php endif ?>
<div class="radek">
	<label for="stitky"><?= e(t('Tags')) ?></label>
	<div><input class="textpole siroke" type="text" id="stitky" name="stitky" value="<?= e($tags) ?>" maxlength="600" list="stitky-seznam" autocomplete="off" data-stitky>
	<datalist id="stitky-seznam"><?php foreach ($allTags as $s): ?><option value="<?= e($s) ?>"><?php endforeach ?></datalist>
	<span class="napoveda"><?= e(t('Comma-separated. Visitors can use a tag to see related news.')) ?></span></div>
</div>
</fieldset>

<fieldset>
<legend><?= e(t('Featured image')) ?></legend>
<div class="radek pres-celou">
	<input class="textpole siroke" type="text" id="obrazek" name="obrazek" value="<?= e($newsItem['obrazek']) ?>" maxlength="255" placeholder="<?= e(t('choose from media or paste a URL')) ?>" aria-label="<?= e(t('Featured image')) ?>" data-obrazek>
	<span class="napoveda"><?= e(t('Used in listings and when shared on social networks.')) ?></span>
</div>
<div class="radek pres-celou">
	<label for="obrazek_popis"><?= e(t('Image caption')) ?></label>
	<input class="textpole siroke" type="text" id="obrazek_popis" name="obrazek_popis" value="<?= e($newsItem['obrazek_popis']) ?>" maxlength="300">
</div>
<div class="radek pres-celou">
	<label for="obrazek_autor"><?= e(t('Image credit')) ?></label>
	<div><input class="textpole siroke" type="text" id="obrazek_autor" name="obrazek_autor" value="<?= e($newsItem['obrazek_autor']) ?>" maxlength="120">
	<span class="napoveda"><?= e(t('Empty field = caption and credit from the Media library.')) ?></span></div>
</div>
</fieldset>

<?php if ($siteLanguages): ?>
<details class="pokrocile"<?= $original !== '' || $translations !== [] ? ' open' : '' ?>>
<summary><?= e(t('Translation')) ?></summary>
<?php if ($translationLanguages !== []): ?>
<div class="radek pres-celou">
	<span class="popisek"><?= e(t('Language versions')) ?></span>
	<div class="volby">
<?php foreach ($translationLanguages as $languageCode): $languageName = Kaleta\Core\Language::AVAILABLE[$languageCode][0]; ?>
<?php if (isset($translations[$languageCode])): ?>
		<a class="navigace" href="<?= e($module->url('edit', ['id' => $translations[$languageCode]])) ?>"><?= e($languageName) ?>: <?= e(t('open translation')) ?></a>
<?php elseif ($assistant): ?>
		<button class="navigace" type="submit" name="prelozit_do" value="<?= e($languageCode) ?>" formaction="<?= e($module->url('translate')) ?>" formnovalidate data-potvrdit="<?= e(t('Translate the saved version with the assistant? A draft is created for you to read before publishing. Translation can take up to a minute.')) ?>"><?= e(t('Translate with the assistant')) ?>: <?= e($languageName) ?></button>
<?php else: ?>
		<span class="napoveda vradku"><?= e($languageName) ?>: <?= e(t('no translation yet')) ?></span>
<?php endif ?>
<?php endforeach ?>
	</div>
</div>
<?php endif ?>
<div class="radek pres-celou">
	<label for="preklad_z"><?= e(t('Original in the default language')) ?></label>
	<input class="textpole siroke" type="text" id="preklad_z" name="preklad_z" value="<?= e($original) ?>" maxlength="255" placeholder="<?= e(t('address or number of the original news item')) ?>">
	<span class="napoveda"><?= e(t('Fill in only for a news item in another language version (the category sets the language).')) ?></span>
</div>
</details>
<?php endif ?>

<fieldset class="kontrola" data-kontrola>
<legend><?= e(t('Accessibility check')) ?></legend>
<div data-kontrola-vysledek aria-live="polite"><p class="napoveda"><?= e(t('The check runs while you write (needs JavaScript).')) ?></p></div>
</fieldset>
<?php if ($newsItem['idc']): ?>
<?= $app->view->render('admin/content_check', ['results' => $contentCheck]) ?>
<?php endif ?>

<details class="pokrocile"<?= $newsItem['seo_titulek'] !== '' || $newsItem['seo_popis'] !== '' || (string) $newsItem['faq'] !== '' ? ' open' : '' ?>>
<summary><?= e(t('SEO and more settings')) ?></summary>
<div class="radek">
	<label for="seo_link"><?= e(t('Adresa')) ?></label>
	<div><input class="textpole siroke" type="text" id="seo_link" name="seo_link" value="<?= e($newsItem['seo_link']) ?>" maxlength="150" placeholder="<?= e(t('created from the headline')) ?>">
	<span class="napoveda"><?= e(t('The part of the address after %s. If you change it after publishing, the old address redirects automatically.', substr($app->url('novinky/'), strlen($app->request->basePath())))) ?></span></div>
</div>
<div class="radek">
	<label for="seo_titulek"><?= e(t('Search engine title')) ?></label>
	<div><input class="textpole siroke" type="text" id="seo_titulek" name="seo_titulek" value="<?= e($newsItem['seo_titulek']) ?>" maxlength="255" placeholder="<?= e(t('empty = news item title')) ?>"></div>
</div>
<div class="radek">
	<label for="seo_popis"><?= e(t('Search engine description')) ?></label>
	<div><input class="textpole siroke" type="text" id="seo_popis" name="seo_popis" value="<?= e($newsItem['seo_popis']) ?>" maxlength="320" placeholder="<?= e(t('empty = beginning of the lead')) ?>"></div>
</div>
<div class="radek">
	<label for="t_slova"><?= e(t('Keywords')) ?></label>
	<div><input class="textpole siroke" type="text" id="t_slova" name="t_slova" value="<?= e($newsItem['t_slova']) ?>" maxlength="500">
	<span class="napoveda"><?= e(t('Comma-separated; they help the site search.')) ?></span></div>
</div>
<div class="radek">
	<label for="faq"><?= e(t('Questions and answers')) ?></label>
	<div><textarea class="textbox nizky" id="faq" name="faq" rows="5"><?= e((string) $newsItem['faq']) ?></textarea>
	<span class="napoveda"><?= e(t('Question on one line, the answer below it, an empty line between pairs. Shown below the text and in structured data (FAQ).')) ?></span></div>
</div>
<div class="radek">
	<span class="popisek"><?= e(t('Options')) ?></span>
	<div class="volby"><label><input type="checkbox" name="noindex" value="1"<?= $newsItem['noindex'] ? ' checked' : '' ?>> <?= e(t('Hide from search engines (noindex)')) ?></label></div>
</div>
</details>
<?php if ($versions !== []): ?>
<details class="pokrocile">
<summary><?= e(t('Version history (%s)', count($versions))) ?></summary>
<ul class="revize">
<?php foreach ($versions as $version): ?>
	<li><a href="<?= e($module->url('versions', ['id' => $newsItem['idc'], 'revision' => $version['idr']])) ?>" title="<?= e($version['titulek']) ?>"><?= e(format_date($version['datum'], true)) ?></a> <span class="napoveda vradku"><?= e($version['kdo_jm'] ?? '') ?></span> · <a href="<?= e($module->url('compare', ['id' => $newsItem['idc'], 'revision' => $version['idr']])) ?>"><?= e(t('what changed')) ?></a></li>
<?php endforeach ?>
</ul>
<p class="napoveda"><?= e(t('Click to load an older version into the editor. The last 20 versions are kept.')) ?></p>
</details>
<?php endif ?>
</aside>
</form>
<?php if ($socialDrafts !== null): // social post drafts (2.13): one card per network, each its own form – outside the editor form ?>
<section class="socialni-prispevky" id="social-posts" aria-labelledby="social-posts-nadpis">
<h2 id="social-posts-nadpis"><?= e(t('Social posts')) ?></h2>
<?php if ($socialDrafts === []): ?>
<p class="napoveda"><?= e(t('No network is chosen. Pick the networks under Settings → General → More options and the drafts appear here.')) ?></p>
<?php else: ?>
<p class="napoveda"><?= e(t('Prepared when the news item was published, with a tracked link – the statistics show the visits from each network. Edit the text, copy it and post it yourself; the site never posts anywhere.')) ?></p>
<?php if ($assistant): ?>
<form method="post" action="<?= e($module->url('social_suggest')) ?>" class="vradku">
<?= $csrf ?>
<input type="hidden" name="idc" value="<?= (int) $newsItem['idc'] ?>">
<button class="navigace" type="submit" data-potvrdit="<?= e(t('Rewrite all the drafts with the assistant? Your edits to them are replaced. Hashtags and the link are added back.')) ?>"><?= e(t('Suggest with the assistant')) ?></button>
</form>
<?php endif ?>
<div class="socialni-mrizka">
<?php foreach ($socialDrafts as $d): $id = (int) $d['id']; ?>
<form method="post" action="<?= e($module->url('social_save')) ?>" class="socialni-prispevek<?= $d['posted_at'] ? ' socialni-prispevek--hotovo' : '' ?>">
<?= $csrf ?>
<input type="hidden" name="id" value="<?= $id ?>">
<h3><?= e($d['network_name']) ?><?= $d['posted_at'] ? ' <span class="stitek stitek-vydano">' . e(t('posted %s', format_date($d['posted_at'], true))) . '</span>' : '' ?></h3>
<textarea class="textbox" id="social-text-<?= $id ?>" name="text" rows="8" maxlength="<?= Kaleta\Core\SocialDrafts::MAX_TEXT ?>" aria-label="<?= e(t('Post for %s', $d['network_name'])) ?>"><?= e($d['text']) ?></textarea>
<?php if ($d['network'] === 'x'): ?>
<p class="napoveda"><?= e(t('At most %d characters; a link counts as %d.', Kaleta\Core\SocialDrafts::X_LIMIT, Kaleta\Core\SocialDrafts::X_LINK_LENGTH)) ?></p>
<?php endif ?>
<?php if ($d['image'] !== ''): ?>
<p class="smltxt"><?= e(t('Image:')) ?> <a href="<?= e($d['image']) ?>" target="_blank" rel="noopener"><?= e(mb_strimwidth($d['image'], 0, 70, '…')) ?></a></p>
<?php endif ?>
<?php if ($d['network'] === 'instagram'): ?>
<p class="smltxt"><?= e(t('Link for the bio:')) ?> <code id="social-link-<?= $id ?>"><?= e($d['link']) ?></code> <button class="navigace" type="button" data-kopirovat="#social-link-<?= $id ?>"><?= e(t('Copy')) ?></button></p>
<?php endif ?>
<p class="tlacitka">
	<button class="tl" type="button" data-kopirovat="#social-text-<?= $id ?>"><?= e(t('Copy')) ?></button>
	<button class="navigace" type="submit"><?= e(t('Uložit')) ?></button>
	<button class="navigace" type="submit" formaction="<?= e($module->url('social_posted')) ?>" name="posted" value="<?= $d['posted_at'] ? 0 : 1 ?>" formnovalidate><?= e(t($d['posted_at'] ? 'Not posted yet' : 'Mark as posted')) ?></button>
</p>
</form>
<?php endforeach ?>
</div>
<?php endif ?>
</section>
<?php endif ?>
<script src="<?= e($module->app()->url('image/pomocnik.js')) ?>?v=<?= e(KALETA_VERSION) ?>" defer></script>
