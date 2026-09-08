@php
    $sidebarUser = auth()->user();

    $sidebarRoles = $sidebarUser->roles->pluck('slug');
    $sidebarCanManageShifts = $sidebarRoles->intersect(['system-administrator', 'hr-manager'])->isNotEmpty();

    /* Staff have no reason to browse the workforce directory, so the Organization
       entry is limited to the roles that administer it. */
    $sidebarCanSeeOrganization = $sidebarRoles
        ->intersect(['system-administrator', 'hr-manager', 'department-head'])
        ->isNotEmpty();

    /* Reports and Workforce Analytics share a gate, so the whole Insights group is
       hidden together rather than leaving a heading with nothing under it. */
    $sidebarCanSeeInsights = $sidebarRoles
        ->intersect(['system-administrator', 'hr-manager', 'department-head'])
        ->isNotEmpty();

    /* Audit logs and integrations used to be reachable only through Account
       settings, which framed a compliance record and a system-wide integration
       as personal preferences. They are administration, so they get their own
       group. The gate mirrors AuditLogController::authorizeAuditAccess() and
       IntegrationController::authorizeIntegrationAdmin(). */
    $sidebarCanSeeAdministration = $sidebarRoles
        ->intersect(['system-administrator', 'hr-manager'])
        ->isNotEmpty();
@endphp

<aside class="app-sidebar" id="appSidebar" aria-label="Primary navigation">
    <div class="sidebar-brand">
        <span class="brand-mark">
            {{-- The hospital's own seal. srcset carries the 2x file so it stays
                 sharp on a phone without shipping a 300KB original to a 44px
                 box. `alt` is empty because the brand copy beside it already
                 names the hospital — a screen reader would otherwise read the
                 name twice. --}}
            <img
                src="{{ asset('images/icons/logo-mark-96.png') }}?v=20260826"
                srcset="{{ asset('images/icons/logo-mark-96.png') }}?v=20260826 1x, {{ asset('images/icons/logo-mark-192.png') }}?v=20260826 2x"
                alt=""
                width="34"
                height="34"
            >
        </span>
        <span class="brand-copy">
            <strong>Dr. Jose Rodriguez</strong>
            {{-- The subtitle wraps to two lines in the 272px rail. The
                 non-breaking space keeps the ampersand tied to the word
                 before it, so the break falls after "Hospital &" rather than
                 leaving a line to open with a stray "&". --}}
            <small>Memorial Hospital&nbsp;&amp; Sanitarium</small>
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
        <div class="sidebar-nav-group" role="group" aria-labelledby="sidebar-group-overview">
            <h2 class="sidebar-nav-heading" id="sidebar-group-overview">Overview</h2>

            <x-sidebar-link :href="route('dashboard')" icon="dashboard" :active="request()->routeIs('dashboard')">
                Dashboard
            </x-sidebar-link>

            @if ($sidebarCanSeeOrganization)
                <x-sidebar-link :href="route('organization.index')" icon="building" :active="request()->routeIs('organization.*', 'employees.*', 'departments.*', 'positions.*')">Organization</x-sidebar-link>
            @endif
        </div>

        <div class="sidebar-nav-group" role="group" aria-labelledby="sidebar-group-scheduling">
            <h2 class="sidebar-nav-heading" id="sidebar-group-scheduling">Scheduling</h2>

            <x-sidebar-link :href="route('schedules.index')" icon="calendar" :active="request()->routeIs('schedules.*')">
                Schedules
            </x-sidebar-link>
            <x-sidebar-link :href="route('schedule-preferences.index')" icon="clock" :active="request()->routeIs('schedule-preferences.*', 'shift-swaps.*')">
                Preferences and Swaps
            </x-sidebar-link>
            @if ($sidebarCanManageShifts)
                <x-sidebar-link :href="route('shifts.index')" icon="repeat" :active="request()->routeIs('shifts.*')">
                    Shift templates
                </x-sidebar-link>
            @endif
        </div>

        <div class="sidebar-nav-group" role="group" aria-labelledby="sidebar-group-time">
            <h2 class="sidebar-nav-heading" id="sidebar-group-time">Time &amp; attendance</h2>

            <x-sidebar-link :href="route('attendance.index')" icon="clock" :active="request()->routeIs('attendance.index')">
                Attendance
            </x-sidebar-link>
            <x-sidebar-link :href="route('timesheets.index')" icon="timesheet" :active="request()->routeIs('timesheets.*')">
                Timesheets
            </x-sidebar-link>
            <x-sidebar-link :href="route('leaves.index')" icon="leave" :active="request()->routeIs('leaves.*') || request()->routeIs('leave-attachments.*')">
                Leave Management
            </x-sidebar-link>
        </div>

        @if ($sidebarCanSeeInsights)
            <div class="sidebar-nav-group" role="group" aria-labelledby="sidebar-group-insights">
                <h2 class="sidebar-nav-heading" id="sidebar-group-insights">Insights</h2>

                <x-sidebar-link :href="route('reports.index')" icon="report" :active="request()->routeIs('reports.*') || request()->routeIs('attendance.reports.*')">
                    Reports
                </x-sidebar-link>
                <x-sidebar-link :href="route('analytics.index')" icon="analytics" :active="request()->routeIs('analytics.*')">
                    Workforce Analytics
                </x-sidebar-link>
            </div>
        @endif

        @if ($sidebarCanSeeAdministration)
            <div class="sidebar-nav-group" role="group" aria-labelledby="sidebar-group-administration">
                <h2 class="sidebar-nav-heading" id="sidebar-group-administration">Administration</h2>

                <x-sidebar-link :href="route('integrations.index')" icon="plug" :active="request()->routeIs('integrations.*')">
                    Integrations
                </x-sidebar-link>
                <x-sidebar-link :href="route('audit-logs.index')" icon="report" :active="request()->routeIs('audit-logs.*')">
                    Audit logs
                </x-sidebar-link>
            </div>
        @endif
    </nav>
</aside>
