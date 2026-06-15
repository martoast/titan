/* Titan service worker — app-shell offline strategy.
 * Bump CACHE_VERSION to invalidate old caches on deploy. */
const CACHE_VERSION = 'titan-v1';
const PRECACHE = `${CACHE_VERSION}-precache`;
const RUNTIME = `${CACHE_VERSION}-runtime`;

const OFFLINE_URL = '/offline.html';

// App shell + offline fallback. Kept intentionally small — built assets
// (hashed under /build/*) are cached on demand at runtime.
const PRECACHE_URLS = [
  OFFLINE_URL,
  '/manifest.webmanifest',
  '/icons/icon.svg',
  '/icons/icon-192.svg',
  '/icons/icon-512.svg',
  '/icons/icon-maskable.svg',
  '/icons/apple-touch-icon.svg',
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
