/* Kaleta – menu editor ("Vzhled → Menu", Appearance → Menu). Items: page, custom link, news, group; one submenu level under an item,
 * and inside a submenu a group may have items of its own (a column of the mega menu). Each item can have an icon and a short description.
 * Order by dragging or with arrows (keyboard too); the right arrow moves an item into the submenu of the item above it (inside a submenu
 * only into a group above it).
 * The state is an array of items; on form submit it goes as JSON into a hidden field – the server cleans it (Core\Menu::sanitize).
 */
// the script is in the page content, i.e. before admin.js with the translation dictionary (window.T) – it starts only after all scripts load
document.addEventListener('DOMContentLoaded', function () {
	'use strict';

	const T = window.T || ((s) => s);
	const formEl = document.querySelector('[data-menu]');
	if (!formEl) { return; }
	const data = JSON.parse(formEl.querySelector('[data-menu-data]').textContent);
	const list = formEl.querySelector('[data-menu-seznam]');
	const empty = formEl.querySelector('[data-menu-prazdne]');
	const pages = Object.fromEntries(data.stranky.map((s) => [s.ids, s]));
	const icons = data.ikony || { '': '' };
	const normalize = (p) => Object.assign({ deti: [] }, p, { deti: (p.deti || []).map(normalize) });
	const items = data.polozky.map(normalize);
	const NAMES = { stranka: T('Page'), odkaz: T('Link'), novinky: T('Novinky'), skupina: T('Group') };
	const MAX_DEPTH = 2; // top level, submenu, items of a group inside the submenu
	// the installation folder (/web of example.com/web/admin.php): a custom link is stored without it – the site adds it when
	// it draws the menu (Core\Menu::items) – but the link picker offers the site's addresses with it (3.25, N325-2)
	const adminUrl = (document.querySelector('script[data-admin-url]') || { getAttribute: () => '' }).getAttribute('data-admin-url') || '';
	const BASE = adminUrl.replace(/\/admin\.php(?:[?#].*)?$/, '');
	const withoutBase = (url) => (BASE !== '' && url.startsWith(BASE + '/') ? url.slice(BASE.length) : url);
	let dragged = null;

	function el(tag, attributes, ...children) {
		const e = document.createElement(tag);
		for (const [k, v] of Object.entries(attributes || {})) {
			if (v === null || v === undefined || v === false) { continue; }
			if (k.startsWith('on')) { e.addEventListener(k.slice(2), v); } else { e.setAttribute(k, v === true ? '' : v); }
		}
		children.flat().forEach((d) => { if (d !== null && d !== undefined && d !== false) { e.append(d instanceof Node ? d : String(d)); } });
		return e;
	}

	/** The array the item lies in, and its index: path [i] = top level, [i, j] = submenu of item i, [i, j, k] = items of group j. */
	const field = (path) => path.slice(0, -1).reduce((p, i) => p[i].deti, items);
	const item = (path) => field(path)[path[path.length - 1]];
	const depthOf = (path) => path.length - 1;
	/** Whether the item can move into the item above it: at the top level under anything, inside a submenu only under a group. */
	const canIndent = (p, path) => {
		const i = path[path.length - 1];
		if (i === 0 || p.deti.length || depthOf(path) >= MAX_DEPTH) { return false; }
		return depthOf(path) === 0 || field(path)[i - 1].typ === 'skupina';
	};

	function move(path, direction) {
		const p = field(path);
		const i = path[path.length - 1];
		const j = i + direction;
		if (j < 0 || j >= p.length) { return; }
		[p[i], p[j]] = [p[j], p[i]];
		render([...path.slice(0, -1), j]);
	}
	function indent(path) {
		const i = path[path.length - 1];
		const arr = field(path);
		if (!canIndent(arr[i], path)) { return; }
		const [p] = arr.splice(i, 1);
		arr[i - 1].deti.push(p);
		render([...path.slice(0, -1), i - 1, arr[i - 1].deti.length - 1]);
	}
	function outdent(path) {
		if (path.length < 2) { return; }
		const parentPath = path.slice(0, -1);
		const [p] = field(path).splice(path[path.length - 1], 1);
		const target = field(parentPath);
		target.splice(parentPath[parentPath.length - 1] + 1, 0, p);
		render([...parentPath.slice(0, -1), parentPath[parentPath.length - 1] + 1]);
	}
	function remove(path) {
		const arr = field(path);
		const i = path[path.length - 1];
		const [p] = arr.splice(i, 1);
		// the submenu of a removed item moves one level up, it does not disappear
		arr.splice(i, 0, ...p.deti);
		render(null);
	}

	function row(p, path) {
		const depth = depthOf(path);
		const pageName = p.typ === 'stranka' ? (pages[p.ids] || { titulek: T('deleted page') }).titulek : '';
		const tl = (text, description, fn, disabled) => el('button', { type: 'button', class: 'menu-tl', title: description, 'aria-label': description, disabled: disabled, onclick: () => fn(path) }, text);
		const text = el('input', { class: 'textpole', type: 'text', maxlength: 80, value: p.text || '', 'aria-label': T('Menu text'),
			placeholder: p.typ === 'stranka' ? pageName : p.typ === 'novinky' ? T('Novinky') : T('Menu text'),
			oninput: (e) => { p.text = e.target.value; } });
		const icon = el('select', { class: 'menu-ikona-vyber', 'aria-label': T('Icon'), title: T('Icon'), onchange: (e) => { p.ikona = e.target.value; } },
			Object.entries(icons).map(([k, v]) => el('option', { value: k, selected: k === (p.ikona || '') }, v)));
		const description = el('input', { class: 'textpole menu-popis-pole', type: 'text', maxlength: 120, value: p.popis || '', placeholder: T('Description (mega menu)'),
			'aria-label': T('Description (mega menu)'), oninput: (e) => { p.popis = e.target.value; } });
		const i = path[path.length - 1];
		const rowEl = el('div', { class: 'menu-radek' },
			el('span', { class: 'menu-uchyt', draggable: 'true', 'aria-hidden': 'true', title: T('Drag to reorder') }, '⠿'),
			el('span', { class: 'stitek' }, NAMES[p.typ]),
			p.typ === 'stranka' && pages[p.ids] && pages[p.ids].skryta ? el('span', { class: 'stitek stitek-koncept', title: T('A hidden page does not appear in the menu on the site.') }, T('hidden')) : null,
			icon,
			text,
			p.typ === 'odkaz' ? el('input', { class: 'textpole', type: 'text', maxlength: 500, value: p.url || '', placeholder: T('site page, https://…, #anchor, mailto:, tel:'), 'aria-label': T('Link address'),
				'data-odkaz-vyber': '', oninput: (e) => { p.url = withoutBase(e.target.value.trim()); } }) : null, // 3.25 (N-U9): the link picker of image/editor.js
			p.typ === 'odkaz' ? el('label', { class: 'menu-okno' }, el('input', { type: 'checkbox', checked: !!p.nove_okno, onchange: (e) => { p.nove_okno = e.target.checked; } }), ' ' + T('new window')) : null,
			description,
			el('span', { class: 'menu-akce' },
				tl('↑', T('Move up'), (c) => move(c, -1), i === 0),
				tl('↓', T('Move down'), (c) => move(c, 1), i === field(path).length - 1),
				depth < MAX_DEPTH ? tl('→', T(depth === 0 ? 'Into the submenu of the item above' : 'Into the group above (a column of the mega menu)'), indent, !canIndent(p, path)) : null,
				depth > 0 ? tl('←', T('Out of the submenu, one level up'), outdent) : null,
				tl('✕', T('Remove from menu'), remove)));
		const li = el('li', { class: 'menu-polozka' }, rowEl);
		li.dataset.cesta = path.join(',');
		// dragged by the handle (the whole item would prevent selecting text in the fields)
		const handle = rowEl.querySelector('.menu-uchyt');
		handle.addEventListener('dragstart', (e) => { dragged = path; e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', ''); e.dataTransfer.setDragImage(rowEl, 10, 10); li.classList.add('menu-tazena'); });
		handle.addEventListener('dragend', () => { dragged = null; li.classList.remove('menu-tazena'); list.querySelectorAll('.menu-cil').forEach((x) => x.classList.remove('menu-cil')); });
		rowEl.addEventListener('dragover', (e) => {
			if (!dragged || (item(dragged).deti.length && path.length > 1)) { return; } // an item with a submenu stays at the top level
			e.preventDefault();
			rowEl.classList.add('menu-cil');
		});
		rowEl.addEventListener('dragleave', () => rowEl.classList.remove('menu-cil'));
		rowEl.addEventListener('drop', (e) => {
			e.preventDefault();
			if (!dragged || dragged.join() === path.join()) { return; }
			// inserted before the target item, at its level (the arrays are looked up first – removing the dragged item shifts the indexes)
			const target = item(path);
			const destination = field(path);
			const [p2] = field(dragged).splice(dragged[dragged.length - 1], 1);
			if (path.length > 1) { p2.deti = []; }
			destination.splice(destination.indexOf(target), 0, p2);
			dragged = null;
			render(null);
		});
		if (p.deti.length) {
			li.append(el('ol', { class: 'menu-podmenu' }, p.deti.map((d, j) => row(d, [...path, j]))));
		}
		return li;
	}

	function render(focusTarget) {
		list.replaceChildren(...items.map((p, i) => row(p, [i])));
		empty.hidden = items.length > 0;
		if (focusTarget) {
			// after a move with an arrow, focus stays on the moved item (keyboard control)
			const li = list.querySelector('[data-cesta="' + focusTarget.join(',') + '"]');
			if (li) { li.querySelector('.menu-radek input').focus(); }
		}
	}

	formEl.querySelectorAll('[data-menu-pridej]').forEach((b) => b.addEventListener('click', () => {
		const type = b.dataset.menuPridej;
		const newVersion = { typ: type, text: '', deti: [] };
		if (type === 'stranka') {
			const selection = formEl.querySelector('[data-menu-stranka]');
			if (!selection.value) { return; }
			newVersion.ids = Number(selection.value);
		}
		if (type === 'odkaz') { newVersion.url = ''; newVersion.nove_okno = false; }
		items.push(newVersion);
		render([items.length - 1]);
	}));

	/** A notice in a custom dialog (not window.alert – that cannot be styled or translated); on close, focus returns to the invalid field. */
	function notify(text, field) {
		const d = el('dialog', { class: 'potvrzeni', role: 'alertdialog', 'aria-modal': 'true' },
			el('p', {}, text),
			el('div', {}, el('button', { type: 'button', class: 'tl', onclick: () => d.close() }, T('Rozumím'))));
		d.setAttribute('aria-label', text);
		d.addEventListener('close', () => { d.remove(); field.focus(); });
		document.body.append(d);
		d.showModal();
	}

	formEl.addEventListener('submit', (e) => {
		// the server would silently drop a link without a URL or text and a group without text – better to say so right away
		const invalid = list.querySelectorAll('.menu-polozka');
		for (const li of invalid) {
			const p = item(li.dataset.cesta.split(',').map(Number));
			if ((p.typ === 'odkaz' && (!p.text || !p.url)) || (p.typ === 'skupina' && !p.text)) {
				e.preventDefault();
				// the text or (for a link) the URL is missing: focus the field that is empty
				const empty = [...li.querySelector('.menu-radek').querySelectorAll('input.textpole:not(.menu-popis-pole)')].find((x) => !x.value.trim()) || li.querySelector('input');
				empty.setAttribute('aria-invalid', 'true');
				empty.addEventListener('input', () => empty.removeAttribute('aria-invalid'), { once: true });
				notify(p.typ === 'odkaz' ? T('A custom link needs both text and an address.') : T('A group needs text.'), empty);
				return;
			}
		}
		formEl.querySelector('input[name="polozky"]').value = JSON.stringify(items);
	});

	render(null);
});
