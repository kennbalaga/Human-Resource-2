@props(['status'])

@php
    $normalizedStatus = strtolower($status);
    $tone = match ($normalizedStatus) {
        'active', 'approved', 'completed', 'passed' => 'success',
        'inactive', 'rejected', 'terminated', 'failed', 'declined_by_target' => 'danger',
        'pending', 'on leave', 'pending_target', 'pending_manager', 'passed_with_warnings' => 'warning',
        default => 'secondary',
    };
@endphp

<span class="status-badge status-{{ $tone }}">
    <span class="status-dot"></span>
    {{ str($status)->headline() }}
</span>
