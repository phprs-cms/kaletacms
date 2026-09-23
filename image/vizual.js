/* MiroCMS.0 - vizuální editor bloků. Běží přímo ve stránce webu (adresa s ?upravit=1), bez knihoven.
 *
 * Stránka v tomto režimu obsahuje značky: .mc-zona[data-zona] (zóna), .mc-blok[data-blok] (blok), .mc-pridat (tlačítko).
 * Vše se ukládá hned přes JSON akce modulu Bloky v administraci; po změně obsahu se stránka načte znovu,
 * takže je vždy vidět skutečný výsledek.
 */
(function () {
	'use strict';

	var T = function (t) { return (window.MIROCMS_PREKLAD || {})[t] || t; };

	var N = JSON.parse(document.getElementById('mc-nastaveni').textContent);
	var MODUL = N.admin + '?modul=bloky';

	function el(tag, atributy, deti) {
		var e = document.createElement(tag);
		Object.keys(atributy || {}).forEach(function (k) {
			if (k === 'text') { e.textContent = atributy[k]; } else if (k === 'html') { e.innerHTML = atributy[k]; } else if (k.slice(0, 2) === 'on') { e.addEventListener(k.slice(2), atributy[k]); } else if (atributy[k] !== false && atributy[k] != null) { e.setAttribute(k, atributy[k] === true ? '' : atributy[k]); }
		});
		(deti || []).forEach(function (d) { if (d) { e.appendChild(typeof d === 'string' ? document.createTextNode(d) : d); } });
		return e;
	}
	function odesli(akce, data) {
		var fd = new FormData();
		fd.append('_csrf', N.csrf);
		Object.keys(data || {}).forEach(function (k) { fd.append(k, data[k]); });
		return fetch(MODUL + '&akce=' + akce, { method: 'POST', body: fd, credentials: 'same-origin' });
	}
	function znovu(otevritBlok) {
		sessionStorage.setItem('mc-posun', String(window.scrollY));
		if (otevritBlok) { sessionStorage.setItem('mc-otevrit', String(otevritBlok)); }
		location.reload();
	}
	function hlaska(text) {
		var h = el('div', { class: 'mc-hlaska', role: 'status', text: text });
		document.body.appendChild(h);
		setTimeout(function () { h.remove(); }, 2200);
	}

	// tlačítka „Přidat blok“ vykresluje server česky – v jiném jazyce administrace se přeloží tady
	if (window.MIROCMS_PREKLAD) {
		Array.prototype.forEach.call(document.querySelectorAll('.mc-pridat'), function (tl) {
			var zona = tl.querySelector('small');
			var nazev = zona ? zona.textContent : '';
			tl.textContent = T('+ Přidat blok') + ' ';
			tl.appendChild(el('small', { text: T(nazev) }));
		});
	}

	/* ---------- horní lišta ---------- */

	var lista = el('div', { class: 'mc-lista mc-ui' }, [
		el('strong', { text: T('Úprava bloků') }),
		el('span', { class: 'mc-lista-napoveda', text: T('Bloky přetahujte myší. Najetím na blok se ukáže jeho ovládání.') }),
		el('span', { class: 'mc-lista-rozvrzeni' }, [el('span', { text: T('Rozvržení:') })].concat(Object.keys(N.rozvrzeniVolby).map(function (klic) {
			return el('button', { type: 'button', class: klic === N.rozvrzeni ? 'mc-aktivni' : '', title: N.rozvrzeniVolby[klic].popis, text: N.rozvrzeniVolby[klic].nazev,
				onclick: function () { if (klic !== N.rozvrzeni) { odesli('rozvrzeni', { rozvrzeni: klic }).then(function () { znovu(); }); } } });
		}))),
		el('a', { class: 'mc-hotovo', href: N.admin, text: T('Hotovo') })
	]);
	document.body.appendChild(lista);
	document.documentElement.classList.add('mc-upravy');

	// odkazy na webu zůstávají v režimu úprav - bloky se tak dají ladit i na stránce článku nebo rubriky
	document.addEventListener('click', function (e) {
		var a = e.target.closest && e.target.closest('a[href]');
		if (!a || a.closest('.mc-ui') || a.target === '_blank' || a.origin !== location.origin || a.pathname.indexOf('admin.php') !== -1) { return; }
		e.preventDefault();
		var u = new URL(a.href);
		u.searchParams.set('upravit', '1');
		location.href = u.toString();
	}, true);

	/* ---------- ovládání bloku ---------- */

	var tazeny = null;

	function ulozPoradi() {
		var poradi = {};
		document.querySelectorAll('.mc-zona').forEach(function (z) {
			poradi[z.getAttribute('data-zona')] = Array.prototype.map.call(z.querySelectorAll(':scope > .mc-blok'), function (b) { return b.getAttribute('data-blok'); });
		});
		odesli('poradi', { poradi: JSON.stringify(poradi) }).then(function (r) { return r.json(); }).then(function (j) { hlaska(j.ok ? T('Pořadí uloženo') : T('Pořadí se nepodařilo uložit')); });
	}
	function posun(blok, smer) {
		var soused = smer > 0 ? blok.nextElementSibling : blok.previousElementSibling;
		if (!soused || !soused.classList.contains('mc-blok')) { return; }
		blok.parentNode.insertBefore(blok, smer > 0 ? soused.nextElementSibling : soused);
		ulozPoradi();
	}

	document.querySelectorAll('.mc-blok').forEach(function (blok) {
		var id = blok.getAttribute('data-blok');
		blok.appendChild(el('div', { class: 'mc-nastroje mc-ui' }, [
			el('span', { class: 'mc-nazev', text: blok.getAttribute('data-nazev') }),
			el('button', { type: 'button', title: T('Posunout výš'), 'aria-label': T('Posunout výš'), text: '↑', onclick: function () { posun(blok, -1); } }),
			el('button', { type: 'button', title: T('Posunout níž'), 'aria-label': T('Posunout níž'), text: '↓', onclick: function () { posun(blok, 1); } }),
			el('button', { type: 'button', class: 'mc-hlavni', text: T('Nastavit'), onclick: function () { otevriNastaveni(id); } }),
			el('button', { type: 'button', title: T('Smazat blok'), 'aria-label': T('Smazat blok'), text: '✕', onclick: function () {
				potvrd(T('Smazat blok „') + blok.getAttribute('data-nazev') + '“?', function () { odesli('smaz', { idb: id }).then(function () { blok.remove(); hlaska(T('Blok smazán')); }); });
			} })
		]));
		blok.addEventListener('dragstart', function (e) {
			if (e.target !== blok) { return; }
			tazeny = blok;
			e.dataTransfer.effectAllowed = 'move';
			e.dataTransfer.setData('text/plain', id);
			setTimeout(function () { blok.classList.add('mc-tazeny'); document.documentElement.classList.add('mc-tahne'); }, 0);
		});
		blok.addEventListener('dragend', function () {
			blok.classList.remove('mc-tazeny');
			document.documentElement.classList.remove('mc-tahne');
			document.querySelectorAll('.mc-cil').forEach(function (z) { z.classList.remove('mc-cil'); });
			if (tazeny) { tazeny = null; ulozPoradi(); }
		});
	});

	document.querySelectorAll('.mc-zona').forEach(function (zona) {
		zona.addEventListener('dragover', function (e) {
			if (!tazeny) { return; }
			e.preventDefault();
			document.querySelectorAll('.mc-cil').forEach(function (z) { if (z !== zona) { z.classList.remove('mc-cil'); } });
			zona.classList.add('mc-cil');
			var pred = null;
			Array.prototype.some.call(zona.querySelectorAll(':scope > .mc-blok'), function (b) {
				if (b === tazeny) { return false; }
				var r = b.getBoundingClientRect();
				if (e.clientY < r.top + r.height / 2 && e.clientX < r.right) { pred = b; return true; }
				return false;
			});
			zona.insertBefore(tazeny, pred || zona.querySelector(':scope > .mc-pridat'));
		});
		zona.addEventListener('drop', function (e) { if (tazeny) { e.preventDefault(); } });
	});

	/* ---------- dialogy ---------- */

	function okno(trida, obsah) {
		var d = el('dialog', { class: 'mc-okno mc-ui ' + trida }, obsah);
		document.body.appendChild(d);
		d.addEventListener('close', function () { d.remove(); });
		d.addEventListener('click', function (e) { if (e.target === d) { d.close(); } });
		d.showModal();
		return d;
	}
	function potvrd(text, ano) {
		var d = okno('mc-potvrzeni', [
			el('p', { text: text }),
			el('div', { class: 'mc-tlacitka' }, [
				el('button', { type: 'button', class: 'mc-hlavni', text: 'Ano, smazat', onclick: function () { d.close(); ano(); } }),
				el('button', { type: 'button', text: T('Zrušit'), onclick: function () { d.close(); } })
			])
		]);
	}

	/* ---------- přidání bloku ---------- */

	document.querySelectorAll('.mc-pridat').forEach(function (tl) {
		tl.addEventListener('click', function () {
			var zona = tl.getAttribute('data-zona');
			var d = okno('mc-nabidka', [
				el('div', { class: 'mc-okno-hlava' }, [el('strong', { text: T('Co chcete přidat?') }), el('button', { type: 'button', 'aria-label': T('Zavřít'), text: '✕', onclick: function () { d.close(); } })]),
				el('p', { class: 'mc-okno-popis', text: T('Blok se přidá do zóny „') + T(tl.closest('.mc-zona').getAttribute('data-nazev')) + T('“. Nastavení můžete kdykoli změnit.') })
			].concat(Object.keys(N.katalog).map(function (skupina) {
				return el('section', {}, [el('h3', { text: skupina }), el('div', { class: 'mc-karty' }, N.katalog[skupina].map(function (p) {
					return el('button', { type: 'button', class: 'mc-karta', onclick: function () {
						odesli('rychle_pridat', { zona: zona, typ: p.typ }).then(function (r) { return r.json(); }).then(function (j) { if (j.ok) { znovu(j.idb); } });
					} }, [el('i', { html: p.ikona, 'aria-hidden': 'true' }), el('strong', { text: p.nazev }), el('span', { text: p.popis })]);
				}))]);
			})));
		});
	});

	/* ---------- nastavení bloku ---------- */

	function pole(popisek, prvek, napoveda) {
		return el('label', { class: 'mc-pole' }, [el('span', { text: popisek }), prvek, napoveda ? el('small', { text: napoveda }) : null]);
	}
	function vyber(name, volby, hodnota) {
		return el('select', { name: name }, volby.map(function (v) { return el('option', { value: v[0], selected: String(v[0]) === String(hodnota), text: v[1] }); }));
	}

	function otevriNastaveni(id) {
		fetch(MODUL + '&akce=nastaveni_json&id=' + id, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
			if (!j.ok) { return; }
			var b = j.blok, typ = b.sys_funkce, data = String(b.data_sys || '');
			var nazevTypu = T('Text');
			Object.keys(N.katalog).forEach(function (s) { N.katalog[s].forEach(function (p) { if (p.typ === typ) { nazevTypu = p.nazev; } }); });
			var vzhled = Number(b.typ) === 5 ? 1 : Number(b.typ);
			var form = el('form', { class: 'mc-formular' });
			var radky = [];

			radky.push(pole(T('Nadpis'), el('input', { type: 'text', name: 'nazev', value: b.nazev, maxlength: '100', required: true })));
			radky.push(el('label', { class: 'mc-zaskrtnuti' }, [el('input', { type: 'checkbox', name: 'ukazat_nadpis', checked: Number(b.typ) !== 5 }), T(' Zobrazit nadpis na webu')]));

			if (typ === '') {
				var ta = el('textarea', { name: 'obsah', rows: '8', 'data-editor': 'maly' });
				ta.value = b.obsah;
				radky.push(pole(T('Obsah'), ta, T('Text, obrázek z médií nebo vložený kód (přepněte na HTML).')));
			}
			if (typ === 'men') {
				var seznam = el('div', { class: 'mc-odkazy' });
				var pridejRadek = function (text, adresa) {
					var r = el('div', { class: 'mc-odkaz' }, [
						el('input', { type: 'text', placeholder: T('Text odkazu'), value: text || '', 'aria-label': T('Text odkazu') }),
						el('input', { type: 'text', placeholder: T('/o-nas nebo https://…'), value: adresa || '', 'aria-label': T('Adresa') }),
						el('button', { type: 'button', 'aria-label': T('Odebrat odkaz'), text: '✕', onclick: function () { r.remove(); } })
					]);
					seznam.appendChild(r);
				};
				String(b.obsah).split(/\r?\n/).forEach(function (l) { var c = l.split('|'); if (c.length > 1 && c[0].trim()) { pridejRadek(c[0].trim(), c.slice(1).join('|').trim()); } });
				if (!seznam.children.length) { pridejRadek('', ''); }
				radky.push(el('div', { class: 'mc-pole' }, [el('span', { text: T('Odkazy') }), seznam, el('button', { type: 'button', class: 'mc-pridat-radek', text: T('+ další odkaz'), onclick: function () { pridejRadek('', ''); } })]));
			}
			if (typ === 'cla') {
				radky.push(pole(T('Rubrika'), vyber('blok_rubrika', [[0, T('Nejnovější ze všech rubrik')]].concat(N.rubriky.map(function (r) { return [r.id, r.nazev]; })), data.split(':')[0])));
			}
			if (['cla', 'nej', 'sti', 'aut', 'arc'].indexOf(typ) !== -1) {
				radky.push(pole(T('Kolik položek'), el('input', { type: 'number', name: 'blok_pocet', min: '1', max: typ === 'cla' ? '20' : '50', value: typ === 'cla' ? (data.split(':')[1] || 5) : (data.split(':')[0] || 5) })));
			}
			if (typ === 'nej') {
				radky.push(pole(T('Období'), vyber('blok_obdobi', N.obdobiNej, data.split(':')[1] || 30), T('Počítá se z vlastní statistiky návštěv; bez ní podle celkového počtu přečtení.')));
			}
			if (typ === 'pod') {
				var taPod = el('textarea', { name: 'obsah', rows: '3' });
				taPod.value = String(b.obsah || '').replace(/<[^>]+>/g, '');
				radky.push(pole(T('Výzva'), taPod, T('Jedna až dvě věty. Prázdné = výchozí text.')));
				radky.push(pole(T('Text tlačítka'), el('input', { type: 'text', name: 'pod_tlacitko', maxlength: '60', value: data.split('|')[0] || '', placeholder: T('Podpořit redakci') })));
				radky.push(pole(T('Kam tlačítko vede'), el('input', { type: 'text', name: 'pod_adresa', maxlength: '190', value: data.split('|')[1] || '', placeholder: 'https://… nebo /podporte-nas' }), T('Platební odkaz (Stripe, Donio, Darujme, Ko-fi…) nebo vlastní stránka s číslem účtu a QR kódem.')));
			}
			if (typ === 'rek') {
				radky.push(pole(T('Reklamní pozice'), vyber('data_sys', Object.keys(N.pozice).map(function (k) { return [k, N.pozice[k]]; }), data), T('Bannery se spravují v sekci Reklama.')));
			}

			radky.push(el('div', { class: 'mc-pole' }, [el('span', { text: T('Vzhled') }), el('div', { class: 'mc-vzhledy' }, [[1, T('Běžný')], [2, T('Podbarvený')], [3, T('Zvýrazněný nadpis')], [4, T('V rámečku')]].map(function (v) {
				return el('label', {}, [el('input', { type: 'radio', name: 'vzhled', value: v[0], checked: vzhled === v[0] }), el('span', { text: v[1] })]);
			}))]));

			radky.push(el('details', {}, [
				el('summary', { text: T('Kdy a kde blok zobrazit') }),
				pole(T('Stránky'), vyber('zobrazit_kde', Object.keys(N.kde).map(function (k) { return [k, N.kde[k]]; }), b.zobrazit_kde)),
				pole(T('Jen v rubrice'), vyber('jen_rubrika', [[0, T('ve všech')]].concat(N.rubriky.map(function (r) { return [r.id, r.nazev]; })), b.jen_rubrika || 0)),
				N.jazyky.length ? pole(T('Jazyková verze'), vyber('jen_jazyk', N.jazyky, b.jen_jazyk || '')) : null,
				pole(T('Zařízení'), vyber('zarizeni', Object.keys(N.zarizeni).map(function (k) { return [k, N.zarizeni[k]]; }), b.zarizeni)),
				el('label', { class: 'mc-zaskrtnuti' }, [el('input', { type: 'checkbox', name: 'skryt', checked: !Number(b.zobrazit) }), T(' Blok dočasně skrýt')])
			]));
			radky.push(el('div', { class: 'mc-tlacitka' }, [el('button', { type: 'submit', class: 'mc-hlavni', text: T('Uložit') }), el('button', { type: 'button', text: T('Zrušit'), onclick: function () { d.close(); } })]));
			radky.forEach(function (r) { form.appendChild(r); });

			var d = okno('mc-panel', [
				el('div', { class: 'mc-okno-hlava' }, [el('strong', { text: nazevTypu }), el('button', { type: 'button', 'aria-label': T('Zavřít'), text: '✕', onclick: function () { d.close(); } })]),
				form
			]);
			var editor = form.querySelector('textarea[data-editor]');
			if (editor && window.mirocmsVytvorEditor) { window.mirocmsVytvorEditor(editor); }

			form.addEventListener('submit', function (e) {
				e.preventDefault();
				var f = new FormData(form), odeslat = { idb: id, sys_funkce: typ, nazev: f.get('nazev'), zona: b.zona };
				odeslat.typ = f.get('ukazat_nadpis') ? (f.get('vzhled') || 1) : 5;
				odeslat.zobrazit_kde = f.get('zobrazit_kde'); odeslat.jen_rubrika = f.get('jen_rubrika'); odeslat.zarizeni = f.get('zarizeni'); odeslat.jen_jazyk = f.get('jen_jazyk') || '';
				if (!f.get('skryt')) { odeslat.zobrazit = 1; }
				['obsah', 'blok_rubrika', 'blok_pocet', 'blok_obdobi', 'data_sys', 'pod_tlacitko', 'pod_adresa'].forEach(function (k) { if (f.get(k) !== null) { odeslat[k] = f.get(k); } });
				if (typ === 'men') {
					odeslat.obsah_menu = Array.prototype.map.call(form.querySelectorAll('.mc-odkaz'), function (r) {
						var v = r.querySelectorAll('input'); return v[0].value.trim() && v[1].value.trim() ? v[0].value.trim() + ' | ' + v[1].value.trim() : '';
					}).filter(Boolean).join('\n');
				}
				odesli('uloz_json', odeslat).then(function (r) { return r.json(); }).then(function (o) {
					if (o.ok) { znovu(); } else { hlaska(o.chyba || T('Uložení se nezdařilo')); }
				});
			});
		});
	}

	/* ---------- po znovunačtení: vrátit posun a případně otevřít nastavení nového bloku ---------- */

	var posunuti = sessionStorage.getItem('mc-posun');
	if (posunuti !== null) { sessionStorage.removeItem('mc-posun'); window.scrollTo(0, Number(posunuti)); }
	var otevrit = sessionStorage.getItem('mc-otevrit');
	if (otevrit) {
		sessionStorage.removeItem('mc-otevrit');
		var novy = document.querySelector('.mc-blok[data-blok="' + otevrit + '"]');
		if (novy) { novy.scrollIntoView({ block: 'center' }); novy.classList.add('mc-novy'); otevriNastaveni(otevrit); }
	}
})();
