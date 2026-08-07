@php
    $displayTimezone = config('workforce.timezone', 'Asia/Manila');
    $displayNow = now($displayTimezone);
@endphp

<header class="app-topbar">
    <div class="topbar-left">
        <button class="mobile-menu-button" type="button" aria-controls="appSidebar" aria-expanded="false" data-sidebar-toggle>
            <x-icon name="menu" />
            <span class="visually-hidden">Open navigation</span>
        </button>
    </div>

    <div class="topbar-actions">
        <time
            class="topbar-date d-none d-lg-flex"
            datetime="{{ $displayNow->toIso8601String() }}"
            data-topbar-clock
            data-timezone="{{ $displayTimezone }}"
            data-server-epoch="{{ $displayNow->getTimestamp() }}"
            title="Philippine time ({{ $displayTimezone }})"
        >
            <x-icon name="calendar" />
            <span class="topbar-clock-copy">
                <strong class="topbar-clock-time" data-topbar-time>{{ $displayNow->format('g:i:s A') }}</strong>
                <span class="topbar-clock-date" data-topbar-date>{{ $displayNow->format('D, M j, Y') }}</span>
            </span>
        </time>

        <div class="global-search-wrapper" data-global-search>
            <form class="global-search" method="GET" action="{{ route('search.index') }}" role="search" autocomplete="off">
                <x-icon name="search" />
                <label class="visually-hidden" for="globalSearchInput">Search employees and departments</label>
                <input
                    id="globalSearchInput"
                    type="search"
                    name="q"
                    value="{{ request('q') }}"
                    placeholder="Search employees, departments..."
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

        <button
            class="icon-button theme-toggle"
            type="button"
            data-theme-toggle
            data-theme-update-url="{{ route('settings.theme.update') }}"
            aria-label="Switch colour theme"
            title="Switch colour theme"
        >
            <x-icon name="sun" class="ui-icon theme-toggle-icon theme-toggle-sun" />
            <x-icon name="moon" class="ui-icon theme-toggle-icon theme-toggle-moon" />
        </button>

        <div class="dropdown">
            <button class="icon-button notification-button" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Open notifications">
                <x-icon name="bell" />
                @if ($notificationUnreadCount > 0)
                    <span class="notification-count">{{ $notificationUnreadCount > 99 ? '99+' : $notificationUnreadCount }}</span>
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
                            'security' => ['label' => 'Security', 'tone' => 'danger'],
                            'general' => ['label' => 'General', 'tone' => 'secondary'],
                        ];
                    @endphp
                    @forelse ($notificationItems as $notification)
                        @php
                            $tone = in_array(data_get($notification->data, 'tone'), ['success', 'primary', 'warning'], true) ? data_get($notification->data, 'tone') : 'primary';
                            $icon = in_array(data_get($notification->data, 'icon'), ['clock', 'calendar', 'leave', 'shield'], true) ? data_get($notification->data, 'icon') : 'bell';
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
    </div>
</header>
