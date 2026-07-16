@extends('layouts.app')

@section('title', 'Employees')

@section('content')
    <section class="page-heading">
        <div>
            <p class="eyebrow">Organization</p>
            <h1>Employee directory</h1>
            <p>View workforce identities, assignments, reporting lines, and account status.</p>
        </div>
        @if ($canManage)
            <a class="btn btn-primary dashboard-action" href="{{ route('employees.create') }}"><x-icon name="plus" /> Add employee</a>
        @endif
    </section>

    @include('partials.organization-feedback')

    <form class="panel organization-filters" method="GET" action="{{ route('employees.index') }}">
        <label><span>Search employees</span><input type="search" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="Name, employee ID, or email"></label>
        <label><span>Department</span><select name="department_id"><option value="">All departments</option>@foreach($departments as $department)<option value="{{ $department->id }}" @selected(($filters['department_id'] ?? null) == $department->id)>{{ $department->name }}</option>@endforeach</select></label>
        <label><span>Status</span><select name="status"><option value="">All statuses</option>@foreach(['active' => 'Active', 'on_leave' => 'On leave', 'inactive' => 'Inactive', 'terminated' => 'Terminated'] as $value => $label)<option value="{{ $value }}" @selected(($filters['status'] ?? null) === $value)>{{ $label }}</option>@endforeach</select></label>
        <div class="organization-filter-actions"><button class="btn btn-primary" type="submit">Filter</button>@if(request()->hasAny(['search', 'department_id', 'status']))<a class="btn btn-light" href="{{ route('employees.index') }}">Clear</a>@endif</div>
    </form>

    <section class="panel organization-table-panel">
        <div class="panel-header">
            <div><p class="panel-kicker">Workforce records</p><h2>{{ number_format($employees->total()) }} {{ str('employee')->plural($employees->total()) }}</h2></div>
        </div>
        <div class="table-responsive">
            <table class="dashboard-table organization-table">
                <thead><tr><th>Employee</th><th>Employee ID</th><th>Department</th><th>Position</th><th>Supervisor</th><th>Status</th><th><span class="visually-hidden">Actions</span></th></tr></thead>
                <tbody>
                    @forelse ($employees as $employee)
                        <tr>
                            <td><div class="employee-cell"><span class="avatar avatar-table">{{ strtoupper(substr($employee->first_name, 0, 1).substr($employee->last_name, 0, 1)) }}</span><div><strong>{{ $employee->full_name }}</strong><span>{{ $employee->user?->email ?? 'No linked email' }}</span></div></div></td>
                            <td><span class="employee-number">{{ $employee->employee_number }}</span></td>
                            <td>{{ $employee->department?->name ?? 'Unassigned' }}</td>
                            <td>{{ $employee->position?->title ?? 'Unassigned' }}</td>
                            <td>{{ $employee->supervisor?->full_name ?? 'None' }}</td>
                            <td><x-status-badge :status="str($employee->employment_status)->replace('_', ' ')" /></td>
                            <td><div class="organization-row-actions"><a class="btn btn-sm btn-light" href="{{ route('employees.show', $employee) }}">View</a>@if($canManage)<a class="btn btn-sm btn-outline-primary" href="{{ route('employees.edit', $employee) }}">Edit</a>@endif</div></td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="empty-table-cell"><x-icon name="users" /><strong>No matching employees</strong><span>Adjust the filters or add a new employee.</span></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($employees->hasPages())<div class="report-pagination">{{ $employees->onEachSide(1)->links('pagination::bootstrap-5') }}</div>@endif
    </section>
@endsection
