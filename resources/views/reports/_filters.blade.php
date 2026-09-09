{{--
    The filter bar every report shares.

    Each control renders only if the report declared it, so a report is never
    offered a filter its query would ignore -- the leave report shows a leave
    type picker and no capture source, and neither had to be written twice.
--}}
@php($supported = $report->filters())

<section class="panel report-filter-panel">
    <form method="GET" action="{{ route($route, $routeParameters ?? []) }}" class="report-filters">
        <label>
            <span>From</span>
            <input type="date" name="date_from" value="{{ $filters['date_from'] }}">
        </label>
        <label>
            <span>To</span>
            <input type="date" name="date_to" value="{{ $filters['date_to'] }}">
        </label>

        @if (in_array('department_id', $supported, true))
            <label>
                <span>Department</span>
                <select name="department_id">
                    <option value="">All departments</option>
                    @foreach ($departments as $department)
                        <option value="{{ $department->id }}" @selected(($filters['department_id'] ?? '') == $department->id)>{{ $department->name }}</option>
                    @endforeach
                </select>
            </label>
        @endif

        @if (in_array('employee_id', $supported, true))
            <label>
                <span>Employee</span>
                <select name="employee_id">
                    <option value="">All employees</option>
                    @foreach ($employees as $employee)
                        <option value="{{ $employee->id }}" @selected(($filters['employee_id'] ?? '') == $employee->id)>{{ $employee->employee_number }} · {{ $employee->full_name }}</option>
                    @endforeach
                </select>
            </label>
        @endif

        @if (in_array('status', $supported, true))
            <label>
                <span>Status</span>
                <select name="status">
                    <option value="">All statuses</option>
                    <option value="present" @selected(($filters['status'] ?? '') === 'present')>Present</option>
                    <option value="late" @selected(($filters['status'] ?? '') === 'late')>Late</option>
                </select>
            </label>
        @endif

        @if (in_array('approval_status', $supported, true))
            <label>
                <span>Approval</span>
                <select name="approval_status">
                    <option value="">All approvals</option>
                    <option value="pending" @selected(($filters['approval_status'] ?? '') === 'pending')>Pending</option>
                    <option value="approved" @selected(($filters['approval_status'] ?? '') === 'approved')>Approved</option>
                    <option value="rejected" @selected(($filters['approval_status'] ?? '') === 'rejected')>Rejected</option>
                </select>
            </label>
        @endif

        @if (in_array('capture_method', $supported, true))
            <label>
                <span>Capture source</span>
                <select name="capture_method">
                    <option value="">All sources</option>
                    <option value="biometric" @selected(($filters['capture_method'] ?? '') === 'biometric')>Biometric</option>
                    <option value="qr" @selected(($filters['capture_method'] ?? '') === 'qr')>QR badge</option>
                    <option value="manual" @selected(($filters['capture_method'] ?? '') === 'manual')>Manual website</option>
                    <option value="mixed" @selected(($filters['capture_method'] ?? '') === 'mixed')>Mixed in/out</option>
                </select>
            </label>
        @endif

        @if (in_array('leave_status', $supported, true))
            <label>
                <span>Leave status</span>
                <select name="leave_status">
                    <option value="">All statuses</option>
                    @foreach (\App\Models\LeaveRequest::FILTERABLE_STATUSES as $value)
                        <option value="{{ $value }}" @selected(($filters['leave_status'] ?? '') === $value)>{{ str($value)->headline() }}</option>
                    @endforeach
                </select>
            </label>
        @endif

        @if (in_array('leave_type_id', $supported, true))
            <label>
                <span>Leave type</span>
                <select name="leave_type_id">
                    <option value="">All types</option>
                    @foreach ($leaveTypes as $leaveType)
                        <option value="{{ $leaveType->id }}" @selected(($filters['leave_type_id'] ?? '') == $leaveType->id)>{{ $leaveType->name }}</option>
                    @endforeach
                </select>
            </label>
        @endif

        @if (in_array('timesheet_status', $supported, true))
            <label>
                <span>Timesheet status</span>
                <select name="timesheet_status">
                    <option value="">All statuses</option>
                    @foreach (['draft' => 'Draft', 'submitted' => 'Submitted', 'approved' => 'Approved', 'rejected' => 'Rejected'] as $value => $label)
                        <option value="{{ $value }}" @selected(($filters['timesheet_status'] ?? '') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
        @endif

        <button class="btn btn-primary" type="submit">Apply filters</button>
    </form>
</section>
