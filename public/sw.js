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

/*
 * Bump VERSION whenever the precache list below changes. The activate handler
 * deletes every cache whose name is not the current one, so a bump is what
 * evicts the previous build's assets rather than leaving them to accumulate.
 */
const VERSION = 'hrms-v3';
const ASSET_CACHE = `${VERSION}-assets`;

// Resolved against the worker's own scope so this works both at a document
// root and in a subdirectory deployment.
const scoped = (path) => new URL(path, self.registration.scope).toString();

const OFFLINE_URL = scoped('offline.html');

/*
 * The launcher icons are precached alongside the offline page for one reason:
 * the offline page and the lock screen both show the hospital mark, and an
 * icon fetched over a dead network is a broken image in exactly the moment the
 * app is trying to look composed. They are content-stable, so caching them
 * costs one fetch each, ever.
 */
const PRECACHE_URLS = [
    OFFLINE_URL,
    scoped('images/icons/icon-192.png'),
    scoped('images/icons/logo-mark-192.png'),
    scoped('images/icons/favicon-32.png'),
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches
            .open(ASSET_CACHE)
            // addAll is atomic — one 404 rejects the whole install and leaves
            // the previous worker in charge, which is the safe direction.
            .then((cache) => cache.addAll(PRECACHE_URLS))
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

/**
 * Endpoints that must always hit the network, no matter what they look like.
 *
 * The manifest does not match the asset extensions below today, so this is
 * belt-and-braces — but it is the file that decides the installed app's icon
 * and name, and a worker serving a stale one is invisible and maddening to
 * debug. Adding an extension to the list below must not silently start caching
 * it.
 */
const isNeverCached = (url) => url.pathname.includes('/api/') || url.pathname.endsWith('.webmanifest');

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
