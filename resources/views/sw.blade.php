{{-- SW view: emits JavaScript, not HTML. Route sets Content-Type: application/javascript.
     Blade is used only to inject the app version string via config() at request time.
     Pitfall 4: Vite::asset() injects the hashed URLs so precache survives every build. --}}
const CACHE_NAME = 'beatrax-shell-v{{ config('nativephp.version') }}';
const STATIC_ASSETS = [
    '{{ Vite::asset('resources/css/app.css') }}',
    '{{ Vite::asset('resources/js/app.js') }}',
    '/icons/icon-192.png',
    '/icons/icon-512.png',
    '/offline.html',
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then(cache => cache.addAll(STATIC_ASSETS))
    );
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then(keys =>
            Promise.all(keys.filter(k => k !== CACHE_NAME).map(k => caches.delete(k)))
        )
    );
    self.clients.claim();
});

self.addEventListener('fetch', (event) => {
    const url = new URL(event.request.url);
    if (
        event.request.method !== 'GET'
        || url.pathname.startsWith('/livewire')
        || url.pathname.startsWith('/api')
        || url.pathname.startsWith('/desktop')
    ) {
        return;
    }
    if (event.request.mode === 'navigate') {
        event.respondWith(
            fetch(event.request).catch(() => caches.match('/offline.html'))
        );
        return;
    }
    event.respondWith(
        caches.match(event.request).then(cached => cached || fetch(event.request))
    );
});
