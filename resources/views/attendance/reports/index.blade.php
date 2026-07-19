@extends('layouts.app')

@section('title', 'Attendance Reports')

@section('content')
    @php
        $formatMinutes = fn (int $minutes) => sprintf('%dh %02dm', intdiv($minutes, 60), $minutes % 60);
    @endphp

    <section class="page-heading attendance-heading">
        <div>
            <p class="eyebrow">Time & Attendance</p>
            <h1>Attendance Reports</h1>
            <p>Review work hours, late arrivals, undertime, and overtime records.</p>
        </div>
        <a class="btn btn-primary dashboard-action" href="{{ route('attendance.reports.export', request()->query()) }}">
            <x-icon name="download" /> Export CSV
        </a>
    </section>

    @if ($errors->any())
        <div class="attendance-alert attendance-alert-danger" role="alert">
            <x-icon name="close" /> <span>{{ $errors->first() }}</span>
        </div>
    @endif

    @if (session('success'))
        <div class="attendance-alert attendance-alert-success" role="status"><x-icon name="check-circle" /> <span>{{ session('success') }}</span></div>
    @endif

    <section class="panel report-filter-panel">
        <form method="GET" action="{{ route('attendance.reports.index') }}" class="report-filters">
            <label>
                <span>From</span>
                <input type="date" name="date_from" value="{{ $filters['date_from'] }}">
            </label>
            <label>
                <span>To</span>
                <input type="date" name="date_to" value="{{ $filters['date_to'] }}">
            </label>
            <label>
                <span>Department</span>
                <select name="department_id">
                    <option value="">All departments</option>
                    @foreach ($departments as $department)
                        <option value="{{ $department->id }}" @selected(($filters['department_id'] ?? '') == $department->id)>{{ $department->name }}</option>
                    @endforeach
                </select>
            </label>
            <label>
                <span>Employee</span>
                <select name="employee_id">
                    <option value="">All employees</option>
                    @foreach ($employees as $employee)
                        <option value="{{ $employee->id }}" @selected(($filters['employee_id'] ?? '') == $employee->id)>{{ $employee->employee_number }} · {{ $employee->full_name }}</option>
                    @endforeach
                </select>
            </label>
            <label>
                <span>Status</span>
                <select name="status">
                    <option value="">All statuses</option>
                    <option value="present" @selected(($filters['status'] ?? '') === 'present')>Present</option>
                    <option value="late" @selected(($filters['status'] ?? '') === 'late')>Late</option>
                </select>
            </label>
            <label>
                <span>Approval</span>
                <select name="approval_status">
                    <option value="">All approvals</option>
                    <option value="pending" @selected(($filters['approval_status'] ?? '') === 'pending')>Pending</option>
                    <option value="approved" @selected(($filters['approval_status'] ?? '') === 'approved')>Approved</option>
                    <option value="rejected" @selected(($filters['approval_status'] ?? '') === 'rejected')>Rejected</option>
                </select>
            </label>
            <label>
                <span>Capture source</span>
                <select name="capture_method">
                    <option value="">All sources</option>
                    <option value="biometric" @selected(($filters['capture_method'] ?? '') === 'biometric')>Biometric</option>
                    <option value="manual" @selected(($filters['capture_method'] ?? '') === 'manual')>Manual website</option>
                    <option value="mixed" @selected(($filters['capture_method'] ?? '') === 'mixed')>Mixed in/out</option>
                </select>
            </label>
            <button class="btn btn-primary" type="submit">Apply filters</button>
        </form>
    </section>

    <section class="report-stats-grid" aria-label="Attendance report summary">
        <article class="report-stat"><span class="report-stat-icon report-stat-blue"><x-icon name="report" /></span><div><span>Total records</span><strong>{{ number_format($summary['records']) }}</strong></div></article>
        <article class="report-stat"><span class="report-stat-icon report-stat-green"><x-icon name="check-circle" /></span><div><span>Present</span><strong>{{ number_format($summary['present']) }}</strong></div></article>
        <article class="report-stat"><span class="report-stat-icon report-stat-amber"><x-icon name="clock" /></span><div><span>Late</span><strong>{{ number_format($summary['late']) }}</strong></div></article>
        <article class="report-stat"><span class="report-stat-icon report-stat-red"><x-icon name="users" /></span><div><span>Absent</span><strong>{{ $summary['absent'] === null ? '—' : number_format($summary['absent']) }}</strong></div></article>
        <article class="report-stat"><span class="report-stat-icon report-stat-violet"><x-icon name="clock" /></span><div><span>Total work</span><strong>{{ $formatMinutes($summary['worked_minutes']) }}</strong></div></article>
        <article class="report-stat"><span class="report-stat-icon report-stat-green"><x-icon name="arrow-up" /></span><div><span>Overtime</span><strong>{{ $formatMinutes($summary['overtime_minutes']) }}</strong></div></article>
        <article class="report-stat"><span class="report-stat-icon report-stat-amber"><x-icon name="check-circle" /></span><div><span>Pending approval</span><strong>{{ number_format($summary['pending_approval']) }}</strong></div></article>
    </section>

    <section class="panel attendance-report-table-panel">
        <div class="panel-header">
            <div>
                <p class="panel-kicker">Detailed records</p>
                <h2>{{ \Carbon\Carbon::parse($filters['date_from'])->format('M j, Y') }} – {{ \Carbon\Carbon::parse($filters['date_to'])->format('M j, Y') }}</h2>
            </div>
            <span class="history-caption">{{ number_format($summary['records']) }} records</span>
        </div>

        <div class="table-responsive">
            <table class="dashboard-table report-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Employee</th>
                        <th>Department</th>
                        <th>In</th>
                        <th>In source</th>
                        <th>Out</th>
                        <th>Out source</th>
                        <th>Worked</th>
                        <th>Late</th>
                        <th>Undertime</th>
                        <th>Overtime</th>
                        <th>Status</th>
                        <th>Approval</th>
                        <th>Timesheet action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($records as $record)
                        <tr>
                            <td><strong>{{ $record->attendance_date->format('M j, Y') }}</strong></td>
                            <td>
                                <div class="employee-cell">
                                    <span class="avatar avatar-table">{{ strtoupper(substr($record->employee->first_name, 0, 1).substr($record->employee->last_name, 0, 1)) }}</span>
                                    <div><strong>{{ $record->employee->full_name }}</strong><span>{{ $record->employee->employee_number }}</span></div>
                                </div>
                            </td>
                            <td>{{ $record->employee->department?->name ?? 'Unassigned' }}</td>
                            <td>{{ $record->check_in_at?->timezone($record->officeLocation?->timezone ?? 'Asia/Manila')->format('g:i A') ?? '—' }}</td>
                            <td><strong>{{ str($record->check_in_method ?? 'manual')->title() }}</strong>@if($record->checkInBiometricDevice)<small>{{ $record->checkInBiometricDevice->name }}</small>@endif</td>
                            <td>{{ $record->check_out_at?->timezone($record->officeLocation?->timezone ?? 'Asia/Manila')->format('g:i A') ?? '—' }}</td>
                            <td>@if($record->check_out_at)<strong>{{ str($record->check_out_method ?? 'manual')->title() }}</strong>@if($record->checkOutBiometricDevice)<small>{{ $record->checkOutBiometricDevice->name }}</small>@endif @else — @endif</td>
                            <td>{{ $record->worked_hours }}</td>
                            <td>{{ $record->late_minutes ? $record->late_minutes.'m' : '—' }}</td>
                            <td>{{ $record->undertime_minutes ? $record->undertime_minutes.'m' : '—' }}</td>
                            <td>{{ $record->overtime_minutes ? $record->overtime_minutes.'m' : '—' }}</td>
                            <td><x-status-badge :status="$record->status" /></td>
                            <td><x-status-badge :status="$record->approval_status" /></td>
                            <td>
                                @if ($record->check_out_at && $record->approval_status !== 'approved')
                                    <div class="attendance-approval-actions">
                                        <form method="POST" action="{{ route('attendance.records.approve', $record) }}">@csrf<button class="btn btn-sm btn-success" type="submit">Approve</button></form>
                                        <form method="POST" action="{{ route('attendance.records.reject', $record) }}" class="attendance-reject-form">@csrf<input type="text" name="rejection_reason" minlength="5" maxlength="500" placeholder="Reason" required><button class="btn btn-sm btn-outline-danger" type="submit">Reject</button></form>
                                    </div>
                                @elseif ($record->approval_status === 'approved')
                                    <span class="history-caption">Added to timesheet</span>
                                @else
                                    <span class="history-caption">Awaiting check-out</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="14" class="empty-table-cell"><x-icon name="report" /><strong>No matching records</strong><span>Try changing the date range or filters.</span></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($records->hasPages())
            <div class="report-pagination">{{ $records->onEachSide(1)->links('pagination::bootstrap-5') }}</div>
        @endif
    </section>
@endsection
