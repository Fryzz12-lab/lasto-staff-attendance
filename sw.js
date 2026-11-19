/**
 * Staff Attendance System — Progressive Web App Service Worker
 * Strategy: Network-First for dynamic PHP pages & APIs; Cache-First for static assets.
 */

const CACHE_PREFIX = 'sas-static-';
const CACHE_NAME = CACHE_PREFIX + 'v27';
const STATIC_ASSETS = [
  './manifest.webmanifest',
  './assets/css/app.css',
  './assets/vendor/bootstrap-5.3.3/css/bootstrap.min.css',
  './assets/vendor/bootstrap-5.3.3/js/bootstrap.bundle.min.js',
  './assets/js/app.js',
  './assets/js/push_recovery.js',
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
          if (key.startsWith(CACHE_PREFIX) && key !== CACHE_NAME) {
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
        return new Response(
            `<!DOCTYPE html>
            <html lang="en">
            <head>
              <meta charset="utf-8">
              <meta name="viewport" content="width=device-width, initial-scale=1">
              <title>Offline · Staff Attendance</title>
              <style>
                body { background: #06090f; color: #f0f6fc; font-family: system-ui, -apple-system, sans-serif; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; text-align: center; padding: 1.5rem; box-sizing: border-box; }
                .card { max-width: 420px; background: rgba(255,255,255,0.05); padding: 2rem; border-radius: 1rem; border: 1px solid rgba(255,255,255,0.2); }
                h1 { font-size: 1.3rem; margin-bottom: 0.5rem; color: #7dd3fc; }
                p { color: #f0f6fc; font-size: 0.95rem; line-height: 1.5; margin-bottom: 1.5rem; }
                button { background: #0369a1; color: #fff; border: 2px solid #bae6fd; padding: 0.75rem 1.5rem; font-weight: 700; border-radius: 0.5rem; cursor: pointer; font-size: 1rem; }
                button:focus-visible { outline: 3px solid #fff; outline-offset: 3px; }
              </style>
            </head>
            <body>
              <main class="card" role="main">
                <h1>Connection Lost</h1>
                <p role="status" aria-live="polite">The attendance server could not be reached. Attendance changes require server confirmation. No offline attendance action was saved.</p>
                <button type="button" onclick="window.location.reload()">Retry connection</button>
              </main>
              <script>window.addEventListener('online', function () { window.location.reload(); });</script>
            </body>
            </html>`,
            { headers: { 'Content-Type': 'text/html; charset=utf-8' } }
          );
      })
    );
    return;
  }

  // Static assets (CSS, JS, images, fonts): Cache-First with background revalidation
  event.respondWith(
    caches.open(CACHE_NAME).then((cache) => cache.match(request)).then((cachedResponse) => {
      const fetchPromise = fetch(request).then((networkResponse) => {
        if (networkResponse && networkResponse.status === 200) {
          const responseToCache = networkResponse.clone();
          caches.open(CACHE_NAME).then((cache) => cache.put(request, responseToCache));
        }
        return networkResponse;
      }).catch(() => cachedResponse || new Response('', { status: 504 }));

      return cachedResponse || fetchPromise;
    })
  );
});

const recentlyHandledPushTags = new Map();

// Push notification event: display native system notification banner
self.addEventListener('push', (event) => {
  let payload = {
    title: 'Staff Attendance',
    body: 'You have a new update.',
    icon: './assets/img/icon-192.png',
    badge: './assets/img/icon-192.png',
    data: {}
  };

  if (event.data) {
    try {
      const json = event.data.json();
      if (json && typeof json === 'object') {
        payload = Object.assign(payload, json);
        payload.data = Object.assign({}, json.data || {}, json.url ? { url: json.url } : {});
      } else {
        payload.body = String(json || payload.body);
      }
    } catch (e) {
      payload.body = event.data.text() || payload.body;
    }
  }

  const options = {
    body: payload.body,
    icon: payload.icon || './assets/img/icon-192.png',
    badge: payload.badge || './assets/img/icon-192.png',
    data: payload.data || {},
    tag: payload.tag || ('sas-' + Date.now()),
    renotify: false
  };

  event.waitUntil((async () => {
    const now = Date.now();
    for (const [tag, timestamp] of recentlyHandledPushTags) {
      if (now - timestamp > 300000) recentlyHandledPushTags.delete(tag);
    }
    if (recentlyHandledPushTags.has(options.tag)) return;
    recentlyHandledPushTags.set(options.tag, now);
    if (self.registration.getNotifications) {
      try {
        const existing = await self.registration.getNotifications({ tag: options.tag });
        if (existing.length) return;
      } catch (error) { /* Continue delivery if notification lookup is unavailable. */ }
    }
    // Check if an application window is currently visible
    const clients = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    const hasVisibleClient = clients.some((client) => client.visibilityState === 'visible');
    const category = payload.category || payload.data?.category;
    const critical = ['break_ending', 'missing_clock_out', 'shift_ending', 'test_notification'].includes(category);
    if (critical && hasVisibleClient) {
      await Promise.all(clients.filter((client) => client.visibilityState === 'visible')
        .map(async (client) => { try { client.postMessage({ type: 'sas-critical-push', category, tag: options.tag }); } catch (error) { } }));
    }

    const notificationOptions = Object.assign({}, options);
    // When the application window is visible and the push is non-critical,
    // use silent: true without vibration so the user is not double-alerted.
    // When in background or for critical events, sound and vibration are preserved.
    if (hasVisibleClient && !critical) {
      notificationOptions.silent = true;
    } else {
      notificationOptions.vibrate = [120, 60, 120];
    }

    try {
      await self.registration.showNotification(payload.title, notificationOptions);
    } catch (error) {
      recentlyHandledPushTags.delete(options.tag);
      throw error;
    }
  })());
});

// Notification click event: focus existing tab or open new window to target URL
self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  let urlToOpen = event.notification.data?.url || self.registration.scope;
  try {
    const target = new URL(urlToOpen, self.registration.scope);
    const scope = new URL(self.registration.scope);
    if (target.origin !== scope.origin || !target.pathname.startsWith(scope.pathname)) {
      urlToOpen = scope.href;
    } else {
      let route = target.pathname.slice(scope.pathname.length);
      if (!route.startsWith('/')) route = '/' + route;
      const isBlocked = route === '/logout.php' ||
                        route.endsWith('/logout.php') ||
                        route.startsWith('/api/') ||
                        route.startsWith('/cli/') ||
                        route.startsWith('/config/') ||
                        route.startsWith('/storage/');
      if (isBlocked) {
        urlToOpen = scope.href;
      } else {
        urlToOpen = target.href;
      }
    }
  } catch (e) {
    urlToOpen = self.registration.scope;
  }

  event.waitUntil((async () => {
    let windowClients = [];
    try {
      windowClients = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    } catch (e) {
      windowClients = [];
    }

    const scope = new URL(self.registration.scope);
    const inScope = windowClients.filter((client) => {
      try {
        const clientUrl = new URL(client.url);
        return clientUrl.origin === scope.origin && clientUrl.pathname.startsWith(scope.pathname);
      } catch (error) { return false; }
    });

    // Scenario E: If an existing tab already displays the destination, focus without duplicate navigation
    const alreadyOnDestination = inScope.find((client) => {
      try {
        return new URL(client.url).href === urlToOpen;
      } catch (e) { return false; }
    });
    if (alreadyOnDestination) {
      if ('focus' in alreadyOnDestination) {
        try { await alreadyOnDestination.focus(); } catch (e) { }
      }
      return;
    }

    // Reuse an existing in-scope window (prefer visible window, fallback to first in-scope)
    const clientToReuse = inScope.find((entry) => entry.visibilityState === 'visible') || inScope[0];
    if (clientToReuse) {
      let activeClient = clientToReuse;
      if ('focus' in clientToReuse) {
        try {
          const focused = await clientToReuse.focus();
          if (focused) activeClient = focused;
        } catch (focusErr) { }
      }

      if ('postMessage' in activeClient) {
        try {
          activeClient.postMessage({ type: 'sas-navigate', url: urlToOpen });
        } catch (msgErr) { }
      }

      if ('navigate' in activeClient) {
        try {
          await activeClient.navigate(urlToOpen);
        } catch (navError) {
          console.warn('[WebPush] WindowClient.navigate failed:', navError);
        }
      }

      return;
    }

    // Fallback: If no window exists in scope, open a fresh window
    if (self.clients.openWindow) {
      try {
        await self.clients.openWindow(urlToOpen);
      } catch (openError) {
        console.warn('[WebPush] clients.openWindow failed:', openError);
      }
    }
  })());
});
