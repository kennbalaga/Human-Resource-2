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

    /* Integrations and audit logs are deliberately absent from the rail. Only a
       System Administrator may open them, and they reach both from the
       Operational tools panel in Settings. */

    /* A badge is a call to act, so an empty queue shows nothing rather than a
       zero -- three grey noughts down the rail read as decoration and train the
       eye to skip the plate that does mean something. The composer supplies the
       counts; see SidebarBadgeService for what each one is counting. */
    $sidebarBadges = $sidebarBadges ?? ['swaps' => 0, 'timesheets' => 0, 'leave' => 0];
    $sidebarBadge = fn (int $count): ?string => $count < 1
        ? null
        : ($count > \App\Services\SidebarBadgeService::MAX_DISPLAY
            ? \App\Services\SidebarBadgeService::MAX_DISPLAY.'+'
            : (string) $count);
@endphp

<aside class="app-sidebar" id="appSidebar" aria-label="Primary navigation">
    <div class="sidebar-brand">
        <span class="brand-mark">
            {{-- The WorkForce mark. It is decorative: the wordmark beside it
                 already names the system, and a screen reader would otherwise
                 read the name twice. --}}
            <x-brand-mark :size="42" />
        </span>
        {{-- The product wordmark over the organisation it serves. The rail
             leaves ~188px beside the mark; the wordmark takes one line and the
             organisation name a small second one, so the row stays no taller
             than the mark and the divider still meets the topbar's border. --}}
        <span class="brand-copy">
            <strong><x-brand-wordmark /></strong>
            <small title="{{ config('branding.organization') }}">{{ config('branding.organization') }}</small>
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
            <x-sidebar-link :href="route('schedule-preferences.index')" icon="swap" :active="request()->routeIs('schedule-preferences.*', 'shift-swaps.*')" :badge="$sidebarBadge($sidebarBadges['swaps'])">
                Preferences and Swaps
            </x-sidebar-link>
            @if ($sidebarCanManageShifts)
                <x-sidebar-link :href="route('shifts.index')" icon="layers" :active="request()->routeIs('shifts.*')">
                    Shift templates
                </x-sidebar-link>
            @endif
        </div>

        <div class="sidebar-nav-group" role="group" aria-labelledby="sidebar-group-time">
            <h2 class="sidebar-nav-heading" id="sidebar-group-time">Time &amp; attendance</h2>

            {{-- The badge scanner is a tab on this page, so Attendance stays
                 highlighted on both of its views -- and so does the override
                 screen, which is a route of its own under the same module. The
                 attendance report is the one exception: it is reached from
                 Reports and lights Reports. --}}
            <x-sidebar-link :href="route('attendance.index')" icon="clock" :active="request()->routeIs('attendance.*') && ! request()->routeIs('attendance.reports.*')">
                Attendance
            </x-sidebar-link>
            <x-sidebar-link :href="route('timesheets.index')" icon="timesheet" :active="request()->routeIs('timesheets.*')" :badge="$sidebarBadge($sidebarBadges['timesheets'])">
                Timesheets
            </x-sidebar-link>
        </div>

        {{-- Time off is not attendance, so it no longer sits under it. --}}
        <div class="sidebar-nav-group" role="group" aria-labelledby="sidebar-group-time-off">
            <h2 class="sidebar-nav-heading" id="sidebar-group-time-off">Time off</h2>

            <x-sidebar-link :href="route('leaves.index')" icon="leave" :active="request()->routeIs('leaves.*') || request()->routeIs('leave-attachments.*')" :badge="$sidebarBadge($sidebarBadges['leave'])">
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
    </nav>
</aside>
