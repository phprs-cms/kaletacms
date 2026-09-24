<?php
/**
 * Úprava stránky nebo novinky přímo na webu: stejný editor jako v administraci, vsazený do šablony webu.
 * Ukládá se běžným formulářem do administrace (akce uloz_text), takže platí stejná oprávnění i revize.
 *
 * @var MiroCMS\Core\App $app
 * @var string $typ       novinka | stranka
 * @var array<string, mixed> $zaznam
 * @var string $akce      adresa pro uložení
 * @var string $zpet      adresa, na kterou se po uložení nebo zrušení vrací
 * @var bool $chyba       uložení se nepovedlo (prázdný titulek)
 */
?>
<link rel="stylesheet" href="<?= e($app->url('image/editor.css')) ?>?v=<?= e(MIROCMS_VERSION) ?>">
<article class="clanek clanek-cely mc-upravit-text mc-ui">
	<form method="post" action="<?= e($akce) ?>">
		<input type="hidden" name="_csrf" value="<?= e($app->session->csrfToken()) ?>">
		<input type="hidden" name="id" value="<?= (int) ($zaznam['idc'] ?? $zaznam['ids']) ?>">
		<input type="hidden" name="zpet" value="<?= e($zpet) ?>">
<?php if ($chyba): ?>
		<p class="mc-upravit-hlaska"><?= e(t('Titulek nesmí zůstat prázdný.')) ?></p>
<?php endif ?>
		<p><label for="mc-titulek"><?= e(t('Titulek')) ?></label>
			<input class="mc-upravit-titulek" type="text" id="mc-titulek" name="titulek" value="<?= e($zaznam['titulek']) ?>" maxlength="200" required></p>
<?php if ($typ === 'novinka'): ?>
		<p><label for="mc-uvod"><?= e(t('Perex')) ?></label>
			<textarea id="mc-uvod" name="uvod" rows="4" data-editor="maly"><?= e($zaznam['uvod']) ?></textarea></p>
<?php endif ?>
		<p><label for="mc-text"><?= e(t('Text')) ?></label>
			<textarea id="mc-text" name="text" rows="18" data-editor><?= e($zaznam['text']) ?></textarea></p>
		<div class="mc-upravit-lista">
			<button class="mc-tl" type="submit"><?= e(t('Uložit')) ?></button>
			<a class="mc-tl mc-tl-vedlejsi" href="<?= e($zpet) ?>"><?= e(t('Zrušit')) ?></a>
			<a class="mc-upravit-vse" href="<?= e($app->url('admin.php?modul=' . ($typ === 'novinka' ? 'novinky' : 'stranky') . '&akce=edit&id=' . (int) ($zaznam['idc'] ?? $zaznam['ids']))) ?>"><?= e(t('Všechna nastavení v administraci')) ?></a>
		</div>
	</form>
</article>
<script src="<?= e($app->url('image/editor.js')) ?>?v=<?= e(MIROCMS_VERSION) ?>" data-admin-url="<?= e($app->url('admin.php')) ?>" defer></script>
