/**
 * Staff Attendance System — Progressive Web App Service Worker
 * Strategy: Network-First for dynamic PHP pages & APIs; Cache-First for static assets.
 */

const CACHE_NAME = 'sas-static-v2';
const STATIC_ASSETS = [
  './manifest.webmanifest',
  './assets/css/app.css',
  './assets/js/app.js',
  './assets/img/icon-192.png',
  './assets/img/icon-512.png',
  './assets/img/logo.png'
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => {
      return cache.addAll(STATIC_ASSETS).catch((err) => {
        console.warn('[SW] Pre-caching warning:', err);
      });
    }).then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) => {
      return Promise.all(
        keys.map((key) => {
          if (key !== CACHE_NAME) {
            return caches.delete(key);
          }
        })
      );
    }).then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const request = event.request;

  // Only handle GET requests
  if (request.method !== 'GET') {
    return;
  }

  const url = new URL(request.url);

  // Dynamic pages (*.php), API requests, or navigation MUST be Network-First
  // This guarantees live clock times, fresh CSRF tokens, and zero stale attendance states.
  const isDynamic = request.mode === 'navigate' ||
                    request.destination === 'document' ||
                    (request.headers.get('Accept') || '').includes('text/html') ||
                    url.pathname.endsWith('.php') ||
                    url.pathname.includes('/api/');

  if (isDynamic) {
    event.respondWith(
      fetch(request).catch(() => {
        return caches.match(request).then((cached) => {
          if (cached) return cached;
          return new Response(
            `<!DOCTYPE html>
            <html lang="en">
            <head>
              <meta charset="utf-8">
              <meta name="viewport" content="width=device-width, initial-scale=1">
              <title>Offline · Staff Attendance</title>
              <style>
                body { background: #06090f; color: #f0f6fc; font-family: system-ui, -apple-system, sans-serif; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; text-align: center; padding: 1.5rem; }
                .card { max-width: 380px; background: rgba(255,255,255,0.05); padding: 2rem; border-radius: 1rem; border: 1px solid rgba(255,255,255,0.1); }
                h1 { font-size: 1.3rem; margin-bottom: 0.5rem; color: #38bdf8; }
                p { color: #8b949e; font-size: 0.95rem; margin-bottom: 1.5rem; }
                button { background: #0284c7; color: #fff; border: 0; padding: 0.75rem 1.5rem; font-weight: 600; border-radius: 0.5rem; cursor: pointer; font-size: 1rem; }
              </style>
            </head>
            <body>
              <div class="card">
                <h1>Connection Lost</h1>
                <p>Unable to reach the attendance server. Please verify your Wi-Fi or cellular connection and tap retry.</p>
                <button onclick="window.location.reload()">Retry Now</button>
              </div>
            </body>
            </html>`,
            { headers: { 'Content-Type': 'text/html; charset=utf-8' } }
          );
        });
      })
    );
    return;
  }

  // Static assets (CSS, JS, images, fonts): Cache-First with background revalidation
  event.respondWith(
    caches.match(request).then((cachedResponse) => {
      const fetchPromise = fetch(request).then((networkResponse) => {
        if (networkResponse && networkResponse.status === 200) {
          const responseToCache = networkResponse.clone();
          caches.open(CACHE_NAME).then((cache) => cache.put(request, responseToCache));
        }
        return networkResponse;
      }).catch(() => cachedResponse);

      return cachedResponse || fetchPromise;
    })
  );
});
