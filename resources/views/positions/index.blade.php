@extends('layouts.app')
@section('title', 'Positions')
@section('content')
    <section class="page-heading">
        <div><p class="eyebrow">Organization</p><h1>Positions</h1><p>Define approved workforce roles and keep every role aligned with its department.</p></div>
        @if($canManage)<a class="btn btn-primary dashboard-action" href="{{ route('positions.create') }}"><x-icon name="plus" /> Add position</a>@endif
    </section>
    @include('partials.organization-tabs')
    @include('partials.organization-feedback')
    <form class="panel organization-filters" method="GET" action="{{ route('positions.index') }}">
        <label><span>Search positions</span><input type="search" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="Position title or code" @if(!empty($filters['search'])) autofocus @endif></label>
        <label><span>Department</span><select name="department_id"><option value="">All departments</option>@foreach($departments as $department)<option value="{{ $department->id }}" @selected(($filters['department_id'] ?? null) == $department->id)>{{ $department->name }}</option>@endforeach</select></label>
        <label><span>Status</span><select name="status"><option value="">All statuses</option><option value="active" @selected(($filters['status'] ?? null) === 'active')>Active</option><option value="inactive" @selected(($filters['status'] ?? null) === 'inactive')>Inactive</option></select></label>
        @if(request()->hasAny(['search', 'department_id', 'status']))<div class="organization-filter-actions"><a class="btn btn-light" href="{{ route('positions.index') }}">Clear</a></div>@endif
    </form>
    <section class="panel organization-table-panel">
        <div class="panel-header"><div><p class="panel-kicker">Approved roles</p><h2>{{ number_format($positions->total()) }} {{ str('position')->plural($positions->total()) }}</h2></div></div>
        <div class="table-responsive"><table class="dashboard-table organization-table table-stack"><colgroup><col style="width: 28%"><col style="width: 12%"><col style="width: 15%"><col style="width: 14%"><col style="width: 10%"><col style="width: 11%"><col style="width: 10%"></colgroup><thead><tr><th>Position</th><th>Code</th><th>Department</th><th>Seniority</th><th>Employees</th><th>Status</th><th><span class="visually-hidden">Actions</span></th></tr></thead><tbody>
            @forelse($positions as $position)
                <tr><td data-label="Position"><strong>{{ $position->title }}</strong><span class="organization-secondary">{{ $position->description ?: 'No description' }}</span></td><td data-label="Code"><span class="employee-number">{{ $position->code }}</span></td><td data-label="Department">{{ $position->department?->name ?? 'Unassigned' }}</td><td data-label="Seniority">{{ $position->seniority_rank }} — {{ App\Models\Position::SENIORITY_RANK_LABELS[$position->seniority_rank] ?? 'Unranked' }}</td><td data-label="Employees">{{ number_format($position->employees_count) }}</td><td data-label="Status"><x-status-badge :status="$position->is_active ? 'active' : 'inactive'" /></td><td><div class="organization-row-actions">@if($canManage)<a class="btn btn-sm btn-outline-primary" href="{{ route('positions.edit', $position) }}">Edit</a>@endif</div></td></tr>
            @empty<tr><td colspan="6" class="empty-table-cell"><x-icon name="briefcase" /><strong>No matching positions</strong><span>Adjust the filters or add a position.</span></td></tr>@endforelse
        </tbody></table></div>
        @if($positions->hasPages())<div class="report-pagination">{{ $positions->onEachSide(1)->links('pagination::bootstrap-5') }}</div>@endif
    </section>
@endsection
