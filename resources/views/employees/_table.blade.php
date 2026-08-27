<div class="panel-header">
    <div><p class="panel-kicker">Workforce records</p><h2>{{ number_format($employees->total()) }} {{ str('employee')->plural($employees->total()) }}</h2></div>
</div>
<div class="table-responsive">
    <table class="dashboard-table organization-table table-stack">
        <colgroup><col style="width: 24%"><col style="width: 13%"><col style="width: 14%"><col style="width: 14%"><col style="width: 14%"><col style="width: 11%"><col style="width: 10%"></colgroup>
        <thead><tr><th>Employee</th><th>Employee ID</th><th>Department</th><th>Position</th><th>Supervisor</th><th>Status</th><th><span class="visually-hidden">Actions</span></th></tr></thead>
        <tbody>
            @forelse ($employees as $employee)
                <tr>
                    <td data-label="Employee"><div class="employee-cell"><span class="avatar avatar-table">{{ strtoupper(substr($employee->first_name, 0, 1).substr($employee->last_name, 0, 1)) }}</span><div><strong>{{ $employee->full_name }}</strong><span>{{ $employee->user?->email ?? 'No linked email' }}</span></div></div></td>
                    <td data-label="Employee ID"><span class="employee-number">{{ $employee->employee_number }}</span></td>
                    <td data-label="Department">{{ $employee->department?->name ?? 'Unassigned' }}</td>
                    <td data-label="Position">{{ $employee->position?->title ?? 'Unassigned' }}</td>
                    <td data-label="Supervisor">{{ $employee->supervisor?->full_name ?? 'None' }}</td>
                    <td data-label="Status"><x-status-badge :status="str($employee->employment_status)->replace('_', ' ')" /></td>
                    <td><div class="organization-row-actions"><a class="btn btn-sm btn-light" data-employee-panel href="{{ route('employees.show', $employee) }}">View</a>@if($canManage)<a class="btn btn-sm btn-outline-primary" href="{{ route('employees.edit', $employee) }}">Edit</a>@endif</div></td>
                </tr>
            @empty
                <tr><td colspan="7" class="empty-table-cell"><x-icon name="users" /><strong>No matching employees</strong><span>Adjust the filters or add a new employee.</span></td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@if($employees->hasPages())<div class="report-pagination">{{ $employees->onEachSide(1)->links('pagination::bootstrap-5') }}</div>@endif
