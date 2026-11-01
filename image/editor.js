/* Kaleta - text editor (news, pages) and working with images. No libraries, no build step.
 *
 *   <textarea data-editor>            WYSIWYG editor (data-editor="maly" = shortened toolbar, "stavitel" = the builder's Text:
 *                                     shortened, with numbered lists and quotes, still without subheadings – the Heading element has them)
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
	var MAX_POST = parseInt(SCRIPT.getAttribute('data-max-davka') || '0', 10); // the server's limit of one request (post_max_size, 3.22)
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

	var ALLOWED = { P: [], H2: [], H3: [], H4: [], STRONG: [], EM: [], B: [], I: [], U: [], S: [], SUB: [], SUP: [], MARK: [], BR: [], HR: [],
		A: ['href', 'title', 'target', 'rel'], UL: [], OL: [], LI: [], BLOCKQUOTE: [], CODE: [], PRE: [],
		FIGURE: ['class'], FIGCAPTION: [], IMG: ['src', 'alt', 'width', 'height', 'loading', 'data-id'],
		TABLE: [], THEAD: [], TBODY: [], TR: [], TH: ['colspan', 'rowspan'], TD: ['colspan', 'rowspan'], IFRAME: ['src', 'width', 'height', 'allowfullscreen', 'title'] };
	var TAG_REPLACEMENTS = { DIV: 'P', H1: 'H2', H5: 'H4', H6: 'H4' };
	// removed with their content: the head of a pasted page, Word's markup and every raw-text element – their text is never
	// shown, and the serializer writes it unescaped, so it must not survive into the markup (3.18.2, N3182-1)
	var DISCARD = /^(SCRIPT|STYLE|META|LINK|TITLE|HEAD|O:P|XML|TEXTAREA|NOSCRIPT|XMP|NOEMBED|NOFRAMES|PLAINTEXT)$/;
	// a pasted frame only from the services the site embeds itself – the same list as data-vlozit in image/web.js
	// (YouTube without cookies, Vimeo, Google map, Builder\Elements\Embed::SERVICES)
	var EMBED = /^https:\/\/(www\.youtube-nocookie\.com\/embed\/|player\.vimeo\.com\/video\/|maps\.google\.com\/maps\?|calendly\.com\/|calendar\.google\.com\/calendar\/appointments\/schedules\/|docs\.google\.com\/forms\/d\/e\/|forms\.office\.com\/Pages\/ResponsePage\.aspx\?|tally\.so\/embed\/|form\.typeform\.com\/to\/|airtable\.com\/embed\/|open\.spotify\.com\/embed\/|w\.soundcloud\.com\/player\/\?)/;
	var REL = ['noopener', 'noreferrer', 'nofollow', 'ugc', 'sponsored'];

	// browsers drop tabs, line breaks and control characters from a URL before reading its scheme ("java\tscript:")
	var UNSAFE_URL = /^(javascript|vbscript|data):/i;
	function unsafeUrl(url) { return UNSAFE_URL.test(String(url).replace(/[\u0000-\u0020\u007f]/g, '')); }

	/** The address of a pasted frame the site allows (a YouTube frame turns into its cookie-free variant), otherwise ''. */
	function embedSrc(src) {
		src = String(src).trim().replace(/^https:\/\/(www\.)?youtube\.com\/embed\//, 'https://www.youtube-nocookie.com/embed/');
		return EMBED.test(src) ? src : '';
	}

	// strict: HTML from outside (a paste, the builder canvas) – a frame stays only with an allowed address; 'paste' also turns
	// Word's list paragraphs into lists (wordLists)
	function sanitize(node, strict) {
		Array.prototype.slice.call(node.childNodes).forEach(function (n) {
			if (n.nodeType === 8) { n.remove(); return; }
			if (n.nodeType !== 1) { return; }
			var tag = n.tagName;
			if (DISCARD.test(tag)) { n.remove(); return; }
			sanitize(n, strict);
			if (TAG_REPLACEMENTS[tag]) {
				var fresh = n.ownerDocument.createElement(TAG_REPLACEMENTS[tag]);
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
				if (ALLOWED[tag].indexOf(a.name) === -1 || (/^(href|src)$/.test(a.name) && unsafeUrl(a.value))) { n.removeAttribute(a.name); }
			});
			if (tag === 'IFRAME') {
				// a frame's content is raw text the serializer writes as it is (</iframe> inside it ends the frame) – never kept
				n.textContent = '';
				var src = embedSrc(n.getAttribute('src') || '');
				if (src) { n.setAttribute('src', src); } else if (strict) { n.remove(); }
			} else if (tag === 'A') {
				// a new window only as _blank and always without window.opener; rel only with the values links need (N3182-5)
				if (n.hasAttribute('target') && n.getAttribute('target') !== '_blank') { n.removeAttribute('target'); }
				var rel = (n.getAttribute('rel') || '').toLowerCase().split(/\s+/).filter(function (t, i, all) { return REL.indexOf(t) !== -1 && all.indexOf(t) === i; });
				if (n.getAttribute('target') === '_blank' && rel.indexOf('noopener') === -1) { rel.unshift('noopener'); }
				if (rel.length) { n.setAttribute('rel', rel.join(' ')); } else { n.removeAttribute('rel'); }
			}
		});
	}

	/** Empty paragraphs out, non-breaking spaces into spaces – on the nodes, never on the markup (N3182-1). */
	function tidy(box) {
		Array.prototype.slice.call(box.querySelectorAll('p')).forEach(function (p) {
			var empty = Array.prototype.every.call(p.childNodes, function (c) { return (c.nodeType === 3 && /^\s*$/.test(c.data)) || (c.nodeType === 1 && c.tagName === 'BR'); });
			if (empty) { p.remove(); }
		});
		var walker = box.ownerDocument.createTreeWalker(box, 4); // NodeFilter.SHOW_TEXT
		for (var t = walker.nextNode(); t; t = walker.nextNode()) {
			if (t.data.indexOf('\u00a0') !== -1) { t.data = t.data.replace(/\u00a0/g, ' '); }
		}
	}

	/* ---------- lists pasted from Word (3.22, U7) ---------- */

	// Word writes list items as paragraphs (class MsoListParagraph…, style "mso-list:l0 level2 lfo1") with the bullet or the
	// number as text in a span marked "mso-list:Ignore"; text from elsewhere can carry the bullets ("·", "o", "§", "1.", "a)")
	// at the start of plain paragraphs. Both become <ul>/<ol> with <li>, nested by Word's level; Google Docs and Word Online
	// already paste real lists, which stay as they are.
	var LIST_MARKER = /^[\s\u00a0]*(\(?(?:\d{1,3}|[a-z]|[ivxlc]{1,6})[.)]|[·•▪◦○●■□§Øü*o\-–])(?:[\s\u00a0]+|$)/i;
	var ORDERED_MARKER = /^\(?(?:\d{1,3}|[a-z]|[ivxlc]{1,6})[.)]$/i;
	// a plain paragraph (without Word's marks) needs a stronger hint: a bullet character, "1." / "a)", or Word's "o" / "-" padded with a non-breaking space or tab
	var PLAIN_MARKER = /^[\s\u00a0]*(\(?(?:\d{1,3}|[a-z])[.)](?=[\s\u00a0])|[·•▪◦●■§](?=[\s\u00a0])|[o\-–*](?=[\u00a0\t]))[\s\u00a0]+/i;

	/** Removes the first n characters of the paragraph's text (the marker typed at its start) and the spaces after them. */
	function cutLeading(p, n) {
		var walker = p.ownerDocument.createTreeWalker(p, 4); // NodeFilter.SHOW_TEXT
		for (var t = walker.nextNode(); t && n > 0; t = walker.nextNode()) {
			var take = Math.min(n, t.data.length);
			t.data = t.data.slice(take);
			n -= take;
		}
		trimStart(p);
	}

	function trimStart(p) {
		var walker = p.ownerDocument.createTreeWalker(p, 4);
		for (var t = walker.nextNode(); t; t = walker.nextNode()) {
			t.data = t.data.replace(/^[\s\u00a0]+/, '');
			if (t.data !== '') { return; }
		}
	}

	/** {level, ordered, id, word, strip} of a paragraph that is a list item, otherwise null. */
	function listItem(p) {
		if (p.parentNode && p.parentNode.closest && p.parentNode.closest('li')) { return null; } // Google Docs: <li><p>…</p></li>
		var style = p.getAttribute('style') || '';
		var mso = /mso-list:\s*(l\d+)\s+level(\d+)(?:\s+(lfo\d+))?/i.exec(style);
		var word = !!mso || /^MsoList(Paragraph|Bullet|Number)/i.test(p.getAttribute('class') || '');
		var level = mso ? Math.max(1, Math.min(9, parseInt(mso[2], 10))) : 1;
		var id = mso ? mso[1] + (mso[3] || '') : '';
		if (word) {
			var ignore = Array.prototype.filter.call(p.querySelectorAll('[style]'), function (n) { return /mso-list:\s*ignore/i.test(n.getAttribute('style')); })[0];
			if (ignore) {
				var marker = ignore.textContent.replace(/[\s\u00a0]+/g, '');
				return { level: level, ordered: ORDERED_MARKER.test(marker), id: id, word: true, strip: function () {
					var parent = ignore.parentNode;
					ignore.remove();
					// the wrapper spans of the marker (font Symbol, Wingdings) are empty now
					while (parent && parent !== p && !parent.textContent.replace(/[\s\u00a0]+/g, '') && !parent.querySelector('img')) { var up = parent.parentNode; parent.remove(); parent = up; }
					trimStart(p);
				} };
			}
		}
		var text = p.textContent;
		// only the list style of Word ("List Paragraph" also indents plain paragraphs) needs the stronger hint, as plain text does
		var m = (mso ? LIST_MARKER : PLAIN_MARKER).exec(text);
		if (!m) { return mso ? { level: level, ordered: false, id: id, word: true, strip: function () { trimStart(p); } } : null; }
		var symbol = m[1].replace(/[\s\u00a0]+/g, '');
		return { level: level, ordered: ORDERED_MARKER.test(symbol), id: id, word: !!mso, strip: function () { cutLeading(p, m[0].length); } };
	}

	/** The next element sibling, skipping white space and comments (Word's conditional comments); null at anything else. */
	function nextParagraph(n) {
		for (n = n.nextSibling; n; n = n.nextSibling) {
			if (n.nodeType === 8 || (n.nodeType === 3 && /^[\s\u00a0]*$/.test(n.data))) { continue; }
			return n.nodeType === 1 && n.tagName === 'P' ? n : null;
		}
		return null;
	}

	function wordLists(box) {
		var doc = box.ownerDocument;
		var done = [];
		Array.prototype.slice.call(box.querySelectorAll('p')).forEach(function (p) {
			if (done.indexOf(p) !== -1 || !p.parentNode) { return; }
			var run = [];
			for (var n = p, item; n && (item = listItem(n)); n = nextParagraph(n)) { item.p = n; run.push(item); done.push(n); }
			// plain paragraphs only as a run of at least two of one kind – one "1. Introduction" after bullets stays a paragraph
			run.forEach(function (it, i) {
				if (it.word) { return; }
				var same = function (o) { return o && !o.word && o.ordered === it.ordered; };
				it.skip = !same(run[i - 1]) && !same(run[i + 1]);
			});
			var stack = []; // the open lists: {level, ordered, id, list}
			run.forEach(function (it) {
				if (it.skip) { stack = []; return; } // the paragraph stays; what follows starts a new list after it
				it.strip();
				while (stack.length && stack[stack.length - 1].level > it.level) { stack.pop(); }
				var top = stack[stack.length - 1];
				// the same level but another kind of list (bullets after numbers) or another Word list = a new list next to it
				if (top && top.level === it.level && (top.ordered !== it.ordered || (it.id && top.id && it.id !== top.id))) { stack.pop(); top = stack[stack.length - 1]; }
				if (!top || top.level < it.level) {
					var list = doc.createElement(it.ordered ? 'ol' : 'ul');
					if (top) { (top.list.lastElementChild || top.list.appendChild(doc.createElement('li'))).appendChild(list); } else { it.p.before(list); }
					stack.push(top = { level: it.level, ordered: it.ordered, id: it.id, list: list });
				}
				var li = doc.createElement('li');
				while (it.p.firstChild) { li.appendChild(it.p.firstChild); }
				top.list.appendChild(li);
				it.p.remove();
			});
		});
	}

	// parsed in an inert document (3.18.2, CodeQL #32): in the admin page itself a pasted <img onerror> loaded and ran
	// before sanitize() removed it – nothing loads or runs in a document without a window
	var inert = null;
	function cleanOnce(html, strict, keepEmpty) {
		inert = inert || document.implementation.createHTMLDocument('');
		var box = inert.createElement('div');
		box.innerHTML = html;
		if (strict === 'paste') { wordLists(box); } // only what is pasted; text typed in the builder's canvas stays as it was typed
		sanitize(box, strict);
		if (!keepEmpty) { tidy(box); }
		return box.innerHTML.trim();
	}

	/**
	 * The cleaned HTML. The result is parsed again (insertHTML, innerHTML, the saved field), so it must come out the same
	 * when cleaned once more – nested paragraphs or a table the parser rebuilds settle in a pass or two; markup that does
	 * not settle is given back as plain text.
	 */
	function cleanHtml(html, strict, keepEmpty) {
		var out = cleanOnce(html, strict, keepEmpty);
		for (var i = 0; i < 4; i++) {
			var again = cleanOnce(out, strict, keepEmpty);
			if (again === out) { return out; }
			out = again;
		}
		var box = inert.createElement('div');
		box.innerHTML = out;
		return esc(box.textContent);
	}

	/** Shows cleaned HTML in an element of the admin page as nodes from the inert document – no string is parsed here. */
	function showClean(target, html, strict, keepEmpty) {
		var cleaned = cleanHtml(html, strict, keepEmpty);
		var box = inert.createElement('div');
		box.innerHTML = cleaned;
		var fragment = document.createDocumentFragment();
		Array.prototype.forEach.call(box.childNodes, function (n) { fragment.appendChild(document.importNode(n, true)); });
		target.replaceChildren(fragment);
	}

	/* ---------- uploading ---------- */

	// A phone photo (5–10 MB) is scaled down to MAX_STRANA px already in the browser – the server would scale it anyway – so it
	// does not hit the hosting limit (3.22, C14: quality 0.85, a note on what changed). createImageBitmap keeps the EXIF rotation;
	// EXIF data (location too) is dropped, as in server processing. A PNG stays a PNG (transparency); GIF, SVG and PDF are left alone.
	// Resolves to the file to send; a scaled one carries .kaletaFrom = [width, height, bytes] of the original and .kaletaTo of the result.
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
			// quality 0.85; when the result is still over the server limit, a lower one is tried (PNG has no quality)
			var write = function (quality) {
				return new Promise(function (done) { canvas.toBlob(done, file.type, quality); }).then(function (blob) {
					if (blob && MAX_FILE && blob.size > MAX_FILE && file.type !== 'image/png' && quality > 0.6) { return write(Math.round((quality - 0.1) * 100) / 100); }
					// a browser that cannot write the type (WebP in Safari) returns another one – then the original file is preferred
					if (!(blob && blob.type === file.type && (ratio < 1 || blob.size < file.size))) { return file; }
					var smaller = new File([blob], file.name, { type: file.type, lastModified: file.lastModified });
					smaller.kaletaFrom = [w, h, file.size];
					smaller.kaletaTo = [canvas.width, canvas.height, blob.size];
					return smaller;
				});
			};
			return write(0.85);
		}).catch(function () { return file; });
	}

	/** 6.2 MB, 840 kB – the size for the note. */
	function sizeText(bytes) {
		return bytes >= 1048576 ? (bytes / 1048576).toFixed(1).replace('.', LANGUAGE === 'en' ? '.' : ',') + ' MB' : Math.max(1, Math.round(bytes / 1024)) + ' kB';
	}

	/** "photo.jpg resized from 4032 × 3024 (6.2 MB) to 2000 × 1500 (840 kB)" for each photo the browser scaled down. */
	function resizedNotes(files) {
		return files.filter(function (s) { return s.kaletaFrom; }).map(function (s) {
			var dims = function (d) { return d[0] + ' × ' + d[1] + ' px (' + sizeText(d[2]) + ')'; };
			return T('%s resized from %s to %s').replace('%s', s.name).replace('%s', dims(s.kaletaFrom)).replace('%s', dims(s.kaletaTo));
		});
	}

	// Scales images down and sets aside files the server would reject anyway (a whole request over the limit would fail without
	// explanation): one file over the per-file limit, and – for a form sent at once – files over the limit of one request together.
	// The resolved list carries .notes (resizedNotes).
	function prepareFiles(files, together) {
		return Promise.all(Array.prototype.map.call(files, shrink)).then(function (finished) {
			var errors = [];
			var ok = finished.filter(function (s) {
				if (!MAX_FILE || s.size <= MAX_FILE) { return true; }
				errors.push(s.name + ': ' + T('The file is larger than the server allows (%s at most) even after resizing. Make it smaller, or raise the limit as System status describes.').replace('%s', SCRIPT.getAttribute('data-max-soubor-text') || ''));
				return false;
			});
			var total = ok.reduce(function (sum, s) { return sum + s.size; }, 0);
			if (together && MAX_POST && ok.length > 1 && total > MAX_POST) {
				errors.push(T('These files are larger together (%s) than the server accepts in one upload (%s). Upload them in smaller groups.').replace('%s', sizeText(total)).replace('%s', sizeText(MAX_POST)));
				ok = [];
			}
			if (errors.length) { announce(errors.join('\n')); }
			ok.notes = resizedNotes(ok);
			return ok;
		});
	}

	// note: where the "resized from … to …" note goes (an element with aria-live), optional
	function upload(selected, note) {
		return prepareFiles(selected).then(function (files) {
			if (note && files.notes.length) { note.textContent = files.notes.join(' · '); }
			return files.length ? sendInBatches(files) : [];
		});
	}

	// files that do not fit in one request together go in several (each within the server's post_max_size)
	function sendInBatches(files) {
		var batches = [[]], size = 0;
		files.forEach(function (s) {
			if (MAX_POST && size + s.size > MAX_POST && batches[batches.length - 1].length) { batches.push([]); size = 0; }
			batches[batches.length - 1].push(s);
			size += s.size;
		});
		return batches.reduce(function (chain, batch) {
			return chain.then(function (all) { return send(batch).then(function (items) { return all.concat(items); }); });
		}, Promise.resolve([]));
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
			// one uploaded file while picking one image is the choice (3.12, UX310-24) – no second click on its thumbnail
			var uploaded = function (newItems) {
				if (!modal.vice && newItems.length === 1 && (!newItems[0].soubor || modal.sPrilohami)) { modal.close(); modal.zpetne(newItems[0]); return; }
				newItems.reverse().forEach(function (o) { add(o, true); });
			};
			Array.prototype.forEach.call(modal.querySelectorAll('input[type=file]'), function (inputEl) { inputEl.addEventListener('change', function () {
				upload(this.files, modal.querySelector('.napoveda')).then(uploaded);
				this.value = '';
			}); });
			modal.addEventListener('dragover', function (e) { e.preventDefault(); });
			modal.addEventListener('drop', function (e) {
				e.preventDefault();
				if (e.dataTransfer.files.length) { upload(e.dataTransfer.files, modal.querySelector('.napoveda')).then(uploaded); }
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
		[T('1. list'), T('Numbered list'), function () { statement('insertOrderedList'); }, 'text'],
		[T('“quote”'), T('Quote'), function () { statement('formatBlock', 'BLOCKQUOTE'); }, 'text'],
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

	/* Link dialog (3.22, U6): one field for an address or a search – pages, news, collection items, files in Media and the
	   language versions from the server (admin.php?action=link_targets), anchors on the page in the builder (window.kaletaKotvy),
	   and mailto:/tel: for a typed e-mail or phone number. A combobox: arrows move in the list, Enter picks, Esc closes the list.
	   Embedded browsers suppress the system prompt(). */
	var linkDialog = null;

	// what is an address already – nothing to search for
	var LOOKS_LIKE_ADDRESS = /^(https?:|mailto:|tel:|\/|#|\.|www\.)/i;
	var EMAIL = /^[^\s@<>"]+@[^\s@<>"]+\.[a-z]{2,}$/i;
	var PHONE = /^\+?[\d\s().\/-]{6,}$/;

	/** The suggestions for what is typed that need no server: an e-mail, a phone number, anchors on the page. */
	function localTargets(q) {
		var groups = [];
		if (EMAIL.test(q)) { groups.push({ label: T('E-mail'), items: [{ url: 'mailto:' + q, title: T('Send an e-mail to %s').replace('%s', q), note: 'mailto:' }] }); }
		var digits = q.replace(/\D/g, '');
		if (PHONE.test(q) && digits.length >= 6 && digits.length <= 15) {
			groups.push({ label: T('Phone'), items: [{ url: 'tel:' + (q.trim().charAt(0) === '+' ? '+' : '') + digits, title: T('Call %s').replace('%s', q.trim()), note: 'tel:' }] });
		}
		var anchors = (window.kaletaKotvy ? window.kaletaKotvy() : []).filter(function (a) {
			var needle = q.replace(/^#/, '').toLowerCase();
			return needle === '' || a[0].toLowerCase().indexOf(needle) !== -1 || String(a[1]).toLowerCase().indexOf(needle) !== -1;
		});
		if (anchors.length) { groups.push({ label: T('On this page'), items: anchors.slice(0, 12).map(function (a) { return { url: a[0], title: a[1] || a[0], note: a[0] }; }) }); }
		return groups;
	}

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
				+ '<label for="odkaz-adresa">' + T('Address, or search the site') + '</label>'
				+ '<input class="textpole siroke" id="odkaz-adresa" type="text" name="adresa" placeholder="' + T('https://…, /about-us, an e-mail, a phone – or a page name') + '" autocomplete="off"'
				+ ' role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="odkaz-seznam">'
				+ '<div class="odkaz-vysledky" id="odkaz-seznam" role="listbox" aria-label="' + T('Suggestions') + '" hidden></div>'
				+ '<p class="odkaz-stav" aria-live="polite"></p>'
				+ '<label class="odkaz-volba"><input type="checkbox" name="nove"> ' + T('open in a new window') + '</label>'
				+ '<div class="odkaz-tlacitka"><button type="submit" class="tl" value="ok">' + T('Insert link') + '</button> <button type="button" class="navigace" data-zrusit>' + T('Remove link') + '</button> <button type="button" class="navigace" data-zavri>' + T('Close') + '</button></div></form>';
			document.body.appendChild(linkDialog);
			var input = linkDialog.querySelector('[name=adresa]');
			var list = linkDialog.querySelector('.odkaz-vysledky');
			var status = linkDialog.querySelector('.odkaz-stav');
			var timer = null, asked = 0, active = -1;
			var options = function () { return list.querySelectorAll('[role=option]'); };
			var open = function (yes) { list.hidden = !yes; input.setAttribute('aria-expanded', yes ? 'true' : 'false'); if (!yes) { mark(-1); } };
			var mark = function (i) {
				var all = options();
				active = all.length ? Math.max(-1, Math.min(i, all.length - 1)) : -1;
				Array.prototype.forEach.call(all, function (o, j) { o.classList.toggle('aktivni', j === active); o.setAttribute('aria-selected', j === active ? 'true' : 'false'); });
				if (active >= 0) { input.setAttribute('aria-activedescendant', all[active].id); all[active].scrollIntoView({ block: 'nearest' }); } else { input.removeAttribute('aria-activedescendant'); }
			};
			var pick = function (o) { input.value = o.getAttribute('data-url'); open(false); status.textContent = T('Link to: %s').replace('%s', o.querySelector('span').textContent); input.focus(); };
			var show = function (groups, q) {
				list.textContent = '';
				var n = 0;
				groups.forEach(function (g) {
					var box = document.createElement('div');
					box.setAttribute('role', 'group');
					box.setAttribute('aria-label', g.label);
					var heading = document.createElement('div');
					heading.className = 'odkaz-skupina';
					heading.setAttribute('aria-hidden', 'true');
					heading.textContent = g.label;
					box.appendChild(heading);
					g.items.forEach(function (it) {
						var o = document.createElement('div');
						o.setAttribute('role', 'option');
						o.id = 'odkaz-volba-' + (n++);
						o.setAttribute('data-url', it.url);
						o.innerHTML = '<span></span><small></small>';
						o.firstChild.textContent = it.title;
						o.lastChild.textContent = it.note ? it.note + ' · ' + it.url : it.url;
						o.addEventListener('mousedown', function (e) { e.preventDefault(); }); // the field keeps the focus
						o.addEventListener('click', function () { pick(o); });
						box.appendChild(o);
					});
					list.appendChild(box);
				});
				open(n > 0);
				status.textContent = n ? '' : (q !== '' ? T('Nothing found – the text is used as the address.') : '');
			};
			var search = function () {
				var q = input.value.trim(), mine = ++asked;
				clearTimeout(timer);
				var local = localTargets(q);
				// an address typed in full needs no search; an e-mail or a phone number only its own suggestion
				if (LOOKS_LIKE_ADDRESS.test(q) || EMAIL.test(q) || (local.length && PHONE.test(q))) { show(local, q.charAt(0) === '#' ? q : ''); return; }
				if (q.length === 1) { show(local, ''); return; }
				timer = setTimeout(function () {
					fetch(ADMIN + '?action=link_targets&q=' + encodeURIComponent(q), { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
						if (mine === asked) { show(local.concat(j.groups || []), q); }
					}).catch(function () { if (mine === asked) { show(local, ''); } });
				}, 200);
			};
			linkDialog.search = search;
			input.addEventListener('input', function () { status.textContent = ''; search(); });
			input.addEventListener('keydown', function (e) {
				var all = options();
				if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
					e.preventDefault();
					if (list.hidden) { if (all.length) { open(true); } else { search(); } return; }
					mark(e.key === 'ArrowDown' ? (active + 1) % all.length : (active <= 0 ? all.length - 1 : active - 1));
				} else if (e.key === 'Enter' && !list.hidden && active >= 0) {
					e.preventDefault(); // Enter picks the suggestion; one more Enter inserts the link
					pick(all[active]);
				} else if (e.key === 'Escape' && !list.hidden) {
					e.preventDefault(); // the first Esc closes the list, the second the dialog
					e.stopPropagation();
					open(false);
				}
			});
			linkDialog.addEventListener('cancel', function (e) { if (!list.hidden) { e.preventDefault(); open(false); } });
			input.addEventListener('blur', function () { setTimeout(function () { if (document.activeElement !== input) { open(false); } }, 150); });
			linkDialog.querySelector('[data-zavri]').addEventListener('click', function () { linkDialog.close('zavrit'); });
			linkDialog.querySelector('[data-zrusit]').addEventListener('click', function () { linkDialog.close('zrusit'); });
			linkDialog.addEventListener('close', function () { linkDialog.hotovo(linkDialog.returnValue); });
		}
		var f = linkDialog.querySelector('form');
		f.adresa.value = anchor ? anchor.getAttribute('href') : '';
		f.nove.checked = !!(anchor && anchor.target === '_blank');
		linkDialog.querySelector('.odkaz-vysledky').textContent = '';
		linkDialog.querySelector('.odkaz-vysledky').hidden = true;
		linkDialog.querySelector('.odkaz-stav').textContent = '';
		f.adresa.setAttribute('aria-expanded', 'false');
		linkDialog.querySelector('[data-zrusit]').hidden = !anchor;
		linkDialog.returnValue = '';
		linkDialog.hotovo = function (result) {
			surface.focus();
			selection.removeAllRanges();
			if (anchor) { var r = document.createRange(); r.selectNode(anchor); selection.addRange(r); } else { selection.addRange(scope); }
			var url = f.adresa.value.trim();
			if (result === 'zrusit') { statement('unlink'); } else if (result === 'ok' && url !== '' && !unsafeUrl(url)) { // the sanitizer's check (N3182-6)
				var target = f.nove.checked ? ' target="_blank" rel="noopener"' : '';
				var text = selection.isCollapsed ? url : (anchor ? anchor.innerHTML : selection.toString().replace(/&/g, '&amp;').replace(/</g, '&lt;'));
				statement('insertHTML', '<a href="' + url.replace(/&/g, '&amp;').replace(/"/g, '&quot;') + '"' + target + '>' + (anchor || !selection.isCollapsed ? text : url.replace(/&/g, '&amp;').replace(/</g, '&lt;')) + '</a>');
			}
			surface.dispatchEvent(new Event('input', { bubbles: true }));
		};
		linkDialog.showModal();
		f.adresa.focus();
		if (!anchor) { linkDialog.search(); } // an empty field offers the pages and the anchors at once
	}

	function createEditor(field) {
		var mode = field.getAttribute('data-editor');
		var small = mode === 'maly' || mode === 'stavitel';
		var wrapper = document.createElement('div');
		wrapper.className = 'editor' + (small ? ' editor-maly' : '');
		var tabList = document.createElement('div');
		tabList.className = 'editor-nastroje';
		tabList.setAttribute('role', 'toolbar');
		var surface = document.createElement('div');
		surface.className = 'editor-plocha';
		surface.contentEditable = 'true';
		if (field.dir) { surface.dir = field.dir; } // the text of a right-to-left page (Arabic) is written from the right; the toolbar stays as it is
		surface.style.setProperty('--ed-popis-galerie', JSON.stringify(T('Photo gallery'))); // the label above a photo gallery is drawn by editor.css; a text in CSS could not be translated
		surface.setAttribute('role', 'textbox');
		surface.setAttribute('aria-multiline', 'true');
		surface.setAttribute('aria-label', (field.labels && field.labels[0] ? field.labels[0].textContent : 'Text'));
		var state = document.createElement('div');
		state.className = 'editor-stav';
		var source = false;

		function toField() { if (!source) { field.value = cleanHtml(surface.innerHTML); } count(); field.dispatchEvent(new Event('input', { bubbles: true })); }
		// the field's HTML goes through the cleanup too (it also comes from the builder canvas) and is shown as inert nodes
		function fromField() { showClean(surface, field.value.trim() || '<p><br></p>', false, true); }
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
			// 'velky' = only the large editor; 'text' = also the builder's text fields (3.22, U7), not the short intro fields
			if ((small && t[3] === 'velky') || (mode === 'maly' && t[3] === 'text')) { return; }
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
				upload(transfer.files, state.lastChild).then(function (newItems) { newItems.forEach(function (o) { statement('insertHTML', imageHtml(o)); }); toField(); });
				return;
			}
			var inserted = transfer.getData('text/html');
			if (inserted) { e.preventDefault(); statement('insertHTML', cleanHtml(inserted, 'paste')); toField(); }
		});
		surface.addEventListener('dragover', function (e) { if (hasImages(e.dataTransfer) || (e.dataTransfer.types || []).indexOf('Files') !== -1) { e.preventDefault(); wrapper.classList.add('editor-pretazeni'); } });
		surface.addEventListener('dragleave', function () { wrapper.classList.remove('editor-pretazeni'); });
		surface.addEventListener('drop', function (e) {
			wrapper.classList.remove('editor-pretazeni');
			// a file other than an image (PDF…): not uploaded, but the browser must not open it in place of the form - unsaved text would be gone
			if (!hasImages(e.dataTransfer)) { if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length) { e.preventDefault(); } return; }
			e.preventDefault();
			upload(e.dataTransfer.files, state.lastChild).then(function (newItems) { surface.focus(); newItems.forEach(function (o) { statement('insertHTML', imageHtml(o)); }); toField(); });
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

	// data-obrazek="nahled" (the logo and the site icon, 3.12): only the preview with Change and Remove – the path field
	// stays for the form, hidden; without JavaScript it is a plain text field
	document.querySelectorAll('[data-obrazek]').forEach(function (field) {
		var previewOnly = field.getAttribute('data-obrazek') === 'nahled';
		var tl = document.createElement('button');
		tl.type = 'button';
		tl.className = 'navigace';
		var remove = document.createElement('button');
		remove.type = 'button';
		remove.className = 'navigace nebezpecne';
		remove.textContent = T('Remove');
		var preview = document.createElement('img');
		preview.className = 'obrazek-nahled' + (previewOnly ? ' obrazek-nahled-hlavni' : '');
		preview.alt = '';
		function show() {
			var empty = field.value.trim() === '';
			preview.hidden = empty;
			if (!empty) { preview.src = field.value; }
			tl.textContent = empty ? T('Choose from Media') : T('Change');
			remove.hidden = empty || !previewOnly;
		}
		function choose(url) { field.value = url; show(); field.dispatchEvent(new Event('input', { bubbles: true })); }
		if (previewOnly) {
			field.hidden = true;
			tl.id = field.id + '-vybrat';
			var label = field.id ? document.querySelector('label[for="' + field.id + '"]') : null;
			if (label) { label.htmlFor = tl.id; }
			field.after(preview, tl, remove);
		} else {
			field.after(tl, preview);
		}
		tl.addEventListener('click', function () { pickImage(function (o) { choose(o.url); }); });
		remove.addEventListener('click', function () { choose(''); });
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
			prepareFiles(inputEl.files, true).then(function (files) {
				if (button) { button.disabled = false; }
				if (!files.length) { inputEl.value = ''; return; }
				var transfer = new DataTransfer();
				files.forEach(function (s) { transfer.items.add(s); });
				inputEl.files = transfer.files;
				// the note "resized from … to …" comes back in the message after the upload (Admin\Modules\Media::actionUpload)
				var resized = form.querySelector('input[name="zmenseno"]') || form.appendChild(Object.assign(document.createElement('input'), { type: 'hidden', name: 'zmenseno' }));
				resized.value = files.filter(function (s) { return s.kaletaFrom; }).map(function (s) { return [s.name.replace(/[|\n]/g, ' ')].concat(s.kaletaFrom, s.kaletaTo).join('|'); }).join('\n');
				form.submit();
			});
		});
	});

	window.kaletaCistiHtml = cleanHtml; // the same cleanup for other admin scripts (3.18.2)
	window.kaletaZobrazCiste = showClean; // the builder's inspector shows text typed on the canvas as cleaned nodes (3.18.2, N3182-1)
	window.kaletaVytvorEditor = createEditor; // the page builder creates the editor itself over a dynamic field
	window.kaletaVyberObrazek = pickImage; // picking an image from Media for the builder (the callback gets {url, nazev, …})

	var editors = Array.prototype.map.call(document.querySelectorAll('textarea[data-editor]'), createEditor);
	var draftForm = document.querySelector('form[data-koncept]');
	if (draftForm) { autosave(draftForm, editors); }
})();
