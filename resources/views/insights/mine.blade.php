{{--
    My work patterns.

    Four readings the staff dashboard used to carry near the bottom. They are
    the same components, given a page rather than a footer, because on a phone
    the dashboard has one fold and this is not what belongs in it.

    Order is slowest-changing last: burnout moves over weeks, the month's tally
    over days, and the analytics are a trend. Nothing here is actionable in the
    next ten minutes, which is exactly why it is not on Today.
--}}
@extends('layouts.app')

@section('title', 'My work patterns')

@section('content')
    <div class="insights-mine">
        <section class="page-heading">
            <div>
                <p class="eyebrow">Account</p>
                <h1>My work patterns</h1>
                <p>How the last few weeks have gone: your hours, your overtime, and what they add up to.</p>
            </div>
        </section>

        @if ($burnout)
            <x-staff.burnout-risk :burnout="$burnout" />
        @endif

        <div class="insights-pair">
            <x-staff.attendance-summary :summary="$summary" />
            <x-staff.overtime-summary :overtime="$overtime" />
        </div>

        <x-staff.my-analytics :analytics="$analytics" />
    </div>
@endsection
