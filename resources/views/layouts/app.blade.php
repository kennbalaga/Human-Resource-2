@php
    $uiPreference = auth()->user()->preference;
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
    <title>@yield('title', 'Dashboard') · Workforce HRMS</title>
    @include('partials.favicon')

    <script>
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
        request()->routeIs('organization.chart') ? 'resources/js/org-chart.js' : null,
    ])))
    @stack('head')
</head>
<body class="app-body {{ $uiPreference->compact_navigation ? 'compact-navigation' : '' }} {{ $uiPreference->reduce_motion ? 'reduce-motion' : '' }}">
    <div class="app-shell">
        @include('partials.sidebar')

        <button class="sidebar-overlay" type="button" aria-label="Close navigation" data-sidebar-close></button>

        <div class="app-main">
            @include('partials.topbar')

            <main class="app-content">
                @yield('content')
            </main>

            <footer class="app-footer">
                <span>© {{ now()->year }} Dr. Jose N. Rodriguez Memorial Hospital and Sanitarium</span>
                <span>Human Resource Management System</span>
            </footer>
        </div>
    </div>

    {{-- Device app lock. Inert on desktop: app-lock.js returns immediately
         unless this is a touch device with a small screen. --}}
    @include('partials.app-lock')

    @include('partials.session-timeout', [
        'sessionTimeoutSeconds' => $sessionTimeoutSeconds,
        'sessionWarningSeconds' => $sessionWarningSeconds,
    ])

    @stack('scripts')
</body>
</html>
