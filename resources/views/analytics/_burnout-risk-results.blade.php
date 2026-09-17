{{-- Everything on the Burnout Risk tab that the filters change. Rendered inside
     the page on a normal visit, and on its own when the live filters fetch it,
     so the two can never drift apart. --}}
@php
    $windowDays = (int) config('burnout.window_days');
    $toneFor = fn (string $level) => match ($level) {
        'high' => 'danger',
        'moderate' => 'warning',
        default => 'success',
    };
    $trendIcon = fn (string $trend) => match ($trend) {
        'rising' => 'arrow-up',
        'easing' => 'arrow-down',
        default => 'trend',
    };
    $trendLabel = fn (array $risk) => match ($risk['trend']) {
        'rising' => 'Up '.number_format(abs($risk['change']), 1),
        'easing' => 'Down '.number_format(abs($risk['change']), 1),
        'steady' => 'Steady',
        default => 'New',
    };
    $narrowedTo = collect([
        ($filters['level'] ?? null) ? ucfirst($filters['level']).' risk' : null,
        ($filters['trend'] ?? null) ? ucfirst($filters['trend']).' trend' : null,
    ])->filter()->implode(', ');
@endphp

<section class="analytics-metric-grid">
    <article class="analytics-metric"><span class="analytics-metric-icon red"><x-icon name="alert" /></span><div><span>High risk</span><strong>{{ number_format($summary['high']) }}</strong><small>Protected by the scheduling assistants</small></div></article>
    <article class="analytics-metric"><span class="analytics-metric-icon amber"><x-icon name="clock" /></span><div><span>Moderate risk</span><strong>{{ number_format($summary['moderate']) }}</strong><small>Worth watching before it climbs</small></div></article>
    <article class="analytics-metric"><span class="analytics-metric-icon violet"><x-icon name="arrow-up" /></span><div><span>Rising</span><strong>{{ number_format($summary['rising']) }}</strong><small>Up on the {{ $windowDays }} days before</small></div></article>
    <article class="analytics-metric"><span class="analytics-metric-icon green"><x-icon name="check-circle" /></span><div><span>Low risk</span><strong>{{ number_format($summary['low']) }}</strong><small>Of {{ number_format($summary['assessed']) }} active employees assessed</small></div></article>
    <article class="analytics-metric"><span class="analytics-metric-icon blue"><x-icon name="trend" /></span><div><span>Average score</span><strong>{{ number_format($summary['average'], 1) }}</strong><small>Out of 100, across {{ ($filters['department_id'] ?? null) ? 'the department' : 'everyone assessed' }}</small></div></article>
    <article class="analytics-metric"><span class="analytics-metric-icon green"><x-icon name="shield" /></span><div><span>Protection</span><strong>{{ config('burnout.protection.enabled') ? 'On' : 'Off' }}</strong><small>{{ config('burnout.protection.days_off_per_week') }} rest days, {{ config('burnout.protection.max_weekly_hours') }}h and {{ config('burnout.protection.max_night_shifts_per_week') }} nights a week max</small></div></article>
</section>

<section class="analytics-grid">
    @if ($departmentBreakdown->count() > 1)
        <article class="panel analytics-panel analytics-wide">
            <div class="panel-header"><div><p class="panel-kicker">By department</p><h2>Where the strain is</h2></div></div>
            <div class="table-responsive"><table class="dashboard-table analytics-table table-stack"><thead><tr><th>Department</th><th>Assessed</th><th>High</th><th>Moderate</th><th>Rising</th><th>Average score</th></tr></thead><tbody>
                @foreach ($departmentBreakdown as $department)
                    <tr>
                        <td data-label="Department"><strong>{{ $department['name'] }}</strong><small>{{ $department['code'] }}</small></td>
                        <td data-label="Assessed">{{ $department['assessed'] }}</td>
                        <td data-label="High">@if ($department['high'])<span class="staff-chip staff-chip-danger">{{ $department['high'] }}</span>@else 0 @endif</td>
                        <td data-label="Moderate">@if ($department['moderate'])<span class="staff-chip staff-chip-warning">{{ $department['moderate'] }}</span>@else 0 @endif</td>
                        <td data-label="Rising">{{ $department['rising'] }}</td>
                        <td data-label="Average score"><div class="rate-cell"><div class="horizontal-track"><i class="burnout-track-{{ $department['average'] >= config('burnout.high_score') ? 'high' : ($department['average'] >= config('burnout.moderate_score') ? 'moderate' : 'low') }}" style="width:{{ min(100, $department['average']) }}%"></i></div><strong>{{ number_format($department['average'], 1) }}</strong></div></td>
                    </tr>
                @endforeach
            </tbody></table></div>
        </article>
    @endif

    <article class="panel analytics-panel analytics-wide">
        <div class="panel-header">
            <div><p class="panel-kicker">Highest risk first</p><h2>Employees</h2></div>
            <span class="history-caption"><span data-burnout-count>{{ number_format($rows->total()) }} {{ Str::plural('employee', $rows->total()) }}</span>@if ($narrowedTo) · {{ $narrowedTo }}@endif</span>
        </div>
        <div class="table-responsive">
            <table class="dashboard-table analytics-table burnout-table table-stack">
                <thead><tr><th>Employee</th><th>Level</th><th>Score</th><th>Trend</th><th>Mostly from</th><th>Last 12 weeks</th></tr></thead>
                <tbody>
                    @forelse ($rows as $row)
                        @php($employee = $row['employee'])
                        @php($risk = $row['risk'])
                        <tr>
                            <td data-label="Employee">
                                <strong>{{ $employee->full_name }}</strong>
                                <small>{{ $employee->employee_number }} · {{ $employee->position?->title ?? 'No position' }} · {{ $employee->department?->name ?? 'No department' }}</small>
                            </td>
                            <td data-label="Level"><span class="staff-chip staff-chip-{{ $toneFor($risk['level']) }}">{{ $risk['level_label'] }}</span></td>
                            <td data-label="Score">
                                <div class="rate-cell"><div class="horizontal-track"><i class="burnout-track-{{ $risk['level'] }}" style="width:{{ max(2, min(100, $risk['score'])) }}%"></i></div><strong>{{ number_format($risk['score'], 1) }}</strong></div>
                            </td>
                            <td data-label="Trend">
                                <span class="burnout-trend is-{{ $risk['trend'] }}"><x-icon :name="$trendIcon($risk['trend'])" /> {{ $trendLabel($risk) }}</span>
                            </td>
                            <td data-label="Mostly from">
                                @if (count($risk['drivers']))
                                    <details class="burnout-factors">
                                        <summary>{{ collect($risk['drivers'])->take(2)->pluck('label')->implode(', ') }}</summary>
                                        <ul>
                                            @foreach ($risk['factors'] as $factor)
                                                <li @class(['is-zero' => $factor['points'] <= 0])><span>{{ $factor['summary'] }}</span><b>{{ number_format($factor['points'], 1) }} / {{ number_format($factor['maximum']) }}</b></li>
                                            @endforeach
                                        </ul>
                                    </details>
                                @else
                                    <span class="burnout-muted">Nothing notable</span>
                                @endif
                            </td>
                            <td data-label="Last 12 weeks">
                                @if ($points = $sparklines->get($employee->id))
                                    <svg class="burnout-sparkline is-{{ $risk['level'] }}" viewBox="0 0 96 28" width="96" height="28" role="img" aria-label="Score history for {{ $employee->full_name }}"><polyline points="{{ $points }}" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" stroke-linecap="round" /></svg>
                                @else
                                    <span class="burnout-muted">Building up</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="empty-table-cell">Nobody matches these filters.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($rows->hasPages())<div class="report-pagination">{{ $rows->onEachSide(1)->links('pagination::bootstrap-5') }}</div>@endif
    </article>
</section>
