@extends('layouts.app')

@section('title', 'My Dashboard')

@section('content')
    @php
        $greetingNow = now(config('workforce.timezone', 'Asia/Manila'));
        $greeting = match (true) {
            $greetingNow->hour < 12 => 'Good morning',
            $greetingNow->hour < 18 => 'Good afternoon',
            default => 'Good evening',
        };
    @endphp

    <div class="staff-dashboard">
        <section class="page-heading staff-heading">
            <div>
                <p class="eyebrow">My workday</p>
                <h1>{{ $greeting }}, {{ $dashboard['employee']['first_name'] }}.</h1>
                <p>{{ $dashboard['today']['date_label'] }} · {{ $dashboard['employee']['position'] ?? 'Staff' }}@if ($dashboard['employee']['department']) · {{ $dashboard['employee']['department'] }}@endif</p>
            </div>
            <span class="staff-heading-badge">
                <x-icon name="users" />
                <span>
                    <small>Employee ID</small>
                    <strong>{{ $dashboard['employee']['employee_number'] }}</strong>
                </span>
            </span>
        </section>

        @if (session('success'))
            <div class="staff-flash staff-flash-success" role="status">
                <x-icon name="check-circle" />
                <span>{{ session('success') }}</span>
            </div>
        @endif

        @if ($errors->any())
            <div class="staff-flash staff-flash-danger" role="alert">
                <x-icon name="close" />
                <span>{{ $errors->first() }}</span>
            </div>
        @endif

        {{-- Schedule changes lead the page: a shift that moved is the one thing here
             that stops being useful the moment it is read too late. --}}
        <x-staff.schedule-changes :changes="$dashboard['schedule_changes']" />

        <div class="staff-grid staff-grid-hero">
            <x-staff.today-attendance :today="$dashboard['today']" />
            <x-staff.my-shift :shift="$dashboard['shift']" />
        </div>

        <div class="staff-grid staff-grid-split">
            <x-staff.upcoming-schedule :upcoming="$dashboard['upcoming']" />
            <x-staff.timesheet-status :timesheet="$dashboard['timesheet']" />
        </div>

        <div class="staff-grid staff-grid-trio">
            <x-staff.attendance-summary :summary="$dashboard['attendance_summary']" />
            <x-staff.overtime-summary :overtime="$dashboard['overtime']" />
            <x-staff.leave-balance :leave="$dashboard['leave']" />
        </div>

        <x-staff.my-analytics :analytics="$dashboard['analytics']" />
    </div>
@endsection
