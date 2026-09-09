@props(['status'])

@php
    $normalizedStatus = strtolower($status);
    $tone = match ($normalizedStatus) {
        'active', 'approved', 'completed', 'passed', 'upcoming' => 'success',
        'inactive', 'rejected', 'terminated', 'failed', 'declined_by_target' => 'danger',
        'pending', 'on leave', 'pending_target', 'pending_manager', 'passed_with_warnings' => 'warning',
        // Leave being taken right now reads apart from leave merely booked in.
        'ongoing' => 'primary',
        default => 'secondary',
    };
@endphp

<span class="status-badge status-{{ $tone }}">
    <span class="status-dot"></span>
    {{ str($status)->headline() }}
</span>
