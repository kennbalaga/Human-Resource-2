<aside class="app-sidebar" id="appSidebar" aria-label="Primary navigation">
    <div class="sidebar-brand">
        <span class="brand-mark">
            <x-icon name="hospital" />
            <button
                class="sidebar-brand-toggle"
                type="button"
                aria-controls="appSidebar"
                aria-expanded="true"
                aria-label="Collapse sidebar"
                data-sidebar-collapse
                data-sidebar-label="Collapse sidebar"
            >
                <x-icon name="chevron-right" />
                <span class="visually-hidden">Toggle sidebar</span>
            </button>
        </span>
        <span class="brand-copy">
            <strong>Dr. Jose Rodriguez</strong>
            <small>Memorial Hospital &amp; Sanitarium</small>
        </span>
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

        <x-sidebar-link :href="route('settings.edit')" icon="settings" :active="request()->routeIs('settings.*', 'integrations.*', 'audit-logs.*')">Settings</x-sidebar-link>
    </nav>

</aside>
