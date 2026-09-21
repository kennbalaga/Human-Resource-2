@extends('layouts.app')
@section('title', 'Rooms')
@section('content')
    <section class="page-heading">
        <div><p class="eyebrow">Organization</p><h1>Rooms</h1><p>Theatres, wards, delivery rooms and clinics — the places a rostered shift is actually worked.</p></div>
        @if($canManage)<a class="btn btn-primary dashboard-action" href="{{ route('rooms.create') }}"><x-icon name="plus" /> Add room</a>@endif
    </section>
    @include('partials.organization-tabs')
    @include('partials.organization-feedback')
    <form class="panel organization-filters" method="GET" action="{{ route('rooms.index') }}">
        <label><span>Search rooms</span><input type="search" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="Room name or code" @if(!empty($filters['search'])) autofocus @endif></label>
        <label><span>Unit</span><select name="department_id"><option value="">All units</option>@foreach($departments as $department)<option value="{{ $department->id }}" @selected(($filters['department_id'] ?? null) == $department->id)>{{ $department->name }}</option>@endforeach</select></label>
        <label><span>Type</span><select name="room_type"><option value="">All types</option>@foreach($types as $value => $label)<option value="{{ $value }}" @selected(($filters['room_type'] ?? null) === $value)>{{ $label }}</option>@endforeach</select></label>
        <label><span>Status</span><select name="status"><option value="">All statuses</option>@foreach($statuses as $value => $label)<option value="{{ $value }}" @selected(($filters['status'] ?? null) === $value)>{{ $label }}</option>@endforeach</select></label>
        @if(request()->hasAny(['search', 'department_id', 'room_type', 'status']))<div class="organization-filter-actions"><a class="btn btn-light" href="{{ route('rooms.index') }}">Clear</a></div>@endif
    </form>
    <section class="panel organization-table-panel">
        <div class="panel-header"><div><p class="panel-kicker">Physical places</p><h2>{{ number_format($rooms->total()) }} {{ str('room')->plural($rooms->total()) }}</h2></div></div>
        <div class="table-responsive"><table class="dashboard-table organization-table table-stack"><colgroup><col style="width: 24%"><col style="width: 10%"><col style="width: 20%"><col style="width: 13%"><col style="width: 9%"><col style="width: 12%"><col style="width: 12%"></colgroup><thead><tr><th>Room</th><th>Code</th><th>Unit</th><th>Type</th><th>Beds</th><th>Status</th><th><span class="visually-hidden">Actions</span></th></tr></thead><tbody>
            @forelse($rooms as $room)
                <tr>
                    <td data-label="Room"><strong>{{ $room->name }}</strong><span class="organization-secondary">Charge cover from rank {{ $room->min_seniority_rank }}@if($room->max_staff) · holds {{ $room->max_staff }}@endif</span></td>
                    <td data-label="Code"><span class="employee-number">{{ $room->code }}</span></td>
                    <td data-label="Unit">{{ $room->department?->name ?? 'Unassigned' }}</td>
                    <td data-label="Type">{{ $room->type_label }}</td>
                    <td data-label="Beds">{{ $room->bed_capacity ?? '—' }}</td>
                    <td data-label="Status"><x-status-badge :status="$room->is_active ? $room->status : 'inactive'" /></td>
                    <td><div class="organization-row-actions">@if($canManage)<a class="btn btn-sm btn-outline-primary" href="{{ route('rooms.edit', $room) }}">Edit</a>@endif</div></td>
                </tr>
            @empty<tr><td colspan="7" class="empty-table-cell"><x-icon name="layers" /><strong>No matching rooms</strong><span>Adjust the filters or add a room.</span></td></tr>@endforelse
        </tbody></table></div>
        @if($rooms->hasPages())<div class="report-pagination">{{ $rooms->onEachSide(1)->links('pagination::bootstrap-5') }}</div>@endif
    </section>
@endsection
