@extends('layouts.app')

@section('title', 'Leave Management')

@section('content')
    @php
        $queryFor = fn(array $values) => route('leaves.index', array_merge(request()->query(), $values));
        $previousMonth = $focusDate->copy()->subMonth()->toDateString();
        $nextMonth = $focusDate->copy()->addMonth()->toDateString();
    @endphp
    <section class="page-heading workforce-heading">
        <div><p class="eyebrow">Workforce Management</p><h1>Leave Management</h1><p>Track balances, submit supporting documents, and manage leave approvals.</p></div>
        <div class="row-action-group">
            @if($canManageLeaveTypes)<button class="btn btn-outline-primary dashboard-action" type="button" data-bs-toggle="modal" data-bs-target="#leaveTypeModal"><x-icon name="plus" /> Add leave type</button>@endif
            <button class="btn btn-primary dashboard-action" type="button" data-bs-toggle="modal" data-bs-target="#leaveRequestModal"><x-icon name="plus" /> Request leave</button>
        </div>
    </section>

    @if(session('success'))<div class="attendance-alert attendance-alert-success"><x-icon name="check-circle" /><span>{{ session('success') }}</span></div>@endif
    @if($errors->any())<div class="attendance-alert attendance-alert-danger"><x-icon name="close" /><span>{{ $errors->first() }}</span></div>@endif

    <section class="workforce-stats-grid">
        <article class="report-stat"><span class="report-stat-icon report-stat-amber"><x-icon name="clock" /></span><div><span>Pending requests</span><strong>{{ $summary['pending'] }}</strong></div></article>
        <article class="report-stat"><span class="report-stat-icon report-stat-green"><x-icon name="check-circle" /></span><div><span>Approved days</span><strong>{{ number_format($summary['approved_days'], 1) }}</strong></div></article>
        <article class="report-stat"><span class="report-stat-icon report-stat-blue"><x-icon name="leave" /></span><div><span>Available balance</span><strong>{{ number_format($summary['available_days'], 1) }}</strong></div></article>
        <article class="report-stat"><span class="report-stat-icon report-stat-violet"><x-icon name="paperclip" /></span><div><span>With documents</span><strong>{{ $summary['attachments'] }}</strong></div></article>
    </section>

    <section class="leave-balance-section">
        <div class="leave-balance-heading">
            <h2>Your leave balances</h2>
            <span>{{ $employee->full_name }} · {{ $filters['year'] }} — your personal remaining days, not company-wide totals.</span>
        </div>
        <div class="leave-balance-grid">
            @foreach($balances as $balance)
                <article class="leave-balance-card" style="--leave-color: {{ $balance->leaveType->color }}"><span class="leave-balance-color"></span><div><span title="{{ $balance->leaveType->name }}">{{ $balance->leaveType->name }}</span><strong>{{ number_format($balance->available_days, 1) }} days</strong><small>{{ number_format((float)$balance->used_days, 1) }} used · {{ number_format((float)$balance->pending_days, 1) }} pending</small></div></article>
            @endforeach
        </div>
    </section>

    <section class="panel workforce-filter-panel">
        <form method="GET" action="{{ route('leaves.index') }}" class="workforce-filters">
            <label><span>Year</span><select name="year">@foreach(range(now()->year - 1, now()->year + 1) as $year)<option value="{{ $year }}" @selected($filters['year'] == $year)>{{ $year }}</option>@endforeach</select></label>
            @if($canManage)
                <label><span>Department</span><select name="department_id"><option value="">All departments</option>@foreach($departments as $department)<option value="{{ $department->id }}" @selected(($filters['department_id'] ?? '') == $department->id)>{{ $department->name }}</option>@endforeach</select></label>
                <label><span>Employee</span><select name="employee_id"><option value="">All employees</option>@foreach($employees as $employee)<option value="{{ $employee->id }}" @selected(($filters['employee_id'] ?? '') == $employee->id)>{{ $employee->employee_number }} · {{ $employee->full_name }}</option>@endforeach</select></label>
            @endif
            <label><span>Leave type</span><select name="leave_type_id"><option value="">All types</option>@foreach($types as $type)<option value="{{ $type->id }}" @selected(($filters['leave_type_id'] ?? '') == $type->id)>{{ $type->name }}</option>@endforeach</select></label>
            <label><span>Status</span><select name="status"><option value="">All statuses</option>@foreach(['pending','approved','rejected','cancelled'] as $status)<option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ str($status)->headline() }}</option>@endforeach</select></label>
            <button class="btn btn-primary" type="submit">Apply filters</button>
        </form>
    </section>

    <section class="panel leave-calendar-panel">
        <div class="schedule-toolbar"><div class="calendar-navigation"><a class="calendar-nav-button" href="{{ $queryFor(['date' => $previousMonth]) }}"><x-icon name="chevron-right" class="flip-horizontal" /></a><a class="calendar-today-button" href="{{ $queryFor(['date' => now()->toDateString()]) }}">Today</a><a class="calendar-nav-button" href="{{ $queryFor(['date' => $nextMonth]) }}"><x-icon name="chevron-right" /></a><h2>{{ $focusDate->format('F Y') }}</h2></div><span class="history-caption">Approved leave calendar</span></div>
        <div class="month-calendar leave-calendar"><div class="month-weekdays">@foreach(['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $day)<span>{{ $day }}</span>@endforeach</div><div class="month-days">
            @foreach($calendarDays as $day)
                <article @class(['calendar-day', 'outside-month' => !$day['current_month'], 'is-today' => $day['today']])><div class="calendar-day-header"><span>{{ $day['date']->day }}</span>@if($day['today'])<small>Today</small>@endif</div><div class="calendar-day-events">
                    @foreach($day['requests']->take(3) as $leave)<span class="leave-calendar-event" style="--leave-color: {{ $leave->leaveType->color }}"><i></i><span><strong>{{ $leave->employee->first_name }} {{ $leave->employee->last_name }}</strong><small>{{ $leave->leaveType->name }}</small></span></span>@endforeach
                    @if($day['requests']->count() > 3)<span class="more-events">+{{ $day['requests']->count() - 3 }} more</span>@endif
                </div></article>
            @endforeach
        </div></div>
    </section>

    <section class="panel workforce-table-panel">
        <div class="panel-header"><div><p class="panel-kicker">Requests and approvals</p><h2>Leave request history</h2></div><span class="history-caption">{{ $requests->total() }} results</span></div>
        <div class="table-responsive"><table class="dashboard-table workforce-table"><thead><tr><th>Employee</th><th>Leave</th><th>Dates</th><th>Days</th><th>Reason</th><th>Attachments</th><th>Status</th><th>Actions</th></tr></thead><tbody>
            @forelse($requests as $leave)
                <tr><td><div class="employee-cell"><span class="avatar avatar-table">{{ strtoupper(substr($leave->employee->first_name,0,1).substr($leave->employee->last_name,0,1)) }}</span><div><strong>{{ $leave->employee->full_name }}</strong><span>{{ $leave->employee->employee_number }}</span></div></div></td><td><span class="leave-type-label"><i style="background:{{ $leave->leaveType->color }}"></i>{{ $leave->leaveType->name }}</span></td><td><strong>{{ $leave->start_date->format('M j') }}–{{ $leave->end_date->format('M j, Y') }}</strong></td><td>{{ number_format((float)$leave->requested_days, 1) }}</td><td><span class="truncate-reason" title="{{ $leave->reason }}">{{ $leave->reason }}</span></td><td>@forelse($leave->attachments as $attachment)<a class="attachment-link" href="{{ route('leave-attachments.download', $attachment) }}"><x-icon name="paperclip" />{{ $attachment->original_name }}</a>@empty<span>—</span>@endforelse</td><td><x-status-badge :status="$leave->status" /></td><td><div class="row-action-group">
                    @if($canManage && $leave->status === 'pending')
                        <form method="POST" action="{{ route('leaves.approve', $leave) }}">@csrf<button class="btn btn-sm btn-success">Approve</button></form>
                        <form method="POST" action="{{ route('leaves.reject', $leave) }}" class="inline-review-form">@csrf<input name="reviewer_notes" minlength="5" placeholder="Reason" required><button class="btn btn-sm btn-outline-danger">Reject</button></form>
                    @endif
                    @if(in_array($leave->status, ['pending','approved']) && ($leave->employee_id === auth()->user()->employee?->id || $canManage))<form method="POST" action="{{ route('leaves.cancel', $leave) }}" onsubmit="return confirm('Cancel this leave request?')">@csrf<button class="btn btn-sm btn-light">Cancel</button></form>@endif
                </div></td></tr>
            @empty<tr><td colspan="8" class="empty-table-cell"><x-icon name="leave" /><strong>No leave requests found</strong><span>New requests will appear here.</span></td></tr>@endforelse
        </tbody></table></div>
        @if($requests->hasPages())<div class="report-pagination">{{ $requests->onEachSide(1)->links('pagination::bootstrap-5') }}</div>@endif
    </section>

    <div class="modal fade" id="leaveRequestModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content schedule-modal-content"><form method="POST" action="{{ route('leaves.store') }}" enctype="multipart/form-data">@csrf<div class="modal-header"><div><p class="panel-kicker">Employee self-service</p><h2 class="modal-title">Request leave</h2></div><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body schedule-form-grid">
        <label class="full-width"><span>Leave type</span><select name="leave_type_id" required><option value="">Select leave type</option>@foreach($types as $type)<option value="{{ $type->id }}">{{ $type->name }} · {{ number_format($balances->firstWhere('leave_type_id', $type->id)?->available_days ?? 0, 1) }} days available{{ $type->requires_attachment ? ' · Attachment required' : '' }}</option>@endforeach</select></label>
        <label><span>Starts</span><input type="date" name="start_date" required></label><label><span>Ends</span><input type="date" name="end_date" required></label><label class="full-width"><span>Reason</span><textarea name="reason" minlength="10" maxlength="1000" rows="4" required></textarea></label><label class="full-width"><span>Supporting documents</span><input type="file" name="attachments[]" accept=".pdf,.jpg,.jpeg,.png" multiple><small>PDF, JPG, or PNG. Up to 5 files, 5 MB each.</small></label>
    </div><div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Submit request</button></div></form></div></div></div>

    @if($canManageLeaveTypes)
        <div class="modal fade" id="leaveTypeModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content schedule-modal-content"><form method="POST" action="{{ route('leave-types.store') }}">@csrf<div class="modal-header"><div><p class="panel-kicker">Leave policy</p><h2 class="modal-title">Add leave type</h2></div><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body schedule-form-grid">
            <label><span>Code</span><input type="text" name="code" maxlength="30" value="{{ old('code') }}" placeholder="BEREAVEMENT" required><small>Uppercase letters, numbers, and dashes only.</small></label>
            <label><span>Leave type name</span><input type="text" name="name" maxlength="100" value="{{ old('name') }}" placeholder="Bereavement Leave" required></label>
            <label class="full-width"><span>Description</span><textarea name="description" rows="3" maxlength="1000" placeholder="Who can use this leave type and any conditions">{{ old('description') }}</textarea></label>
            <label><span>Annual entitlement (days)</span><input type="number" name="annual_entitlement" min="0" max="366" step="0.5" value="{{ old('annual_entitlement', 0) }}" required></label>
            <label><span>Maximum carry-over (days)</span><input type="number" name="max_carry_over" min="0" max="366" step="0.5" value="{{ old('max_carry_over', 0) }}" required></label>
            <label><span>Color</span><input type="color" name="color" value="{{ old('color', '#176B43') }}" required></label>
            <label><span>Status</span><select name="is_active" required><option value="1" @selected(old('is_active', '1') === '1')>Active</option><option value="0" @selected(old('is_active') === '0')>Inactive</option></select></label>
            <label class="full-width"><span><input type="hidden" name="requires_attachment" value="0"><input type="checkbox" name="requires_attachment" value="1" @checked(old('requires_attachment'))> Require supporting document when this leave type is requested</span></label>
        </div><div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Create leave type</button></div></form></div></div></div>
    @endif
@endsection
