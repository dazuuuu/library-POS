/* 2in1 POS service worker — makes the app installable + fast.
   Pages are always network-first (so live data + auth stay correct);
   only static assets and the login shell are cached. */
const CACHE = '2in1-pos-v1';
// Derived from this script's own URL, not hard-coded — works whatever
// folder the app is deployed under.
const BASE  = new URL('.', self.location).pathname.replace(/\/$/, '');
const SHELL = [
  BASE + '/',
  BASE + '/manifest.webmanifest',
  BASE + '/assets/icons/icon-192.png',
  BASE + '/assets/icons/icon-512.png'
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE).then((c) => c.addAll(SHELL)).then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  if (url.origin !== self.location.origin) return;

  // Page navigations: network-first, fall back to the cached login shell offline.
  if (req.mode === 'navigate') {
    event.respondWith(fetch(req).catch(() => caches.match(BASE + '/')));
    return;
  }

  // Static assets: cache-first, then fill the cache.
  if (/\.(png|jpe?g|svg|gif|webp|ico|css|js|woff2?|ttf)$/i.test(url.pathname)) {
    event.respondWith(
      caches.match(req).then((hit) => {
        if (hit) return hit;
        return fetch(req).then((res) => {
          const copy = res.clone();
          caches.open(CACHE).then((c) => c.put(req, copy));
          return res;
        });
      })
    );
    return;
  }

  // Everything else: network, fall back to cache if offline.
  event.respondWith(fetch(req).catch(() => caches.match(req)));
});
