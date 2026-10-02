@extends('layouts.app')

@section('title', 'Timesheet Management')

@section('content')
    @php $formatMinutes = fn (int $minutes) => sprintf('%dh %02dm', intdiv($minutes, 60), $minutes % 60); @endphp

    <section class="page-heading workforce-heading">
        <div><p class="eyebrow">Workforce Management</p><h1>Timesheet Management</h1><p>Weekly work records generated from completed and approved attendance.</p></div>
        @if(auth()->user()->canManageData())<a class="btn btn-primary dashboard-action" data-download href="{{ route('timesheets.export', request()->query()) }}"><x-icon name="download" /> Export CSV</a>@endif
    </section>

    @if ($errors->any())<div class="attendance-alert attendance-alert-danger"><x-icon name="close" /><span>{{ $errors->first() }}</span></div>@endif

    <section class="workforce-stats-grid">
        <article class="report-stat"><span class="report-stat-icon report-stat-blue"><x-icon name="timesheet" /></span><div><span>Timesheets</span><strong>{{ number_format($summary['total']) }}</strong></div></article>
        <article class="report-stat"><span class="report-stat-icon report-stat-amber"><x-icon name="clock" /></span><div><span>Awaiting review</span><strong>{{ number_format($summary['submitted']) }}</strong></div></article>
        <article class="report-stat"><span class="report-stat-icon report-stat-green"><x-icon name="check-circle" /></span><div><span>Approved</span><strong>{{ number_format($summary['approved']) }}</strong></div></article>
        <article class="report-stat"><span class="report-stat-icon report-stat-violet"><x-icon name="clock" /></span><div><span>Total recorded</span><strong>{{ $formatMinutes($summary['minutes']) }}</strong></div></article>
    </section>

    <section class="panel workforce-filter-panel">
        <form method="GET" action="{{ route('timesheets.index') }}" class="workforce-filters">
            <label><span>From</span><input type="date" name="date_from" value="{{ $filters['date_from'] }}"></label>
            <label><span>To</span><input type="date" name="date_to" value="{{ $filters['date_to'] }}"></label>
            @if($canManage)
                <label><span>Department</span><select name="department_id"><option value="">All departments</option>@foreach($departments as $department)<option value="{{ $department->id }}" @selected(($filters['department_id'] ?? '') == $department->id)>{{ $department->name }}</option>@endforeach</select></label>
                <label><span>Employee</span><select name="employee_id"><option value="">All employees</option>@foreach($employees as $employee)<option value="{{ $employee->id }}" @selected(($filters['employee_id'] ?? '') == $employee->id)>{{ $employee->employee_number }} · {{ $employee->full_name }}</option>@endforeach</select></label>
            @endif
            <label><span>Status</span><select name="status"><option value="">All statuses</option>@foreach(['draft', 'submitted', 'approved', 'rejected'] as $status)<option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ str($status)->headline() }}</option>@endforeach</select></label>
            <button class="btn btn-primary" type="submit">Apply filters</button>
        </form>
    </section>

    <section class="panel workforce-table-panel">
        <div class="panel-header"><div><p class="panel-kicker">Weekly records</p><h2>Attendance-generated timesheets</h2></div><span class="history-caption">{{ $timesheets->total() }} results</span></div>
        <div class="table-responsive">
            <table class="dashboard-table workforce-table table-stack">
                <thead><tr><th>Period</th><th>Employee</th><th>Regular</th><th>Overtime</th><th>Late</th><th>Entries</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody>
                    @forelse($timesheets as $timesheet)
                        <tr>
                            <td data-label="Period"><strong>{{ $timesheet->period_start->format('M j') }}–{{ $timesheet->period_end->format('M j, Y') }}</strong></td>
                            <td data-label="Employee"><div class="employee-cell"><span class="avatar avatar-table">{{ strtoupper(substr($timesheet->employee->first_name, 0, 1).substr($timesheet->employee->last_name, 0, 1)) }}</span><div><strong>{{ $timesheet->employee->full_name }}</strong><span>{{ $timesheet->employee->employee_number }} · {{ $timesheet->employee->department?->code }}</span></div></div></td>
                            <td data-label="Regular">{{ $formatMinutes($timesheet->regular_minutes) }}</td>
                            <td data-label="Overtime">{{ $formatMinutes($timesheet->overtime_minutes) }}</td>
                            <td data-label="Late">{{ $timesheet->late_minutes ? $timesheet->late_minutes.'m' : '—' }}</td>
                            <td data-label="Entries"><button class="text-action" type="button" data-bs-toggle="collapse" data-bs-target="#timesheet-{{ $timesheet->id }}">{{ $timesheet->entries->count() }} days</button></td>
                            <td data-label="Status"><x-status-badge :status="$timesheet->status" /></td>
                            <td>
                                <div class="row-action-group">
                                    @if(in_array($timesheet->status, ['draft', 'rejected']) && $timesheet->employee_id === auth()->user()->employee?->id && auth()->user()->canManageData())
                                        <form method="POST" action="{{ route('timesheets.submit', $timesheet) }}" class="timesheet-submit-form">@csrf<button class="btn btn-sm btn-primary" type="submit" aria-describedby="timesheet-submit-note-{{ $timesheet->id }}">Submit</button><small class="timesheet-submit-note" id="timesheet-submit-note-{{ $timesheet->id }}">You can’t edit it after submitting.</small></form>
                                    @endif
                                    @if($canManageData && $timesheet->status === 'submitted')
                                        <form method="POST" action="{{ route('timesheets.approve', $timesheet) }}">@csrf<button class="btn btn-sm btn-success" type="submit">Approve</button></form>
                                        <form method="POST" action="{{ route('timesheets.reject', $timesheet) }}" class="inline-review-form">@csrf<input name="reviewer_notes" placeholder="Return reason" minlength="5" required><button class="btn btn-sm btn-outline-danger" type="submit">Return</button></form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                        <tr class="collapse workforce-detail-row" id="timesheet-{{ $timesheet->id }}"><td colspan="8"><div class="timesheet-entry-grid">
                            @forelse($timesheet->entries as $entry)
                                <article><span>{{ $entry->work_date->format('D, M j') }}</span><strong>{{ $formatMinutes($entry->regular_minutes + $entry->overtime_minutes) }}</strong><small>Regular {{ $formatMinutes($entry->regular_minutes) }} @if($entry->overtime_minutes) · OT {{ $entry->overtime_minutes }}m @endif</small></article>
                            @empty <p>No approved attendance entries.</p> @endforelse
                        </div></td></tr>
                    @empty
                        <tr><td colspan="8" class="empty-table-cell"><x-icon name="timesheet" /><strong>No timesheets found</strong><span>Approve completed attendance records to generate weekly timesheets.</span></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($timesheets->hasPages())<div class="report-pagination">{{ $timesheets->onEachSide(1)->links('pagination::bootstrap-5') }}</div>@endif
    </section>
@endsection
