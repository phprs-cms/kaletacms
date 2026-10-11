<?php
/**
 * Comment mode of a shared draft preview (2.15, Front\DraftComments): a floating button, a small form with the visitor's name
 * and the comment, and a script that attaches the comment to the element the visitor clicks (data-ka-id) or quotes the text
 * they selected. Self-contained – the preview page has no other script of its own.
 *
 * @var string $cil      signed preview target, e.g. stranka:12
 * @var string $klic     the preview key (it allows comments)
 * @var string $zpet     where the form comes back to – the preview address
 * @var string $akce     POST address
 * @var string $vysledek '' | ok | chyba | limit – the result of the previous send
 */
?>
<div class="ka-komentare" id="ka-komentar" data-ka-komentare>
<style>
.ka-komentare{position:fixed;inset-inline-end:1rem;bottom:1rem;z-index:2147482000;font:14px/1.45 system-ui,sans-serif;color:#16181d}
.ka-komentare-tl{display:inline-flex;align-items:center;gap:.5rem;padding:.7rem 1.1rem;border:0;border-radius:999px;background:#16181d;color:#fff;font:600 14px/1 system-ui,sans-serif;cursor:pointer;box-shadow:0 6px 24px rgba(0,0,0,.25)}
.ka-komentare-panel{position:fixed;inset-inline-end:1rem;bottom:4.5rem;width:min(22rem,calc(100vw - 2rem));max-height:calc(100vh - 6rem);overflow:auto;padding:1rem;border-radius:12px;background:#fff;color:#16181d;box-shadow:0 10px 40px rgba(0,0,0,.3)}
.ka-komentare-panel h2{margin:0 0 .5rem;font-size:1.05rem}
.ka-komentare-panel p{margin:0 0 .6rem}
.ka-komentare-panel label{display:block;margin:0 0 .6rem;font-weight:600}
.ka-komentare-panel input[type=text],.ka-komentare-panel textarea{display:block;width:100%;box-sizing:border-box;margin-top:.25rem;padding:.5rem;border:1px solid #c6c9d0;border-radius:8px;background:#fff;color:#16181d;font:inherit;font-weight:400}
.ka-komentare-panel textarea{min-height:6rem;resize:vertical}
.ka-komentare-cil,.ka-komentare-citace{padding:.4rem .6rem;border-radius:8px;background:#f1f3f6;font-size:13px}
.ka-komentare-cil button{margin-inline-start:.4rem;border:0;background:none;color:inherit;font:inherit;cursor:pointer;text-decoration:underline}
.ka-komentare-pozn{color:#5a5f6b;font-size:13px}
.ka-komentare-vysledek{padding:.5rem .7rem;border-radius:8px;background:#e6f4ea;color:#14532d}
.ka-komentare-vysledek.chyba{background:#fdecea;color:#7f1d1d}
.ka-komentar-vybrany{outline:3px solid #ff4f2e !important;outline-offset:2px}
[data-ka-komentare-rezim] [data-ka-id]{cursor:crosshair}
</style>
<button type="button" class="ka-komentare-tl" aria-expanded="false" aria-controls="ka-komentar-panel"><?= e(t('Comment on this draft')) ?></button>
<form class="ka-komentare-panel" id="ka-komentar-panel" method="post" action="<?= e($akce) ?>" hidden>
	<h2><?= e(t('Comment on this draft')) ?></h2>
<?php if ($vysledek === 'ok'): ?>
	<p class="ka-komentare-vysledek" role="status"><?= e(t('Thank you, your comment was sent to the people who edit this page.')) ?></p>
<?php elseif ($vysledek === 'limit'): ?>
	<p class="ka-komentare-vysledek chyba" role="status"><?= e(t('That is a lot of comments in a short time – please try again in a few minutes.')) ?></p>
<?php elseif ($vysledek === 'chyba'): ?>
	<p class="ka-komentare-vysledek chyba" role="status"><?= e(t('The comment could not be saved. Write your name and the comment and try again.')) ?></p>
<?php endif ?>
	<p class="ka-komentare-pozn"><?= e(t('Click a part of the page to attach the comment to it, or select a piece of text to quote it.')) ?></p>
	<p class="ka-komentare-cil" data-ka-komentar-prvek hidden><?= e(t('Attached to:')) ?> <span></span><button type="button"><?= e(t('detach')) ?></button></p>
	<p class="ka-komentare-citace" data-ka-komentar-citace hidden>„<span></span>“</p>
	<input type="hidden" name="cil" value="<?= e($cil) ?>"><input type="hidden" name="klic" value="<?= e($klic) ?>"><input type="hidden" name="zpet" value="<?= e($zpet) ?>">
	<input type="hidden" name="prvek" value=""><input type="hidden" name="citace" value="">
	<div style="position:absolute;inset-inline-start:-9999px" aria-hidden="true"><label><?= e(t('Leave this field empty')) ?> <input type="text" name="web_adresa" tabindex="-1" autocomplete="off"></label></div>
	<label><?= e(t('Your name')) ?> <input type="text" name="jmeno" required maxlength="<?= Kaleta\Core\DraftComments::MAX_NAME ?>" autocomplete="name"></label>
	<label><?= e(t('Comment')) ?> <textarea name="text" required maxlength="<?= Kaleta\Core\DraftComments::MAX_TEXT ?>"></textarea></label>
	<p><button type="submit" class="ka-komentare-tl"><?= e(t('Send comment')) ?></button></p>
	<p class="ka-komentare-pozn"><?= e(t('The comment goes to the people who edit this page. Nothing on the site changes by itself.')) ?></p>
</form>
<script>
(function () {
	var box = document.getElementById('ka-komentar'), button = box.querySelector('.ka-komentare-tl'), form = document.getElementById('ka-komentar-panel');
	var elementField = form.elements.prvek, quoteField = form.elements.citace, elementRow = form.querySelector('[data-ka-komentar-prvek]'), quoteRow = form.querySelector('[data-ka-komentar-citace]');
	var selected = null;
	function open(on) {
		form.hidden = !on; button.setAttribute('aria-expanded', on ? 'true' : 'false');
		if (on) { document.documentElement.setAttribute('data-ka-komentare-rezim', ''); form.elements.jmeno.focus(); } else { document.documentElement.removeAttribute('data-ka-komentare-rezim'); }
	}
	function attach(target) {
		if (selected) { selected.classList.remove('ka-komentar-vybrany'); }
		selected = target;
		if (target) {
			target.classList.add('ka-komentar-vybrany');
			elementField.value = target.getAttribute('data-ka-id');
			elementRow.querySelector('span').textContent = (target.textContent || '').trim().replace(/\s+/g, ' ').slice(0, 60) || elementField.value;
		} else { elementField.value = ''; }
		elementRow.hidden = !target;
	}
	button.addEventListener('click', function () { open(form.hidden); });
	elementRow.querySelector('button').addEventListener('click', function () { attach(null); });
	document.addEventListener('click', function (e) {
		if (form.hidden || box.contains(e.target)) { return; }
		var target = e.target.closest('[data-ka-id]');
		if (!target) { return; }
		e.preventDefault(); // in comment mode a click chooses the element, it does not follow links
		attach(target);
	}, true);
	document.addEventListener('mouseup', function (e) {
		if (form.hidden || box.contains(e.target)) { return; }
		var text = String(window.getSelection && window.getSelection()).trim().replace(/\s+/g, ' ').slice(0, <?= Kaleta\Core\DraftComments::MAX_QUOTE ?>);
		if (text) { quoteField.value = text; quoteRow.querySelector('span').textContent = text; quoteRow.hidden = false; }
	});
	try { form.elements.jmeno.value = localStorage.getItem('ka-komentar-jmeno') || ''; } catch (e) { /* storage blocked */ }
	form.addEventListener('submit', function () { try { localStorage.setItem('ka-komentar-jmeno', form.elements.jmeno.value); } catch (e) { /* storage blocked */ } });
	if (<?= $vysledek !== '' ? 'true' : 'false' ?>) { open(true); }
})();
</script>
</div>
