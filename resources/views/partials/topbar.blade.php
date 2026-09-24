@php
    $topbarUser = auth()->user();
    $topbarTheme = $topbarUser->preference->theme;
    $topbarInitials = collect(explode(' ', $topbarUser->name))
        ->filter()
        ->take(2)
        ->map(fn ($part) => strtoupper(substr($part, 0, 1)))
        ->implode('');

    /*
     * The module the reader is in, named in the bar. Matched on the route here
     * rather than declared by every view, so a page cannot forget to say where
     * it is; anything unmatched falls through to the product's own name, which
     * is never wrong, only unhelpful.
     */
    $topbarContext = match (true) {
        request()->routeIs('dashboard') => ['Human Resource Management', 'Workforce operations'],
        request()->routeIs('organization.*', 'employees.*', 'departments.*', 'positions.*', 'rooms.*') => ['Organization', 'Employees, departments and positions'],
        request()->routeIs('schedules.rooms.*') => ['Scheduling', 'Who is in which room today'],
        request()->routeIs('schedules.*', 'shifts.*', 'schedule-preferences.*', 'shift-swaps.*') => ['Scheduling', 'Coverage and shift assignments'],
        request()->routeIs('attendance.reports.*') => ['Insights', 'Reports and workforce analytics'],
        request()->routeIs('attendance.override.*') => ['Time & attendance', 'Authorised unscheduled punches'],
        request()->routeIs('attendance.*') => ['Time & attendance', 'Today and your recent records'],
        request()->routeIs('timesheets.*') => ['Time & attendance', 'Weekly work records'],
        request()->routeIs('leaves.*', 'leave-attachments.*') => ['Time off & pay', 'Balances, requests and documents'],
        request()->routeIs('payslips.*') => ['Time off & pay', 'Semi-monthly pay periods'],
        request()->routeIs('reports.*', 'analytics.*') => ['Insights', 'Reports and workforce analytics'],
        request()->routeIs('settings.*', 'profile.*') => ['Account', 'Profile, appearance and tools'],
        request()->routeIs('audit-logs.*', 'integrations.*') => ['Account', 'Operational tools'],
        default => [config('branding.short_name'), config('branding.tagline')],
    };

    $topbarRole = $topbarUser->roles->first()?->name;
@endphp

<header class="app-topbar">
    <div class="topbar-left">
        <button class="mobile-menu-button" type="button" aria-controls="appSidebar" aria-expanded="false" data-sidebar-toggle>
            <x-icon name="menu" />
            <span class="visually-hidden">Open navigation</span>
        </button>

        {{-- Where you are, held at the left edge opposite the account. The rail
             already marks the page; this names the module it belongs to, which
             the rail's own grouping only implies. --}}
        <span class="topbar-context">
            <strong>{{ $topbarContext[0] }}</strong>
            <span>{{ $topbarContext[1] }}</span>
        </span>
    </div>

    {{-- A direct child of the bar rather than a member of .topbar-actions: the
         phone breakpoint lays the topbar out as a grid and gives the field its
         own full-width row, which only works while it is a grid item of the
         bar itself. --}}
    <div class="global-search-wrapper" data-global-search>
        <form class="global-search" method="GET" action="{{ route('search.index') }}" role="search" autocomplete="off">
            <x-icon name="search" />
            <label class="visually-hidden" for="globalSearchInput">Search employees and departments</label>
            <input
                id="globalSearchInput"
                type="text"
                name="q"
                value="{{ request('q') }}"
                placeholder="Search anything here"
                autocomplete="off"
                data-global-search-input
                role="combobox"
                aria-expanded="false"
                aria-haspopup="listbox"
                aria-controls="globalSearchDropdown"
            >
        </form>

        <div id="globalSearchDropdown" class="global-search-dropdown" data-global-search-dropdown hidden></div>
    </div>

    <div class="topbar-actions">
        <div class="dropdown topbar-notifications">
            {{-- A dot, not a tally, as in the reference bar. The count itself is
                 not lost: it is announced here and written out in full at the
                 head of the menu this opens. --}}
            <button
                class="icon-button notification-button"
                type="button"
                data-bs-toggle="dropdown"
                aria-expanded="false"
                aria-label="Open notifications{{ $notificationUnreadCount > 0 ? ', '.$notificationUnreadCount.' unread' : '' }}"
            >
                <x-icon name="bell" />
                @if ($notificationUnreadCount > 0)
                    <span class="notification-dot"></span>
                @endif
            </button>

            <div class="dropdown-menu dropdown-menu-end notifications-menu">
                <div class="notification-header">
                    <div>
                        <strong>Notifications</strong>
                        <span>{{ $notificationUnreadCount === 0 ? 'No unread notifications' : $notificationUnreadCount.' unread '.Str::plural('notification', $notificationUnreadCount) }}</span>
                    </div>
                    @if($notificationUnreadCount > 0)
                        <form method="POST" action="{{ route('notifications.read-all') }}">
                            @csrf @method('PATCH')
                            <button type="submit">Mark all as read</button>
                        </form>
                    @endif
                </div>

                <div class="notification-list">
                    @php
                        $notificationCategoryMeta = [
                            'attendance' => ['label' => 'Attendance', 'tone' => 'warning'],
                            'schedule' => ['label' => 'Schedule', 'tone' => 'primary'],
                            'leave' => ['label' => 'Leave', 'tone' => 'success'],
                            'payroll' => ['label' => 'Payroll', 'tone' => 'primary'],
                            'security' => ['label' => 'Security', 'tone' => 'danger'],
                            'general' => ['label' => 'General', 'tone' => 'secondary'],
                        ];
                    @endphp
                    @forelse ($notificationItems as $notification)
                        @php
                            $tone = in_array(data_get($notification->data, 'tone'), ['success', 'primary', 'warning'], true) ? data_get($notification->data, 'tone') : 'primary';
                            $icon = in_array(data_get($notification->data, 'icon'), ['clock', 'calendar', 'leave', 'shield', 'report'], true) ? data_get($notification->data, 'icon') : 'bell';
                            $category = data_get($notification->data, 'category');
                            if (! array_key_exists($category, $notificationCategoryMeta)) {
                                $category = match ($icon) {
                                    'clock' => 'attendance',
                                    'calendar' => 'schedule',
                                    'leave' => 'leave',
                                    'shield' => 'security',
                                    default => 'general',
                                };
                            }
                        @endphp
                        <a class="notification-item" href="{{ route('notifications.open', $notification->id) }}">
                            <span class="notification-icon notification-{{ $tone }}">
                                <x-icon :name="$icon" />
                            </span>
                            <div>
                                <span class="notification-category-row">
                                    <strong>{{ data_get($notification->data, 'title', 'HRMS update') }}</strong>
                                    <span class="status-badge status-{{ $notificationCategoryMeta[$category]['tone'] }}"><span class="status-dot"></span>{{ $notificationCategoryMeta[$category]['label'] }}</span>
                                </span>
                                <p>{{ data_get($notification->data, 'message', 'You have a new workforce update.') }}</p>
                                <time datetime="{{ $notification->created_at->toIso8601String() }}">{{ $notification->created_at->diffForHumans() }}</time>
                            </div>
                        </a>
                    @empty
                        <div class="notification-empty">
                            <span class="notification-empty-icon"><x-icon name="check-circle" /></span>
                            <strong>You're all caught up</strong>
                            <span>No unread notifications right now.</span>
                        </div>
                    @endforelse
                </div>

                <a class="notification-footer" href="{{ route('notifications.index') }}">View all notifications</a>
            </div>
        </div>

        <div class="dropdown topbar-profile">
            {{-- `outside` so choosing a theme below does not close the menu out
                 from under the choice. The other items all navigate away, so
                 the menu's state after a click on them is moot either way. --}}
            <button class="topbar-profile-button" type="button" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" aria-label="Open account menu">
                {{-- The name and role are read here rather than only inside the
                     menu: on a shared ward workstation, who is signed in is the
                     thing worth being able to check without opening anything.
                     Hidden on a phone, where the bar has no room for it. --}}
                <span class="avatar avatar-sm">{{ $topbarInitials }}</span>
                <span class="topbar-profile-copy">
                    <strong>{{ $topbarUser->name }}</strong>
                    @if ($topbarRole)
                        <span>{{ $topbarRole }}</span>
                    @endif
                </span>
                <x-icon name="chevron-down" class="topbar-profile-caret" />
            </button>

            <div class="dropdown-menu dropdown-menu-end profile-dropdown topbar-profile-menu">
                <div class="profile-dropdown-header">
                    <span class="avatar">{{ $topbarInitials }}</span>
                    <div>
                        <strong>{{ $topbarUser->name }}</strong>
                        <span>{{ $currentRole ?? 'Employee' }}</span>
                    </div>
                </div>
                <div class="dropdown-divider"></div>
                <a class="dropdown-item" href="{{ route('profile.show') }}"><x-icon name="users" /> My profile</a>
                <a class="dropdown-item" href="{{ route('settings.edit') }}"><x-icon name="settings" /> Account settings</a>
                <div class="dropdown-divider"></div>
                {{-- Three options rather than a flip, so "System" is reachable
                     from here and not just from Account settings. Every button
                     carries the persist URL because theme.js reads it off the
                     button that was clicked. --}}
                <div class="theme-choice">
                    <span class="theme-choice-label" id="topbarThemeLabel">Appearance</span>
                    <div class="theme-choice-options" role="group" aria-labelledby="topbarThemeLabel">
                        @foreach (['light' => ['sun', 'Light'], 'dark' => ['moon', 'Dark'], 'system' => ['settings', 'System']] as $value => [$icon, $label])
                            <button
                                class="theme-choice-option"
                                type="button"
                                data-theme-set="{{ $value }}"
                                data-theme-update-url="{{ route('settings.theme.update') }}"
                                aria-pressed="{{ $topbarTheme === $value ? 'true' : 'false' }}"
                            >
                                <x-icon :name="$icon" />
                                <span>{{ $label }}</span>
                            </button>
                        @endforeach
                    </div>
                </div>
                <div class="dropdown-divider"></div>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button class="dropdown-item text-danger" type="submit"><x-icon name="logout" /> Log out</button>
                </form>
            </div>
        </div>
    </div>
</header>
