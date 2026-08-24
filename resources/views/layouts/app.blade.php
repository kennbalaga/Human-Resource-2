@php
    $uiPreference = auth()->user()->preference;
    $sessionTimeoutSeconds = max(60, (int) config('session.lifetime', 30) * 60);
    $sessionWarningSeconds = max(30, min((int) config('security.session.warning_seconds', 300), $sessionTimeoutSeconds - 30));
@endphp
<!DOCTYPE html>
<html lang="en" data-theme="{{ $uiPreference->theme }}" data-theme-resolved="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
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

    @include('partials.session-timeout', [
        'sessionTimeoutSeconds' => $sessionTimeoutSeconds,
        'sessionWarningSeconds' => $sessionWarningSeconds,
    ])

    @stack('scripts')
</body>
</html>
