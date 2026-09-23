/* MiroCMS - service worker pro oznámení Web Push (rozšíření Oznámení v prohlížeči).
 * Zpráva přichází bez obsahu; titulek a adresu posledního oznámení si worker stáhne z push.json. */
self.addEventListener('install', function () { self.skipWaiting(); });
self.addEventListener('activate', function (e) { e.waitUntil(self.clients.claim()); });

self.addEventListener('push', function (e) {
	var koren = self.registration.scope;
	e.waitUntil(
		fetch(koren + 'push.json', { cache: 'no-store' })
			.then(function (r) { return r.json(); })
			.catch(function () { return {}; })
			.then(function (z) {
				return self.registration.showNotification(z.titulek || 'Nový článek', {
					body: z.text || '', icon: z.ikona || undefined, image: z.obrazek || undefined,
					tag: 'mirocms-clanek', data: { url: z.url || koren }
				});
			})
	);
});

self.addEventListener('notificationclick', function (e) {
	e.notification.close();
	var url = e.notification.data && e.notification.data.url;
	// oznámení smí otevřít jen adresu na vlastním webu
	if (!url || url.indexOf(self.registration.scope) !== 0) { url = self.registration.scope; }
	e.waitUntil(self.clients.openWindow(url));
});
