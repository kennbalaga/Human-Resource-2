@extends('layouts.app')
@section('title', $employee->full_name)
@section('content')
    <section class="page-heading">
        <div><p class="eyebrow">Organization · Employees</p><h1>Employee profile</h1><p>Verified workforce identity and current organizational assignment.</p></div>
        <div class="organization-heading-actions"><a class="btn btn-light dashboard-action" href="{{ route('employees.index') }}">Back to directory</a>@if($canManage)<a class="btn btn-primary dashboard-action" href="{{ route('employees.edit', $employee) }}"><x-icon name="edit" /> Edit employee</a>@endif</div>
    </section>
    @include('partials.organization-feedback')
    <section class="panel organization-profile">
        <div class="organization-profile-header">
            <div class="organization-profile-identity"><span class="avatar">{{ strtoupper(substr($employee->first_name, 0, 1).substr($employee->last_name, 0, 1)) }}</span><div><h2>{{ $employee->full_name }}</h2><p>{{ $employee->employee_number }} · {{ $employee->user?->email ?? 'No linked email' }}</p></div></div>
            <x-status-badge :status="str($employee->employment_status)->replace('_', ' ')" />
        </div>
        <div class="organization-detail-grid">
            <div class="organization-detail"><span>Department</span><strong>{{ $employee->department?->name ?? 'Unassigned' }}</strong></div>
            <div class="organization-detail"><span>Position</span><strong>{{ $employee->position?->title ?? 'Unassigned' }}</strong></div>
            <div class="organization-detail"><span>Supervisor</span><strong>{{ $employee->supervisor?->full_name ?? 'None assigned' }}</strong></div>
            <div class="organization-detail"><span>Hire date</span><strong>{{ $employee->hire_date?->format('F j, Y') ?? 'Not recorded' }}</strong></div>
            @if($canViewPrivate)
                <div class="organization-detail"><span>Contact number</span><strong>{{ $employee->contact_number ?: 'Not recorded' }}</strong></div>
                <div class="organization-detail"><span>Account access</span><strong>{{ $employee->user?->is_active ? 'Enabled' : 'Disabled' }}</strong></div>
                <div class="organization-detail organization-field-full"><span>Address</span><strong>{{ $employee->address ?: 'Not recorded' }}</strong></div>
            @endif
        </div>
    </section>
@endsection
