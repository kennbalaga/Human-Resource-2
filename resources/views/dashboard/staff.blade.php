@extends('layouts.app')

@section('title', 'My Dashboard')

{{-- This page keeps its own heading — the greeting names the reader and the
     hour — so it opts out of the generic screen name the bar would otherwise
     put above it. Two headings on one fold, one of them saying "My Dashboard"
     to somebody already looking at it, is the double header again. --}}
@section('screen_name_class', 'is-hidden')

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
        {{-- Kept on a phone: "Good morning, Maria" is content, not a label, and
             the topbar says "Today" rather than who is reading. --}}
        <section class="page-heading page-heading-keep staff-heading">
            <div>
                <p class="eyebrow">My workday</p>
                <h1>{{ $greeting }}, {{ $dashboard['employee']['first_name'] }}.</h1>
                {{-- The date used to lead this line. The clock opposite carries
                     it now, ticking, so printing it here as well only said the
                     same day twice at either end of one row. What is left is
                     the part the clock cannot say: who you are on shift as. --}}
                <p>{{ $dashboard['employee']['position'] ?? 'Staff' }}@if ($dashboard['employee']['department']) · {{ $dashboard['employee']['department'] }}@endif</p>
            </div>
            @include('partials.current-time')
        </section>

        @if ($errors->any())
            <div class="staff-flash staff-flash-danger" role="alert">
                <x-icon name="close" />
                <span>{{ $errors->first() }}</span>
            </div>
        @endif

        {{-- Schedule changes lead the page: a shift that moved is the one thing here
             that stops being useful the moment it is read too late. --}}
        <x-staff.schedule-changes :changes="$dashboard['schedule_changes']" />

        {{-- The phone's first fold: the same data as the pair below, composed
             for one column and one thumb. Each is hidden where the other is
             shown, so only one is ever visible. --}}
        <div class="staff-phone-fold">
            <x-staff.phone-today
                :today="$dashboard['today']"
                :shift="$dashboard['shift']"
                :timesheet="$dashboard['timesheet']"
                :summary="$dashboard['attendance_summary']"
                :overtime="$dashboard['overtime']"
                :upcoming="$dashboard['upcoming']"
            />
        </div>

        <div class="staff-grid staff-grid-hero staff-desk-fold">
            <x-staff.today-attendance :today="$dashboard['today']" />
            <x-staff.my-shift :shift="$dashboard['shift']" />
        </div>

        {{-- After the day itself, before the slower material: how the last few
             weeks have been treating you. Absent only for a profile the
             indicator is not kept for. --}}
        @if ($dashboard['burnout'])
            <x-staff.burnout-risk :burnout="$dashboard['burnout']" />
        @endif

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
