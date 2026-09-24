/* Kaleta - skript webu pro návštěvníky. Bez knihoven; vše je volitelné vylepšení, web funguje i bez JavaScriptu.
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
			okno.className = 'ka-prohlizecka';
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
		if (img.tagName !== 'IMG' || img.closest('a') || !img.closest('.text, .perex, figure.galerie, .ka-galerie')) { return; }
		var galerie = img.closest('figure.galerie, .ka-galerie');
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

	/* ---------- záložky (ARIA tabs): bez skriptu jsou vidět všechny panely ---------- */

	document.querySelectorAll('[data-zalozky]').forEach(function (z) {
		var karty = Array.prototype.slice.call(z.querySelectorAll('[role="tab"]'));
		function prepni(karta, fokus) {
			karty.forEach(function (k) {
				var vybrana = k === karta;
				k.setAttribute('aria-selected', vybrana ? 'true' : 'false');
				k.tabIndex = vybrana ? 0 : -1;
				document.getElementById(k.getAttribute('aria-controls')).hidden = !vybrana;
			});
			if (fokus) { karta.focus(); }
		}
		karty.forEach(function (k, i) {
			k.addEventListener('click', function () { prepni(k, false); });
			k.addEventListener('keydown', function (e) {
				var dalsi = { ArrowRight: i + 1, ArrowLeft: i - 1, Home: 0, End: karty.length - 1 }[e.key];
				if (dalsi === undefined) { return; }
				e.preventDefault();
				prepni(karty[(dalsi + karty.length) % karty.length], true);
			});
		});
		z.setAttribute('data-zapnuto', '');
		if (karty.length) { prepni(karty[0], false); }
	});

	/* ---------- karusel: šipky posouvají pás o šířku viditelných snímků ---------- */

	document.querySelectorAll('[data-karusel]').forEach(function (k) {
		var pas = k.querySelector('.ka-karusel-pas');
		var sipky = k.querySelectorAll('[data-krok]');
		function stav() {
			sipky[0].disabled = pas.scrollLeft <= 2;
			sipky[1].disabled = pas.scrollLeft + pas.clientWidth >= pas.scrollWidth - 2;
		}
		sipky.forEach(function (b) {
			b.addEventListener('click', function () { pas.scrollBy({ left: parseInt(b.getAttribute('data-krok'), 10) * pas.clientWidth, behavior: 'smooth' }); });
		});
		pas.addEventListener('scroll', stav, { passive: true });
		k.setAttribute('data-zapnuto', '');
		stav();
	});

	/* ---------- vyskakovací okno: odkaz #kotva ho otevře, případně samo jednou za návštěvu ---------- */

	document.addEventListener('click', function (e) {
		var odkaz = e.target.closest && e.target.closest('a[href^="#"]');
		var okno = odkaz && odkaz.getAttribute('href').length > 1 && document.getElementById(odkaz.getAttribute('href').slice(1));
		if (!okno || !okno.hasAttribute('popover') || !okno.showPopover) { return; }
		e.preventDefault();
		okno.showPopover();
	});
	document.querySelectorAll('[popover][data-samo]').forEach(function (okno) {
		var klic = 'ka-okno-' + okno.id;
		try { if (sessionStorage.getItem(klic)) { return; } } catch (chyba) { /* soukromý režim */ }
		setTimeout(function () {
			if (!okno.showPopover || document.querySelector(':popover-open')) { return; }
			okno.showPopover();
			try { sessionStorage.setItem(klic, '1'); } catch (chyba) { /* soukromý režim */ }
		}, parseInt(okno.getAttribute('data-samo'), 10) * 1000);
	});

	/* ---------- formuláře: po chybě vrátit vyplněné hodnoty, po odeslání ohlásit konverzi ---------- */

	// hodnoty drží jen prohlížeč návštěvníka (sessionStorage) a po úspěšném odeslání zmizí; do adresy se nic nepíše
	document.querySelectorAll('form[data-formular]').forEach(function (f) {
		var klic = 'ka-formular-' + f.getAttribute('data-formular');
		f.addEventListener('submit', function () {
			var hodnoty = {};
			Array.prototype.forEach.call(f.elements, function (p) {
				if (!/^p\d+$/.test(p.name)) { return; }
				if (p.type === 'checkbox' || p.type === 'radio') { if (p.checked) { hodnoty[p.name] = p.value; } } else { hodnoty[p.name] = p.value; }
			});
			try { sessionStorage.setItem(klic, JSON.stringify(hodnoty)); } catch (chyba) { /* soukromý režim */ }
		});
		if (!f.hasAttribute('data-obnovit')) { return; }
		var ulozene = null;
		try { ulozene = JSON.parse(sessionStorage.getItem(klic) || 'null'); } catch (chyba) { /* nic */ }
		if (!ulozene) { return; }
		Array.prototype.forEach.call(f.elements, function (p) {
			if (!(p.name in ulozene)) { return; }
			if (p.type === 'checkbox' || p.type === 'radio') { p.checked = p.value === ulozene[p.name]; } else { p.value = ulozene[p.name]; }
		});
	});
	var odeslano = new URLSearchParams(location.search).get('odeslano');
	Array.prototype.map.call(document.querySelectorAll('[data-odeslano]'), function (h) { return h.getAttribute('data-odeslano'); }).concat(odeslano ? [odeslano] : []).forEach(function (nazev) {
		try { Object.keys(sessionStorage).forEach(function (k) { if (k.indexOf('ka-formular-') === 0) { sessionStorage.removeItem(k); } }); } catch (chyba) { /* nic */ }
		// měření konverzí: vlastní skript naslouchá události, Google Tag Manager dostane záznam do dataLayer
		window.dispatchEvent(new CustomEvent('kaleta:odeslano', { detail: { formular: nazev } }));
		if (Array.isArray(window.dataLayer)) { window.dataLayer.push({ event: 'kaleta_formular_odeslan', formular: nazev }); }
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
