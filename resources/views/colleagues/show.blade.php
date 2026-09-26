@php
    $colleagueInitials = collect(explode(' ', $colleague->full_name))
        ->filter()
        ->take(2)
        ->map(fn ($part) => strtoupper(substr($part, 0, 1)))
        ->implode('');
@endphp

{{--
    Someone you are on shift with.

    Deliberately small. It answers who this person is, what the two of you are
    both on, and whether you can ask them to take it — and then stops. There is
    no contact detail here because there is none to show: a phone number and an
    email address belong to HR, which is the same rule the desktop record
    follows, and pretending otherwise would be the one thing this card could
    get badly wrong.
--}}
@extends('layouts.app')

@section('title', 'On shift with you')

@section('content')
    <div class="colleague-card">
        <section class="page-heading">
            <div>
                <p class="eyebrow">Scheduling</p>
                <h1>On shift with you</h1>
                <p>{{ $isToday ? 'Today' : $date->format('l, j F') }}</p>
            </div>
        </section>

        <article class="panel colleague-identity">
            <span class="avatar colleague-avatar">{{ $colleagueInitials }}</span>
            <strong>{{ $colleague->full_name }}</strong>
            <span>{{ $colleague->position?->title ?? 'Staff' }}@if ($colleague->department) · {{ $colleague->department->name }}@endif</span>
            <span class="colleague-number">{{ $colleague->employee_number }}</span>
        </article>

        <article class="panel colleague-shift">
            <p class="colleague-shift-kicker">{{ $isToday ? 'Today, with you' : 'With you on this day' }}</p>
            @if ($hours)
                <p class="colleague-shift-hours">{{ $hours }}</p>
            @endif
            <p class="colleague-shift-where">
                {{ $shift?->name ?? 'Same shift' }}@if ($theirRoom) · {{ $theirRoom }}@endif
            </p>
            @if ($sameRoom)
                <p class="colleague-shift-note">
                    <x-icon name="check-circle" /> You are both rostered to {{ $theirRoom }}.
                </p>
            @elseif ($theirRoom && $myRoom)
                <p class="colleague-shift-note">
                    <x-icon name="map-pin" /> You are in {{ $myRoom }}, they are in {{ $theirRoom }}.
                </p>
            @endif
        </article>

        @unless ($isPast)
            <a class="colleague-swap" href="{{ route('schedule-preferences.index') }}#shift-swaps">
                <x-icon name="swap" /> Ask {{ $colleague->first_name }} to swap a shift
            </a>
        @endunless

        <div class="colleague-note">
            <x-icon name="shield" />
            <p>
                <strong>No contact details here.</strong>
                A colleague's phone number and email stay with HR, the same as on a computer. This card is for knowing
                who you are working beside and acting on it — it is not a way to reach people off shift.
            </p>
        </div>

        <a class="colleague-back" href="{{ route('schedules.index', ['date' => $date->toDateString()]) }}">
            <x-icon name="arrow-left" /> Back to that day
        </a>
    </div>
@endsection
