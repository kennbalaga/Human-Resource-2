@props(['overtime'])

<section class="panel staff-overtime" id="overtime-summary" aria-labelledby="overtime-summary-title">
    <div class="panel-header">
        <div>
            <p class="panel-kicker">This month</p>
            <h2 id="overtime-summary-title">My overtime summary</h2>
        </div>
        <span class="staff-panel-meta">{{ $overtime['month_label'] }}</span>
    </div>

    <div class="staff-overtime-body">
        <p class="staff-hero-figure">
            <b>{{ $overtime['total_hours'] }}</b>
            <span>Total overtime, recorded on my own attendance</span>
        </p>

        {{-- Approved and pending are the two parts of the total above, so the split
             is drawn as one bar rather than two competing figures. --}}
        <div
            class="staff-split-bar"
            role="img"
            aria-label="{{ $overtime['approved_hours'] }} approved and {{ $overtime['pending_hours'] }} pending of {{ $overtime['total_hours'] }} total overtime."
        >
            <i class="staff-split-approved" style="flex-grow: {{ max(0, $overtime['total_minutes'] - $overtime['pending_minutes']) }}"></i>
            <i class="staff-split-pending" style="flex-grow: {{ $overtime['pending_minutes'] }}"></i>
        </div>

        <ul class="staff-legend">
            <li>
                <span class="staff-legend-swatch is-approved" aria-hidden="true"></span>
                <span>Approved</span>
                <b>{{ $overtime['approved_hours'] }}</b>
            </li>
            <li>
                <span class="staff-legend-swatch is-pending" aria-hidden="true"></span>
                <span>Pending</span>
                <b>{{ $overtime['pending_hours'] }}</b>
            </li>
        </ul>

        <p class="staff-overtime-note">
            @if ($overtime['days'] > 0)
                Logged across {{ $overtime['days'] }} {{ Str::plural('day', $overtime['days']) }} this month.
            @else
                No overtime recorded this month.
            @endif
        </p>
    </div>

    <a href="{{ route('timesheets.index') }}" class="panel-footer-link">View overtime <x-icon name="chevron-right" /></a>
</section>
