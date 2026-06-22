/* Titan service worker — app-shell offline strategy.
 * Bump CACHE_VERSION to invalidate old caches on deploy. */
const CACHE_VERSION = 'titan-v2';
const PRECACHE = `${CACHE_VERSION}-precache`;
const RUNTIME = `${CACHE_VERSION}-runtime`;

const OFFLINE_URL = '/offline.html';

// App shell + offline fallback. Kept intentionally small — built assets
// (hashed under /build/*) are cached on demand at runtime.
const PRECACHE_URLS = [
  OFFLINE_URL,
  '/manifest.webmanifest',
  '/icons/icon.svg',
  '/icons/icon-192.png',
  '/icons/icon-512.png',
  '/icons/icon-maskable.png',
  '/icons/apple-touch-icon.png',
];

// ---- Install: precache the shell, activate immediately ----
self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(PRECACHE)
      .then((cache) => cache.addAll(PRECACHE_URLS))
      .then(() => self.skipWaiting())
  );
});

// ---- Activate: drop caches from older versions ----
self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(
        keys
          .filter((key) => !key.startsWith(CACHE_VERSION))
          .map((key) => caches.delete(key))
      ))
      .then(() => self.clients.claim())
  );
});

// Allow the page to trigger an immediate update.
self.addEventListener('message', (event) => {
  if (event.data === 'SKIP_WAITING') self.skipWaiting();
});

// Treat as a cacheable static asset?
function isStaticAsset(url) {
  return (
    url.pathname.startsWith('/build/') ||
    url.pathname.startsWith('/icons/') ||
    url.pathname === '/manifest.webmanifest' ||
    /\.(?:css|js|woff2?|ttf|otf|png|jpg|jpeg|gif|svg|webp|ico)$/.test(url.pathname)
  );
}

self.addEventListener('fetch', (event) => {
  const { request } = event;

  // Only handle GET; never interfere with POST/PUT/etc.
  if (request.method !== 'GET') return;

  const url = new URL(request.url);

  // Same-origin only (let cross-origin font CDN etc. pass through normally,
  // except we opportunistically cache google fonts below).
  const sameOrigin = url.origin === self.location.origin;

  // ---- Never cache API or authenticated data: network-only ----
  if (sameOrigin && (url.pathname.startsWith('/api/') || url.pathname.startsWith('/sanctum/'))) {
    return; // default browser handling
  }

  // ---- Navigations: network-first with offline fallback ----
  if (request.mode === 'navigate') {
    event.respondWith(
      fetch(request)
        .then((response) => {
          // Keep a fresh copy of successful navigations for offline reuse.
          const copy = response.clone();
          caches.open(RUNTIME).then((cache) => cache.put(request, copy)).catch(() => {});
          return response;
        })
        .catch(() =>
          caches.match(request).then((cached) => cached || caches.match(OFFLINE_URL))
        )
    );
    return;
  }

  // ---- Static assets: cache-first ----
  const isFontCdn =
    url.hostname === 'fonts.googleapis.com' || url.hostname === 'fonts.gstatic.com';

  if ((sameOrigin && isStaticAsset(url)) || isFontCdn) {
    event.respondWith(
      caches.match(request).then((cached) => {
        if (cached) return cached;
        return fetch(request).then((response) => {
          if (response && (response.ok || response.type === 'opaque')) {
            const copy = response.clone();
            caches.open(RUNTIME).then((cache) => cache.put(request, copy)).catch(() => {});
          }
          return response;
        });
      })
    );
  }
  // Everything else: default network handling.
});

// ---- Web Push: show the notification the server sent ----
// Payload is the JSON WebPushService sends: { title, body, url }.
self.addEventListener('push', (event) => {
  let payload = {};
  try {
    payload = event.data ? event.data.json() : {};
  } catch (e) {
    payload = { title: 'Titan', body: event.data ? event.data.text() : '' };
  }

  const title = payload.title || 'Titan';
  const url = payload.url || '/notifications';

  event.waitUntil(
    self.registration.showNotification(title, {
      body: payload.body || '',
      icon: '/icons/icon-192.png',
      badge: '/icons/icon-192.png',
      tag: payload.tag || undefined,
      data: { url },
    })
  );
});

// ---- Notification click: focus an existing tab or open the deep link ----
self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const target = (event.notification.data && event.notification.data.url) || '/notifications';

  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clients) => {
      // Focus an open Titan tab and navigate it to the target if we can.
      for (const client of clients) {
        if ('focus' in client) {
          client.focus();
          if ('navigate' in client && target) {
            try { client.navigate(target); } catch (e) { /* cross-origin etc. */ }
          }
          return;
        }
      }
      // No open tab — open a fresh one.
      if (self.clients.openWindow) return self.clients.openWindow(target);
    })
  );
});
