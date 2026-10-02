@php
    $moreUser = auth()->user();
    $moreInitials = collect(explode(' ', $moreUser->name))
        ->filter()
        ->take(2)
        ->map(fn ($part) => strtoupper(substr($part, 0, 1)))
        ->implode('');
@endphp

{{--
    The fifth tab.

    A list of destinations rather than a screen of its own content, so the
    ordering is the whole design: what an employee needs on a shift is already
    in the four tabs, and this holds what they need on a payday, on a new
    phone, or once a year.

    Notifications is deliberately absent. The bell in the header reaches it from
    every screen, and a row here under a bell would be the same door twice.
--}}
@extends('layouts.app')

@section('title', 'More')

@section('content')
    <div class="more-screen">
        <section class="page-heading">
            <div>
                <p class="eyebrow">Account</p>
                <h1>More</h1>
                <p>Your records, this device, and everything that is not part of a shift.</p>
            </div>
        </section>

        <a class="panel more-identity" href="{{ route('profile.show') }}">
            <span class="avatar more-avatar">{{ $moreInitials }}</span>
            <span class="more-identity-copy">
                <strong>{{ $moreUser->name }}</strong>
                <span>{{ $employee?->position?->title ?? $currentRole }}@if ($employee?->department) · {{ $employee->department->name }}@endif</span>
                @if ($employee)
                    <span class="more-identity-number">{{ $employee->employee_number }}</span>
                @endif
            </span>
            <x-icon name="chevron-right" />
        </a>

        <section class="more-group" aria-labelledby="more-records">
            <h2 class="more-group-title" id="more-records">Your records</h2>
            <div class="panel more-list">
                @if ($employee)
                    <a class="more-row" href="{{ route('profile.badge') }}">
                        <span class="more-row-icon"><x-icon name="scan" /></span>
                        <span class="more-row-label">My attendance badge</span>
                        <x-icon name="chevron-right" />
                    </a>
                @endif

                <a class="more-row" href="{{ route('timesheets.index') }}">
                    <span class="more-row-icon"><x-icon name="timesheet" /></span>
                    <span class="more-row-label">Timesheets</span>
                    @if ($timesheetsToSubmit > 0)
                        <span class="status-badge status-warning"><span class="status-dot"></span>{{ $timesheetsToSubmit }} to submit</span>
                    @endif
                    <x-icon name="chevron-right" />
                </a>

                @if ($employee)
                    <a class="more-row" href="{{ route('insights.mine') }}">
                        <span class="more-row-icon"><x-icon name="trend" /></span>
                        <span class="more-row-label">My work patterns</span>
                        <x-icon name="chevron-right" />
                    </a>
                @endif
            </div>
        </section>

        {{--
            Appearance, saved the moment it is chosen.

            It lives here because the phone has no account menu: the topbar's
            was hidden when the tab bar replaced the drawer, and that menu was
            the only place in the app where choosing a theme saved itself. The
            radios on the settings page only preview until the form is
            submitted, so somebody tapping Dark there and walking away watched
            it revert on the next screen.

            Same contract as the desktop menu — data-theme-set plus the persist
            URL, read by theme.js off the button that was clicked.
        --}}
        <section class="more-group" aria-labelledby="more-appearance">
            <h2 class="more-group-title" id="more-appearance">Appearance</h2>
            <div class="panel more-appearance">
                <div class="theme-choice-options" role="group" aria-labelledby="more-appearance">
                    @foreach (['light' => ['sun', 'Light'], 'dark' => ['moon', 'Dark'], 'system' => ['settings', 'System']] as $value => [$themeIcon, $themeLabel])
                        <button
                            class="theme-choice-option"
                            type="button"
                            data-theme-set="{{ $value }}"
                            data-theme-update-url="{{ route('settings.theme.update') }}"
                            aria-pressed="{{ $moreUser->preference->theme === $value ? 'true' : 'false' }}"
                        >
                            <x-icon :name="$themeIcon" />
                            <span>{{ $themeLabel }}</span>
                        </button>
                    @endforeach
                </div>
            </div>
        </section>

        <section class="more-group" aria-labelledby="more-device">
            <h2 class="more-group-title" id="more-device">This device</h2>
            <div class="panel more-list">
                {{-- Whether a lock is actually set is known only to this device's
                     own storage, so the row states what it is rather than
                     claiming a state the server cannot see. --}}
                <a class="more-row" href="{{ route('settings.edit') }}#device-lock">
                    <span class="more-row-icon"><x-icon name="lock" /></span>
                    <span class="more-row-label">App lock and PIN</span>
                    <x-icon name="chevron-right" />
                </a>

                <a class="more-row" href="{{ route('settings.edit') }}">
                    <span class="more-row-icon"><x-icon name="settings" /></span>
                    <span class="more-row-label">Appearance and settings</span>
                    <x-icon name="chevron-right" />
                </a>

                <a class="more-row" href="{{ route('privacy-policy') }}" data-privacy-open>
                    <span class="more-row-icon"><x-icon name="shield" /></span>
                    <span class="more-row-label">Privacy policy</span>
                    <x-icon name="chevron-right" />
                </a>
            </div>
        </section>

        <form class="more-signout" method="POST" action="{{ route('logout') }}">
            @csrf
            <button class="btn more-signout-button" type="submit">
                <x-icon name="logout" /> Sign out
            </button>
        </form>

        <p class="more-footnote">
            {{ config('branding.organization') }}<br>
            Human Resource Management System
        </p>
    </div>
@endsection
