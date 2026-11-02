// Makes the portal installable and shows a friendly page when there is no connection.
// Pages are never cached: everything comes from the server so private details and live data stay current.
const OFFLINE_CACHE = 'scodex-offline-v1';
const OFFLINE_FILES = ['/offline.html', '/brand/app-icon-192.png'];

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(OFFLINE_CACHE).then((cache) => cache.addAll(OFFLINE_FILES)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
  event.waitUntil(caches.keys()
    .then((keys) => Promise.all(keys.filter((key) => key !== OFFLINE_CACHE).map((key) => caches.delete(key))))
    .then(() => self.clients.claim()));
});

self.addEventListener('fetch', (event) => {
  const request = event.request;
  if (request.method === 'GET' && request.mode === 'navigate') {
    event.respondWith(fetch(request).catch(() => caches.match('/offline.html')));
  } else if (request.method === 'GET' && OFFLINE_FILES.includes(new URL(request.url).pathname)) {
    event.respondWith(fetch(request).catch(() => caches.match(request)));
  }
});
