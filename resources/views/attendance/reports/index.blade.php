@extends('layouts.app')

@section('title', $report->heading())

@section('content')
    @include('reports._heading')
    @include('reports._filters', ['route' => 'reports.show', 'routeParameters' => ['report' => $report->key()]])

    <section class="panel attendance-report-table-panel">
        <div class="panel-header">
            <div>
                <p class="panel-kicker">Detailed records</p>
                <h2>{{ \Carbon\Carbon::parse($filters['date_from'])->format('M j, Y') }} – {{ \Carbon\Carbon::parse($filters['date_to'])->format('M j, Y') }}</h2>
            </div>
            <span class="history-caption">{{ number_format($records->total()) }} {{ str('record')->plural($records->total()) }}</span>
        </div>

        {{--
            Attendance keeps a hand-written table rather than the shared one: the
            employee cell pairs an avatar with the employee number, each capture
            source is shown with the device that recorded it, and the approval
            controls live in the last column. This is the only screen from which
            an attendance record can be approved, so the forms stay here until
            approvals get a home of their own.
        --}}
        <div class="table-responsive">
            <table class="dashboard-table report-table table-stack">
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
                            <td data-label="Date"><strong>{{ $record->attendance_date->format('M j, Y') }}</strong></td>
                            <td data-label="Employee">
                                <div class="employee-cell">
                                    <span class="avatar avatar-table">{{ strtoupper(substr($record->employee->first_name, 0, 1).substr($record->employee->last_name, 0, 1)) }}</span>
                                    <div><strong>{{ $record->employee->full_name }}</strong><span>{{ $record->employee->employee_number }}</span></div>
                                </div>
                            </td>
                            <td data-label="Department">{{ $record->employee->department?->name ?? 'Unassigned' }}</td>
                            <td data-label="In">{{ $record->check_in_at?->timezone($record->officeLocation?->timezone ?? 'Asia/Manila')->format('g:i A') ?? '—' }}</td>
                            <td data-label="In source"><strong>{{ $record->check_in_method_label }}</strong>@if($record->checkInBiometricDevice)<small>{{ $record->checkInBiometricDevice->name }}</small>@endif</td>
                            <td data-label="Out">{{ $record->check_out_at?->timezone($record->officeLocation?->timezone ?? 'Asia/Manila')->format('g:i A') ?? '—' }}</td>
                            <td data-label="Out source">@if($record->check_out_at)<strong>{{ $record->check_out_method_label }}</strong>@if($record->checkOutBiometricDevice)<small>{{ $record->checkOutBiometricDevice->name }}</small>@endif @else — @endif</td>
                            <td data-label="Worked">{{ $record->worked_hours }}</td>
                            <td data-label="Late">{{ $record->late_minutes ? $record->late_minutes.'m' : '—' }}</td>
                            <td data-label="Undertime">{{ $record->undertime_minutes ? $record->undertime_minutes.'m' : '—' }}</td>
                            <td data-label="Overtime">{{ $record->overtime_minutes ? $record->overtime_minutes.'m' : '—' }}</td>
                            <td data-label="Status"><x-status-badge :status="$record->status" /></td>
                            <td data-label="Approval"><x-status-badge :status="$record->approval_status" /></td>
                            <td data-label="Timesheet action">
                                @if ($record->check_out_at && $record->approval_status !== 'approved' && $canManageData)
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
