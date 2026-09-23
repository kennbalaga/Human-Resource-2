/**
 * Service-worker registration, update handling, and the install prompt.
 *
 * The worker's URL comes from a Blade-rendered meta tag rather than a
 * hardcoded '/sw.js', because this app is served from a document root in
 * production but a subdirectory in local development — and a service worker
 * can only control pages at or below its own path, so the wrong path means
 * either no worker at all or one scoped above the app.
 */

const INSTALL_DISMISSED_KEY = 'hrms.install-prompt-dismissed';

/**
 * The worker calls skipWaiting() on install, so a new build takes control of
 * this page mid-session. Without a reload the page keeps running the previous
 * build's JavaScript against the new build's cached assets, which is the
 * classic "the app is stale until you close every tab" bug.
 *
 * `hadController` is the guard that matters: on a first-ever registration the
 * controller also changes, and reloading there would bounce the page the first
 * time anybody opens the app.
 */
const reloadOnWorkerUpdate = () => {
    const hadController = Boolean(navigator.serviceWorker.controller);
    let reloading = false;

    navigator.serviceWorker.addEventListener('controllerchange', () => {
        if (!hadController || reloading) {
            return;
        }

        reloading = true;
        window.markIntentionalNavigation();
        window.location.reload();
    });
};

/**
 * A dismissible "install this app" bar, on phones only.
 *
 * Chromium fires `beforeinstallprompt` and lets the prompt be deferred to a
 * gesture of our own; iOS fires nothing at all and requires the user to go
 * through the Share sheet, so there is nothing to show there and nothing is
 * shown. Once dismissed it stays dismissed on this device.
 *
 * Whose page carries a manifest is decided in Blade, by role: the roles that see
 * the whole hospital's records are not offered a phone app and cannot use one.
 * `beforeinstallprompt` would not fire for them anyway with no manifest to
 * install, so the guard below is belt and braces — but it is the cheap kind, and
 * without it this file would be one browser quirk away from offering an install
 * that the next sign-in refuses.
 */
const offerInstall = () => {
    if (!document.querySelector('link[rel="manifest"]')) {
        return;
    }

    let deferredPrompt = null;

    window.addEventListener('beforeinstallprompt', (event) => {
        // Suppress Chromium's own mini-infobar so there is one prompt, not two.
        event.preventDefault();

        if (!window.matchMedia('(pointer: coarse)').matches) {
            return;
        }

        try {
            if (localStorage.getItem(INSTALL_DISMISSED_KEY) === '1') {
                return;
            }
        } catch (error) {
            // Storage being unavailable is not a reason to withhold the prompt.
        }

        deferredPrompt = event;
        render();
    });

    // Nothing left to offer once it is installed, and the bar would otherwise
    // sit there inside the installed app itself.
    window.addEventListener('appinstalled', () => {
        deferredPrompt = null;
        document.querySelector('[data-install-banner]')?.remove();
    });

    const dismiss = (banner) => {
        banner.remove();

        try {
            localStorage.setItem(INSTALL_DISMISSED_KEY, '1');
        } catch (error) {
            // It reappears next time; an unstorable dismissal is a small cost.
        }
    };

    function render() {
        if (document.querySelector('[data-install-banner]')) {
            return;
        }

        const banner = document.createElement('div');
        banner.className = 'pwa-install-banner';
        banner.setAttribute('data-install-banner', '');
        banner.setAttribute('role', 'region');
        banner.setAttribute('aria-label', 'Install this app');

        const copy = document.createElement('p');
        // The PIN is the part worth naming: it is what the installed app gets that
        // the browser tab does not, and it is what replaces the authenticator
        // code on every sign-in afterwards.
        copy.innerHTML = '<strong>Install the app</strong><span>Opens from your home screen, and a PIN or fingerprint unlock replaces the sign-in code.</span>';

        const install = document.createElement('button');
        install.type = 'button';
        install.className = 'pwa-install-accept';
        install.textContent = 'Install';

        const close = document.createElement('button');
        close.type = 'button';
        close.className = 'pwa-install-dismiss';
        close.setAttribute('aria-label', 'Dismiss');
        close.textContent = '✕';

        install.addEventListener('click', async () => {
            if (!deferredPrompt) {
                return;
            }

            const prompt = deferredPrompt;
            // The event can only be used once, so it is cleared before the await
            // rather than after — a double tap would otherwise call it twice.
            deferredPrompt = null;
            banner.remove();
            prompt.prompt();
            await prompt.userChoice;
        });

        close.addEventListener('click', () => dismiss(banner));

        banner.append(copy, install, close);
        document.body.append(banner);
    }
};

document.addEventListener('DOMContentLoaded', () => {
    offerInstall();

    if (!('serviceWorker' in navigator)) return;

    const workerUrl = document.querySelector('meta[name="sw-url"]')?.content;
    if (!workerUrl) return;

    reloadOnWorkerUpdate();

    // Registration failing is never worth breaking the page over: the app works
    // perfectly well without a worker, it just isn't installable.
    navigator.serviceWorker.register(workerUrl).catch(() => {});
});
