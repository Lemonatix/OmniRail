/**
 * Service Worker: die App funktioniert auch im Funkloch.
 *
 * WAS OFFLINE GEHT: Die Seite selbst lädt, die verfolgte Verbindung steht
 * da (sie liegt ohnehin im localStorage, siehe app.js), und alles, was die
 * App zuletzt vom eigenen Backend geholt hat - Suchergebnisse, Zugläufe,
 * Abfahrtstafeln, Bahnhofspläne -, kommt aus dem Zwischenspeicher, mit dem
 * Stand von damals. Im ICE zwischen Würzburg und Fulda ist das der
 * Unterschied zwischen "weiß ich noch, an welchem Gleis ich umsteige" und
 * einer weißen Seite.
 *
 * NETZ ZUERST, IMMER. Auch für HTML, CSS und JS: ein Service Worker, der
 * die App aus dem Cache ausliefert, würde jedes Update verschlucken - genau
 * das Problem, das .htaccess mit "no-cache" gerade gelöst hat. Der Cache
 * springt nur ein, wenn das Netz nicht antwortet.
 *
 * NICHT GESPEICHERT werden Kartenkacheln: sie kommen als <img> ohne CORS,
 * also als "opake" Antworten, und die rechnet Chrome mit je rund 7 MB auf
 * das Speicherkontingent an. Die Route liegt als SVG über der Karte und ist
 * auch ohne Kacheln zu sehen.
 */

// Bei jeder Änderung an dieser Datei hochzählen - dann räumt activate()
// die alten Caches weg.
const VERSION = 'omnirail-v1';
const SHELL = `${VERSION}-shell`;
const DATA = `${VERSION}-data`;

/** Wie viele Backend-Antworten höchstens aufgehoben werden. */
const MAX_DATA = 150;

/** Was die Seite zum Starten braucht. Relativ zum Ort dieser Datei. */
const SHELL_FILES = [
  './',
  'index.html',
  'manifest.webmanifest',
  'assets/css/style.css',
  'assets/js/app.js',
  'assets/js/api.js',
  'assets/js/autocomplete.js',
  'assets/js/board.js',
  'assets/js/favorites.js',
  'assets/js/live.js',
  'assets/js/map.js',
  'assets/js/mvgTicker.js',
  'assets/js/render.js',
  'assets/js/scoring.js',
  'assets/js/works.js',
  'assets/js/data/routes.js',
  'assets/js/data/trains.js',
];

/**
 * Backend-Aktionen, deren letzte Antwort offline noch etwas taugt. Nicht
 * dabei: livetrains (Positionen von vor einer Stunde sind falsch, nicht
 * alt) und health.
 */
const CACHED_ACTIONS = new Set([
  'journeys', 'traindetails', 'departures', 'locations', 'catalogue',
  'platforms', 'works', 'offers', 'fxrate', 'disruptions', 'nextconnection', 'localroute',
]);

self.addEventListener('install', (event) => {
  event.waitUntil((async () => {
    const cache = await caches.open(SHELL);
    // Einzeln statt addAll: fehlt eine Datei, soll nicht die ganze
    // Installation scheitern.
    await Promise.allSettled(SHELL_FILES.map((f) => cache.add(new Request(f, { cache: 'no-cache' }))));
    await self.skipWaiting();
  })());
});

self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    for (const key of await caches.keys()) {
      if (!key.startsWith(VERSION)) await caches.delete(key);
    }
    await self.clients.claim();
  })());
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  if (url.origin !== self.location.origin) return;   // Kacheln, Schriften, Fremdes

  const scope = new URL(self.registration.scope);
  if (!url.pathname.startsWith(scope.pathname)) return;

  const isApi = url.pathname.startsWith(new URL('api/', scope).pathname);
  if (isApi) {
    if (!CACHED_ACTIONS.has(url.searchParams.get('action') || '')) return;
    event.respondWith(networkFirst(req, DATA, true));
    return;
  }
  event.respondWith(networkFirst(req, SHELL, false));
});

/**
 * Netz zuerst, bei Erfolg eine Kopie in den Cache; ohne Netz die Kopie.
 *
 * Aus dem Cache gelieferte API-Antworten bekommen den Kopf
 * `X-From-Cache: 1` - daran erkennt die App, dass sie alt sind.
 */
async function networkFirst(req, cacheName, isApi) {
  const cache = await caches.open(cacheName);
  try {
    const res = await fetch(req);
    if (res.ok) {
      cache.put(req, res.clone()).then(() => (isApi ? trim(cache) : null)).catch(() => {});
    }
    return res;
  } catch (err) {
    const hit = await cache.match(req, { ignoreVary: true })
      // Die Startseite unter jeder Adresse: "./?von=…" gibt es nicht im
      // Cache, "./" schon.
      || (req.mode === 'navigate' ? await cache.match('./') || await cache.match('index.html') : null);
    if (!hit) throw err;
    if (!isApi) return hit;
    const headers = new Headers(hit.headers);
    headers.set('X-From-Cache', '1');
    return new Response(await hit.blob(), { status: hit.status, statusText: hit.statusText, headers });
  }
}

/** Die ältesten Einträge verwerfen, damit der Cache nicht wächst. */
async function trim(cache) {
  const keys = await cache.keys();
  for (let i = 0; i < keys.length - MAX_DATA; i++) await cache.delete(keys[i]);
}

// Tipp auf eine Benachrichtigung der Live-Verfolgung: zur App zurück.
self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  event.waitUntil((async () => {
    const all = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    const own = all.find((c) => c.url.startsWith(self.registration.scope));
    if (own) return own.focus();
    return self.clients.openWindow(self.registration.scope);
  })());
});
