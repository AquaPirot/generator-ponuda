const CACHE_NAME = 'ag-ponude-v3';
const STATIC_ASSETS = [
    './manifest.json',
    './icon.svg'
];

self.addEventListener('install', e => {
    e.waitUntil(
        caches.open(CACHE_NAME).then(cache => cache.addAll(STATIC_ASSETS))
    );
    self.skipWaiting();
});

self.addEventListener('activate', e => {
    e.waitUntil(
        caches.keys().then(keys =>
            Promise.all(keys.filter(k => k !== CACHE_NAME).map(k => caches.delete(k)))
        )
    );
    self.clients.claim();
});

self.addEventListener('fetch', e => {
    const url = new URL(e.request.url);
    const isHtml = e.request.destination === 'document'
        || url.pathname.endsWith('.html')
        || url.pathname.endsWith('/');

    if (isHtml) {
        // Network first za HTML — uvek svjeza verzija, keš samo kao fallback
        e.respondWith(
            fetch(e.request)
                .then(response => {
                    const clone = response.clone();
                    caches.open(CACHE_NAME).then(c => c.put(e.request, clone));
                    return response;
                })
                .catch(() => caches.match(e.request))
        );
    } else {
        // Cache first za sve ostalo (ikonice, manifest)
        e.respondWith(
            caches.match(e.request).then(cached => cached || fetch(e.request))
        );
    }
});
