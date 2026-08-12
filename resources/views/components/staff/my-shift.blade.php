@props(['shift'])

<section
    class="panel staff-shift staff-shift-{{ $shift['kind'] }}"
    id="my-shift"
    aria-labelledby="my-shift-title"
    @if ($shift['color']) style="--shift-color: {{ $shift['color'] }}" @endif
>
    <div class="panel-header">
        <div>
            <p class="panel-kicker">My schedule</p>
            <h2 id="my-shift-title">My shift today</h2>
        </div>
    </div>

    <div class="staff-shift-body">
        <p class="staff-shift-name">
            @if ($shift['kind'] === 'shift')
                <span class="staff-shift-dot" aria-hidden="true"></span>
            @endif
            <strong>{{ $shift['name'] }}</strong>
            @if ($shift['kind'] === 'shift' && ($shift['crosses_midnight'] ?? false))
                <span class="staff-shift-tag">Overnight</span>
            @endif
        </p>

        <p class="staff-shift-department">
            <x-icon name="hospital" />
            <span>{{ $shift['department'] }}</span>
        </p>

        <p class="staff-shift-hours">{{ $shift['hours'] }}</p>

        <ul class="staff-detail-list">
            <li>
                <span>Shift status</span>
                <b class="staff-detail-tone-{{ $shift['status_tone'] }}">{{ $shift['status'] }}</b>
            </li>
            <li>
                <span>Position</span>
                <b>{{ $shift['position'] }}</b>
            </li>
            <li>
                <span>Assigned area</span>
                <b>{{ $shift['area'] }}</b>
            </li>
        </ul>
    </div>

    <a href="{{ route('schedules.index') }}" class="panel-footer-link">View my schedule <x-icon name="chevron-right" /></a>
</section>
