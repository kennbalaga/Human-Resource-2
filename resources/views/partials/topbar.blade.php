@php
    $notificationItems = $notifications ?? collect();
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
                @if ($notificationItems->isNotEmpty())
                    <span class="notification-count">{{ $notificationItems->count() }}</span>
                @endif
            </button>

            <div class="dropdown-menu dropdown-menu-end notifications-menu">
                <div class="notification-header">
                    <div>
                        <strong>Notifications</strong>
                        <span>{{ $notificationItems->count() }} new updates</span>
                    </div>
                    <button type="button">Mark all as read</button>
                </div>

                <div class="notification-list">
                    @forelse ($notificationItems as $notification)
                        <article class="notification-item">
                            <span class="notification-icon notification-{{ $notification['tone'] }}">
                                <x-icon :name="$notification['icon']" />
                            </span>
                            <div>
                                <strong>{{ $notification['title'] }}</strong>
                                <p>{{ $notification['message'] }}</p>
                                <time>{{ $notification['time'] }}</time>
                            </div>
                        </article>
                    @empty
                        <p class="notification-empty">You are all caught up.</p>
                    @endforelse
                </div>

                <a class="notification-footer" href="#">View all notifications</a>
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
