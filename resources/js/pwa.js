/**
 * Registers the service worker that makes the app installable.
 *
 * The worker's URL comes from a Blade-rendered meta tag rather than a
 * hardcoded '/sw.js', because this app is served from a document root in
 * production but a subdirectory in local development — and a service worker
 * can only control pages at or below its own path, so the wrong path means
 * either no worker at all or one scoped above the app.
 */
document.addEventListener('DOMContentLoaded', () => {
    if (!('serviceWorker' in navigator)) return;

    const workerUrl = document.querySelector('meta[name="sw-url"]')?.content;
    if (!workerUrl) return;

    // Registration failing is never worth breaking the page over: the app works
    // perfectly well without a worker, it just isn't installable.
    navigator.serviceWorker.register(workerUrl).catch(() => {});
});
