/* MiroCMS.0 - drobnosti administrace. Bez knihoven, bez build kroku. */

(function () {
	'use strict';

	// překlad textů skriptů administrace: slovník window.MIROCMS_PREKLAD dodá image/jazyky/admin-<kód>.js, čeština ho nemá
	window.T = function (s) { return (window.MIROCMS_PREKLAD || {})[s] || s; };
	var T = window.T;

	// Potvrzení nevratných akcí: data-potvrdit="text" na formuláři nebo tlačítku.
	// Vlastní dialog místo window.confirm(), který vestavěné prohlížeče (např. v aplikacích) potichu potlačují.
	var dialogPotvrzeni = null;
	document.addEventListener('submit', function (e) {
		var form = e.target, tlacitko = e.submitter;
		var text = (tlacitko && tlacitko.getAttribute('data-potvrdit')) || form.getAttribute('data-potvrdit');
		if (!text || form.potvrzeno) { return; }
		e.preventDefault();
		if (!dialogPotvrzeni) {
			dialogPotvrzeni = document.createElement('dialog');
			dialogPotvrzeni.className = 'potvrzeni';
			dialogPotvrzeni.innerHTML = '<p></p><div><button type="button" class="tl" data-ano>' + T('Ano, provést') + '</button> <button type="button" class="navigace" data-ne>' + T('Zrušit') + '</button></div>';
			document.body.appendChild(dialogPotvrzeni);
			dialogPotvrzeni.querySelector('[data-ne]').addEventListener('click', function () { dialogPotvrzeni.close(); });
		}
		dialogPotvrzeni.querySelector('p').textContent = text;
		dialogPotvrzeni.querySelector('[data-ano]').onclick = function () {
			dialogPotvrzeni.close();
			form.potvrzeno = true;
			if (form.requestSubmit) { form.requestSubmit(tlacitko || undefined); } else { form.submit(); }
			form.potvrzeno = false;
		};
		dialogPotvrzeni.showModal();
		dialogPotvrzeni.querySelector('[data-ne]').focus();
	});

	// Světlý / tmavý režim (výchozí podle systému, volba se pamatuje v prohlížeči; před vykreslením ji nastaví tema.js)
	var temaTl = document.querySelector('[data-tema-prepinac]');
	if (temaTl) {
		temaTl.addEventListener('click', function () {
			var koren = document.documentElement;
			var tmavy = koren.getAttribute('data-tema') ? koren.getAttribute('data-tema') === 'tmavy' : window.matchMedia('(prefers-color-scheme: dark)').matches;
			koren.setAttribute('data-tema', tmavy ? 'svetly' : 'tmavy');
			try { localStorage.setItem('mirocms-tema', tmavy ? 'svetly' : 'tmavy'); } catch (e) { /* nic */ }
		});
	}

	// Rozbalení menu na mobilu
	var prepinac = document.querySelector('.menu-prepinac');
	if (prepinac) {
		prepinac.addEventListener('click', function () {
			var otevrene = document.body.classList.toggle('menu-otevrene');
			prepinac.setAttribute('aria-expanded', otevrene ? 'true' : 'false');
		});
	}

	// Vzhled webu: předvolby a živý náhled skutečné úvodní stránky. CSS tokenů počítá server (akce nahled) – jediný výpočet v PHP.
	var vzhled = document.querySelector('[data-vzhled]');
	if (vzhled) {
		var nahled = document.querySelector('[data-nahled]'), ramec = document.querySelector('[data-ramec]');
		var zarizeni = 'pocitac', casovac = null, posledniCss = '';
		var vlozCss = function () {
			var doc = nahled.contentDocument;
			if (!doc || !doc.head || posledniCss === '') { return; }
			var styl = doc.getElementById('mc-vzhled-nahled');
			if (!styl) { styl = doc.createElement('style'); styl.id = 'mc-vzhled-nahled'; doc.head.appendChild(styl); }
			styl.textContent = posledniCss;
		};
		var prepocitej = function () {
			var data = new FormData(vzhled);
			fetch(vzhled.getAttribute('data-nahled-url'), { method: 'POST', body: data, credentials: 'same-origin' })
				.then(function (r) { return r.json(); })
				.then(function (j) {
					posledniCss = j.css;
					vlozCss();
					vzhled.querySelector('[data-kontrasty]').innerHTML = j.kontrasty.map(function (k) {
						var li = document.createElement('li');
						li.className = k.ok ? 'ok' : 'spatne';
						li.innerHTML = '<span></span><strong></strong>';
						li.firstChild.textContent = k.popis;
						li.lastChild.textContent = String(k.pomer.toFixed(1)).replace('.', ',') + ' : 1';
						return li.outerHTML;
					}).join('');
				})
				.catch(function () {});
		};
		var zmena = function (e) {
			if (e && e.target && e.target.type === 'color') { e.target.parentNode.querySelector('[data-hex]').textContent = e.target.value; }
			vzhled.querySelector('[data-neulozeno]').hidden = false;
			clearTimeout(casovac);
			casovac = setTimeout(prepocitej, 180);
		};
		vzhled.addEventListener('input', zmena);
		vzhled.addEventListener('change', zmena);
		nahled.addEventListener('load', vlozCss);
		// předvolba vyplní formulář (velikosti jsou ve formuláři v px, v design systému v rem)
		vzhled.querySelectorAll('[data-predvolba]').forEach(function (tl) {
			tl.addEventListener('click', function () {
				var ds = JSON.parse(tl.getAttribute('data-predvolba'));
				Object.keys(ds).forEach(function (klic) {
					if (typeof ds[klic] === 'object') {
						Object.keys(ds[klic]).forEach(function (b) {
							var pole = vzhled.elements['ds[' + klic + '][' + b + ']'];
							if (pole) { pole.value = ds[klic][b]; pole.parentNode.querySelector('[data-hex]').textContent = ds[klic][b]; }
						});
						return;
					}
					var pole = vzhled.elements['ds[' + klic + ']'];
					if (!pole) { return; }
					var hodnota = ['zaklad_min', 'zaklad_max', 'sirka', 'sirka_textu'].indexOf(klic) !== -1 ? Math.round(ds[klic] * 16) : ds[klic];
					if (pole instanceof RadioNodeList) { pole.value = String(hodnota); return; }
					if (pole.tagName === 'SELECT') {
						Array.prototype.forEach.call(pole.options, function (o) { if (Math.abs(parseFloat(o.value) - hodnota) < 0.001 || o.value === String(hodnota)) { pole.value = o.value; } });
						return;
					}
					pole.value = hodnota;
				});
				zmena();
			});
		});
		// počítač se vykresluje v šířce 1280 px a zmenší se do rámu, aby platily skutečné breakpointy webu
		var rozmer = function () {
			var sirka = zarizeni === 'mobil' ? 390 : 1280, dostupna = ramec.clientWidth, meritko = Math.min(1, dostupna / sirka);
			nahled.style.width = sirka + 'px';
			nahled.style.height = (ramec.clientHeight / meritko) + 'px';
			nahled.style.transform = 'scale(' + meritko + ')';
			nahled.style.left = Math.max(0, (dostupna - sirka * meritko) / 2) + 'px';
		};
		document.querySelectorAll('[data-zarizeni]').forEach(function (tl) {
			tl.addEventListener('click', function () {
				zarizeni = tl.getAttribute('data-zarizeni');
				document.querySelectorAll('[data-zarizeni]').forEach(function (b) { b.setAttribute('aria-pressed', b === tl ? 'true' : 'false'); });
				rozmer();
			});
		});
		if (window.ResizeObserver) { new ResizeObserver(rozmer).observe(ramec); }
		rozmer();
	}

	// Obecné: volba s data-prepni="sekce:1" ukáže (nebo :0 skryje) část formuláře označenou data-sekce="sekce"
	document.querySelectorAll('[data-prepni]').forEach(function (volba) {
		volba.addEventListener('change', function () {
			var p = volba.getAttribute('data-prepni').split(':');
			document.querySelectorAll('[data-sekce="' + p[0] + '"]').forEach(function (s) { s.hidden = p[1] !== '1'; });
		});
	});

	// Obecné: formulář s data-prepinac="pole" ukazuje jen řádky, jejichž data-pro obsahuje zvolenou hodnotu pole
	document.querySelectorAll('form[data-prepinac]').forEach(function (form) {
		var jmeno = form.getAttribute('data-prepinac');
		var prepni = function () {
			var zvolene = form.querySelector('[name="' + jmeno + '"]:checked') || form.querySelector('select[name="' + jmeno + '"]');
			form.querySelectorAll('[data-pro]').forEach(function (radek) { radek.hidden = !zvolene || radek.getAttribute('data-pro').split(' ').indexOf(zvolene.value) === -1; });
		};
		form.addEventListener('change', function (e) { if (e.target.name === jmeno) { prepni(); } });
		prepni();
	});

	// Varování před opuštěním rozepsaného formuláře
	document.querySelectorAll('form.formular').forEach(function (form) {
		var zmeneno = false;
		form.addEventListener('input', function () { zmeneno = true; });
		form.addEventListener('submit', function () { zmeneno = false; });
		window.addEventListener('beforeunload', function (e) {
			if (zmeneno) { e.preventDefault(); e.returnValue = ''; }
		});
	});
	/* ---------- drobné obsluhy místo inline skriptů (administrace má Content-Security-Policy bez 'unsafe-inline') ---------- */

	document.addEventListener('change', function (e) {
		var prvek = e.target;
		if (prvek.hasAttribute && prvek.hasAttribute('data-odeslat-pri-zmene') && prvek.form) { prvek.form.submit(); }
		if (prvek.hasAttribute && prvek.hasAttribute('data-ukaz-heslo')) {
			var heslo = document.getElementById(prvek.getAttribute('data-ukaz-heslo'));
			if (heslo) { heslo.type = prvek.checked ? 'text' : 'password'; }
		}
	});
	document.addEventListener('click', function (e) {
		if (e.target.closest && e.target.closest('[data-neklikat]')) { e.preventDefault(); }
	});
	/* ---------- paleta příkazů: Ctrl/⌘+K – sekce, rychlé akce a hledání novinky ---------- */

	var paleta = document.getElementById('paleta');
	if (paleta && typeof paleta.showModal === 'function') {
		var pPole = paleta.querySelector('.paleta-pole');
		var pSeznam = paleta.querySelector('.paleta-seznam');
		var pPrikazy = [];
		try { pPrikazy = JSON.parse(document.getElementById('paleta-data').textContent) || []; } catch (e) {}
		var pClanky = [];
		var pVybrano = 0;
		var pCasovac = null;
		var bezDiakritiky = function (t) { return String(t).toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, ''); };

		var pPolozky = function () {
			var q = bezDiakritiky(pPole.value.trim());
			var slova = q.split(/\s+/).filter(Boolean);
			var prikazy = pPrikazy.filter(function (p) {
				var kde = bezDiakritiky(p.n + ' ' + p.s);
				return slova.every(function (s) { return kde.indexOf(s) !== -1; });
			});
			return (q === '' ? prikazy.slice(0, 9) : prikazy.slice(0, 7)).concat(q === '' ? [] : pClanky);
		};
		var pKresli = function () {
			var polozky = pPolozky();
			pVybrano = Math.max(0, Math.min(pVybrano, polozky.length - 1));
			pSeznam.textContent = '';
			polozky.forEach(function (p, i) {
				var li = document.createElement('li');
				li.setAttribute('role', 'option');
				li.setAttribute('aria-selected', i === pVybrano ? 'true' : 'false');
				var a = document.createElement('a');
				a.href = p.u;
				a.textContent = p.n;
				var s = document.createElement('small');
				s.textContent = p.s;
				a.appendChild(s);
				li.appendChild(a);
				li.addEventListener('mousemove', function () { if (pVybrano !== i) { pVybrano = i; pKresli(); } });
				pSeznam.appendChild(li);
			});
			if (polozky.length === 0) {
				var nic = document.createElement('li');
				nic.className = 'paleta-nic';
				nic.textContent = T('Nic takového tu není.');
				pSeznam.appendChild(nic);
			}
			var vybrany = pSeznam.querySelector('[aria-selected="true"]');
			if (vybrany && vybrany.scrollIntoView) { vybrany.scrollIntoView({ block: 'nearest' }); }
		};
		var pOtevri = function () {
			if (paleta.open) { return; }
			pPole.value = '';
			pClanky = [];
			pVybrano = 0;
			pKresli();
			paleta.showModal();
			pPole.focus();
		};

		document.addEventListener('keydown', function (e) {
			if ((e.ctrlKey || e.metaKey) && !e.altKey && (e.key === 'k' || e.key === 'K')) {
				e.preventDefault();
				if (paleta.open) { paleta.close(); } else { pOtevri(); }
			}
		});
		document.addEventListener('click', function (e) {
			if (e.target.closest && e.target.closest('[data-paleta]')) { pOtevri(); }
			if (e.target === paleta) { paleta.close(); } // klik mimo okno
		});
		pPole.addEventListener('input', function () {
			pVybrano = 0;
			pKresli();
			clearTimeout(pCasovac);
			var q = pPole.value.trim();
			var adresa = paleta.getAttribute('data-clanky');
			if (!adresa || q.length < 2) { pClanky = []; return; }
			pCasovac = setTimeout(function () {
				fetch(adresa + '&q=' + encodeURIComponent(q), { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
					if (pPole.value.trim() !== q) { return; } // mezitím se psalo dál
					pClanky = (d.clanky || []).map(function (c) { return { n: c.titulek, u: c.url, s: c.vydany ? T('novinka') : T('novinka – nevydaná') }; });
					pKresli();
				}).catch(function () {});
			}, 200);
		});
		pPole.addEventListener('keydown', function (e) {
			var pocet = pPolozky().length;
			if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
				e.preventDefault();
				pVybrano = pocet === 0 ? 0 : (pVybrano + (e.key === 'ArrowDown' ? 1 : pocet - 1)) % pocet;
				pKresli();
			} else if (e.key === 'Enter') {
				e.preventDefault();
				var cil = pSeznam.querySelector('[aria-selected="true"] a');
				if (cil) { window.location.href = cil.href; }
			}
		});
		// na Macu ukázat ⌘K
		if (/Mac|iPhone|iPad/.test(navigator.platform || '')) {
			Array.prototype.forEach.call(document.querySelectorAll('[data-paleta] kbd'), function (k) { k.textContent = '⌘K'; });
		}
	}
	// Rozbalovací nabídky (<details data-zavrit-mimo>): zavře je klepnutí mimo a klávesa Esc
	document.addEventListener('click', function (e) {
		Array.prototype.forEach.call(document.querySelectorAll('details[data-zavrit-mimo][open]'), function (d) {
			if (!d.contains(e.target)) { d.open = false; }
		});
	});
	document.addEventListener('keydown', function (e) {
		if (e.key !== 'Escape') { return; }
		Array.prototype.forEach.call(document.querySelectorAll('details[data-zavrit-mimo][open]'), function (d) {
			d.open = false;
			d.querySelector('summary').focus();
		});
	});
})();
