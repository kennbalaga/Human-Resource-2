@extends('layouts.app')

@section('title', 'Search')

@section('content')
    <section class="page-heading">
        <div>
            <p class="eyebrow">Workforce search</p>
            <h1>Search results</h1>
            <p>{{ $query === '' ? 'Search the employee directory and hospital departments.' : 'Results for “'.$query.'”.' }}</p>
        </div>
    </section>

    @if ($query === '')
        <section class="panel global-search-empty-state">
            <x-icon name="search" />
            <strong>Start typing to search</strong>
            <span>Find employees by name, ID, or email, and departments by name or code.</span>
        </section>
    @elseif ($employees->isEmpty() && $departments->isEmpty())
        <section class="panel global-search-empty-state">
            <x-icon name="search" />
            <strong>No matches found</strong>
            <span>Try a different employee name, employee ID, email, department name, or code.</span>
        </section>
    @else
        <div class="global-search-results-grid">
            <section class="panel global-search-results-panel">
                <div class="panel-header">
                    <div><p class="panel-kicker">Employees</p><h2>{{ $employees->count() }} {{ str('match')->plural($employees->count()) }}</h2></div>
                    @if ($employees->isNotEmpty())<a class="btn btn-sm btn-light" href="{{ route('employees.index', ['search' => $query]) }}">Open directory</a>@endif
                </div>
                <div class="global-search-result-list">
                    @forelse ($employees as $employee)
                        <a class="global-search-result" href="{{ route('employees.show', $employee) }}">
                            <span class="avatar avatar-table">{{ strtoupper(substr($employee->first_name, 0, 1).substr($employee->last_name, 0, 1)) }}</span>
                            <span><strong>{{ $employee->full_name }}</strong><small>{{ $employee->employee_number }} · {{ $employee->department?->name ?? 'Unassigned department' }} · {{ $employee->position?->title ?? 'Unassigned position' }}</small></span>
                            <x-icon name="chevron-right" />
                        </a>
                    @empty
                        <p class="global-search-section-empty">No employee matches.</p>
                    @endforelse
                </div>
            </section>

            <section class="panel global-search-results-panel">
                <div class="panel-header">
                    <div><p class="panel-kicker">Departments</p><h2>{{ $departments->count() }} {{ str('match')->plural($departments->count()) }}</h2></div>
                    @if ($departments->isNotEmpty())<a class="btn btn-sm btn-light" href="{{ route('departments.index', ['search' => $query]) }}">Open departments</a>@endif
                </div>
                <div class="global-search-result-list">
                    @forelse ($departments as $department)
                        <a class="global-search-result" href="{{ route('departments.index', ['search' => $department->name]) }}">
                            <span class="global-search-department-code">{{ $department->code }}</span>
                            <span><strong>{{ $department->name }}</strong><small>{{ $department->employees_count }} {{ str('employee')->plural($department->employees_count) }} · {{ $department->positions_count }} {{ str('position')->plural($department->positions_count) }}</small></span>
                            <x-icon name="chevron-right" />
                        </a>
                    @empty
                        <p class="global-search-section-empty">No department matches.</p>
                    @endforelse
                </div>
            </section>
        </div>
    @endif
@endsection
