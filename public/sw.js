/*
 * Service worker de l'application : affiche les notifications envoyées par le club (convocations, messages,
 * entraînements annulés…), même quand l'application est fermée, et ouvre la bonne page quand on les touche.
 * Rien n'est mis en cache : les pages sont toujours lues à jour (données de mineurs, souvent modifiées).
 */
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));

self.addEventListener('push', (event) => {
    let donnees = {};
    try {
        donnees = event.data ? event.data.json() : {};
    } catch (e) {
        donnees = { texte: event.data ? event.data.text() : '' };
    }
    event.waitUntil(self.registration.showNotification(donnees.titre || 'Club', {
        body: donnees.texte || '',
        data: { url: donnees.url || '/' },
        tag: donnees.url || undefined,
        renotify: true,
        lang: 'fr',
    }));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const url = (event.notification.data && event.notification.data.url) || '/';
    event.waitUntil((async () => {
        const fenetres = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
        for (const f of fenetres) {
            if ('navigate' in f && new URL(f.url).origin === self.location.origin) {
                await f.focus();
                return f.navigate(url);
            }
        }
        return self.clients.openWindow(url);
    })());
});
