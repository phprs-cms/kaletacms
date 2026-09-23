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

	// Úprava bloků: přetahování bloků mezi zónami a šipky nahoru/dolů; pořadí se ukládá samo
	var platno = document.querySelector('[data-platno]');
	if (platno) {
		var tazeny = null;
		var stavPoradi = document.querySelector('[data-stav-poradi]');
		var ulozPoradi = function () {
			var poradi = {};
			platno.querySelectorAll('[data-zona]').forEach(function (z) {
				poradi[z.getAttribute('data-zona')] = Array.prototype.map.call(z.querySelectorAll('[data-idb]'), function (k) { return k.getAttribute('data-idb'); });
			});
			var data = new FormData();
			data.append('_csrf', document.querySelector('input[name="_csrf"]').value);
			data.append('poradi', JSON.stringify(poradi));
			stavPoradi.textContent = T('Ukládám…');
			fetch(platno.getAttribute('data-url'), { method: 'POST', body: data, credentials: 'same-origin' })
				.then(function (r) { return r.json(); })
				.then(function (j) { stavPoradi.textContent = j.ok ? T('Pořadí uloženo.') : T('Pořadí se nepodařilo uložit.'); })
				.catch(function () { stavPoradi.textContent = T('Pořadí se nepodařilo uložit.'); });
		};
		// karta, před kterou se má tažený blok vložit (podle polohy kurzoru), nebo null = na konec
		var kartaZa = function (seznam, x, y) {
			var karty = Array.prototype.filter.call(seznam.querySelectorAll('[data-idb]'), function (k) { return k !== tazeny; });
			for (var i = 0; i < karty.length; i++) {
				var r = karty[i].getBoundingClientRect();
				if (y < r.top + r.height / 2 && (y < r.top || x < r.right) || (y >= r.top && y <= r.bottom && x < r.left + r.width / 2)) { return karty[i]; }
			}
			return null;
		};
		platno.addEventListener('dragstart', function (e) {
			tazeny = e.target.closest('[data-idb]');
			if (!tazeny) { return; }
			e.dataTransfer.effectAllowed = 'move';
			e.dataTransfer.setData('text/plain', tazeny.getAttribute('data-idb'));
			setTimeout(function () { tazeny.classList.add('blok-tazeny'); }, 0);
		});
		platno.addEventListener('dragover', function (e) {
			var zona = e.target.closest('[data-zona]');
			if (!tazeny || !zona) { return; }
			e.preventDefault();
			platno.querySelectorAll('.zona-cil').forEach(function (z) { if (z !== zona) { z.classList.remove('zona-cil'); } });
			zona.classList.add('zona-cil');
			var seznam = zona.querySelector('.zona-bloky');
			seznam.insertBefore(tazeny, kartaZa(seznam, e.clientX, e.clientY));
		});
		platno.addEventListener('drop', function (e) { if (tazeny) { e.preventDefault(); } });
		platno.addEventListener('dragend', function () {
			if (!tazeny) { return; }
			tazeny.classList.remove('blok-tazeny');
			platno.querySelectorAll('.zona-cil').forEach(function (z) { z.classList.remove('zona-cil'); });
			tazeny = null;
			ulozPoradi();
		});
		platno.addEventListener('click', function (e) {
			var tl = e.target.closest('[data-posun]');
			if (!tl) { return; }
			var karta = tl.closest('[data-idb]');
			var dolu = tl.getAttribute('data-posun') === '1';
			var soused = dolu ? karta.nextElementSibling : karta.previousElementSibling;
			if (soused) {
				karta.parentNode.insertBefore(karta, dolu ? soused.nextElementSibling : soused);
			} else {
				// na kraji zóny blok přeskočí do sousední zóny
				var zony = Array.prototype.slice.call(platno.querySelectorAll('[data-zona]'));
				var cil = zony[zony.indexOf(karta.closest('[data-zona]')) + (dolu ? 1 : -1)];
				if (!cil) { return; }
				var seznam = cil.querySelector('.zona-bloky');
				seznam.insertBefore(karta, dolu ? seznam.firstChild : null);
			}
			tl.focus();
			ulozPoradi();
		});
	}

	// Editor článku: každou minutu dá serveru vědět, že je článek stále otevřený (zámek proti souběžné úpravě)
	var formClanku = document.querySelector('form.formular-clanek');
	if (formClanku && formClanku.idc && formClanku.idc.value !== '0') {
		setInterval(function () {
			var data = new FormData();
			data.append('_csrf', formClanku._csrf.value);
			data.append('idc', formClanku.idc.value);
			fetch(formClanku.action.replace('akce=uloz', 'akce=zamek'), { method: 'POST', body: data, credentials: 'same-origin' });
		}, 60000);
	}

	// Identita webu: živá ukázka barvy a písem, vzorník barev, kontrola čitelnosti barvy
	var identita = document.querySelector('[data-identita]');
	if (identita) {
		var ukazka = identita.querySelector('[data-ukazka]'), barva = identita.brand_akcent;
		var jas = function (hex) {
			var k = [1, 3, 5].map(function (i) { var c = parseInt(hex.substr(i, 2), 16) / 255; return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4); });
			return 0.2126 * k[0] + 0.7152 * k[1] + 0.0722 * k[2];
		};
		var prekresli = function () {
			var vlastni = identita.akcent_vlastni.value === '1';
			ukazka.style.setProperty('--u-akcent', vlastni ? barva.value : '');
			ukazka.style.setProperty('--u-titulky', identita.brand_pismo_titulky.selectedOptions[0].getAttribute('data-css') || '');
			ukazka.style.setProperty('--u-text', identita.brand_pismo_text.selectedOptions[0].getAttribute('data-css') || '');
			identita.querySelector('[data-kontrast]').hidden = !vlastni || 1.05 / (jas(barva.value) + 0.05) >= 4.5;
		};
		identita.addEventListener('input', prekresli);
		identita.addEventListener('change', prekresli);
		barva.addEventListener('input', function () { identita.akcent_vlastni.value = '1'; });
		identita.querySelectorAll('[data-barva]').forEach(function (tl) {
			tl.addEventListener('click', function () { barva.value = tl.getAttribute('data-barva'); identita.akcent_vlastni.value = '1'; prekresli(); });
		});
		prekresli();
	}

	// Hromadné akce: doplňující pole (rubrika, štítek) se ukáže jen u akce, která ho potřebuje
	document.querySelectorAll('[data-hromadne]').forEach(function (vyber) {
		vyber.addEventListener('change', function () {
			vyber.form.querySelectorAll('[data-pro-akci]').forEach(function (p) { p.hidden = p.getAttribute('data-pro-akci') !== vyber.value; });
		});
	});

	// Titulní strana: dva seznamy (připnuté / ostatní), přetahování, šipky a tlačítko Připnout; pořadí jde do skrytého pole
	var titulni = document.querySelector('form[data-titulni]');
	if (titulni) {
		var pripnute = titulni.querySelector('[data-seznam="pripnute"]'), ostatni = titulni.querySelector('[data-seznam="dalsi"]'), tazenyClanek = null;
		var zapisPoradi = function () {
			titulni.poradi.value = Array.prototype.map.call(pripnute.children, function (li) { return li.getAttribute('data-id'); }).join(',');
			Array.prototype.forEach.call(titulni.querySelectorAll('[data-prepnout]'), function (b) {
				b.textContent = b.closest('[data-seznam]') === pripnute ? b.getAttribute('data-odepnout') : b.getAttribute('data-pripnout');
			});
		};
		titulni.addEventListener('click', function (e) {
			var li = e.target.closest('li');
			if (!li) { return; }
			if (e.target.hasAttribute('data-prepnout')) { (li.parentNode === pripnute ? ostatni : pripnute)[li.parentNode === pripnute ? 'prepend' : 'appendChild'](li); }
			if (e.target.hasAttribute('data-nahoru') && li.previousElementSibling) { li.parentNode.insertBefore(li, li.previousElementSibling); e.target.focus(); }
			if (e.target.hasAttribute('data-dolu') && li.nextElementSibling) { li.parentNode.insertBefore(li.nextElementSibling, li); e.target.focus(); }
			zapisPoradi();
		});
		titulni.addEventListener('dragstart', function (e) { tazenyClanek = e.target.closest('li'); if (tazenyClanek) { tazenyClanek.classList.add('tazeny'); e.dataTransfer.effectAllowed = 'move'; } });
		titulni.addEventListener('dragend', function () { if (tazenyClanek) { tazenyClanek.classList.remove('tazeny'); } tazenyClanek = null; zapisPoradi(); });
		titulni.addEventListener('dragover', function (e) {
			var seznam = e.target.closest('[data-seznam]');
			if (!seznam || !tazenyClanek) { return; }
			e.preventDefault();
			var pod = Array.prototype.filter.call(seznam.children, function (li) { return li !== tazenyClanek; }).filter(function (li) {
				var r = li.getBoundingClientRect(); return e.clientY < r.top + r.height / 2;
			})[0];
			seznam.insertBefore(tazenyClanek, pod || null);
		});
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

	// Formulář bloku: ukazuje jen pole, která zvolený typ bloku používá (data-pro="zkratka zkratka")
	var formBloku = document.querySelector('[data-blok-formular]');
	if (formBloku) {
		var ukazPole = function () {
			var typ = formBloku.sys_funkce.value;
			formBloku.querySelectorAll('[data-pro]').forEach(function (radek) {
				radek.hidden = radek.getAttribute('data-pro').split(' ').indexOf(typ) === -1;
			});
		};
		formBloku.sys_funkce.addEventListener('change', function () {
			if (formBloku.nazev.value === '' && formBloku.sys_funkce.value !== '') { formBloku.nazev.value = formBloku.sys_funkce.selectedOptions[0].textContent.split(' – ')[0]; }
			ukazPole();
		});
		ukazPole();
	}

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
	// rozesílka newsletteru po dávkách: formulář se odešle sám
	var autoOdeslat = document.querySelector('form[data-auto-odeslat]');
	if (autoOdeslat) { setTimeout(function () { autoOdeslat.submit(); }, parseInt(autoOdeslat.getAttribute('data-auto-odeslat'), 10) || 1200); }

	/* ---------- paleta příkazů: Ctrl/⌘+K – sekce, rychlé akce a hledání článku ---------- */

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
					pClanky = (d.clanky || []).map(function (c) { return { n: c.titulek, u: c.url, s: c.vydany ? T('článek') : T('článek – nevydaný') }; });
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
