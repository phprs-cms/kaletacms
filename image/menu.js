/* Kaleta – editor menu (Vzhled → Menu). Položky: stránka, vlastní odkaz, novinky, skupina; pod položkou jedna úroveň podmenu.
 * Pořadí přetažením nebo šipkami (i z klávesnice), šipka vpravo zařadí položku do podmenu té nad ní.
 * Stav je pole položek; při odeslání formuláře jde jako JSON do skrytého pole – čistí ho server (Core\Menu::vycisti).
 */
// skript je v obsahu stránky, tedy před admin.js se slovníkem překladů (window.T) – začne až po načtení všech skriptů
document.addEventListener('DOMContentLoaded', function () {
	'use strict';

	const T = window.T || ((s) => s);
	const formular = document.querySelector('[data-menu]');
	if (!formular) { return; }
	const data = JSON.parse(formular.querySelector('[data-menu-data]').textContent);
	const seznam = formular.querySelector('[data-menu-seznam]');
	const prazdne = formular.querySelector('[data-menu-prazdne]');
	const stranky = Object.fromEntries(data.stranky.map((s) => [s.ids, s]));
	const polozky = data.polozky.map((p) => Object.assign({ deti: [] }, p, { deti: (p.deti || []).map((d) => Object.assign({}, d)) }));
	const NAZVY = { stranka: T('Stránka'), odkaz: T('Odkaz'), novinky: T('Novinky'), skupina: T('Skupina') };
	let tazena = null;

	function el(tag, atributy, ...deti) {
		const e = document.createElement(tag);
		for (const [k, v] of Object.entries(atributy || {})) {
			if (v === null || v === undefined || v === false) { continue; }
			if (k.startsWith('on')) { e.addEventListener(k.slice(2), v); } else { e.setAttribute(k, v === true ? '' : v); }
		}
		deti.flat().forEach((d) => { if (d !== null && d !== undefined && d !== false) { e.append(d instanceof Node ? d : String(d)); } });
		return e;
	}

	/** Pole, ve kterém položka leží, a její index: cesta [i] = hlavní úroveň, [i, j] = podmenu položky i. */
	const pole = (cesta) => (cesta.length === 1 ? polozky : polozky[cesta[0]].deti);
	const polozka = (cesta) => pole(cesta)[cesta[cesta.length - 1]];

	function presun(cesta, smer) {
		const p = pole(cesta);
		const i = cesta[cesta.length - 1];
		const j = i + smer;
		if (j < 0 || j >= p.length) { return; }
		[p[i], p[j]] = [p[j], p[i]];
		vykresli([...cesta.slice(0, -1), j]);
	}
	function odsad(cesta) {
		const i = cesta[0];
		if (cesta.length > 1 || i === 0 || polozky[i].deti.length) { return; }
		const [p] = polozky.splice(i, 1);
		polozky[i - 1].deti.push(p);
		vykresli([i - 1, polozky[i - 1].deti.length - 1]);
	}
	function vysad(cesta) {
		if (cesta.length < 2) { return; }
		const [p] = polozky[cesta[0]].deti.splice(cesta[1], 1);
		p.deti = [];
		polozky.splice(cesta[0] + 1, 0, p);
		vykresli([cesta[0] + 1]);
	}
	function smaz(cesta) {
		const [p] = pole(cesta).splice(cesta[cesta.length - 1], 1);
		// podmenu smazané položky se posune o úroveň výš, nezmizí
		if (cesta.length === 1 && p.deti.length) { polozky.splice(cesta[0], 0, ...p.deti.map((d) => Object.assign(d, { deti: [] }))); }
		vykresli(null);
	}

	function radek(p, cesta) {
		const nazevStranky = p.typ === 'stranka' ? (stranky[p.ids] || { titulek: T('smazaná stránka') }).titulek : '';
		const tl = (text, popis, fn, zakazano) => el('button', { type: 'button', class: 'menu-tl', title: popis, 'aria-label': popis, disabled: zakazano, onclick: () => fn(cesta) }, text);
		const text = el('input', { class: 'textpole', type: 'text', maxlength: 80, value: p.text || '', 'aria-label': T('Text v menu'),
			placeholder: p.typ === 'stranka' ? nazevStranky : p.typ === 'novinky' ? T('Novinky') : T('Text v menu'),
			oninput: (e) => { p.text = e.target.value; } });
		const i = cesta[cesta.length - 1];
		const radekEl = el('div', { class: 'menu-radek' },
			el('span', { class: 'menu-uchyt', draggable: 'true', 'aria-hidden': 'true', title: T('Přetažením změníte pořadí') }, '⠿'),
			el('span', { class: 'stitek' }, NAZVY[p.typ]),
			p.typ === 'stranka' && stranky[p.ids] && stranky[p.ids].skryta ? el('span', { class: 'stitek stitek-koncept', title: T('Skrytá stránka se v menu na webu neukáže.') }, T('skrytá')) : null,
			text,
			p.typ === 'odkaz' ? el('input', { class: 'textpole', type: 'text', maxlength: 500, value: p.url || '', placeholder: 'https://… ' + T('nebo') + ' /cesta', 'aria-label': T('Adresa odkazu'), oninput: (e) => { p.url = e.target.value.trim(); } }) : null,
			p.typ === 'odkaz' ? el('label', { class: 'menu-okno' }, el('input', { type: 'checkbox', checked: !!p.nove_okno, onchange: (e) => { p.nove_okno = e.target.checked; } }), ' ' + T('nové okno')) : null,
			el('span', { class: 'menu-akce' },
				tl('↑', T('Posunout výš'), (c) => presun(c, -1), i === 0),
				tl('↓', T('Posunout níž'), (c) => presun(c, 1), i === pole(cesta).length - 1),
				cesta.length === 1 ? tl('→', T('Do podmenu položky nad ní'), odsad, i === 0 || p.deti.length > 0) : tl('←', T('Z podmenu o úroveň výš'), vysad),
				tl('✕', T('Odebrat z menu'), smaz)));
		const li = el('li', { class: 'menu-polozka' }, radekEl);
		li.dataset.cesta = cesta.join(',');
		// táhne se za úchyt (celá položka by bránila označování textu v polích)
		const uchyt = radekEl.querySelector('.menu-uchyt');
		uchyt.addEventListener('dragstart', (e) => { tazena = cesta; e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', ''); e.dataTransfer.setDragImage(radekEl, 10, 10); li.classList.add('menu-tazena'); });
		uchyt.addEventListener('dragend', () => { tazena = null; li.classList.remove('menu-tazena'); seznam.querySelectorAll('.menu-cil').forEach((x) => x.classList.remove('menu-cil')); });
		radekEl.addEventListener('dragover', (e) => {
			if (!tazena || (tazena.length === 1 && polozka(tazena).deti.length && cesta.length > 1)) { return; } // položka s podmenu nejde do podmenu
			e.preventDefault();
			radekEl.classList.add('menu-cil');
		});
		radekEl.addEventListener('dragleave', () => radekEl.classList.remove('menu-cil'));
		radekEl.addEventListener('drop', (e) => {
			e.preventDefault();
			if (!tazena || tazena.join() === cesta.join()) { return; }
			// vloží se před cílovou položku, na její úroveň
			const cil = polozka(cesta);
			const [p2] = pole(tazena).splice(tazena[tazena.length - 1], 1);
			if (cesta.length > 1) { p2.deti = []; }
			const kam = cesta.length === 1 ? polozky : polozky.find((x) => x.deti.includes(cil)).deti;
			kam.splice(kam.indexOf(cil), 0, p2);
			tazena = null;
			vykresli(null);
		});
		if (cesta.length === 1 && p.deti.length) {
			li.append(el('ol', { class: 'menu-podmenu' }, p.deti.map((d, j) => radek(d, [cesta[0], j]))));
		}
		return li;
	}

	function vykresli(fokus) {
		seznam.replaceChildren(...polozky.map((p, i) => radek(p, [i])));
		prazdne.hidden = polozky.length > 0;
		if (fokus) {
			// po přesunu šipkou zůstane fokus na přesunuté položce (ovládání z klávesnice)
			const li = seznam.querySelector('[data-cesta="' + fokus.join(',') + '"]');
			if (li) { li.querySelector('.menu-radek input').focus(); }
		}
	}

	formular.querySelectorAll('[data-menu-pridej]').forEach((b) => b.addEventListener('click', () => {
		const typ = b.dataset.menuPridej;
		const nova = { typ, text: '', deti: [] };
		if (typ === 'stranka') {
			const vyber = formular.querySelector('[data-menu-stranka]');
			if (!vyber.value) { return; }
			nova.ids = Number(vyber.value);
		}
		if (typ === 'odkaz') { nova.url = ''; nova.nove_okno = false; }
		polozky.push(nova);
		vykresli([polozky.length - 1]);
	}));

	/** Upozornění vlastním dialogem (ne window.alert – ten nejde nastylovat ani přeložit); po zavření se vrátí fokus na chybné pole. */
	function upozorni(text, pole) {
		const d = el('dialog', { class: 'potvrzeni', role: 'alertdialog', 'aria-modal': 'true' },
			el('p', {}, text),
			el('div', {}, el('button', { type: 'button', class: 'tl', onclick: () => d.close() }, T('Rozumím'))));
		d.setAttribute('aria-label', text);
		d.addEventListener('close', () => { d.remove(); pole.focus(); });
		document.body.append(d);
		d.showModal();
	}

	formular.addEventListener('submit', (e) => {
		// odkaz bez adresy nebo textu a skupina bez textu by server zahodil potichu – raději říct hned
		const chybna = seznam.querySelectorAll('.menu-polozka');
		for (const li of chybna) {
			const p = polozka(li.dataset.cesta.split(',').map(Number));
			if ((p.typ === 'odkaz' && (!p.text || !p.url)) || (p.typ === 'skupina' && !p.text)) {
				e.preventDefault();
				// chybí text, nebo (u odkazu) adresa: fokus na to pole, které je prázdné
				const prazdne = [...li.querySelector('.menu-radek').querySelectorAll('input.textpole')].find((x) => !x.value.trim()) || li.querySelector('input');
				prazdne.setAttribute('aria-invalid', 'true');
				prazdne.addEventListener('input', () => prazdne.removeAttribute('aria-invalid'), { once: true });
				upozorni(p.typ === 'odkaz' ? T('Vlastní odkaz potřebuje text i adresu.') : T('Skupina potřebuje text.'), prazdne);
				return;
			}
		}
		formular.querySelector('input[name="polozky"]').value = JSON.stringify(polozky);
	});

	vykresli(null);
});
