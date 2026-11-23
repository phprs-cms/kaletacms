/* Kaleta - admin odds and ends. No libraries, no build step. */

(function () {
	'use strict';

	// translation of admin script texts: the window.KALETA_PREKLAD dictionary comes from image/jazyky/admin-<code>.js (Czech too); English is the source and has none
	window.T = function (s) { return (window.KALETA_PREKLAD || {})[s] || s; };
	var T = window.T;

	// date and time like date() in PHP, in the site's time zone (<html data-pasmo>): Czech 25. 9. 2026 09:31, English 25 Sep 2026 09:31, Spanish 25/09/2026 09:31
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
		var lang = document.documentElement.lang, pad = function (n) { return ('0' + n).slice(-2); };
		return (lang === 'en' ? c.day + ' ' + months[c.month - 1] + ' ' + c.year : lang === 'es' ? pad(c.day) + '/' + pad(c.month) + '/' + c.year : c.day + '. ' + c.month + '. ' + c.year) + ' ' + parseOpeningHours;
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

	// 3.32 (U46): a table wrapper that scrolls sideways (a wide table at 1024 px) can be reached and scrolled with the keyboard –
	// it becomes a focusable region named after its heading; one that fits is left alone (no extra stop for the Tab key)
	var scrollers = document.querySelectorAll('.tab-obal');
	var markScrollers = function () {
		Array.prototype.forEach.call(scrollers, function (box) {
			if (box.scrollWidth > box.clientWidth + 1) {
				if (!box.hasAttribute('tabindex')) { // a wrapper with its own tabindex keeps it
					var heading = null, el = box;
					while (el && !heading) {
						var prev = el.previousElementSibling;
						while (prev && !/^H[2-4]$/.test(prev.tagName)) { prev = prev.previousElementSibling; }
						heading = prev;
						el = el.parentElement;
						if (el && el.tagName === 'MAIN') { break; }
					}
					box.setAttribute('tabindex', '0');
					box.setAttribute('role', 'region');
					box.setAttribute('aria-label', heading ? heading.textContent.trim() : T('Table'));
				}
			} else if (box.getAttribute('tabindex') === '0' && box.getAttribute('role') === 'region') {
				box.removeAttribute('tabindex'); box.removeAttribute('role'); box.removeAttribute('aria-label');
			}
		});
	};
	if (scrollers.length) {
		markScrollers();
		window.addEventListener('resize', function () { clearTimeout(markScrollers.t); markScrollers.t = setTimeout(markScrollers, 150); });
	}

	// Warning before leaving a form with unsaved changes: form.formular gets the browser's own question when the page is left;
	// 3.44 (U63) form[data-unsaved] (the quote editor, the price grid, the pipeline stages) also asks in the admin's dialog when a
	// link on the page is followed – Stay, Leave without saving, and Save and go on where the link says how (data-unsaved-page
	// in the price grid: saves and opens that page). A submit that goes through (not stopped by a confirmation or a check) ends it.
	var guardedForms = Array.prototype.slice.call(document.querySelectorAll('form.formular, form[data-unsaved]'));
	var unsavedDialog = null;
	guardedForms.forEach(function (form) {
		var mark = function () {
			form.kaletaChanged = true;
			if (form.hasAttribute('data-unsaved')) { form.setAttribute('data-unsaved-changed', ''); }
		};
		form.addEventListener('input', mark);
		form.addEventListener('change', mark);
	});
	if (guardedForms.length) {
		// registered after the confirmation dialog's listener: a submit it (or another check) stopped keeps the changes marked
		document.addEventListener('submit', function (e) {
			if (!e.defaultPrevented && e.target.kaletaChanged) { e.target.kaletaChanged = false; e.target.removeAttribute('data-unsaved-changed'); }
		});
		window.addEventListener('beforeunload', function (e) {
			if (guardedForms.some(function (f) { return f.kaletaChanged; })) { e.preventDefault(); e.returnValue = ''; }
		});
		document.addEventListener('click', function (e) {
			var link = e.target.closest && e.target.closest('a[href]');
			if (!link || e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || link.target === '_blank' || link.hasAttribute('download')) { return; }
			var href = link.getAttribute('href');
			if (href.charAt(0) === '#' || /^(mailto|tel|javascript):/i.test(href)) { return; }
			var form = guardedForms.filter(function (f) { return f.kaletaChanged && f.hasAttribute('data-unsaved'); })[0];
			if (!form) { return; }
			e.preventDefault();
			if (!unsavedDialog) {
				unsavedDialog = document.createElement('dialog');
				unsavedDialog.className = 'potvrzeni';
				unsavedDialog.setAttribute('data-unsaved-dialog', '');
				unsavedDialog.innerHTML = '<p></p><div><button type="button" class="tl" data-unsaved-save></button> <button type="button" class="navigace nebezpecne" data-unsaved-leave></button> <button type="button" class="navigace" data-unsaved-stay></button></div>';
				unsavedDialog.querySelector('p').textContent = T('You have changes that are not saved yet.');
				unsavedDialog.querySelector('[data-unsaved-leave]').textContent = T('Leave without saving');
				unsavedDialog.querySelector('[data-unsaved-stay]').textContent = T('Stay on the page');
				unsavedDialog.querySelector('[data-unsaved-stay]').addEventListener('click', function () { unsavedDialog.close(); });
				document.body.appendChild(unsavedDialog);
			}
			var save = unsavedDialog.querySelector('[data-unsaved-save]'), page = link.getAttribute('data-unsaved-page');
			save.hidden = !page;
			save.textContent = T('Save and open page %s').replace('%s', page || '');
			save.onclick = function () {
				unsavedDialog.close();
				var field = form.querySelector('input[name="page"]');
				if (!field) { field = document.createElement('input'); field.type = 'hidden'; field.name = 'page'; form.appendChild(field); }
				field.value = page;
				if (form.requestSubmit) { form.requestSubmit(); } else { form.submit(); }
			};
			unsavedDialog.querySelector('[data-unsaved-leave]').onclick = function () {
				unsavedDialog.close();
				guardedForms.forEach(function (f) { f.kaletaChanged = false; });
				window.location.href = link.href;
			};
			unsavedDialog.showModal();
			(page ? save : unsavedDialog.querySelector('[data-unsaved-stay]')).focus();
		});
	}
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
	// 3.44 (U71): My work → Summary shows the chosen period at once (the Show button stays for use without JavaScript)
	var summaryPeriod = document.querySelector('form[data-team-summary-period] select[name="period"]');
	if (summaryPeriod) { summaryPeriod.addEventListener('change', function () { if (summaryPeriod.form.requestSubmit) { summaryPeriod.form.requestSubmit(); } else { summaryPeriod.form.submit(); } }); }
	var autoSubmit = document.querySelector('form[data-auto-odeslat]');
	if (autoSubmit) { setTimeout(function () { autoSubmit.requestSubmit ? autoSubmit.requestSubmit() : autoSubmit.submit(); }, parseInt(autoSubmit.getAttribute('data-auto-odeslat'), 10) || 1200); }
	/* ---------- command palette: Ctrl/⌘+K – sections, quick actions and (3.52, A4) every kind of record, searched on the server ---------- */

	var palette = document.getElementById('paleta');
	if (palette && typeof palette.showModal === 'function') {
		var popupFields = palette.querySelector('.paleta-pole');
		var popupList = palette.querySelector('.paleta-seznam');
		// the combobox roles only with the script that makes them true (without it the field is a plain text field)
		popupFields.setAttribute('role', 'combobox');
		popupFields.setAttribute('aria-expanded', 'true');
		popupFields.setAttribute('aria-autocomplete', 'list');
		var popupCommands = [];
		try { popupCommands = JSON.parse(document.getElementById('paleta-data').textContent) || []; } catch (e) {}
		var popupSearchUrl = palette.getAttribute('data-paleta-hledat') || ''; // admin.php?action=palette_search (Admin\Palette::search)
		var popupGroups = []; // the groups of the server's answer to popupAnswered
		var popupAnswered = '';
		var popupState = ''; // '' | 'searching' | 'error'
		var popupShown = []; // the rows as listed, in order (the selectable ones)
		var popupSelected = 0;
		var popupSelectedUrl = ''; // 3.52 (A5): the selection stays on its row when the answer comes, Enter never opens another one
		var popupTimer = null;
		var popupAbort = null;
		var popupRecentKey = 'kaleta-paleta-posledni';
		var popupSignIn = palette.getAttribute('data-paleta-prihlaseni') || ''; // "<user id>:<start of the sign-in>" (Core\Auth's login_at)
		var removeDiacritics = function (t) { return String(t).toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, ''); };

		// how well a name fits the search – the same as Admin\Palette::rank(): 0 the same, 1 the start, 2 the start of a word, 3 anywhere
		var popupRank = function (name, q) {
			var n = removeDiacritics(name).replace(/[^a-z0-9]+/g, ' ').trim();
			var w = removeDiacritics(q).replace(/[^a-z0-9]+/g, ' ').trim();
			if (w === '' || n === '') { return 3; }
			return n === w ? 0 : (n.indexOf(w) === 0 ? 1 : ((' ' + n).indexOf(' ' + w) !== -1 ? 2 : 3));
		};
		// 3.52 (A5): the last records and screens opened from the palette – in this browser only (localStorage), never on the server;
		// 3.52 review: only of this account and sign-in ({who, list}) – another person or a new sign-in never sees them
		var popupRecent = function () {
			try {
				var stored = JSON.parse(localStorage.getItem(popupRecentKey) || '{}') || {};
				if (popupSignIn === '' || stored.who !== popupSignIn) {
					if (popupSignIn !== '' && localStorage.getItem(popupRecentKey) !== null) { localStorage.removeItem(popupRecentKey); } // of someone else: forgotten
					return [];
				}
				var list = stored.list;
				return Array.isArray(list) ? list.filter(function (p) { return p && typeof p.n === 'string' && typeof p.u === 'string' && p.u.charAt(0) === '/' && p.u.charAt(1) !== '/'; }).slice(0, 8) : [];
			} catch (e) { return []; }
		};
		var popupRemember = function (p) {
			if (p.p || p.more) { return; } // a command that creates something, "+ N more"
			try {
				var list = popupRecent().filter(function (r) { return r.u !== p.u; });
				list.unshift({ n: p.n, u: p.u, s: p.s || '' });
				if (popupSignIn !== '') { localStorage.setItem(popupRecentKey, JSON.stringify({ who: popupSignIn, list: list.slice(0, 8) })); }
			} catch (e) {}
		};

		// the sections of the list: the commands that match (sections, actions, settings – with their keywords), then the groups of the server
		var popupSections = function () {
			var q = popupFields.value.trim();
			var words = removeDiacritics(q).split(/\s+/).filter(Boolean);
			if (q === '') {
				var recent = popupRecent();
				return (recent.length ? [{ heading: T('Recently opened'), items: recent }] : []).concat([{ heading: '', items: popupCommands.slice(0, 9) }]);
			}
			var commands = popupCommands.filter(function (p) {
				var whereParts = removeDiacritics(p.n + ' ' + p.s + ' ' + (p.k || ''));
				return words.every(function (s) { return whereParts.indexOf(s) !== -1; });
			}).map(function (p, i) { return { p: p, r: popupRank(p.n, q), i: i }; }).sort(function (a, b) { return a.r - b.r || a.i - b.i; });
			var sections = [{ heading: '', items: commands.slice(0, 7).map(function (c) { return c.p; }), best: commands.length ? commands[0].r : 3 }];
			if (popupAnswered === q) {
				popupGroups.forEach(function (g) {
					var items = (g.items || []).map(function (r) { return { n: r.title, u: r.url, s: r.note || '' }; });
					if (g.more && g.total > items.length) { items.push({ n: T('+ %s more').replace('%s', g.total - items.length), u: g.more, s: g.label, more: true }); }
					sections.push({ heading: g.label, items: items, best: items.length ? popupRank(items[0].n, q) : 3 });
				});
			}
			// 3.52 (A5): the exact name and the start of a name first – the server sends its groups in that order already
			return sections.map(function (s, i) { s.i = i; return s; }).sort(function (a, b) { return a.best - b.best || a.i - b.i; });
		};
		var popupSelect = function (i, scroll) {
			popupSelected = Math.max(0, Math.min(i, popupShown.length - 1));
			Array.prototype.forEach.call(popupList.querySelectorAll('[role="option"]'), function (li, j) { li.setAttribute('aria-selected', j === popupSelected ? 'true' : 'false'); });
			var item = popupShown[popupSelected];
			popupSelectedUrl = item ? item.u : '';
			if (item) { popupFields.setAttribute('aria-activedescendant', 'paleta-moznost-' + popupSelected); } else { popupFields.removeAttribute('aria-activedescendant'); }
			var li = document.getElementById('paleta-moznost-' + popupSelected);
			if (scroll && li && li.scrollIntoView) { li.scrollIntoView({ block: 'nearest' }); }
		};
		var popupNote = function (text) {
			var li = document.createElement('li');
			li.className = 'paleta-nic';
			li.setAttribute('role', 'presentation');
			li.textContent = text;
			popupList.appendChild(li);
		};
		var popupRender = function () {
			popupShown = [];
			popupList.textContent = '';
			popupSections().forEach(function (section) {
				if (section.heading && section.items.length) {
					var head = document.createElement('li');
					head.className = 'paleta-skupina';
					head.setAttribute('role', 'presentation');
					head.textContent = section.heading;
					popupList.appendChild(head);
				}
				section.items.forEach(function (p) {
					var i = popupShown.length;
					popupShown.push(p);
					var li = document.createElement('li');
					li.id = 'paleta-moznost-' + i;
					li.setAttribute('role', 'option');
					var a = document.createElement('a');
					a.href = p.u;
					a.tabIndex = -1;
					a.textContent = p.n;
					a.addEventListener('click', function (e) {
						if (p.p) { e.preventDefault(); popupPost(p.u); return; } // 3.45: "New quote" is a POST
						popupRemember(p);
					});
					var s = document.createElement('small');
					s.textContent = p.s || '';
					a.appendChild(s);
					li.appendChild(a);
					// 3.52 (A5): pointing at a row only moves the selection – the list is not drawn again under the mouse
					li.addEventListener('mousemove', function () { if (popupSelected !== i) { popupSelect(i, false); } });
					popupList.appendChild(li);
				});
			});
			if (popupState === 'searching') {
				popupNote(T('Searching…'));
			} else if (popupState === 'error') {
				popupNote(T('Search is not available – reload the page.'));
			} else if (popupShown.length === 0) {
				popupNote(T('Nothing like that here.'));
			}
			var index = -1;
			popupShown.forEach(function (p, i) { if (index === -1 && popupSelectedUrl !== '' && p.u === popupSelectedUrl) { index = i; } });
			popupSelect(index === -1 ? 0 : index, true);
		};
		// 3.45 (U70): a command that creates something (p: 1 in the palette data) goes as a POST form with the form token
		var popupPost = function (url) {
			var form = document.createElement('form');
			form.method = 'post';
			form.action = url;
			var token = document.createElement('input');
			token.type = 'hidden';
			token.name = '_csrf';
			token.value = palette.getAttribute('data-csrf') || '';
			form.appendChild(token);
			document.body.appendChild(form);
			form.submit();
		};
		// one request for every kind of record; a newer word cancels the older request (AbortController)
		var popupFetch = function (q) {
			if (popupAbort) { popupAbort.abort(); }
			popupAbort = typeof AbortController === 'function' ? new AbortController() : null;
			fetch(popupSearchUrl + '&q=' + encodeURIComponent(q), { credentials: 'same-origin', headers: { Accept: 'application/json' }, signal: popupAbort ? popupAbort.signal : undefined })
				.then(function (r) { if (!r.ok) { throw new Error('search'); } return r.json(); }) // signed out: the sign-in page is no JSON
				.then(function (d) {
					if (popupFields.value.trim() !== q) { return; } // typing continued in the meantime
					popupGroups = Array.isArray(d.groups) ? d.groups : [];
					popupAnswered = q;
					popupState = '';
					popupRender();
				}).catch(function (e) {
					if ((e && e.name === 'AbortError') || popupFields.value.trim() !== q) { return; }
					popupState = 'error';
					popupRender();
				});
		};
		var popupOpen = function () {
			if (palette.open) { return; }
			popupFields.value = '';
			popupGroups = [];
			popupAnswered = '';
			popupState = '';
			popupSelectedUrl = '';
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
			clearTimeout(popupTimer);
			if (popupAbort) { popupAbort.abort(); popupAbort = null; }
			popupSelectedUrl = '';
			var q = popupFields.value.trim();
			popupState = q.length >= 2 && popupSearchUrl !== '' ? 'searching' : ''; // 3.52 (A5): "Searching…", never an early "Nothing like that here."
			popupRender();
			if (popupState === 'searching') {
				popupTimer = setTimeout(function () { popupFetch(q); }, 200);
			}
		});
		popupFields.addEventListener('keydown', function (e) {
			var count = popupShown.length;
			if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
				e.preventDefault();
				popupSelect(count === 0 ? 0 : (popupSelected + (e.key === 'ArrowDown' ? 1 : count - 1)) % count, true);
			} else if (e.key === 'Enter') {
				e.preventDefault();
				var chosen = popupShown[popupSelected];
				if (!chosen) { return; }
				if (chosen.p) { popupPost(chosen.u); return; }
				popupRemember(chosen);
				window.location.href = chosen.u;
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

	// 3.23 (C12): Settings → General was split into tabs; a link to a field that moved (tab=general#additional_languages,
	// a bookmark or a note from before) goes on to the field's tab – data-pole-jinde is {element id: address of its tab}.
	// 3.31 (code scanning #44): only to an http(s) address of this same origin, and only its path and query – never a
	// protocol-relative "//host" (leading slashes are folded into one)
	var movedFields = document.querySelector('[data-pole-jinde]');
	if (movedFields && location.hash.length > 1) {
		var movedId = '', movedTo = null, movedUrl = null;
		try { movedId = decodeURIComponent(location.hash.slice(1)); movedTo = JSON.parse(movedFields.getAttribute('data-pole-jinde'))[movedId]; } catch (e) { movedTo = null; }
		if (typeof movedTo === 'string' && movedTo !== '' && !document.getElementById(movedId)) {
			try { movedUrl = new URL(movedTo, location.href); } catch (e) { movedUrl = null; }
			if (movedUrl && movedUrl.origin === location.origin && (movedUrl.protocol === 'https:' || movedUrl.protocol === 'http:')) {
				location.replace(movedUrl.pathname.replace(/^\/+/, '/') + movedUrl.search + '#' + encodeURIComponent(movedId));
			}
		}
	}
	// 3.47 (U68): My account in tabs – an old link with a fragment (#claude, #two-factor, #passkeys…) arrives on my details
	// without a tab; data-account-tabs is {fragment: address of its tab}. Same origin, path and query only, as above.
	var accountTabs = document.querySelector('[data-account-tabs]');
	if (accountTabs && location.hash.length > 1 && !/[?&]tab=/.test(location.search)) {
		var accountId = '', accountTo = null, accountUrl = null;
		try { accountId = decodeURIComponent(location.hash.slice(1)); accountTo = JSON.parse(accountTabs.getAttribute('data-account-tabs'))[accountId]; } catch (e) { accountTo = null; }
		if (typeof accountTo === 'string' && accountTo !== '' && !document.getElementById(accountId)) {
			try { accountUrl = new URL(accountTo, location.href); } catch (e) { accountUrl = null; }
			if (accountUrl && accountUrl.origin === location.origin && (accountUrl.protocol === 'https:' || accountUrl.protocol === 'http:')) {
				location.replace(accountUrl.pathname.replace(/^\/+/, '/') + accountUrl.search + '#' + encodeURIComponent(accountId));
			}
		}
	}
	// 3.47 (U68): the first day of an absence is the earliest last day – input[data-date-min-for] names the id of the last day,
	// which gets the minimum and, when it is empty or earlier, the same day
	document.addEventListener('change', function (e) {
		var from = e.target.closest && e.target.closest('input[data-date-min-for]');
		var to = from ? document.getElementById(from.getAttribute('data-date-min-for')) : null;
		if (!to || from.value === '') { return; }
		to.min = from.value;
		if (to.value === '' || to.value < from.value) { to.value = from.value; }
	});
	// 3.26: "@" in a comment offers the colleagues who may open the record – textarea[data-mentions] carries them as JSON
	// [{id, user, name}] (only those the server would accept; it checks again). Typing "@ja" lists the matches under the
	// field (by name or sign-in name, without diacritics); arrows move, Enter or Tab inserts "@signin-name ", Esc closes the
	// list only. Delegated, so the builder's Team tab (fields drawn by image/stavitel.js) gets it too.
	var mentionList = null, mentionField = null, mentionActive = -1, mentionCount = 0;
	var fold = function (s) { return String(s).normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase(); };
	function mentionQuery(field) {
		var before = field.value.slice(0, field.selectionStart);
		var m = /(^|[^\p{L}\p{N}._@-])@([a-zA-Z0-9._-]{0,40})$/u.exec(before);
		return m && field.selectionStart === field.selectionEnd ? { q: m[2], from: before.length - m[2].length - 1 } : null;
	}
	function mentionClose() {
		if (mentionList) { mentionList.hidden = true; mentionList.textContent = ''; }
		if (mentionField) { mentionField.removeAttribute('aria-activedescendant'); }
		mentionActive = -1;
	}
	function mentionMark(i) {
		var all = mentionList ? mentionList.querySelectorAll('[role=option]') : [];
		mentionActive = all.length ? (i + all.length) % all.length : -1;
		Array.prototype.forEach.call(all, function (o, j) { o.classList.toggle('aktivni', j === mentionActive); o.setAttribute('aria-selected', j === mentionActive ? 'true' : 'false'); });
		if (mentionActive >= 0) { mentionField.setAttribute('aria-activedescendant', all[mentionActive].id); }
	}
	function mentionPick(o) {
		var field = mentionField, found = mentionQuery(field);
		if (!found) { mentionClose(); return; }
		var insert = '@' + o.getAttribute('data-user') + ' ';
		field.value = field.value.slice(0, found.from) + insert + field.value.slice(field.selectionStart);
		field.selectionStart = field.selectionEnd = found.from + insert.length;
		mentionClose();
		field.focus();
	}
	function mentionShow(field) {
		var found = mentionQuery(field), people = [];
		try { people = JSON.parse(field.getAttribute('data-mentions') || '[]'); } catch (e) { people = []; }
		if (!found || !people.length) { mentionClose(); return; }
		if (mentionField !== field || !mentionList || mentionList.previousElementSibling !== field) {
			if (mentionList) { mentionList.remove(); }
			mentionField = field;
			mentionList = document.createElement('div');
			mentionList.className = 'zminky-navrhy';
			mentionList.id = 'zminky-navrhy-' + (++mentionCount);
			mentionList.setAttribute('role', 'listbox');
			mentionList.setAttribute('aria-label', T('People to mention'));
			field.setAttribute('aria-autocomplete', 'list');
			field.setAttribute('aria-controls', mentionList.id);
			field.insertAdjacentElement('afterend', mentionList);
		}
		var q = fold(found.q);
		var hits = people.filter(function (p) { return q === '' || fold(p.user).indexOf(q) === 0 || fold(p.name).split(/\s+/).some(function (w) { return w.indexOf(q) === 0; }); }).slice(0, 8);
		mentionList.textContent = '';
		hits.forEach(function (p, i) {
			var o = document.createElement('div');
			o.setAttribute('role', 'option');
			o.id = mentionList.id + '-' + i;
			o.setAttribute('data-user', p.user);
			o.appendChild(document.createElement('span')).textContent = p.name;
			o.appendChild(document.createElement('small')).textContent = '@' + p.user;
			o.addEventListener('mousedown', function (e) { e.preventDefault(); }); // the field keeps the focus
			o.addEventListener('click', function () { mentionPick(o); });
			mentionList.appendChild(o);
		});
		mentionList.hidden = !hits.length;
		mentionMark(hits.length ? 0 : -1);
	}
	document.addEventListener('input', function (e) {
		if (e.target.matches && e.target.matches('textarea[data-mentions]')) { mentionShow(e.target); }
	});
	document.addEventListener('keydown', function (e) {
		if (!mentionList || mentionList.hidden || e.target !== mentionField) { return; }
		if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
			e.preventDefault();
			mentionMark(mentionActive + (e.key === 'ArrowDown' ? 1 : -1));
		} else if ((e.key === 'Enter' || e.key === 'Tab') && mentionActive >= 0) {
			e.preventDefault();
			mentionPick(mentionList.querySelectorAll('[role=option]')[mentionActive]);
		} else if (e.key === 'Escape') {
			e.preventDefault(); // the first Esc closes the list, a second one the dialog
			e.stopPropagation();
			mentionClose();
		}
	}, true);
	document.addEventListener('focusout', function (e) { if (e.target === mentionField) { mentionClose(); } });
	/* ---------- 3.31, 3.47 (U65): the boards – drag and drop and the keyboard on top of the move forms that work without them ---------- */
	// One behaviour for the Pipeline ([data-pipeline-board]) and the board of a position in Applications ([data-applications-board]).
	// Each card has its own form (a select + Move). Here: a card is dragged to a column, or its handle is picked up with Space,
	// the arrows choose the column and Space drops it (Esc cancels); the move is posted as JSON and the card and the counts
	// follow; every step is announced in the live region. A column that needs a reason (a lost deal, a rejected application):
	// the card's own form then shows the reason select (set to that column) and the person finishes with Move. With a mouse
	// the select and Move fold behind ⋯ for a lighter card; on a touch screen and without JavaScript the form stays open.
	// cfg: column (selector of a column), columnKey (its attribute with the key), card, form, field (the select's name and the
	// key of the JSON answer), reasonField, needsReason(col), label, count, cards, empty (selectors inside a column), announce
	// (selector of the live region), guideId (the instructions the handle points to), texts, after(card, json, col) (optional).
	function boardBehaviour(board, cfg) {
		var boardSay = document.querySelector(cfg.announce);
		var boardLine = cfg.message ? document.querySelector(cfg.message) : null; // 3.48 B (U30): the same words, visible
		var say = function (text) {
			if (boardLine) { boardLine.textContent = text; boardLine.hidden = false; }
			if (!boardSay) { return; }
			boardSay.textContent = '';
			setTimeout(function () { boardSay.textContent = text; }, 50); // emptied first, so the same message twice is read twice
		};
		var boardColumns = function () { return Array.prototype.slice.call(board.querySelectorAll(cfg.column)); };
		var columnName = function (col) { var l = col.querySelector(cfg.label); return l ? l.textContent : col.getAttribute(cfg.columnKey); };
		var cardTitle = function (card) { var h = card.querySelector('h3'); return h ? h.textContent.trim() : ''; };
		var recount = function () {
			boardColumns().forEach(function (col) {
				var cards = col.querySelectorAll(cfg.card);
				var count = col.querySelector(cfg.count);
				// 3.47 review F2: a column the server cut (Applications: BOARD_MAX cards) keeps the ones it leaves out in data-board-more
				if (count) { count.textContent = String(cards.length + (parseInt(count.getAttribute('data-board-more') || '0', 10) || 0)); }
				var empty = col.querySelector(cfg.empty);
				if (empty) { empty.hidden = cards.length > 0; }
			});
		};
		var setColumn = function (card, key, current) {
			var select = card.querySelector('select[name="' + cfg.field + '"]');
			if (!select) { return; }
			select.value = key;
			if (current) {
				Array.prototype.forEach.call(select.options, function (o) { if (o.value === key) { o.setAttribute('data-current', ''); } else { o.removeAttribute('data-current'); } });
			}
		};
		var place = function (card, col) {
			var from = card.closest(cfg.column);
			if (!col || from === col) { say(cfg.texts.stays.replace('%s', columnName(from))); return; }
			if (cfg.needsReason(col)) {
				// 3.48 B (U30): one server-rendered popover asks for the reason – Move sends it, Cancel leaves the card
				var pop = cfg.popover ? document.querySelector(cfg.popover) : null;
				if (pop && pop.showPopover && pop.hasAttribute('popover')) { // never a chip that carries the same attribute
					pending = { card: card, col: col };
					var popTitle = pop.querySelector('[data-board-pop-title]');
					if (popTitle) { popTitle.textContent = cfg.texts.reason.replace('%s', cardTitle(card)); }
					var popSelect = pop.querySelector('select');
					if (popSelect) { popSelect.selectedIndex = 0; }
					pop.showPopover();
					if (popSelect) { popSelect.focus(); }
					say(cfg.texts.reason.replace('%s', cardTitle(card)));
					return;
				}
				// the card's own form asks for the reason, Move finishes
				setColumn(card, col.getAttribute(cfg.columnKey), false);
				var fold = card.querySelector('details.obchod-vice');
				if (fold) { fold.open = true; } // 3.37: the form behind ⋯ opens with the reason
				var reason = card.querySelector('select[name="' + cfg.reasonField + '"]');
				if (reason) { reason.focus(); }
				say(cfg.texts.reason.replace('%s', cardTitle(card)));
				return;
			}
			send(card, col, '');
		};
		var send = function (card, col, reason) {
			var handle = card.querySelector('.obchod-uchyt');
			var form = card.querySelector(cfg.form);
			var data = new FormData(form);
			data.set(cfg.field, col.getAttribute(cfg.columnKey));
			data.set('format', 'json');
			if (reason) { data.set(cfg.reasonField, reason); } else { data.delete(cfg.reasonField); }
			card.classList.add('obchod-presouva');
			fetch(form.action, { method: 'POST', body: data, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
				card.classList.remove('obchod-presouva');
				if (!j.ok) { say(j.chyba || cfg.texts.failed); return; }
				col.querySelector(cfg.cards).prepend(card);
				setColumn(card, j[cfg.field], true);
				if (cfg.after) { cfg.after(card, j, col); }
				recount();
				if (handle) { handle.focus(); }
				say(j.zprava || T('Moved to %s.').replace('%s', columnName(col)));
			}).catch(function () {
				card.classList.remove('obchod-presouva');
				say(cfg.texts.failed);
			});
		};
		// 3.48 B (U30): the reason popover – Move sends the card with the chosen reason; Cancel (popovertargetaction) or Esc leaves it
		var pending = null;
		var reasonPop = cfg.popover ? document.querySelector(cfg.popover) : null;
		if (reasonPop) {
			var popMove = reasonPop.querySelector('[data-board-pop-move]');
			if (popMove) {
				popMove.addEventListener('click', function () {
					var chosen = reasonPop.querySelector('select');
					if (!pending || !chosen || !chosen.value) { if (chosen) { chosen.focus(); } return; }
					var job = pending;
					pending = null;
					reasonPop.hidePopover();
					send(job.card, job.col, chosen.value);
				});
			}
			reasonPop.addEventListener('toggle', function (e) {
				if (e.newState === 'closed' && pending) {
					var card = pending.card;
					pending = null;
					say(cfg.texts.stays.replace('%s', columnName(card.closest(cfg.column))));
					var h = card.querySelector('.obchod-uchyt');
					if (h) { h.focus(); }
				}
			});
		}
		// the mouse: drag a card to a column
		var dragged = null;
		Array.prototype.forEach.call(board.querySelectorAll(cfg.card), function (card) {
			card.setAttribute('draggable', 'true');
			// 3.37: a drag that starts on the title or the customer moves the card, not the link (the card is short now)
			Array.prototype.forEach.call(card.querySelectorAll('a'), function (link) { link.setAttribute('draggable', 'false'); });
			card.addEventListener('dragstart', function (e) {
				if (e.target !== card) { return; }
				dragged = card;
				card.classList.add('obchod-tazena');
				e.dataTransfer.effectAllowed = 'move';
				e.dataTransfer.setData('text/plain', card.id);
			});
			card.addEventListener('dragend', function () {
				card.classList.remove('obchod-tazena');
				dragged = null;
				boardColumns().forEach(function (col) { col.classList.remove('obchody-cil'); });
			});
			// the keyboard: a handle at the top of the card
			var handle = document.createElement('button');
			handle.type = 'button';
			handle.className = 'obchod-uchyt';
			handle.textContent = '⠿';
			handle.setAttribute('aria-label', T('Move “%s”').replace('%s', cardTitle(card)));
			handle.setAttribute('aria-describedby', cfg.guideId);
			handle.setAttribute('aria-pressed', 'false');
			handle.title = T('Drag, or press Space to move with the arrow keys');
			card.insertBefore(handle, card.firstChild);
			// 3.37 (U28): with a mouse the select and Move fold behind ⋯ – a lighter card
			var form = card.querySelector(cfg.form);
			if (form && window.matchMedia && window.matchMedia('(pointer: fine)').matches) {
				var fold = document.createElement('details');
				fold.className = 'obchod-vice';
				var summary = document.createElement('summary');
				summary.textContent = '⋯';
				summary.setAttribute('aria-label', cfg.texts.fold.replace('%s', cardTitle(card)));
				summary.title = cfg.texts.foldTitle;
				fold.appendChild(summary);
				var row = card.querySelector('.obchod-udaje'); // ⋯ ends the line of the card's facts
				if (!row) {
					row = document.createElement('div');
					row.className = 'obchod-udaje';
					form.parentNode.insertBefore(row, form);
				}
				row.appendChild(fold);
				fold.appendChild(form);
			}
		});
		boardColumns().forEach(function (col) {
			col.addEventListener('dragover', function (e) {
				if (!dragged) { return; }
				e.preventDefault();
				e.dataTransfer.dropEffect = 'move';
				col.classList.add('obchody-cil');
			});
			col.addEventListener('dragleave', function (e) { if (!col.contains(e.relatedTarget)) { col.classList.remove('obchody-cil'); } });
			col.addEventListener('drop', function (e) {
				if (!dragged) { return; }
				e.preventDefault();
				col.classList.remove('obchody-cil');
				place(dragged, col);
			});
		});
		var lifted = null;
		var liftedTo = 0;
		var mark = function () { boardColumns().forEach(function (col, i) { col.classList.toggle('obchody-cil', lifted !== null && i === liftedTo); }); };
		var putDown = function () {
			if (lifted) {
				lifted.classList.remove('obchod-zvednuta');
				var h = lifted.querySelector('.obchod-uchyt');
				if (h) { h.setAttribute('aria-pressed', 'false'); }
			}
			lifted = null;
			mark();
		};
		board.addEventListener('keydown', function (e) {
			var handle = e.target.closest && e.target.closest('.obchod-uchyt');
			if (!handle) { return; }
			var card = handle.closest(cfg.card);
			var cols = boardColumns();
			if (e.key === ' ' || e.key === 'Enter') {
				e.preventDefault();
				if (lifted !== card) {
					putDown();
					lifted = card;
					liftedTo = cols.indexOf(card.closest(cfg.column));
					card.classList.add('obchod-zvednuta');
					handle.setAttribute('aria-pressed', 'true');
					mark();
					say(T('Picked up “%s” in %s. Choose a stage with the arrow keys, Space drops it, Esc cancels.').replace('%s', cardTitle(card)).replace('%s', columnName(cols[liftedTo])));
				} else {
					var target = cols[liftedTo];
					putDown();
					place(card, target);
				}
			} else if (lifted === card && ['ArrowRight', 'ArrowDown', 'ArrowLeft', 'ArrowUp'].indexOf(e.key) !== -1) {
				e.preventDefault();
				liftedTo = Math.max(0, Math.min(cols.length - 1, liftedTo + (e.key === 'ArrowRight' || e.key === 'ArrowDown' ? 1 : -1)));
				mark();
				cols[liftedTo].scrollIntoView({ block: 'nearest', inline: 'nearest' });
				say(T('Over %s.').replace('%s', columnName(cols[liftedTo])));
			} else if (lifted === card && e.key === 'Escape') {
				e.preventDefault();
				putDown();
				say(T('Cancelled – “%s” stays where it was.').replace('%s', cardTitle(card)));
			}
		});
		board.addEventListener('focusout', function (e) { if (lifted && e.target.closest && e.target.closest('.obchod-uchyt') && !board.contains(e.relatedTarget)) { putDown(); } });
	}
	var board = document.querySelector('[data-pipeline-board]');
	if (board && window.fetch && window.FormData) {
		// 3.32 (U29): the totals read as the server writes them – Core\Deals::moneyFormat() in data-money (separators, sign before/after)
		var moneyFormat = {};
		try { moneyFormat = JSON.parse(board.getAttribute('data-money') || '{}'); } catch (e) { moneyFormat = {}; }
		var money = function (cents, currency) {
			var whole = Math.floor(cents / 100), rest = cents % 100;
			var text = String(whole).replace(/\B(?=(\d{3})+(?!\d))/g, moneyFormat.thousands || ' ') + (rest ? (moneyFormat.decimal || ',') + String(100 + rest).slice(1) : '');
			var affix = (moneyFormat.affixes && moneyFormat.affixes[currency]) || ['', ' ' + currency];
			return affix[0] + text + affix[1];
		};
		var sums = function () {
			Array.prototype.forEach.call(board.querySelectorAll('section[data-stage]'), function (col) {
				var out = col.querySelector('[data-stage-sums]');
				if (!out) { return; }
				var by = {};
				Array.prototype.forEach.call(col.querySelectorAll('[data-deal]'), function (card) {
					var value = card.getAttribute('data-value'), currency = card.getAttribute('data-currency');
					if (!value || !currency) { return; }
					var parts = value.split('.');
					by[currency] = (by[currency] || 0) + parseInt(parts[0], 10) * 100 + parseInt(((parts[1] || '') + '00').slice(0, 2), 10);
				});
				out.textContent = Object.keys(by).sort().map(function (c) { return money(by[c], c); }).join(' · ');
			});
		};
		boardBehaviour(board, {
			column: 'section[data-stage]', columnKey: 'data-stage', card: '[data-deal]', form: 'form[data-deal-move]', field: 'stage', reasonField: 'lost_reason',
			needsReason: function (col) { return col.getAttribute('data-kind') === 'lost'; },
			label: '[data-stage-label]', count: '[data-stage-count]', cards: '[data-stage-cards]', empty: '[data-stage-empty]', announce: '[data-pipeline-announce]', guideId: 'obchody-navod',
			popover: '[data-pipeline-reason]', message: '[data-pipeline-message]', // 3.48 B (U30)
			texts: { stays: T('The deal stays in %s.'), reason: T('Say why “%s” was lost, then press Move.'), failed: T('The deal could not be moved.'), fold: T('Stage and Move: “%s”'), foldTitle: T('Stage and Move') },
			after: function (card) {
				var days = card.querySelector('[data-deal-days]'); // 3.41: a new stage starts today
				if (days) { // 3.44 (U57): the short chip and its full text for screen readers and on hover
					days.title = T('In this stage since today');
					days.innerHTML = '';
					var shown = document.createElement('span'); shown.setAttribute('aria-hidden', 'true'); shown.textContent = T('today');
					var full = document.createElement('span'); full.className = 'navod-skryte'; full.textContent = days.title;
					days.append(shown, full);
				}
				sums();
			}
		});
	}
	// 3.40, 3.47 (U65): the board of one position in Applications – the same drag and keyboard, the status posted as JSON
	var applicationsBoard = document.querySelector('[data-applications-board]');
	if (applicationsBoard && window.fetch && window.FormData) {
		boardBehaviour(applicationsBoard, {
			column: 'section[data-board-status]', columnKey: 'data-board-status', card: '[data-board-card]', form: 'form[data-board-move]', field: 'status', reasonField: 'reject_reason',
			needsReason: function (col) { return col.hasAttribute('data-board-needs-reason'); },
			label: '[data-board-label]', count: '[data-board-count]', cards: '[data-board-cards]', empty: '[data-board-empty]', announce: '[data-board-announce]', guideId: 'uchazeci-navod',
			popover: '[data-board-reason-pop]', message: '[data-board-message]', // 3.48 B (U30)
			texts: { stays: T('The application stays in %s.'), reason: T('Say why “%s” was rejected, then press Move.'), failed: T('The application could not be moved.'), fold: T('Status and Move: “%s”'), foldTitle: T('Status and Move') },
			// 3.47 review F4: the chips of the old status go with it – "waiting" belongs to New, the reason to Rejected
			after: function (card, j) {
				if (j.status !== 'new') {
					card.classList.remove('obchod-po-terminu');
					Array.prototype.forEach.call(card.querySelectorAll('[data-board-waiting]'), function (chip) { chip.remove(); });
				}
				if (j.status !== 'rejected') {
					Array.prototype.forEach.call(card.querySelectorAll('[data-board-reason]'), function (chip) { chip.remove(); });
					var reason = card.querySelector('select[name="reject_reason"]');
					if (reason) { reason.selectedIndex = 0; }
				}
			}
		});
	}
	// 3.38 B: the quote editor asks the server for the totals as the lines change (Admin\Modules\Quotes::actionTotals) – the
	// arithmetic lives only in Core\Money, never here; Save computes and stores them anyway
	var quoteEditor = document.querySelector('[data-quote-totals]');
	if (quoteEditor) {
		var quoteTimer = null;
		var quoteTotals = function () {
			fetch(quoteEditor.getAttribute('data-quote-totals'), { method: 'POST', body: new FormData(quoteEditor), credentials: 'same-origin' })
				.then(function (r) { return r.json(); })
				.then(function (j) {
					if (j.error || !j.lines) { return; }
					[['[data-quote-sum-net]', j.net], ['[data-quote-sum-vat]', j.vat], ['[data-quote-sum-gross] strong', j.gross]].forEach(function (pair) {
						var el = quoteEditor.querySelector(pair[0]);
						if (el) { el.textContent = pair[1]; }
					});
					quoteEditor.querySelectorAll('[data-quote-line-net]').forEach(function (out, i) { out.textContent = j.lines[i] || ''; });
				})
				.catch(function () { /* the saved totals stay */ });
		};
		var quoteChanged = function (e) {
			if (!e.target.closest || !e.target.closest('[data-quote-lines] tbody, [data-quote-conditions]')) { return; }
			clearTimeout(quoteTimer);
			quoteTimer = setTimeout(quoteTotals, 400);
		};
		quoteEditor.addEventListener('input', quoteChanged);
		quoteEditor.addEventListener('change', quoteChanged);

		// 3.44 (U58): Up, Down and Remove of a line work in place (without them the buttons save and the server moves or drops the
		// line). A moved row keeps the focus on its button and the names say its new number; a removed row stays greyed with
		// Undo until Save, its hidden line_removed[] says so. The totals are asked again.
		var quoteBody = quoteEditor.querySelector('[data-quote-lines] tbody');
		var quoteStatus = quoteEditor.querySelector('[data-quote-line-status]');
		var renumber = function () {
			Array.prototype.forEach.call(quoteBody.rows, function (row, i) {
				row.querySelectorAll('[aria-label]').forEach(function (el) { el.setAttribute('aria-label', el.getAttribute('aria-label').replace(/\d+/, String(i + 1))); });
				row.querySelectorAll('[data-quote-line-number]').forEach(function (el) { el.textContent = el.textContent.replace(/\d+/, String(i + 1)); });
				row.querySelectorAll('[data-quote-move], [data-quote-remove]').forEach(function (b) { b.value = b.value.replace(/^\d+/, String(i)); });
				var up = row.querySelector('[data-quote-move="up"]'), down = row.querySelector('[data-quote-move="down"]');
				if (up) { up.disabled = i === 0; }
				if (down) { down.disabled = i === quoteBody.rows.length - 1; }
			});
		};
		if (quoteBody) {
			quoteBody.addEventListener('click', function (e) {
				var button = e.target.closest('[data-quote-move], [data-quote-remove]');
				if (!button) { return; }
				e.preventDefault();
				var row = button.closest('tr');
				if (button.hasAttribute('data-quote-move')) {
					var up = button.getAttribute('data-quote-move') === 'up';
					var other = up ? row.previousElementSibling : row.nextElementSibling;
					if (!other) { return; }
					quoteBody.insertBefore(row, up ? other : other.nextElementSibling);
					renumber();
					if (button.disabled) { (row.querySelector('[data-quote-move]:not([disabled])') || button).focus(); } else { button.focus(); }
					if (quoteStatus) { quoteStatus.textContent = T('Line moved to position %s.').replace('%s', String(Array.prototype.indexOf.call(quoteBody.rows, row) + 1)); }
				} else {
					var flag = row.querySelector('input[name="line_removed[]"]'), removed = flag.value !== '1';
					flag.value = removed ? '1' : '0';
					row.classList.toggle('nabidka-odebrano', removed);
					button.querySelector('[data-quote-remove-label]').textContent = button.getAttribute(removed ? 'data-undo' : 'data-remove');
					if (quoteStatus) { quoteStatus.textContent = removed ? T('The line is removed when you save.') : T('The line stays.'); }
				}
				quoteEditor.kaletaChanged = true;
				quoteEditor.setAttribute('data-unsaved-changed', '');
				clearTimeout(quoteTimer);
				quoteTimer = setTimeout(quoteTotals, 100);
			});
		}

		// 3.44 (U60): Save and send checks here what the send dialog would refuse – a customer, an e-mail, a line, a price on
		// every line – marks the fields and says it above them; the server refuses the same (Quotes::sendRefusal) as the safety net
		var quoteCheck = quoteEditor.querySelector('[data-quote-send-check]');
		quoteEditor.addEventListener('submit', function (e) {
			if (!quoteCheck) { return; }
			quoteCheck.hidden = true;
			quoteEditor.querySelectorAll('[aria-invalid="true"]').forEach(function (el) { el.removeAttribute('aria-invalid'); el.removeAttribute('aria-describedby'); });
			if (!e.submitter || !e.submitter.hasAttribute('data-quote-send')) { return; }
			var value = function (name) { var el = quoteEditor.elements[name]; return el && el.value ? el.value.trim() : ''; };
			var chosen = ['contact_id', 'company_id'].some(function (name) { var el = quoteEditor.elements[name]; return el && el.value !== '0' && el.value !== (el.getAttribute('data-saved') || '0'); });
			var problems = [], fields = [];
			if (!chosen && value('customer_name') === '' && value('customer_company') === '') { problems.push('customer'); fields.push('customer_name', 'customer_company'); }
			if (!chosen && value('customer_email') === '') { problems.push('email'); fields.push('customer_email'); }
			var rows = quoteBody ? Array.prototype.filter.call(quoteBody.rows, function (r) { return !r.classList.contains('nabidka-odebrano'); }) : [];
			var adding = value('add_product') !== '' || value('add_own') !== '' || value('add_free_name') !== '';
			if (!rows.length && !adding) { problems.push('lines'); }
			var unpriced = rows.filter(function (r) { var p = r.querySelector('input[name="line_price[]"]'); return p && p.value.trim() === ''; });
			if (unpriced.length) { problems.push('prices'); }
			if (!problems.length) { return; }
			e.preventDefault();
			quoteCheck.querySelectorAll('[data-check]').forEach(function (li) { li.hidden = problems.indexOf(li.getAttribute('data-check')) === -1; });
			quoteCheck.hidden = false;
			var marked = fields.map(function (name) { return quoteEditor.elements[name]; }).filter(Boolean);
			unpriced.forEach(function (r) { marked.push(r.querySelector('input[name="line_price[]"]')); });
			marked.forEach(function (el) { el.setAttribute('aria-invalid', 'true'); el.setAttribute('aria-describedby', quoteCheck.id); });
			quoteCheck.scrollIntoView({ block: 'center' });
			(marked[0] || quoteCheck).focus({ preventScroll: true });
		});
	}

	// 3.44 (U61): the send dialog's preview of the e-mail follows the subject and the message as they are typed
	var mailPreview = document.querySelector('[data-quote-mail-preview]');
	if (mailPreview) {
		var sendForm = mailPreview.closest('form');
		var mirror = function () {
			var subject = sendForm && sendForm.elements.subject, message = sendForm && sendForm.elements.message;
			if (subject) { mailPreview.querySelector('[data-quote-mail-subject]').textContent = subject.value.split('{number}').join(mailPreview.getAttribute('data-number')); }
			if (message) { mailPreview.querySelector('[data-quote-mail-message]').textContent = message.value; }
		};
		if (sendForm) { sendForm.addEventListener('input', mirror); }
	}
	/* ---------- 3.49 C: choosing by search – select[data-pick] and input[data-pick] (views/admin/pick.php) ---------- */
	// The form control stays the value carrier and, without this script, the whole control. A select is hidden behind a
	// text field (role="combobox", it takes over the select's id and required) with a listbox under it; the product field
	// (an input with a datalist) keeps its input and loses only the datalist. Under two characters the select's own options
	// (the datalist's) are filtered; from two the search address is asked (&q=…&pick=1) after 200 ms and a stale answer is
	// dropped (the palette's guard). A row outside the options is appended to the select and chosen, then "change" is fired
	// on it – the server, the quote editor and the unsaved-changes guard see nothing new; typing a search changes nothing
	// until a row is chosen. Special options (data-pick-special: "—", "The enquiry's customer") stay at the top with their
	// values. The deal form's details[data-pick-new] ("New customer…") is the last row: it opens and gets the focus.
	// Arrows, Enter (only a chosen row – with a search typed it never sends the form), Escape, Tab; a live region says how
	// many results. pickSources: data-pick => the key of the answer's rows ({id|value, name, note, size?}) – unit 4460 keeps
	// it equal to the values the views write.
	var pickSources = { contacts: 'contacts', companies: 'companies', deals: 'deals', products: 'products', files: 'files' };
	var pickNumber = 0;
	var pickFold = function (t) { return String(t).toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, ''); };
	Array.prototype.forEach.call(document.querySelectorAll('[data-pick]'), function (control) {
		var source = pickSources[control.getAttribute('data-pick')];
		var address = control.getAttribute('data-pick-url');
		var isSelect = control.tagName === 'SELECT';
		if (!source || !address || control.kaletaPick) { return; }
		control.kaletaPick = true;
		pickNumber += 1;
		var datalist = isSelect ? null : control.list;
		var field = isSelect ? document.createElement('input') : control;
		var wrap = document.createElement('span');
		var list = document.createElement('ul');
		var status = document.createElement('span');
		var row = control.closest('.radek, .zaznam-pole');
		var newBox = isSelect && row ? row.querySelector('[data-pick-new]') : null;
		var items = [], active = -1, timer = null, asked = '';
		wrap.className = 'vyber';
		list.className = 'vyber-seznam';
		list.id = 'vyber-' + pickNumber + '-seznam';
		list.setAttribute('role', 'listbox');
		list.hidden = true;
		status.className = 'navod-skryte';
		status.setAttribute('role', 'status');
		control.parentNode.insertBefore(wrap, control);
		if (isSelect) {
			field.type = 'text';
			field.className = 'textpole';
			field.id = control.id;
			control.id = control.id + '-hodnota';
			field.placeholder = T('Type to search');
			field.required = control.required;
			control.required = false;
			control.hidden = true;
			control.tabIndex = -1;
			wrap.appendChild(field);
			wrap.appendChild(control);
		} else {
			control.removeAttribute('list');
			wrap.appendChild(control);
		}
		wrap.appendChild(list);
		wrap.appendChild(status);
		field.setAttribute('role', 'combobox');
		field.setAttribute('autocomplete', 'off');
		field.setAttribute('aria-autocomplete', 'list');
		field.setAttribute('aria-expanded', 'false');
		field.setAttribute('aria-controls', list.id);

		var label = function (option) { return option ? (option.textContent.trim() === '—' ? '' : option.textContent.trim()) : ''; };
		var sync = function () { if (isSelect) { field.value = label(control.options[control.selectedIndex]); } };
		var specials = function () {
			return isSelect ? Array.prototype.filter.call(control.options, function (o) { return o.hasAttribute('data-pick-special'); })
				.map(function (o) { return { value: o.value, name: o.textContent.trim(), note: '' }; }) : [];
		};
		var local = function (q) {
			var words = pickFold(q).split(/\s+/).filter(Boolean);
			var options = isSelect ? Array.prototype.filter.call(control.options, function (o) { return !o.hasAttribute('data-pick-special'); }) : Array.prototype.slice.call(datalist ? datalist.options : []);
			return options.map(function (o) { return isSelect ? { value: o.value, name: o.textContent.trim(), note: '' } : { value: o.value, name: o.textContent.trim() || o.value, note: o.value }; })
				.filter(function (r) { var where = pickFold(r.name + ' ' + r.note); return words.every(function (w) { return where.indexOf(w) !== -1; }); })
				.slice(0, 50);
		};
		var close = function () {
			list.hidden = true;
			active = -1;
			field.setAttribute('aria-expanded', 'false');
			field.removeAttribute('aria-activedescendant');
		};
		var render = function (found) {
			items = specials().concat(found);
			if (newBox) { items.push({ create: true, name: T('New customer…'), note: '' }); }
			active = Math.min(active, items.length - 1);
			list.textContent = '';
			items.forEach(function (item, i) {
				var li = document.createElement('li');
				li.id = list.id + '-' + i;
				li.setAttribute('role', 'option');
				li.setAttribute('aria-selected', i === active ? 'true' : 'false');
				li.textContent = item.name;
				if (item.note) {
					li.appendChild(document.createTextNode(' ')); // the option's name read with a space before the note
					var note = document.createElement('small');
					note.textContent = item.note;
					li.appendChild(note);
				}
				li.addEventListener('mousedown', function (e) { e.preventDefault(); }); // the field keeps the focus
				li.addEventListener('click', function () { choose(item); });
				list.appendChild(li);
			});
			if (active >= 0) {
				field.setAttribute('aria-activedescendant', list.id + '-' + active);
				if (list.children[active].scrollIntoView) { list.children[active].scrollIntoView({ block: 'nearest' }); }
			} else {
				field.removeAttribute('aria-activedescendant');
			}
			list.hidden = items.length === 0;
			field.setAttribute('aria-expanded', items.length ? 'true' : 'false');
			status.textContent = found.length ? T('%d results').replace('%d', String(found.length)) : T('No result');
		};
		var show = function () {
			var q = field.value.trim();
			clearTimeout(timer);
			render(local(q));
			if (q.length < 2) { asked = ''; return; }
			asked = q;
			timer = setTimeout(function () {
				fetch(address + (address.indexOf('?') === -1 ? '?' : '&') + 'q=' + encodeURIComponent(q) + '&pick=1', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
					.then(function (r) { return r.ok ? r.json() : null; })
					.then(function (d) {
						if (!d || asked !== q || field.value.trim() !== q || document.activeElement !== field) { return; } // typing went on, or the list was left
						render((d[source] || []).map(function (r) { return { value: String(r.id !== undefined ? r.id : r.value), name: String(r.name || ''), note: String(r.note || ''), bytes: r.size !== undefined ? String(r.size) : '' }; }));
					})
					.catch(function () { /* the options found here stay */ });
			}, 200);
		};
		var choose = function (item) {
			clearTimeout(timer);
			asked = '';
			close();
			if (item.create) {
				var none = Array.prototype.filter.call(control.options, function (o) { return o.hasAttribute('data-pick-special') && o.textContent.trim() === '—'; })[0];
				if (none && control.value !== none.value) { control.value = none.value; control.dispatchEvent(new Event('change', { bubbles: true })); }
				sync();
				newBox.open = true;
				var first = newBox.querySelector('input');
				if (first) { first.focus(); }
				return;
			}
			if (isSelect) {
				var option = Array.prototype.filter.call(control.options, function (o) { return o.value === item.value; })[0];
				if (!option) {
					option = document.createElement('option');
					option.value = item.value;
					option.textContent = item.name;
					if (item.bytes) { option.setAttribute('data-bytes', item.bytes); } // a searched file is measured too (the attachments' check)
					control.appendChild(option);
				}
				var changed = control.value !== item.value;
				control.value = item.value;
				sync();
				if (changed) { control.dispatchEvent(new Event('change', { bubbles: true })); }
			} else {
				field.value = item.value;
				field.dispatchEvent(new Event('change', { bubbles: true }));
			}
		};
		var leave = function () {
			close();
			if (!isSelect) { return; }
			if (field.value.trim() === '') {
				var none = Array.prototype.filter.call(control.options, function (o) { return o.hasAttribute('data-pick-special') && o.textContent.trim() === '—'; })[0];
				if (none && control.value !== none.value) { choose({ value: none.value, name: '' }); return; }
			}
			sync(); // a search left without a choice: the field shows the chosen value again
		};

		if (isSelect) {
			// typing a search is not a change of the form – only the chosen row is (the select's change event)
			['input', 'change'].forEach(function (type) { field.addEventListener(type, function (e) { e.stopPropagation(); }); });
			control.addEventListener('change', sync);
			window.addEventListener('pageshow', function () { if (document.activeElement !== field) { sync(); } }); // back and the back-forward cache: the field follows the select
			sync();
		}
		field.addEventListener('input', function () { active = -1; show(); });
		field.addEventListener('click', function () { if (list.hidden) { show(); } });
		field.addEventListener('focus', function () {
			// on a phone the field goes to the top so the list is not under the keyboard
			if (window.matchMedia && window.matchMedia('(max-width: 767px)').matches && field.scrollIntoView) { field.scrollIntoView({ block: 'start', behavior: 'smooth' }); }
		});
		field.addEventListener('blur', leave);
		field.addEventListener('keydown', function (e) {
			if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
				e.preventDefault();
				if (list.hidden) { active = -1; show(); if (e.altKey) { return; } }
				if (!items.length) { return; }
				active = e.key === 'ArrowDown' ? (active + 1) % items.length : (active <= 0 ? items.length - 1 : active - 1);
				render(items.filter(function (i) { return !i.create; }).slice(specials().length));
			} else if (e.key === 'Enter' && !list.hidden && active >= 0) {
				e.preventDefault();
				choose(items[active]);
			} else if (e.key === 'Enter' && (!list.hidden || (isSelect && field.value.trim() !== label(control.options[control.selectedIndex])))) {
				// a search typed but no row chosen: Enter never sends the surrounding form (review C9) – the list stays or opens
				e.preventDefault();
				if (list.hidden) { show(); }
			} else if (e.key === 'Escape' && !list.hidden) {
				e.preventDefault();
				close();
				sync();
			}
		});
	});

	/* ---------- 3.49: saved reply (part A) ---------- */
	// select[data-saved-reply] (views/admin/enquiries/detail.php): choosing a saved reply asks the address in the attribute
	// (&id=…, GET, read-only) for it filled for this enquiry; the subject is replaced while it is still the default one, the
	// text when it is empty or still the drafted reply the page came with – otherwise the saved text goes after a blank line.
	// The no-script "Insert" (it reloads the page) is hidden.
	Array.prototype.forEach.call(document.querySelectorAll('select[data-saved-reply]'), function (select) {
		var form = document.querySelector('form[data-reply-form]');
		var subject = form && form.elements.subject, text = form && form.elements.text;
		var insert = document.querySelector('[data-saved-reply-insert]');
		if (!subject || !text) { return; }
		if (insert) { insert.hidden = true; }
		select.addEventListener('change', function () {
			if (!select.value) { return; }
			var address = select.getAttribute('data-saved-reply');
			fetch(address + (address.indexOf('?') === -1 ? '?' : '&') + 'id=' + encodeURIComponent(select.value), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
				.then(function (r) { return r.ok ? r.json() : null; })
				.then(function (d) {
					if (!d || typeof d.text !== 'string') { return; }
					if (subject.value === subject.defaultValue && d.subject) { subject.value = d.subject; }
					var current = text.value.trim();
					text.value = current === '' || text.value === text.defaultValue ? d.text : text.value.replace(/\s+$/, '') + '\n\n' + d.text;
					text.dispatchEvent(new Event('input', { bubbles: true }));
					text.focus();
				})
				.catch(function () { /* the person writes the reply themselves */ });
		});
	});

	// [data-attachments] (views/admin/attachments.php, a reply and the quote e-mail): a file chosen in "Add another file"
	// becomes a ticked row (the no-script "Add" is hidden); before the form is sent the files are counted and measured –
	// over data-max-files or data-max-bytes the submit is refused with a message before anything is uploaded, and with
	// files the confirmation names their number (data-attach-confirm, %2$d). The quote's preview lists the ticked files.
	Array.prototype.forEach.call(document.querySelectorAll('[data-attachments]'), function (box) {
		var form = box.closest('form'), list = box.querySelector('[data-attach-list]'), add = box.querySelector('select[data-attach-add]');
		var upload = box.querySelector('input[type="file"]'), error = box.querySelector('[data-attach-error]');
		var maxBytes = parseInt(box.getAttribute('data-max-bytes'), 10) || 0, maxFiles = parseInt(box.getAttribute('data-max-files'), 10) || 0;
		var plain = form ? form.getAttribute('data-potvrdit') : null, preview = form ? form.querySelector('[data-quote-mail-files]') : null;
		var addButton = box.querySelector('[data-attach-add-button]');
		if (!form || !list) { return; }
		if (addButton) { addButton.hidden = true; }
		var ticked = function () { return Array.prototype.filter.call(list.querySelectorAll('input[type="checkbox"]'), function (c) { return c.checked; }); };
		var showPreview = function () {
			if (!preview) { return; }
			var names = ticked().map(function (c) { return c.parentNode.textContent.trim(); });
			Array.prototype.forEach.call(upload ? upload.files : [], function (f) { names.push(f.name); });
			preview.hidden = names.length === 0;
			preview.querySelector('span').textContent = names.join(', ');
		};
		if (add) {
			add.addEventListener('change', function () {
				var value = add.value, option = add.options[add.selectedIndex];
				if (!value) { return; }
				var known = Array.prototype.filter.call(list.querySelectorAll('input[type="checkbox"]'), function (c) { return c.value === value; })[0];
				if (known) {
					known.checked = true;
				} else {
					var li = document.createElement('li'), label = document.createElement('label'), box2 = document.createElement('input');
					box2.type = 'checkbox';
					box2.name = 'internal_files[]';
					box2.value = value;
					box2.checked = true;
					box2.setAttribute('data-bytes', (option && option.getAttribute('data-bytes')) || '0');
					label.appendChild(box2);
					label.appendChild(document.createTextNode(' ' + (option ? option.textContent.trim() : value)));
					li.appendChild(label);
					list.appendChild(li);
				}
				add.value = '';
				add.dispatchEvent(new Event('change', { bubbles: true })); // the choice by search shows the empty field again
				showPreview();
			});
		}
		list.addEventListener('change', showPreview);
		if (upload) { upload.addEventListener('change', showPreview); }
		form.addEventListener('submit', function (e) {
			var files = ticked(), count = files.length, bytes = 0;
			files.forEach(function (c) { bytes += parseInt(c.getAttribute('data-bytes'), 10) || 0; });
			Array.prototype.forEach.call(upload ? upload.files : [], function (f) { count += 1; bytes += f.size; });
			var problem = maxFiles && count > maxFiles ? T('Up to %d files can go with one e-mail.').replace('%d', String(maxFiles))
				: maxBytes && bytes > maxBytes ? T('The attachments are too large together (%1$s, at most %2$s).').replace('%1$s', (bytes / 1048576).toFixed(1) + ' MB').replace('%2$s', (maxBytes / 1048576).toFixed(1) + ' MB') : '';
			if (error) { error.hidden = problem === ''; error.textContent = problem; }
			if (problem) {
				e.preventDefault();
				e.stopImmediatePropagation(); // no confirmation for a form that cannot go
				if (error) { error.scrollIntoView({ block: 'center' }); }
				return;
			}
			if (plain !== null) { form.setAttribute('data-potvrdit', count > 0 ? box.getAttribute('data-attach-confirm').replace('%2$d', String(count)) : plain); }
		});
	});

	/* ---------- 3.49: internal file box (part B) ---------- */
	// The box of a record (views/admin/internal_files/box.php) needs no code of its own: "Link a file" is a choice by search
	// above (data-pick="files", the answer's rows under "files"), and without this script a plain select.

})();
