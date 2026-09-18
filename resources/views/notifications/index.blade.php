@extends('layouts.app')

@section('title', 'Notifications')

@section('content')
    <section class="page-heading workforce-heading">
        <div>
            <p class="eyebrow">Notification center</p>
            <h1>Notifications</h1>
            <p>Review attendance reminders and updates to your schedules and leave requests.</p>
        </div>
        @if(auth()->user()->unreadNotifications()->exists())
            <form method="POST" action="{{ route('notifications.read-all') }}">
                @csrf @method('PATCH')
                <button class="btn btn-outline-primary profile-heading-action" type="submit"><x-icon name="check-circle" /> Mark all as read</button>
            </form>
        @endif
    </section>

    @if(session('success'))
        <div class="attendance-alert attendance-alert-success"><x-icon name="check-circle" /><span>{{ session('success') }}</span></div>
    @endif

    @php
        $categoryMeta = [
            'attendance' => ['label' => 'Attendance', 'tone' => 'warning', 'icon' => 'clock', 'description' => 'Punches, lateness and corrections'],
            'schedule' => ['label' => 'Schedule', 'tone' => 'primary', 'icon' => 'calendar', 'description' => 'Published rosters and changes'],
            'leave' => ['label' => 'Leave', 'tone' => 'success', 'icon' => 'leave', 'description' => 'Requests and decisions'],
            'payroll' => ['label' => 'Payroll', 'tone' => 'primary', 'icon' => 'receipt', 'description' => 'Payslips ready to view'],
            'security' => ['label' => 'Security', 'tone' => 'danger', 'icon' => 'shield', 'description' => 'Sign-ins and account changes'],
            'general' => ['label' => 'General', 'tone' => 'secondary', 'icon' => 'bell', 'description' => 'Announcements and the rest'],
        ];
        $totalCount = array_sum($categoryCounts);
    @endphp

    <nav class="page-tabs notification-filter-tabs" aria-label="Filter notifications by category">
        <a class="page-tab notification-filter-tab {{ $activeCategory === null ? 'is-active' : '' }}" href="{{ route('notifications.index') }}">
            <x-page-tab-label icon="inbox" title="All" description="Everything, newest first"><span class="notification-filter-count">{{ $totalCount }}</span></x-page-tab-label>
        </a>
        @foreach($categoryMeta as $key => $meta)
            @continue($key === 'general' && $categoryCounts[$key] === 0 && $activeCategory !== $key)
            <a class="page-tab notification-filter-tab {{ $activeCategory === $key ? 'is-active' : '' }}" href="{{ route('notifications.index', ['category' => $key]) }}">
                <x-page-tab-label :icon="$meta['icon']" :title="$meta['label']" :description="$meta['description']"><span class="notification-filter-count">{{ $categoryCounts[$key] }}</span></x-page-tab-label>
            </a>
        @endforeach
    </nav>

    <p class="notification-retention-note">Notifications are kept for 7 days, then cleared automatically. Use the circle button on each item to mark it as read or unread.</p>

    <section class="panel notification-center-panel">
        @forelse($notificationPage as $notification)
            @php
                $tone = in_array(data_get($notification->data, 'tone'), ['success', 'primary', 'warning'], true) ? data_get($notification->data, 'tone') : 'primary';
                $icon = in_array(data_get($notification->data, 'icon'), ['clock', 'calendar', 'leave', 'report'], true) ? data_get($notification->data, 'icon') : 'bell';
                $category = data_get($notification->data, 'category');
                if (! array_key_exists($category, $categoryMeta)) {
                    $category = match ($icon) {
                        'clock' => 'attendance',
                        'calendar' => 'schedule',
                        'leave' => 'leave',
                        default => 'general',
                    };
                }
            @endphp
            <div class="notification-center-item {{ $notification->read_at ? '' : 'is-unread' }}">
                <a class="notification-center-link" href="{{ route('notifications.open', $notification->id) }}">
                    <span class="notification-icon notification-{{ $tone }}"><x-icon :name="$icon" /></span>
                    <span class="notification-center-copy">
                        <span class="notification-category-row">
                            <strong>{{ data_get($notification->data, 'title', 'HRMS update') }}</strong>
                            <span class="status-badge status-{{ $categoryMeta[$category]['tone'] }}"><span class="status-dot"></span>{{ $categoryMeta[$category]['label'] }}</span>
                        </span>
                        <span>{{ data_get($notification->data, 'message', 'You have a new workforce update.') }}</span>
                        <time datetime="{{ $notification->created_at->toIso8601String() }}">{{ $notification->created_at->diffForHumans() }}</time>
                    </span>
                </a>
                @if(!$notification->read_at)<span class="notification-unread-dot" aria-label="Unread"></span>@endif
                <form method="POST" action="{{ route('notifications.toggle-read', $notification->id) }}" class="notification-read-toggle">
                    @csrf @method('PATCH')
                    <button type="submit" title="{{ $notification->read_at ? 'Mark as unread' : 'Mark as read' }}" aria-label="{{ $notification->read_at ? 'Mark as unread' : 'Mark as read' }}">
                        <x-icon :name="$notification->read_at ? 'circle' : 'check-circle'" />
                    </button>
                </form>
            </div>
        @empty
            <div class="notification-center-empty">
                <span class="notification-icon notification-success"><x-icon name="check-circle" /></span>
                <strong>{{ $activeCategory ? 'No '.strtolower($categoryMeta[$activeCategory]['label']).' notifications' : 'No notifications yet' }}</strong>
                <p>{{ $activeCategory ? 'Nothing in this category right now.' : 'Attendance, schedule, and leave updates will appear here.' }}</p>
            </div>
        @endforelse
    </section>

    @if($notificationPage->hasPages())
        <div class="report-pagination">{{ $notificationPage->links('pagination::bootstrap-5') }}</div>
    @endif
@endsection
