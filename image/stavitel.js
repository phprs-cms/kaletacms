/* MiroCMS – stavitel stránek. Bez knihoven a bez build kroku.
 *
 * Stav je strom prvků (stejný tvar, jaký čistí a vykresluje PHP: Stavitel\Stavba). Každá změna jde do historie (zpět/znovu),
 * za chvíli se uloží jako koncept (akce stavba_uloz) a plátno – skutečná stránka webu v iframe – se překreslí.
 * Druhé vykreslování v JavaScriptu záměrně není: co je na plátně, je přesně to, co uvidí návštěvník.
 */
(function () {
	'use strict';

	const T = window.T || ((s) => s);
	const koren = document.getElementById('stavitel');
	const D = JSON.parse(document.getElementById('stavitel-data').textContent);
	const CSRF = (document.querySelector('input[name="_csrf"]') || {}).value || '';
	const TYPY = Object.fromEntries(D.schema.prvky.map((p) => [p.typ, p]));
	const STYL = D.schema.styl;
	const BP = { zaklad: T('Počítač'), tablet: T('Tablet'), mobil: T('Mobil') };
	const IKONY = {
		sekce: '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 9h18M3 15h18"/>',
		kontejner: '<rect x="4" y="4" width="16" height="16" rx="3"/><path d="M8 9h8M8 13h5"/>',
		mrizka: '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
		nadpis: '<path d="M6 4v16M18 4v16M6 12h12"/>',
		text: '<path d="M4 6h16M4 10h16M4 14h16M4 18h10"/>',
		obrazek: '<rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="10" r="2"/><path d="m21 17-5-5-9 7"/>',
		tlacitko: '<rect x="3" y="8" width="18" height="8" rx="4"/><path d="M9 12h6"/>',
		seznam: '<path d="M9 6h11M9 12h11M9 18h11"/><circle cx="4.5" cy="6" r="1"/><circle cx="4.5" cy="12" r="1"/><circle cx="4.5" cy="18" r="1"/>',
		citat: '<path d="M7 7h4v4c0 3-2 5-4 6M15 7h4v4c0 3-2 5-4 6"/>',
		faq: '<circle cx="12" cy="12" r="9"/><path d="M9.5 9.5a2.5 2.5 0 1 1 3.5 2.3c-.6.3-1 .9-1 1.6v.6M12 17h.01"/>',
		video: '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m10 9 5 3-5 3z"/>',
		oddelovac: '<path d="M3 12h18"/>',
		kolekce: '<rect x="3" y="4" width="8" height="7" rx="1.5"/><rect x="13" y="4" width="8" height="7" rx="1.5"/><rect x="3" y="13" width="8" height="7" rx="1.5"/><rect x="13" y="13" width="8" height="7" rx="1.5"/><path d="M5.5 8h3M15.5 8h3M5.5 17h3M15.5 17h3"/>',
		logo: '<circle cx="12" cy="12" r="8"/><path d="M9 15V9l3 3 3-3v6"/>',
		menu: '<path d="M4 7h16M4 12h16M4 17h16"/>',
		komponenta: '<path d="M12 3 4 7.5v9L12 21l8-4.5v-9z"/><path d="M4 7.5 12 12l8-4.5M12 12v9"/>',
		formular: '<rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 8h8M8 12h8"/><rect x="8" y="15" width="5" height="3" rx="1"/>',
		udaje: '<rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="11" r="2"/><path d="M14 10h4M14 14h4M6 16c.8-1.5 1.8-2 3-2s2.2.5 3 2"/>',
		clanek: '<rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 7h8M8 11h8M8 15h5"/>',
		kod: '<path d="m8 8-4 4 4 4M16 8l4 4-4 4M13 6l-2 12"/>',
		blok: '<rect x="4" y="4" width="16" height="16" rx="2"/>',
		nahoru: '<path d="m6 15 6-6 6 6"/>', dolu: '<path d="m6 9 6 6 6-6"/>', rodic: '<path d="M9 14 4 9l5-5M4 9h11a5 5 0 0 1 0 10h-2"/>',
		kopie: '<rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2"/>',
		smazat: '<path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/>',
		zpet: '<path d="M9 14 4 9l5-5M4 9h11a5 5 0 0 1 0 10h-3"/>', vpred: '<path d="m15 14 5-5-5-5M20 9H9a5 5 0 0 0 0 10h3"/>',
		pocitac: '<rect x="3" y="4" width="18" height="12" rx="2"/><path d="M8 20h8M12 16v4"/>', tablet: '<rect x="5" y="3" width="14" height="18" rx="2"/><path d="M11 18h2"/>',
		mobil: '<rect x="7" y="3" width="10" height="18" rx="2"/><path d="M11 18h2"/>', verze: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
		oko: '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>', zavrit: '<path d="M6 6l12 12M18 6 6 18"/>',
	};

	const stav = {
		stavba: D.stavba && Array.isArray(D.stavba.deti) ? D.stavba : { v: 1, deti: [] },
		vybrane: null, bp: 'zaklad', hover: false, levo: 'pridat', pravo: 'obsah', zpet: [], vpred: [], posledniKlic: null, posledniCas: 0,
		zmeny: !!D.zmeny, uklada: false, znovuUlozit: false, casovac: null, chyby: {}, sbalene: {}, trida: null, tazeny: null, tazeno: null, upravaNaPlatne: false,
	};

	/* ---------- drobné pomůcky ---------- */

	function el(tag, atributy, ...deti) {
		const e = document.createElement(tag);
		for (const [k, v] of Object.entries(atributy || {})) {
			if (v === null || v === undefined || v === false) { continue; }
			if (k.startsWith('on')) { e.addEventListener(k.slice(2), v); } else if (k === 'html') { e.innerHTML = v; } else { e.setAttribute(k, v === true ? '' : v); }
		}
		for (const d of deti.flat()) { if (d !== null && d !== undefined && d !== false) { e.append(d instanceof Node ? d : String(d)); } }
		return e;
	}
	const ikona = (nazev) => el('span', { html: '<svg class="st-ikona" viewBox="0 0 24 24" aria-hidden="true">' + (IKONY[nazev] || IKONY.blok) + '</svg>' }).firstChild;
	const klon = (o) => JSON.parse(JSON.stringify(o));
	const noveId = () => Math.random().toString(16).slice(2, 9).padEnd(7, '0');
	const text = (html) => { const d = document.createElement('div'); d.innerHTML = html || ''; return d.textContent.trim(); };

	function dotaz(adresa, data) {
		const f = new FormData();
		f.append('_csrf', CSRF);
		for (const [k, v] of Object.entries(data || {})) { f.append(k, v); }
		return fetch(adresa, { method: data ? 'POST' : 'GET', body: data ? f : undefined, credentials: 'same-origin' })
			.then((r) => r.json().catch(() => ({ ok: false, chyba: T('Server vrátil neočekávanou odpověď.') })));
	}

	function potvrd(zprava, tlacitko) {
		return new Promise((hotovo) => {
			const d = el('dialog', { class: 'st-dialog' },
				el('div', {}, el('p', {}, zprava)),
				el('footer', {}, el('button', { type: 'button', class: 'st-tl', onclick: () => { d.close(); hotovo(false); } }, T('Zrušit')),
					el('button', { type: 'button', class: 'st-tl st-tl-hlavni', onclick: () => { d.close(); hotovo(true); } }, tlacitko || T('Pokračovat'))));
			d.addEventListener('close', () => d.remove());
			document.body.append(d);
			d.showModal();
		});
	}

	/* ---------- strom ---------- */

	function najdi(id, deti = stav.stavba.deti, rodic = null) {
		for (let i = 0; i < deti.length; i++) {
			if (deti[i].id === id) { return { p: deti[i], pole: deti, i, rodic }; }
			if (deti[i].deti) { const n = najdi(id, deti[i].deti, deti[i]); if (n) { return n; } }
		}
		return null;
	}
	function obsahuje(p, id) { return (p.deti || []).some((d) => d.id === id || obsahuje(d, id)); }
	function sNovymiId(p) { const k = klon(p); (function projdi(x) { x.id = noveId(); (x.deti || []).forEach(projdi); })(k); return k; }

	function novyPrvek(typ) {
		const s = TYPY[typ];
		const obsah = {};
		for (const [k, def] of Object.entries(s.vlastnosti || {})) { obsah[k] = klon(def.vychozi ?? ''); }
		const styl = s.vychozi_styl && Object.keys(s.vychozi_styl).length ? klon(s.vychozi_styl) : {};
		// kontejner může mít výchozí vnitřek (Výpis kolekce: vzor karty s {{nazev}} a {{url}})
		return Object.assign({ id: noveId(), typ, znacka: s.znacky[0], obsah, styl }, s.kontejner ? { deti: (s.vychozi_deti || []).map(sNovymiId) } : {});
	}

	function popisek(p) {
		if (p.popis) { return p.popis; }
		const s = TYPY[p.typ] || { nazev: p.typ };
		const ukazka = p.typ === 'nadpis' ? text(p.obsah.text) : p.typ === 'tlacitko' ? p.obsah.text : p.typ === 'text' ? text(p.obsah.html) : p.typ === 'citat' ? p.obsah.autor : '';
		return ukazka ? s.nazev + ': ' + ukazka.slice(0, 40) : s.nazev;
	}

	/* ---------- změny, historie, ukládání ---------- */

	function zmen(fn, klic) {
		const ted = Date.now();
		// psaní do jednoho pole se v historii slučuje (jinak by Zpět vracelo po písmenech)
		if (!klic || klic !== stav.posledniKlic || ted - stav.posledniCas > 1500) {
			stav.zpet.push(JSON.stringify(stav.stavba));
			if (stav.zpet.length > 150) { stav.zpet.shift(); }
			stav.vpred = [];
		}
		stav.posledniKlic = klic || null;
		stav.posledniCas = ted;
		fn();
		stav.zmeny = true;
		naplanujUlozeni();
		if (!klic) { prekresli(); } else { prekresliStrom(); prekresliListu(); }
	}
	function zpet() { if (!stav.zpet.length) { return; } stav.vpred.push(JSON.stringify(stav.stavba)); stav.stavba = JSON.parse(stav.zpet.pop()); stav.posledniKlic = null; stav.zmeny = true; naplanujUlozeni(); prekresli(); }
	function vpred() { if (!stav.vpred.length) { return; } stav.zpet.push(JSON.stringify(stav.stavba)); stav.stavba = JSON.parse(stav.vpred.pop()); stav.posledniKlic = null; stav.zmeny = true; naplanujUlozeni(); prekresli(); }

	function naplanujUlozeni() {
		clearTimeout(stav.casovac);
		nastavStav(T('Neuloženo…'));
		stav.casovac = setTimeout(uloz, 600);
	}
	function uloz() {
		clearTimeout(stav.casovac);
		if (stav.uklada) { stav.znovuUlozit = true; return Promise.resolve(); }
		stav.uklada = true;
		nastavStav(T('Ukládám…'));
		const odeslano = JSON.stringify(stav.stavba);
		return dotaz(D.adresy.uloz, { stavba: odeslano }).then((j) => {
			stav.uklada = false;
			if (!j.ok) { nastavStav(j.chyba || T('Uložení se nepovedlo.'), true); return; }
			stav.chyby = j.chyby || {};
			// server strom vyčistil (neplatné hodnoty zahodil) – převezme se, jen když se mezitím nic dalšího nezměnilo
			if (JSON.stringify(stav.stavba) === odeslano && JSON.stringify(j.stavba) !== odeslano) { stav.stavba = j.stavba; prekresliPanely(); }
			stav.zmeny = j.zmeny;
			const pocet = Object.keys(stav.chyby).length;
			nastavStav(pocet ? T('Uloženo, ale s upozorněními: ') + pocet : T('Koncept uložen'), pocet > 0);
			prekresliListu();
			if (stav.znovuUlozit) { stav.znovuUlozit = false; uloz(); } else { obnovNahled(); }
		}).catch(() => { stav.uklada = false; nastavStav(T('Spojení se serverem selhalo – změny zatím nejsou uložené.'), true); });
	}

	/* ---------- plátno: skutečná stránka v iframe, přepínaná bez blikání ---------- */

	let ramec, nahled, cekaNahled = false, meritko;
	const SIRKY = { zaklad: 1280, tablet: 820, mobil: 390 };

	/** Iframe má šířku zařízení (počítač 1280 px) a zmenší se, aby se vešel – na plátně pak platí skutečné breakpointy webu. */
	function rozmerNahledu(frame) {
		if (!frame || !ramec) { return; }
		const w = ramec.clientWidth;
		const h = ramec.clientHeight;
		const sirka = stav.bp === 'zaklad' ? Math.max(w, SIRKY.zaklad) : SIRKY[stav.bp];
		const m = Math.min(1, w / sirka);
		frame.style.width = sirka + 'px';
		frame.style.height = Math.round(h / m) + 'px';
		frame.style.left = Math.max(0, Math.round((w - sirka * m) / 2)) + 'px';
		frame.style.transform = m < 1 ? 'scale(' + m + ')' : '';
		if (meritko) { meritko.textContent = sirka + ' px' + (m < 1 ? ' · ' + Math.round(m * 100) + ' %' : ''); }
	}
	function obnovNahled() {
		if (stav.upravaNaPlatne) { return; }
		if (cekaNahled) { cekaNahled = 'znovu'; return; }
		cekaNahled = true;
		const novy = el('iframe', { class: 'st-nacita', title: T('Náhled stránky'), src: D.nahled + '&t=' + Date.now() });
		rozmerNahledu(novy);
		novy.addEventListener('load', () => {
			const posun = nahled && nahled.contentWindow ? nahled.contentWindow.scrollY : 0;
			try { novy.contentWindow.scrollTo(0, posun); } catch (e) { /* nic */ }
			pripravNahled(novy);
			if (nahled) { nahled.remove(); }
			novy.classList.remove('st-nacita');
			nahled = novy;
			oznacVNahledu(false);
			const znovu = cekaNahled === 'znovu';
			cekaNahled = false;
			if (znovu) { obnovNahled(); }
		});
		ramec.append(novy);
	}

	function pripravNahled(frame) {
		const doc = frame.contentDocument;
		if (!doc) { return; }
		doc.head.append(Object.assign(doc.createElement('style'), { textContent:
			'[data-mc-id]{cursor:default} .mc-st-hover{outline:1px dashed #2b5be3!important;outline-offset:-1px} .mc-st-vybrany{outline:2px solid #2b5be3!important;outline-offset:-2px}'
			+ '[contenteditable]{outline:2px solid #f79009!important;outline-offset:2px;cursor:text} .mc-upravit-zde,.cookies-lista,.cookies-znovu{display:none!important}' }));
		doc.addEventListener('click', (e) => {
			if (e.target.closest('[contenteditable]') || e.target.id === 'mc-st-uchyt') { return; }
			e.preventDefault();
			const t = e.target.closest('[data-mc-id]');
			vyber(t ? t.getAttribute('data-mc-id') : null);
		}, true);
		doc.addEventListener('mouseover', (e) => {
			doc.querySelectorAll('.mc-st-hover').forEach((x) => x.classList.remove('mc-st-hover'));
			const t = e.target.closest('[data-mc-id]');
			if (t) { t.classList.add('mc-st-hover'); }
		});
		doc.addEventListener('dblclick', (e) => { const t = e.target.closest('[data-mc-id]'); if (t) { upravNaPlatne(t); } });
		doc.addEventListener('keydown', klavesy);
		doc.addEventListener('dragover', (e) => {
			if (!stav.tazeno) { return; }
			const misto = mistoNaPlatne(doc, e);
			ukazMisto(doc, misto);
			if (misto) { e.preventDefault(); e.dataTransfer.dropEffect = stav.tazeno.presun ? 'move' : 'copy'; }
		});
		doc.addEventListener('dragleave', (e) => { if (!e.relatedTarget) { ukazMisto(doc, null); } });
		doc.addEventListener('drop', (e) => {
			const misto = stav.tazeno && mistoNaPlatne(doc, e);
			ukazMisto(doc, null);
			if (!misto) { return; }
			e.preventDefault();
			pustNaMisto(stav.tazeno, misto);
			stav.tazeno = null;
		});
	}

	/* ---------- přetahování na plátně: nový prvek, hotová sekce nebo přesun vybraného prvku ---------- */

	function zacniTahnout(e, co) {
		schovejNahledSekce();
		stav.tazeno = co;
		e.dataTransfer.effectAllowed = co.presun ? 'move' : 'copy';
		e.dataTransfer.setData('text/plain', 'mirocms');
	}

	function skonciTazeni() {
		stav.tazeno = null;
		const doc = nahled && nahled.contentDocument;
		if (doc) { ukazMisto(doc, null); }
	}

	/**
	 * Kam by prvek dopadl: {cil: id, kam: 'pred' | 'za' | 'dovnitr'}, nebo {koren: true} na prázdné stránce. Sekce jen mezi sekce;
	 * do kontejneru dovnitř, když je ukazatel v jeho prostřední části (nebo je prázdný); přesun nikdy do sebe sama.
	 */
	function mistoNaPlatne(doc, e) {
		const typ = stav.tazeno.novy || (stav.tazeno.sekce ? 'sekce' : (najdi(stav.tazeno.presun) || { p: {} }).p.typ);
		let uzel = e.target.closest ? e.target.closest('[data-mc-id]') : null;
		while (uzel && !najdi(uzel.getAttribute('data-mc-id'))) { uzel = uzel.parentElement && uzel.parentElement.closest('[data-mc-id]'); }
		if (!uzel) { return stav.stavba.deti.length ? null : { koren: true }; }
		let n = najdi(uzel.getAttribute('data-mc-id'));
		if (typ === 'sekce' || typ === 'obsah') {
			while (n.rodic) { n = najdi(n.rodic.id); }
			uzel = doc.querySelector('[data-mc-id="' + n.p.id + '"]') || uzel;
		}
		const presouvany = stav.tazeno.presun && najdi(stav.tazeno.presun);
		if (presouvany && (presouvany.p.id === n.p.id || obsahuje(presouvany.p, n.p.id))) { return null; }
		const r = uzel.getBoundingClientRect();
		const y = (e.clientY - r.top) / Math.max(1, r.height);
		const dovnitr = typ !== 'sekce' && typ !== 'obsah' && TYPY[n.p.typ] && TYPY[n.p.typ].kontejner && (!n.p.deti.length || (y > 0.25 && y < 0.75));
		return { cil: n.p.id, kam: dovnitr ? 'dovnitr' : (y < 0.5 ? 'pred' : 'za'), uzel };
	}

	/** Modrá čára (před / za) nebo rámeček (dovnitř) na plátně. */
	function ukazMisto(doc, misto) {
		let znacka = doc.getElementById('mc-st-misto');
		if (!misto || !misto.uzel) { if (znacka) { znacka.hidden = true; } return; }
		if (!znacka) {
			znacka = Object.assign(doc.createElement('div'), { id: 'mc-st-misto' });
			znacka.style.cssText = 'position:absolute;z-index:2147483646;pointer-events:none;border-radius:3px';
			doc.body.append(znacka);
		}
		const r = misto.uzel.getBoundingClientRect();
		const x = r.left + doc.defaultView.scrollX;
		const y = r.top + doc.defaultView.scrollY;
		znacka.hidden = false;
		Object.assign(znacka.style, misto.kam === 'dovnitr'
			? { left: x + 'px', top: y + 'px', width: r.width + 'px', height: r.height + 'px', background: 'rgb(43 91 227 / 0.08)', outline: '2px dashed #2b5be3' }
			: { left: x + 'px', top: (misto.kam === 'pred' ? y - 2 : y + r.height - 2) + 'px', width: r.width + 'px', height: '4px', background: '#2b5be3', outline: 'none' });
	}

	function pustNaMisto(co, misto) {
		if (co.presun) {
			const n = najdi(co.presun);
			const cil = !misto.koren && najdi(misto.cil);
			if (!n || !cil) { return; }
			if (misto.kam !== 'dovnitr' && !cil.rodic && n.p.typ !== 'sekce' && n.p.typ !== 'obsah') {
				// prvek přesunutý mezi sekce dostane vlastní sekci
				zmen(() => {
					n.pole.splice(n.i, 1);
					const c = najdi(misto.cil);
					c.pole.splice(c.i + (misto.kam === 'za' ? 1 : 0), 0, Object.assign(novyPrvek('sekce'), { deti: [n.p] }));
				});
			} else {
				presun(co.presun, misto.cil, misto.kam);
			}
			vyber(co.presun);
			prekresliPanely();
			return;
		}
		const vlozit = (prvek) => {
			// samotný prvek mezi sekcemi dostane vlastní sekci (jako při vložení klepnutím)
			const cil = misto.koren ? null : najdi(misto.cil);
			const mezeSekce = misto.koren || (misto.kam !== 'dovnitr' && !cil.rodic);
			const vkladany = mezeSekce && prvek.typ !== 'sekce' && prvek.typ !== 'obsah' ? Object.assign(novyPrvek('sekce'), { deti: [prvek] }) : prvek;
			zmen(() => {
				if (misto.koren) { stav.stavba.deti.push(vkladany); return; }
				const c = najdi(misto.cil);
				if (misto.kam === 'dovnitr') { c.p.deti.push(vkladany); } else { c.pole.splice(c.i + (misto.kam === 'za' ? 1 : 0), 0, vkladany); }
			});
			vyber(prvek.id);
			prekresliPanely();
		};
		if (co.novy) { vlozit(novyPrvek(co.novy)); return; }
		dotaz(D.adresy.sekce + '&klic=' + encodeURIComponent(co.sekce), { ok: 1 }).then((j) => {
			if (!j.ok) { nastavStav(j.chyba, true); return; }
			D.tridy = j.tridy;
			vlozit(j.prvek);
		});
	}

	function oznacVNahledu(posunout) {
		const doc = nahled && nahled.contentDocument;
		if (!doc) { return; }
		doc.querySelectorAll('.mc-st-vybrany').forEach((x) => x.classList.remove('mc-st-vybrany'));
		const t = stav.vybrane && doc.querySelector('[data-mc-id="' + stav.vybrane + '"]');
		let uchyt = doc.getElementById('mc-st-uchyt');
		if (t) {
			t.classList.add('mc-st-vybrany');
			if (posunout) { t.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); }
			// úchyt vlevo nahoře: přetažením se vybraný prvek přesune jinam na stránce
			if (!uchyt) {
				uchyt = Object.assign(doc.createElement('div'), { id: 'mc-st-uchyt', draggable: true, title: T('Přetažením přesunete') });
				uchyt.textContent = '⠿';
				uchyt.style.cssText = 'position:absolute;z-index:2147483647;display:grid;place-items:center;width:22px;height:22px;border-radius:4px;background:#2b5be3;color:#fff;font:14px/1 system-ui;cursor:grab;user-select:none';
				uchyt.addEventListener('dragstart', (e) => zacniTahnout(e, { presun: stav.vybrane }));
				uchyt.addEventListener('dragend', skonciTazeni);
				uchyt.addEventListener('click', (e) => e.stopPropagation(), true);
				doc.body.append(uchyt);
			}
			const r = t.getBoundingClientRect();
			uchyt.hidden = false;
			uchyt.style.left = Math.max(0, r.left + doc.defaultView.scrollX) + 'px';
			uchyt.style.top = Math.max(0, r.top + doc.defaultView.scrollY - 24) + 'px';
		} else if (uchyt) {
			uchyt.hidden = true;
		}
	}

	/** Dvojklik na nadpis, text, tlačítko nebo referenci: psaní přímo na plátně. */
	function upravNaPlatne(uzel) {
		const n = najdi(uzel.getAttribute('data-mc-id'));
		if (!n || !['nadpis', 'text', 'tlacitko', 'citat'].includes(n.p.typ)) { return; }
		// v kolekci je na plátně dosazená hodnota položky – úprava by přepsala {{značku}}; text se mění v panelu Obsah
		if (kolekcePrvku(n.p.id) && JSON.stringify(n.p.obsah).includes('{{')) { vyber(n.p.id); nastavStav(T('Text s {{značkami}} kolekce upravte v panelu Obsah.')); return; }
		const cil = n.p.typ === 'citat' ? uzel.querySelector('p') : uzel;
		if (!cil) { return; }
		stav.upravaNaPlatne = true;
		cil.contentEditable = n.p.typ === 'tlacitko' ? 'plaintext-only' : 'true';
		cil.focus();
		const hotovo = () => {
			cil.removeEventListener('blur', hotovo);
			cil.removeAttribute('contenteditable');
			stav.upravaNaPlatne = false;
			const pole = { nadpis: 'text', text: 'html', tlacitko: 'text', citat: 'text' }[n.p.typ];
			const hodnota = n.p.typ === 'tlacitko' ? cil.textContent.trim() : cil.innerHTML.trim();
			if (hodnota !== n.p.obsah[pole]) { zmen(() => { n.p.obsah[pole] = hodnota; }); } else { obnovNahled(); }
		};
		cil.addEventListener('blur', hotovo);
		cil.addEventListener('keydown', (e) => { if (e.key === 'Escape' || (e.key === 'Enter' && n.p.typ !== 'text' && !e.shiftKey)) { e.preventDefault(); cil.blur(); } });
	}

	/* ---------- výběr a úpravy stromu ---------- */

	function vyber(id) {
		stav.vybrane = id && najdi(id) ? id : null;
		stav.trida = null;
		stav.posledniKlic = null;
		prekresliPanely();
		oznacVNahledu(true);
	}

	function vloz(prvek) {
		const v = stav.vybrane && najdi(stav.vybrane);
		zmen(() => {
			if (!v && prvek.typ !== 'sekce' && prvek.typ !== 'obsah') {
				// na nejvyšší úrovni jsou sekce: samotný prvek dostane vlastní sekci (obsah stránky v obálce má vlastní obal)
				const sekce = novyPrvek('sekce');
				sekce.deti.push(prvek);
				stav.stavba.deti.push(sekce);
			} else if (v && TYPY[v.p.typ].kontejner && prvek.typ !== 'sekce') {
				v.p.deti.push(prvek);
			} else if (v) {
				if (prvek.typ === 'sekce' || prvek.typ === 'obsah') {
					// sekce patří na nejvyšší úroveň – za sekci, ve které je vybraný prvek
					let horni = v; while (horni.rodic) { horni = najdi(horni.rodic.id); }
					horni.pole.splice(horni.i + 1, 0, prvek);
				} else { v.pole.splice(v.i + 1, 0, prvek); }
			} else { stav.stavba.deti.push(prvek); }
			stav.vybrane = prvek.id;
		});
		stav.pravo = 'obsah';
		prekresliPanely();
	}

	function smaz(id) {
		const n = najdi(id);
		if (!n) { return; }
		zmen(() => { n.pole.splice(n.i, 1); stav.vybrane = n.rodic ? n.rodic.id : (n.pole[n.i] || n.pole[n.i - 1] || {}).id || null; });
	}
	function duplikuj(id) {
		const n = najdi(id);
		if (!n) { return; }
		const kopie = sNovymiId(n.p);
		zmen(() => { n.pole.splice(n.i + 1, 0, kopie); stav.vybrane = kopie.id; });
	}
	function posun(id, smer) {
		const n = najdi(id);
		if (!n || n.i + smer < 0 || n.i + smer >= n.pole.length) { return; }
		zmen(() => { n.pole.splice(n.i, 1); n.pole.splice(n.i + smer, 0, n.p); });
	}
	function presun(id, cilId, kam) {
		const n = najdi(id);
		const c = najdi(cilId);
		if (!n || !c || id === cilId || obsahuje(n.p, cilId)) { return; }
		zmen(() => {
			n.pole.splice(n.i, 1);
			const cil = najdi(cilId);
			if (kam === 'dovnitr') { cil.p.deti.push(n.p); } else { cil.pole.splice(cil.i + (kam === 'za' ? 1 : 0), 0, n.p); }
		});
	}

	/* ---------- schránka (i mezi stránkami) ---------- */

	function kopiruj() { const n = stav.vybrane && najdi(stav.vybrane); if (n) { try { localStorage.setItem('mc-stavitel-schranka', JSON.stringify(n.p)); nastavStav(T('Zkopírováno')); } catch (e) { /* nic */ } } }
	function vlozZeSchranky() {
		let p = null;
		try { p = JSON.parse(localStorage.getItem('mc-stavitel-schranka') || 'null'); } catch (e) { p = null; }
		if (p && TYPY[p.typ]) { vloz(sNovymiId(p)); }
	}

	/* ---------- klávesy ---------- */

	function klavesy(e) {
		const v = e.target;
		const pise = v.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(v.tagName);
		const mod = e.ctrlKey || e.metaKey;
		if (mod && e.key.toLowerCase() === 's') { e.preventDefault(); uloz(); return; }
		if (pise) { return; }
		if (mod && e.key.toLowerCase() === 'z') { e.preventDefault(); if (e.shiftKey) { vpred(); } else { zpet(); } }
		else if (mod && e.key.toLowerCase() === 'y') { e.preventDefault(); vpred(); }
		else if (mod && e.key.toLowerCase() === 'd' && stav.vybrane) { e.preventDefault(); duplikuj(stav.vybrane); }
		else if (mod && e.key.toLowerCase() === 'c' && stav.vybrane) { kopiruj(); }
		else if (mod && e.key.toLowerCase() === 'v') { vlozZeSchranky(); }
		else if ((e.key === 'Delete' || e.key === 'Backspace') && stav.vybrane) { e.preventDefault(); smaz(stav.vybrane); }
		else if (e.key === 'Escape' && stav.vybrane) { const n = najdi(stav.vybrane); vyber(n && n.rodic ? n.rodic.id : null); }
	}

	/* ---------- horní lišta ---------- */

	let lista, stavText;
	function nastavStav(zprava, chyba) { if (stavText) { stavText.textContent = zprava; stavText.classList.toggle('chyba', !!chyba); } }

	function vytvorListu() {
		stavText = el('span', { class: 'st-stav', role: 'status' }, stav.zmeny ? T('Rozpracovaný koncept') : T('Publikováno'));
		lista = el('header', { class: 'st-lista' });
		koren.append(lista);
		prekresliListu();
	}
	function prekresliListu() {
		const bpTl = Object.entries({ zaklad: 'pocitac', tablet: 'tablet', mobil: 'mobil' }).map(([bp, ik]) =>
			el('button', { type: 'button', title: BP[bp], 'aria-label': BP[bp], 'aria-pressed': String(stav.bp === bp), onclick: () => { stav.bp = bp; ramec.dataset.bp = bp; rozmerNahledu(nahled); prekresliListu(); prekresliPanely(); } }, ikona(ik)));
		lista.replaceChildren(...[
			el('a', { class: 'st-tl', href: D.zpet.adresa, title: D.zpet.text }, ikona('rodic'), el('span', { class: 'st-text' }, D.zpet.text)),
			el('div', { class: 'st-nazev' }, el('strong', {}, D.stranka.titulek), el('small', {}, stav.zmeny ? T('rozpracovaný koncept – návštěvníci vidí publikovanou verzi') : T('beze změn proti webu'))),
			el('div', { class: 'st-skupina', role: 'group', 'aria-label': T('Zařízení') }, bpTl),
			el('div', { class: 'st-skupina', role: 'group', 'aria-label': T('Historie') },
				el('button', { type: 'button', title: T('Zpět (Ctrl+Z)'), disabled: !stav.zpet.length, onclick: zpet }, ikona('zpet')),
				el('button', { type: 'button', title: T('Znovu (Ctrl+Shift+Z)'), disabled: !stav.vpred.length, onclick: vpred }, ikona('vpred'))),
			stavText,
			el('button', { type: 'button', class: 'st-tl', title: T('Publikované verze'), onclick: dialogVerze }, ikona('verze'), el('span', { class: 'st-text' }, T('Verze'))),
			el('a', { class: 'st-tl', href: D.stranka.adresa, target: '_blank', rel: 'noopener', title: T('Otevřít publikovanou stránku') }, ikona('oko')),
			D.stranka.publikovana && stav.zmeny ? el('button', { type: 'button', class: 'st-tl', onclick: zahod }, T('Zahodit změny')) : null,
			el('button', { type: 'button', class: 'st-tl st-tl-hlavni', disabled: !stav.zmeny && D.stranka.publikovana, onclick: publikuj }, T('Publikovat')),
		].filter(Boolean));
	}

	function publikuj() {
		uloz().then(() => dotaz(D.adresy.publikuj, { ok: 1 })).then((j) => {
			if (!j || !j.ok) { nastavStav((j && j.chyba) || T('Publikování se nepovedlo.'), true); return; }
			stav.zmeny = false;
			D.stranka.publikovana = true;
			nastavStav(D.stranka.zobrazena ? T('Publikováno – změny jsou na webu') : T('Publikováno (stránka je zatím skrytá – zveřejníte ji v nastavení stránky)'));
			prekresliListu();
		});
	}
	function zahod() {
		potvrd(T('Zahodit všechny změny od posledního publikování? Nejde to vrátit.'), T('Zahodit')).then((ano) => {
			if (!ano) { return; }
			// naplánované uložení by koncept po zahození znovu vytvořilo; běžící se nechá doběhnout
			clearTimeout(stav.casovac);
			stav.znovuUlozit = false;
			const pockej = () => new Promise((hotovo) => { const cekej = () => (stav.uklada ? setTimeout(cekej, 100) : hotovo()); cekej(); });
			pockej().then(() => dotaz(D.adresy.zahod, { ok: 1 })).then((j) => {
				if (!j.ok) { nastavStav(j.chyba, true); return; }
				stav.stavba = j.stavba; stav.zpet = []; stav.vpred = []; stav.zmeny = false; stav.vybrane = null;
				nastavStav(T('Změny zahozeny')); prekresli(); obnovNahled();
			});
		});
	}
	function dialogVerze() {
		dotaz(D.adresy.revize).then((j) => {
			const seznam = el('ul');
			(j.revize || []).forEach((r) => seznam.append(el('li', {}, el('span', {}, new Date(r.datum.replace(' ', 'T')).toLocaleString(document.documentElement.lang), r.kdo ? ' · ' + r.kdo : ''),
				el('button', { type: 'button', class: 'st-tl', onclick: () => dotaz(D.adresy.obnov, { idr: r.idr }).then((o) => {
					d.close();
					if (!o.ok) { nastavStav(o.chyba, true); return; }
					stav.zpet.push(JSON.stringify(stav.stavba)); stav.stavba = o.stavba; stav.zmeny = true; stav.vybrane = null;
					nastavStav(T('Starší verze je v konceptu – publikujte ji, až bude hotová')); prekresli(); obnovNahled();
				}) }, T('Načíst do konceptu')))));
			const d = el('dialog', { class: 'st-dialog' }, el('div', {}, el('h2', {}, T('Publikované verze')), j.revize && j.revize.length ? seznam : el('p', { class: 'st-prazdno' }, T('Zatím žádné starší verze.'))),
				el('footer', {}, el('button', { type: 'button', class: 'st-tl', onclick: () => d.close() }, T('Zavřít'))));
			d.addEventListener('close', () => d.remove());
			document.body.append(d);
			d.showModal();
		});
	}

	/* ---------- levý panel: Přidat a Struktura ---------- */

	let levy, levyObsah, pravy;
	function prekresliLevy() {
		const zal = (klic, nazev) => el('button', { type: 'button', role: 'tab', 'aria-selected': String(stav.levo === klic), onclick: () => { stav.levo = klic; prekresliLevy(); } }, nazev);
		levyObsah = el('div', { class: 'st-panel' });
		levy.replaceChildren(el('div', { class: 'st-zalozky', role: 'tablist' }, zal('pridat', T('Přidat')), zal('struktura', T('Struktura'))), levyObsah);
		if (stav.levo === 'pridat') { panelPridat(); } else { prekresliStrom(); }
	}

	/** AI: nová sekce podle popisu – vloží se za vybranou sekci (nebo na konec) jako běžná změna, jde vrátit. */
	function sekceSAi() {
		const pole = el('textarea', { rows: 5, placeholder: T('Např.: Tři karty s našimi službami – kuchyně, skříně, schodiště. Ke každé krátký popis a odkaz na kontakt.') });
		const d = el('dialog', { class: 'st-dialog' }, el('div', {}, el('h2', {}, T('Vytvořit sekci s AI')),
			el('label', { class: 'st-pole' }, el('span', {}, T('Co má sekce obsahovat?')), pole),
			el('p', { class: 'st-prazdno', style: 'text-align:left;padding:0' }, T('Asistent navrhne texty i rozložení ve stylu vašeho webu. Výsledek zkontrolujte – fakta (čísla, ceny, jména) doplňte sami.'))),
		el('footer', {}, el('button', { type: 'button', class: 'st-tl', onclick: () => d.close() }, T('Zrušit')),
			el('button', { type: 'button', class: 'st-tl st-tl-hlavni', onclick: () => {
				const zadani = pole.value.trim();
				if (!zadani) { pole.focus(); return; }
				d.close();
				nastavStav(T('Asistent navrhuje sekci…'));
				dotaz(D.adresy.aiSekce, { zadani }).then((j) => {
					if (!j.ok) { nastavStav(j.chyba || T('Asistent neodpověděl.'), true); return; }
					D.tridy = j.tridy;
					const v = stav.vybrane && najdi(stav.vybrane);
					let horni = v; while (horni && horni.rodic) { horni = najdi(horni.rodic.id); }
					zmen(() => { stav.stavba.deti.splice(horni ? horni.i + 1 : stav.stavba.deti.length, 0, ...j.prvky); });
					vyber(j.prvky[0].id);
					prekresliPanely();
					nastavStav(j.hlaseni && j.hlaseni.length ? T('Sekce vložena. Upozornění: ') + j.hlaseni.join(' ') : T('Sekce vložena – zkontrolujte texty.'));
				});
			} }, T('Vytvořit'))));
		d.addEventListener('close', () => d.remove());
		document.body.append(d);
		d.showModal();
		pole.focus();
	}

	/** AI: přepis textu prvku (kratší, delší…) – výsledek je běžná změna, Zpět ji vrátí. */
	function prepisySAi(p) {
		const klic = { nadpis: 'text', text: 'html', tlacitko: 'text', citat: 'text' }[p.typ];
		if (!D.ai || !klic || !(p.obsah[klic] || '').trim() || String(p.obsah[klic]).includes('{{')) { return null; }
		const pokyny = [['kratsi', T('kratší')], ['delsi', T('delší')], ['formalne', T('formálněji')], ['pratelsky', T('přátelštěji')], ['oprava', T('opravit chyby')]];
		return el('div', { class: 'st-ai' }, el('span', {}, '✨ ' + T('Přepsat s AI:')), el('div', {}, pokyny.map(([pokyn, nazev]) => el('button', { type: 'button', onclick: (e) => {
			e.target.disabled = true;
			nastavStav(T('Asistent přepisuje text…'));
			dotaz(D.adresy.aiText, { text: p.obsah[klic], pokyn, html: klic === 'html' ? '1' : '0' }).then((j) => {
				e.target.disabled = false;
				if (!j.ok) { nastavStav(j.chyba || T('Asistent neodpověděl.'), true); return; }
				zmen(() => { p.obsah[klic] = j.text; });
				prekresliPravy();
				nastavStav(T('Text přepsán – Ctrl+Z ho vrátí.'));
			});
		} }, nazev))));
	}

	function panelPridat() {
		if (D.ai) { levyObsah.append(el('button', { type: 'button', class: 'st-tl st-ai-sekce', onclick: sekceSAi }, '✨ ' + T('Vytvořit sekci s AI'))); }
		const skupiny = {};
		D.schema.prvky.forEach((p) => { (skupiny[p.skupina] = skupiny[p.skupina] || []).push(p); });
		for (const [nazev, prvky] of Object.entries(skupiny)) {
			levyObsah.append(el('h3', {}, T(nazev)), el('div', { class: 'st-prvky' }, prvky.map((p) =>
				el('button', { type: 'button', title: p.popis, draggable: 'true', onclick: () => vloz(novyPrvek(p.typ)),
					ondragstart: (e) => zacniTahnout(e, { novy: p.typ }), ondragend: skonciTazeni }, ikona(p.ikona), p.nazev))));
		}
		// hotové sekce po kategoriích; hledání filtruje podle názvu i popisu
		const knihovna = el('div', {});
		const vykresliKnihovnu = (hledat) => {
			const q = hledat.trim().toLowerCase();
			knihovna.replaceChildren(...Object.entries(D.kategorieKnihovny || { obsah: '' }).map(([kat, nazevKat]) => {
				const sekce = D.knihovna.filter((s) => (s.kategorie || 'obsah') === kat && (!q || (s.nazev + ' ' + s.popis).toLowerCase().includes(q)));
				return sekce.length ? el('div', {}, el('h3', {}, nazevKat), el('div', { class: 'st-knihovna' }, sekce.map(tlacitkoSekce))) : null;
			}).filter(Boolean));
		};
		levyObsah.append(el('h3', {}, T('Hotové sekce')), el('input', { type: 'search', class: 'st-hledat', placeholder: T('Hledat sekci…'), oninput: (e) => vykresliKnihovnu(e.target.value) }), knihovna);
		vykresliKnihovnu('');
	}

	/** Živý náhled hotové sekce vedle panelu (vykreslí ji server ve vzhledu webu, zmenšenou). */
	let nahledSekce = null;
	let casovacNahleduSekce = null;
	function ukazNahledSekce(tlacitko, klic) {
		clearTimeout(casovacNahleduSekce);
		casovacNahleduSekce = setTimeout(() => {
			if (!nahledSekce) {
				nahledSekce = el('div', { class: 'st-nahled-sekce', 'aria-hidden': 'true' }, el('iframe', { tabindex: '-1', title: '' }));
				document.body.append(nahledSekce);
			}
			const r = tlacitko.getBoundingClientRect();
			const iframe = nahledSekce.firstChild;
			if (iframe.dataset.klic !== klic) { iframe.dataset.klic = klic; iframe.src = D.adresy.nahledSekce + encodeURIComponent(klic); }
			nahledSekce.style.left = Math.max(8, Math.min(r.right + 12, window.innerWidth - 392)) + 'px';
			nahledSekce.style.top = Math.max(8, Math.min(window.innerHeight - 280, r.top - 40)) + 'px';
			nahledSekce.hidden = false;
		}, 250);
	}
	function schovejNahledSekce() {
		clearTimeout(casovacNahleduSekce);
		if (nahledSekce) { nahledSekce.hidden = true; }
	}

	function tlacitkoSekce(s) {
		return (
			el('button', { onmouseenter: (e) => ukazNahledSekce(e.currentTarget, s.klic), onmouseleave: schovejNahledSekce, onfocus: (e) => ukazNahledSekce(e.currentTarget, s.klic), onblur: schovejNahledSekce, type: 'button', draggable: 'true', ondragstart: (e) => zacniTahnout(e, { sekce: s.klic }), ondragend: skonciTazeni, onclick: () => dotaz(D.adresy.sekce + '&klic=' + encodeURIComponent(s.klic), { ok: 1 }).then((j) => {
				if (!j.ok) { nastavStav(j.chyba, true); return; }
				D.tridy = j.tridy;
				vloz(j.prvek);
			}) }, el('strong', {}, s.nazev), el('small', {}, s.popis)));
	}

	function prekresliStrom() {
		if (stav.levo !== 'struktura' || !levyObsah) { return; }
		const uzel = (p) => {
			const s = TYPY[p.typ] || { nazev: p.typ, ikona: 'blok' };
			const maDeti = p.deti && p.deti.length;
			const radek = el('div', {
				class: 'st-uzel', draggable: 'true', 'aria-selected': String(stav.vybrane === p.id), tabindex: '0',
				onclick: () => vyber(p.id), onkeydown: (e) => { if (e.key === 'Enter') { vyber(p.id); } },
				onmouseenter: () => { const t = nahled && nahled.contentDocument && nahled.contentDocument.querySelector('[data-mc-id="' + p.id + '"]'); if (t) { t.classList.add('mc-st-hover'); } },
				onmouseleave: () => { const t = nahled && nahled.contentDocument && nahled.contentDocument.querySelector('[data-mc-id="' + p.id + '"]'); if (t) { t.classList.remove('mc-st-hover'); } },
				ondragstart: (e) => { stav.tazeny = p.id; e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', p.id); },
				ondragover: (e) => {
					if (!stav.tazeny || stav.tazeny === p.id) { return; }
					e.preventDefault();
					const r = radek.getBoundingClientRect();
					const y = (e.clientY - r.top) / r.height;
					const kam = s.kontejner && y > 0.25 && y < 0.75 ? 'dovnitr' : (y < 0.5 ? 'pred' : 'za');
					radek.classList.remove('cil-pred', 'cil-za', 'cil-dovnitr');
					radek.classList.add('cil-' + kam);
					radek.dataset.kam = kam;
				},
				ondragleave: () => radek.classList.remove('cil-pred', 'cil-za', 'cil-dovnitr'),
				ondrop: (e) => { e.preventDefault(); radek.classList.remove('cil-pred', 'cil-za', 'cil-dovnitr'); if (stav.tazeny) { presun(stav.tazeny, p.id, radek.dataset.kam || 'za'); } stav.tazeny = null; },
				ondragend: () => { stav.tazeny = null; },
			},
			maDeti ? el('button', { type: 'button', class: 'st-sbalit', 'aria-label': T('Sbalit / rozbalit'), onclick: (e) => { e.stopPropagation(); stav.sbalene[p.id] = !stav.sbalene[p.id]; prekresliStrom(); } }, stav.sbalene[p.id] ? '▸' : '▾') : el('span', { style: 'width:16px;flex:none' }),
			ikona(s.ikona), el('span', {}, popisek(p)), el('small', {}, p.znacka));
			return el('li', {}, radek, maDeti && !stav.sbalene[p.id] ? el('ul', {}, p.deti.map(uzel)) : null);
		};
		levyObsah.replaceChildren(stav.stavba.deti.length
			? el('ul', { class: 'st-strom', role: 'tree' }, stav.stavba.deti.map(uzel))
			: el('p', { class: 'st-prazdno' }, T('Stránka je prázdná. Přidejte sekci z panelu Přidat.')));
	}

	/* ---------- pravý panel: vlastnosti vybraného prvku, nebo úprava třídy ---------- */

	function prekresliPanely() { prekresliStrom(); prekresliPravy(); }
	function prekresli() { prekresliListu(); prekresliLevy(); prekresliPravy(); }

	function prekresliPravy() {
		if (stav.trida !== null) { panelTridy(); return; }
		const n = stav.vybrane && najdi(stav.vybrane);
		if (!n) {
			pravy.replaceChildren(el('div', { class: 'st-panel' }, el('p', { class: 'st-prazdno' }, T('Vyberte prvek na plátně nebo ve struktuře. Dvojklikem na text ho upravíte přímo na stránce.')),
				D.adresy.nastaveni ? el('p', { class: 'st-prazdno' }, el('a', { href: D.adresy.nastaveni }, T('Nastavení stránky (název, adresa, SEO)'))) : null));
			return;
		}
		const p = n.p;
		const s = TYPY[p.typ];
		const zal = (klic, nazev) => el('button', { type: 'button', role: 'tab', 'aria-selected': String(stav.pravo === klic), onclick: () => { stav.pravo = klic; prekresliPravy(); } }, nazev);
		const panel = el('div', { class: 'st-panel' });
		const chyby = Object.entries(stav.chyby);
		if (chyby.length) { panel.append(el('ul', { class: 'st-chyby' }, chyby.slice(0, 6).map(([, t]) => el('li', {}, t)))); }
		pravy.replaceChildren(
			el('div', { class: 'st-hlava-prvku' }, ikona(s.ikona), el('strong', {}, s.nazev), el('div', { class: 'st-akce' },
				el('button', { type: 'button', title: T('Nahoru'), onclick: () => posun(p.id, -1) }, ikona('nahoru')),
				el('button', { type: 'button', title: T('Dolů'), onclick: () => posun(p.id, 1) }, ikona('dolu')),
				n.rodic ? el('button', { type: 'button', title: T('Vybrat nadřazený prvek (Esc)'), onclick: () => vyber(n.rodic.id) }, ikona('rodic')) : null,
				el('button', { type: 'button', title: T('Duplikovat (Ctrl+D)'), onclick: () => duplikuj(p.id) }, ikona('kopie')),
				D.adresy.komponenta && p.typ !== 'komponenta' ? el('button', { type: 'button', title: T('Uložit jako komponentu'), onclick: () => ulozJakoKomponentu(p.id) }, ikona('komponenta')) : null,
				el('button', { type: 'button', class: 'nebezpecne', title: T('Smazat (Delete)'), onclick: () => smaz(p.id) }, ikona('smazat')))),
			el('div', { class: 'st-zalozky', role: 'tablist' }, zal('obsah', T('Obsah')), zal('styl', T('Styl')), zal('pokrocile', T('Pokročilé'))),
			panel,
		);
		if (stav.pravo === 'obsah') { panelObsah(panel, p, s); } else if (stav.pravo === 'styl') { panelStyl(panel, p, 'prvek:' + p.id); } else { panelPokrocile(panel, p, s); }
	}

	/** Kolekce, jejíž položky prvek dostává: nejbližší nadřazený Výpis kolekce, jinak kolekce šablony detailu. */
	function kolekcePrvku(id) {
		let n = najdi(id);
		while (n) {
			if (n.p.typ === 'kolekce' && n.p.id !== id) { return (D.kolekce || []).find((k) => k.seo_link === n.p.obsah.kolekce) || null; }
			n = n.rodic ? najdi(n.rodic.id) : null;
		}
		return D.kolekceDetailu || null;
	}

	/** Nápověda zástupných značek {{pole}} – klepnutím se značka zkopíruje. */
	function napovedaZnacek(kolekce) {
		const znacky = (kolekce.vestavene === false ? [] : [['nazev', T('Název')], ['url', T('Adresa detailu')], ['datum', T('Datum')]]).concat(kolekce.pole.map((p) => [p.klic, p.popisek]));
		if (!znacky.length) { return null; }
		return el('div', { class: 'st-znacky' }, el('span', {}, (kolekce.vestavene === false ? T('Vlastnosti komponenty „%s“ – vložte do textu, obrázku nebo odkazu:') : T('Pole kolekce „%s“ – vložte do textu, obrázku nebo odkazu:')).replace('%s', kolekce.nazev)),
			el('div', {}, znacky.map(([klic, nazev]) => el('button', { type: 'button', title: nazev, onclick: (e) => {
				const znacka = '{{' + klic + '}}';
				if (navigator.clipboard) { navigator.clipboard.writeText(znacka); }
				e.target.textContent = T('zkopírováno');
				setTimeout(() => { e.target.textContent = znacka; }, 1200);
			} }, '{{' + klic + '}}'))));
	}

	/** Vybraný prvek se uloží jako komponenta (správce) a na jeho místě zůstane její použití. */
	function ulozJakoKomponentu(id) {
		const n = najdi(id);
		const nazev = n && window.prompt(T('Název komponenty (např. Karta služby):'), popisek(n.p));
		if (!nazev) { return; }
		dotaz(D.adresy.komponenta, { nazev, prvek: JSON.stringify(n.p) }).then((j) => {
			if (!j.ok) { nastavStav(j.chyba || T('Uložení se nepovedlo.'), true); return; }
			D.komponenty = j.komponenty;
			const moznosti = { '': '—' };
			j.komponenty.forEach((k) => { moznosti[String(k.id)] = k.nazev; });
			if (TYPY.komponenta) { TYPY.komponenta.vlastnosti.komponenta.moznosti = moznosti; }
			const pouziti = { id: noveId(), typ: 'komponenta', znacka: 'div', obsah: { komponenta: String(j.id), hodnoty: {} }, styl: {} };
			zmen(() => { n.pole.splice(n.i, 1, pouziti); stav.vybrane = pouziti.id; });
			prekresliPanely();
			nastavStav(T('Komponenta uložena – úpravy v Komponentách se projeví všude, kde je použitá.'));
		});
	}

	/** Hodnoty vlastností u použití komponenty: pole podle vybrané komponenty, prázdné = výchozí hodnota. */
	function poleHodnot(p) {
		const komponenta = (D.komponenty || []).find((k) => String(k.id) === String(p.obsah.komponenta));
		if (!komponenta) { return null; }
		const obal = el('div', { class: 'st-pole' }, el('span', {}, T('Vlastnosti')));
		if (!komponenta.vlastnosti.length) {
			obal.append(el('p', { class: 'st-prazdno' }, T('Komponenta nemá vlastnosti – u všech použití vypadá stejně.')));
			return obal;
		}
		if (!p.obsah.hodnoty || Array.isArray(p.obsah.hodnoty)) { p.obsah.hodnoty = {}; }
		komponenta.vlastnosti.forEach((v) => {
			const def = { typ: v.typ === 'radky' || v.typ === 'html' ? 'radky' : (v.typ === 'obrazek' ? 'obrazek' : 'text'), popisek: v.popisek + ' {{' + v.klic + '}}' };
			const vstup = pole(def, p.obsah.hodnoty[v.klic] || '', (h) => zmen(() => { p.obsah.hodnoty[v.klic] = h; }, 'hodnoty:' + p.id + ':' + v.klic));
			const input = vstup.querySelector('input, textarea');
			if (input && v.vychozi) { input.placeholder = v.vychozi; }
			obal.append(vstup);
		});
		return obal;
	}

	function panelObsah(panel, p, s) {
		if (p.typ === 'komponenta') {
			panel.append(pole(s.vlastnosti.komponenta, p.obsah.komponenta, (h) => { zmen(() => { p.obsah.komponenta = h; p.obsah.hodnoty = {}; }); prekresliPravy(); }));
			const hodnoty = poleHodnot(p);
			if (hodnoty) { panel.append(hodnoty); }
			return;
		}
		const kolekce = p.typ !== 'kolekce' ? kolekcePrvku(p.id) : null;
		const napoveda = kolekce ? napovedaZnacek(kolekce) : null;
		if (napoveda) { panel.append(napoveda); }
		const ai = prepisySAi(p);
		if (ai) { panel.append(ai); }
		const vlastnosti = Object.entries(s.vlastnosti || {});
		if (!vlastnosti.length) { panel.append(el('p', { class: 'st-prazdno' }, s.kontejner ? T('Kontejner nemá vlastní obsah – vložte do něj prvky, vzhled nastavíte v záložce Styl.') : T('Prvek nemá nastavitelný obsah.'))); return; }
		vlastnosti.forEach(([klic, def]) => panel.append(pole(def, p.obsah[klic], (h) => zmen(() => { p.obsah[klic] = h; }, 'obsah:' + p.id + ':' + klic))));
	}

	/** Ovládací prvek pro pole obsahu podle typu ze schématu. */
	function pole(def, hodnota, zmena) {
		const popis = T(def.popisek || '');
		const obal = el('label', { class: 'st-pole' }, el('span', {}, popis));
		let vstup;
		switch (def.typ) {
			case 'prepinac':
				return el('label', { class: 'st-zaskrt' }, el('input', { type: 'checkbox', checked: !!hodnota, onchange: (e) => zmena(e.target.checked) }), popis);
			case 'vyber':
				vstup = el('select', { onchange: (e) => zmena(e.target.value) }, Object.entries(def.moznosti).map(([k, v]) => el('option', { value: k, selected: k === String(hodnota) }, T(v))));
				break;
			case 'cislo':
				vstup = el('input', { type: 'number', min: def.min ?? 0, max: def.max ?? 100, value: hodnota, oninput: (e) => zmena(parseInt(e.target.value, 10) || 0) });
				break;
			case 'radky': case 'kod':
				vstup = el('textarea', { rows: def.typ === 'kod' ? 8 : 5, oninput: (e) => zmena(e.target.value) });
				vstup.value = hodnota || '';
				break;
			case 'html': {
				const ta = el('textarea', { 'data-editor': 'maly', oninput: (e) => zmena(e.target.value) });
				ta.value = hodnota || '';
				obal.append(ta);
				if (window.mirocmsVytvorEditor) { setTimeout(() => window.mirocmsVytvorEditor(ta), 0); }
				return obal;
			}
			case 'obrazek': {
				const nahledObr = el('img', { class: 'st-obrazek-nahled', alt: '', src: hodnota || null, hidden: !hodnota });
				vstup = el('input', { type: 'text', value: hodnota || '', placeholder: 'media/…', oninput: (e) => { zmena(e.target.value); nahledObr.src = e.target.value; nahledObr.hidden = !e.target.value; } });
				const tl = el('button', { type: 'button', class: 'st-tl', onclick: () => window.mirocmsVyberObrazek && window.mirocmsVyberObrazek((o) => { vstup.value = o.url; nahledObr.src = o.url; nahledObr.hidden = false; zmena(o.url); }) }, T('Média'));
				obal.append(el('span', { class: 'st-pole-radek' }, vstup, tl), nahledObr);
				return obal;
			}
			case 'polozky':
				return polePolozky(def, Array.isArray(hodnota) ? hodnota : [], zmena);
			default:
				vstup = el('input', { type: 'text', value: hodnota ?? '', placeholder: def.typ === 'odkaz' ? 'https://…, /kontakt, #kotva, mailto:, tel:' : null, oninput: (e) => zmena(e.target.value) });
		}
		obal.append(vstup);
		return obal;
	}

	function polePolozky(def, polozky, zmena) {
		const obal = el('div', { class: 'st-pole' }, el('span', {}, T(def.popisek)));
		const uloz = () => zmena(klon(polozky));
		polozky.forEach((polozka, i) => {
			const box = el('div', { class: 'st-polozka' });
			// pole s podmínkou „kdyz“ ({pole: hodnota}) se ukáže, jen když má jiné pole položky danou hodnotu (možnosti jen u výběru)
			const viditelne = (d) => !d.kdyz || Object.entries(d.kdyz).every(([pk, pv]) => polozka[pk] === pv);
			Object.entries(def.pole).forEach(([k, d]) => {
				if (!viditelne(d)) { return; }
				box.append(pole(d, polozka[k], (h) => {
					polozka[k] = h;
					uloz();
					if (Object.values(def.pole).some((jine) => jine.kdyz && k in jine.kdyz)) { prekresliPravy(); }
				}));
			});
			box.append(el('div', { class: 'st-polozka-akce st-akce' },
				el('button', { type: 'button', title: T('Nahoru'), onclick: () => { if (i > 0) { polozky.splice(i - 1, 0, polozky.splice(i, 1)[0]); zmena(klon(polozky)); prekresliPravy(); } } }, ikona('nahoru')),
				el('button', { type: 'button', class: 'nebezpecne', title: T('Odebrat'), onclick: () => { polozky.splice(i, 1); zmena(klon(polozky)); prekresliPravy(); } }, ikona('smazat'))));
			obal.append(box);
		});
		obal.append(el('button', { type: 'button', class: 'st-tl', onclick: () => {
			const nova = {};
			Object.entries(def.pole).forEach(([k, d]) => { nova[k] = d.vychozi ?? ''; });
			polozky.push(nova);
			zmena(klon(polozky));
			prekresliPravy();
		} }, T('Přidat položku')));
		return obal;
	}

	/* ---------- styl (prvku i třídy) ---------- */

	const NAPOVEDY = {
		mezera: ['2xs', 'xs', 's', 'm', 'l', 'xl', '2xl', '3xl', '0'], krok: ['-1', '0', '1', '2', '3', '4', '5'], zaobleni: ['0', 's', 'm', 'l', 'plne'], stin: ['s', 'm', 'l', 'none'],
		barva: Object.keys(D.schema.tokeny.barvy).concat(['transparent']), delka: ['auto', '100%', '50%', 'var(--mc-sirka-textu)', 'var(--mc-sirka)', '20rem', '30rem', '60vh', 'fit-content'],
		sloupce: ['1', '2', '3', '4', 'auto:14rem', 'auto:16rem', 'auto:20rem', '2fr 1fr', '1fr 2fr'], cislo: ['-1', '0', '1', '2'],
	};
	const datalisty = el('div', { hidden: true }, Object.entries(NAPOVEDY).map(([typ, hodnoty]) => el('datalist', { id: 'st-dl-' + typ }, hodnoty.map((h) => el('option', { value: h })))));

	/** Barva tokenu přibližně (pro vzorek v panelu) – odvozené odstíny se míchají stejně jako na webu. */
	function barvaTokenu(h) {
		const b = D.barvy;
		const mapa = { primarni: b.primarni, sekundarni: b.sekundarni, text: b.text, pozadi: b.pozadi, plocha: b.plocha, bila: '#fff', cerna: '#000',
			'primarni-jemna': 'color-mix(in oklch, ' + b.primarni + ' 12%, ' + b.pozadi + ')', tlumeny: 'color-mix(in oklch, ' + b.text + ' 64%, ' + b.pozadi + ')',
			linka: 'color-mix(in oklch, ' + b.text + ' 14%, ' + b.pozadi + ')', 'na-primarni': '#fff' };
		return mapa[h] || h;
	}

	function aktualniStav() { return stav.hover ? 'hover' : stav.bp; }
	function zdedena(styl, klic) {
		const s = aktualniStav();
		const poradi = s === 'mobil' ? ['tablet', 'zaklad'] : s === 'tablet' || s === 'hover' ? ['zaklad'] : [];
		for (const st of poradi) { if (styl[st] && styl[st][klic] !== undefined) { return styl[st][klic]; } }
		return '';
	}

	/** Panel stylu: $cil je prvek ({styl}) nebo záznam třídy; změna jde přes zmen (prvek) nebo ulozTridu (třída). */
	function panelStyl(panel, cil, klicZmen, ulozit) {
		cil.styl = cil.styl && !Array.isArray(cil.styl) ? cil.styl : {};
		const s = aktualniStav();
		panel.append(el('div', { class: 'st-stav-stylu' },
			el('span', {}, T('Upravujete: '), el('strong', {}, stav.hover ? T('při najetí myší') : BP[stav.bp])),
			el('button', { type: 'button', class: 'st-tl', 'aria-pressed': String(stav.hover), onclick: () => { stav.hover = !stav.hover; prekresliPravy(); } }, stav.hover ? T('Běžný stav') : T('Najetí myší'))));
		if (s !== 'zaklad') { panel.append(el('p', { class: 'napoveda', style: 'margin:0 0 8px;font-size:12px;color:var(--text-slaby)' }, T('Prázdné pole = stejná hodnota jako na větší obrazovce (šedě).'))); }
		const skupiny = {};
		Object.entries(STYL).forEach(([klic, def]) => { (skupiny[def.skupina] = skupiny[def.skupina] || []).push([klic, def]); });
		const box = el('div', { class: 'st-styl' });
		Object.entries(skupiny).forEach(([skupina, vlastnosti], poradi) => {
			const nastaveno = vlastnosti.filter(([k]) => cil.styl[s] && cil.styl[s][k] !== undefined).length;
			const det = el('details', { open: nastaveno > 0 || poradi === 0 }, el('summary', {}, T(D.schema.skupiny_stylu[skupina]), nastaveno ? el('small', {}, nastaveno) : null));
			const obsah = el('div');
			vlastnosti.forEach(([klic, def]) => obsah.append(ovladacStylu(cil, klic, def, (hodnota) => {
				const provest = () => {
					cil.styl[s] = cil.styl[s] || {};
					if (hodnota === '') { delete cil.styl[s][klic]; if (!Object.keys(cil.styl[s]).length) { delete cil.styl[s]; } } else { cil.styl[s][klic] = hodnota; }
				};
				if (ulozit) { provest(); ulozit(); } else { zmen(provest, klicZmen + ':' + s + ':' + klic); }
			})));
			det.append(obsah);
			box.append(det);
		});
		panel.append(box);
	}

	function ovladacStylu(cil, klic, def, zmena) {
		const s = aktualniStav();
		const hodnota = (cil.styl[s] || {})[klic] ?? '';
		const zdedeno = zdedena(cil.styl, klic);
		let vstup;
		if (def.typ === 'vyber') {
			vstup = el('select', { onchange: (e) => zmena(e.target.value) }, el('option', { value: '' }, zdedeno ? '↳ ' + T(def.moznosti[zdedeno] || zdedeno) : '—'),
				Object.entries(def.moznosti).map(([k, v]) => el('option', { value: k, selected: k === hodnota }, T(v))));
		} else {
			const vzorek = def.typ === 'barva' ? el('span', { class: 'st-vzorek', style: 'background:' + barvaTokenu(hodnota || zdedeno || 'transparent') }) : null;
			const pole = el('input', { type: 'text', value: hodnota, placeholder: zdedeno, list: NAPOVEDY[def.typ] ? 'st-dl-' + def.typ : null,
				onchange: (e) => zmena(e.target.value.trim()), oninput: (e) => { if (vzorek) { vzorek.style.background = barvaTokenu(e.target.value || zdedeno || 'transparent'); } } });
			vstup = el('span', { class: 'st-pole-radek' }, vzorek, pole,
				def.typ === 'obrazek' ? el('button', { type: 'button', class: 'st-tl', title: T('Média'), onclick: () => window.mirocmsVyberObrazek && window.mirocmsVyberObrazek((o) => { pole.value = o.url; zmena(o.url); }) }, '…') : null);
		}
		const id = 'st-v-' + klic;
		vstup.id = id;
		return el('div', { class: 'st-vlastnost' + (hodnota !== '' ? ' nastaveno' : '') }, el('label', { for: id, title: def.css }, T(def.popisek)), vstup);
	}

	/* ---------- pokročilé: značka, třídy, kotva ---------- */

	function panelPokrocile(panel, p, s) {
		if (s.znacky.length > 1) {
			panel.append(pole({ typ: 'vyber', popisek: 'HTML značka', moznosti: Object.fromEntries(s.znacky.map((z) => [z, '<' + z + '>'])) }, p.znacka, (h) => zmen(() => { p.znacka = h; })));
		}
		panel.append(
			pole({ typ: 'text', popisek: 'Název ve struktuře' }, p.popis || '', (h) => zmen(() => { if (h) { p.popis = h; } else { delete p.popis; } }, 'popis:' + p.id)),
			pole({ typ: 'text', popisek: 'Kotva (id pro odkaz #…)' }, p.kotva || '', (h) => zmen(() => { if (h) { p.kotva = h; } else { delete p.kotva; } }, 'kotva:' + p.id)),
		);
		const tridy = p.tridy || [];
		const novaTrida = el('input', { type: 'text', list: 'st-dl-tridy', placeholder: T('např. karta'), onkeydown: (e) => { if (e.key === 'Enter') { e.preventDefault(); pridejTridu(); } } });
		const pridejTridu = () => {
			const nazev = novaTrida.value.trim().toLowerCase();
			if (!/^[a-z][a-z0-9-]{0,40}(__[a-z0-9-]{1,30})?(--[a-z0-9-]{1,30})?$/.test(nazev) || tridy.includes(nazev)) { return; }
			zmen(() => { p.tridy = tridy.concat([nazev]); });
		};
		panel.append(el('div', { class: 'st-pole' }, el('span', {}, T('Třídy')),
			el('div', { class: 'st-tridy' }, tridy.map((t) => el('span', { class: 'st-trida' },
				el('a', { href: '#', title: T('Upravit třídu'), onclick: (e) => { e.preventDefault(); stav.trida = t; prekresliPravy(); } }, '.' + t),
				el('button', { type: 'button', title: T('Odebrat třídu z prvku'), onclick: () => zmen(() => { p.tridy = tridy.filter((x) => x !== t); if (!p.tridy.length) { delete p.tridy; } }) }, '×')))),
			el('span', { class: 'st-pole-radek' }, novaTrida, el('button', { type: 'button', class: 'st-tl', onclick: pridejTridu }, T('Přidat'))),
			el('datalist', { id: 'st-dl-tridy' }, Object.keys(D.tridy).map((t) => el('option', { value: t }))),
			el('small', { style: 'color:var(--text-slaby)' }, T('Třída sdílí vzhled mezi prvky na všech stránkách. Klepnutím na třídu ji upravíte.'))));
		panel.append(el('h3', {}, T('Viditelnost')),
			el('label', { class: 'st-zaskrt' }, el('input', { type: 'checkbox', checked: ((p.styl || {}).mobil || {}).zobrazeni === 'none', onchange: (e) => zmen(() => {
				p.styl = p.styl || {};
				if (e.target.checked) { p.styl.mobil = Object.assign(p.styl.mobil || {}, { zobrazeni: 'none' }); } else if (p.styl.mobil) { delete p.styl.mobil.zobrazeni; }
			}) }), T('Skrýt na mobilu')),
			el('label', { class: 'st-zaskrt' }, el('input', { type: 'checkbox', checked: ((p.styl || {}).tablet || {}).zobrazeni === 'none', onchange: (e) => zmen(() => {
				p.styl = p.styl || {};
				if (e.target.checked) { p.styl.tablet = Object.assign(p.styl.tablet || {}, { zobrazeni: 'none' }); } else if (p.styl.tablet) { delete p.styl.tablet.zobrazeni; }
			}) }), T('Skrýt na tabletu i mobilu')));
	}

	/* ---------- úprava sdílené třídy ---------- */

	let casovacTridy = null;
	function panelTridy() {
		const nazev = stav.trida;
		const zaznam = D.tridy[nazev] || (D.tridy[nazev] = { styl: {}, css: '' });
		if (Array.isArray(zaznam.styl)) { zaznam.styl = {}; }
		const ulozTridu = () => {
			nastavStav(T('Neuloženo…'));
			clearTimeout(casovacTridy);
			casovacTridy = setTimeout(() => dotaz(D.adresy.trida, { nazev, styl: JSON.stringify(zaznam.styl), css: zaznam.css }).then((j) => {
				if (!j.ok) { nastavStav(j.chyba, true); return; }
				D.tridy = j.tridy;
				nastavStav(j.chyby ? T('Třída uložena s upozorněním') : T('Třída uložena – platí na všech stránkách'), !!j.chyby);
				obnovNahled();
			}), 500);
		};
		const panel = el('div', { class: 'st-panel' });
		pravy.replaceChildren(el('div', { class: 'st-hlava-prvku' }, el('strong', {}, T('Třída') + ' .' + nazev),
			el('div', { class: 'st-akce' }, el('button', { type: 'button', title: T('Zpět na prvek'), onclick: () => { stav.trida = null; prekresliPravy(); } }, ikona('zavrit')))), panel);
		panel.append(el('p', { style: 'margin:0 0 10px;font-size:12px;color:var(--text-slaby)' }, T('Změny třídy se projeví u všech prvků s touto třídou na celém webu – hned po uložení, bez publikování.')));
		panelStyl(panel, zaznam, 'trida:' + nazev, ulozTridu);
		const css = el('textarea', { rows: 5, placeholder: 'transition: transform .2s;', oninput: (e) => { zaznam.css = e.target.value; ulozTridu(); } });
		css.value = zaznam.css || '';
		panel.append(el('h3', {}, T('Vlastní CSS')), el('label', { class: 'st-pole' }, el('span', {}, T('Deklarace navíc (vlastnost: hodnota;)')), css),
			el('button', { type: 'button', class: 'st-tl', onclick: () => potvrd(T('Smazat třídu .') + nazev + T('? Prvky ji ve struktuře ponechají, ale přestane mít vzhled.'), T('Smazat')).then((ano) => {
				if (!ano) { return; }
				dotaz(D.adresy.trida, { nazev, smazat: '1' }).then((j) => { D.tridy = j.tridy || D.tridy; stav.trida = null; prekresliPravy(); obnovNahled(); });
			}) }, T('Smazat třídu')));
	}

	/* ---------- start ---------- */

	vytvorListu();
	levy = el('aside', { class: 'st-levy', 'aria-label': T('Prvky a struktura') });
	pravy = el('aside', { class: 'st-pravy', 'aria-label': T('Vlastnosti') });
	ramec = el('div', { class: 'st-ramec', 'data-bp': 'zaklad' });
	meritko = el('span', { class: 'st-meritko', 'aria-hidden': 'true' });
	koren.append(levy, el('main', { class: 'st-platno' }, ramec, meritko), pravy, datalisty);
	new ResizeObserver(() => rozmerNahledu(nahled)).observe(ramec);
	koren.hidden = false;
	prekresli();
	obnovNahled();
	document.addEventListener('keydown', klavesy);
	window.addEventListener('beforeunload', (e) => { if (stav.casovac && !stav.uklada) { uloz(); } if (stav.uklada || cekaNahled === 'ulozit') { e.preventDefault(); e.returnValue = ''; } });
})();
