const DELIVERY_CACHE = 'zando-delivery-shell-v1';
const SHELL_ASSETS = [
    './assets/css/delivery.css',
    './assets/icons/delivery.svg',
    './delivery-offline.html',
];

self.addEventListener('install', event => {
    event.waitUntil(caches.open(DELIVERY_CACHE).then(cache => cache.addAll(SHELL_ASSETS)));
    self.skipWaiting();
});

self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys().then(keys => Promise.all(keys.filter(key => key !== DELIVERY_CACHE).map(key => caches.delete(key))))
    );
    self.clients.claim();
});

self.addEventListener('fetch', event => {
    const requestUrl = new URL(event.request.url);
    if (requestUrl.origin !== self.location.origin || event.request.method !== 'GET') return;

    if (event.request.mode === 'navigate' && /\/delivery_(app|route)\.php$/.test(requestUrl.pathname)) {
        event.respondWith(fetch(event.request).catch(() => caches.match('./delivery-offline.html')));
        return;
    }

    if (SHELL_ASSETS.some(asset => requestUrl.href.endsWith(asset.replace('./', '/')))) {
        event.respondWith(caches.match(event.request).then(cached => cached || fetch(event.request)));
    }
});