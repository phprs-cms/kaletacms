/*
 * Kaleta – the customer's quote (3.38, Front\QuotePage): the "Print or save as PDF" button. Without JavaScript it stays
 * hidden and the browser's own Print works the same.
 */
(function () {
	'use strict';
	var button = document.querySelector('[data-quote-print]');
	if (!button) {
		return;
	}
	button.hidden = false;
	button.addEventListener('click', function () {
		window.print();
	});
})();
