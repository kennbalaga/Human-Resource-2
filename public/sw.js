/**
 * HRMS service worker — deliberately narrow.
 *
 * This app is session-authenticated with CSRF tokens, a 30-minute session
 * lifetime, and single-active-session enforcement. A conventional
 * "cache the HTML for offline" service worker would be a real security bug
 * here: a cached authenticated page can be replayed to the next person on a
 * shared ward terminal after logout, and every cached page carries a stale
 * CSRF token that fails the next form post.
 *
 * So the rules are:
 *   - GET only. Mutations are never intercepted.
 *   - Same-origin only.
 *   - Navigations (authenticated HTML) are network-only, falling back to a
 *     static offline page. They are never written to a cache.
 *   - /api/ is never cached.
 *   - Only versioned build output and static icons/fonts are cached.
 *
 * tests/Feature/Pwa/PwaAssetsTest.php asserts these properties so a future
 * edit cannot quietly reintroduce HTML caching.
 */

const VERSION = 'hrms-v1';
const ASSET_CACHE = `${VERSION}-assets`;

// Resolved against the worker's own scope so this works both at a document
// root and in a subdirectory deployment.
const OFFLINE_URL = new URL('offline.html', self.registration.scope).toString();

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches
            .open(ASSET_CACHE)
            .then((cache) => cache.add(OFFLINE_URL))
            .then(() => self.skipWaiting()),
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches
            .keys()
            .then((keys) => Promise.all(keys.filter((key) => key !== ASSET_CACHE).map((key) => caches.delete(key))))
            .then(() => self.clients.claim()),
    );
});

/** Endpoints that must always hit the network, no matter what they look like. */
const isNeverCached = (url) => url.pathname.includes('/api/');

/** Build output and static icons/fonts — content-hashed or version-stable. */
const isCacheableAsset = (url) =>
    url.pathname.includes('/build/') || /\.(?:css|js|mjs|woff2?|ttf|otf|png|jpe?g|webp|gif|svg|ico)$/i.test(url.pathname);

self.addEventListener('fetch', (event) => {
    const { request } = event;

    // Anything that changes server state carries a CSRF token and must reach
    // the origin untouched.
    if (request.method !== 'GET') {
        return;
    }

    const url = new URL(request.url);

    if (url.origin !== self.location.origin) {
        return;
    }

    // Authenticated HTML: never cached, offline page as the only fallback.
    if (request.mode === 'navigate') {
        event.respondWith(fetch(request).catch(() => caches.match(OFFLINE_URL)));

        return;
    }

    if (isNeverCached(url) || !isCacheableAsset(url)) {
        return;
    }

    event.respondWith(
        caches.match(request).then(
            (cached) =>
                cached ||
                fetch(request).then((response) => {
                    // response.type 'basic' means same-origin and not opaque —
                    // an opaque cross-origin response would poison the cache.
                    if (response.ok && response.type === 'basic') {
                        const copy = response.clone();
                        caches.open(ASSET_CACHE).then((cache) => cache.put(request, copy));
                    }

                    return response;
                }),
        ),
    );
});
