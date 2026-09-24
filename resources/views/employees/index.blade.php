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
            <button class="btn btn-primary dashboard-action" type="button" data-bs-toggle="modal" data-bs-target="#createEmployeeModal"><x-icon name="plus" /> Add employee</button>
        @endif
    </section>

    @include('partials.organization-tabs')

    @include('partials.organization-feedback')

    {{-- The workforce at a glance, above the list. These count the whole
         directory, not the filtered page below: what the four of them are for
         is the shape of the place, which a filter would take away. --}}
    <section class="workforce-stats-grid" aria-label="Directory summary">
        @foreach ([
            ['active', 'Active', 'check-circle', 'green'],
            ['on_leave', 'On leave', 'clock', 'amber'],
            ['inactive', 'Inactive', 'briefcase', 'blue'],
            ['terminated', 'Terminated', 'alert', 'red'],
        ] as [$status, $label, $icon, $tone])
            <article class="report-stat">
                <span class="report-stat-icon report-stat-{{ $tone }}"><x-icon :name="$icon" /></span>
                <div>
                    <span>{{ $label }}</span>
                    <strong>{{ number_format($statusCounts[$status] ?? 0) }}</strong>
                </div>
            </article>
        @endforeach
    </section>

    <form class="panel organization-filters" method="GET" action="{{ route('employees.index') }}" id="employee-filters-form" data-live-filters>
        <label><span>Search employees</span><input type="search" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="{{ $canSearchEmail ? 'Name, employee ID, or email' : 'Name or employee ID' }}" @if(!empty($filters['search'])) autofocus @endif></label>
        <label><span>Department</span><select name="department_id"><option value="">All departments</option>@foreach($departments as $department)<option value="{{ $department->id }}" @selected(($filters['department_id'] ?? null) == $department->id)>{{ $department->name }}</option>@endforeach</select></label>
        <label><span>Status</span><select name="status"><option value="">All statuses</option>@foreach(['active' => 'Active', 'on_leave' => 'On leave', 'inactive' => 'Inactive', 'terminated' => 'Terminated', 'archived' => 'Archived'] as $value => $label)<option value="{{ $value }}" @selected(($filters['status'] ?? null) === $value)>{{ $label }}</option>@endforeach</select></label>
        <div class="organization-filter-actions" @if(!request()->hasAny(['search', 'department_id', 'status'])) hidden @endif><a class="btn btn-light" href="{{ route('employees.index') }}">Clear</a></div>
    </form>

    <section class="panel organization-table-panel" id="employee-directory-results">
        @include('employees._table')
    </section>

    @include('employees._panel')

    @if ($canManage)
        @include('employees._create-modal')
        @include('employees._edit-modal')
    @endif
@endsection
