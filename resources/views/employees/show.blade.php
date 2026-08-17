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
                <div class="organization-detail organization-field-full"><span>Email address</span><strong>{{ $employee->user?->email ?: 'Not recorded' }}</strong></div>
            @endif
        </div>
    </section>
    @if($canReissueAttendanceQr)
        <section class="panel organization-profile employee-qr-panel">
            <div class="panel-header"><div><p class="panel-kicker">Time &amp; attendance</p><h2>Attendance badge</h2></div><x-icon name="fingerprint" /></div>
            <div class="profile-qr-body">
                <figure class="profile-qr-code" role="img" aria-label="Attendance QR code for {{ $employee->full_name }}">{!! $attendanceQrSvg !!}</figure>
                <div class="profile-qr-copy">
                    <p>{{ $employee->full_name }} presents this at the entrance scanner. They can download it themselves from My Profile.</p>
                    <p class="profile-qr-warning"><x-icon name="shield" /> <span>Issue a new badge if this one has been lost, shared, or photographed. Every printed copy of their current code stops scanning immediately, and they will need to download the replacement.</span></p>
                    <form method="POST" action="{{ route('employees.attendance-qr.reissue', $employee) }}" onsubmit="return confirm('Issue a new attendance badge for {{ $employee->full_name }}? Their current code will stop working immediately.')">
                        @csrf
                        <button class="btn btn-outline-primary" type="submit"><x-icon name="refresh" /> Issue new badge</button>
                    </form>
                </div>
            </div>
        </section>
    @endif

    @if($canResetTwoFactor)
        <section class="panel organization-profile two-factor-admin-reset">
            <div class="panel-header"><div><p class="panel-kicker">Security recovery</p><h2>Reset employee two-factor authentication</h2></div><x-icon name="shield" /></div>
            <div class="settings-security-note"><x-icon name="shield" /><p>Use only after verifying the employee’s identity outside this system. The reset signs out existing database sessions, revokes API tokens, and is recorded in Audit Logs.</p></div>
            <form method="POST" action="{{ route('employees.two-factor.reset', $employee) }}" class="profile-settings-form">
                @csrf
                <label><span>Your administrator password</span><input type="password" name="current_password" autocomplete="current-password" required></label>
                <label class="two-factor-identity-check"><input type="checkbox" name="identity_verified" value="1" required><span>I confirm that I verified this employee’s identity using the hospital’s approved process.</span></label>
                <button class="btn btn-danger" type="submit">Reset employee 2FA</button>
            </form>
        </section>
    @endif
@endsection
