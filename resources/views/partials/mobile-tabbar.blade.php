@php
    /*
     * The phone's navigation. Five destinations, always on screen, in the half
     * of the display a thumb can reach.
     *
     * It replaces the drawer rather than joining it: the rail is a desktop
     * control that was ported to the phone behind a hamburger, which put every
     * destination an employee has — seven of them, under four headings — one
     * tap further away than the screen they were already looking at.
     *
     * Rendered only for accounts that may use a phone at all. A restricted role
     * reaching a narrow layout (which happens only when MOBILE_RESTRICTED_ROLES
     * is emptied for testing) keeps the drawer, because these five tabs do not
     * name Organization, Reports or the room board and it would otherwise be
     * navigation with no way to the rest of the app.
     */
    $tabBadges = $sidebarBadges ?? ['swaps' => 0, 'timesheets' => 0, 'leave' => 0];

    /* One dot for the whole tab: Requests holds leave, swaps and days off, and
       three separate counts on one 78px target would be unreadable. The number
       itself is on each list. */
    $tabRequestsWaiting = (int) ($tabBadges['swaps'] ?? 0) + (int) ($tabBadges['leave'] ?? 0);

    /* Matched here rather than declared per page, so a new view cannot forget
       to light a tab. The exclusions mirror the rail's: the attendance report
       belongs to Insights, and the room board is a supervisor's screen that
       shares the schedules prefix. */
    $onToday = request()->routeIs('dashboard');
    $onAttendance = (request()->routeIs('attendance.*') && ! request()->routeIs('attendance.reports.*'))
        || request()->routeIs('timesheets.*');
    $onSchedule = request()->routeIs('schedules.*') && ! request()->routeIs('schedules.rooms.*');
    $onRequests = request()->routeIs('leaves.*', 'leave-attachments.*', 'shift-swaps.*', 'schedule-preferences.*');

    /* Everything the four tabs do not claim — payslips, notifications, the
       profile, settings, search — is reached through More, so More lights for
       all of it rather than listing routes that would drift. */
    $onMore = ! ($onToday || $onAttendance || $onSchedule || $onRequests);
@endphp

<nav class="app-tabbar" aria-label="Primary">
    <a @class(['app-tab', 'is-active' => $onToday]) href="{{ route('dashboard') }}" @if ($onToday) aria-current="page" @endif>
        <span class="app-tab-glyph"><x-icon name="dashboard" /></span>
        <span class="app-tab-label">Today</span>
    </a>

    <a @class(['app-tab', 'is-active' => $onAttendance]) href="{{ route('attendance.index') }}" @if ($onAttendance) aria-current="page" @endif>
        <span class="app-tab-glyph"><x-icon name="clock" /></span>
        <span class="app-tab-label">Attendance</span>
    </a>

    <a @class(['app-tab', 'is-active' => $onSchedule]) href="{{ route('schedules.index') }}" @if ($onSchedule) aria-current="page" @endif>
        <span class="app-tab-glyph"><x-icon name="calendar" /></span>
        <span class="app-tab-label">Schedule</span>
    </a>

    <a @class(['app-tab', 'is-active' => $onRequests]) href="{{ route('leaves.index') }}" @if ($onRequests) aria-current="page" @endif>
        <span class="app-tab-glyph">
            <x-icon name="leave" />
            @if ($tabRequestsWaiting > 0)
                <span class="app-tab-dot"></span>
            @endif
        </span>
        <span class="app-tab-label">Requests</span>
        @if ($tabRequestsWaiting > 0)
            <span class="visually-hidden">{{ $tabRequestsWaiting }} waiting on a reply</span>
        @endif
    </a>

    <a @class(['app-tab', 'is-active' => $onMore]) href="{{ route('settings.edit') }}" @if ($onMore) aria-current="page" @endif>
        <span class="app-tab-glyph"><x-icon name="menu" /></span>
        <span class="app-tab-label">More</span>
    </a>
</nav>
