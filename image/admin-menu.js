/* Kaleta - the groups of the admin's left menu (3.25). Loaded right after the menu, without defer, so a group this browser
 * closed is closed before the first paint (no jump after the page loads).
 *
 *   <details data-menu-skupina="content" [data-aktualni] open>   one group; the server renders every group open
 *
 * The group with the current screen is always open. The others follow what the person last did in this browser
 * (localStorage "kaleta-menu": {"content": 1, "appearance": 0}); without a choice they stay open, except on a phone, where
 * only the current group is open so the menu sheet stays short. Storage may be blocked – then nothing is remembered.
 */
(function () {
	'use strict';

	var KEY = 'kaleta-menu';
	var stored = {};
	try { stored = JSON.parse(localStorage.getItem(KEY) || '{}') || {}; } catch (e) { stored = {}; }
	var phone = window.matchMedia && window.matchMedia('(max-width: 720px)').matches;

	document.querySelectorAll('details[data-menu-skupina]').forEach(function (group) {
		var key = group.getAttribute('data-menu-skupina');
		if (!group.hasAttribute('data-aktualni')) {
			var open = Object.prototype.hasOwnProperty.call(stored, key) ? !!stored[key] : !phone;
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
