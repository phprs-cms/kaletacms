// Kaleta - passkeys (WebAuthn): registration in "Můj účet" (My account), the second sign-in step, and since 3.35 the sign-in
// without a user name and password - a button and the autofill of the user-name field (conditional UI).
// A form with the data-klice attribute carries the URL it posts to; the server creates the challenge and verifies it (Core\Passkey).
(function () {
	'use strict';

	function toBytes(text) {
		var b64 = text.replace(/-/g, '+').replace(/_/g, '/');
		while (b64.length % 4) { b64 += '='; }
		var chars = atob(b64), field = new Uint8Array(chars.length);
		for (var i = 0; i < chars.length; i++) { field[i] = chars.charCodeAt(i); }
		return field.buffer;
	}

	function toText(bytes) {
		var field = new Uint8Array(bytes), chars = '';
		for (var i = 0; i < field.length; i++) { chars += String.fromCharCode(field[i]); }
		return btoa(chars).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
	}

	var supported = !!(window.PublicKeyCredential && navigator.credentials && window.isSecureContext);

	// the response of navigator.credentials.get() as the server reads it
	function assertion(k) {
		var o = k.response;
		return {
			id: toText(k.rawId), clientDataJSON: toText(o.clientDataJSON), authenticatorData: toText(o.authenticatorData),
			signature: toText(o.signature), userHandle: o.userHandle ? toText(o.userHandle) : ''
		};
	}

	function getOptions(m) {
		m.challenge = toBytes(m.challenge);
		(m.allowCredentials || []).forEach(function (c) { c.id = toBytes(c.id); });
		return m;
	}

	document.querySelectorAll('form[data-klice]').forEach(function (formEl) {
		var address = formEl.getAttribute('data-klice');
		var error = formEl.querySelector('[data-klic-chyba]');
		var buttons = formEl.querySelectorAll('[data-klic-pridat], [data-klic-prihlasit], [data-klic-bezhesla], [data-klic-potvrdit]');
		if (!buttons.length) { return; }

		if (!supported) {
			buttons.forEach(function (b) { b.disabled = true; });
			var unsupported = formEl.querySelector('[data-klic-nepodporuje]');
			if (unsupported) { unsupported.hidden = false; }
			return;
		}

		function showError(text) {
			if (error) { error.textContent = text; error.hidden = !text; }
			buttons.forEach(function (b) { b.disabled = false; });
		}

		function deliver(field) {
			var data = new FormData();
			data.append('_csrf', formEl.querySelector('input[name="_csrf"]').value);
			Object.keys(field).forEach(function (k) { data.append(k, field[k]); });
			return fetch(address, { method: 'POST', body: data, credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
				.then(function (r) { return r.json(); })
				.then(function (j) { if (j && j.chyba) { throw new Error(j.chyba); } return j; });
		}

		function done(j) {
			window.location.href = (j && j.kam) || window.location.href.split('#')[0];
		}

		// 3.35: the user-name field offers the site's passkeys as it is focused (conditional UI) where the browser can; the
		// request waits in the background and is renewed before its challenge expires on the server (5 minutes)
		var conditional = null, renew = null, settled = Promise.resolve(), busy = false;
		var passwordless = formEl.querySelector('[data-klic-bezhesla]');
		function startConditional() {
			if (!passwordless || !document.querySelector('input[autocomplete~="webauthn"]') || !PublicKeyCredential.isConditionalMediationAvailable) { return; }
			PublicKeyCredential.isConditionalMediationAvailable().then(function (available) {
				if (!available || busy) { return; } // a modal request started meanwhile
				stopConditional();
				var controller = new AbortController();
				conditional = controller;
				renew = window.setTimeout(startConditional, 240000);
				var chosen = false;
				settled = deliver({ krok: 'passkey_options' }).then(function (m) {
					if (controller.signal.aborted) { return null; }
					return navigator.credentials.get({ publicKey: getOptions(m), mediation: 'conditional', signal: controller.signal });
				}).then(function (k) {
					if (!k) { return null; }
					chosen = true;
					return deliver({ krok: 'passkey', odpoved: JSON.stringify(assertion(k)) }).then(done);
				}).catch(function (e) {
					if (controller.signal.aborted || (e && e.name === 'AbortError')) { return; }
					showError(e && e.name === 'NotAllowedError' ? '' : (e && e.message) || '');
					if (chosen) { startConditional(); } // a refused passkey: the autofill is offered again (never in a loop of failing requests)
				}).then(function () { return null; });
			});
		}
		// resolves once the aborted autofill request has really ended – the browser allows one WebAuthn request at a time
		function stopConditional() {
			if (renew) { window.clearTimeout(renew); renew = null; }
			if (conditional) { conditional.abort(); conditional = null; }
			return settled;
		}
		startConditional();

		buttons.forEach(function (button) {
			button.addEventListener('click', function () {
				var register = button.hasAttribute('data-klic-pridat');
				var withoutPassword = button.hasAttribute('data-klic-bezhesla');
				buttons.forEach(function (b) { b.disabled = true; });
				showError('');
				buttons.forEach(function (b) { b.disabled = true; });
				busy = true;
				var stopped = stopConditional(); // one WebAuthn request at a time: the modal one replaces the autofill

				// 3.35: "Confirm with your passkey" – the signed-in account's own passkey as the fresh proof (the OAuth consent)
				if (button.hasAttribute('data-klic-potvrdit')) {
					deliver({ krok: 'passkey_confirm_options' }).then(function (m) {
						return navigator.credentials.get({ publicKey: getOptions(m) });
					}).then(function (k) {
						return deliver({ krok: 'passkey_confirm', odpoved: JSON.stringify(assertion(k)) });
					}).then(done).catch(function (e) {
						showError(e && e.name === 'NotAllowedError' ? '' : (e && e.message) || '');
					});
					return;
				}

				if (withoutPassword) {
					stopped.then(function () {
						return deliver({ krok: 'passkey_options' });
					}).then(function (m) {
						return navigator.credentials.get({ publicKey: getOptions(m) });
					}).then(function (k) {
						return deliver({ krok: 'passkey', odpoved: JSON.stringify(assertion(k)) });
					}).then(done).catch(function (e) {
						showError(e && e.name === 'NotAllowedError' ? '' : (e && e.message) || '');
						busy = false;
						startConditional();
					});
					return;
				}

				// adding a passkey needs the current password (3.3.3) or a passkey/backup-code step a few minutes ago (3.35):
				// the server issues the challenge only then; "register again" (3.35) names the older passkey it replaces
				var password = formEl.querySelector('[data-klic-heslo]');
				var replaces = button.getAttribute('data-nahradit') || '';
				deliver(register ? { co: 'klic_moznosti', soucasne: password ? password.value : '', nahradit: replaces } : { krok: 'klic_moznosti' }).then(function (m) {
					if (register) {
						m.challenge = toBytes(m.challenge);
						m.user.id = toBytes(m.user.id);
						(m.excludeCredentials || []).forEach(function (c) { c.id = toBytes(c.id); });
						return navigator.credentials.create({ publicKey: m });
					}
					return navigator.credentials.get({ publicKey: getOptions(m) });
				}).then(function (k) {
					var o = k.response, response = { id: toText(k.rawId), clientDataJSON: toText(o.clientDataJSON) };
					if (register) {
						if (!o.getPublicKey || !o.getAuthenticatorData) { throw new Error(formEl.querySelector('[data-klic-nepodporuje]').textContent); }
						response.authenticatorData = toText(o.getAuthenticatorData());
						response.publicKey = toText(o.getPublicKey());
						response.publicKeyAlgorithm = o.getPublicKeyAlgorithm();
						var props = (k.getClientExtensionResults && k.getClientExtensionResults().credProps) || {};
						response.rk = props.rk === false ? '0' : (props.rk === true ? '1' : '');
						return deliver({ co: 'klic_uloz', odpoved: JSON.stringify(response), nazev: formEl.querySelector('[name="nazev"]:not([form])').value, nahradit: replaces });
					}
					return deliver({ krok: 'klic', odpoved: JSON.stringify(assertion(k)) });
				}).then(done).catch(function (e) {
					// the user cancelling the dialog is not an error worth reporting
					showError(e && e.name === 'NotAllowedError' ? '' : (e && e.message) || '');
				});
			});
		});
	});
})();
