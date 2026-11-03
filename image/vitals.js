/* Kaleta - the site's own statistics from the visitor's browser. No libraries, no cookies, no storage, no identifiers.
 * 1. The page view (3.29): once the page is really shown to a person – visible, not prerendered, not under browser automation –
 *    one beacon goes to POST /hit (data-hit) with the path the server rendered and its signature (data-hit-page, data-hit-key),
 *    the news item on the page (data-hit-news), the referring page and the page's query string (campaigns). Front\Stats::hit
 *    checks everything again; a page that is never shown (a link preview, a prefetch, a background tab nobody opens) or a
 *    browser without JavaScript is not counted.
 * 2. Real-user speed (2.8): LCP, CLS and INP of this page view go once to POST /vitals (data-vitals) when the visitor leaves or
 *    hides the page. Core\WebVitals aggregates the values per page and day. Browsers without PerformanceObserver (or without a
 *    metric) simply send less.
 * Front\Seo::head() loads the script only when the built-in statistics are on, never in previews and never for signed-in users. */
(function () {
	'use strict';

	var script = document.currentScript;
	if (!script || navigator.webdriver || /bot|crawl|spider|slurp|preview|monitor|lighthouse|headless/i.test(navigator.userAgent)) { return; }

	function beacon(url, body) {
		if (navigator.sendBeacon && navigator.sendBeacon(url, body)) { return; }
		if (typeof fetch === 'function') { fetch(url, { method: 'POST', body: body, keepalive: true, credentials: 'same-origin' }).catch(function () {}); }
	}

	// 1. the page view, once per page view and only when a person can see it
	var hit = script.getAttribute('data-hit');
	if (hit) {
		var counted = false;
		var count = function () {
			if (counted || document.visibilityState !== 'visible' || document.prerendering) { return; }
			counted = true;
			var body = new URLSearchParams();
			body.set('path', script.getAttribute('data-hit-page') || '');
			body.set('key', script.getAttribute('data-hit-key') || '');
			var news = script.getAttribute('data-hit-news');
			if (news) { body.set('news', news); }
			if (document.referrer) { body.set('referrer', document.referrer.slice(0, 2000)); }
			if (location.search) { body.set('query', location.search.slice(0, 2000)); }
			beacon(hit, body);
		};
		count();
		if (!counted) {
			document.addEventListener('visibilitychange', count);
			document.addEventListener('prerenderingchange', count);
		}
	}

	// 2. real-user speed
	var endpoint = script.getAttribute('data-vitals');
	if (!endpoint || typeof PerformanceObserver !== 'function' || !navigator.sendBeacon) { return; }

	var lcp = null, cls = 0, sent = false;
	var sessionValue = 0, sessionStart = 0, sessionLast = 0;
	var interactions = {};

	function observe(type, each, options) {
		try {
			var o = new PerformanceObserver(function (list) { list.getEntries().forEach(each); });
			o.observe(Object.assign({ type: type, buffered: true }, options || {}));
		} catch (e) { /* the browser does not know this entry type */ }
	}

	// LCP: the last candidate before the first interaction; renderTime is missing for cross-origin images without Timing-Allow-Origin
	observe('largest-contentful-paint', function (e) { lcp = e.renderTime || e.loadTime || e.startTime; });

	// CLS: the largest session window of shifts (gap under 1 s, window under 5 s), shifts after input do not count
	observe('layout-shift', function (e) {
		if (e.hadRecentInput) { return; }
		if (sessionValue && e.startTime - sessionLast < 1000 && e.startTime - sessionStart < 5000) {
			sessionValue += e.value;
		} else {
			sessionValue = e.value;
			sessionStart = e.startTime;
		}
		sessionLast = e.startTime;
		if (sessionValue > cls) { cls = sessionValue; }
	});

	// INP: the longest duration of every interaction (events sharing an interactionId); the worst one is skipped per 50 interactions
	observe('event', function (e) {
		if (!e.interactionId) { return; }
		interactions[e.interactionId] = Math.max(interactions[e.interactionId] || 0, e.duration);
	}, { durationThreshold: 40 });

	function inp() {
		var durations = Object.keys(interactions).map(function (k) { return interactions[k]; }).sort(function (a, b) { return b - a; });
		return durations.length ? durations[Math.min(durations.length - 1, Math.floor(durations.length / 50))] : null;
	}

	function send() {
		if (sent || lcp === null) { return; } // a page hidden before it painted has nothing to say
		sent = true;
		var body = new URLSearchParams();
		body.set('path', location.pathname);
		body.set('lcp', String(Math.round(lcp)));
		body.set('cls', cls.toFixed(4));
		var responsiveness = inp();
		if (responsiveness !== null) { body.set('inp', String(Math.round(responsiveness))); }
		navigator.sendBeacon(endpoint, body);
	}

	document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'hidden') { send(); } });
	window.addEventListener('pagehide', send);
})();
