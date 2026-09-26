{{--
    The attendance badge, on its own.

    The profile already draws this code, but the profile is three screens of
    record around it. This page exists for the ten seconds at a door: the code
    as large as the screen allows, the name under it so the officer can check
    the face against it, and nothing else to read.

    Deliberately not a download. The file behind it is audited and asks for a
    password, because a copy leaves the device; looking at your own badge does
    not, and putting a password in front of the thing you need at a turnstile
    would only teach people to screenshot it instead.
--}}
@extends('layouts.app')

@section('title', 'My badge')

@section('content')
    <div class="badge-screen">
        <section class="page-heading">
            <div>
                <p class="eyebrow">Time &amp; attendance</p>
                <h1>My badge</h1>
            </div>
        </section>

        {{-- Outside the heading on purpose: a phone hides a heading's wording,
             because the bar above already says the screen's name — but this is
             the instruction, not the name, and it has to survive that. --}}
        <p class="badge-instruction">Hold this up to the officer at the entrance. They scan it — there is nothing here for you to tap.</p>

        <article class="panel badge-card">
            {{-- .profile-qr-code carries the white plate the camera needs to find
                 the code, in the dark theme as well as the light one. --}}
            <figure class="profile-qr-code badge-qr" role="img" aria-label="Attendance badge for {{ $employee->full_name }}">
                {!! $attendanceQrSvg !!}
            </figure>

            <div class="badge-identity">
                <strong>{{ $employee->full_name }}</strong>
                <span>{{ $employee->position?->title ?? 'Staff' }}@if ($employee->department) · {{ $employee->department->name }}@endif</span>
                <span class="badge-number">{{ $employee->employee_number }}</span>
            </div>
        </article>

        {{-- Revealed by badge-screen.js only once a wake lock is actually held,
             so the page never promises something the browser refused. --}}
        <p class="badge-awake" data-badge-awake hidden>
            <x-icon name="check-circle" /> Your screen stays awake while this is open.
        </p>

        <div class="badge-note">
            <x-icon name="shield" />
            <p>
                <strong>Treat this like your ID.</strong>
                This code does not change, so anyone holding a photo of it can be scanned in as you. Do not send it to
                anyone, and tell HR the moment you think it has been copied — they retire it and issue you a new one.
            </p>
        </div>

        <div class="badge-actions">
            <a class="btn btn-light" href="{{ route('profile.show') }}"><x-icon name="users" /> My profile</a>
        </div>
    </div>
@endsection
