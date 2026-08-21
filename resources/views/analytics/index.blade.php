@extends('layouts.app')

@section('title', 'Workforce Analytics')

@section('content')
    <section class="page-heading workforce-heading">
        <div><p class="eyebrow">Workforce Intelligence</p><h1>Workforce Analytics</h1><p>Attendance, labor hours, leave utilization, schedule coverage, and timesheet performance.</p></div>
        @if($canManageData)<a class="btn btn-primary dashboard-action" href="{{ route('analytics.export', request()->query()) }}"><x-icon name="download" /> Download report</a>@endif
    </section>

    @if($errors->any())<div class="attendance-alert attendance-alert-danger"><x-icon name="close" /><span>{{ $errors->first() }}</span></div>@endif
    @if(session('success'))<div class="attendance-alert attendance-alert-success"><x-icon name="check-circle" /><span>{{ session('success') }}</span></div>@endif
    @if(session('warning'))<div class="attendance-alert attendance-alert-warning"><x-icon name="ai" /><span>{{ session('warning') }}</span></div>@endif

    <section class="panel workforce-filter-panel">
        <form method="GET" action="{{ route('analytics.index') }}" class="workforce-filters analytics-filters">
            <label class="analytics-date-field"><span>From</span><input type="date" name="date_from" value="{{ $filters['date_from'] }}"></label>
            <label class="analytics-date-field"><span>To</span><input type="date" name="date_to" value="{{ $filters['date_to'] }}"></label>
            <label><span>Department</span><select name="department_id"><option value="">All departments</option>@foreach($departments as $department)<option value="{{ $department->id }}" @selected(($filters['department_id'] ?? '') == $department->id)>{{ $department->name }}</option>@endforeach</select></label>
            <button class="btn btn-primary" type="submit">Update analytics</button>
        </form>
    </section>

    <section class="analytics-metric-grid">
        <article class="analytics-metric"><span class="analytics-metric-icon blue"><x-icon name="users" /></span><div><span>Active headcount</span><strong>{{ number_format($metrics['active_headcount']) }}</strong><small>Current active employees</small></div></article>
        <article class="analytics-metric"><span class="analytics-metric-icon green"><x-icon name="check-circle" /></span><div><span>Attendance rate</span><strong>{{ number_format($metrics['attendance_rate'], 1) }}%</strong><small>Attendance per possible workday</small></div></article>
        <article class="analytics-metric"><span class="analytics-metric-icon violet"><x-icon name="clock" /></span><div><span>Worked hours</span><strong>{{ number_format($metrics['worked_hours'], 1) }}</strong><small>{{ number_format($metrics['overtime_hours'], 1) }} overtime hours</small></div></article>
        <article class="analytics-metric"><span class="analytics-metric-icon amber"><x-icon name="leave" /></span><div><span>Approved leave</span><strong>{{ number_format($metrics['approved_leave_days'], 1) }}</strong><small>Days within selected period</small></div></article>
        <article class="analytics-metric"><span class="analytics-metric-icon red"><x-icon name="clock" /></span><div><span>Late events</span><strong>{{ number_format($metrics['late_events']) }}</strong><small>Recorded late arrivals</small></div></article>
        <article class="analytics-metric"><span class="analytics-metric-icon blue"><x-icon name="calendar" /></span><div><span>Scheduled shifts</span><strong>{{ number_format($metrics['scheduled_shifts']) }}</strong><small>Coverage assignments</small></div></article>
        <article class="analytics-metric"><span class="analytics-metric-icon green"><x-icon name="shield" /></span><div><span>Schedule adherence</span><strong>{{ number_format($metrics['schedule_adherence_rate'], 1) }}%</strong><small>On-shift punches vs. published shifts</small></div></article>
        <article class="analytics-metric"><span class="analytics-metric-icon amber"><x-icon name="alert" /></span><div><span>Off-shift rate</span><strong>{{ number_format($metrics['off_shift_rate'], 1) }}%</strong><small>Punches matching no published shift</small></div></article>
        <article class="analytics-metric"><span class="analytics-metric-icon red"><x-icon name="shield" /></span><div><span>Override rate</span><strong>{{ number_format($metrics['override_rate'], 1) }}%</strong><small>Manager-authorised unscheduled punches</small></div></article>
        <article class="analytics-metric"><span class="analytics-metric-icon violet"><x-icon name="trend" /></span><div><span>Plan vs. actual</span><strong>{{ $metrics['plan_vs_actual_variance_hours'] >= 0 ? '+' : '' }}{{ number_format($metrics['plan_vs_actual_variance_hours'], 1) }}h</strong><small>Avg. worked vs. rostered, per shift</small></div></article>
    </section>

    <section class="analytics-grid">
        <article class="panel analytics-panel analytics-wide ai-insight-panel">
            <div class="panel-header"><div><p class="panel-kicker">Gemini AI · aggregate data only</p><h2>Workforce insight assistant</h2></div><form method="POST" action="{{ route('analytics.ai-insights', request()->query()) }}">@csrf<button class="btn btn-outline-primary" type="submit"><x-icon name="ai" /> Generate insights</button></form></div>
            @if($aiInsight)<div class="ai-insight-copy">{!! nl2br(e($aiInsight)) !!}</div>@else<div class="compact-empty-state"><x-icon name="ai" /><p>Generate a concise interpretation of the selected aggregate metrics. No employee names or personal records are sent.</p></div>@endif
        </article>
        <article class="panel analytics-panel analytics-wide">
            <div class="panel-header"><div><p class="panel-kicker">Daily workforce activity</p><h2>Attendance trend</h2></div><span class="history-caption">{{ date('M j', strtotime($filters['date_from'])) }}–{{ date('M j, Y', strtotime($filters['date_to'])) }}</span></div>
            <div class="attendance-bar-chart" role="img" aria-label="Daily attendance records">
                @foreach($attendanceTrend as $day)
                    <div class="bar-column" title="{{ $day['date'] }}: {{ $day['records'] }} records, {{ $day['hours'] }} hours"><span class="bar-value">{{ $day['records'] ?: '' }}</span><div class="bar-track"><i style="height: {{ max(3, $day['records'] / $chartMax * 100) }}%"></i></div><small>{{ $loop->count <= 16 || $loop->iteration % max(1, intdiv($loop->count, 12)) === 0 ? $day['label'] : '' }}</small></div>
                @endforeach
            </div>
        </article>

        <article class="panel analytics-panel">
            <div class="panel-header"><div><p class="panel-kicker">Leave composition</p><h2>Approved leave usage</h2></div></div>
            <div class="analytics-list-chart">
                @php $leaveMax = max(1, (float)$leaveMix->max('days')); @endphp
                @forelse($leaveMix as $leave)<div class="list-chart-row"><div><span><i style="background:{{ $leave['color'] }}"></i>{{ $leave['name'] }}</span><strong>{{ $leave['days'] }} days</strong></div><div class="horizontal-track"><i style="width:{{ $leave['days'] / $leaveMax * 100 }}%;background:{{ $leave['color'] }}"></i></div></div>@empty<div class="compact-empty-state"><x-icon name="leave" /><p>No approved leave in this period.</p></div>@endforelse
            </div>
        </article>

        <article class="panel analytics-panel">
            <div class="panel-header"><div><p class="panel-kicker">Approval pipeline</p><h2>Timesheet status</h2></div></div>
            <div class="status-chart">
                @php $statusMax = max(1, (int)$timesheetStatuses->max('count')); @endphp
                @foreach($timesheetStatuses as $status)<div><span>{{ str($status['status'])->headline() }}</span><div class="horizontal-track"><i class="status-bar-{{ $status['status'] }}" style="width:{{ $status['count'] / $statusMax * 100 }}%"></i></div><strong>{{ $status['count'] }}</strong></div>@endforeach
            </div>
        </article>

        <article class="panel analytics-panel analytics-wide">
            <div class="panel-header"><div><p class="panel-kicker">Department comparison</p><h2>Workforce performance</h2></div></div>
            <div class="table-responsive"><table class="dashboard-table analytics-table"><thead><tr><th>Department</th><th>Headcount</th><th>Attendance</th><th>Attendance rate</th><th>Avg. worked</th><th>Leave days</th></tr></thead><tbody>
                @forelse($departmentMetrics as $department)<tr><td><strong>{{ $department['name'] }}</strong><small>{{ $department['code'] }}</small></td><td>{{ $department['employees'] }}</td><td>{{ $department['attendance'] }}</td><td><div class="rate-cell"><div class="horizontal-track"><i style="width:{{ $department['attendance_rate'] }}%"></i></div><strong>{{ $department['attendance_rate'] }}%</strong></div></td><td>{{ $department['average_hours'] }}h</td><td>{{ $department['leave_days'] }}</td></tr>@empty<tr><td colspan="6" class="empty-table-cell">No department data.</td></tr>@endforelse
            </tbody></table></div>
        </article>
    </section>
@endsection
