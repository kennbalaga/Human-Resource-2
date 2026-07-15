@php($uiPreference = auth()->user()->preference)
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Dashboard') · Workforce HRMS</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
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

    @stack('scripts')
</body>
</html>
