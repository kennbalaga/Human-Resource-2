@props(['summary'])

@php
    $tallies = [
        ['key' => 'present', 'label' => 'Present', 'value' => $summary['present']],
        ['key' => 'late', 'label' => 'Late', 'value' => $summary['late']],
        ['key' => 'absent', 'label' => 'Absent', 'value' => $summary['absent']],
        ['key' => 'leave', 'label' => 'Leave', 'value' => $summary['leave']],
    ];
@endphp

<section class="panel staff-summary" id="attendance-summary" aria-labelledby="attendance-summary-title">
    <div class="panel-header">
        <div>
            <p class="panel-kicker">This month</p>
            <h2 id="attendance-summary-title">My attendance summary</h2>
        </div>
        <span class="staff-panel-meta">{{ $summary['month_label'] }}</span>
    </div>

    <div class="staff-summary-body">
        <dl class="staff-tallies">
            @foreach ($tallies as $tally)
                <div class="staff-tally staff-tally-{{ $tally['key'] }}">
                    <dt>{{ $tally['label'] }}</dt>
                    <dd>{{ number_format($tally['value']) }}</dd>
                </div>
            @endforeach
        </dl>

        <div class="staff-meter-block">
            <p class="staff-meter-head">
                <span>Attendance rate</span>
                <b>{{ rtrim(rtrim(number_format($summary['rate'], 1), '0'), '.') }}%</b>
            </p>
            <div
                class="staff-meter"
                role="progressbar"
                aria-label="Attendance rate this month"
                aria-valuenow="{{ round($summary['rate']) }}"
                aria-valuemin="0"
                aria-valuemax="100"
            >
                <span style="width: {{ min(100, max(0, $summary['rate'])) }}%"></span>
            </div>
            <p class="staff-meter-note">
                @if ($summary['tracked'])
                    {{ number_format($summary['present'] + $summary['late']) }} of {{ number_format($summary['expected']) }} expected {{ Str::plural('day', $summary['expected']) }} attended. Approved leave is not counted against you.
                @else
                    No rostered days have closed yet this month.
                @endif
            </p>
        </div>
    </div>

    <a href="{{ route('attendance.index') }}" class="panel-footer-link">View my attendance <x-icon name="chevron-right" /></a>
</section>
