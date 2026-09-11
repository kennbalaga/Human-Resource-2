@extends('layouts.app')

@section('title', 'My Profile')

@section('content')
    <section class="page-heading workforce-heading">
        <div><p class="eyebrow">Employee account</p><h1>My Profile</h1><p>Review your employment details and keep your contact information current.</p></div>
        <a class="btn btn-outline-primary profile-heading-action" href="{{ route('settings.edit') }}"><x-icon name="settings" /> Account settings</a>
    </section>

    @if(session('success'))<div class="attendance-alert attendance-alert-success"><x-icon name="check-circle" /><span>{{ session('success') }}</span></div>@endif
    @if($errors->any())<div class="attendance-alert attendance-alert-danger"><x-icon name="close" /><span>{{ $errors->first() }}</span></div>@endif

    <section class="profile-hero panel">
        <span class="profile-hero-avatar">{{ str($user->name)->explode(' ')->filter()->take(2)->map(fn($part) => str($part)->substr(0, 1)->upper())->implode('') }}</span>
        <div class="profile-hero-copy"><p>Workforce profile</p><h2>{{ $employee?->full_name ?? $user->name }}</h2><span>{{ $employee?->position?->name ?? 'Position not assigned' }} · {{ $employee?->department?->name ?? 'Department not assigned' }}</span></div>
        <div class="profile-hero-status"><x-status-badge :status="$employee?->employment_status ?? ($user->is_active ? 'active' : 'inactive')" /><small>{{ $currentRole }}</small></div>
    </section>

    @if($employee)
        <section class="profile-stat-grid">
            <article><span>Attendance records</span><strong>{{ number_format($employee->attendance_records_count) }}</strong><small>All recorded entries</small></article>
            <article><span>Schedule assignments</span><strong>{{ number_format($employee->schedule_assignments_count) }}</strong><small>Assigned workforce shifts</small></article>
            <article><span>Timesheets</span><strong>{{ number_format($employee->timesheets_count) }}</strong><small>Generated work periods</small></article>
            <article><span>Leave requests</span><strong>{{ number_format($employee->leave_requests_count) }}</strong><small>All submitted requests</small></article>
        </section>

        <section class="profile-content-grid">
            <article class="panel profile-detail-panel">
                <div class="panel-header"><div><p class="panel-kicker">HR-managed information</p><h2>Employment details</h2></div><span class="profile-readonly-label"><x-icon name="shield" /> Read only</span></div>
                <dl class="profile-detail-list">
                    <div><dt>Employee ID</dt><dd>{{ $employee->employee_number }}</dd></div>
                    <div><dt>Full name</dt><dd>{{ $employee->full_name }}</dd></div>
                    <div><dt>Department</dt><dd>{{ $employee->department?->name ?? '—' }}</dd></div>
                    <div><dt>Position</dt><dd>{{ $employee->position?->name ?? '—' }}</dd></div>
                    <div><dt>Supervisor</dt><dd>{{ $employee->supervisor?->full_name ?? 'Not assigned' }}</dd></div>
                    <div><dt>Hire date</dt><dd>{{ $employee->hire_date?->format('F j, Y') ?? '—' }}</dd></div>
                    <div><dt>Gender</dt><dd>{{ $employee->gender ? ucfirst($employee->gender) : 'Not recorded' }}</dd></div>
                    {{-- Shown to the employee because it is theirs to check. A
                         lapsed solo parent ID silently withdraws the seven days,
                         and the first they would otherwise know of it is a
                         refused request. --}}
                    <div><dt>Solo parent ID</dt><dd>{{ $employee->soloParentSummary() }}@if($employee->solo_parent_id_expires_on && ! $employee->hasValidSoloParentId())<br><small class="profile-detail-note">Renew it with the DSWD and ask HR to update your record to restore solo parent leave.</small>@endif</dd></div>
                    <div><dt>Account email</dt><dd>{{ $user->email }}</dd></div>
                    <div><dt>Last login</dt><dd>{{ $user->last_login_at?->format('M j, Y · g:i A') ?? 'No recorded login' }}</dd></div>
                </dl>
            </article>

            <article class="panel profile-qr-panel">
                <div class="panel-header"><div><p class="panel-kicker">Time &amp; attendance</p><h2>My attendance QR</h2></div><x-icon name="fingerprint" /></div>
                <div class="profile-qr-body">
                    <figure class="profile-qr-code" role="img" aria-label="Attendance QR code for {{ $employee->full_name }}">
                        {{-- Rendered by the server: the badge is here whether or not
                             the page's scripts are. --}}
                        {!! $attendanceQrSvg !!}
                    </figure>
                    <div class="profile-qr-copy">
                        <p>Present this at the entrance scanner to record your time in and time out while the fingerprint terminal is unavailable.</p>
                        <dl>
                            <div><dt>Issued to</dt><dd>{{ $employee->full_name }}</dd></div>
                            <div><dt>Employee ID</dt><dd>{{ $employee->employee_number }}</dd></div>
                        </dl>
                        <p class="profile-qr-warning"><x-icon name="shield" /> <span>Treat this like your ID. Anyone holding a copy can have it scanned in your name — tell HR at once if you lose it, so they can retire it and issue you a new one.</span></p>
                        <div class="profile-qr-actions">
                            <a class="btn btn-primary" href="{{ route('profile.attendance-qr.download') }}"><x-icon name="download" /> Download</a>
                        </div>
                    </div>
                </div>
            </article>

            <article class="panel profile-form-panel">
                <div class="panel-header"><div><p class="panel-kicker">Personal contact</p><h2>Contact information</h2></div><x-icon name="edit" /></div>
                <form method="POST" action="{{ route('profile.update') }}" class="profile-settings-form">
                    @csrf @method('PATCH')
                    <label><span>Contact number</span><input name="contact_number" value="{{ old('contact_number', $employee->contact_number) }}" maxlength="30" inputmode="tel" placeholder="e.g. +63 917 123 4567"><small>Numbers, spaces, parentheses, plus, and hyphen are supported.</small></label>
                    <label><span>Home address</span><textarea name="address" rows="5" maxlength="1000" placeholder="Enter your current address">{{ old('address', $employee->address) }}</textarea><small>Used only for authorized HR records.</small></label>
                    <button class="btn btn-primary" type="submit"><x-icon name="check-circle" /> Save contact details</button>
                </form>
            </article>
        </section>
    @else
        <section class="panel profile-empty-state"><x-icon name="users" /><h2>No employee profile linked</h2><p>Ask HR or a system administrator to connect this account to an employee record.</p></section>
    @endif
@endsection
