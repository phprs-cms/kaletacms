/* MiroCMS - skript webu pro návštěvníky. Bez knihoven; vše je volitelné vylepšení, web funguje i bez JavaScriptu.
 * Menu na telefonu, dialogy a rozbalování řeší HTML a CSS (popover, <details>), ne tento skript. */
(function () {
	'use strict';

	/* ---------- texty: česky v kódu, překlad jazykové verze posílá Front\Seo::hlava() v atributu data-texty značky <script> ---------- */

	var texty = {};
	try {
		var znacka = document.currentScript || document.querySelector('script[data-texty]');
		texty = JSON.parse((znacka && znacka.getAttribute('data-texty')) || '{}') || {};
	} catch (e) { texty = {}; }
	/* bez atributu (vlastní šablona načítá skript jinak) zůstanou texty česky */
	function T(cesky) { return typeof texty[cesky] === 'string' && texty[cesky] !== '' ? texty[cesky] : cesky; }
	function A(cesky) { return T(cesky).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;'); }

	/* ---------- prohlížečka fotek: fotogalerie i jednotlivé obrázky v textu ---------- */

	var okno = null, fotky = [], pozice = 0;

	function ukaz(i) {
		pozice = (i + fotky.length) % fotky.length;
		var zdroj = fotky[pozice];
		okno.querySelector('img').src = zdroj.getAttribute('src');
		okno.querySelector('img').alt = zdroj.alt;
		var popis = zdroj.alt || ((zdroj.closest('figure') || document).querySelector('figcaption') || {}).textContent || '';
		okno.querySelector('p').textContent = (fotky.length > 1 ? (pozice + 1) + ' / ' + fotky.length + (popis ? ' · ' : '') : '') + popis;
	}

	function otevri(seznam, index) {
		if (!okno) {
			okno = document.createElement('dialog');
			okno.className = 'mc-prohlizecka';
			okno.innerHTML = '<img alt=""><p aria-live="polite"></p><button type="button" data-krok="-1" aria-label="' + A('Předchozí fotka') + '">‹</button>'
				+ '<button type="button" data-krok="1" aria-label="' + A('Další fotka') + '">›</button><button type="button" data-zavrit aria-label="' + A('Zavřít') + '">×</button>';
			document.body.appendChild(okno);
			okno.addEventListener('click', function (e) {
				var krok = e.target.getAttribute('data-krok');
				if (krok) { ukaz(pozice + parseInt(krok, 10)); } else if (e.target.tagName !== 'IMG') { okno.close(); }
			});
			okno.addEventListener('keydown', function (e) {
				if (e.key === 'ArrowLeft') { ukaz(pozice - 1); }
				if (e.key === 'ArrowRight') { ukaz(pozice + 1); }
			});
			var start = null;
			okno.addEventListener('touchstart', function (e) { start = e.changedTouches[0].clientX; }, { passive: true });
			okno.addEventListener('touchend', function (e) {
				var posun = e.changedTouches[0].clientX - start;
				if (Math.abs(posun) > 50 && fotky.length > 1) { ukaz(pozice + (posun < 0 ? 1 : -1)); }
			}, { passive: true });
		}
		fotky = seznam;
		okno.querySelectorAll('[data-krok]').forEach(function (b) { b.hidden = fotky.length < 2; });
		ukaz(index);
		okno.showModal();
	}

	document.addEventListener('click', function (e) {
		var img = e.target;
		if (img.tagName !== 'IMG' || img.closest('a') || !img.closest('.text, .perex, figure.galerie')) { return; }
		var galerie = img.closest('figure.galerie');
		var seznam = Array.prototype.slice.call((galerie || img.closest('.text, .perex')).querySelectorAll(galerie ? 'img' : 'figure:not(.galerie) img'));
		if (seznam.indexOf(img) === -1) { seznam = [img]; }
		otevri(seznam, seznam.indexOf(img));
	});

	/* ---------- sdílení novinky: systémové sdílení (telefon) a kopírování odkazu ---------- */

	document.querySelectorAll('[data-sdilet]').forEach(function (tl) {
		if (!navigator.share) { return; }
		tl.hidden = false;
		tl.addEventListener('click', function () {
			navigator.share({ title: tl.getAttribute('data-titulek'), url: tl.getAttribute('data-adresa') }).catch(function () { /* návštěvník sdílení zavřel */ });
		});
	});
	document.addEventListener('click', function (e) {
		var tl = e.target.closest && e.target.closest('[data-kopirovat]');
		if (!tl || !navigator.clipboard) { return; }
		var puvodni = tl.textContent;
		navigator.clipboard.writeText(tl.getAttribute('data-kopirovat')).then(function () {
			tl.textContent = tl.getAttribute('data-hotovo');
			setTimeout(function () { tl.textContent = puvodni; }, 2000);
		});
	});

	/* ---------- přehrávač cizí služby se vloží až po kliknutí ---------- */

	document.addEventListener('click', function (e) {
		var tl = e.target.closest && e.target.closest('[data-vlozit]');
		if (!tl) { return; }
		var ram = document.createElement('iframe');
		ram.src = tl.getAttribute('data-vlozit');
		ram.title = tl.getAttribute('data-titulek') || '';
		ram.allow = 'autoplay; encrypted-media; picture-in-picture; fullscreen';
		ram.allowFullscreen = true;
		ram.loading = 'lazy';
		tl.replaceWith(ram);
	});
})();
