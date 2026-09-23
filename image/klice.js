// MiroCMS - přihlašovací klíče (passkeys / WebAuthn): registrace v Můj účet a druhý krok přihlášení.
// Formulář s atributem data-klice nese adresu, kam se posílá; výzvu i ověření dělá server (Core\Passkey).
(function () {
	'use strict';

	function naBajty(text) {
		var b64 = text.replace(/-/g, '+').replace(/_/g, '/');
		while (b64.length % 4) { b64 += '='; }
		var znaky = atob(b64), pole = new Uint8Array(znaky.length);
		for (var i = 0; i < znaky.length; i++) { pole[i] = znaky.charCodeAt(i); }
		return pole.buffer;
	}

	function naText(bajty) {
		var pole = new Uint8Array(bajty), znaky = '';
		for (var i = 0; i < pole.length; i++) { znaky += String.fromCharCode(pole[i]); }
		return btoa(znaky).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
	}

	document.querySelectorAll('form[data-klice]').forEach(function (formular) {
		var adresa = formular.getAttribute('data-klice');
		var chyba = formular.querySelector('[data-klic-chyba]');
		var tlacitko = formular.querySelector('[data-klic-pridat], [data-klic-prihlasit]');
		if (!tlacitko) { return; }

		if (!window.PublicKeyCredential || !navigator.credentials || !window.isSecureContext) {
			tlacitko.disabled = true;
			var nepodporuje = formular.querySelector('[data-klic-nepodporuje]');
			if (nepodporuje) { nepodporuje.hidden = false; }
			return;
		}

		function ukazChybu(text) {
			if (chyba) { chyba.textContent = text; chyba.hidden = !text; }
			tlacitko.disabled = false;
		}

		function posli(pole) {
			var data = new FormData();
			data.append('_csrf', formular.querySelector('input[name="_csrf"]').value);
			Object.keys(pole).forEach(function (k) { data.append(k, pole[k]); });
			return fetch(adresa, { method: 'POST', body: data, credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
				.then(function (r) { return r.json(); })
				.then(function (j) { if (j && j.chyba) { throw new Error(j.chyba); } return j; });
		}

		tlacitko.addEventListener('click', function () {
			var registrace = tlacitko.hasAttribute('data-klic-pridat');
			tlacitko.disabled = true;
			ukazChybu('');
			tlacitko.disabled = true;

			posli(registrace ? { co: 'klic_moznosti' } : { krok: 'klic_moznosti' }).then(function (m) {
				m.challenge = naBajty(m.challenge);
				if (registrace) {
					m.user.id = naBajty(m.user.id);
					(m.excludeCredentials || []).forEach(function (c) { c.id = naBajty(c.id); });
					return navigator.credentials.create({ publicKey: m });
				}
				(m.allowCredentials || []).forEach(function (c) { c.id = naBajty(c.id); });
				return navigator.credentials.get({ publicKey: m });
			}).then(function (k) {
				var o = k.response, odpoved = { id: naText(k.rawId), clientDataJSON: naText(o.clientDataJSON) };
				if (registrace) {
					if (!o.getPublicKey || !o.getAuthenticatorData) { throw new Error(formular.querySelector('[data-klic-nepodporuje]').textContent); }
					odpoved.authenticatorData = naText(o.getAuthenticatorData());
					odpoved.publicKey = naText(o.getPublicKey());
					odpoved.publicKeyAlgorithm = o.getPublicKeyAlgorithm();
					return posli({ co: 'klic_uloz', odpoved: JSON.stringify(odpoved), nazev: formular.querySelector('[name="nazev"]').value });
				}
				odpoved.authenticatorData = naText(o.authenticatorData);
				odpoved.signature = naText(o.signature);
				return posli({ krok: 'klic', odpoved: JSON.stringify(odpoved) });
			}).then(function (j) {
				window.location.href = (j && j.kam) || window.location.href.split('#')[0];
			}).catch(function (e) {
				// zrušení dialogu uživatelem není chyba, kterou by bylo potřeba hlásit
				ukazChybu(e && e.name === 'NotAllowedError' ? '' : (e && e.message) || '');
			});
		});
	});
})();
