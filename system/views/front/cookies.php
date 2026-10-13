<?php
/**
 * Built-in cookie bar. Consent is stored in the cookie "kaleta_souhlas" for 6 months. A browser sending Global Privacy
 * Control counts as "only necessary" without asking (2.3).
 * Scripts waiting for consent have type="text/plain" data-souhlas="analytika", marketing codes are in <template data-souhlas="marketing">.
 * The appearance is deliberately neutral and independent of the layout; the layout can override it with the .cookies-* classes.
 * The bar is non-modal and sits over the bottom of the page, so it must never hide keyboard focus (WCAG 2.2 SC 2.4.11, 3.5):
 * the script moves it right after the skip link (reached with the second Tab), and while it shows, --ka-cookies-vyska on <html>
 * (the bar's real distance from the bottom of the window) gives the page as much scroll padding and room at its end and lifts
 * the accessibility toolbar above it. On a phone the bar is compact: two lines of text, the buttons in a row, categories behind
 * Settings.
 *
 * @var string $text
 * @var string $zasady
 * @var bool $analytika
 * @var bool $marketing
 * @var string $evidence  url for recording consent, empty = do not record
 */
?>
<div class="cookies-lista" id="cookies-lista" role="dialog" aria-modal="false" aria-labelledby="cookies-nadpis" hidden>
	<div class="cookies-obsah">
		<strong id="cookies-nadpis"><?= e(t('Privacy and cookies')) ?></strong>
		<p><span class="cookies-text"><?= nl2br(e($text)) ?></span><?php if ($zasady !== ''): ?> <a href="<?= e($zasady) ?>"><?= e(t('More about cookies and privacy')) ?></a><?php endif ?></p>
		<div class="cookies-volby" hidden>
			<label><input type="checkbox" checked disabled> <?= e(t('Necessary – the site does not work without them')) ?></label>
<?php if ($analytika): ?>
			<label><input type="checkbox" data-kategorie="analytika"> <?= e(t('Analytics – anonymous traffic measurement')) ?></label>
<?php endif ?>
<?php if ($marketing): ?>
			<label><input type="checkbox" data-kategorie="marketing"> <?= e(t('Marketing – campaign measurement and ad targeting')) ?></label>
<?php endif ?>
		</div>
		<div class="cookies-tlacitka">
			<button type="button" data-cookies="vse"><?= e(t('Accept all')) ?></button>
			<button type="button" data-cookies="nic"><?= e(t('Only necessary')) ?></button>
			<button type="button" data-cookies="nastavit" class="cookies-odkaz"><?= e(t('Nastavení')) ?></button>
			<button type="button" data-cookies="ulozit" hidden><?= e(t('Save selection')) ?></button>
		</div>
	</div>
</div>
<button type="button" class="cookies-znovu" id="cookies-znovu" hidden><?= e(t('Cookie settings')) ?></button>
<style>
.cookies-lista { position: fixed; z-index: 1000; inset-inline: 16px; bottom: 16px; max-width: 560px; max-height: calc(100vh - 32px); overflow-y: auto; margin-block: 0; margin-inline: 0 auto; padding: 18px 20px; border: 1px solid #D0D5DD; border-radius: 10px; background: #FFFFFF; color: #14171F; box-shadow: 0 12px 40px rgb(0 0 0 / 0.18); font: 14px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif; }
.cookies-lista p { margin: 6px 0 12px; }
.cookies-lista a { color: inherit; }
.cookies-volby:not([hidden]) { display: grid; gap: 6px; margin-bottom: 14px; }
.cookies-tlacitka { display: flex; flex-wrap: wrap; gap: 8px; }
.cookies-tlacitka button { padding: 9px 16px; border: 1px solid #14171F; border-radius: 6px; background: #14171F; color: #FFFFFF; font: inherit; font-weight: 600; cursor: pointer; }
.cookies-tlacitka button[data-cookies="nic"], .cookies-tlacitka button[data-cookies="ulozit"] { background: #FFFFFF; color: #14171F; }
.cookies-tlacitka .cookies-odkaz { border-color: transparent; background: none; color: #14171F; text-decoration: underline; font-weight: 400; padding-inline: 4px; }
.cookies-znovu { position: fixed; z-index: 999; inset-inline-start: 12px; bottom: 12px; padding: 5px 10px; border: 1px solid #D0D5DD; border-radius: 6px; background: #FFFFFF; color: #475467; font: 12px system-ui, sans-serif; cursor: pointer; opacity: 0.85; }
/* while the bar shows, focus that moves to the bottom of the window scrolls clear of it, and the end of the page can scroll above it */
:root:has(#cookies-lista:not([hidden])) { scroll-padding-bottom: var(--ka-cookies-vyska, 0px); padding-bottom: var(--ka-cookies-vyska, 0px); }
@media (max-width: 600px) {
	.cookies-lista { inset-inline: 8px; bottom: 8px; max-height: calc(100vh - 16px); padding: 12px 14px; font-size: 13px; line-height: 1.4; }
	.cookies-lista p { margin: 2px 0 10px; }
	/* two lines of the text until the visitor opens Settings; the link to the policy follows on its own line */
	.cookies-lista:has(.cookies-volby[hidden]) .cookies-text { display: -webkit-box; -webkit-box-orient: vertical; -webkit-line-clamp: 2; line-clamp: 2; overflow: hidden; }
	.cookies-tlacitka { gap: 6px; }
	.cookies-tlacitka button { padding: 8px 9px; }
	.cookies-tlacitka .cookies-odkaz { padding-inline: 2px; }
}
</style>
<script>
(function () {
	var lista = document.getElementById('cookies-lista'), znovu = document.getElementById('cookies-znovu');
	var volby = lista.querySelector('.cookies-volby'), koren = document.documentElement;
	// early in the tab order: right after the skip link (the bar stays non-modal, the page around it works)
	var preskocit = document.querySelector('body > .preskocit');
	if (preskocit) { preskocit.after(lista); } else { document.body.prepend(lista); }
	// the bar's real distance from the bottom of the window: scroll padding, room at the end of the page, toolbars above it
	function vyska() {
		if (lista.hidden) { koren.style.removeProperty('--ka-cookies-vyska'); return; }
		koren.style.setProperty('--ka-cookies-vyska', Math.ceil(window.innerHeight - lista.getBoundingClientRect().top + 8) + 'px');
	}
	if (window.ResizeObserver) { new ResizeObserver(vyska).observe(lista); }
	window.addEventListener('resize', vyska);
	new MutationObserver(vyska).observe(lista, { attributes: true, attributeFilter: ['hidden'] });
	function precti() { var m = document.cookie.match(/(?:^|; )kaleta_souhlas=([^;]*)/); return m ? decodeURIComponent(m[1]).split(',') : null; }
	function povol(kategorie) {
		// Google consent mode first, so tags that start now already see the granted consent
		if (window.gtag) {
			gtag('consent', 'update', kategorie === 'analytika' ? { analytics_storage: 'granted' } : { ad_storage: 'granted', ad_user_data: 'granted', ad_personalization: 'granted' });
		}
		// Google Tag Manager (2.6) starts with the first consent to analytics or marketing; its tags follow consent mode
		document.querySelectorAll('script[type="text/plain"][data-souhlas="' + kategorie + '"], script[type="text/plain"][data-gtm]').forEach(function (s) {
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
		if (kategorie === 'marketing') { puvod(true); }
		if (Array.isArray(window.dataLayer)) { window.dataLayer.push({ event: 'kaleta_consent', consent: kategorie === 'analytika' ? 'analytics' : 'marketing' }); }
	}
	// where a lead came from (2.3): with consent to marketing, the first page of this visit, its campaign and the site that
	// sent the visitor are remembered for this tab only (sessionStorage) and go with forms and newsletter sign-ups
	function puvod(povoleno) {
		var klic = 'ka-puvod', data = null;
		try {
			if (!povoleno) { sessionStorage.removeItem(klic); return; }
			data = JSON.parse(sessionStorage.getItem(klic) || 'null');
			if (!data) {
				var q = new URLSearchParams(location.search), utm = new URLSearchParams(), odkud = '';
				['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'].forEach(function (k) { if (q.get(k)) { utm.set(k, q.get(k).slice(0, 80)); } });
				try { odkud = document.referrer ? new URL(document.referrer).hostname.replace(/^www\./, '') : ''; } catch (e) { odkud = ''; }
				if (odkud === location.hostname.replace(/^www\./, '')) { odkud = ''; }
				data = { vstup: location.pathname, kampan: utm.toString(), odkud: odkud };
				sessionStorage.setItem(klic, JSON.stringify(data));
			}
		} catch (e) { return; }
		var vypln = function (jmeno, hodnota) { document.querySelectorAll('input[name="' + jmeno + '"]').forEach(function (i) { i.value = hodnota || ''; }); };
		vypln('ka_vstup', data.vstup); vypln('ka_kampan', data.kampan); vypln('ka_odkud', data.odkud);
	}
	function uloz(kategorie) {
		var bylo = precti() || [];
		document.cookie = 'kaleta_souhlas=' + encodeURIComponent(kategorie.join(',') || 'nic') + '; path=/; max-age=' + (180 * 86400) + '; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : '');
		lista.hidden = true; znovu.hidden = false;
		var evidence = <?= json_encode($evidence) ?>;
		if (evidence) {
			var id = (document.cookie.match(/(?:^|; )kaleta_souhlas_id=([a-f0-9]{32})/) || [])[1];
			if (!id) { id = Array.prototype.map.call(crypto.getRandomValues(new Uint8Array(16)), function (b) { return ('0' + b.toString(16)).slice(-2); }).join(''); document.cookie = 'kaleta_souhlas_id=' + id + '; path=/; max-age=' + (180 * 86400) + '; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : ''); }
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
	if (souhlas === null || souhlas.indexOf('marketing') === -1) { puvod(false); }
	// Global Privacy Control (2.3): a browser that asks not to be tracked counts as "only necessary" until the visitor chooses
	// otherwise – the bar does not ask, the settings button stays
	if (souhlas === null && navigator.globalPrivacyControl === true) { znovu.hidden = false; return; }
	if (souhlas === null) { lista.hidden = false; } else { znovu.hidden = false; souhlas.forEach(function (k) { if (k !== 'nic') { povol(k); } }); }
})();
</script>
