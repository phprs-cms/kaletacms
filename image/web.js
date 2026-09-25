/* Kaleta - skript webu pro návštěvníky. Bez knihoven; vše je volitelné vylepšení, web funguje i bez JavaScriptu.
 * Menu na telefonu, dialogy a rozbalování řeší HTML a CSS (popover, <details>), ne tento skript. */
(function () {
	'use strict';

	/* ---------- přechod mezi stránkami (View Transitions řídí jen CSS šablony): když ho prohlížeč přeruší nebo vynechá
	   (rychlé proklikání, nová stránka přechod nepovolí), je to v pořádku – bez neošetřené chyby v konzoli ---------- */

	window.addEventListener('pagereveal', function (e) {
		if (!e.viewTransition) { return; }
		e.viewTransition.ready.catch(function () { /* přechod vynechán */ });
		e.viewTransition.finished.catch(function () { /* přechod vynechán */ });
	});

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

	/* ---------- podmenu: Esc zavře panel otevřený fokusem nebo myší a vrátí fokus na položku menu (WCAG 1.4.13) ---------- */

	document.addEventListener('keydown', function (e) {
		if (e.key !== 'Escape') { return; }
		var li = e.target.closest && e.target.closest('.ka-nav li.podmenu');
		if (li && !li.closest('.ka-nav-menu:popover-open')) {
			li.classList.add('zavreno');
			var vrchol = li.querySelector(':scope > a, :scope > .menu-skupina');
			if (vrchol && vrchol !== e.target) { vrchol.focus(); }
		}
		// panel otevřený jen najetím myši
		document.querySelectorAll('.ka-nav li.podmenu:hover').forEach(function (h) { h.classList.add('zavreno'); });
	});
	['focusout', 'mouseout'].forEach(function (udalost) {
		document.addEventListener(udalost, function (e) {
			var li = e.target.closest && e.target.closest('.ka-nav li.podmenu.zavreno');
			if (li && !li.contains(e.relatedTarget)) { li.classList.remove('zavreno'); }
		});
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

	// otevřené okno dostane fokus (čtečka ho oznámí, klávesnice pokračuje uvnitř); po zavření se fokus vrátí, odkud přišel
	var otevriOkno = function (okno) {
		if (!okno.showPopover || okno.matches(':popover-open')) { return; }
		var odkud = document.activeElement;
		okno.showPopover();
		var cil = okno.querySelector('h1, h2, h3, input, select, textarea, a[href], button:not(.ka-okno-zavrit)') || okno.querySelector('button');
		if (cil) { if (!cil.matches('a, button, input, select, textarea')) { cil.setAttribute('tabindex', '-1'); } cil.focus(); }
		okno.addEventListener('toggle', function vratit(e) {
			if (e.newState !== 'closed') { return; }
			okno.removeEventListener('toggle', vratit);
			if (odkud && odkud.focus && document.contains(odkud)) { odkud.focus(); }
		});
	};
	document.addEventListener('click', function (e) {
		var odkaz = e.target.closest && e.target.closest('a[href^="#"]');
		var okno = odkaz && odkaz.getAttribute('href').length > 1 && document.getElementById(odkaz.getAttribute('href').slice(1));
		if (!okno || !okno.hasAttribute('popover') || !okno.showPopover) { return; }
		e.preventDefault();
		otevriOkno(okno);
	});
	// adresa s kotvou okna nebo prvku v okně (návrat po odeslání formuláře či odběru) okno rovnou otevře, ať je potvrzení vidět
	if (location.hash.length > 1) {
		var kotva = document.getElementById(decodeURIComponent(location.hash.slice(1)));
		var vOkne = kotva && kotva.closest('[popover]');
		if (vOkne) { otevriOkno(vOkne); }
	}
	// okno, které se otevře samo: po čase, po odrolování poloviny stránky nebo při odchodu (myš k liště prohlížeče);
	// jednou za návštěvu (sessionStorage), jednou za týden nebo už nikdy (localStorage) – vždy jen v prohlížeči návštěvníka
	document.querySelectorAll('[popover][data-samo]').forEach(function (okno) {
		var klic = 'ka-okno-' + okno.id;
		var znovu = okno.getAttribute('data-znovu') || 'relace';
		var uloziste = function () { try { return znovu === 'relace' ? sessionStorage : localStorage; } catch (chyba) { return null; } };
		try {
			var bylo = uloziste() && uloziste().getItem(klic);
			if (bylo && (znovu !== 'tyden' || Date.now() - parseInt(bylo, 10) < 7 * 864e5)) { return; }
		} catch (chyba) { /* soukromý režim */ }
		var hotovo = false;
		var otevri = function () {
			if (hotovo || !okno.showPopover || document.querySelector(':popover-open')) { return; }
			hotovo = true;
			otevriOkno(okno);
			try { uloziste().setItem(klic, String(Date.now())); } catch (chyba) { /* soukromý režim */ }
		};
		var kdy = okno.getAttribute('data-samo');
		if (kdy === 'posun') {
			window.addEventListener('scroll', function () {
				if (window.scrollY + window.innerHeight >= document.documentElement.scrollHeight / 2) { otevri(); }
			}, { passive: true });
		} else if (kdy === 'odchod') {
			document.addEventListener('mouseout', function (e) { if (!e.relatedTarget && e.clientY <= 0) { otevri(); } });
		} else {
			setTimeout(otevri, parseInt(kdy, 10) * 1000);
		}
	});

	/* ---------- počítadlo a odpočet ---------- */

	var klidne = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
	var cisla = new Intl.NumberFormat(document.documentElement.lang || 'cs');
	// počítadlo: číslo je v HTML hotové (do objevení zůstává vidět), při prvním objevení na obrazovce se jednou napočítá od nuly
	document.querySelectorAll('[data-pocitadlo]').forEach(function (c) {
		var cil = parseInt(c.getAttribute('data-pocitadlo'), 10);
		if (klidne || !cil || !('IntersectionObserver' in window)) { return; }
		var pozorovatel = new IntersectionObserver(function (zaznamy) {
			if (!zaznamy[0].isIntersecting) { return; }
			pozorovatel.disconnect();
			var start = null;
			var krok = function (t) {
				start = start || t;
				var podil = Math.min(1, (t - start) / 1400);
				c.textContent = cisla.format(Math.round(cil * (1 - Math.pow(1 - podil, 3))));
				if (podil < 1) { requestAnimationFrame(krok); }
			};
			requestAnimationFrame(krok);
		}, { threshold: 0.3 });
		pozorovatel.observe(c);
	});
	// odpočet: server vypsal stav v okamžiku vykreslení (stránka může být z cache), tady se dopočítává každou sekundu
	document.querySelectorAll('[data-odpocet]').forEach(function (o) {
		var cil = Date.parse(o.getAttribute('data-odpocet'));
		var casti = {};
		o.querySelectorAll('[data-cast]').forEach(function (d) { casti[d.getAttribute('data-cast')] = d; });
		var dva = function (n) { return (n < 10 ? '0' : '') + n; };
		var tik = function () {
			var zbyva = Math.floor((cil - Date.now()) / 1000);
			if (zbyva <= 0) {
				var konec = document.createElement('p');
				konec.className = 'ka-odpocet-konec';
				konec.textContent = o.getAttribute('data-konec');
				o.replaceWith(konec);
				return;
			}
			casti.d.textContent = Math.floor(zbyva / 86400);
			casti.h.textContent = dva(Math.floor(zbyva % 86400 / 3600));
			casti.m.textContent = dva(Math.floor(zbyva % 3600 / 60));
			casti.s.textContent = dva(zbyva % 60);
			setTimeout(tik, 1000 - Date.now() % 1000);
		};
		if (!isNaN(cil) && casti.s) { tik(); }
	});

	/* ---------- formuláře: po chybě vrátit vyplněné hodnoty, po odeslání ohlásit konverzi ---------- */

	// hodnoty drží jen prohlížeč návštěvníka (sessionStorage) a po úspěšném odeslání zmizí; do adresy se nic nepíše
	document.querySelectorAll('form[data-formular]').forEach(function (f) {
		var klic = 'ka-formular-' + f.getAttribute('data-formular');
		var cekat = f.querySelector('input[data-cekat]');
		f.addEventListener('submit', function (e) {
			var hodnoty = {};
			Array.prototype.forEach.call(f.elements, function (p) {
				if (!/^p\d+$/.test(p.name)) { return; }
				if (p.type === 'checkbox' || p.type === 'radio') { if (p.checked) { hodnoty[p.name] = p.value; } } else { hodnoty[p.name] = p.value; }
			});
			try { sessionStorage.setItem(klic, JSON.stringify(hodnoty)); } catch (chyba) { /* soukromý režim */ }
			// ochrana proti robotům odmítne formulář odeslaný pár vteřin po načtení stránky (s automatickým vyplněním to zvládne
			// i člověk) – místo chyby se odeslání o zbytek odloží
			// počítá se od první odpovědi serveru (stránka vznikla ještě před ní), ne od kliknutí na odkaz
			var navigace = performance.getEntriesByType ? performance.getEntriesByType('navigation')[0] : null;
			var zbyva = cekat ? parseInt(cekat.getAttribute('data-cekat'), 10) * 1000 + 250 - (performance.now() - (navigace ? navigace.responseStart : 0)) : 0;
			if (zbyva > 0) {
				e.preventDefault();
				var tlacitko = f.querySelector('[type=submit]');
				if (tlacitko) { tlacitko.disabled = true; tlacitko.setAttribute('aria-busy', 'true'); }
				setTimeout(function () { f.submit(); }, zbyva);
			}
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
		// jen služby, které web sám vkládá (YouTube bez cookies, Vimeo, mapa Google) – nikdy jiná adresa ani javascript:
		var adresa = tl.getAttribute('data-vlozit') || '';
		if (!/^https:\/\/(www\.youtube-nocookie\.com\/embed\/|player\.vimeo\.com\/video\/|maps\.google\.com\/maps\?)/.test(adresa)) { return; }
		var ram = document.createElement('iframe');
		ram.src = adresa;
		ram.title = tl.getAttribute('data-titulek') || '';
		ram.allow = 'autoplay; encrypted-media; picture-in-picture; fullscreen';
		ram.allowFullscreen = true;
		ram.loading = 'lazy';
		tl.replaceWith(ram);
	});
	// přepínač vzhledu (views/front/tema.php): volba se pamatuje v prohlížeči, hlavička šablony ji použije před vykreslením
	(function () {
		var volby = document.querySelectorAll('[data-tema-volba]');
		if (!volby.length) { return; }
		var koren = document.documentElement;
		function oznac(v) {
			document.querySelectorAll('.ka-tema').forEach(function (n) { n.setAttribute('data-volba', v); });
			volby.forEach(function (b) { b.setAttribute('aria-pressed', String(b.getAttribute('data-tema-volba') === v)); });
		}
		var ulozena = null;
		try { ulozena = localStorage.getItem('ka-tema'); } catch (e) { /* úložiště nedostupné */ }
		var vychozi = (document.querySelector('.ka-tema') || koren).getAttribute('data-tema-vychozi') || 'auto';
		oznac(ulozena === 'auto' || ulozena === 'svetly' || ulozena === 'tmavy' ? ulozena : vychozi);
		document.addEventListener('click', function (e) {
			var b = e.target.closest && e.target.closest('[data-tema-volba]');
			if (!b) { return; }
			var v = b.getAttribute('data-tema-volba');
			if (v === 'auto') { koren.removeAttribute('data-tema'); } else { koren.setAttribute('data-tema', v); }
			try { localStorage.setItem('ka-tema', v); } catch (err) { /* volba platí jen pro tuto stránku */ }
			oznac(v);
			var nabidka = b.closest('[popover]');
			if (nabidka && nabidka.matches(':popover-open')) { nabidka.hidePopover(); }
		});
	})();
})();
