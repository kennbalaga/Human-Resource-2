@php
    $uiPreference = auth()->user()->preference;
    /*
     * Whether this account gets the phone's tab bar instead of the drawer.
     * Same question as the install offer and the sign-in refusal, asked once:
     * the five tabs name an employee's destinations, so an account that may not
     * use a phone at all keeps the rail it would otherwise be stranded without.
     */
    $phoneTabBar = ! app(App\Services\Security\MobileAccessPolicy::class)->restricts(auth()->user());
    $sessionTimeoutSeconds = max(60, (int) config('session.lifetime', 30) * 60);
    $sessionWarningSeconds = max(30, min((int) config('security.session.warning_seconds', 300), $sessionTimeoutSeconds - 30));
@endphp
<!DOCTYPE html>
<html lang="en" data-theme="{{ $uiPreference->theme }}" data-theme-resolved="light">
<head>
    <meta charset="UTF-8">
    {{-- viewport-fit=cover is what makes env(safe-area-inset-*) return a real
         value on a notched phone; without it the topbar sits under the status
         bar in the installed app. `interactive-widget` keeps the layout still
         when the soft keyboard opens instead of squashing the whole page. --}}
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover, interactive-widget=resizes-content">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Dashboard') · {{ config('branding.organization') }}</title>

    {{-- The mobile rules, and the two endpoints mobile-access.js reaches them by.
         URLs come from the server rather than being written into the bundle
         because this app is served from a subdirectory in local development and a
         document root in production.

         `mobile-restricted` is rendered only for the roles that may not use a
         phone at all. They are already refused at sign-in and turned out by
         middleware, both from the user agent — this is present for the one device
         the user agent cannot describe: an iPad, which since iPadOS 13 sends a
         Mac's string byte for byte. --}}
    @if (app(App\Services\Security\MobileAccessPolicy::class)->restricts(auth()->user()))
        <meta name="mobile-restricted" content="1">
    @endif
    <meta name="mobile-unavailable-url" content="{{ route('mobile.unavailable.store') }}">
    <meta name="trusted-mobile-url" content="{{ route('trusted-mobile.store') }}">
    @include('partials.favicon')

    <script @if(isset($cspNonce)) nonce="{{ $cspNonce }}" @endif>
        (() => {
            const root = document.documentElement;
            const requested = root.dataset.theme || 'system';
            root.dataset.themeResolved = requested === 'system'
                ? (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light')
                : requested;

            try {
                root.dataset.sidebar = localStorage.getItem('workforce.sidebar') === 'collapsed'
                    ? 'collapsed'
                    : 'expanded';
            } catch (error) {
                root.dataset.sidebar = 'expanded';
            }

            /*
             * Device app lock — the pre-paint check.
             *
             * app-lock.js runs at DOMContentLoaded, which is one full paint too
             * late: on a locked phone the dashboard flashes up for a frame
             * before the lock covers it, which is long enough to read a name
             * and long enough for the OS to capture it as the app-switcher
             * thumbnail. This runs in <head>, before anything renders, and
             * hides the shell if this device has a PIN that has not been
             * satisfied in this session yet.
             *
             * It only ever HIDES; app-lock.js owns the decision to actually
             * lock, and clears this flag on every path it can take.
             */
            try {
                const record = JSON.parse(localStorage.getItem('hrms.device-lock.v1') || '{}')['{{ auth()->id() }}'];

                if (record && record.hash) {
                    const session = JSON.parse(sessionStorage.getItem('hrms.device-lock.session.v1') || 'null');

                    if (!session || session.user !== '{{ auth()->id() }}') {
                        root.dataset.appLockPending = 'true';
                    }
                }
            } catch (error) {
                // A device that cannot read storage has no lock to honour.
            }

            /*
             * Failsafe. If app-lock.js never arrives — a failed bundle, a
             * blocked script, an exception in an earlier module — the flag
             * above would hide the app permanently. Four seconds is long past
             * any normal boot and far short of the user giving up.
             */
            if (root.dataset.appLockPending) {
                setTimeout(() => delete root.dataset.appLockPending, 4000);
            }
        })();
    </script>

    {{-- One @vite call: each one emits its own dev-server client, so a second
         call elsewhere would run two HMR clients on the same page. --}}
    @vite(array_values(array_filter([
        'resources/css/app.css',
        'resources/js/app.js',
        // Registers the service worker that makes the app installable. Loaded
        // everywhere so the worker is available whichever page is opened first.
        'resources/js/pwa.js',
        // Only the entrance scanner needs it. Badges are drawn by the server,
        // so an employee's own page carries no QR script at all.
        request()->routeIs('attendance.index') ? 'resources/js/attendance-qr.js' : null,
        // Holds the screen awake while the badge is being shown at a door.
        request()->routeIs('profile.badge') ? 'resources/js/badge-screen.js' : null,
    ])))
    @stack('head')
</head>
<body class="app-body {{ $uiPreference->compact_navigation ? 'compact-navigation' : '' }} {{ $uiPreference->reduce_motion ? 'reduce-motion' : '' }} {{ $phoneTabBar ? 'has-tabbar' : '' }}">
    {{-- The sidebar puts a dozen links in front of the content on every page. A
         keyboard or screen-reader user had to walk all of them to reach the
         thing they navigated here for. Off-screen until focused. --}}
    <a class="skip-link" href="#appContent">Skip to main content</a>

    <div class="app-shell">
        @include('partials.sidebar')

        <button class="sidebar-overlay" type="button" aria-label="Close navigation" data-sidebar-close></button>

        <div class="app-main">
            @include('partials.topbar')

            {{-- `tabindex="-1"` is what makes the skip link actually move focus:
                 without it the browser scrolls to the landmark and leaves the
                 keyboard where it was, back in the sidebar. --}}
            <main class="app-content" id="appContent" tabindex="-1">
                {{-- The screen's name, under the bar rather than in it, so the
                     bar can carry the product mark. Inside the content column
                     so it picks up the same gutters everything below it has.

                     Phone only. On a wider layout the page draws its own
                     heading and this stays out of the way, which is also what
                     keeps exactly one h1 visible at any width. --}}
                <h1 class="app-screen-name @yield('screen_name_class')">@yield('title', config('branding.short_name'))</h1>

                @yield('content')
            </main>

            <footer class="app-footer">
                <span>© {{ now()->year }} {{ config('branding.organization') }}</span>
                <span>Human Resource Management System</span>
            </footer>
        </div>

        {{-- The phone's navigation. Fixed to the bottom of the viewport, so it
             sits outside the main column rather than at the end of it. Hidden by
             CSS on every layout that keeps the rail. --}}
        @if ($phoneTabBar)
            @include('partials.mobile-tabbar')
        @endif
    </div>

    {{-- Device app lock. Inert on desktop: app-lock.js returns immediately
         unless this is a touch device with a small screen. --}}
    @include('partials.app-lock')

    {{-- The password check in front of every download link on the page. --}}
    @include('partials.download-confirm')

    {{-- The one confirmation prompt and the one toast, shared by every page.
         See confirm-actions.js and toast.js for when each is used. --}}
    @include('partials.confirm-dialog')
    @include('partials.toast')

    @include('partials.session-timeout', [
        'sessionTimeoutSeconds' => $sessionTimeoutSeconds,
        'sessionWarningSeconds' => $sessionWarningSeconds,
    ])

    {{-- The fallback path: set by the full confirmation page on its way back
         here, naming the file that was asked for before being stopped. Reached
         only when the modal did not handle it -- a download URL opened
         directly, or a browser without the bundle. Assigning a download URL
         fetches the file without navigating away. Only this app's own URLs are
         ever stored there (ConfirmPasswordForDownload). --}}
    @if (session('download.start'))
        <script @if(isset($cspNonce)) nonce="{{ $cspNonce }}" @endif>
            window.addEventListener('load', () => {
                window.markIntentionalNavigation?.();
                window.location.assign(@json(session('download.start')));
            });
        </script>
    @endif

    @stack('scripts')
</body>
</html>
