@extends('layouts.app')

@section('title', 'Leave Management')

@section('content')
    <section class="page-heading workforce-heading">
        <div><p class="eyebrow">Workforce Management</p><h1>Leave Management</h1><p>Track balances, submit supporting documents, and manage leave approvals.</p></div>
        <div class="row-action-group">
            @if($canManageLeaveTypes)<button class="btn btn-primary dashboard-action" type="button" data-bs-toggle="modal" data-bs-target="#leaveTypeModal"><x-icon name="plus" /> Add leave type</button>@endif
            @if($canRequestLeave)<button class="btn btn-primary dashboard-action" type="button" data-bs-toggle="modal" data-bs-target="#leaveRequestModal"><x-icon name="plus" /> Request leave</button>@endif
        </div>
    </section>

    @if(session('success'))<div class="attendance-alert attendance-alert-success"><x-icon name="check-circle" /><span>{{ session('success') }}</span></div>@endif
    @if($errors->any())<div class="attendance-alert attendance-alert-danger"><x-icon name="close" /><span>{{ $errors->first() }}</span></div>@endif

    <section class="workforce-stats-grid">
        <article class="report-stat"><span class="report-stat-icon report-stat-amber"><x-icon name="clock" /></span><div><span>Pending requests</span><strong>{{ $summary['pending'] }}</strong></div></article>
        <article class="report-stat"><span class="report-stat-icon report-stat-green"><x-icon name="check-circle" /></span><div><span>Approved days</span><strong>{{ number_format($summary['approved_days'], 1) }}</strong></div></article>
        <article class="report-stat"><span class="report-stat-icon report-stat-blue"><x-icon name="leave" /></span><div><span>Available credits</span><strong>{{ number_format($summary['available_days'], 1) }}</strong></div></article>
        <article class="report-stat"><span class="report-stat-icon report-stat-violet"><x-icon name="paperclip" /></span><div><span>With documents</span><strong>{{ $summary['attachments'] }}</strong></div></article>
    </section>

    <section class="leave-balance-section">
        <div class="leave-balance-heading">
            <h2>Your leave balances</h2>
            <span>{{ $employee->full_name }} · {{ $filters['year'] }} — your personal remaining days, not company-wide totals.</span>
        </div>
        {{-- Split so nobody reads a company allowance as something the law
             guarantees, or a statutory entitlement as something the handbook
             may withdraw. Partitioned rather than filtered per category on
             purpose: a type whose category is missing -- an unmigrated row, or
             a stale ReferenceDataCache entry serialised before the column
             existed -- has to land under company policy and still be shown. An
             earlier cut filtered on the category and every card vanished. --}}
        @php($grouped = $balances->partition(fn ($balance) => $balance->leaveType->isStatutory()))
        @foreach([['Required by law', $grouped[0]], ['Company policy', $grouped[1]]] as [$categoryLabel, $categoryBalances])
            @if($categoryBalances->isNotEmpty())
                <p class="panel-kicker">{{ $categoryLabel }}</p>
                <div class="leave-balance-grid">
                    @foreach($categoryBalances as $balance)
                        @php($type = $balance->leaveType)
                        @php($committed = (float) $balance->used_days + (float) $balance->pending_days)
                        <article class="leave-balance-card" style="--leave-color: {{ $type->color }}"><span class="leave-balance-color"></span><div><span title="{{ $type->name }}">{{ $type->name }}</span><strong>{{ $type->isBalanceBacked() ? number_format($balance->available_days, 1).' days' : $type->entitlementSummary() }}</strong>@if($committed > 0)<small>{{ number_format((float)$balance->used_days, 1) }} used · {{ number_format((float)$balance->pending_days, 1) }} pending</small>@elseif($type->satisfies_sil)<small>Covers the statutory service incentive leave</small>@endif</div></article>
                    @endforeach
                </div>
            @endif
        @endforeach
    </section>

    <section class="panel workforce-filter-panel">
        <form method="GET" action="{{ route('leaves.index') }}" class="workforce-filters">
            <label><span>Year</span><select name="year">@foreach(range(now()->year - 1, now()->year + 1) as $year)<option value="{{ $year }}" @selected($filters['year'] == $year)>{{ $year }}</option>@endforeach</select></label>
            @if($canManage)
                <label><span>Department</span><select name="department_id"><option value="">All departments</option>@foreach($departments as $department)<option value="{{ $department->id }}" @selected(($filters['department_id'] ?? '') == $department->id)>{{ $department->name }}</option>@endforeach</select></label>
                <label><span>Employee</span><select name="employee_id"><option value="">All employees</option>@foreach($employees as $employee)<option value="{{ $employee->id }}" @selected(($filters['employee_id'] ?? '') == $employee->id)>{{ $employee->employee_number }} · {{ $employee->full_name }}</option>@endforeach</select></label>
            @endif
            <label><span>Leave type</span><select name="leave_type_id"><option value="">All types</option>@foreach($types as $type)<option value="{{ $type->id }}" @selected(($filters['leave_type_id'] ?? '') == $type->id)>{{ $type->name }}</option>@endforeach</select></label>
            <label><span>Status</span><select name="status"><option value="">All statuses</option>@foreach(\App\Models\LeaveRequest::FILTERABLE_STATUSES as $status)<option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ str($status)->headline() }}</option>@endforeach</select></label>
            <button class="btn btn-primary" type="submit">Apply filters</button>
        </form>
    </section>

    <section class="panel workforce-table-panel">
        <div class="panel-header"><div><p class="panel-kicker">Requests and approvals</p><h2>Leave request history</h2></div><span class="history-caption">{{ $requests->total() }} results</span></div>
        <div class="table-responsive"><table class="dashboard-table workforce-table table-stack"><thead><tr><th>Employee</th><th>Leave</th><th>Dates</th><th>Days</th><th>Reason</th><th>Attachments</th><th>Status</th><th>Actions</th></tr></thead><tbody>
            @forelse($requests as $leave)
                <tr><td data-label="Employee"><div class="employee-cell"><span class="avatar avatar-table">{{ strtoupper(substr($leave->employee->first_name,0,1).substr($leave->employee->last_name,0,1)) }}</span><div><strong>{{ $leave->employee->full_name }}</strong><span>{{ $leave->employee->employee_number }}</span></div></div></td><td data-label="Leave"><span class="leave-type-label"><i style="background:{{ $leave->leaveType->color }}"></i>{{ $leave->leaveType->name }}</span></td><td data-label="Dates"><strong>{{ $leave->start_date->format('M j') }}–{{ $leave->end_date->format('M j, Y') }}</strong></td><td data-label="Days">{{ number_format((float)$leave->requested_days, 1) }}</td><td data-label="Reason"><span class="truncate-reason" title="{{ $leave->reason }}">{{ $leave->reason }}</span></td><td data-label="Attachments">@forelse($leave->attachments as $attachment)<a class="attachment-link" href="{{ route('leave-attachments.download', $attachment) }}"><x-icon name="paperclip" />{{ $attachment->original_name }}</a>@empty<span>—</span>@endforelse</td><td data-label="Status"><x-status-badge :status="$leave->lifecycle_status" /></td><td><div class="row-action-group">
                    @if($canManageData && $leave->status === 'pending')
                        <form method="POST" action="{{ route('leaves.approve', $leave) }}">@csrf<button class="btn btn-sm btn-success">Approve</button></form>
                        <form method="POST" action="{{ route('leaves.reject', $leave) }}" class="inline-review-form">@csrf<input name="reviewer_notes" minlength="5" placeholder="Reason" required><button class="btn btn-sm btn-outline-danger">Reject</button></form>
                    @endif
                    @if($leave->isCancellable() && ($leave->employee_id === auth()->user()->employee?->id || $canManageData))<form method="POST" action="{{ route('leaves.cancel', $leave) }}" data-confirm="Cancel this leave request?">@csrf<button class="btn btn-sm btn-light">Cancel</button></form>@endif
                </div></td></tr>
            @empty<tr><td colspan="8" class="empty-table-cell"><x-icon name="leave" /><strong>No leave requests found</strong><span>New requests will appear here.</span></td></tr>@endforelse
        </tbody></table></div>
        @if($requests->hasPages())<div class="report-pagination">{{ $requests->onEachSide(1)->links('pagination::bootstrap-5') }}</div>@endif
    </section>

    @if($canRequestLeave)
        <div class="modal fade" id="leaveRequestModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered modal-dialog-scrollable"><div class="modal-content schedule-modal-content"><form method="POST" action="{{ route('leaves.store') }}" enctype="multipart/form-data">@csrf<div class="modal-header"><div><p class="panel-kicker">Employee self-service</p><h2 class="modal-title">Request leave</h2></div><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body schedule-form-grid">
            <label class="full-width"><span>Leave type</span><select name="leave_type_id" required><option value="">Select leave type</option>@foreach($requestableTypes as $type)<option value="{{ $type->id }}">{{ $type->name }} · {{ $type->isBalanceBacked() ? number_format($balances->firstWhere('leave_type_id', $type->id)?->available_days ?? 0, 1).' days available' : $type->entitlementSummary() }}{{ $type->requires_attachment ? ' · Attachment required' : '' }}</option>@endforeach</select></label>
            <label><span>Starts</span><input type="date" name="start_date" required></label><label><span>Ends</span><input type="date" name="end_date" required></label><label class="full-width"><span>Reason</span><textarea name="reason" minlength="10" maxlength="1000" rows="4" required></textarea></label><label class="full-width"><span>Supporting documents</span><input type="file" name="attachments[]" accept=".pdf,.jpg,.jpeg,.png" multiple><small>PDF, JPG, or PNG. Up to 5 files, 5 MB each.</small></label>
        </div><div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Submit request</button></div></form></div></div></div>
    @endif

    @if($canManageLeaveTypes)
        <div class="modal fade" id="leaveTypeModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered modal-dialog-scrollable"><div class="modal-content schedule-modal-content"><form method="POST" action="{{ route('leave-types.store') }}">@csrf<div class="modal-header"><div><p class="panel-kicker">Leave policy</p><h2 class="modal-title">Add leave type</h2></div><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body schedule-form-grid">
            <label><span>Code</span><input type="text" name="code" maxlength="30" value="{{ old('code') }}" placeholder="BEREAVEMENT" required><small>Uppercase letters, numbers, and dashes only.</small></label>
            <label><span>Leave type name</span><input type="text" name="name" maxlength="100" value="{{ old('name') }}" placeholder="Bereavement Leave" required><small>Shown to employees when they request leave.</small></label>
            <label class="full-width"><span>Description</span><textarea name="description" rows="3" maxlength="1000" placeholder="Who can use this leave type and any conditions">{{ old('description') }}</textarea></label>
            <label><span>Category</span><select name="category" required><option value="company" @selected(old('category', 'company') === 'company')>Company policy</option><option value="statutory" @selected(old('category') === 'statutory')>Required by law</option></select><small>Statutory types are labelled to employees as legal entitlements.</small></label>
            <label><span>How it is granted</span><select name="accrual_method" required><option value="annual" @selected(old('accrual_method', 'annual') === 'annual')>Yearly allowance</option><option value="per_event" @selected(old('accrual_method') === 'per_event')>Per occurrence</option><option value="unlimited" @selected(old('accrual_method') === 'unlimited')>No fixed cap</option></select><small>A yearly allowance holds credits. Per occurrence caps one request and never accumulates.</small></label>
            <label><span>Entitlement (days)</span><input type="number" name="annual_entitlement" min="0" max="366" step="0.5" value="{{ old('annual_entitlement', 0) }}" required><small>Days per year, or the ceiling for one occurrence. Leave at 0 when there is no fixed cap.</small></label>
            <label><span>Counted in</span><select name="day_basis" required><option value="working" @selected(old('day_basis', 'working') === 'working')>Working days</option><option value="calendar" @selected(old('day_basis') === 'calendar')>Calendar days</option></select><small>Maternity leave and similar entitlements are reckoned in calendar days.</small></label>
            <label><span>Maximum carry-over (days)</span><input type="number" name="max_carry_over" min="0" max="366" step="0.5" value="{{ old('max_carry_over', 0) }}" required><small>Yearly allowances only.</small></label>
            <label><span>Limited to</span><select name="eligible_gender"><option value="" @selected(old('eligible_gender', '') === '')>Any employee</option><option value="female" @selected(old('eligible_gender') === 'female')>Female employees</option><option value="male" @selected(old('eligible_gender') === 'male')>Male employees</option></select><small>Employees whose record does not state one cannot request a restricted type.</small></label>
            <label><span>Minimum service (months)</span><input type="number" name="min_service_months" min="0" max="600" step="1" value="{{ old('min_service_months', 0) }}" required><small>Counted from the hire date to the first day of leave.</small></label>
            <label><span>Requires status</span><select name="requires_designation"><option value="" @selected(old('requires_designation', '') === '')>None</option><option value="solo_parent" @selected(old('requires_designation') === 'solo_parent')>Valid solo parent ID</option></select><small>Hidden entirely from employees HR has not recorded as holding it.</small></label>
            <label><span>Calendar color</span><input type="color" name="color" value="{{ old('color', '#176B43') }}" required></label>
            <label><span>Status</span><select name="is_active" required><option value="1" @selected(old('is_active', '1') === '1')>Active</option><option value="0" @selected(old('is_active') === '0')>Inactive</option></select></label>
            <input type="hidden" name="requires_attachment" value="0">
            <label class="shift-active-toggle full-width"><input type="checkbox" name="requires_attachment" value="1" @checked(old('requires_attachment'))><span><strong>Require supporting document</strong><small>Employees must attach a file when they request this leave type.</small></span></label>
            <input type="hidden" name="satisfies_sil" value="0">
            <label class="shift-active-toggle full-width"><input type="checkbox" name="satisfies_sil" value="1" @checked(old('satisfies_sil'))><span><strong>Discharges the service incentive leave</strong><small>Tick where this allowance already gives at least five paid days, which excludes covered employees from the separate SIL entitlement. Do not create a second five-day SIL type alongside it.</small></span></label>
        </div><div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Create leave type</button></div></form></div></div></div>
    @endif
@endsection
