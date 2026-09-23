/* MiroCMS - skript webu pro čtenáře. Bez knihoven; vše je volitelné vylepšení, web funguje i bez JavaScriptu. */
(function () {
	'use strict';

	/* ---------- texty: česky v kódu, překlad jazykové verze posílá Front\Seo::hlava() v atributu data-texty značky <script> ---------- */

	var texty = {};
	try {
		var znacka = document.currentScript || document.querySelector('script[data-texty]');
		texty = JSON.parse((znacka && znacka.getAttribute('data-texty')) || '{}') || {};
	} catch (e) { texty = {}; }
	/* bez atributu (vlastní šablona načítá skript jinak) zůstanou texty česky */
	function T(cesky) { return typeof texty[cesky] === 'string' && texty[cesky] !== '' ? texty[cesky] : cesky; }
	function A(cesky) { return T(cesky).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;'); }

	/* ---------- prohlížečka fotek: fotogalerie i jednotlivé obrázky v textu článku ---------- */

	var okno = null, fotky = [], pozice = 0;

	function ukaz(i) {
		pozice = (i + fotky.length) % fotky.length;
		var zdroj = fotky[pozice];
		okno.querySelector('img').src = zdroj.getAttribute('src');
		okno.querySelector('img').alt = zdroj.alt;
		var popis = zdroj.alt || ((zdroj.closest('figure') || document).querySelector('figcaption') || {}).textContent || '';
		okno.querySelector('p').textContent = (fotky.length > 1 ? (pozice + 1) + ' / ' + fotky.length + (popis ? ' · ' : '') : '') + popis;
	}

	function otevri(seznam, index) {
		if (!okno) {
			okno = document.createElement('dialog');
			okno.className = 'mc-prohlizecka';
			okno.innerHTML = '<img alt=""><p aria-live="polite"></p><button type="button" data-krok="-1" aria-label="' + A('Předchozí fotka') + '">‹</button>'
				+ '<button type="button" data-krok="1" aria-label="' + A('Další fotka') + '">›</button><button type="button" data-zavrit aria-label="' + A('Zavřít') + '">×</button>';
			document.body.appendChild(okno);
			okno.addEventListener('click', function (e) {
				var krok = e.target.getAttribute('data-krok');
				if (krok) { ukaz(pozice + parseInt(krok, 10)); } else if (e.target.tagName !== 'IMG') { okno.close(); }
			});
			okno.addEventListener('keydown', function (e) {
				if (e.key === 'ArrowLeft') { ukaz(pozice - 1); }
				if (e.key === 'ArrowRight') { ukaz(pozice + 1); }
			});
			var start = null;
			okno.addEventListener('touchstart', function (e) { start = e.changedTouches[0].clientX; }, { passive: true });
			okno.addEventListener('touchend', function (e) {
				var posun = e.changedTouches[0].clientX - start;
				if (Math.abs(posun) > 50 && fotky.length > 1) { ukaz(pozice + (posun < 0 ? 1 : -1)); }
			}, { passive: true });
		}
		fotky = seznam;
		okno.querySelectorAll('[data-krok]').forEach(function (b) { b.hidden = fotky.length < 2; });
		ukaz(index);
		okno.showModal();
	}

	document.addEventListener('click', function (e) {
		var img = e.target;
		if (img.tagName !== 'IMG' || img.closest('a') || !img.closest('.clanek-text, .perex, figure.galerie')) { return; }
		var galerie = img.closest('figure.galerie');
		var seznam = Array.prototype.slice.call((galerie || img.closest('.clanek-text, .perex')).querySelectorAll(galerie ? 'img' : 'figure:not(.galerie) img'));
		if (seznam.indexOf(img) === -1) { seznam = [img]; }
		otevri(seznam, seznam.indexOf(img));
	});

	/* ---------- ukazatel průběhu čtení u delších článků ---------- */

	var textClanku = document.querySelector('[data-prubeh]') && document.querySelector('.clanek-text');
	if (textClanku) {
		var pruhCteni = document.createElement('div');
		pruhCteni.className = 'mc-prubeh';
		pruhCteni.setAttribute('aria-hidden', 'true');
		document.body.appendChild(pruhCteni);
		var ceka = false;
		var prekresliPrubeh = function () {
			ceka = false;
			var r = textClanku.getBoundingClientRect();
			var podil = Math.min(1, Math.max(0, -r.top / Math.max(1, r.height - window.innerHeight * 0.6)));
			pruhCteni.style.transform = 'scaleX(' + podil + ')';
		};
		window.addEventListener('scroll', function () { if (!ceka) { ceka = true; requestAnimationFrame(prekresliPrubeh); } }, { passive: true });
		prekresliPrubeh();
	}

	/* ---------- mobilní menu: pruh rubrik se na telefonu sbalí pod tlačítko ---------- */

	var pruh = document.querySelector('nav.rubriky-lista');
	if (pruh && pruh.children.length > 3 && window.matchMedia) {
		var tlMenu = document.createElement('button');
		tlMenu.type = 'button';
		tlMenu.className = 'mc-menu-tl';
		tlMenu.setAttribute('aria-expanded', 'false');
		tlMenu.innerHTML = '<span aria-hidden="true"></span>' + (pruh.getAttribute('aria-label') || 'Menu');
		pruh.id = pruh.id || 'mc-rubriky';
		tlMenu.setAttribute('aria-controls', pruh.id);
		pruh.parentNode.insertBefore(tlMenu, pruh);
		document.documentElement.classList.add('mc-ma-menu');
		tlMenu.addEventListener('click', function () {
			var otevrit = tlMenu.getAttribute('aria-expanded') !== 'true';
			tlMenu.setAttribute('aria-expanded', otevrit ? 'true' : 'false');
			pruh.classList.toggle('mc-otevrene', otevrit);
		});
	}

	/* ---------- sdílení článku: systémové sdílení (telefon) a kopírování odkazu ---------- */

	document.querySelectorAll('[data-sdilet]').forEach(function (tl) {
		if (!navigator.share) { return; }
		tl.hidden = false;
		tl.addEventListener('click', function () {
			navigator.share({ title: tl.getAttribute('data-titulek'), url: tl.getAttribute('data-adresa') }).catch(function () { /* čtenář sdílení zavřel */ });
		});
	});
	document.addEventListener('click', function (e) {
		var tl = e.target.closest && e.target.closest('[data-kopirovat]');
		if (!tl || !navigator.clipboard) { return; }
		var puvodni = tl.textContent;
		navigator.clipboard.writeText(tl.getAttribute('data-kopirovat')).then(function () {
			tl.textContent = tl.getAttribute('data-hotovo');
			setTimeout(function () { tl.textContent = puvodni; }, 2000);
		});
	});

	/* ---------- komentáře: reakce na komentář (views/front/komentare.php) ---------- */

	document.addEventListener('click', function (e) {
		var tl = e.target.closest && e.target.closest('[data-reagovat], [data-zrusit-reakci]');
		if (!tl) { return; }
		var f = tl.hasAttribute('data-reagovat') ? document.querySelector('.komentar-formular') : tl.closest('form');
		var info = f && f.querySelector('.komentar-reakce-info');
		if (!f || !info) { return; }
		if (tl.hasAttribute('data-reagovat')) {
			f.reakce_na.value = tl.getAttribute('data-reagovat');
			info.hidden = false;
			info.querySelector('strong').textContent = tl.getAttribute('data-jmeno');
			f.scrollIntoView({ behavior: 'smooth', block: 'center' });
			f.obsah.focus();
		} else {
			f.reakce_na.value = '0';
			info.hidden = true;
		}
	});

	/* ---------- přehrávač cizí služby se vloží až po kliknutí ---------- */

	document.addEventListener('click', function (e) {
		var tl = e.target.closest && e.target.closest('[data-vlozit]');
		if (!tl) { return; }
		var ram = document.createElement('iframe');
		ram.src = tl.getAttribute('data-vlozit');
		ram.title = tl.getAttribute('data-titulek') || '';
		ram.allow = 'autoplay; encrypted-media; picture-in-picture; fullscreen';
		ram.allowFullscreen = true;
		ram.loading = 'lazy';
		tl.replaceWith(ram);
	});

	/* ---------- živá reportáž: nové zápisy se načítají samy ---------- */

	var zive = document.querySelector('[data-zive]');
	if (zive) {
		var nacti = function () {
			if (document.hidden) { return; }
			var prvni = zive.querySelector('[data-zapis]');
			fetch(zive.getAttribute('data-zive') + '?od=' + (prvni ? prvni.getAttribute('data-zapis') : 0), { cache: 'no-store' })
				.then(function (r) { return r.json(); })
				.then(function (j) {
					if (j.html) { zive.querySelector('.mc-zive-zapisy').insertAdjacentHTML('afterbegin', j.html); }
					if (!j.bezi) { clearInterval(casovac); }
				}).catch(function () { /* další pokus za chvíli */ });
		};
		var casovac = setInterval(nacti, 30000);
		document.addEventListener('visibilitychange', nacti);
	}

	/* ---------- oznámení o nových článcích (Web Push) ---------- */

	var meta = document.querySelector('meta[name="mc-push"]');
	var bloky = document.querySelectorAll('[data-push]');
	if (meta && bloky.length && 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window) {
		var koren = meta.getAttribute('data-koren');
		var klic = meta.getAttribute('content');
		var naBajty = function (b64) {
			var t = atob((b64 + '===='.slice((b64.length + 3) % 4 + 1)).replace(/-/g, '+').replace(/_/g, '/'));
			return Uint8Array.from(t, function (z) { return z.charCodeAt(0); });
		};
		var posli = function (cesta, odber) {
			return fetch(koren + cesta, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(odber) });
		};
		var prekresli = function (odber, zprava) {
			bloky.forEach(function (b) {
				b.hidden = false;
				b.querySelector('[data-push-tl]').textContent = odber ? T('Vypnout oznámení') : T('Zapnout oznámení');
				b.querySelector('[data-push-tl]').classList.toggle('mc-tl-vedlejsi', !!odber);
				b.querySelector('[data-push-stav]').textContent = zprava || (odber ? T('Oznámení jsou v tomto prohlížeči zapnutá.') : '');
			});
		};
		navigator.serviceWorker.register(koren + 'sw.js', { scope: koren }).then(function (reg) {
			reg.pushManager.getSubscription().then(function (odber) { prekresli(odber); });
			bloky.forEach(function (b) {
				b.querySelector('[data-push-tl]').addEventListener('click', function () {
					reg.pushManager.getSubscription().then(function (odber) {
						if (odber) {
							return posli('push/zrusit', odber).then(function () { return odber.unsubscribe(); }).then(function () { prekresli(null, T('Oznámení jsou vypnutá.')); });
						}
						return reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: naBajty(klic) }).then(function (novy) {
							return posli('push/odber', novy).then(function (r) {
								if (!r.ok) { return novy.unsubscribe().then(function () { prekresli(null, T('Oznámení se nepodařilo zapnout. Zkuste to později.')); }); }
								prekresli(novy);
							});
						});
					}).catch(function () {
						prekresli(null, Notification.permission === 'denied' ? T('Oznámení máte pro tento web v prohlížeči zakázaná. Povolíte je v nastavení webu u adresního řádku.') : T('Oznámení se nepodařilo zapnout.'));
					});
				});
			});
		}).catch(function () { /* bez service workeru zůstane blok skrytý */ });
	}
})();
