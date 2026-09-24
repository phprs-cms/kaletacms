<?php
/**
 * Vestavěná cookie lišta. Souhlas se ukládá do cookie "kaleta_souhlas" na 6 měsíců.
 * Skripty čekající na souhlas mají type="text/plain" data-souhlas="analytika", marketingové kódy jsou v <template data-souhlas="marketing">.
 * Vzhled je záměrně neutrální a nezávislý na layoutu; layout ho může přepsat třídami .cookies-*.
 *
 * @var string $text
 * @var string $zasady
 * @var bool $analytika
 * @var bool $marketing
 * @var string $evidence  adresa pro zápis souhlasu, prázdná = neevidovat
 */
?>
<div class="cookies-lista" id="cookies-lista" role="dialog" aria-modal="false" aria-labelledby="cookies-nadpis" hidden>
	<div class="cookies-obsah">
		<strong id="cookies-nadpis"><?= e(t('Soukromí a cookies')) ?></strong>
		<p><?= nl2br(e($text)) ?><?php if ($zasady !== ''): ?> <a href="<?= e($zasady) ?>"><?= e(t('Více informací')) ?></a><?php endif ?></p>
		<div class="cookies-volby" hidden>
			<label><input type="checkbox" checked disabled> <?= e(t('Nezbytné – bez nich web nefunguje')) ?></label>
<?php if ($analytika): ?>
			<label><input type="checkbox" data-kategorie="analytika"> <?= e(t('Analytické – anonymní měření návštěvnosti')) ?></label>
<?php endif ?>
<?php if ($marketing): ?>
			<label><input type="checkbox" data-kategorie="marketing"> <?= e(t('Marketingové – měření kampaní a cílení reklamy')) ?></label>
<?php endif ?>
		</div>
		<div class="cookies-tlacitka">
			<button type="button" data-cookies="vse"><?= e(t('Přijmout vše')) ?></button>
			<button type="button" data-cookies="nic"><?= e(t('Jen nezbytné')) ?></button>
			<button type="button" data-cookies="nastavit" class="cookies-odkaz"><?= e(t('Nastavení')) ?></button>
			<button type="button" data-cookies="ulozit" hidden><?= e(t('Uložit výběr')) ?></button>
		</div>
	</div>
</div>
<button type="button" class="cookies-znovu" id="cookies-znovu" hidden><?= e(t('Nastavení cookies')) ?></button>
<style>
.cookies-lista { position: fixed; z-index: 1000; left: 16px; right: 16px; bottom: 16px; max-width: 560px; margin: 0 auto 0 0; padding: 18px 20px; border: 1px solid #D0D5DD; border-radius: 10px; background: #FFFFFF; color: #14171F; box-shadow: 0 12px 40px rgb(0 0 0 / 0.18); font: 14px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif; }
.cookies-lista p { margin: 6px 0 12px; }
.cookies-lista a { color: inherit; }
.cookies-volby { display: grid; gap: 6px; margin-bottom: 14px; }
.cookies-tlacitka { display: flex; flex-wrap: wrap; gap: 8px; }
.cookies-tlacitka button { padding: 9px 16px; border: 1px solid #14171F; border-radius: 6px; background: #14171F; color: #FFFFFF; font: inherit; font-weight: 600; cursor: pointer; }
.cookies-tlacitka button[data-cookies="nic"], .cookies-tlacitka button[data-cookies="ulozit"] { background: #FFFFFF; color: #14171F; }
.cookies-tlacitka .cookies-odkaz { border-color: transparent; background: none; color: #14171F; text-decoration: underline; font-weight: 400; padding-left: 4px; padding-right: 4px; }
.cookies-znovu { position: fixed; z-index: 999; left: 12px; bottom: 12px; padding: 5px 10px; border: 1px solid #D0D5DD; border-radius: 6px; background: #FFFFFF; color: #475467; font: 12px system-ui, sans-serif; cursor: pointer; opacity: 0.85; }
</style>
<script>
(function () {
	var lista = document.getElementById('cookies-lista'), znovu = document.getElementById('cookies-znovu');
	var volby = lista.querySelector('.cookies-volby');
	function precti() { var m = document.cookie.match(/(?:^|; )kaleta_souhlas=([^;]*)/); return m ? decodeURIComponent(m[1]).split(',') : null; }
	function povol(kategorie) {
		document.querySelectorAll('script[type="text/plain"][data-souhlas="' + kategorie + '"]').forEach(function (s) {
			var n = document.createElement('script');
			if (s.src || s.getAttribute('src')) { n.src = s.getAttribute('src'); n.async = true; } else { n.text = s.text; }
			s.replaceWith(n);
		});
		document.querySelectorAll('template[data-souhlas="' + kategorie + '"]').forEach(function (t) {
			var box = document.createElement('div');
			box.appendChild(t.content.cloneNode(true));
			box.querySelectorAll('script').forEach(function (s) { var n = document.createElement('script'); Array.prototype.forEach.call(s.attributes, function (a) { n.setAttribute(a.name, a.value); }); n.text = s.text; s.replaceWith(n); });
			t.replaceWith.apply(t, Array.prototype.slice.call(box.childNodes));
		});
		if (window.gtag) {
			gtag('consent', 'update', kategorie === 'analytika' ? { analytics_storage: 'granted' } : { ad_storage: 'granted', ad_user_data: 'granted', ad_personalization: 'granted' });
		}
	}
	function uloz(kategorie) {
		var bylo = precti() || [];
		document.cookie = 'kaleta_souhlas=' + encodeURIComponent(kategorie.join(',') || 'nic') + '; path=/; max-age=' + (180 * 86400) + '; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : '');
		lista.hidden = true; znovu.hidden = false;
		var evidence = <?= json_encode($evidence) ?>;
		if (evidence) {
			var id = (document.cookie.match(/(?:^|; )kaleta_souhlas_id=([a-f0-9]{32})/) || [])[1];
			if (!id) { id = Array.prototype.map.call(crypto.getRandomValues(new Uint8Array(16)), function (b) { return ('0' + b.toString(16)).slice(-2); }).join(''); document.cookie = 'kaleta_souhlas_id=' + id + '; path=/; max-age=' + (180 * 86400) + '; SameSite=Lax'; }
			var data = new FormData(); data.append('id', id); data.append('kategorie', kategorie.join(',') || 'nic');
			if (navigator.sendBeacon) { navigator.sendBeacon(evidence, data); } else { fetch(evidence, { method: 'POST', body: data }); }
		}
		// odvolaný souhlas se projeví po novém načtení stránky (už spuštěný skript nejde zastavit)
		if (bylo.some(function (k) { return k !== 'nic' && kategorie.indexOf(k) === -1; })) { location.reload(); return; }
		kategorie.forEach(povol);
	}
	lista.addEventListener('click', function (e) {
		var akce = e.target.getAttribute && e.target.getAttribute('data-cookies');
		if (akce === 'vse') { uloz(Array.prototype.map.call(volby.querySelectorAll('[data-kategorie]'), function (c) { return c.getAttribute('data-kategorie'); })); }
		if (akce === 'nic') { uloz([]); }
		if (akce === 'ulozit') { uloz(Array.prototype.map.call(volby.querySelectorAll('[data-kategorie]:checked'), function (c) { return c.getAttribute('data-kategorie'); })); }
		if (akce === 'nastavit') { volby.hidden = false; e.target.hidden = true; lista.querySelector('[data-cookies="ulozit"]').hidden = false; }
	});
	znovu.addEventListener('click', function () {
		var ma = precti() || [];
		volby.querySelectorAll('[data-kategorie]').forEach(function (c) { c.checked = ma.indexOf(c.getAttribute('data-kategorie')) !== -1; });
		volby.hidden = false; lista.querySelector('[data-cookies="nastavit"]').hidden = true; lista.querySelector('[data-cookies="ulozit"]').hidden = false;
		lista.hidden = false; znovu.hidden = true;
	});
	var souhlas = precti();
	if (souhlas === null) { lista.hidden = false; } else { znovu.hidden = false; souhlas.forEach(function (k) { if (k !== 'nic') { povol(k); } }); }
})();
</script>
