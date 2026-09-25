/* Kaleta - editor textu (novinky, stránky) a práce s obrázky. Bez knihoven, bez build kroku.
 *
 *   <textarea data-editor>            WYSIWYG editor (data-editor="maly" = zkrácená lišta)
 *   <input data-obrazek>              pole s adresou obrázku + tlačítko "Vybrat z galerie" a náhled
 *   <form data-nahravani>             nahrávání přetažením souborů
 *
 * Do formuláře se vždy odesílá obsah původní <textarea> - bez JavaScriptu zůstane obyčejným polem pro HTML.
 */

(function () {
	'use strict';

	var T = window.T || function (s) { return s; }; // překlad textů administrace (image/jazyky/admin-*.js)

	var SKRIPT = document.querySelector('script[data-admin-url]');
	var ADMIN = SKRIPT.getAttribute('data-admin-url');
	var MAX_SOUBOR = parseInt(SKRIPT.getAttribute('data-max-soubor') || '0', 10); // limit serveru na soubor v bajtech (0 = bez limitu)
	var MAX_STRANA = parseInt(SKRIPT.getAttribute('data-max-strana') || '2000', 10);
	var CSRF = (document.querySelector('input[name="_csrf"]') || {}).value || '';
	var GALERIE = ADMIN + '?modul=intergal';
	var ID_CLANKU = parseInt((document.querySelector('form[data-koncept] input[name="idc"]') || {}).value || '0', 10);
	var JAZYK = document.documentElement.lang || 'cs'; // formát data a času podle jazyka stránky

	function esc(t) { return String(t).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;'); }

	// Oznámení chyby vlastním dialogem - systémový alert() vestavěné prohlížeče potlačují stejně jako confirm().
	var oknoOznameni = null;
	function oznam(text) {
		if (!oknoOznameni) {
			oknoOznameni = document.createElement('dialog');
			oknoOznameni.className = 'potvrzeni';
			oknoOznameni.setAttribute('role', 'alertdialog');
			oknoOznameni.innerHTML = '<p></p><div><button type="button" class="tl" data-zavri>' + T('Zavřít') + '</button></div>';
			document.body.appendChild(oknoOznameni);
			oknoOznameni.querySelector('[data-zavri]').addEventListener('click', function () { oknoOznameni.close(); });
		}
		oknoOznameni.querySelector('p').textContent = text;
		if (!oknoOznameni.open) { oknoOznameni.showModal(); }
	}

	/* ---------- čištění HTML (vkládání z Wordu a webu) ---------- */

	var POVOLENE = { P: [], H2: [], H3: [], H4: [], STRONG: [], EM: [], B: [], I: [], U: [], S: [], SUB: [], SUP: [], BR: [], HR: [],
		A: ['href', 'title', 'target', 'rel'], UL: [], OL: [], LI: [], BLOCKQUOTE: [], CODE: [], PRE: [],
		FIGURE: ['class'], FIGCAPTION: [], IMG: ['src', 'alt', 'width', 'height', 'loading', 'data-id'],
		TABLE: [], THEAD: [], TBODY: [], TR: [], TH: ['colspan', 'rowspan'], TD: ['colspan', 'rowspan'], IFRAME: ['src', 'width', 'height', 'allowfullscreen', 'title'] };
	var PREVOD = { DIV: 'P', H1: 'H2', H5: 'H4', H6: 'H4' };

	function vycisti(uzel) {
		Array.prototype.slice.call(uzel.childNodes).forEach(function (n) {
			if (n.nodeType === 8) { n.remove(); return; }
			if (n.nodeType !== 1) { return; }
			var tag = n.tagName;
			if (/^(SCRIPT|STYLE|META|LINK|TITLE|HEAD|O:P|XML)$/.test(tag)) { n.remove(); return; }
			vycisti(n);
			if (PREVOD[tag]) {
				var novy = document.createElement(PREVOD[tag]);
				while (n.firstChild) { novy.appendChild(n.firstChild); }
				n.replaceWith(novy);
				return;
			}
			if (!POVOLENE[tag]) {
				while (n.firstChild) { n.parentNode.insertBefore(n.firstChild, n); }
				n.remove();
				return;
			}
			Array.prototype.slice.call(n.attributes).forEach(function (a) {
				if (POVOLENE[tag].indexOf(a.name) === -1 || /^\s*javascript:/i.test(a.value)) { n.removeAttribute(a.name); }
			});
		});
	}

	function cisteHtml(html) {
		var box = document.createElement('div');
		box.innerHTML = html;
		vycisti(box);
		return box.innerHTML.replace(/<p>(\s|&nbsp;|<br>)*<\/p>/g, '').replace(/&nbsp;/g, ' ').trim();
	}

	/* ---------- nahrávání ---------- */

	// Fotka z telefonu (5–10 MB) se zmenší už v prohlížeči na MAX_STRANA px – stejně by ji zmenšil server – a nenarazí tak
	// na limit hostingu. Otočení podle EXIF zachová createImageBitmap; údaje EXIF (i poloha) zmizí, jako při zpracování na serveru.
	function zmensi(soubor) {
		if (!/^image\/(jpeg|png|webp)$/.test(soubor.type) || !window.createImageBitmap) { return Promise.resolve(soubor); }
		return createImageBitmap(soubor, { imageOrientation: 'from-image' }).then(function (bitmapa) {
			var pomer = Math.min(1, MAX_STRANA / Math.max(bitmapa.width, bitmapa.height));
			if (pomer === 1 && (!MAX_SOUBOR || soubor.size <= MAX_SOUBOR)) { bitmapa.close(); return soubor; }
			var platno = document.createElement('canvas');
			platno.width = Math.round(bitmapa.width * pomer);
			platno.height = Math.round(bitmapa.height * pomer);
			platno.getContext('2d').drawImage(bitmapa, 0, 0, platno.width, platno.height);
			bitmapa.close();
			// kvalita 0,9; když je výsledek pořád nad limitem serveru, zkusí se nižší (PNG kvalitu nemá)
			var zapis = function (kvalita) {
				return new Promise(function (hotovo) { platno.toBlob(hotovo, soubor.type, kvalita); }).then(function (blob) {
					if (blob && MAX_SOUBOR && blob.size > MAX_SOUBOR && soubor.type !== 'image/png' && kvalita > 0.6) { return zapis(Math.round((kvalita - 0.1) * 10) / 10); }
					// prohlížeč, který typ neumí zapsat (WebP v Safari), vrátí jiný – pak raději původní soubor
					return blob && blob.type === soubor.type && (pomer < 1 || blob.size < soubor.size)
						? new File([blob], soubor.name, { type: soubor.type, lastModified: soubor.lastModified }) : soubor;
				});
			};
			return zapis(0.9);
		}).catch(function () { return soubor; });
	}

	// Zmenší obrázky a odloží soubory, které by server i tak odmítl (celý požadavek nad limitem by skončil chybou bez vysvětlení).
	function pripravSoubory(soubory) {
		return Promise.all(Array.prototype.map.call(soubory, zmensi)).then(function (hotove) {
			var chyby = [];
			var ok = hotove.filter(function (s) {
				if (!MAX_SOUBOR || s.size <= MAX_SOUBOR) { return true; }
				chyby.push(s.name + ': ' + T('Soubor je větší, než server dovoluje nahrát (nejvýš %s). Zmenšete ho, nebo požádejte správce hostingu o vyšší limit.').replace('%s', SKRIPT.getAttribute('data-max-soubor-text') || ''));
				return false;
			});
			if (chyby.length) { oznam(chyby.join('\n')); }
			return ok;
		});
	}

	function nahraj(vybrane) {
		return pripravSoubory(vybrane).then(function (soubory) { return soubory.length ? odesli(soubory) : []; });
	}

	function odesli(soubory) {
		var data = new FormData();
		var slozka = document.querySelector('.galerie-okno[open] select');
		data.append('_csrf', CSRF);
		data.append('sekce', slozka && /^\d+$/.test(slozka.value) ? slozka.value : '0');
		soubory.forEach(function (s) { data.append('soubory[]', s); });
		return fetch(GALERIE + '&akce=nahraj&format=json', { method: 'POST', body: data, credentials: 'same-origin' })
			.then(function (r) { return r.json(); })
			.then(function (j) {
				if (j.chyby && j.chyby.length) { oznam(j.chyby.join('\n')); }
				return j.obrazky || [];
			})
			.catch(function () { oznam(T('Nahrání se nezdařilo. Zkontrolujte připojení a zkuste to znovu.')); return []; });
	}

	function jsouObrazky(prenos) {
		return prenos && prenos.files && prenos.files.length && Array.prototype.every.call(prenos.files, function (f) { return /^image\//.test(f.type); });
	}

	/* ---------- okno galerie ---------- */

	var okno = null;

	// vice = true: klepnutím se obrázky označují a vloží se najednou jako fotogalerie
	function vyberObrazek(zpetne, vice, sPrilohami) {
		var vybrane = [];
		if (!okno) {
			okno = document.createElement('dialog');
			okno.className = 'galerie-okno';
			okno.innerHTML = '<div class="galerie-okno-hlava"><strong>' + T('Média') + '</strong>'
				+ '<label class="tl">' + T('Nahrát nový') + '<input type="file" multiple hidden></label>'
				// na telefonu a tabletu: vyfotit přímo do textu (tlačítko ukazuje CSS jen na dotykových zařízeních)
				+ '<label class="navigace galerie-vyfotit">' + T('Vyfotit') + '<input type="file" accept="image/*" capture="environment" hidden></label>'
				+ '<button type="button" class="tl" data-vlozit hidden></button>' // „Vložit galerii (n)“ – jen při výběru více fotek
				+ '<button type="button" class="navigace" data-zavri>' + T('Zavřít') + '</button></div>'
				+ '<div class="galerie-okno-filtr"><select aria-label="' + T('Složka') + '"></select>'
				+ '<input class="textpole" type="search" placeholder="' + T('Hledat v médiích…') + '" aria-label="' + T('Hledat v médiích') + '"></div>'
				+ '<p class="napoveda"></p><div class="galerie-mrizka"></div>' // text nápovědy se nastavuje při každém otevření;
			document.body.appendChild(okno);
			okno.querySelector('[data-zavri]').addEventListener('click', function () { okno.close(); });
			okno.querySelector('[data-vlozit]').addEventListener('click', function () { okno.close(); okno.zpetne(okno.vybrane.slice()); });
			okno.querySelector('select').addEventListener('change', function () { nacti(this.value); });
			var cekani = null; // hledá se až po krátké pauze v psaní, ne po každém písmenu
			okno.querySelector('input[type=search]').addEventListener('input', function () {
				clearTimeout(cekani);
				cekani = setTimeout(function () { nacti(okno.querySelector('select').value); }, 300);
			});
			Array.prototype.forEach.call(okno.querySelectorAll('input[type=file]'), function (vstup) { vstup.addEventListener('change', function () {
				nahraj(this.files).then(function (nove) { nove.reverse().forEach(function (o) { pridej(o, true); }); });
				this.value = '';
			}); });
			okno.addEventListener('dragover', function (e) { e.preventDefault(); });
			okno.addEventListener('drop', function (e) {
				e.preventDefault();
				if (e.dataTransfer.files.length) { nahraj(e.dataTransfer.files).then(function (nove) { nove.reverse().forEach(function (o) { pridej(o, true); }); }); }
			});
		}
		var mrizka = okno.querySelector('.galerie-mrizka');
		function pridej(o, nahoru) {
			var b = document.createElement('button');
			b.type = 'button';
			b.className = 'galerie-polozka';
			if (o.soubor && !okno.sPrilohami) { return; } // hlavní obrázek, logo, galerie: jen obrázky
			b.innerHTML = o.soubor ? '<span class="galerie-soubor"><span></span></span><span></span>' : '<img loading="lazy" alt=""><span></span>';
			if (o.soubor) { b.firstChild.firstChild.textContent = o.pripona; } else { b.firstChild.src = o.nahled; }
			b.lastChild.textContent = o.nazev || T('bez názvu');
			b.addEventListener('click', function () {
				if (!okno.vice) { okno.close(); okno.zpetne(o); return; }
				var i = okno.vybrane.indexOf(o);
				if (i === -1) { okno.vybrane.push(o); } else { okno.vybrane.splice(i, 1); }
				b.classList.toggle('vybrana', i === -1);
				b.setAttribute('aria-pressed', i === -1 ? 'true' : 'false');
				okno.oznac();
			});
			if (nahoru) { mrizka.prepend(b); } else { mrizka.appendChild(b); }
		}
		// filtr: "" = vše, "clanek" = obrázky této novinky, číslo = složka (0 = nezařazené)
		function nacti(filtr) {
			var dotaz = filtr === 'clanek' ? '&clanek=' + ID_CLANKU : (filtr !== '' ? '&sekce=' + filtr : '');
			var hledat = okno.querySelector('input[type=search]').value.trim();
			if (hledat !== '') { dotaz += '&hledat=' + encodeURIComponent(hledat); }
			mrizka.textContent = T('Načítám…');
			fetch(GALERIE + '&akce=seznam' + dotaz, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
				var vyber = okno.querySelector('select');
				vyber.textContent = '';
				[['', T('Všechna média')]].concat(ID_CLANKU ? [['clanek', T('V tomto textu')]] : [], [['0', T('Nezařazené')]], j.slozky.map(function (s) { return [String(s.id), T('Složka: ') + s.nazev]; })).forEach(function (v) {
					var o = document.createElement('option');
					o.value = v[0]; o.textContent = v[1]; o.selected = v[0] === filtr;
					vyber.appendChild(o);
				});
				mrizka.textContent = j.obrazky.length ? '' : (hledat !== '' ? T('Hledanému textu nic neodpovídá.') : T('Tady zatím žádné obrázky nejsou.'));
				j.obrazky.forEach(function (o) { pridej(o, false); });
				dalsi(dotaz, 2, j.obrazky.length);
			});
		}
		// server vrací 60 položek na stránku: plná stránka = nabídnout další, ať jsou dosažitelné i starší soubory
		function dalsi(dotaz, strana, nacteno) {
			if (nacteno < 60) { return; }
			var tl = document.createElement('button');
			tl.type = 'button';
			tl.className = 'navigace media-dalsi';
			tl.textContent = T('Načíst další');
			tl.addEventListener('click', function () {
				tl.disabled = true;
				fetch(GALERIE + '&akce=seznam' + dotaz + '&strana=' + strana, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
					tl.remove();
					j.obrazky.forEach(function (o) { pridej(o, false); });
					dalsi(dotaz, strana + 1, j.obrazky.length);
				});
			});
			mrizka.appendChild(tl);
		}
		okno.zpetne = zpetne;
		okno.vice = !!vice;
		okno.sPrilohami = !!sPrilohami && !vice;
		okno.vybrane = vybrane;
		okno.oznac = function () {
			var tl = okno.querySelector('[data-vlozit]');
			tl.hidden = !okno.vice;
			tl.disabled = okno.vybrane.length < 2;
			tl.textContent = okno.vybrane.length < 2 ? T('Označte aspoň 2 fotky') : T('Vložit galerii (') + okno.vybrane.length + ')';
		};
		okno.oznac();
		okno.querySelector('.napoveda').textContent = vice
			? T('Klepnutím označte fotky v pořadí, v jakém mají jít za sebou. Soubory sem můžete i přetáhnout.')
			: T('Klepnutím obrázek vložíte. Soubory sem můžete i přetáhnout - nahrají se do zvolené složky.');
		okno.showModal();
		nacti(okno.querySelector('select').value || '');
	}

	function htmlObrazku(o) {
		return '<figure><img src="' + esc(o.url) + '" alt="' + esc(o.nazev) + '" width="' + o.sirka + '" height="' + o.vyska + '" loading="lazy" data-id="' + o.id + '">'
			+ (o.popis ? '<figcaption>' + esc(o.popis) + '</figcaption>' : '') + '</figure><p><br></p>';
	}

	function htmlPrilohy(o) {
		return '<p><a href="' + esc(o.url) + '" title="' + esc(o.pripona + ', ' + o.velikost) + '">' + esc(o.nazev || o.pripona) + '</a> (' + esc(o.pripona + ', ' + o.velikost) + ')</p>';
	}

	function htmlGalerie(obrazky) {
		return '<figure class="galerie">' + obrazky.map(function (o) {
			return '<img src="' + esc(o.url) + '" alt="' + esc(o.popis || o.nazev) + '" width="' + o.sirka + '" height="' + o.vyska + '" loading="lazy" data-id="' + o.id + '">';
		}).join('') + '</figure><p><br></p>';
	}

	/* ---------- editor ---------- */

	var TLACITKA = [
		['¶', T('Odstavec'), function () { prikaz('formatBlock', 'P'); }],
		['H2', T('Mezititulek'), function () { prikaz('formatBlock', 'H2'); }, 'velky'],
		['H3', T('Menší mezititulek'), function () { prikaz('formatBlock', 'H3'); }, 'velky'],
		['B', T('Tučně (Ctrl+B)'), function () { prikaz('bold'); }],
		['I', T('Kurzíva (Ctrl+I)'), function () { prikaz('italic'); }],
		[T('odkaz'), T('Vložit odkaz (Ctrl+K)'), odkaz],
		[T('• seznam'), T('Odrážkový seznam'), function () { prikaz('insertUnorderedList'); }],
		[T('1. seznam'), T('Číslovaný seznam'), function () { prikaz('insertOrderedList'); }, 'velky'],
		[T('„citace“'), T('Citace'), function () { prikaz('formatBlock', 'BLOCKQUOTE'); }, 'velky'],
		[T('obrázek'), T('Vložit obrázek z médií'), null, 'velky'],
		[T('galerie'), T('Vložit fotogalerii - návštěvník si fotky prolistuje přes celou obrazovku'), 'galerie', 'velky'],
		[T('tabulka'), T('Vložit tabulku 3 × 3 se záhlavím; řádky a sloupce pak přidáte tlačítky nad tabulkou'), function () {
			var radek = function (tag) { return '<tr><' + tag + '><br></' + tag + '><' + tag + '><br></' + tag + '><' + tag + '><br></' + tag + '></tr>'; };
			prikaz('insertHTML', '<table><thead>' + radek('th') + '</thead><tbody>' + radek('td') + radek('td') + '</tbody></table><p><br></p>');
		}, 'velky'],
		['—', T('Oddělovací čára'), function () { prikaz('insertHorizontalRule'); }, 'velky'],
		['Tx', T('Odstranit formátování'), function () { prikaz('removeFormat'); prikaz('unlink'); }]
	];

	function prikaz(nazev, hodnota) { document.execCommand(nazev, false, hodnota || null); }

	/* Dialog odkazu: adresa, nebo vlastní novinka vyhledaná podle titulku. Systémový prompt() vestavěné prohlížeče potlačují. */
	var oknoOdkazu = null;

	function odkaz() {
		var vyber = window.getSelection();
		var rozsah = vyber.rangeCount ? vyber.getRangeAt(0).cloneRange() : null;
		var uzel = vyber.anchorNode && (vyber.anchorNode.nodeType === 1 ? vyber.anchorNode : vyber.anchorNode.parentElement);
		var kotva = uzel && uzel.closest('a');
		var plocha = uzel && uzel.closest('.editor-plocha');
		if (!plocha || !rozsah) { return; }
		if (!oknoOdkazu) {
			oknoOdkazu = document.createElement('dialog');
			oknoOdkazu.className = 'galerie-okno odkaz-okno';
			oknoOdkazu.innerHTML = '<form method="dialog"><div class="galerie-okno-hlava"><strong>' + T('Odkaz') + '</strong></div>'
				+ '<label>' + T('Adresa') + '<input class="textpole siroke" type="text" name="adresa" placeholder="https://… ' + T('nebo') + ' /o-nas" autocomplete="off"></label>'
				+ '<label>' + T('…nebo najděte novinku webu') + '<input class="textpole siroke" type="search" name="hledat" placeholder="' + T('část titulku') + '" autocomplete="off"></label>'
				+ '<div class="odkaz-vysledky" aria-live="polite"></div>'
				+ '<label class="odkaz-volba"><input type="checkbox" name="nove"> ' + T('otevřít v novém okně') + '</label>'
				+ '<div class="odkaz-tlacitka"><button type="submit" class="tl" value="ok">' + T('Vložit odkaz') + '</button> <button type="button" class="navigace" data-zrusit>' + T('Zrušit odkaz') + '</button> <button type="button" class="navigace" data-zavri>' + T('Zavřít') + '</button></div></form>';
			document.body.appendChild(oknoOdkazu);
			var casovac = null;
			oknoOdkazu.querySelector('[name=hledat]').addEventListener('input', function () {
				var q = this.value.trim(), vysledky = oknoOdkazu.querySelector('.odkaz-vysledky');
				clearTimeout(casovac);
				if (q.length < 2) { vysledky.textContent = ''; return; }
				casovac = setTimeout(function () {
					fetch(ADMIN + '?modul=novinky&akce=hledej_json&q=' + encodeURIComponent(q), { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
						vysledky.textContent = j.clanky.length ? '' : T('Nic nenalezeno.');
						j.clanky.forEach(function (c) {
							var b = document.createElement('button');
							b.type = 'button';
							b.textContent = c.titulek + (c.vydany ? '' : ' (' + T('nevydaný') + ')');
							b.addEventListener('click', function () { oknoOdkazu.querySelector('[name=adresa]').value = c.url; oknoOdkazu.querySelector('[name=adresa]').focus(); });
							vysledky.appendChild(b);
						});
					});
				}, 250);
			});
			oknoOdkazu.querySelector('[data-zavri]').addEventListener('click', function () { oknoOdkazu.close('zavrit'); });
			oknoOdkazu.querySelector('[data-zrusit]').addEventListener('click', function () { oknoOdkazu.close('zrusit'); });
			oknoOdkazu.addEventListener('close', function () { oknoOdkazu.hotovo(oknoOdkazu.returnValue); });
		}
		var f = oknoOdkazu.querySelector('form');
		f.adresa.value = kotva ? kotva.getAttribute('href') : '';
		f.hledat.value = '';
		f.nove.checked = !!(kotva && kotva.target === '_blank');
		oknoOdkazu.querySelector('.odkaz-vysledky').textContent = '';
		oknoOdkazu.querySelector('[data-zrusit]').hidden = !kotva;
		oknoOdkazu.returnValue = '';
		oknoOdkazu.hotovo = function (vysledek) {
			plocha.focus();
			vyber.removeAllRanges();
			if (kotva) { var r = document.createRange(); r.selectNode(kotva); vyber.addRange(r); } else { vyber.addRange(rozsah); }
			var url = f.adresa.value.trim();
			if (vysledek === 'zrusit') { prikaz('unlink'); } else if (vysledek === 'ok' && url !== '' && !/^\s*javascript:/i.test(url)) {
				var cil = f.nove.checked ? ' target="_blank" rel="noopener"' : '';
				var text = vyber.isCollapsed ? url : (kotva ? kotva.innerHTML : vyber.toString().replace(/&/g, '&amp;').replace(/</g, '&lt;'));
				prikaz('insertHTML', '<a href="' + url.replace(/&/g, '&amp;').replace(/"/g, '&quot;') + '"' + cil + '>' + (kotva || !vyber.isCollapsed ? text : url.replace(/&/g, '&amp;').replace(/</g, '&lt;')) + '</a>');
			}
			plocha.dispatchEvent(new Event('input', { bubbles: true }));
		};
		oknoOdkazu.showModal();
		f.adresa.focus();
	}

	function vytvorEditor(pole) {
		var maly = pole.getAttribute('data-editor') === 'maly';
		var obal = document.createElement('div');
		obal.className = 'editor' + (maly ? ' editor-maly' : '');
		var lista = document.createElement('div');
		lista.className = 'editor-nastroje';
		lista.setAttribute('role', 'toolbar');
		var plocha = document.createElement('div');
		plocha.className = 'editor-plocha';
		plocha.contentEditable = 'true';
		plocha.style.setProperty('--ed-popis-galerie', JSON.stringify(T('Fotogalerie'))); // štítek nad fotogalerií kreslí editor.css; text v CSS by přeložit nešel
		plocha.setAttribute('role', 'textbox');
		plocha.setAttribute('aria-multiline', 'true');
		plocha.setAttribute('aria-label', (pole.labels && pole.labels[0] ? pole.labels[0].textContent : 'Text'));
		var stav = document.createElement('div');
		stav.className = 'editor-stav';
		var zdroj = false;

		function doPole() { if (!zdroj) { pole.value = cisteHtml(plocha.innerHTML); } pocitej(); pole.dispatchEvent(new Event('input', { bubbles: true })); }
		function zPole() { plocha.innerHTML = pole.value.trim() || '<p><br></p>'; }
		function pocitej() {
			var slov = (plocha.innerText.trim().match(/\S+/g) || []).length;
			stav.firstChild.textContent = slov + T(' slov') + (maly ? '' : T(' · čtení asi ') + Math.max(1, Math.round(slov / 200)) + ' min');
		}
		function vlozObrazek(galerie) {
			var rozsah = window.getSelection().rangeCount ? window.getSelection().getRangeAt(0).cloneRange() : null;
			vyberObrazek(function (o) {
				plocha.focus();
				if (rozsah && plocha.contains(rozsah.startContainer)) { window.getSelection().removeAllRanges(); window.getSelection().addRange(rozsah); }
				prikaz('insertHTML', galerie ? htmlGalerie(o) : (o.soubor ? htmlPrilohy(o) : htmlObrazku(o)));
				doPole();
			}, galerie, true);
		}

		TLACITKA.forEach(function (t) {
			if (maly && t[3] === 'velky') { return; }
			var b = document.createElement('button');
			b.type = 'button';
			b.textContent = t[0];
			b.title = t[1];
			if (t[0] === 'B') { b.style.fontWeight = 'bold'; }
			if (t[0] === 'I') { b.style.fontStyle = 'italic'; }
			b.addEventListener('mousedown', function (e) { e.preventDefault(); });
			b.addEventListener('click', function () { if (zdroj) { return; } plocha.focus(); if (typeof t[2] === 'function') { t[2](); } else { vlozObrazek(t[2] === 'galerie'); } doPole(); });
			lista.appendChild(b);
		});
		var html = document.createElement('button');
		html.type = 'button';
		html.textContent = 'HTML';
		html.title = T('Přepnout na zdrojový kód');
		html.className = 'editor-html';
		html.setAttribute('aria-pressed', 'false');
		html.addEventListener('click', function () {
			zdroj = !zdroj;
			if (zdroj) { pole.value = cisteHtml(plocha.innerHTML).replace(/<\/(p|h2|h3|h4|ul|ol|li|blockquote|figure)>/g, '</$1>\n'); } else { zPole(); }
			obal.classList.toggle('editor-zdroj', zdroj);
			html.setAttribute('aria-pressed', zdroj ? 'true' : 'false');
			(zdroj ? pole : plocha).focus();
		});
		lista.appendChild(html);
		stav.appendChild(document.createElement('span'));
		stav.appendChild(document.createElement('span'));

		pole.parentNode.insertBefore(obal, pole);
		obal.appendChild(lista);
		obal.appendChild(plocha);
		obal.appendChild(pole);
		obal.appendChild(stav);
		pole.classList.add('editor-pole');
		zPole();
		prikaz('defaultParagraphSeparator', 'p');

		// úpravy tabulky: lišta se ukáže, když je kurzor v tabulce
		var tabLista = document.createElement('div');
		tabLista.className = 'editor-tabulka-lista';
		tabLista.hidden = true;
		var bunka = function () {
			var uzel = window.getSelection().anchorNode;
			uzel = uzel && (uzel.nodeType === 1 ? uzel : uzel.parentElement);
			var b = uzel && uzel.closest('td, th');
			return b && plocha.contains(b) ? b : null;
		};
		[[T('+ řádek'), function (b) {
			var novy = b.parentNode.cloneNode(true);
			Array.prototype.forEach.call(novy.children, function (c) { var td = document.createElement('td'); td.innerHTML = '<br>'; c.replaceWith(td); });
			var telo = b.closest('table').querySelector('tbody') || b.closest('table');
			if (b.parentNode.parentNode.tagName === 'THEAD') { telo.prepend(novy); } else { b.parentNode.after(novy); }
		}], [T('+ sloupec'), function (b) {
			var i = b.cellIndex;
			Array.prototype.forEach.call(b.closest('table').rows, function (r) { var c = document.createElement(r.cells[i].tagName); c.innerHTML = '<br>'; r.cells[i].after(c); });
		}], [T('− řádek'), function (b) {
			var t = b.closest('table');
			if (t.rows.length > 1) { b.parentNode.remove(); } else { t.remove(); }
		}], [T('− sloupec'), function (b) {
			var i = b.cellIndex, t = b.closest('table');
			if (t.rows[0].cells.length > 1) { Array.prototype.forEach.call(t.rows, function (r) { r.deleteCell(i); }); } else { t.remove(); }
		}], [T('smazat tabulku'), function (b) { b.closest('table').remove(); }]].forEach(function (a) {
			var tl = document.createElement('button');
			tl.type = 'button';
			tl.textContent = a[0];
			tl.addEventListener('mousedown', function (e) { e.preventDefault(); });
			tl.addEventListener('click', function () { var b = bunka(); if (b) { a[1](b); doPole(); tabLista.hidden = !bunka(); } });
			tabLista.appendChild(tl);
		});
		lista.after(tabLista);
		document.addEventListener('selectionchange', function () { tabLista.hidden = zdroj || !bunka(); });

		plocha.addEventListener('input', doPole);
		plocha.addEventListener('blur', doPole);
		plocha.addEventListener('keydown', function (e) {
			// stopPropagation: stejnou zkratku má paleta příkazů (admin.js) - v editoru znamená „vložit odkaz“
			if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); e.stopPropagation(); odkaz(); doPole(); }
		});
		plocha.addEventListener('paste', function (e) {
			var prenos = e.clipboardData;
			if (jsouObrazky(prenos)) {
				e.preventDefault();
				nahraj(prenos.files).then(function (nove) { nove.forEach(function (o) { prikaz('insertHTML', htmlObrazku(o)); }); doPole(); });
				return;
			}
			var vlozene = prenos.getData('text/html');
			if (vlozene) { e.preventDefault(); prikaz('insertHTML', cisteHtml(vlozene)); doPole(); }
		});
		plocha.addEventListener('dragover', function (e) { if (jsouObrazky(e.dataTransfer) || (e.dataTransfer.types || []).indexOf('Files') !== -1) { e.preventDefault(); obal.classList.add('editor-pretazeni'); } });
		plocha.addEventListener('dragleave', function () { obal.classList.remove('editor-pretazeni'); });
		plocha.addEventListener('drop', function (e) {
			obal.classList.remove('editor-pretazeni');
			// jiný soubor než obrázek (PDF…): nenahrává se, ale prohlížeč ho nesmí otevřít místo formuláře - rozepsaný text by byl pryč
			if (!jsouObrazky(e.dataTransfer)) { if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length) { e.preventDefault(); } return; }
			e.preventDefault();
			nahraj(e.dataTransfer.files).then(function (nove) { plocha.focus(); nove.forEach(function (o) { prikaz('insertHTML', htmlObrazku(o)); }); doPole(); });
		});
		if (pole.form) { pole.form.addEventListener('submit', function () { if (!zdroj) { pole.value = cisteHtml(plocha.innerHTML); } }); }
		pocitej();
		// pomocník editoru (kontrola přístupnosti, AI asistent) po zápisu do pole editor překreslí
		window.kaletaEditory = window.kaletaEditory || {};
		if (pole.id) { window.kaletaEditory[pole.id] = { obnov: function () { zPole(); pocitej(); } }; }
		return { obnov: zPole, stav: stav.lastChild };
	}

	/* ---------- automatické ukládání rozepsaného textu do prohlížeče ---------- */

	function autoUkladani(form, editory) {
		var klic = 'kaleta-koncept:' + form.getAttribute('data-koncept');
		var pole = Array.prototype.filter.call(form.elements, function (p) { return p.name && p.name !== '_csrf' && p.type !== 'password' && p.type !== 'file' && p.type !== 'submit'; });
		var casovac = null;

		function uloz() {
			var data = { cas: Date.now(), pole: {} };
			pole.forEach(function (p) { if (p.type === 'checkbox' || p.type === 'radio') { if (p.checked) { data.pole[p.name] = p.value; } else if (p.type === 'checkbox') { data.pole[p.name] = null; } } else { data.pole[p.name] = p.value; } });
			try { localStorage.setItem(klic, JSON.stringify(data)); } catch (e) { /* prohlížeč úložiště nedovolil - zbývá server */ }
			editory.forEach(function (ed) { ed.stav.textContent = T('rozepsaný text uložen v prohlížeči ') + new Date().toLocaleTimeString(JAZYK, { hour: '2-digit', minute: '2-digit' }); });
			posledniData = data;
			if (!casovacServer) { casovacServer = setTimeout(ulozNaServer, 15000); }
		}
		// na server jde rozepsaný stav nejvýš jednou za 15 vteřin: dá se v něm pokračovat z jiného zařízení
		var urlKonceptu = form.getAttribute('data-koncept-url'), casovacServer = null, posledniData = null;
		function ulozNaServer() {
			casovacServer = null;
			if (!urlKonceptu || !posledniData) { return; }
			var fd = new FormData();
			fd.append('_csrf', CSRF);
			fd.append('idc', String(ID_CLANKU || 0));
			fd.append('pole', JSON.stringify(posledniData.pole));
			fetch(urlKonceptu, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
				if (j.ok) { editory.forEach(function (ed) { ed.stav.textContent = T('rozepsaný text uložen i na serveru ') + new Date().toLocaleTimeString(JAZYK, { hour: '2-digit', minute: '2-digit' }); }); }
			}).catch(function () { /* bez spojení zůstává kopie v prohlížeči */ });
		}
		function zahodNaServeru() {
			if (!urlKonceptu) { return; }
			var fd = new FormData();
			fd.append('_csrf', CSRF);
			fd.append('idc', String(ID_CLANKU || 0));
			fetch(urlKonceptu, { method: 'POST', body: fd, credentials: 'same-origin' }).catch(function () { /* nic */ });
		}
		form.addEventListener('input', function () { clearTimeout(casovac); casovac = setTimeout(uloz, 1500); });
		// při odeslání se kopie nemaže, jen označí: když uložení selže (vypršelé přihlášení, výpadek spojení), text zůstane k obnovení.
		// Smaže se až po potvrzeném uložení (hláška o úspěchu, image/admin.js), nebo když se shoduje s uloženým obsahem.
		form.addEventListener('submit', function () { clearTimeout(casovac); uloz(); try { var d = JSON.parse(localStorage.getItem(klic) || 'null'); if (d) { d.odeslano = Date.now(); localStorage.setItem(klic, JSON.stringify(d)); } } catch (e) { /* nic */ } });

		var ulozene = null;
		try { ulozene = JSON.parse(localStorage.getItem(klic) || 'null'); } catch (e) { /* nic */ }
		// novější z obou kopií: prohlížeč tohoto zařízení, nebo server (psaní z jiného zařízení)
		var zeServeru = null;
		try { zeServeru = JSON.parse((document.getElementById('koncept-server') || {}).textContent || 'null'); } catch (e) { /* nic */ }
		var jeZeServeru = !!(zeServeru && zeServeru.pole && (!ulozene || !ulozene.cas || zeServeru.cas > ulozene.cas));
		if (jeZeServeru) { ulozene = zeServeru; }
		if (!ulozene || !ulozene.pole || Date.now() - ulozene.cas > 14 * 86400000) { return; }
		var lisiSe = pole.some(function (p) { return (p.tagName === 'TEXTAREA' || p.type === 'text') && ulozene.pole[p.name] !== undefined && ulozene.pole[p.name] !== p.value; });
		if (!lisiSe) { if (!jeZeServeru) { try { localStorage.removeItem(klic); } catch (e) { /* nic */ } } return; }
		var lista = document.createElement('p');
		lista.className = 'hlaska';
		lista.innerHTML = T(jeZeServeru ? 'Na serveru je neuložená rozepsaná verze z ' : 'V prohlížeči je neuložená rozepsaná verze z ') + new Date(ulozene.cas).toLocaleString(JAZYK) + '. <button type="button" class="navigace">' + T('Obnovit ji') + '</button> <button type="button" class="navigace">' + T('Zahodit') + '</button>';
		form.parentNode.insertBefore(lista, form);
		lista.children[0].addEventListener('click', function () {
			pole.forEach(function (p) {
				if (!(p.name in ulozene.pole)) { return; }
				if (p.type === 'checkbox') { p.checked = ulozene.pole[p.name] !== null; } else if (p.type === 'radio') { p.checked = p.value === ulozene.pole[p.name]; } else { p.value = ulozene.pole[p.name]; }
			});
			editory.forEach(function (ed) { ed.obnov(); });
			document.querySelectorAll('[data-obrazek]').forEach(function (p) { p.dispatchEvent(new Event('change')); });
			lista.remove();
		});
		lista.children[1].addEventListener('click', function () { try { localStorage.removeItem(klic); } catch (e) { /* nic */ } zahodNaServeru(); lista.remove(); });
	}

	/* ---------- pole "Hlavní obrázek" ---------- */

	document.querySelectorAll('[data-obrazek]').forEach(function (pole) {
		var tl = document.createElement('button');
		tl.type = 'button';
		tl.className = 'navigace';
		tl.textContent = T('Vybrat z médií');
		var nahled = document.createElement('img');
		nahled.className = 'obrazek-nahled';
		nahled.alt = '';
		function ukaz() { nahled.hidden = pole.value.trim() === ''; if (!nahled.hidden) { nahled.src = pole.value; } }
		pole.after(tl, nahled);
		tl.addEventListener('click', function () { vyberObrazek(function (o) { pole.value = o.url; ukaz(); pole.dispatchEvent(new Event('input', { bubbles: true })); }); });
		pole.addEventListener('change', ukaz);
		nahled.addEventListener('error', function () { nahled.hidden = true; });
		ukaz();
	});

	/* ---------- nahrávání přetažením na stránce galerie ---------- */

	document.querySelectorAll('[data-nahravani]').forEach(function (form) {
		var vstup = form.querySelector('input[type=file]');
		form.addEventListener('dragover', function (e) { e.preventDefault(); form.classList.add('nahravani-aktivni'); });
		form.addEventListener('dragleave', function () { form.classList.remove('nahravani-aktivni'); });
		form.addEventListener('drop', function (e) {
			e.preventDefault();
			form.classList.remove('nahravani-aktivni');
			if (e.dataTransfer.files.length) { vstup.files = e.dataTransfer.files; form.requestSubmit(); }
		});
		// před odesláním zmenšit fotky a odložit soubory nad limit serveru (form.submit() už tuto obsluhu nespustí)
		form.addEventListener('submit', function (e) {
			if (!window.DataTransfer) { return; }
			e.preventDefault();
			var tlacitko = form.querySelector('[type=submit]');
			if (tlacitko) { tlacitko.disabled = true; }
			pripravSoubory(vstup.files).then(function (soubory) {
				if (tlacitko) { tlacitko.disabled = false; }
				if (!soubory.length) { vstup.value = ''; return; }
				var prenos = new DataTransfer();
				soubory.forEach(function (s) { prenos.items.add(s); });
				vstup.files = prenos.files;
				form.submit();
			});
		});
	});

	window.kaletaVytvorEditor = vytvorEditor; // builder stránek si editor vytváří sám nad dynamickým polem
	window.kaletaVyberObrazek = vyberObrazek; // výběr obrázku z Médií pro builder (zpětné volání dostane {url, nazev, …})

	var editory = Array.prototype.map.call(document.querySelectorAll('textarea[data-editor]'), vytvorEditor);
	var formKoncept = document.querySelector('form[data-koncept]');
	if (formKoncept) { autoUkladani(formKoncept, editory); }
})();
