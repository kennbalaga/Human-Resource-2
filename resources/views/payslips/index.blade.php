@extends('layouts.app')

@section('title', 'Payslips')

@section('content')
    @php $formatMinutes = fn (int $minutes) => sprintf('%dh %02dm', intdiv($minutes, 60), $minutes % 60); @endphp

    <section class="page-heading workforce-heading">
        <div>
            <p class="eyebrow">Time &amp; attendance</p>
            <h1>Payslips</h1>
            <p>{{ $seesEveryone ? 'Semi-monthly payslips across the workforce, from approved timesheets.' : 'Your semi-monthly payslips, from your approved timesheets.' }}</p>
        </div>
    </section>

    <section class="workforce-stats-grid">
        <article class="report-stat"><span class="report-stat-icon report-stat-blue"><x-icon name="report" /></span><div><span>Payslips</span><strong>{{ number_format($totals['payslips']) }}</strong></div></article>
        <article class="report-stat"><span class="report-stat-icon report-stat-green"><x-icon name="clock" /></span><div><span>Regular hours</span><strong>{{ $formatMinutes($totals['regular_minutes']) }}</strong></div></article>
        <article class="report-stat"><span class="report-stat-icon report-stat-violet"><x-icon name="clock" /></span><div><span>Overtime</span><strong>{{ $formatMinutes($totals['overtime_minutes']) }}</strong></div></article>
        <article class="report-stat"><span class="report-stat-icon report-stat-amber"><x-icon name="alert" /></span><div><span>Late</span><strong>{{ $formatMinutes($totals['late_minutes']) }}</strong></div></article>
    </section>

    <section class="panel workforce-filter-panel">
        <form method="GET" action="{{ route('payslips.index') }}" class="workforce-filters">
            <label><span>From</span><input type="date" name="date_from" value="{{ $filters['date_from'] }}"></label>
            <label><span>To</span><input type="date" name="date_to" value="{{ $filters['date_to'] }}"></label>
            @if($seesEveryone)
                <label><span>Department</span><select name="department_id"><option value="">All departments</option>@foreach($departments as $department)<option value="{{ $department->id }}" @selected(($filters['department_id'] ?? '') == $department->id)>{{ $department->name }}</option>@endforeach</select></label>
                <label><span>Employee</span><select name="employee_id"><option value="">All employees</option>@foreach($employees as $employee)<option value="{{ $employee->id }}" @selected(($filters['employee_id'] ?? '') == $employee->id)>{{ $employee->employee_number }} · {{ $employee->full_name }}</option>@endforeach</select></label>
            @endif
            <button class="btn btn-primary" type="submit">Apply filters</button>
        </form>
    </section>

    <section class="panel workforce-table-panel">
        <div class="panel-header"><div><p class="panel-kicker">Semi-monthly pay periods</p><h2>Issued payslips</h2></div><span class="history-caption">{{ $payslips->total() }} results</span></div>
        <div class="table-responsive">
            <table class="dashboard-table workforce-table table-stack">
                <thead><tr><th>Pay period</th>@if($seesEveryone)<th>Employee</th>@endif<th>Payslip no.</th><th>Regular</th><th>Overtime</th><th>Late</th><th>Days worked</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody>
                    @forelse($payslips as $payslip)
                        <tr>
                            <td data-label="Pay period"><strong>{{ $payslip->period->label() }}</strong></td>
                            @if($seesEveryone)
                                <td data-label="Employee"><div class="employee-cell"><span class="avatar avatar-table">{{ strtoupper(substr($payslip->employee->first_name, 0, 1).substr($payslip->employee->last_name, 0, 1)) }}</span><div><strong>{{ $payslip->employee->full_name }}</strong><span>{{ $payslip->employee->employee_number }} · {{ $payslip->employee->department?->code }}</span></div></div></td>
                            @endif
                            <td data-label="Payslip no."><code>{{ $payslip->period->number($payslip->employee) }}</code></td>
                            <td data-label="Regular">{{ $formatMinutes($payslip->regular_minutes) }}</td>
                            <td data-label="Overtime">{{ $formatMinutes($payslip->overtime_minutes) }}</td>
                            <td data-label="Late">{{ $payslip->late_minutes ? $payslip->late_minutes.'m' : '—' }}</td>
                            <td data-label="Days worked">{{ $payslip->days }}</td>
                            <td data-label="Status">
                                @if($payslip->complete)
                                    <span class="status-badge status-primary"><span class="status-dot"></span>Sent to payroll</span>
                                @else
                                    <span class="status-badge status-warning"><span class="status-dot"></span>Partial</span>
                                @endif
                            </td>
                            <td>
                                <div class="row-action-group">
                                    <a class="btn btn-sm btn-primary" href="{{ route('payslips.show', [$payslip->employee, $payslip->period->key()]) }}">View</a>
                                    <a class="btn btn-sm btn-outline-secondary" data-download href="{{ route('payslips.download', [$payslip->employee, $payslip->period->key()]) }}"><x-icon name="download" /> PDF</a>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="{{ $seesEveryone ? 9 : 8 }}" class="empty-table-cell"><x-icon name="report" /><strong>No payslips yet</strong><span>A payslip appears here as soon as a timesheet in its pay period is approved.</span></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($payslips->hasPages())<div class="report-pagination">{{ $payslips->onEachSide(1)->links('pagination::bootstrap-5') }}</div>@endif
    </section>
@endsection
