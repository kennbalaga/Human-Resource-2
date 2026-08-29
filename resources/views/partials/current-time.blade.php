{{-- The live clock, lifted out of the topbar and set down in the dashboard's
     heading row the way the reference does: a label and the hour, held against
     the right edge opposite the greeting. Both dashboards run it — the overview
     and the staff one — so the hour reads the same whoever signs in. The
     dashboards only, though: it is a thing you glance at on the way in, not a
     fixture of every screen.

     It keeps the topbar's data attributes because dashboard.js still drives it
     by those, ticking it forward from the server's epoch rather than the
     device's own — a workstation with a wrong clock still reads hospital time.
     Hidden below the large breakpoint, exactly as it was in the bar. --}}
@php
    $displayTimezone = config('workforce.timezone', 'Asia/Manila');
    $displayNow = now($displayTimezone);
@endphp

<div class="current-time-strip d-none d-lg-flex">
    <time
        class="current-time"
        datetime="{{ $displayNow->toIso8601String() }}"
        data-topbar-clock
        data-timezone="{{ $displayTimezone }}"
        data-server-epoch="{{ $displayNow->getTimestamp() }}"
        title="Philippine time ({{ $displayTimezone }})"
    >
        {{-- Off the page, not out of the markup: on its own a bare number reads
             as an unlabelled figure to a screen reader. --}}
        <span class="visually-hidden">Current time</span>
        <strong class="current-time-value" data-topbar-time>{{ $displayNow->format('g:i:s A') }}</strong>
        <span class="current-time-date" data-topbar-date>{{ $displayNow->format('D, M j, Y') }}</span>
    </time>
</div>
