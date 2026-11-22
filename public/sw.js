/*
 * Service worker de l'application : affiche les notifications envoyées par le club (convocations, messages,
 * entraînements annulés…), même quand l'application est fermée, et ouvre la bonne page quand on les touche.
 *
 * Une seule exception à « rien en cache » : la saisie du match en direct (/matchs/<n>/direct, et sa version simple /matchs/<n>/live) et ses fichiers
 * (/assets/…), pour l'ouvrir au stade sans réseau. Elle est toujours lue sur le réseau quand il y en a ; la copie
 * gardée ne sert qu'en cas d'échec. Le cache est vidé à la déconnexion (app.js).
 */
const CACHE_DIRECT = 'club-direct-v1';
const PAGE_DIRECT = /^\/matchs\/\d+\/(direct|live)$/;

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));

// La page « En direct » demande à garder sa propre adresse et les fichiers qu'elle a chargés.
self.addEventListener('message', (event) => {
    const d = event.data || {};
    if (d.type === 'garder' && Array.isArray(d.urls)) {
        const urls = d.urls.filter((u) => {
            const chemin = new URL(u, self.location.origin).pathname;
            return PAGE_DIRECT.test(chemin) || chemin.startsWith('/assets/');
        });
        event.waitUntil(caches.open(CACHE_DIRECT).then((c) => Promise.all(urls.map((u) => c.add(new Request(u, { credentials: 'same-origin' })).catch(() => null)))));
    }
    if (d.type === 'oublier') {
        event.waitUntil(caches.delete(CACHE_DIRECT));
    }
});

self.addEventListener('fetch', (event) => {
    const req = event.request;
    if (req.method !== 'GET') return;
    const url = new URL(req.url);
    if (url.origin !== self.location.origin) return;
    if (PAGE_DIRECT.test(url.pathname)) {
        // Réseau d'abord ; la copie gardée seulement sans réseau.
        event.respondWith(fetch(req).then((rep) => {
            if (rep.ok && !rep.redirected) {
                const copie = rep.clone();
                caches.open(CACHE_DIRECT).then((c) => c.put(url.pathname, copie));
            }
            return rep;
        }).catch(() => caches.match(url.pathname).then((r) => r || Response.error())));
    } else if (url.pathname.startsWith('/assets/')) {
        // Fichiers versionnés (nom avec empreinte) : la copie gardée si elle existe, sinon le réseau.
        event.respondWith(caches.match(req).then((r) => r || fetch(req)));
    } else if (req.mode === 'navigate') {
        // Toute autre page sans réseau : la liste des matchs gardés sur l'appareil, pour noter quand même.
        event.respondWith(fetch(req).catch(() => pageHorsReseau()));
    }
});

async function pageHorsReseau() {
    const cache = await caches.open(CACHE_DIRECT);
    const liens = [];
    for (const req of await cache.keys()) {
        const chemin = new URL(req.url).pathname;
        if (!PAGE_DIRECT.test(chemin)) continue;
        const html = await (await cache.match(req)).text();
        const titre = (html.match(/<title>([^<]*)<\/title>/) || [])[1] || chemin;
        liens.push(`<li><a href="${chemin}">${titre.replace(/ · [^·]*$/, '')}</a></li>`);
    }
    const corps = liens.length
        ? `<p>Matchs gardés sur cet appareil : la saisie fonctionne, tout sera envoyé au retour du réseau.</p><ul>${liens.join('')}</ul>`
        : '<p>Aucun match n\'est gardé sur cet appareil. Ouvrez la page « En direct » d\'un match une fois avec du réseau.</p>';
    return new Response(`<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Pas de réseau</title><style>body{font-family:system-ui,sans-serif;margin:0;padding:1.5rem;background:#f4f4f6;color:#1d1d1f}
        a{display:block;padding:.9rem 1rem;margin:.5rem 0;background:#fff;border:1px solid #ddd;border-radius:10px;color:#1d1d1f;text-decoration:none;font-weight:600}
        ul{list-style:none;padding:0}</style></head><body><h1>Pas de réseau</h1>${corps}</body></html>`,
    { headers: { 'Content-Type': 'text/html; charset=utf-8' } });
}

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
