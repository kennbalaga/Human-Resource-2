@props(['status'])

@php
    $normalizedStatus = strtolower($status);
    $tone = match ($normalizedStatus) {
        'active', 'approved', 'completed' => 'success',
        'inactive', 'rejected', 'terminated' => 'danger',
        'pending', 'on leave' => 'warning',
        default => 'secondary',
    };
@endphp

<span class="status-badge status-{{ $tone }}">
    <span class="status-dot"></span>
    {{ str($status)->headline() }}
</span>
