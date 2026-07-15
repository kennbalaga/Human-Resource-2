<aside class="app-sidebar" id="appSidebar" aria-label="Primary navigation">
    <div class="sidebar-brand">
        <span class="brand-mark"><x-icon name="hospital" /></span>
        <span class="brand-copy">
            <strong>Workforce</strong>
            <small>HR Management System</small>
        </span>
        <button class="sidebar-close" type="button" aria-label="Close navigation" data-sidebar-close>
            <x-icon name="close" />
        </button>
    </div>

    <nav class="sidebar-nav">
        <p class="sidebar-section-label">Overview</p>
        <x-sidebar-link :href="route('dashboard')" icon="dashboard" :active="request()->routeIs('dashboard')">
            Dashboard
        </x-sidebar-link>

        <p class="sidebar-section-label">Organization</p>
        <x-sidebar-link href="#employee-overview" icon="users">Employees</x-sidebar-link>
        <x-sidebar-link href="#department-overview" icon="building">Departments</x-sidebar-link>
        <x-sidebar-link href="#position-overview" icon="briefcase">Positions</x-sidebar-link>

        <p class="sidebar-section-label">Workforce</p>
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

        <p class="sidebar-section-label">System</p>
        @if (auth()->user()->roles()->whereIn('slug', ['system-administrator', 'hr-manager'])->exists())
            <x-sidebar-link :href="route('integrations.index')" icon="plug" :active="request()->routeIs('integrations.*')">
                Integrations
            </x-sidebar-link>
            <x-sidebar-link :href="route('audit-logs.index')" icon="shield" :active="request()->routeIs('audit-logs.*')">
                Audit Logs
            </x-sidebar-link>
        @endif
        <x-sidebar-link href="#" icon="settings">Settings</x-sidebar-link>
    </nav>

    <div class="sidebar-support-card">
        <span class="support-icon"><x-icon name="check-circle" /></span>
        <div>
            <strong>System operational</strong>
            <span>Database and authentication are connected.</span>
        </div>
    </div>

    <div class="sidebar-user">
        <span class="avatar avatar-sm">{{ str(auth()->user()->name)->substr(0, 1)->upper() }}</span>
        <span class="sidebar-user-copy">
            <strong>{{ auth()->user()->name }}</strong>
            <small>{{ $currentRole ?? 'Employee' }}</small>
        </span>
        <x-icon name="chevron-right" class="sidebar-user-chevron" />
    </div>
</aside>
