/* Kaleta - the groups of the admin's left menu (3.25). Loaded right after the menu, without defer, so a group this browser
 * closed is closed before the first paint (no jump after the page loads).
 *
 *   <details data-menu-skupina="content" [data-aktualni] open>   one group; the server renders every group open
 *
 * The group with the current screen is always open. The others follow what the person did in this browser since they
 * signed in (localStorage "kaleta-menu": {"_signin": "<start of the sign-in>", "content": 1, "appearance": 0}); without a
 * choice only the group marked data-menu-vychozi (Content, Admin\MenuGroups::OPEN_AT_SIGN_IN) is open – on a phone too.
 * 3.29.1: a new sign-in (data-menu-prihlaseni on this script, Core\Auth's login_at) forgets the choices of the one before,
 * so after signing in only Content is open. Storage may be blocked – then nothing is remembered.
 */
(function () {
	'use strict';

	var KEY = 'kaleta-menu';
	var signIn = document.currentScript ? document.currentScript.getAttribute('data-menu-prihlaseni') || '' : '';
	var stored = {};
	try {
		stored = JSON.parse(localStorage.getItem(KEY) || '{}') || {};
		if (signIn !== '' && stored._signin !== signIn) {
			stored = { _signin: signIn }; // a new sign-in: the menu starts again with only Content open
			localStorage.setItem(KEY, JSON.stringify(stored));
		}
	} catch (e) { stored = {}; }

	document.querySelectorAll('details[data-menu-skupina]').forEach(function (group) {
		var key = group.getAttribute('data-menu-skupina');
		if (!group.hasAttribute('data-aktualni')) {
			var open = Object.prototype.hasOwnProperty.call(stored, key) ? !!stored[key] : group.hasAttribute('data-menu-vychozi');
			if (!open) { group.removeAttribute('open'); }
		}
		// only the person's own click (or Enter / Space on the summary) is remembered – a details element parsed open fires
		// a toggle event too; the new state is read once the click has opened or closed the group
		var summary = group.querySelector('summary');
		if (summary) {
			summary.addEventListener('click', function () {
				setTimeout(function () {
					try {
						var now = JSON.parse(localStorage.getItem(KEY) || '{}') || {};
						now[key] = group.open ? 1 : 0;
						localStorage.setItem(KEY, JSON.stringify(now));
					} catch (e) { /* storage blocked: the choice lasts for this page only */ }
				}, 0);
			});
		}
	});
}());
