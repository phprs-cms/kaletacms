/* Kaleta - text editor (news, pages) and working with images. No libraries, no build step.
 *
 *   <textarea data-editor>            WYSIWYG editor (data-editor="maly" = shortened toolbar)
 *   <input data-obrazek>              image URL field + "Vybrat z galerie" (Choose from gallery) button and a preview
 *   <form data-nahravani>             uploading by dragging files
 *
 * The form always submits the content of the original <textarea> - without JavaScript it stays a plain HTML field.
 */

(function () {
	'use strict';

	var T = window.T || function (s) { return s; }; // translation of admin texts (image/jazyky/admin-*.js)

	var SCRIPT = document.querySelector('script[data-admin-url]');
	var ADMIN = SCRIPT.getAttribute('data-admin-url');
	var MAX_FILE = parseInt(SCRIPT.getAttribute('data-max-soubor') || '0', 10); // the server's per-file limit in bytes (0 = no limit)
	var MAX_SIDE = parseInt(SCRIPT.getAttribute('data-max-strana') || '2000', 10);
	var CSRF = (document.querySelector('input[name="_csrf"]') || {}).value || '';
	var GALLERY = ADMIN + '?module=media';
	var NEWS_ID = parseInt((document.querySelector('form[data-koncept] input[name="idc"]') || {}).value || '0', 10);
	var LANGUAGE = document.documentElement.lang || 'cs'; // date and time format by the page language
	var time = function (t, timeOnly) { return window.kaletaCas ? window.kaletaCas(t, timeOnly) : new Date(t).toLocaleString(LANGUAGE); }; // image/admin.js

	function esc(t) { return String(t).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;'); }

	// Error notice in a custom dialog - embedded browsers suppress the system alert() just like confirm().
	var noticeDialog = null;
	function announce(text) {
		if (!noticeDialog) {
			noticeDialog = document.createElement('dialog');
			noticeDialog.className = 'potvrzeni';
			noticeDialog.setAttribute('role', 'alertdialog');
			noticeDialog.innerHTML = '<p></p><div><button type="button" class="tl" data-zavri>' + T('Close') + '</button></div>';
			document.body.appendChild(noticeDialog);
			noticeDialog.querySelector('[data-zavri]').addEventListener('click', function () { noticeDialog.close(); });
		}
		noticeDialog.querySelector('p').textContent = text;
		if (!noticeDialog.open) { noticeDialog.showModal(); }
	}

	/* ---------- HTML cleanup (pasting from Word and the web) ---------- */

	var ALLOWED = { P: [], H2: [], H3: [], H4: [], STRONG: [], EM: [], B: [], I: [], U: [], S: [], SUB: [], SUP: [], BR: [], HR: [],
		A: ['href', 'title', 'target', 'rel'], UL: [], OL: [], LI: [], BLOCKQUOTE: [], CODE: [], PRE: [],
		FIGURE: ['class'], FIGCAPTION: [], IMG: ['src', 'alt', 'width', 'height', 'loading', 'data-id'],
		TABLE: [], THEAD: [], TBODY: [], TR: [], TH: ['colspan', 'rowspan'], TD: ['colspan', 'rowspan'], IFRAME: ['src', 'width', 'height', 'allowfullscreen', 'title'] };
	var TAG_REPLACEMENTS = { DIV: 'P', H1: 'H2', H5: 'H4', H6: 'H4' };

	function sanitize(node) {
		Array.prototype.slice.call(node.childNodes).forEach(function (n) {
			if (n.nodeType === 8) { n.remove(); return; }
			if (n.nodeType !== 1) { return; }
			var tag = n.tagName;
			if (/^(SCRIPT|STYLE|META|LINK|TITLE|HEAD|O:P|XML)$/.test(tag)) { n.remove(); return; }
			sanitize(n);
			if (TAG_REPLACEMENTS[tag]) {
				var fresh = document.createElement(TAG_REPLACEMENTS[tag]);
				while (n.firstChild) { fresh.appendChild(n.firstChild); }
				n.replaceWith(fresh);
				return;
			}
			if (!ALLOWED[tag]) {
				while (n.firstChild) { n.parentNode.insertBefore(n.firstChild, n); }
				n.remove();
				return;
			}
			Array.prototype.slice.call(n.attributes).forEach(function (a) {
				if (ALLOWED[tag].indexOf(a.name) === -1 || /^\s*javascript:/i.test(a.value)) { n.removeAttribute(a.name); }
			});
		});
	}

	function cleanHtml(html) {
		var box = document.createElement('div');
		box.innerHTML = html;
		sanitize(box);
		return box.innerHTML.replace(/<p>(\s|&nbsp;|<br>)*<\/p>/g, '').replace(/&nbsp;/g, ' ').trim();
	}

	/* ---------- uploading ---------- */

	// A phone photo (5–10 MB) is scaled down to MAX_STRANA px already in the browser – the server would scale it anyway – so it
	// does not hit the hosting limit. createImageBitmap keeps the EXIF rotation; EXIF data (location too) is dropped, as in server processing.
	function shrink(file) {
		if (!/^image\/(jpeg|png|webp)$/.test(file.type) || !window.createImageBitmap) { return Promise.resolve(file); }
		return createImageBitmap(file, { imageOrientation: 'from-image' }).then(function (bitmap) {
			// the same rule as Core\Images::ratio: a tall image (a full-page screenshot) is measured by width, not by the longer side
			var w = bitmap.width, h = bitmap.height;
			var ratio = h > 2 * w ? Math.min(1, MAX_SIDE / w, 3 * MAX_SIDE / h) : Math.min(1, MAX_SIDE / Math.max(w, h));
			if (ratio === 1 && (!MAX_FILE || file.size <= MAX_FILE)) { bitmap.close(); return file; }
			var canvas = document.createElement('canvas');
			canvas.width = Math.round(bitmap.width * ratio);
			canvas.height = Math.round(bitmap.height * ratio);
			canvas.getContext('2d').drawImage(bitmap, 0, 0, canvas.width, canvas.height);
			bitmap.close();
			// quality 0.9; when the result is still over the server limit, a lower one is tried (PNG has no quality)
			var write = function (quality) {
				return new Promise(function (done) { canvas.toBlob(done, file.type, quality); }).then(function (blob) {
					if (blob && MAX_FILE && blob.size > MAX_FILE && file.type !== 'image/png' && quality > 0.6) { return write(Math.round((quality - 0.1) * 10) / 10); }
					// a browser that cannot write the type (WebP in Safari) returns another one – then the original file is preferred
					return blob && blob.type === file.type && (ratio < 1 || blob.size < file.size)
						? new File([blob], file.name, { type: file.type, lastModified: file.lastModified }) : file;
				});
			};
			return write(0.9);
		}).catch(function () { return file; });
	}

	// Scales images down and sets aside files the server would reject anyway (a whole request over the limit would fail without explanation).
	function prepareFiles(files) {
		return Promise.all(Array.prototype.map.call(files, shrink)).then(function (finished) {
			var errors = [];
			var ok = finished.filter(function (s) {
				if (!MAX_FILE || s.size <= MAX_FILE) { return true; }
				errors.push(s.name + ': ' + T('The file is larger than the server allows (%s at most). Make it smaller or ask your hosting provider to raise the limit.').replace('%s', SCRIPT.getAttribute('data-max-soubor-text') || ''));
				return false;
			});
			if (errors.length) { announce(errors.join('\n')); }
			return ok;
		});
	}

	function upload(selected) {
		return prepareFiles(selected).then(function (files) { return files.length ? send(files) : []; });
	}

	function send(files) {
		var data = new FormData();
		var folder = document.querySelector('.galerie-okno[open] select');
		data.append('_csrf', CSRF);
		data.append('sekce', folder && /^\d+$/.test(folder.value) ? folder.value : '0');
		files.forEach(function (s) { data.append('soubory[]', s); });
		return fetch(GALLERY + '&action=upload&format=json', { method: 'POST', body: data, credentials: 'same-origin' })
			.then(function (r) { return r.json(); })
			.then(function (j) {
				if (j.chyby && j.chyby.length) { announce(j.chyby.join('\n')); }
				return j.obrazky || [];
			})
			.catch(function () { announce(T('Upload failed. Check your connection and try again.')); return []; });
	}

	function hasImages(transfer) {
		return transfer && transfer.files && transfer.files.length && Array.prototype.every.call(transfer.files, function (f) { return /^image\//.test(f.type); });
	}

	/** The original file name of a stored file: media/2026/10/team-photo-d18407.jpg → team-photo.jpg (Images::save adds the suffix). */
	function fileName(url) {
		var name = String(url || '').split('/').pop();
		try { name = decodeURIComponent(name); } catch (e) { /* keep as it is */ }
		return name.replace(/-[0-9a-f]{6}(\.[a-z0-9]+)$/i, '$1');
	}

	/* ---------- gallery dialog ---------- */

	var modal = null;

	// vice = true: tapping selects images and they are inserted at once as a photo gallery
	function pickImage(backwards, more, withAttachments) {
		var selected = [];
		if (!modal) {
			modal = document.createElement('dialog');
			modal.className = 'galerie-okno';
			modal.innerHTML = '<div class="galerie-okno-hlava"><strong>' + T('Media') + '</strong>'
				+ '<label class="tl">' + T('Upload new') + '<input type="file" multiple hidden></label>'
				// on a phone and tablet: take a photo straight into the text (CSS shows the button only on touch devices)
				+ '<label class="navigace galerie-vyfotit">' + T('Take a photo') + '<input type="file" accept="image/*" capture="environment" hidden></label>'
				+ '<button type="button" class="tl" data-vlozit hidden></button>' // „Vložit galerii (n)“ (Insert gallery) – only when selecting several photos
				+ '<button type="button" class="navigace" data-zavri>' + T('Close') + '</button></div>'
				+ '<div class="galerie-okno-filtr"><select aria-label="' + T('Folder') + '"></select>'
				+ '<input class="textpole" type="search" placeholder="' + T('Search media…') + '" aria-label="' + T('Search media') + '"></div>'
				+ '<p class="napoveda"></p><div class="galerie-mrizka"></div>' // the hint text is set on every opening;
			document.body.appendChild(modal);
			modal.querySelector('[data-zavri]').addEventListener('click', function () { modal.close(); });
			modal.querySelector('[data-vlozit]').addEventListener('click', function () { modal.close(); modal.zpetne(modal.vybrane.slice()); });
			modal.querySelector('select').addEventListener('change', function () { load(this.value); });
			var waiting = null; // search runs only after a short pause in typing, not after every letter
			modal.querySelector('input[type=search]').addEventListener('input', function () {
				clearTimeout(waiting);
				waiting = setTimeout(function () { load(modal.querySelector('select').value); }, 300);
			});
			Array.prototype.forEach.call(modal.querySelectorAll('input[type=file]'), function (inputEl) { inputEl.addEventListener('change', function () {
				upload(this.files).then(function (newItems) { newItems.reverse().forEach(function (o) { add(o, true); }); });
				this.value = '';
			}); });
			modal.addEventListener('dragover', function (e) { e.preventDefault(); });
			modal.addEventListener('drop', function (e) {
				e.preventDefault();
				if (e.dataTransfer.files.length) { upload(e.dataTransfer.files).then(function (newItems) { newItems.reverse().forEach(function (o) { add(o, true); }); }); }
			});
		}
		var grid = modal.querySelector('.galerie-mrizka');
		function add(o, upward) {
			var b = document.createElement('button');
			b.type = 'button';
			b.className = 'galerie-polozka';
			if (o.soubor && !modal.sPrilohami) { return; } // main image, logo, gallery: images only
			b.innerHTML = o.soubor ? '<span class="galerie-soubor"><span></span></span><span></span>' : '<img loading="lazy" alt=""><span></span>';
			if (o.soubor) { b.firstChild.firstChild.textContent = o.pripona; } else { b.firstChild.src = o.nahled; }
			// without a name (an upload has none – the name doubles as the alt text) the file name shows: team-photo.jpg
			b.lastChild.textContent = o.nazev || fileName(o.url) || T('untitled');
			b.addEventListener('click', function () {
				if (!modal.vice) { modal.close(); modal.zpetne(o); return; }
				var i = modal.vybrane.indexOf(o);
				if (i === -1) { modal.vybrane.push(o); } else { modal.vybrane.splice(i, 1); }
				b.classList.toggle('vybrana', i === -1);
				b.setAttribute('aria-pressed', i === -1 ? 'true' : 'false');
				modal.oznac();
			});
			// the "No images here yet." / "Loading…" text goes away with the first image (3.6)
			Array.prototype.slice.call(grid.childNodes).forEach(function (n) { if (n.nodeType === 3) { grid.removeChild(n); } });
			if (upward) { grid.prepend(b); } else { grid.appendChild(b); }
		}
		// filter: "" = all, "clanek" = images of this news item, number = folder (0 = unfiled)
		function load(filter) {
			var query = filter === 'clanek' ? '&article=' + NEWS_ID : (filter !== '' ? '&section=' + filter : '');
			var search = modal.querySelector('input[type=search]').value.trim();
			if (search !== '') { query += '&search=' + encodeURIComponent(search); }
			grid.textContent = T('Loading…');
			fetch(GALLERY + '&action=listing' + query, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
				var selection = modal.querySelector('select');
				selection.textContent = '';
				[['', T('All media')]].concat(NEWS_ID ? [['clanek', T('In this text')]] : [], [['0', T('Nezařazené')]], j.slozky.map(function (s) { return [String(s.id), T('Folder: ') + s.nazev]; })).forEach(function (v) {
					var o = document.createElement('option');
					o.value = v[0]; o.textContent = v[1]; o.selected = v[0] === filter;
					selection.appendChild(o);
				});
				grid.textContent = j.obrazky.length ? '' : (search !== '' ? T('Nothing matches your search.') : T('No images here yet.'));
				j.obrazky.forEach(function (o) { add(o, false); });
				additional(query, 2, j.obrazky.length);
			});
		}
		// the server returns 60 items per page: a full page = offer more, so older files are reachable too
		function additional(query, pageNumber, loaded) {
			if (loaded < 60) { return; }
			var tl = document.createElement('button');
			tl.type = 'button';
			tl.className = 'navigace media-dalsi';
			tl.textContent = T('Load more');
			tl.addEventListener('click', function () {
				tl.disabled = true;
				fetch(GALLERY + '&action=listing' + query + '&page=' + pageNumber, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
					tl.remove();
					j.obrazky.forEach(function (o) { add(o, false); });
					additional(query, pageNumber + 1, j.obrazky.length);
				});
			});
			grid.appendChild(tl);
		}
		modal.zpetne = backwards;
		modal.vice = !!more;
		modal.sPrilohami = !!withAttachments && !more;
		modal.vybrane = selected;
		modal.oznac = function () {
			var tl = modal.querySelector('[data-vlozit]');
			tl.hidden = !modal.vice;
			tl.disabled = modal.vybrane.length < 2;
			tl.textContent = modal.vybrane.length < 2 ? T('Select at least 2 photos') : T('Insert gallery (') + modal.vybrane.length + ')';
		};
		modal.oznac();
		modal.querySelector('.napoveda').textContent = more
			? T('Click photos in the order they should appear. You can also drop files here.')
			: T('Click an image to insert it. You can also drop files here – they upload to the selected folder.');
		modal.showModal();
		load(modal.querySelector('select').value || '');
	}

	function imageHtml(o) {
		return '<figure><img src="' + esc(o.url) + '" alt="' + esc(o.nazev) + '" width="' + o.sirka + '" height="' + o.vyska + '" loading="lazy" data-id="' + o.id + '">'
			+ (o.popis ? '<figcaption>' + esc(o.popis) + '</figcaption>' : '') + '</figure><p><br></p>';
	}

	function attachmentHtml(o) {
		return '<p><a href="' + esc(o.url) + '" title="' + esc(o.pripona + ', ' + o.velikost) + '">' + esc(o.nazev || o.pripona) + '</a> (' + esc(o.pripona + ', ' + o.velikost) + ')</p>';
	}

	function galleryHtml(images) {
		return '<figure class="galerie">' + images.map(function (o) {
			return '<img src="' + esc(o.url) + '" alt="' + esc(o.popis || o.nazev) + '" width="' + o.sirka + '" height="' + o.vyska + '" loading="lazy" data-id="' + o.id + '">';
		}).join('') + '</figure><p><br></p>';
	}

	/* ---------- editor ---------- */

	var BUTTONS = [
		['¶', T('Paragraph'), function () { statement('formatBlock', 'P'); }],
		['H2', T('Mezititulek'), function () { statement('formatBlock', 'H2'); }, 'velky'],
		['H3', T('Smaller subheading'), function () { statement('formatBlock', 'H3'); }, 'velky'],
		['B', T('Bold (Ctrl+B)'), function () { statement('bold'); }],
		['I', T('Italic (Ctrl+I)'), function () { statement('italic'); }],
		[T('link'), T('Insert link (Ctrl+K)'), link],
		[T('• list'), T('Bulleted list'), function () { statement('insertUnorderedList'); }],
		[T('1. list'), T('Numbered list'), function () { statement('insertOrderedList'); }, 'velky'],
		[T('“quote”'), T('Quote'), function () { statement('formatBlock', 'BLOCKQUOTE'); }, 'velky'],
		[T('image'), T('Insert an image from Media'), null, 'velky'],
		[T('gallery'), T('Insert a photo gallery – visitors can browse the photos full screen'), 'galerie', 'velky'],
		[T('table'), T('Insert a 3 × 3 table with a header; add rows and columns with the buttons above the table'), function () {
			var row = function (tag) { return '<tr><' + tag + '><br></' + tag + '><' + tag + '><br></' + tag + '><' + tag + '><br></' + tag + '></tr>'; };
			statement('insertHTML', '<table><thead>' + row('th') + '</thead><tbody>' + row('td') + row('td') + '</tbody></table><p><br></p>');
		}, 'velky'],
		['—', T('Oddělovací čára'), function () { statement('insertHorizontalRule'); }, 'velky'],
		['Tx', T('Remove formatting'), function () { statement('removeFormat'); statement('unlink'); }]
	];

	function statement(name, value) { document.execCommand(name, false, value || null); }

	/* Link dialog: a URL, or an own news item found by its title. Embedded browsers suppress the system prompt(). */
	var linkDialog = null;

	function link() {
		var selection = window.getSelection();
		var scope = selection.rangeCount ? selection.getRangeAt(0).cloneRange() : null;
		var node = selection.anchorNode && (selection.anchorNode.nodeType === 1 ? selection.anchorNode : selection.anchorNode.parentElement);
		var anchor = node && node.closest('a');
		var surface = node && node.closest('.editor-plocha');
		if (!surface || !scope) { return; }
		if (!linkDialog) {
			linkDialog = document.createElement('dialog');
			linkDialog.className = 'galerie-okno odkaz-okno';
			linkDialog.innerHTML = '<form method="dialog"><div class="galerie-okno-hlava"><strong>' + T('Link') + '</strong></div>'
				+ '<label>' + T('Adresa') + '<input class="textpole siroke" type="text" name="adresa" placeholder="' + T('https://… or /about-us') + '" autocomplete="off"></label>'
				+ '<label>' + T('…or find a news item on the site') + '<input class="textpole siroke" type="search" name="hledat" placeholder="' + T('part of the headline') + '" autocomplete="off"></label>'
				+ '<div class="odkaz-vysledky" aria-live="polite"></div>'
				+ '<label class="odkaz-volba"><input type="checkbox" name="nove"> ' + T('open in a new window') + '</label>'
				+ '<div class="odkaz-tlacitka"><button type="submit" class="tl" value="ok">' + T('Insert link') + '</button> <button type="button" class="navigace" data-zrusit>' + T('Remove link') + '</button> <button type="button" class="navigace" data-zavri>' + T('Close') + '</button></div></form>';
			document.body.appendChild(linkDialog);
			var timer = null;
			linkDialog.querySelector('[name=hledat]').addEventListener('input', function () {
				var q = this.value.trim(), results = linkDialog.querySelector('.odkaz-vysledky');
				clearTimeout(timer);
				if (q.length < 2) { results.textContent = ''; return; }
				timer = setTimeout(function () {
					fetch(ADMIN + '?module=news&action=search_json&q=' + encodeURIComponent(q), { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
						results.textContent = j.clanky.length ? '' : T('Nothing found.');
						j.clanky.forEach(function (c) {
							var b = document.createElement('button');
							b.type = 'button';
							b.textContent = c.titulek + (c.vydany ? '' : ' (' + T('nevydaný') + ')');
							b.addEventListener('click', function () { linkDialog.querySelector('[name=adresa]').value = c.url; linkDialog.querySelector('[name=adresa]').focus(); });
							results.appendChild(b);
						});
					});
				}, 250);
			});
			linkDialog.querySelector('[data-zavri]').addEventListener('click', function () { linkDialog.close('zavrit'); });
			linkDialog.querySelector('[data-zrusit]').addEventListener('click', function () { linkDialog.close('zrusit'); });
			linkDialog.addEventListener('close', function () { linkDialog.hotovo(linkDialog.returnValue); });
		}
		var f = linkDialog.querySelector('form');
		f.adresa.value = anchor ? anchor.getAttribute('href') : '';
		f.hledat.value = '';
		f.nove.checked = !!(anchor && anchor.target === '_blank');
		linkDialog.querySelector('.odkaz-vysledky').textContent = '';
		linkDialog.querySelector('[data-zrusit]').hidden = !anchor;
		linkDialog.returnValue = '';
		linkDialog.hotovo = function (result) {
			surface.focus();
			selection.removeAllRanges();
			if (anchor) { var r = document.createRange(); r.selectNode(anchor); selection.addRange(r); } else { selection.addRange(scope); }
			var url = f.adresa.value.trim();
			if (result === 'zrusit') { statement('unlink'); } else if (result === 'ok' && url !== '' && !/^\s*javascript:/i.test(url)) {
				var target = f.nove.checked ? ' target="_blank" rel="noopener"' : '';
				var text = selection.isCollapsed ? url : (anchor ? anchor.innerHTML : selection.toString().replace(/&/g, '&amp;').replace(/</g, '&lt;'));
				statement('insertHTML', '<a href="' + url.replace(/&/g, '&amp;').replace(/"/g, '&quot;') + '"' + target + '>' + (anchor || !selection.isCollapsed ? text : url.replace(/&/g, '&amp;').replace(/</g, '&lt;')) + '</a>');
			}
			surface.dispatchEvent(new Event('input', { bubbles: true }));
		};
		linkDialog.showModal();
		f.adresa.focus();
	}

	function createEditor(field) {
		var small = field.getAttribute('data-editor') === 'maly';
		var wrapper = document.createElement('div');
		wrapper.className = 'editor' + (small ? ' editor-maly' : '');
		var tabList = document.createElement('div');
		tabList.className = 'editor-nastroje';
		tabList.setAttribute('role', 'toolbar');
		var surface = document.createElement('div');
		surface.className = 'editor-plocha';
		surface.contentEditable = 'true';
		surface.style.setProperty('--ed-popis-galerie', JSON.stringify(T('Photo gallery'))); // the label above a photo gallery is drawn by editor.css; a text in CSS could not be translated
		surface.setAttribute('role', 'textbox');
		surface.setAttribute('aria-multiline', 'true');
		surface.setAttribute('aria-label', (field.labels && field.labels[0] ? field.labels[0].textContent : 'Text'));
		var state = document.createElement('div');
		state.className = 'editor-stav';
		var source = false;

		function toField() { if (!source) { field.value = cleanHtml(surface.innerHTML); } count(); field.dispatchEvent(new Event('input', { bubbles: true })); }
		function fromField() { surface.innerHTML = field.value.trim() || '<p><br></p>'; }
		function count() {
			var wordCount = (surface.innerText.trim().match(/\S+/g) || []).length;
			state.firstChild.textContent = wordCount + T(' words') + (small ? '' : T(' · reading time about ') + Math.max(1, Math.round(wordCount / 200)) + ' min');
		}
		function insertImage(gallery) {
			var scope = window.getSelection().rangeCount ? window.getSelection().getRangeAt(0).cloneRange() : null;
			pickImage(function (o) {
				surface.focus();
				if (scope && surface.contains(scope.startContainer)) { window.getSelection().removeAllRanges(); window.getSelection().addRange(scope); }
				statement('insertHTML', gallery ? galleryHtml(o) : (o.soubor ? attachmentHtml(o) : imageHtml(o)));
				toField();
			}, gallery, true);
		}

		BUTTONS.forEach(function (t) {
			if (small && t[3] === 'velky') { return; }
			var b = document.createElement('button');
			b.type = 'button';
			b.textContent = t[0];
			b.title = t[1];
			if (t[0] === 'B') { b.style.fontWeight = 'bold'; }
			if (t[0] === 'I') { b.style.fontStyle = 'italic'; }
			b.addEventListener('mousedown', function (e) { e.preventDefault(); });
			b.addEventListener('click', function () { if (source) { return; } surface.focus(); if (typeof t[2] === 'function') { t[2](); } else { insertImage(t[2] === 'galerie'); } toField(); });
			tabList.appendChild(b);
		});
		var html = document.createElement('button');
		html.type = 'button';
		html.textContent = 'HTML';
		html.title = T('Switch to source code');
		html.className = 'editor-html';
		html.setAttribute('aria-pressed', 'false');
		html.addEventListener('click', function () {
			source = !source;
			if (source) { field.value = cleanHtml(surface.innerHTML).replace(/<\/(p|h2|h3|h4|ul|ol|li|blockquote|figure)>/g, '</$1>\n'); } else { fromField(); }
			wrapper.classList.toggle('editor-zdroj', source);
			html.setAttribute('aria-pressed', source ? 'true' : 'false');
			(source ? field : surface).focus();
		});
		tabList.appendChild(html);
		state.appendChild(document.createElement('span'));
		state.appendChild(document.createElement('span'));

		field.parentNode.insertBefore(wrapper, field);
		wrapper.appendChild(tabList);
		wrapper.appendChild(surface);
		wrapper.appendChild(field);
		wrapper.appendChild(state);
		field.classList.add('editor-pole');
		fromField();
		statement('defaultParagraphSeparator', 'p');

		// table editing: the toolbar shows when the cursor is in a table
		var tabBar = document.createElement('div');
		tabBar.className = 'editor-tabulka-lista';
		tabBar.hidden = true;
		var cell = function () {
			var node = window.getSelection().anchorNode;
			node = node && (node.nodeType === 1 ? node : node.parentElement);
			var b = node && node.closest('td, th');
			return b && surface.contains(b) ? b : null;
		};
		[[T('+ row'), function (b) {
			var fresh = b.parentNode.cloneNode(true);
			Array.prototype.forEach.call(fresh.children, function (c) { var td = document.createElement('td'); td.innerHTML = '<br>'; c.replaceWith(td); });
			var body = b.closest('table').querySelector('tbody') || b.closest('table');
			if (b.parentNode.parentNode.tagName === 'THEAD') { body.prepend(fresh); } else { b.parentNode.after(fresh); }
		}], [T('+ column'), function (b) {
			var i = b.cellIndex;
			Array.prototype.forEach.call(b.closest('table').rows, function (r) { var c = document.createElement(r.cells[i].tagName); c.innerHTML = '<br>'; r.cells[i].after(c); });
		}], [T('− row'), function (b) {
			var t = b.closest('table');
			if (t.rows.length > 1) { b.parentNode.remove(); } else { t.remove(); }
		}], [T('− column'), function (b) {
			var i = b.cellIndex, t = b.closest('table');
			if (t.rows[0].cells.length > 1) { Array.prototype.forEach.call(t.rows, function (r) { r.deleteCell(i); }); } else { t.remove(); }
		}], [T('delete table'), function (b) { b.closest('table').remove(); }]].forEach(function (a) {
			var tl = document.createElement('button');
			tl.type = 'button';
			tl.textContent = a[0];
			tl.addEventListener('mousedown', function (e) { e.preventDefault(); });
			tl.addEventListener('click', function () { var b = cell(); if (b) { a[1](b); toField(); tabBar.hidden = !cell(); } });
			tabBar.appendChild(tl);
		});
		tabList.after(tabBar);
		document.addEventListener('selectionchange', function () { tabBar.hidden = source || !cell(); });

		surface.addEventListener('input', toField);
		surface.addEventListener('blur', toField);
		surface.addEventListener('keydown', function (e) {
			// stopPropagation: the command palette (admin.js) has the same shortcut - in the editor it means "insert link"
			if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); e.stopPropagation(); link(); toField(); }
		});
		surface.addEventListener('paste', function (e) {
			var transfer = e.clipboardData;
			if (hasImages(transfer)) {
				e.preventDefault();
				upload(transfer.files).then(function (newItems) { newItems.forEach(function (o) { statement('insertHTML', imageHtml(o)); }); toField(); });
				return;
			}
			var inserted = transfer.getData('text/html');
			if (inserted) { e.preventDefault(); statement('insertHTML', cleanHtml(inserted)); toField(); }
		});
		surface.addEventListener('dragover', function (e) { if (hasImages(e.dataTransfer) || (e.dataTransfer.types || []).indexOf('Files') !== -1) { e.preventDefault(); wrapper.classList.add('editor-pretazeni'); } });
		surface.addEventListener('dragleave', function () { wrapper.classList.remove('editor-pretazeni'); });
		surface.addEventListener('drop', function (e) {
			wrapper.classList.remove('editor-pretazeni');
			// a file other than an image (PDF…): not uploaded, but the browser must not open it in place of the form - unsaved text would be gone
			if (!hasImages(e.dataTransfer)) { if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length) { e.preventDefault(); } return; }
			e.preventDefault();
			upload(e.dataTransfer.files).then(function (newItems) { surface.focus(); newItems.forEach(function (o) { statement('insertHTML', imageHtml(o)); }); toField(); });
		});
		if (field.form) { field.form.addEventListener('submit', function () { if (!source) { field.value = cleanHtml(surface.innerHTML); } }); }
		count();
		// the editor helper (accessibility check, AI assistant) redraws the editor after writing into the field
		window.kaletaEditory = window.kaletaEditory || {};
		if (field.id) { window.kaletaEditory[field.id] = { obnov: function () { fromField(); count(); } }; }
		return { obnov: fromField, stav: state.lastChild };
	}

	/* ---------- automatic saving of unsaved text to the browser ---------- */

	function autosave(form, editors) {
		var key = 'kaleta-koncept:' + form.getAttribute('data-koncept');
		var field = Array.prototype.filter.call(form.elements, function (p) { return p.name && p.name !== '_csrf' && p.type !== 'password' && p.type !== 'file' && p.type !== 'submit'; });
		var timer = null;

		function save() {
			var data = { cas: Date.now(), pole: {} };
			field.forEach(function (p) { if (p.type === 'checkbox' || p.type === 'radio') { if (p.checked) { data.pole[p.name] = p.value; } else if (p.type === 'checkbox') { data.pole[p.name] = null; } } else { data.pole[p.name] = p.value; } });
			try { localStorage.setItem(key, JSON.stringify(data)); } catch (e) { /* the browser did not allow storage - the server remains */ }
			editors.forEach(function (ed) { ed.stav.textContent = T('draft saved in your browser at ') + time(Date.now(), true); });
			lastData = data;
			if (!serverTimer) { serverTimer = setTimeout(saveToServer, 15000); }
		}
		// the unsaved state goes to the server at most once every 15 seconds: it can be continued from another device
		var draftUrl = form.getAttribute('data-koncept-url'), serverTimer = null, lastData = null;
		function saveToServer() {
			serverTimer = null;
			if (!draftUrl || !lastData) { return; }
			var fd = new FormData();
			fd.append('_csrf', CSRF);
			fd.append('idc', String(NEWS_ID || 0));
			fd.append('pole', JSON.stringify(lastData.pole));
			fetch(draftUrl, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
				if (j.ok) { editors.forEach(function (ed) { ed.stav.textContent = T('draft also saved on the server at ') + time(Date.now(), true); }); }
			}).catch(function () { /* offline, the copy stays in the browser */ });
		}
		function discardOnServer() {
			if (!draftUrl) { return; }
			var fd = new FormData();
			fd.append('_csrf', CSRF);
			fd.append('idc', String(NEWS_ID || 0));
			fetch(draftUrl, { method: 'POST', body: fd, credentials: 'same-origin' }).catch(function () { /* nothing */ });
		}
		form.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(save, 1500); });
		// on submit the copy is not deleted, only marked: when saving fails (expired sign-in, connection outage), the text remains to restore.
		// It is deleted only after a confirmed save (success message, image/admin.js), or when it matches the saved content.
		form.addEventListener('submit', function () { clearTimeout(timer); save(); try { var d = JSON.parse(localStorage.getItem(key) || 'null'); if (d) { d.odeslano = Date.now(); localStorage.setItem(key, JSON.stringify(d)); } } catch (e) { /* nothing */ } });

		var storedForm = null;
		try { storedForm = JSON.parse(localStorage.getItem(key) || 'null'); } catch (e) { /* nothing */ }
		// the newer of the two copies: this device's browser, or the server (writing from another device)
		var fromServer = null;
		try { fromServer = JSON.parse((document.getElementById('koncept-server') || {}).textContent || 'null'); } catch (e) { /* nothing */ }
		var isFromServer = !!(fromServer && fromServer.pole && (!storedForm || !storedForm.cas || fromServer.cas > storedForm.cas));
		if (isFromServer) { storedForm = fromServer; }
		if (!storedForm || !storedForm.pole || Date.now() - storedForm.cas > 14 * 86400000) { return; }
		var differs = field.some(function (p) { return (p.tagName === 'TEXTAREA' || p.type === 'text') && storedForm.pole[p.name] !== undefined && storedForm.pole[p.name] !== p.value; });
		if (!differs) { if (!isFromServer) { try { localStorage.removeItem(key); } catch (e) { /* nothing */ } } return; }
		var tabList = document.createElement('p');
		tabList.className = 'hlaska';
		tabList.innerHTML = T(isFromServer ? 'The server holds an unsaved draft from ' : 'Your browser holds an unsaved draft from ') + time(storedForm.cas) + '. <button type="button" class="navigace">' + T('Restore it') + '</button> <button type="button" class="navigace">' + T('Discard') + '</button>';
		form.parentNode.insertBefore(tabList, form);
		tabList.children[0].addEventListener('click', function () {
			field.forEach(function (p) {
				if (!(p.name in storedForm.pole)) { return; }
				if (p.type === 'checkbox') { p.checked = storedForm.pole[p.name] !== null; } else if (p.type === 'radio') { p.checked = p.value === storedForm.pole[p.name]; } else { p.value = storedForm.pole[p.name]; }
			});
			editors.forEach(function (ed) { ed.obnov(); });
			document.querySelectorAll('[data-obrazek]').forEach(function (p) { p.dispatchEvent(new Event('change')); });
			tabList.remove();
		});
		tabList.children[1].addEventListener('click', function () { try { localStorage.removeItem(key); } catch (e) { /* nothing */ } discardOnServer(); tabList.remove(); });
	}

	/* ---------- the "Hlavní obrázek" (Featured image) field ---------- */

	document.querySelectorAll('[data-obrazek]').forEach(function (field) {
		var tl = document.createElement('button');
		tl.type = 'button';
		tl.className = 'navigace';
		tl.textContent = T('Choose from Media');
		var preview = document.createElement('img');
		preview.className = 'obrazek-nahled';
		preview.alt = '';
		function show() { preview.hidden = field.value.trim() === ''; if (!preview.hidden) { preview.src = field.value; } }
		field.after(tl, preview);
		tl.addEventListener('click', function () { pickImage(function (o) { field.value = o.url; show(); field.dispatchEvent(new Event('input', { bubbles: true })); }); });
		field.addEventListener('change', show);
		preview.addEventListener('error', function () { preview.hidden = true; });
		show();
	});

	/* ---------- a file field of a collection (2.11): any file from Media, shown by its name ---------- */

	document.querySelectorAll('[data-soubor]').forEach(function (field) {
		var tl = document.createElement('button');
		tl.type = 'button';
		tl.className = 'navigace';
		tl.textContent = T('Choose from Media');
		field.after(tl);
		tl.addEventListener('click', function () { pickImage(function (o) { field.value = o.url; field.dispatchEvent(new Event('input', { bubbles: true })); }, false, true); });
	});

	/* ---------- uploading by dragging on the gallery page ---------- */

	document.querySelectorAll('[data-nahravani]').forEach(function (form) {
		var inputEl = form.querySelector('input[type=file]');
		form.addEventListener('dragover', function (e) { e.preventDefault(); form.classList.add('nahravani-aktivni'); });
		form.addEventListener('dragleave', function () { form.classList.remove('nahravani-aktivni'); });
		form.addEventListener('drop', function (e) {
			e.preventDefault();
			form.classList.remove('nahravani-aktivni');
			if (e.dataTransfer.files.length) { inputEl.files = e.dataTransfer.files; form.requestSubmit(); }
		});
		// before submitting, scale photos down and set aside files over the server limit (form.submit() does not trigger this handler again)
		form.addEventListener('submit', function (e) {
			if (!window.DataTransfer) { return; }
			e.preventDefault();
			var button = form.querySelector('[type=submit]');
			if (button) { button.disabled = true; }
			prepareFiles(inputEl.files).then(function (files) {
				if (button) { button.disabled = false; }
				if (!files.length) { inputEl.value = ''; return; }
				var transfer = new DataTransfer();
				files.forEach(function (s) { transfer.items.add(s); });
				inputEl.files = transfer.files;
				form.submit();
			});
		});
	});

	window.kaletaVytvorEditor = createEditor; // the page builder creates the editor itself over a dynamic field
	window.kaletaVyberObrazek = pickImage; // picking an image from Media for the builder (the callback gets {url, nazev, …})

	var editors = Array.prototype.map.call(document.querySelectorAll('textarea[data-editor]'), createEditor);
	var draftForm = document.querySelector('form[data-koncept]');
	if (draftForm) { autosave(draftForm, editors); }
})();
