@php
    $sidebarUser = auth()->user();
    $sidebarInitials = collect(explode(' ', $sidebarUser->name))
        ->filter()
        ->take(2)
        ->map(fn ($part) => strtoupper(substr($part, 0, 1)))
        ->implode('');
@endphp

<aside class="app-sidebar" id="appSidebar" aria-label="Primary navigation">
    <div class="sidebar-brand">
        <span class="brand-mark">
            <x-icon name="hospital" />
        </span>
        <span class="brand-copy">
            <strong>Dr. Jose Rodriguez</strong>
            <small>Memorial Hospital &amp; Sanitarium</small>
        </span>
        <button
            class="sidebar-brand-toggle"
            type="button"
            aria-controls="appSidebar"
            aria-expanded="true"
            aria-label="Collapse sidebar"
            data-sidebar-collapse
            data-sidebar-label="Collapse sidebar"
        >
            <x-icon name="chevrons-left" />
            <span class="visually-hidden">Toggle sidebar</span>
        </button>
        <button class="sidebar-close" type="button" aria-label="Close navigation" data-sidebar-close>
            <x-icon name="close" />
        </button>
    </div>

    <nav class="sidebar-nav">
        <x-sidebar-link :href="route('dashboard')" icon="dashboard" :active="request()->routeIs('dashboard')">
            Dashboard
        </x-sidebar-link>

        <x-sidebar-link :href="route('organization.index')" icon="building" :active="request()->routeIs('organization.*', 'employees.*', 'departments.*', 'positions.*')">Organization</x-sidebar-link>

        <x-sidebar-link :href="route('schedules.index')" icon="calendar" :active="request()->routeIs('schedules.*')">
            Schedules
        </x-sidebar-link>
        @if (auth()->user()->roles()->whereIn('slug', ['system-administrator', 'hr-manager'])->exists())
            <x-sidebar-link :href="route('shifts.index')" icon="repeat" :active="request()->routeIs('shifts.*')">
                Shift templates
            </x-sidebar-link>
        @endif
        <x-sidebar-link :href="route('attendance.index')" icon="clock" :active="request()->routeIs('attendance.index')">
            Attendance
        </x-sidebar-link>
        @if (auth()->user()->roles()->whereIn('slug', ['system-administrator', 'hr-manager', 'department-head'])->exists())
            <x-sidebar-link :href="route('attendance.reports.index')" icon="report" :active="request()->routeIs('attendance.reports.*')">
                Reports
            </x-sidebar-link>
        @endif
        <x-sidebar-link :href="route('timesheets.index')" icon="timesheet" :active="request()->routeIs('timesheets.*')">
            Timesheets
        </x-sidebar-link>
        <x-sidebar-link :href="route('leaves.index')" icon="leave" :active="request()->routeIs('leaves.*') || request()->routeIs('leave-attachments.*')">
            Leave Management
        </x-sidebar-link>
        @if (auth()->user()->roles()->whereIn('slug', ['system-administrator', 'hr-manager', 'department-head'])->exists())
            <x-sidebar-link :href="route('analytics.index')" icon="analytics" :active="request()->routeIs('analytics.*')">
                Workforce Analytics
            </x-sidebar-link>
        @endif
    </nav>

    <div class="sidebar-footer">
        <x-sidebar-link :href="route('settings.edit')" icon="settings" :active="request()->routeIs('settings.*', 'integrations.*', 'audit-logs.*')">Settings</x-sidebar-link>

        <div class="sidebar-theme-switch" role="group" aria-label="Color theme">
            <button class="sidebar-theme-option" type="button" data-theme-set="light" data-theme-update-url="{{ route('settings.theme.update') }}" aria-pressed="false">
                <x-icon name="sun" />
                <span>Light</span>
            </button>
            <button class="sidebar-theme-option" type="button" data-theme-set="dark" data-theme-update-url="{{ route('settings.theme.update') }}" aria-pressed="false">
                <x-icon name="moon" />
                <span>Dark</span>
            </button>
        </div>

        <div class="dropup sidebar-profile">
            <button class="sidebar-profile-button" type="button" data-bs-toggle="dropdown" data-bs-display="static" aria-expanded="false" aria-label="Open account menu">
                <span class="avatar avatar-sm">{{ $sidebarInitials }}</span>
                <span class="sidebar-profile-copy">
                    <strong>{{ $sidebarUser->name }}</strong>
                    <small>{{ $sidebarUser->email }}</small>
                </span>
                <x-icon name="more-vertical" class="sidebar-profile-caret" />
            </button>

            <div class="dropdown-menu profile-dropdown sidebar-profile-menu">
                <div class="profile-dropdown-header">
                    <span class="avatar">{{ $sidebarInitials }}</span>
                    <div>
                        <strong>{{ $sidebarUser->name }}</strong>
                        <span>{{ $currentRole ?? 'Employee' }}</span>
                    </div>
                </div>
                <div class="dropdown-divider"></div>
                <a class="dropdown-item" href="{{ route('profile.show') }}"><x-icon name="users" /> My profile</a>
                <a class="dropdown-item" href="{{ route('settings.edit') }}"><x-icon name="settings" /> Account settings</a>
                <div class="dropdown-divider"></div>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button class="dropdown-item text-danger" type="submit"><x-icon name="logout" /> Log out</button>
                </form>
            </div>
        </div>
    </div>
</aside>
