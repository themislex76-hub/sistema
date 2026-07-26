const CACHE_NAME = 'ela-shell-v1';
const SHELL_FILES = [
  '/sistema/',
  '/sistema/assets/style.css',
  '/sistema/assets/app.js',
  '/sistema/manifest.json',
  '/sistema/assets/icons/icon-192.png',
  '/sistema/assets/icons/icon-512.png',
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => cache.addAll(SHELL_FILES))
  );
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((names) =>
      Promise.all(names.filter((n) => n !== CACHE_NAME).map((n) => caches.delete(n)))
    )
  );
  self.clients.claim();
});

// Estrategia: red primero, caché solo como respaldo sin conexión. Así nunca
// se sirve una versión vieja de app.js mientras haya internet — el caché
// nada más entra a rescatar si el celular se queda sin señal.
self.addEventListener('fetch', (event) => {
  if (event.request.method !== 'GET') return;
  const url = new URL(event.request.url);
  if (url.pathname.includes('/api/')) return; // nunca cachear datos del despacho

  event.respondWith(
    fetch(event.request)
      .then((response) => {
        const copy = response.clone();
        caches.open(CACHE_NAME).then((cache) => cache.put(event.request, copy));
        return response;
      })
      .catch(() => caches.match(event.request))
  );
});
