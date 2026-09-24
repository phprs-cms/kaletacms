/* Kaleta - pomocník editoru: kontrola přístupnosti obsahu a AI asistent. Bez knihoven.
 *
 *   <fieldset data-kontrola>          sem se vypisuje průběžná kontrola (alt texty, nadpisy, odkazy, tabulky)
 *   <form data-asistent="adresa">     u polí formuláře přibydou tlačítka "✦ Navrhnout" (jen se zapnutým rozšířením)
 *
 * Asistent nic neukládá - návrh se jen vloží do pole formuláře a člověk ho může dál upravit.
 */
(function () {
	'use strict';

	var T = window.T || function (s) { return s; }; // překlad textů administrace (image/jazyky/admin-*.js)

	var form = document.querySelector('form.formular-clanek');
	if (!form) { return; }
	var pole = function (id) { return form.querySelector('#' + id); };

	function nastav(id, hodnota) {
		var p = pole(id);
		p.value = hodnota;
		if (window.kaletaEditory && window.kaletaEditory[id]) { window.kaletaEditory[id].obnov(); }
		p.dispatchEvent(new Event('input', { bubbles: true }));
	}
	function strom(html) { return new DOMParser().parseFromString('<div>' + html + '</div>', 'text/html').body.firstChild; }
	function esc(t) { return String(t).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }
	function prvek(tag, trida, text) { var e = document.createElement(tag); if (trida) { e.className = trida; } if (text) { e.textContent = text; } return e; }

	/* ---------- kontrola přístupnosti ---------- */

	var panel = form.querySelector('[data-kontrola]');
	var asistentUrl = form.getAttribute('data-asistent') || '';

	function zkontroluj() {
		if (!panel) { return; }
		var vystup = panel.querySelector('[data-kontrola-vysledek]');
		var nalezy = [];
		vystup.textContent = '';

		['uvod', 'text'].forEach(function (id) {
			var koren = strom(pole(id).value);
			Array.prototype.forEach.call(koren.querySelectorAll('img'), function (img, i) {
				if ((img.getAttribute('alt') || '').trim() !== '') { return; }
				var radek = prvek('div', 'kontrola-obrazek');
				var nahled = prvek('img'); nahled.src = img.getAttribute('src'); nahled.alt = '';
				var vstup = prvek('input', 'textpole'); vstup.type = 'text'; vstup.maxLength = 200; vstup.placeholder = T('co je na obrázku vidět');
				vstup.setAttribute('aria-label', T('Popis obrázku pro nevidomé návštěvníky'));
				var uloz = function () {
					if (vstup.value.trim() === '') { return; }
					var k = strom(pole(id).value);
					k.querySelectorAll('img')[i].setAttribute('alt', vstup.value.trim());
					nastav(id, k.innerHTML);
				};
				vstup.addEventListener('change', uloz);
				vstup.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); uloz(); } });
				radek.appendChild(nahled); radek.appendChild(vstup);
				if (asistentUrl) {
					var ai = prvek('button', 'navigace ai-tl', '✦'); ai.type = 'button'; ai.title = T('Navrhnout popis asistentem'); ai.setAttribute('aria-label', ai.title);
					ai.addEventListener('click', function () {
						ai.disabled = true; ai.textContent = '…';
						zeptejSe('alt', { obrazek: img.getAttribute('src') }).then(function (j) {
							if (j.navrhy && j.navrhy[0]) { vstup.value = j.navrhy[0]; vstup.focus(); } else { oznam(j.chyba || T('Asistent nic nenavrhl.')); }
						}).finally(function () { ai.disabled = false; ai.textContent = '✦'; });
					});
					radek.appendChild(ai);
				}
				nalezy.push([T('Obrázek bez popisu – nevidomý návštěvník ani vyhledávač neví, co na něm je. Popis doplňte a potvrďte Enterem:'), radek]);
			});

			var uroven = 1;
			Array.prototype.forEach.call(koren.querySelectorAll('h2, h3, h4'), function (h) {
				var u = parseInt(h.tagName.charAt(1), 10);
				if (u > uroven + 1) { nalezy.push([T('Mezititulek „') + h.textContent.trim().slice(0, 50) + T('“ přeskakuje úroveň (H') + u + ' bez H' + (u - 1) + T(' nad sebou). Čtečky podle úrovní skládají osnovu textu.')]); }
				if (h.textContent.trim() === '') { nalezy.push([T('Prázdný mezititulek – smažte ho.')]); }
				uroven = u;
			});
			Array.prototype.forEach.call(koren.querySelectorAll('a'), function (a) {
				var t = a.textContent.trim().toLowerCase();
				if (/^(zde|tady|tu|sem|klikn[ěe]te( zde)?|více|vice|odkaz|link|here|click here)$/.test(t) || /^https?:\/\//.test(t)) {
					nalezy.push([T('Odkaz „') + a.textContent.trim().slice(0, 40) + T('“ neříká, kam vede. Odkazujte slovy, která dávají smysl i sama o sobě.')]);
				}
			});
			Array.prototype.forEach.call(koren.querySelectorAll('table'), function (t) { if (!t.querySelector('th')) { nalezy.push([T('Tabulka nemá záhlaví (buňky TH) – čtečka neumí říct, co který sloupec znamená.')]); } });
			Array.prototype.forEach.call(koren.querySelectorAll('iframe'), function (f) { if (!(f.getAttribute('title') || '').trim()) { nalezy.push([T('Vložené video nebo rámec nemá název (atribut title).')]); } });
		});
		if (pole('titulek').value.length > 110) { nalezy.push([T('Titulek má přes 110 znaků – ve výsledcích hledání i na sítích se ořízne.')]); }
		if (pole('titulek').value.length > 12 && pole('titulek').value === pole('titulek').value.toUpperCase()) { nalezy.push([T('Titulek psaný VERZÁLKAMI se špatně čte a čtečky ho mohou hláskovat.')]); }
		if (strom(pole('uvod').value).textContent.trim() === '') { nalezy.push([T('Chybí perex – výpis novinek a sdílení na sítích ho potřebují.')]); }

		panel.classList.toggle('kontrola-ok', nalezy.length === 0);
		panel.querySelector('legend').textContent = T('Kontrola přístupnosti') + (nalezy.length ? ' (' + nalezy.length + ')' : '');
		if (!nalezy.length) { vystup.appendChild(prvek('p', 'kontrola-vporadku', T('✓ Obrázky mají popisy, nadpisy i odkazy jsou v pořádku.'))); return; }
		var ul = prvek('ul', 'kontrola-seznam');
		nalezy.forEach(function (n) { var li = prvek('li', '', n[0]); if (n[1]) { li.appendChild(n[1]); } ul.appendChild(li); });
		vystup.appendChild(ul);
	}

	var casovac = null;
	form.addEventListener('input', function (e) { if (panel && panel.contains(e.target)) { return; } clearTimeout(casovac); casovac = setTimeout(zkontroluj, 900); });
	zkontroluj();

	/* ---------- AI asistent ---------- */

	if (!asistentUrl) { return; }

	var okno = null;
	function dialog(nadpis) {
		if (!okno) {
			okno = prvek('dialog', 'galerie-okno ai-okno');
			okno.innerHTML = '<div class="galerie-okno-hlava"><strong></strong><button type="button" class="navigace" data-zavri>' + T('Zavřít') + '</button></div><div class="ai-obsah"></div>';
			document.body.appendChild(okno);
			okno.querySelector('[data-zavri]').addEventListener('click', function () { okno.close(); });
		}
		okno.querySelector('strong').textContent = '✦ ' + nadpis;
		var obsah = okno.querySelector('.ai-obsah');
		obsah.textContent = '';
		if (!okno.open) { okno.showModal(); }
		return obsah;
	}
	function oznam(text) { dialog(T('Asistent')).appendChild(prvek('p', 'hlaska hlaska-chyba', text)); }

	function zeptejSe(ukol, dalsi) {
		var data = new FormData();
		data.append('_csrf', form.querySelector('input[name="_csrf"]').value);
		data.append('ukol', ukol);
		['titulek', 'uvod', 'text'].forEach(function (id) { data.append(id, pole(id).value); });
		Object.keys(dalsi || {}).forEach(function (k) { data.append(k, dalsi[k]); });
		return fetch(asistentUrl, { method: 'POST', body: data, credentials: 'same-origin' })
			.then(function (r) { return r.json(); })
			.catch(function () { return { chyba: T('Spojení s asistentem selhalo. Zkuste to znovu.') }; });
	}

	/* úkol => [pole, popisek tlačítka, nadpis okna, jak návrh zapsat do pole] */
	var UKOLY = {
		titulky: ['titulek', T('Navrhnout'), T('Návrhy titulku'), function (n) { nastav('titulek', n); }],
		perex: ['uvod', T('Navrhnout'), T('Návrhy perexu'), function (n) { nastav('uvod', '<p>' + esc(n) + '</p>'); }],
		korektura: ['text', T('Korektura'), T('Korektura'), null],
		seo: ['seo_popis', T('Navrhnout'), T('Popis pro vyhledávače'), function (n) { nastav('seo_popis', n); }],
		stitky: ['stitky', T('Navrhnout'), T('Návrh štítků'), function (n) {
			var mam = pole('stitky').value.split(',').map(function (s) { return s.trim(); }).filter(Boolean);
			n.split(',').map(function (s) { return s.trim(); }).filter(Boolean).forEach(function (s) { if (mam.map(function (m) { return m.toLowerCase(); }).indexOf(s.toLowerCase()) === -1) { mam.push(s); } });
			nastav('stitky', mam.join(', '));
		}]
	};

	function ukazNavrhy(ukol, j) {
		var u = UKOLY[ukol];
		var obsah = dialog(u[2]);
		if (j.chyba || !j.navrhy || !j.navrhy.length) { obsah.appendChild(prvek('p', 'hlaska hlaska-chyba', j.chyba || T('Asistent nic nenavrhl. Zkuste to znovu.'))); return; }
		j.navrhy.forEach(function (n) {
			var radek = prvek('div', 'ai-navrh');
			radek.appendChild(prvek('p', '', n));
			var b = prvek('button', 'tl', T('Použít')); b.type = 'button';
			b.addEventListener('click', function () { u[3](n); okno.close(); });
			radek.appendChild(b);
			obsah.appendChild(radek);
		});
		obsah.appendChild(prvek('p', 'napoveda', T('Návrh se jen vloží do pole – můžete ho dál upravit. Nic se neuloží, dokud formulář neuložíte.')));
	}

	function ukazKorekturu(j) {
		var obsah = dialog(T('Korektura'));
		if (j.chyba) { obsah.appendChild(prvek('p', 'hlaska hlaska-chyba', j.chyba)); return; }
		if (!j.opravy.length) { obsah.appendChild(prvek('p', 'kontrola-vporadku', T('✓ Asistent nenašel nic k opravě.'))); return; }
		var polozky = j.opravy.map(function (o) {
			// oprava jde provést jen tam, kde se původní úsek v poli najde přesně (a nejde přes formátování)
			var kde = ['titulek', 'uvod', 'text'].filter(function (id) { return pole(id).value.indexOf(id === 'titulek' ? o.puvodni : esc(o.puvodni)) !== -1; })[0];
			var radek = prvek('label', 'ai-navrh ai-oprava');
			var box = prvek('input'); box.type = 'checkbox'; box.checked = !!kde; box.disabled = !kde;
			var text = prvek('span');
			text.appendChild(prvek('del', '', o.puvodni)); text.appendChild(document.createTextNode(' → ')); text.appendChild(prvek('ins', '', o.oprava));
			text.appendChild(prvek('small', '', (o.duvod ? ' ' + o.duvod : '') + (kde ? '' : T(' – úsek prochází formátováním, opravte ho prosím ručně'))));
			radek.appendChild(box); radek.appendChild(text);
			obsah.appendChild(radek);
			return { o: o, kde: kde, box: box };
		});
		var b = prvek('button', 'tl', T('Opravit označené')); b.type = 'button';
		b.addEventListener('click', function () {
			var hodnoty = {};
			polozky.forEach(function (p) {
				if (!p.kde || !p.box.checked) { return; }
				var h = hodnoty[p.kde] !== undefined ? hodnoty[p.kde] : pole(p.kde).value;
				hodnoty[p.kde] = p.kde === 'titulek' ? h.replace(p.o.puvodni, function () { return p.o.oprava; }) : h.replace(esc(p.o.puvodni), function () { return esc(p.o.oprava); });
			});
			Object.keys(hodnoty).forEach(function (id) { nastav(id, hodnoty[id]); });
			okno.close();
		});
		obsah.appendChild(b);
	}

	Object.keys(UKOLY).forEach(function (ukol) {
		var u = UKOLY[ukol];
		var stitek = form.querySelector('label[for="' + u[0] + '"]');
		if (!stitek || !pole(u[0])) { return; }
		var b = prvek('button', 'ai-tl', '✦ ' + u[1]); b.type = 'button';
		b.title = ukol === 'korektura' ? T('Asistent zkontroluje pravopis, překlepy a typografii') : T('Asistent navrhne znění podle textu');
		b.addEventListener('click', function () {
			b.disabled = true; b.textContent = T('✦ přemýšlím…');
			zeptejSe(ukol).then(function (j) { if (ukol === 'korektura') { ukazKorekturu(j); } else { ukazNavrhy(ukol, j); } })
				.finally(function () { b.disabled = false; b.textContent = '✦ ' + u[1]; });
		});
		stitek.appendChild(document.createTextNode(' '));
		stitek.appendChild(b);
	});
})();
