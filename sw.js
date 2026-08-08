const CACHE_NAME = 'ela-shell-v36';
const SHELL_FILES = [
  '/sistema/',
  '/sistema/assets/style.css?v=4',
  '/sistema/assets/app.js?v=35',
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
    fetch(event.request, { cache: 'no-store' })
      .then((response) => {
        const copy = response.clone();
        caches.open(CACHE_NAME).then((cache) => cache.put(event.request, copy));
        return response;
      })
      .catch(() => caches.match(event.request))
  );
});

// Notificaciones push (como WhatsApp) — el servidor manda un JSON
// {title, body, url} (ver api/push_helpers.php); esto solo lo muestra.
self.addEventListener('push', (event) => {
  let data = {title: 'Expertos Laborales', body: 'Tienes un mensaje nuevo.', url: '/sistema/'};
  try{ if(event.data) data = Object.assign(data, event.data.json()); }catch(e){}
  event.waitUntil(
    self.registration.showNotification(data.title, {
      body: data.body,
      icon: '/sistema/assets/icons/icon-192.png',
      badge: '/sistema/assets/icons/icon-192.png',
      data: {url: data.url || '/sistema/'},
    })
  );
});

// Al darle clic a la notificación, enfoca una pestaña ya abierta del
// sistema si existe, o abre una nueva.
self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const url = (event.notification.data && event.notification.data.url) || '/sistema/';
  event.waitUntil(
    self.clients.matchAll({type: 'window', includeUncontrolled: true}).then((clientList) => {
      for(const client of clientList){
        if(client.url.includes('/sistema/') && 'focus' in client) return client.focus();
      }
      if(self.clients.openWindow) return self.clients.openWindow(url);
    })
  );
});
