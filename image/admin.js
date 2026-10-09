/* Kaleta - admin odds and ends. No libraries, no build step. */

(function () {
	'use strict';

	// translation of admin script texts: the window.KALETA_PREKLAD dictionary comes from image/jazyky/admin-<code>.js (Czech too); English is the source and has none
	window.T = function (s) { return (window.KALETA_PREKLAD || {})[s] || s; };
	var T = window.T;

	// date and time like date() in PHP, in the site's time zone (<html data-pasmo>): Czech 25. 9. 2026 09:31, English 25 Sep 2026 09:31
	window.kaletaCas = function (time, timeOnly) {
		var c = {};
		var format = function (timeZone) {
			new Intl.DateTimeFormat('en-GB', { timeZone: timeZone, year: 'numeric', month: 'numeric', day: 'numeric', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' })
				.formatToParts(new Date(time)).forEach(function (p) { c[p.type] = p.value; });
		};
		try { format(document.documentElement.getAttribute('data-pasmo') || undefined); } catch (e) { format(undefined); }
		var parseOpeningHours = c.hour + ':' + c.minute;
		if (timeOnly) { return parseOpeningHours; }
		var months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
		return (document.documentElement.lang === 'en' ? c.day + ' ' + months[c.month - 1] + ' ' + c.year : c.day + '. ' + c.month + '. ' + c.year) + ' ' + parseOpeningHours;
	};

	// Select all (2.14): a checkbox with data-vybrat-vse="<form id>" ticks every row checkbox that belongs to that form.
	document.addEventListener('change', function (e) {
		var all = e.target;
		if (!all.hasAttribute || !all.hasAttribute('data-vybrat-vse')) { return; }
		document.querySelectorAll('input[type="checkbox"][form="' + all.getAttribute('data-vybrat-vse') + '"]').forEach(function (box) { box.checked = all.checked; });
	});

	// Search over cards (3.3, Blueprints): input[data-filtr-karet="#container"] hides the [data-filtr-karta] whose text does not
	// contain every word typed, a [data-filtr-skupina] without a visible card, and shows [data-filtr-prazdne] when nothing is left.
	document.addEventListener('input', function (e) {
		var input = e.target;
		if (!input.hasAttribute || !input.hasAttribute('data-filtr-karet')) { return; }
		var box = document.querySelector(input.getAttribute('data-filtr-karet'));
		if (!box) { return; }
		var words = input.value.toLocaleLowerCase().split(/\s+/).filter(Boolean), any = false;
		box.querySelectorAll('[data-filtr-karta]').forEach(function (card) {
			var text = card.textContent.toLocaleLowerCase();
			card.hidden = !words.every(function (w) { return text.indexOf(w) !== -1; });
			any = any || !card.hidden;
		});
		box.querySelectorAll('[data-filtr-skupina]').forEach(function (group) { group.hidden = !group.querySelector('[data-filtr-karta]:not([hidden])'); });
		var empty = box.querySelector('[data-filtr-prazdne]');
		if (empty) { empty.hidden = any; }
	});

	// Confirmation of irreversible actions: data-potvrdit="text" on a form or a button.
	// A custom dialog instead of window.confirm(), which embedded browsers (e.g. in apps) silently suppress.
	var confirmDialog = null;
	document.addEventListener('submit', function (e) {
		var form = e.target, button = e.submitter;
		var text = (button && button.getAttribute('data-potvrdit')) || form.getAttribute('data-potvrdit');
		if (!text || form.potvrzeno) { return; }
		e.preventDefault();
		if (!confirmDialog) {
			confirmDialog = document.createElement('dialog');
			confirmDialog.className = 'potvrzeni';
			confirmDialog.innerHTML = '<p></p><div><button type="button" class="tl" data-ano>' + T('Yes, do it') + '</button> <button type="button" class="navigace" data-ne>' + T('Cancel') + '</button></div>';
			document.body.appendChild(confirmDialog);
			confirmDialog.querySelector('[data-ne]').addEventListener('click', function () { confirmDialog.close(); });
		}
		confirmDialog.querySelector('p').textContent = text;
		// the confirming button says what it does ("Delete", "Disconnect") and is red for a dangerous action (3.1.1)
		var yes = confirmDialog.querySelector('[data-ano]'), label = button && button.tagName === 'BUTTON' ? button.textContent.trim() : '';
		yes.textContent = label !== '' && label.length <= 40 ? label : T('Yes, do it');
		yes.classList.toggle('tl-nebezpecne', !!(button && button.classList.contains('nebezpecne')));
		confirmDialog.querySelector('[data-ano]').onclick = function () {
			confirmDialog.close();
			form.potvrzeno = true;
			if (form.requestSubmit) { form.requestSubmit(button || undefined); } else { form.submit(); }
			form.potvrzeno = false;
		};
		confirmDialog.showModal();
		confirmDialog.querySelector('[data-ne]').focus();
	});

	// Light / dark mode (the default follows the system, the choice is remembered in the browser; tema.js sets it before rendering)
	var schemeButton = document.querySelector('[data-tema-prepinac]');
	if (schemeButton) {
		schemeButton.addEventListener('click', function () {
			var root = document.documentElement;
			var dark = root.getAttribute('data-tema') ? root.getAttribute('data-tema') === 'tmavy' : window.matchMedia('(prefers-color-scheme: dark)').matches;
			root.setAttribute('data-tema', dark ? 'svetly' : 'tmavy');
			try { localStorage.setItem('kaleta-tema', dark ? 'svetly' : 'tmavy'); } catch (e) { /* nothing */ }
		});
	}

	// Expanding the menu on mobile
	var toggle = document.querySelector('.menu-prepinac');
	if (toggle) {
		toggle.addEventListener('click', function () {
			var openItems = document.body.classList.toggle('menu-otevrene');
			toggle.setAttribute('aria-expanded', openItems ? 'true' : 'false');
		});
		// the open menu covers the page on a phone (2.17): Esc closes it and gives the focus back to the button
		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape' && document.body.classList.contains('menu-otevrene')) {
				document.body.classList.remove('menu-otevrene');
				toggle.setAttribute('aria-expanded', 'false');
				toggle.focus();
			}
		});
	}

	// A tab bar that scrolls sideways on a phone (2.17, image/editor.css): the active tab is scrolled into view, and with
	// in-page tabs the newly chosen one follows
	function showActiveTab(bar) {
		var active = bar.querySelector('a.aktivni, [role="tab"][aria-selected="true"]');
		if (active && bar.scrollWidth > bar.clientWidth) {
			var a = active.getBoundingClientRect(), b = bar.getBoundingClientRect();
			bar.scrollLeft += (a.left - b.left) - (b.width - a.width) / 2;
		}
	}
	function markEdges(bar) {
		bar.classList.toggle('posunuto', bar.scrollLeft > 2);
		bar.classList.toggle('na-konci', bar.scrollLeft > 2 && bar.scrollLeft + bar.clientWidth >= bar.scrollWidth - 2);
	}
	document.querySelectorAll('.zalozky').forEach(function (bar) {
		showActiveTab(bar);
		markEdges(bar);
		bar.addEventListener('scroll', function () { markEdges(bar); }, { passive: true });
		bar.addEventListener('click', function () { setTimeout(function () { showActiveTab(bar); }, 0); });
	});

	// Tabs within one page ("Vzhled webu", Site appearance): arrows, Home and End; after saving the last tab comes back; a field that
	// fails the browser's validation shows its tab. Without the script all panels are visible one below another.
	document.querySelectorAll('[data-zalozky]').forEach(function (wrapper) {
		var buttons = Array.prototype.slice.call(wrapper.querySelectorAll('[role="tab"]'));
		var panels = buttons.map(function (t) { return document.getElementById(t.getAttribute('aria-controls')); });
		var shouldSave = wrapper.querySelector('.vzhled-ulozit');
		var key = 'ka-zalozka' + location.search;
		var show = function (i, focusTarget) {
			buttons.forEach(function (t, j) {
				t.setAttribute('aria-selected', i === j ? 'true' : 'false');
				t.tabIndex = i === j ? 0 : -1;
				if (panels[j]) { panels[j].hidden = i !== j; }
			});
			if (shouldSave) { shouldSave.hidden = !wrapper.querySelector('.vzhled-formular').contains(panels[i]); } // import and export have their own buttons
			if (focusTarget) { buttons[i].focus(); }
			try { sessionStorage.setItem(key, buttons[i].id); } catch (error) { /* private mode */ }
		};
		buttons.forEach(function (t, i) {
			t.addEventListener('click', function () { show(i, false); });
			t.addEventListener('keydown', function (e) {
				var target = { ArrowRight: i + 1, ArrowLeft: i - 1, Home: 0, End: buttons.length - 1 }[e.key];
				if (target === undefined) { return; }
				e.preventDefault();
				show((target + buttons.length) % buttons.length, true);
			});
		});
		wrapper.addEventListener('invalid', function (e) {
			var i = panels.indexOf(e.target.closest('[role="tabpanel"]'));
			if (i >= 0) { show(i, false); }
		}, true);
		var storedValue = null;
		try { storedValue = sessionStorage.getItem(key); } catch (error) { /* private mode */ }
		show(Math.max(0, buttons.findIndex(function (t) { return t.id === storedValue; })), false);
	});

	// "Vzhled webu" (Site appearance): presets and a live preview of the real home page. The token CSS is computed by the server (action nahled) – the single computation in PHP.
	var appearance = document.querySelector('[data-vzhled]');
	if (appearance) {
		var preview = document.querySelector('[data-nahled]'), frame2 = document.querySelector('[data-ramec]');
		var device = 'pocitac', timer = null, lastCss = '';
		var insertCss = function () {
			var doc = preview.contentDocument;
			if (!doc || !doc.head || lastCss === '') { return; }
			var style = doc.getElementById('ka-vzhled-nahled');
			if (!style) { style = doc.createElement('style'); style.id = 'ka-vzhled-nahled'; doc.head.appendChild(style); }
			style.textContent = lastCss;
		};
		var recalculate = function () {
			var data = new FormData(appearance);
			fetch(appearance.getAttribute('data-nahled-url'), { method: 'POST', body: data, credentials: 'same-origin' })
				.then(function (r) { return r.json(); })
				.then(function (j) {
					lastCss = j.css;
					insertCss();
					var list = function (items) {
						return items.map(function (k) {
							var li = document.createElement('li');
							li.className = k.ok ? 'ok' : 'spatne';
							li.innerHTML = '<span></span><strong></strong>';
							li.firstChild.textContent = k.popis;
							li.lastChild.textContent = T('%s:1').replace('%s', k.pomer.toLocaleString(document.documentElement.lang || 'cs', { minimumFractionDigits: 1, maximumFractionDigits: 1 }));
							return li.outerHTML;
						}).join('');
					};
					appearance.querySelector('[data-kontrasty]').innerHTML = list(j.kontrasty);
					appearance.querySelector('[data-kontrasty-tmave]').innerHTML = list(j.kontrasty_tmave);
					// dark primary and secondary left on "automatic" (3.6) show the colour the server derived
					appearance.querySelectorAll('[data-tmava-barva]').forEach(function (field) {
						var auto = appearance.querySelector('[name="ds[tmave_auto][]"][value="' + field.getAttribute('data-tmava-barva') + '"]');
						if (auto && auto.checked && j.tmave[field.getAttribute('data-tmava-barva')]) {
							field.value = j.tmave[field.getAttribute('data-tmava-barva')];
							field.parentNode.querySelector('[data-hex]').textContent = field.value;
						}
					});
				})
				.catch(function () {});
		};
		var change = function (e) {
			if (e && e.target && e.target.type === 'color') { e.target.parentNode.querySelector('[data-hex]').textContent = e.target.value; }
			// picking a dark primary or secondary colour by hand turns its "automatic" off
			if (e && e.target && e.target.hasAttribute('data-tmava-barva')) {
				var auto = appearance.querySelector('[name="ds[tmave_auto][]"][value="' + e.target.getAttribute('data-tmava-barva') + '"]');
				if (auto) { auto.checked = false; }
			}
			appearance.querySelector('[data-neulozeno]').hidden = false;
			clearTimeout(timer);
			timer = setTimeout(recalculate, 180);
		};
		appearance.addEventListener('input', change);
		appearance.addEventListener('change', change);
		preview.addEventListener('load', insertCss);
		// a preset fills in the form (sizes are in px in the form, in rem in the design system)
		appearance.querySelectorAll('[data-predvolba]').forEach(function (tl) {
			tl.addEventListener('click', function () {
				var ds = JSON.parse(tl.getAttribute('data-predvolba'));
				Object.keys(ds).forEach(function (key) {
					if (typeof ds[key] === 'object') {
						Object.keys(ds[key]).forEach(function (b) {
							var field = appearance.elements['ds[' + key + '][' + b + ']'];
							if (field) { field.value = ds[key][b]; field.parentNode.querySelector('[data-hex]').textContent = ds[key][b]; }
						});
						return;
					}
					var field = appearance.elements['ds[' + key + ']'];
					if (!field) { return; }
					var value = ['zaklad_min', 'zaklad_max', 'sirka', 'sirka_textu'].indexOf(key) !== -1 ? Math.round(ds[key] * 16) : ds[key];
					if (field instanceof RadioNodeList) { field.value = String(value); return; }
					if (field.tagName === 'SELECT') {
						Array.prototype.forEach.call(field.options, function (o) { if (Math.abs(parseFloat(o.value) - value) < 0.001 || o.value === String(value)) { field.value = o.value; } });
						return;
					}
					field.value = value;
				});
				// like a manual change: recomputes the preview and the form will guard against leaving without saving
				appearance.dispatchEvent(new Event('input', { bubbles: true }));
			});
		});
		// desktop renders at 1280 px wide and is scaled down into the frame, so the site's real breakpoints apply
		var dimension = function () {
			var width = device === 'mobil' ? 390 : 1280, available = frame2.clientWidth, scale = Math.min(1, available / width);
			preview.style.width = width + 'px';
			preview.style.height = (frame2.clientHeight / scale) + 'px';
			preview.style.transform = 'scale(' + scale + ')';
			preview.style.left = Math.max(0, (available - width * scale) / 2) + 'px';
		};
		document.querySelectorAll('[data-zarizeni]').forEach(function (tl) {
			tl.addEventListener('click', function () {
				device = tl.getAttribute('data-zarizeni');
				document.querySelectorAll('[data-zarizeni]').forEach(function (b) { b.setAttribute('aria-pressed', b === tl ? 'true' : 'false'); });
				dimension();
			});
		});
		if (window.ResizeObserver) { new ResizeObserver(dimension).observe(frame2); }
		dimension();
	}

	// General: an option with data-prepni="sekce:1" shows (or :0 hides) the form part marked data-sekce="sekce"
	document.querySelectorAll('[data-prepni]').forEach(function (choice) {
		choice.addEventListener('change', function () {
			var p = choice.getAttribute('data-prepni').split(':');
			document.querySelectorAll('[data-sekce="' + p[0] + '"]').forEach(function (s) { s.hidden = p[1] !== '1'; });
		});
	});

	// Settings → Mail (3.9): choosing a mail service fills the SMTP server, port and encryption from its option and shows
	// its hint (what the user name and the password are); the user name and the password fields are never touched
	document.querySelectorAll('select[data-smtp-sluzba]').forEach(function (service) {
		var form = service.form, region = form.querySelector('select[data-smtp-region]');
		var fill = function () {
			var option = service.options[service.selectedIndex];
			if (!option || !option.hasAttribute('data-host')) { return; }
			var host = option.getAttribute('data-host');
			if (service.value === 'ses' && region) { host = host.replace(/^email-smtp\.[^.]+\./, 'email-smtp.' + region.value + '.'); }
			form.querySelector('#smtp_host').value = host;
			form.querySelector('#smtp_port').value = option.getAttribute('data-port');
			form.querySelector('#smtp_encryption').value = option.getAttribute('data-sifrovani');
		};
		service.addEventListener('change', function () {
			form.querySelectorAll('[data-smtp-tip]').forEach(function (tip) { tip.hidden = tip.getAttribute('data-smtp-tip') !== service.value; });
			fill();
		});
		if (region) { region.addEventListener('change', fill); }
	});

	// General: a form with data-prepinac="pole" shows only rows whose data-pro contains the selected value of the field
	document.querySelectorAll('form[data-prepinac]').forEach(function (form) {
		var displayName = form.getAttribute('data-prepinac');
		var switchTo = function () {
			var chosen = form.querySelector('[name="' + displayName + '"]:checked') || form.querySelector('select[name="' + displayName + '"]');
			form.querySelectorAll('[data-pro]').forEach(function (row) { row.hidden = !chosen || row.getAttribute('data-pro').split(' ').indexOf(chosen.value) === -1; });
		};
		form.addEventListener('change', function (e) { if (e.target.name === displayName) { switchTo(); } });
		switchTo();
	});

	// A listing as cards on a phone: a cell gets the column label from the header (CSS shows it only in a narrow window). The table
	// roles are added explicitly – otherwise browsers drop them with display: block and a screen reader would lose the columns.
	document.querySelectorAll('table.vypis').forEach(function (tab) {
		if (!tab.tHead || !tab.tHead.rows.length) { return; }
		var headers = Array.prototype.map.call(tab.tHead.rows[0].cells, function (th) { th.setAttribute('role', 'columnheader'); return th.textContent.trim(); });
		tab.classList.add('vypis-karty');
		tab.setAttribute('role', 'table');
		Array.prototype.forEach.call(tab.querySelectorAll('thead, tbody'), function (group) { group.setAttribute('role', 'rowgroup'); });
		Array.prototype.forEach.call(tab.rows, function (tr) { tr.setAttribute('role', 'row'); });
		Array.prototype.forEach.call(tab.tBodies, function (body) {
			Array.prototype.forEach.call(body.rows, function (tr) {
				Array.prototype.forEach.call(tr.cells, function (td, i) {
					td.setAttribute('role', td.tagName === 'TH' ? 'rowheader' : 'cell');
					if (headers[i]) { td.setAttribute('data-popisek', headers[i]); }
				});
			});
		});
	});

	// Warning before leaving a form with unsaved changes
	document.querySelectorAll('form.formular').forEach(function (form) {
		var changed = false;
		form.addEventListener('input', function () { changed = true; });
		form.addEventListener('change', function () { changed = true; });
		form.addEventListener('submit', function () { changed = false; });
		window.addEventListener('beforeunload', function (e) {
			if (changed) { e.preventDefault(); e.returnValue = ''; }
		});
	});
	/* ---------- small handlers instead of inline scripts (the admin has a Content-Security-Policy without 'unsafe-inline') ---------- */

	// data-aktivni-kdyz="pole=hodnota": fields inside the block are enabled only when the form field has the given value
	// (the number of days only for the frequency „jednou za N dní“ (once every N days), the page selection only for
	// „jen na vybraných místech“ (only in selected places))
	var dependent = document.querySelectorAll('[data-aktivni-kdyz]');
	var refreshDependent = function () {
		dependent.forEach(function (block) {
			var condition = block.getAttribute('data-aktivni-kdyz').split('=');
			var field = block.closest('form') && block.closest('form').elements[condition[0]];
			// checkbox: a value only when it is checked („zobrazit=“ = unchecked)
			var value = field && field.type === 'checkbox' ? (field.checked ? field.value : '') : (field ? field.value : '');
			var isEnabled = !field || value === condition[1];
			block.querySelectorAll('input, select, textarea').forEach(function (i) { i.disabled = !isEnabled; });
			block.classList.toggle('neaktivni', !isEnabled);
		});
	};
	if (dependent.length) { document.addEventListener('change', refreshDependent); refreshDependent(); }

	// the header of a numeric column is aligned like the numbers below it (td.cislo cells in the first row)
	document.querySelectorAll('table.vypis').forEach(function (table) {
		var row = table.tBodies[0] && table.tBodies[0].rows[0];
		var header = table.tHead && table.tHead.rows[0];
		if (!row || !header || row.cells.length !== header.cells.length) { return; }
		Array.prototype.forEach.call(row.cells, function (cell, i) { if (cell.classList.contains('cislo')) { header.cells[i].classList.add('cislo'); } });
	});

	document.addEventListener('change', function (e) {
		var element = e.target;
		if (element.hasAttribute && element.hasAttribute('data-odeslat-pri-zmene') && element.form) { element.form.submit(); }
		if (element.hasAttribute && element.hasAttribute('data-ukaz-heslo')) {
			var password = document.getElementById(element.getAttribute('data-ukaz-heslo'));
			if (password) { password.type = element.checked ? 'text' : 'password'; }
		}
	});
	document.addEventListener('click', function (e) {
		if (e.target.closest && e.target.closest('[data-neklikat]')) { e.preventDefault(); }
	});
	// E-mail signature of a person (Collections, 2.10): the button copies the formatted signature and its plain text together,
	// so the mail client pastes the rich one; without the Clipboard API the preview is selected and copied the old way
	var signatureButton = document.querySelector('[data-kopirovat-podpis]');
	if (signatureButton) {
		signatureButton.addEventListener('click', function () {
			var preview = document.querySelector('[data-podpis-nahled]');
			var plain = document.querySelector('[data-podpis-text]');
			var html = preview.innerHTML, text = plain ? plain.value : preview.innerText;
			var done = function () { signatureButton.textContent = T('Copied'); };
			var select = function () {
				var range = document.createRange(); range.selectNodeContents(preview);
				var selection = window.getSelection(); selection.removeAllRanges(); selection.addRange(range);
				var copied = false;
				try { copied = document.execCommand('copy'); } catch (e) { /* nothing */ }
				if (copied) { selection.removeAllRanges(); done(); } else { signatureButton.textContent = T('Selected – press Ctrl+C (⌘C) to copy'); }
			};
			if (navigator.clipboard && window.ClipboardItem) {
				navigator.clipboard.write([new ClipboardItem({ 'text/html': new Blob([html], { type: 'text/html' }), 'text/plain': new Blob([text], { type: 'text/plain' }) })]).then(done, select);
			} else { select(); }
		});
	}
	// an address to hand over (the screen mode address, 2.11): a button with data-kopirovat="#id" copies the text of that element;
	// for a text field (a social post draft, 2.13) its current value, edits included
	document.querySelectorAll('[data-kopirovat]').forEach(function (button) {
		button.addEventListener('click', function () {
			var source = document.querySelector(button.getAttribute('data-kopirovat'));
			if (!source) { return; }
			var text = (/^(TEXTAREA|INPUT)$/.test(source.tagName) ? source.value : source.textContent).trim();
			var done = function () { button.textContent = T('Copied'); };
			var select = function () { // without the Clipboard API the address is selected for Ctrl+C
				var range = document.createRange(); range.selectNodeContents(source);
				var selection = window.getSelection(); selection.removeAllRanges(); selection.addRange(range);
				button.textContent = T('Selected – press Ctrl+C (⌘C) to copy');
			};
			if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(text).then(done, select); } else { select(); }
		});
	});
	// a schedule shows only the day field of its cadence (3.1.1): the weekday for weekly, the day of the month for monthly
	var cadence = document.getElementById('cadence');
	if (cadence) {
		var showDays = function () { document.querySelectorAll('[data-kadence]').forEach(function (row) { row.hidden = row.getAttribute('data-kadence') !== cadence.value; }); };
		cadence.addEventListener('change', showDays); showDays();
	}
	// the sidebar keeps the current section in view (3.1.1): on a short screen the lower groups are below the fold
	var sidebar = document.querySelector('.hlavicka'), activeItem = document.querySelector('.menu li.aktivni');
	if (sidebar && activeItem && sidebar.scrollHeight > sidebar.clientHeight && getComputedStyle(sidebar).overflowY === 'auto') {
		var below = activeItem.getBoundingClientRect().bottom - sidebar.getBoundingClientRect().bottom;
		if (below > 0) { sidebar.scrollTop += below + 48; }
	}
	// "Ask Claude" on the dashboard (3.1): an example request fills the box (to be changed before sending); "Copy for the
	// Claude app" copies the text with the site's address and lets the link open Claude in a new tab
	var askText = document.getElementById('ask-claude-text');
	if (askText) {
		document.querySelectorAll('[data-ask-claude-example]').forEach(function (example) {
			example.addEventListener('click', function () {
				askText.value = example.textContent.trim();
				askText.focus();
				askText.setSelectionRange(askText.value.length, askText.value.length);
			});
		});
		var askCopy = document.querySelector('[data-ask-claude-copy]');
		if (askCopy && navigator.clipboard && navigator.clipboard.writeText) {
			askCopy.addEventListener('click', function () {
				if (askText.value.trim() === '') { return; }
				navigator.clipboard.writeText(askCopy.getAttribute('data-prompt').replace('{text}', askText.value.trim())).then(function () {
					askCopy.textContent = askCopy.getAttribute('data-copied');
				}, function () { /* the link still opens Claude */ });
			});
		}
	}
	// imports in batches: the progress form submits itself (each submission is one batch) until the work is done
	var autoSubmit = document.querySelector('form[data-auto-odeslat]');
	if (autoSubmit) { setTimeout(function () { autoSubmit.requestSubmit ? autoSubmit.requestSubmit() : autoSubmit.submit(); }, parseInt(autoSubmit.getAttribute('data-auto-odeslat'), 10) || 1200); }
	/* ---------- command palette: Ctrl/⌘+K – sections, quick actions and news item search ---------- */

	var palette = document.getElementById('paleta');
	if (palette && typeof palette.showModal === 'function') {
		var popupFields = palette.querySelector('.paleta-pole');
		var popupList = palette.querySelector('.paleta-seznam');
		var popupCommands = [];
		try { popupCommands = JSON.parse(document.getElementById('paleta-data').textContent) || []; } catch (e) {}
		var popupNews = [];
		var popupSelected = 0;
		var popupTimer = null;
		var removeDiacritics = function (t) { return String(t).toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, ''); };

		var popupItems = function () {
			var q = removeDiacritics(popupFields.value.trim());
			var words = q.split(/\s+/).filter(Boolean);
			var statements = popupCommands.filter(function (p) {
				var whereParts = removeDiacritics(p.n + ' ' + p.s);
				return words.every(function (s) { return whereParts.indexOf(s) !== -1; });
			});
			return (q === '' ? statements.slice(0, 9) : statements.slice(0, 7)).concat(q === '' ? [] : popupNews);
		};
		var popupRender = function () {
			var items = popupItems();
			popupSelected = Math.max(0, Math.min(popupSelected, items.length - 1));
			popupList.textContent = '';
			items.forEach(function (p, i) {
				var li = document.createElement('li');
				li.setAttribute('role', 'option');
				li.setAttribute('aria-selected', i === popupSelected ? 'true' : 'false');
				var a = document.createElement('a');
				a.href = p.u;
				a.textContent = p.n;
				var s = document.createElement('small');
				s.textContent = p.s;
				a.appendChild(s);
				li.appendChild(a);
				li.addEventListener('mousemove', function () { if (popupSelected !== i) { popupSelected = i; popupRender(); } });
				popupList.appendChild(li);
			});
			if (items.length === 0) {
				var nothing = document.createElement('li');
				nothing.className = 'paleta-nic';
				nothing.textContent = T('Nothing like that here.');
				popupList.appendChild(nothing);
			}
			var selected = popupList.querySelector('[aria-selected="true"]');
			if (selected && selected.scrollIntoView) { selected.scrollIntoView({ block: 'nearest' }); }
		};
		var popupOpen = function () {
			if (palette.open) { return; }
			popupFields.value = '';
			popupNews = [];
			popupSelected = 0;
			popupRender();
			palette.showModal();
			popupFields.focus();
		};

		document.addEventListener('keydown', function (e) {
			if ((e.ctrlKey || e.metaKey) && !e.altKey && (e.key === 'k' || e.key === 'K')) {
				e.preventDefault();
				if (palette.open) { palette.close(); } else { popupOpen(); }
			}
		});
		document.addEventListener('click', function (e) {
			if (e.target.closest && e.target.closest('[data-paleta]')) { popupOpen(); }
			if (e.target === palette) { palette.close(); } // click outside the dialog
		});
		popupFields.addEventListener('input', function () {
			popupSelected = 0;
			popupRender();
			clearTimeout(popupTimer);
			var q = popupFields.value.trim();
			var address = palette.getAttribute('data-clanky');
			if (!address || q.length < 2) { popupNews = []; return; }
			popupTimer = setTimeout(function () {
				fetch(address + '&q=' + encodeURIComponent(q), { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
					if (popupFields.value.trim() !== q) { return; } // typing continued in the meantime
					popupNews = (d.clanky || []).map(function (c) { return { n: c.titulek, u: c.url, s: c.vydany ? T('news item') : T('news item – unpublished') }; });
					popupRender();
				}).catch(function () {});
			}, 200);
		});
		popupFields.addEventListener('keydown', function (e) {
			var count = popupItems().length;
			if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
				e.preventDefault();
				popupSelected = count === 0 ? 0 : (popupSelected + (e.key === 'ArrowDown' ? 1 : count - 1)) % count;
				popupRender();
			} else if (e.key === 'Enter') {
				e.preventDefault();
				var target = popupList.querySelector('[aria-selected="true"] a');
				if (target) { window.location.href = target.href; }
			}
		});
		// show ⌘K on a Mac
		if (/Mac|iPhone|iPad/.test(navigator.platform || '')) {
			Array.prototype.forEach.call(document.querySelectorAll('[data-paleta] kbd'), function (k) { k.textContent = '⌘K'; });
		}
	}
	// Dropdown menus (<details data-zavrit-mimo>): closed by a tap outside and by the Esc key
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
	// Media: crop focal point – a tap in the preview sets both fields (in percent)
	document.querySelectorAll('[data-ohnisko]').forEach(function (box) {
		var formEl = box.closest('form');
		box.addEventListener('click', function (e) {
			var r = box.getBoundingClientRect();
			var x = Math.round((e.clientX - r.left) / r.width * 100);
			var y = Math.round((e.clientY - r.top) / r.height * 100);
			formEl.elements.ohnisko_x.value = x;
			formEl.elements.ohnisko_y.value = y;
			box.querySelector('.ohnisko-bod').style.left = x + '%';
			box.querySelector('.ohnisko-bod').style.top = y + '%';
		});
	});
	// Media: image description (alt) right in the grid – saved on leaving the field, without reloading the page
	document.querySelectorAll('[data-popis-media]').forEach(function (field) {
		var previous = field.value;
		var token = document.querySelector('input[name="_csrf"]');
		field.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); field.blur(); } });
		field.addEventListener('change', function () {
			var data = new FormData();
			data.append('_csrf', token ? token.value : '');
			data.append('ido', field.getAttribute('data-popis-media'));
			data.append('popis', field.value);
			field.classList.remove('ulozeno', 'chyba');
			fetch(field.getAttribute('data-adresa'), { method: 'POST', body: data, credentials: 'same-origin' })
				.then(function (r) { return r.json(); })
				.then(function (j) { if (!j.ok) { throw new Error(j.chyba); } previous = field.value; field.classList.add('ulozeno'); })
				.catch(function () { field.value = previous; field.classList.add('chyba'); });
		});
	});
	// the sign-in is kept alive while the admin is open (otherwise after inactivity a form submit would fail and unsaved text would be lost)
	if (document.querySelector('form[method="post"]')) {
		setInterval(function () {
			if (document.visibilityState === 'visible') { fetch('admin.php?action=token', { credentials: 'same-origin' }).catch(function () { /* offline: nothing */ }); }
		}, 10 * 60 * 1000);
	}
	// a success message confirmed the save: unsaved copies of submitted forms (image/editor.js) are no longer needed
	if (document.querySelector('.hlaska-ok')) {
		try {
			Object.keys(localStorage).filter(function (k) { return k.indexOf('kaleta-koncept:') === 0; }).forEach(function (k) {
				var d = JSON.parse(localStorage.getItem(k) || 'null');
				if (d && d.odeslano && Date.now() - d.odeslano < 15 * 60 * 1000) { localStorage.removeItem(k); }
			});
		} catch (e) { /* storage unavailable */ }
	}

	// tooltips of charts and figures (data-tip): immediately on mouse hover, on keyboard focus and after a tap on a touch screen
	var tip = null, tipTarget = null;
	function showTip(el) {
		tipTarget = el;
		if (!tip) {
			tip = document.createElement('div');
			tip.className = 'tip';
			tip.setAttribute('role', 'tooltip');
			document.body.appendChild(tip);
		}
		tip.textContent = el.getAttribute('data-tip');
		tip.hidden = false;
		var r = (el.querySelector('[data-tip-kotva]') || el).getBoundingClientRect(); // chart bar: the tooltip above its height
		var x = Math.min(Math.max(r.left + r.width / 2, tip.offsetWidth / 2 + 8), window.innerWidth - tip.offsetWidth / 2 - 8);
		tip.style.left = x + 'px';
		tip.style.top = Math.max(r.top - 8, tip.offsetHeight + 8) + 'px';
	}
	function hideTip() { tipTarget = null; if (tip) { tip.hidden = true; } }
	document.addEventListener('pointerover', function (e) { var el = e.target.closest && e.target.closest('[data-tip]'); if (el) { showTip(el); } });
	document.addEventListener('pointerout', function (e) { var el = e.target.closest && e.target.closest('[data-tip]'); if (el && !el.contains(e.relatedTarget)) { hideTip(); } });
	document.addEventListener('focusin', function (e) { var el = e.target.closest && e.target.closest('[data-tip]'); if (el) { showTip(el); } });
	document.addEventListener('focusout', hideTip);
	window.addEventListener('scroll', function () { if (tipTarget) { showTip(tipTarget); } }, { passive: true }); // when the page scrolls, the tooltip moves with the element
})();
