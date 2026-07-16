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

    <section class="panel notification-center-panel">
        @forelse($notificationPage as $notification)
            @php
                $tone = in_array(data_get($notification->data, 'tone'), ['success', 'primary', 'warning'], true) ? data_get($notification->data, 'tone') : 'primary';
                $icon = in_array(data_get($notification->data, 'icon'), ['clock', 'calendar', 'leave'], true) ? data_get($notification->data, 'icon') : 'bell';
            @endphp
            <a class="notification-center-item {{ $notification->read_at ? '' : 'is-unread' }}" href="{{ route('notifications.open', $notification->id) }}">
                <span class="notification-icon notification-{{ $tone }}"><x-icon :name="$icon" /></span>
                <span class="notification-center-copy">
                    <strong>{{ data_get($notification->data, 'title', 'HRMS update') }}</strong>
                    <span>{{ data_get($notification->data, 'message', 'You have a new workforce update.') }}</span>
                    <time datetime="{{ $notification->created_at->toIso8601String() }}">{{ $notification->created_at->diffForHumans() }}</time>
                </span>
                @if(!$notification->read_at)<span class="notification-unread-dot" aria-label="Unread"></span>@endif
            </a>
        @empty
            <div class="notification-center-empty">
                <span class="notification-icon notification-success"><x-icon name="check-circle" /></span>
                <strong>No notifications yet</strong>
                <p>Attendance, schedule, and leave updates will appear here.</p>
            </div>
        @endforelse
    </section>

    @if($notificationPage->hasPages())
        <div class="report-pagination">{{ $notificationPage->links() }}</div>
    @endif
@endsection
