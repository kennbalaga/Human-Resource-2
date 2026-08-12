@props(['today'])

@php
    // Four readouts, always the same four slots. Time Out and Total Hours take the
    // last two positions once the day closes; before that they read as pending, so
    // the card never reflows under the employee between morning and evening.
    $readouts = [
        ['label' => 'Time In', 'value' => $today['time_in'], 'pending' => 'Not yet'],
        ['label' => 'Scheduled', 'value' => $today['scheduled'], 'pending' => 'No shift'],
        ['label' => 'Time Out', 'value' => $today['time_out'], 'pending' => $today['state'] === 'clocked_in' ? 'On the clock' : '—'],
        ['label' => 'Total Hours', 'value' => $today['total_hours'], 'pending' => '—'],
    ];
@endphp

<section
    class="panel staff-today staff-today-{{ $today['status_tone'] }}"
    id="today-attendance"
    aria-labelledby="today-attendance-title"
    @if ($today['check_in_epoch']) data-clocked-in-since="{{ $today['check_in_epoch'] }}" @endif
>
    <div class="panel-header">
        <div>
            <p class="panel-kicker">Time &amp; attendance</p>
            <h2 id="today-attendance-title">Today’s attendance</h2>
        </div>
        <span class="staff-today-date">{{ $today['date_label'] }}</span>
    </div>

    <div class="staff-today-body">
        <div class="staff-today-state">
            <span class="staff-state-dot" aria-hidden="true"></span>
            <div>
                <strong>{{ $today['status_label'] }}</strong>
                <span>
                    @if ($today['state'] === 'clocked_in')
                        On the clock for <b data-elapsed-since aria-live="polite">—</b>
                    @elseif ($today['state'] === 'completed')
                        Attendance complete for today
                    @else
                        Your first scan of the day starts the record
                    @endif
                </span>
            </div>
            <span class="staff-chip staff-chip-{{ $today['punctuality']['tone'] }}">
                <x-icon :name="$today['punctuality']['tone'] === 'success' ? 'check-circle' : 'clock'" />
                {{ $today['punctuality']['label'] }}
            </span>
        </div>

        <dl class="staff-today-readouts">
            @foreach ($readouts as $readout)
                <div @class(['is-pending' => $readout['value'] === null])>
                    <dt>{{ $readout['label'] }}</dt>
                    <dd>{{ $readout['value'] ?? $readout['pending'] }}</dd>
                </div>
            @endforeach
        </dl>

        @if ($today['punctuality']['detail'])
            <p class="staff-today-note">{{ $today['punctuality']['detail'] }}.</p>
        @endif

        {{-- The device row is what makes this a readout of the biometric Time &
             Attendance System rather than a web form that happens to store times. --}}
        <div class="staff-biometric" role="group" aria-label="Biometric attendance link">
            <span class="staff-biometric-icon"><x-icon name="fingerprint" /></span>
            <div class="staff-biometric-copy">
                <strong>
                    @if ($today['biometric']['enrolled'])
                        Enrolled on {{ $today['biometric']['device'] ?? 'a biometric terminal' }}
                    @else
                        Not enrolled on a biometric terminal
                    @endif
                </strong>
                <span>
                    @if ($today['biometric']['enrolled'])
                        Biometric ID {{ $today['biometric']['external_id'] }}
                        @if ($today['biometric']['last_sync']) · last synced {{ $today['biometric']['last_sync'] }} @endif
                    @else
                        Ask HR to enrol your fingerprint so terminal scans post to this card.
                    @endif
                </span>
            </div>
            <span @class(['staff-biometric-state', 'is-online' => $today['biometric']['enrolled'] && $today['biometric']['online']])>
                {{ $today['biometric']['enrolled'] && $today['biometric']['online'] ? 'Linked' : 'Not linked' }}
            </span>
        </div>

        @if ($today['in_source'] || $today['out_source'])
            <ul class="staff-source-list">
                @if ($today['in_source'])
                    <li>
                        <x-icon :name="$today['in_source']['biometric'] ? 'fingerprint' : 'log-in'" />
                        <span>In · {{ $today['in_source']['label'] }}@if ($today['in_source']['device']) · {{ $today['in_source']['device'] }}@endif</span>
                    </li>
                @endif
                @if ($today['out_source'])
                    <li>
                        <x-icon :name="$today['out_source']['biometric'] ? 'fingerprint' : 'log-out'" />
                        <span>Out · {{ $today['out_source']['label'] }}@if ($today['out_source']['device']) · {{ $today['out_source']['device'] }}@endif</span>
                    </li>
                @endif
            </ul>
        @endif
    </div>

    <div class="staff-today-actions">
        @if ($today['can_clock_out'] || $today['can_clock_in'])
            <form method="POST" action="{{ $today['can_clock_out'] ? route('attendance.check-out') : route('attendance.check-in') }}" class="staff-clock-form">
                @csrf
                <input type="hidden" name="office_location_id" value="{{ $today['office']['id'] }}">
                <input type="hidden" name="return_to" value="dashboard">

                @if ($today['reason_required'])
                    <label class="staff-clock-reason">
                        <span>Reason for manual attendance</span>
                        <input type="text" name="notes" maxlength="500" required placeholder="Why the terminal cannot be used…" value="{{ old('notes') }}">
                    </label>
                @endif

                <button class="btn staff-clock-button {{ $today['can_clock_out'] ? 'is-out' : 'is-in' }}" type="submit">
                    <x-icon :name="$today['can_clock_out'] ? 'log-out' : 'log-in'" />
                    <span>{{ $today['can_clock_out'] ? 'Clock Out' : 'Clock In' }}</span>
                </button>
            </form>
        @elseif ($today['state'] === 'completed')
            <p class="staff-today-closed">
                <x-icon name="check-circle" />
                <span>Clocked out at {{ $today['time_out'] }} · {{ $today['total_hours'] }} recorded.</span>
            </p>
        @else
            <p class="staff-today-closed">
                <x-icon name="shield" />
                <span>Website attendance is off ({{ $today['capture_mode_label'] }}). Use the biometric terminal.</span>
            </p>
        @endif

        <a href="{{ route('attendance.index') }}" class="panel-link">My attendance log <x-icon name="chevron-right" /></a>
    </div>
</section>
