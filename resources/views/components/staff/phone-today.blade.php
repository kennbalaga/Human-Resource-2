@props(['today', 'shift', 'timesheet', 'summary', 'overtime', 'upcoming'])

@php
    /*
     * The dashboard's first fold, composed for a phone.
     *
     * Same data as the cards below it — this adds nothing and queries nothing.
     * What changes is the order and the weight: on a 390px screen the reader
     * has one fold, and the thing that belongs in it is how to be recorded on
     * shift, not four panels of summary.
     *
     * Hidden above the phone breakpoint, where the desktop pair takes over. The
     * two never render visibly at once.
     */
    $clockAction = $today['can_clock_out'] ? 'out' : 'in';
    $canPunchHere = $today['can_clock_out'] || $today['can_clock_in'];

    /* Where the reader is meant to go instead. The terminal is named when the
       employee is actually enrolled on one; otherwise it stays generic rather
       than sending somebody to a machine that does not know them. */
    $terminal = $today['biometric']['enrolled']
        ? ($today['biometric']['device'] ?? 'your biometric terminal')
        : null;
@endphp

<section class="phone-today" aria-labelledby="phone-today-title">
    <div class="phone-today-head">
        <p class="phone-today-kicker" id="phone-today-title">
            {{ $shift['kind'] === 'shift' ? 'Your shift today' : 'Today' }}
        </p>
        <span class="phone-today-chip phone-today-chip-{{ $today['punctuality']['tone'] }}">
            {{ $today['punctuality']['label'] }}
        </span>
    </div>

    <p class="phone-today-hours">{{ $shift['hours'] }}</p>
    <p class="phone-today-meta">{{ $shift['name'] }}@if ($shift['department']) · {{ $shift['department'] }}@endif</p>

    {{-- The door leads. Under hybrid the button below is a fallback and reads
         as one; under biometric_only there is no button at all and this line is
         the whole instruction. --}}
    <div class="phone-today-route">
        <x-icon name="fingerprint" />
        <span>
            @if ($terminal)
                Scan at <strong>{{ $terminal }}</strong>, or show your badge at the entrance.
            @else
                Scan at a biometric terminal, or show your badge at the entrance.
            @endif
        </span>
    </div>

    @if ($canPunchHere)
        <form method="POST" action="{{ $today['can_clock_out'] ? route('attendance.check-out') : route('attendance.check-in') }}" class="phone-today-form">
            @csrf
            <input type="hidden" name="office_location_id" value="{{ $today['office']['id'] }}">
            <input type="hidden" name="return_to" value="dashboard">

            @if ($today['reason_required'])
                <label class="phone-today-reason">
                    <span>Reason for recording this by hand</span>
                    {{-- id deliberately distinct from the desktop card's field:
                         both are in the document, and duplicate ids would point
                         every label at whichever came first. --}}
                    <input id="phoneManualReason" type="text" name="notes" maxlength="500" required placeholder="Why the terminal cannot be used…" value="{{ old('notes') }}">
                </label>
            @endif

            <div class="phone-today-actions">
                <a class="phone-today-ghost" href="{{ route('profile.badge') }}">
                    <x-icon name="scan" /> My badge
                </a>
                <button class="phone-today-ghost" type="submit">
                    <x-icon :name="$today['can_clock_out'] ? 'log-out' : 'log-in'" />
                    Check {{ $clockAction }} here
                </button>
            </div>
        </form>
    @else
        <div class="phone-today-actions">
            <a class="phone-today-ghost phone-today-ghost-wide" href="{{ route('profile.badge') }}">
                <x-icon name="scan" /> My badge
            </a>
        </div>

        <p class="phone-today-closed">
            @if ($today['state'] === 'completed')
                Clocked out at {{ $today['time_out'] }} · {{ $today['total_hours'] }} recorded.
            @else
                {{ $today['capture_mode_label'] }} — the readers and the entrance scanner are the ways time is recorded.
            @endif
        </p>
    @endif
</section>

{{-- Three figures, each labelled with the period it actually covers rather than
     a tidier word that would be wrong. --}}
<section class="phone-stats panel" aria-label="Your hours">
    <div>
        <p class="phone-stat-value">{{ $timesheet['total_hours'] }}</p>
        <p class="phone-stat-label">{{ $timesheet['period_label'] }}</p>
    </div>
    <div>
        <p class="phone-stat-value">{{ $overtime['total_hours'] }}</p>
        <p class="phone-stat-label">Overtime, {{ $overtime['month_label'] }}</p>
    </div>
    <div>
        <p class="phone-stat-value {{ $summary['late'] > 0 ? 'is-warning' : '' }}">{{ $summary['late'] }}</p>
        <p class="phone-stat-label">Late, {{ $summary['month_label'] }}</p>
    </div>
</section>

@if ($timesheet['attention'])
    <a class="phone-attention" href="{{ route('timesheets.index') }}">
        <span class="phone-attention-icon"><x-icon :name="$timesheet['icon']" /></span>
        <span class="phone-attention-copy">
            <strong>{{ $timesheet['label'] }}</strong>
            <span>{{ $timesheet['period_label'] }}</span>
        </span>
        <x-icon name="chevron-right" />
    </a>
@endif

@if (! empty($upcoming['rows']))
    <section class="panel phone-upcoming" aria-labelledby="phone-upcoming-title">
        <h2 class="phone-upcoming-title" id="phone-upcoming-title">Later this week</h2>
        <ul>
            @foreach (collect($upcoming['rows'])->take(3) as $row)
                <li>
                    <span class="phone-upcoming-day">
                        <b>{{ $row['weekday'] }}</b>
                        <span>{{ $row['date_label'] }}</span>
                    </span>
                    <span class="phone-upcoming-what">{{ $row['time'] }}@if ($row['department']) · {{ $row['department'] }}@endif</span>
                    @if ($row['type'] !== 'shift')
                        <span class="phone-upcoming-tag">{{ $row['shift'] }}</span>
                    @endif
                </li>
            @endforeach
        </ul>
        <a class="phone-upcoming-link" href="{{ route('schedules.index') }}">My schedule <x-icon name="chevron-right" /></a>
    </section>
@endif
