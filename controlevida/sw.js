const CACHE = 'controlevida-static-v2';
const STATIC = ['/controlevida/assets/app.css','/controlevida/assets/app.js','/controlevida/assets/vendor/lucide.min.js','/controlevida/offline.html','/assets/icons/icon-192.png','/assets/icons/icon-512.png'];
self.addEventListener('install', event => event.waitUntil(caches.open(CACHE).then(cache => cache.addAll(STATIC)).then(() => self.skipWaiting())));
self.addEventListener('activate', event => event.waitUntil(Promise.all([caches.keys().then(keys => Promise.all(keys.filter(k => k.startsWith('controlevida-') && k !== CACHE).map(k => caches.delete(k)))), self.clients.claim()])));
self.addEventListener('fetch', event => {
  if (event.request.method !== 'GET' || new URL(event.request.url).origin !== self.location.origin) return;
  // Personal pages and API responses are never put in an offline cache.
  if (event.request.mode === 'navigate') { event.respondWith(fetch(event.request).catch(() => caches.match('/controlevida/offline.html'))); return; }
  if (STATIC.includes(new URL(event.request.url).pathname)) event.respondWith(fetch(event.request, {cache:'no-cache'}).then(response => {
    if (response.ok) { const copy=response.clone(); event.waitUntil(caches.open(CACHE).then(cache=>cache.put(event.request,copy))); }
    return response;
  }).catch(()=>caches.match(event.request)));
});
