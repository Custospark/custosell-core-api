/**
 * Custosell service worker — offline-first caching
 *
 * | Request type   | Online                         | Offline              |
 * |----------------|--------------------------------|----------------------|
 * | Image/JS/CSS   | CacheStorage (cache-first)     | CacheStorage         |
 * | API GET        | Network → save CacheStorage    | Stale CacheStorage   |
 * | API mutations  | Network (pass-through)         | App queues IndexedDB |
 */

const CACHE_VERSION = 'v5.1.0-1786706901170';
const STATIC_CACHE = `custosell-static-${CACHE_VERSION}`;
const API_CACHE = `custosell-api-${CACHE_VERSION}`;
const API_PATH = /\/api\/v1(\/|$)/;

const STATIC_DESTINATIONS = new Set(['script', 'style', 'image', 'font']);
const STATIC_EXT = /\.(js|mjs|css|png|jpe?g|gif|webp|svg|ico|woff2?|ttf|eot)$/i;

self.addEventListener('install', (event) => {
  self.skipWaiting();
  event.waitUntil(caches.open(STATIC_CACHE));
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    (async () => {
      const keys = await caches.keys();
      await Promise.all(
        keys
          .filter((key) => key.startsWith('custosell-') && key !== STATIC_CACHE && key !== API_CACHE)
          .map((key) => caches.delete(key)),
      );
      await self.clients.claim();
    })(),
  );
});

self.addEventListener('message', (event) => {
  if (event.data?.type === 'CLEAR_API_CACHE') {
    event.waitUntil(caches.delete(API_CACHE));
  }
  if (event.data?.type === 'SKIP_WAITING') {
    self.skipWaiting();
  }
});

/*
 * Web Push
 * The backend sends an encrypted JSON payload: { title, body, url, icon, tag }.
 * `url` is an in-app route (origin-relative); `tag` collapses duplicate alerts.
 */
self.addEventListener('push', (event) => {
  if (!event.data) return;
  let data = {};
  try {
    data = event.data.json();
  } catch {
    data = { title: 'Custosell', body: event.data.text() };
  }

  const payload = {
    title: data.title || 'Custosell',
    body: data.body || '',
    icon: data.icon || '/icons/icon-192.png',
    badge: '/icons/icon-192.png',
    tag: data.tag || undefined,
    data: { url: data.url || '/account/notifications' },
  };

  event.waitUntil(
    self.registration
      .showNotification(payload.title, {
        body: payload.body,
        icon: payload.icon,
        badge: payload.badge,
        tag: payload.tag,
        data: payload.data,
      })
      .then(async () => {
        // Tell any open app window to refresh its unread bell immediately.
        const clients = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
        clients.forEach((client) => client.postMessage({ type: 'PUSH_RECEIVED' }));
      }),
  );
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const url = event.notification.data?.url || '/account/notifications';
  const absolute = new URL(url, self.location.origin).href;

  event.waitUntil(
    (async () => {
      const allClients = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
      for (const client of allClients) {
        if ('focus' in client) {
          await client.focus();
          client.postMessage({ type: 'NAVIGATE', url: absolute });
          return;
        }
      }
      if (self.clients.openWindow) {
        await self.clients.openWindow(absolute);
      }
    })(),
  );
});

function isApiRequest(url, method) {
  return API_PATH.test(url.pathname) && method === 'GET';
}

function isMutationRequest(url, method) {
  return API_PATH.test(url.pathname) && method !== 'GET';
}

function isStaticAsset(url, request) {
  if (STATIC_DESTINATIONS.has(request.destination)) return true;
  if (STATIC_EXT.test(url.pathname)) return true;
  return false;
}

function isNavigateRequest(request) {
  return request.mode === 'navigate';
}

/** Scope API cache entries per business to avoid cross-tenant leakage. */
function toApiCacheRequest(request) {
  const url = new URL(request.url);
  const businessId = request.headers.get('X-Business-Id') || '0';
  url.hash = `biz=${businessId}`;
  return new Request(url.toString(), {
    method: 'GET',
    headers: request.headers,
    credentials: request.credentials,
  });
}

/** Cache-first: JS, CSS, images — online and offline. */
async function cacheFirst(request, cacheName) {
  const cache = await caches.open(cacheName);
  const cached = await cache.match(request);
  if (cached) return cached;

  try {
    const response = await fetch(request);
    if (response.ok) {
      await cache.put(request, response.clone());
    }
    return response;
  } catch (error) {
    if (cached) return cached;
    throw error;
  }
}

/** Network-first with cache write; offline serves stale API GET responses. */
async function networkFirstApi(request) {
  const cache = await caches.open(API_CACHE);
  const cacheRequest = toApiCacheRequest(request);

  try {
    const response = await fetch(request);
    if (response.ok) {
      await cache.put(cacheRequest, response.clone());
    }
    return response;
  } catch (error) {
    const stale = await cache.match(cacheRequest);
    if (stale) return stale;
    throw error;
  }
}

/** SPA shell: try network, fall back to cached index.html. */
async function networkFirstNavigate(request) {
  const cache = await caches.open(STATIC_CACHE);

  try {
    const response = await fetch(request);
    if (response.ok) {
      await cache.put(request, response.clone());
    }
    return response;
  } catch {
    const cached =
      (await cache.match(request)) ||
      (await cache.match('/index.html')) ||
      (await cache.match('./index.html'));
    if (cached) return cached;
    throw new Error('Offline and no cached shell');
  }
}

self.addEventListener('fetch', (event) => {
  const { request } = event;

  if (request.method !== 'GET' && request.method !== 'HEAD') {
    if (isMutationRequest(new URL(request.url), request.method)) {
      // Mutations: pass-through online; offline fails → app queues in IndexedDB
      event.respondWith(fetch(request));
    }
    return;
  }

  const url = new URL(request.url);
  if (url.protocol !== 'http:' && url.protocol !== 'https:') return;

  if (isNavigateRequest(request)) {
    event.respondWith(networkFirstNavigate(request));
    return;
  }

  if (isApiRequest(url, request.method)) {
    event.respondWith(networkFirstApi(request));
    return;
  }

  if (isStaticAsset(url, request)) {
    event.respondWith(cacheFirst(request, STATIC_CACHE));
  }
});
