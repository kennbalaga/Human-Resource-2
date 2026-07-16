@php($uiPreference = auth()->user()->preference)
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

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('head')
</head>
<body class="app-body {{ $uiPreference->compact_navigation ? 'compact-navigation' : '' }} {{ $uiPreference->reduce_motion ? 'reduce-motion' : '' }}">
    <div class="app-shell">
        @include('partials.sidebar')

        <button
            class="sidebar-collapse-button"
            type="button"
            aria-controls="appSidebar"
            aria-expanded="true"
            aria-label="Collapse sidebar"
            data-sidebar-collapse
            data-sidebar-label="Collapse sidebar"
        >
            <span class="sidebar-collapse-grip" aria-hidden="true"></span>
            <x-icon name="chevron-right" />
        </button>

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

    @stack('scripts')
</body>
</html>
