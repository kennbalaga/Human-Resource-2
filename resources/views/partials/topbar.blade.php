@php
    $userInitials = collect(explode(' ', auth()->user()->name))
        ->filter()
        ->take(2)
        ->map(fn ($part) => strtoupper(substr($part, 0, 1)))
        ->implode('');
@endphp

<header class="app-topbar">
    <div class="topbar-left">
        <button class="mobile-menu-button" type="button" aria-controls="appSidebar" aria-expanded="false" data-sidebar-toggle>
            <x-icon name="menu" />
            <span class="visually-hidden">Open navigation</span>
        </button>

        <label class="global-search">
            <x-icon name="search" />
            <span class="visually-hidden">Search HRMS</span>
            <input type="search" placeholder="Search employees, departments..." aria-label="Search HRMS">
            <kbd>⌘ K</kbd>
        </label>
    </div>

    <div class="topbar-actions">
        <div class="topbar-date d-none d-lg-flex">
            <x-icon name="calendar" />
            <span>{{ now($uiPreference->timezone ?? 'Asia/Manila')->format('D, M j, Y') }}</span>
        </div>

        <button class="icon-button theme-toggle" type="button" data-theme-toggle data-theme-update-url="{{ route('settings.theme.update') }}" aria-label="Switch color theme" title="Switch color theme">
            <span class="theme-icon theme-icon-moon"><x-icon name="moon" /></span>
            <span class="theme-icon theme-icon-sun"><x-icon name="sun" /></span>
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
                    @forelse ($notificationItems as $notification)
                        @php
                            $tone = in_array(data_get($notification->data, 'tone'), ['success', 'primary', 'warning'], true) ? data_get($notification->data, 'tone') : 'primary';
                            $icon = in_array(data_get($notification->data, 'icon'), ['clock', 'calendar', 'leave', 'shield'], true) ? data_get($notification->data, 'icon') : 'bell';
                        @endphp
                        <a class="notification-item" href="{{ route('notifications.open', $notification->id) }}">
                            <span class="notification-icon notification-{{ $tone }}">
                                <x-icon :name="$icon" />
                            </span>
                            <div>
                                <strong>{{ data_get($notification->data, 'title', 'HRMS update') }}</strong>
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

        <span class="topbar-divider"></span>

        <div class="dropdown">
            <button class="profile-menu-button" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                <span class="avatar">{{ $userInitials }}</span>
                <span class="profile-menu-copy d-none d-sm-flex">
                    <strong>{{ auth()->user()->name }}</strong>
                    <small>{{ $currentRole ?? 'Employee' }}</small>
                </span>
                <x-icon name="chevron-down" class="d-none d-sm-block" />
            </button>

            <div class="dropdown-menu dropdown-menu-end profile-dropdown">
                <div class="profile-dropdown-header">
                    <span class="avatar">{{ $userInitials }}</span>
                    <div>
                        <strong>{{ auth()->user()->name }}</strong>
                        <span>{{ auth()->user()->email }}</span>
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
</header>
