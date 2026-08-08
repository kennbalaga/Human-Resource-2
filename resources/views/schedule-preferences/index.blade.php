@extends('layouts.app')

@section('title', 'Schedule Preferences')

@section('content')
    <section class="page-heading workforce-heading">
        <div><p class="eyebrow">Scheduling</p><h1>Schedule Preferences</h1><p>Set your standing shift preference and request specific days off for the roster to consider.</p></div>
        <div class="row-action-group">
            <button class="btn btn-primary dashboard-action" type="button" data-bs-toggle="modal" data-bs-target="#preferredDayOffModal"><x-icon name="plus" /> Request day off</button>
        </div>
    </section>

    @if(session('success'))<div class="attendance-alert attendance-alert-success"><x-icon name="check-circle" /><span>{{ session('success') }}</span></div>@endif
    @if($errors->any())<div class="attendance-alert attendance-alert-danger"><x-icon name="close" /><span>{{ $errors->first() }}</span></div>@endif

    <section class="panel workforce-filter-panel">
        <div class="panel-header"><div><p class="panel-kicker">Standing preference</p><h2>Your usual shift and rest day</h2></div></div>
        <form method="POST" action="{{ route('schedule-preferences.update-standing') }}" class="workforce-filters">
            @csrf
            @method('PATCH')
            <label><span>Preferred shift</span><select name="preferred_shift_id">
                <option value="">No preference</option>
                @foreach($shifts as $shift)
                    <option value="{{ $shift->id }}" @selected($employee->preferred_shift_id === $shift->id)>{{ $shift->name }} ({{ $shift->formatted_time }})</option>
                @endforeach
            </select></label>
            <label><span>Preferred weekly rest day</span><select name="preferred_weekly_off_day">
                <option value="">No preference</option>
                @foreach($weekdays as $value => $label)
                    <option value="{{ $value }}" @selected($employee->preferred_weekly_off_day === $value)>{{ $label }}</option>
                @endforeach
            </select></label>
            <button class="btn btn-primary" type="submit">Save preference</button>
        </form>
        <p class="history-caption">The rotation planner and AI recommendations treat this as a soft preference, honored when staffing requirements allow — not a guarantee.</p>
    </section>

    <section class="panel workforce-table-panel">
        <div class="panel-header"><div><p class="panel-kicker">Requests</p><h2>Preferred day-off requests</h2></div><span class="history-caption">{{ $requests->total() }} results</span></div>
        <div class="table-responsive"><table class="dashboard-table workforce-table"><thead><tr>@if($canManage)<th>Employee</th>@endif<th>Date</th><th>Reason</th><th>Status</th><th>Actions</th></tr></thead><tbody>
            @forelse($requests as $preference)
                <tr>
                    @if($canManage)<td><div class="employee-cell"><span class="avatar avatar-table">{{ strtoupper(substr($preference->employee->first_name,0,1).substr($preference->employee->last_name,0,1)) }}</span><div><strong>{{ $preference->employee->full_name }}</strong><span>{{ $preference->employee->department?->name }}</span></div></div></td>@endif
                    <td><strong>{{ $preference->preferred_date->format('M j, Y') }}</strong></td>
                    <td><span class="truncate-reason" title="{{ $preference->reason }}">{{ $preference->reason ?: '—' }}</span></td>
                    <td><x-status-badge :status="$preference->status" /></td>
                    <td><div class="row-action-group">
                        @if($canManageData && $preference->status === 'pending')
                            <form method="POST" action="{{ route('schedule-preferences.approve-day-off', $preference) }}">@csrf<button class="btn btn-sm btn-success">Approve</button></form>
                            <form method="POST" action="{{ route('schedule-preferences.reject-day-off', $preference) }}" class="inline-review-form">@csrf<input name="reviewer_notes" minlength="5" placeholder="Reason" required><button class="btn btn-sm btn-outline-danger">Reject</button></form>
                        @endif
                        @if(in_array($preference->status, ['pending', 'approved']) && ($preference->employee_id === $employee->id || $canManageData))
                            <form method="POST" action="{{ route('schedule-preferences.cancel-day-off', $preference) }}" onsubmit="return confirm('Cancel this request?')">@csrf<button class="btn btn-sm btn-light">Cancel</button></form>
                        @endif
                    </div></td>
                </tr>
            @empty<tr><td colspan="{{ $canManage ? 5 : 4 }}" class="empty-table-cell"><x-icon name="calendar" /><strong>No preferred day-off requests found</strong><span>New requests will appear here.</span></td></tr>@endforelse
        </tbody></table></div>
        @if($requests->hasPages())<div class="report-pagination">{{ $requests->onEachSide(1)->links('pagination::bootstrap-5') }}</div>@endif
    </section>

    <div class="modal fade" id="preferredDayOffModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered modal-dialog-scrollable"><div class="modal-content schedule-modal-content"><form method="POST" action="{{ route('schedule-preferences.store-day-off') }}">@csrf<div class="modal-header"><div><p class="panel-kicker">Employee self-service</p><h2 class="modal-title">Request a preferred day off</h2></div><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body schedule-form-grid">
        <label class="full-width"><span>Date</span><input type="date" name="preferred_date" required></label>
        <label class="full-width"><span>Reason (optional)</span><textarea name="reason" maxlength="1000" rows="3"></textarea></label>
    </div><div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Submit request</button></div></form></div></div></div>
@endsection
