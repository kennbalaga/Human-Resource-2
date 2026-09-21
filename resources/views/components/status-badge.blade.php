@props(['status'])

@php
    $normalizedStatus = strtolower($status);
    $tone = match ($normalizedStatus) {
        'active', 'approved', 'completed', 'passed', 'upcoming', 'present' => 'success',
        'inactive', 'rejected', 'terminated', 'failed', 'declined_by_target', 'absent' => 'danger',
        // Late was falling through to the neutral grey, which read as "nothing to see".
        'pending', 'on leave', 'pending_target', 'pending_manager', 'passed_with_warnings', 'late' => 'warning',
        // A room out of service: 'maintenance' is temporary and expected,
        // 'closed' is not, so they do not share a tone.
        'maintenance' => 'warning',
        'closed' => 'danger',
        // Leave being taken right now reads apart from leave merely booked in.
        'ongoing' => 'primary',
        default => 'secondary',
    };
@endphp

<span class="status-badge status-{{ $tone }}">
    <span class="status-dot"></span>
    {{ str($status)->headline() }}
</span>
