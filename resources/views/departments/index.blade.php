@extends('layouts.app')
@section('title', 'Departments')
@section('content')
    <section class="page-heading">
        <div><p class="eyebrow">Organization</p><h1>Departments</h1><p>Maintain the hospital’s operational units and review their workforce capacity.</p></div>
        @if($canManage)<a class="btn btn-primary dashboard-action" href="{{ route('departments.create') }}"><x-icon name="plus" /> Add department</a>@endif
    </section>
    @include('partials.organization-tabs')
    @include('partials.organization-feedback')
    <form class="panel organization-filters" method="GET" action="{{ route('departments.index') }}">
        <label><span>Search departments</span><input type="search" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="Department name or code"></label>
        <label><span>Status</span><select name="status"><option value="">All statuses</option><option value="active" @selected(($filters['status'] ?? null) === 'active')>Active</option><option value="inactive" @selected(($filters['status'] ?? null) === 'inactive')>Inactive</option></select></label>
        <div class="organization-filter-actions"><button class="btn btn-primary" type="submit">Filter</button>@if(request()->hasAny(['search', 'status']))<a class="btn btn-light" href="{{ route('departments.index') }}">Clear</a>@endif</div>
    </form>
    <section class="organization-card-grid">
        @forelse($departments as $department)
            <article class="organization-card">
                <div class="organization-card-header"><span class="organization-code">{{ $department->code }}</span><x-status-badge :status="$department->is_active ? 'active' : 'inactive'" /></div>
                <div><h2>{{ $department->name }}</h2><p>{{ $department->description ?: 'No department description has been added.' }}</p></div>
                <div class="organization-card-meta"><div class="organization-card-counts"><span><strong>{{ $department->employees_count }}</strong> employees</span><span><strong>{{ $department->positions_count }}</strong> positions</span></div>@if($canManage)<a class="btn btn-sm btn-outline-primary" href="{{ route('departments.edit', $department) }}">Edit</a>@endif</div>
            </article>
        @empty
            <section class="panel"><div class="empty-table-cell"><x-icon name="building" /><strong>No matching departments</strong><span>Adjust the filters or add a department.</span></div></section>
        @endforelse
    </section>
    @if($departments->hasPages())<div class="report-pagination">{{ $departments->onEachSide(1)->links('pagination::bootstrap-5') }}</div>@endif
@endsection
