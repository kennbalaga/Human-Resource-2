@extends('layouts.app')

@section('title', 'Shift & Schedule Preferences')

@section('content')
    <section class="page-heading workforce-heading">
        <div>
            <p class="eyebrow">Scheduling</p>
            <h1>Shift &amp; Schedule Preferences</h1>
            @if($canSeeSwaps)
                <p data-tab-subtitle="shift-swaps" hidden>{{ $canRequestSwap ? 'Request a swap with a colleague, respond to requests, and track manager approval.' : 'Review shift swap requests submitted by clinical staff.' }}</p>
            @endif
            <p data-tab-subtitle="preferences">Set your standing shift preference and request specific days off for the roster to consider.</p>
        </div>
        <div class="row-action-group">
            @if($canRequestSwap)
                <button data-tab-action="shift-swaps" hidden class="btn btn-primary dashboard-action" type="button" data-bs-toggle="modal" data-bs-target="#shiftSwapModal"><x-icon name="plus" /> Request swap</button>
            @endif
            <button data-tab-action="preferences" class="btn btn-primary dashboard-action" type="button" data-bs-toggle="modal" data-bs-target="#preferredDayOffModal"><x-icon name="plus" /> Request day off</button>
        </div>
    </section>

    @if(session('success'))<div class="attendance-alert attendance-alert-success"><x-icon name="check-circle" /><span>{{ session('success') }}</span></div>@endif
    @if($errors->any())<div class="attendance-alert attendance-alert-danger"><x-icon name="close" /><span>{{ $errors->first() }}</span></div>@endif

    @if($canSeeSwaps)
        <nav class="pref-swap-tabs" role="tablist" data-preference-tabs>
            <button class="pref-swap-tab" id="tab-shift-swaps" data-tab-key="shift-swaps" data-bs-toggle="tab" data-bs-target="#pane-shift-swaps" type="button" role="tab" aria-controls="pane-shift-swaps" aria-selected="false"><x-icon name="repeat" /> Shift Swaps</button>
            <button class="pref-swap-tab active" id="tab-preferences" data-tab-key="preferences" data-bs-toggle="tab" data-bs-target="#pane-preferences" type="button" role="tab" aria-controls="pane-preferences" aria-selected="true"><x-icon name="clock" /> Preferences</button>
        </nav>
    @endif

    <div class="tab-content">
        @if($canSeeSwaps)
            <div class="tab-pane fade" id="pane-shift-swaps" role="tabpanel" aria-labelledby="tab-shift-swaps">
                <section class="workforce-stats-grid">
                    <article class="report-stat"><span class="report-stat-icon report-stat-amber"><x-icon name="clock" /></span><div><span>Your open requests</span><strong>{{ $swapSummary['pending_sent'] }}</strong></div></article>
                    <article class="report-stat"><span class="report-stat-icon report-stat-blue"><x-icon name="repeat" /></span><div><span>Awaiting your response</span><strong>{{ $swapSummary['awaiting_me'] }}</strong></div></article>
                    <article class="report-stat"><span class="report-stat-icon report-stat-violet"><x-icon name="calendar" /></span><div><span>Awaiting manager</span><strong>{{ $swapSummary['awaiting_manager'] }}</strong></div></article>
                    <article class="report-stat"><span class="report-stat-icon report-stat-green"><x-icon name="check-circle" /></span><div><span>Approved</span><strong>{{ $swapSummary['approved'] }}</strong></div></article>
                </section>

                <section class="panel workforce-table-panel">
                    <div class="panel-header"><div><p class="panel-kicker">Requests</p><h2>Shift swap history</h2></div><span class="history-caption">{{ $swapRequests->total() }} results</span></div>
                    <div class="table-responsive"><table class="dashboard-table workforce-table"><thead><tr><th>Requester</th><th>Gives up</th><th>Colleague</th><th>Takes</th><th>Reason</th><th>Status</th><th>Actions</th></tr></thead><tbody>
                        @forelse($swapRequests as $swap)
                            <tr>
                                <td><div class="employee-cell"><span class="avatar avatar-table">{{ strtoupper(substr($swap->requesterEmployee->first_name,0,1).substr($swap->requesterEmployee->last_name,0,1)) }}</span><div><strong>{{ $swap->requesterEmployee->full_name }}</strong><span>{{ $swap->requesterEmployee->department?->name }}</span></div></div></td>
                                <td><strong>{{ $swap->requesterAssignment->shift->name }}</strong><span>{{ $swap->requesterAssignment->work_date->format('M j, Y') }}</span></td>
                                <td><div class="employee-cell"><span class="avatar avatar-table">{{ strtoupper(substr($swap->targetEmployee->first_name,0,1).substr($swap->targetEmployee->last_name,0,1)) }}</span><div><strong>{{ $swap->targetEmployee->full_name }}</strong><span>{{ $swap->targetEmployee->department?->name }}</span></div></div></td>
                                <td><strong>{{ $swap->targetAssignment->shift->name }}</strong><span>{{ $swap->targetAssignment->work_date->format('M j, Y') }}</span></td>
                                <td><span class="truncate-reason" title="{{ $swap->reason }}">{{ $swap->reason }}</span></td>
                                <td><x-status-badge :status="$swap->status" /></td>
                                <td><div class="row-action-group">
                                    @if($swap->target_employee_id === $employee->id && $swap->status === 'pending_target')
                                        <form method="POST" action="{{ route('shift-swaps.respond', $swap) }}">@csrf<input type="hidden" name="accept" value="1"><button class="btn btn-sm btn-success">Accept</button></form>
                                        <form method="POST" action="{{ route('shift-swaps.respond', $swap) }}">@csrf<input type="hidden" name="accept" value="0"><button class="btn btn-sm btn-outline-danger">Decline</button></form>
                                    @endif
                                    @if($canManageData && $swap->status === 'pending_manager')
                                        <form method="POST" action="{{ route('shift-swaps.approve', $swap) }}">@csrf<button class="btn btn-sm btn-success">Approve</button></form>
                                        <form method="POST" action="{{ route('shift-swaps.reject', $swap) }}" class="inline-review-form">@csrf<input name="reviewer_notes" minlength="5" placeholder="Reason" required><button class="btn btn-sm btn-outline-danger">Reject</button></form>
                                    @endif
                                    @if(in_array($swap->status, ['pending_target', 'pending_manager']) && ($swap->requester_employee_id === $employee->id || $canManageData))
                                        <form method="POST" action="{{ route('shift-swaps.cancel', $swap) }}" data-confirm="Cancel this swap request?">@csrf<button class="btn btn-sm btn-light">Cancel</button></form>
                                    @endif
                                </div></td>
                            </tr>
                        @empty<tr><td colspan="7" class="empty-table-cell"><x-icon name="repeat" /><strong>No shift swap requests found</strong><span>New requests will appear here.</span></td></tr>@endforelse
                    </tbody></table></div>
                    @if($swapRequests->hasPages())<div class="report-pagination">{{ $swapRequests->onEachSide(1)->links('pagination::bootstrap-5') }}</div>@endif
                </section>
            </div>
        @endif

        <div class="tab-pane fade show active" id="pane-preferences" role="tabpanel" aria-labelledby="tab-preferences">
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
                                    <form method="POST" action="{{ route('schedule-preferences.cancel-day-off', $preference) }}" data-confirm="Cancel this request?">@csrf<button class="btn btn-sm btn-light">Cancel</button></form>
                                @endif
                            </div></td>
                        </tr>
                    @empty<tr><td colspan="{{ $canManage ? 5 : 4 }}" class="empty-table-cell"><x-icon name="calendar" /><strong>No preferred day-off requests found</strong><span>New requests will appear here.</span></td></tr>@endforelse
                </tbody></table></div>
                @if($requests->hasPages())<div class="report-pagination">{{ $requests->onEachSide(1)->links('pagination::bootstrap-5') }}</div>@endif
            </section>
        </div>
    </div>

    <div class="modal fade" id="preferredDayOffModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered modal-dialog-scrollable"><div class="modal-content schedule-modal-content"><form method="POST" action="{{ route('schedule-preferences.store-day-off') }}">@csrf<div class="modal-header"><div><p class="panel-kicker">Employee self-service</p><h2 class="modal-title">Request a preferred day off</h2></div><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body schedule-form-grid">
        <label class="full-width"><span>Date</span><input type="date" name="preferred_date" required></label>
        <label class="full-width"><span>Reason (optional)</span><textarea name="reason" maxlength="1000" rows="3"></textarea></label>
    </div><div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Submit request</button></div></form></div></div></div>

    @if($canRequestSwap)
        <div class="modal fade" id="shiftSwapModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered modal-dialog-scrollable"><div class="modal-content schedule-modal-content"><form method="POST" action="{{ route('shift-swaps.store') }}">@csrf<div class="modal-header"><div><p class="panel-kicker">Employee self-service</p><h2 class="modal-title">Request a shift swap</h2></div><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body schedule-form-grid">
            <label class="full-width"><span>Your shift to give up</span><select name="requester_assignment_id" required>
                <option value="">Select one of your upcoming shifts</option>
                @foreach($myAssignments as $assignment)
                    <option value="{{ $assignment->id }}">{{ $assignment->work_date->format('M j, Y') }} · {{ $assignment->shift->name }} ({{ $assignment->shift->formatted_time }})</option>
                @endforeach
            </select></label>
            <label class="full-width"><span>Colleague's shift you'll take</span><select name="target_assignment_id" required>
                <option value="">Select a colleague's upcoming shift</option>
                @foreach($colleagueAssignments as $assignment)
                    <option value="{{ $assignment->id }}">{{ $assignment->employee->full_name }} · {{ $assignment->work_date->format('M j, Y') }} · {{ $assignment->shift->name }} ({{ $assignment->shift->formatted_time }})</option>
                @endforeach
            </select></label>
            <label class="full-width"><span>Reason</span><textarea name="reason" minlength="10" maxlength="1000" rows="4" required></textarea></label>
        </div><div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Send request</button></div></form></div></div></div>
    @endif
@endsection
