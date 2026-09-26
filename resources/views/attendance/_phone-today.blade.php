@php
    /*
     * Today's attendance, composed for a phone.
     *
     * The same payload the panel beside it draws — this queries nothing and
     * adds no fact. What it changes is the order: the elapsed shift is the
     * number somebody opens this page to read mid-shift, and the way to be
     * recorded is the reader, so both come before the readouts.
     */
    $window = $today['window'];
    $onTheClock = $todayRecord?->check_in_at !== null && $todayRecord?->check_out_at === null;

    /* The shift's length, for the bar the elapsed counter fills. Absolute,
       because an overnight window ends on the following date. */
    $scheduledSeconds = isset($window['start_at'], $window['end_at'])
        ? abs($window['start_at']->diffInSeconds($window['end_at']))
        : 0;

    $scheduledLabel = $scheduledSeconds > 0
        ? intdiv($scheduledSeconds, 3600).'h '.str_pad((string) intdiv($scheduledSeconds % 3600, 60), 2, '0', STR_PAD_LEFT).'m'
        : null;

    $canPunchHere = $manualAttendanceAllowed && ! $todayRecord?->check_out_at;
@endphp

<section
    @class(['panel', 'attendance-phone', 'is-'.$today['tone']])
    data-elapsed-scope
    @if ($onTheClock && $todayRecord?->check_in_at) data-clocked-in-since="{{ $todayRecord->check_in_at->getTimestamp() }}" @endif
    aria-labelledby="attendancePhoneTitle"
>
    <div class="attendance-phone-head">
        <span class="attendance-phone-dot" aria-hidden="true"></span>
        <h2 id="attendancePhoneTitle">{{ $today['title'] }}</h2>
    </div>

    @if ($onTheClock && $scheduledLabel)
        <p class="attendance-phone-elapsed">
            {{-- Written as a fallback, replaced by elapsed.js on a browser that
                 runs it. Never the only way to read the time: the tiles below
                 carry the punch itself. --}}
            <b data-elapsed-since>—</b>
            <span>worked of {{ $scheduledLabel }}</span>
        </p>

        <div class="attendance-phone-track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" aria-label="Shift progress">
            <span data-elapsed-bar data-elapsed-total="{{ $scheduledSeconds }}"></span>
        </div>
    @else
        <p class="attendance-phone-line">{{ $today['line'] }}</p>
    @endif

    {{-- How time is actually recorded, in the words the capture mode chose. On
         biometric_only this is the entire instruction; under hybrid the button
         below is the fallback and reads as one. --}}
    <p class="attendance-phone-route">
        <x-icon :name="$captureNote['icon']" />
        <span>{{ $captureNote['text'] }}</span>
    </p>

    @if ($canPunchHere)
        <form
            method="POST"
            action="{{ $todayRecord?->check_in_at ? route('attendance.check-out') : route('attendance.check-in') }}"
            class="attendance-phone-form"
            data-attendance-action="{{ $todayRecord?->check_in_at ? 'check-out' : 'check-in' }}"
        >
            @csrf
            <input type="hidden" name="office_location_id" value="{{ $office->id }}">

            @if ($manualAttendanceReasonRequired)
                <label class="attendance-phone-reason">
                    <span>Reason for recording this by hand</span>
                    {{-- Its own id: the desktop card's field is in the document
                         too, and a shared id would point one label at the other
                         card's input. --}}
                    <input id="attendancePhoneReason" type="text" name="notes" maxlength="500" required placeholder="Why the terminal can’t be used" value="{{ old('notes') }}">
                </label>
            @endif

            <div class="attendance-phone-actions">
                <a class="attendance-phone-ghost" href="{{ route('profile.badge') }}">
                    <x-icon name="scan" /> My badge
                </a>
                <button class="attendance-phone-ghost" type="submit">
                    <x-icon :name="$todayRecord?->check_in_at ? 'log-out' : 'log-in'" />
                    Check {{ $todayRecord?->check_in_at ? 'out' : 'in' }} here
                </button>
            </div>
        </form>
    @else
        <div class="attendance-phone-actions">
            <a class="attendance-phone-ghost attendance-phone-ghost-wide" href="{{ route('profile.badge') }}">
                <x-icon name="scan" /> My badge
            </a>
        </div>
    @endif

    @if ($today['works_today'])
        <dl class="attendance-phone-tiles">
            @foreach ($today['tiles'] as $tile)
                <div>
                    <dt>{{ $tile['label'] }}</dt>
                    <dd>{{ $tile['value'] }}</dd>
                    @if ($tile['detail'])
                        <dd class="attendance-phone-tile-detail">{{ $tile['detail'] }}</dd>
                    @endif
                </div>
            @endforeach
        </dl>
    @endif
</section>
