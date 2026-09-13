@extends('layouts.app')

@section('title', 'Attendance')

@section('content')
    @php
        $officeTime = now()->timezone($office->timezone);

        // One sentence for the capture rule, said once. Under Biometric Only there
        // is no check-in control at all rather than one that does nothing.
        $captureNote = match (true) {
            ! $manualAttendanceAllowed => [
                'icon' => 'fingerprint',
                'text' => 'Record your time at a biometric terminal or the entrance badge scanner. Your punches appear here automatically.',
            ],
            $manualAttendanceReasonRequired => [
                'icon' => 'alert',
                'text' => 'Manual attendance is on for now'
                    .($manualAttendanceExpiresAt ? ', until '.$manualAttendanceExpiresAt->timezone($office->timezone)->format('M j, g:i A') : '')
                    .'. Give a reason when you check in or out.',
            ],
            default => [
                'icon' => 'log-in',
                'text' => 'Check in here or at a biometric terminal.',
            ],
        };
    @endphp

    <div
        class="attendance-page"
        id="attendanceApp"
        data-office-timezone="{{ $office->timezone }}"
        data-attendance-capture-state="{{ $attendanceCaptureState }}"
        data-attendance-state-url="{{ route('attendance.state') }}"
        @if ($attendanceCaptureMode === \App\Services\AttendanceCaptureSettings::EMERGENCY_MANUAL && $manualAttendanceExpiresAt)
            data-manual-mode-expires-at="{{ $manualAttendanceExpiresAt->getTimestamp() }}"
        @endif
    >
        <section class="page-heading attendance-heading">
            <div>
                <h1>Attendance</h1>
                <p>{{ $view === 'scanner' ? 'Scan employee badges to record their time in and out.' : 'See today’s status and your recent time records.' }}</p>
            </div>
            @if ($view === 'mine')
                <a class="btn btn-outline-primary dashboard-action" href="{{ route('timesheets.index') }}">
                    <x-icon name="timesheet" /> My timesheet
                </a>
            @endif
        </section>

        @if (session('success'))
            <div class="attendance-alert attendance-alert-success" role="status">
                <x-icon name="check-circle" />
                <span>{{ session('success') }}</span>
            </div>
        @endif

        @if ($errors->any())
            <div class="attendance-alert attendance-alert-danger" role="alert">
                <x-icon name="close" />
                <span>{{ $errors->first() }}</span>
            </div>
        @endif

        {{-- Two jobs, two views. An officer at the entrance should not scroll past
             their own record to reach the camera, and staff who cannot scan never
             see a scanner they might mistake for self check-in. --}}
        @if ($canScanQr)
            <nav class="attendance-tabs" aria-label="Attendance views">
                <a
                    href="{{ route('attendance.index') }}"
                    @class(['attendance-tab', 'is-active' => $view === 'mine'])
                    @if ($view === 'mine') aria-current="page" @endif
                >
                    <x-icon name="clock" /> My attendance
                </a>
                <a
                    href="{{ route('attendance.index', ['view' => 'scanner']) }}"
                    @class(['attendance-tab', 'is-active' => $view === 'scanner'])
                    @if ($view === 'scanner') aria-current="page" @endif
                >
                    <x-icon name="scan" /> Badge scanner
                </a>
            </nav>
        @endif

        @if ($view === 'scanner')
            <section
                class="attendance-scanner"
                data-qr-scanner
                data-scan-url="{{ route('attendance.qr-scan.store') }}"
                data-session-key="attendance.scanner.{{ auth()->id() }}"
                aria-labelledby="attendanceScannerTitle"
            >
                <div class="attendance-scanner-intro">
                    <div>
                        <h2 id="attendanceScannerTitle">Badge scanner</h2>
                        <p>Records time for the employee on the badge, not for you. Your name is saved on each record as the scanning officer.</p>
                    </div>
                    <p class="attendance-scanner-officer">
                        <x-icon name="shield" />
                        <span>Scanning officer: {{ auth()->user()->name }}</span>
                    </p>
                </div>

                <div class="attendance-scanner-grid">
                    <div class="attendance-scanner-viewport" data-scanner-viewport>
                        <video data-scanner-video playsinline muted></video>
                        <div class="attendance-scanner-reticle" aria-hidden="true"><i></i><i></i><i></i><i></i></div>

                        <div class="attendance-scanner-idle" data-scanner-idle>
                            <x-icon name="camera-off" />
                            <strong data-idle-title>Camera is off</strong>
                            <p data-idle-message>Start the camera, then hold an employee’s badge inside the frame.</p>
                            <button class="btn btn-primary" type="button" data-scanner-start>
                                <x-icon name="scan" />
                                <span data-start-label>Start scanning</span>
                            </button>
                        </div>

                        <span class="attendance-scanner-live" data-scanner-live hidden><span aria-hidden="true"></span>Scanning</span>
                        <button class="btn attendance-scanner-stop" type="button" data-scanner-stop hidden>Stop camera</button>
                    </div>

                    <div class="panel attendance-scan-result" data-scanner-result aria-live="polite">
                        <p class="attendance-scan-heading"><x-icon name="camera-off" /><span>Scanner is paused</span></p>
                        <p class="attendance-scan-hint">Start the camera to scan badges. Each result appears here.</p>
                    </div>
                </div>

                <section class="panel attendance-session" aria-labelledby="attendanceSessionTitle">
                    <div class="panel-header">
                        <h2 id="attendanceSessionTitle">Scans this session</h2>
                        <span class="history-caption" data-session-count>0 recorded</span>
                    </div>
                    <ol class="attendance-session-list" data-session-list></ol>
                    <p class="attendance-session-empty" data-session-empty>Badges you scan are listed here until you close this tab.</p>
                </section>

                {{-- The scanner script builds its result cards from these, so its
                     icons stay the app's own rather than a second copy in JS. --}}
                @foreach (['check-circle', 'alert', 'x-circle', 'scan', 'camera-off', 'log-in', 'log-out'] as $scannerIcon)
                    <template data-scanner-icon="{{ $scannerIcon }}"><x-icon :name="$scannerIcon" /></template>
                @endforeach
            </section>
        @else
            @php($today = $attendance['today'])

            <section class="panel attendance-today is-{{ $today['tone'] }}" data-attendance-live aria-labelledby="attendanceTodayTitle">
                <div class="attendance-today-top">
                    <div class="attendance-today-summary">
                        <span class="attendance-today-icon"><x-icon :name="$today['icon']" /></span>
                        <div>
                            <p class="attendance-today-date">Today</p>
                            <h2 id="attendanceTodayTitle">{{ $today['title'] }}</h2>
                            <p class="attendance-today-line">{{ $today['line'] }}</p>
                        </div>
                    </div>
                    <p class="attendance-today-clock">
                        <time id="liveAttendanceClock" datetime="{{ $officeTime->toIso8601String() }}">{{ $officeTime->format('g:i:s A') }}</time>
                        <span>{{ $officeTime->format('D, M j, Y') }}</span>
                    </p>
                </div>

                <ul class="attendance-today-shift">
                    @if ($today['works_today'])
                        <li>
                            <x-icon name="calendar" />
                            <span><strong>{{ $today['window']['hours'] }}</strong>@if ($today['window']['name']) · {{ $today['window']['name'] }}@endif</span>
                        </li>
                        <li><x-icon name="map-pin" /><span>{{ $office->name }}</span></li>
                        <li><x-icon name="clock" /><span>Late after <strong>{{ $today['window']['late_after'] }}</strong></span></li>
                    @else
                        <li><x-icon name="calendar" /><span>No shift scheduled today</span></li>
                    @endif
                </ul>

                @if ($today['works_today'])
                    <dl class="attendance-today-tiles">
                        @foreach ($today['tiles'] as $tile)
                            <div>
                                <dt>{{ $tile['label'] }}</dt>
                                <dd>{{ $tile['value'] }}</dd>
                                <dd class="attendance-today-tile-detail">{{ $tile['detail'] }}</dd>
                            </div>
                        @endforeach
                    </dl>
                @endif

                <div class="attendance-today-foot">
                    <p class="attendance-today-mode">
                        <x-icon :name="$captureNote['icon']" />
                        <span>{{ $captureNote['text'] }}</span>
                    </p>

                    @if ($manualAttendanceAllowed && ! $todayRecord?->check_out_at)
                        <form
                            method="POST"
                            action="{{ $todayRecord?->check_in_at ? route('attendance.check-out') : route('attendance.check-in') }}"
                            class="attendance-today-form"
                            data-attendance-action="{{ $todayRecord?->check_in_at ? 'check-out' : 'check-in' }}"
                        >
                            @csrf
                            <input type="hidden" name="office_location_id" value="{{ $office->id }}">

                            <label class="attendance-today-note">
                                <span>{{ $manualAttendanceReasonRequired ? 'Reason for manual attendance' : 'Note for HR (optional)' }}</span>
                                <input
                                    type="text"
                                    name="notes"
                                    maxlength="500"
                                    value="{{ old('notes') }}"
                                    placeholder="{{ $manualAttendanceReasonRequired ? 'Why the biometric terminal can’t be used' : 'Add a note' }}"
                                    @required($manualAttendanceReasonRequired)
                                >
                            </label>

                            <button class="btn btn-primary attendance-today-action" type="submit">
                                <x-icon :name="$todayRecord?->check_in_at ? 'log-out' : 'log-in'" />
                                <span>{{ $todayRecord?->check_in_at ? 'Check out now' : 'Check in now' }}</span>
                            </button>
                        </form>
                    @endif
                </div>
            </section>

            @if ($attendance['missing_time_out'] !== [])
                @php($missingCount = count($attendance['missing_time_out']))
                <div class="attendance-exception" role="status">
                    <x-icon name="alert" />
                    <p>
                        <strong>{{ $missingCount }} {{ str('record')->plural($missingCount) }} {{ $missingCount === 1 ? 'needs' : 'need' }} attention.</strong>
                        {{ collect($attendance['missing_time_out'])->join(', ', ' and ') }}
                        {{ $missingCount === 1 ? 'has' : 'have' }} a time-in but no time-out, so those hours aren’t counted yet.
                        Ask HR or your department head to add the missing time-out.
                    </p>
                    <a class="btn btn-outline-primary dashboard-action" href="{{ route('timesheets.index') }}">Open timesheets</a>
                </div>
            @endif

            <section class="panel attendance-history-panel" aria-labelledby="attendanceHistoryTitle">
                <div class="panel-header">
                    <h2 id="attendanceHistoryTitle">Recent attendance</h2>
                    <span class="history-caption">Last 7 days</span>
                </div>

                <div class="table-responsive">
                    <table class="dashboard-table attendance-table table-stack">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Location</th>
                                <th class="is-num">Time in</th>
                                <th class="is-num">Time out</th>
                                <th class="is-num">Worked</th>
                                <th class="is-num">Late</th>
                                <th class="is-num">Overtime</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($attendance['days'] as $day)
                                <tr @class(['is-flagged' => $day['flagged']])>
                                    <td data-label="Date">
                                        <div>
                                            <strong>{{ $day['date_label'] }}</strong>
                                            @if ($day['is_today'])<small class="attendance-today-marker">Today</small>@endif
                                        </div>
                                    </td>
                                    <td data-label="Location">{{ $day['location'] ?? '—' }}</td>
                                    @foreach (['in' => 'Time in', 'out' => 'Time out'] as $punchKey => $punchLabel)
                                        <td data-label="{{ $punchLabel }}" class="is-num">
                                            @if ($day[$punchKey])
                                                <div class="attendance-time">
                                                    <span>{{ $day[$punchKey]['time'] }}</span>
                                                    <small>{{ $day[$punchKey]['source'] }}</small>
                                                </div>
                                            @else
                                                —
                                            @endif
                                        </td>
                                    @endforeach
                                    <td data-label="Worked" class="is-num">{{ $day['worked'] ?? '—' }}</td>
                                    <td data-label="Late" class="is-num">{{ $day['late'] ?? '—' }}</td>
                                    <td data-label="Overtime" class="is-num">{{ $day['overtime'] ?? '—' }}</td>
                                    <td data-label="Status">
                                        <span class="attendance-badges">
                                            @foreach ($day['badges'] as [$badgeTone, $badgeLabel])
                                                <span class="status-badge status-{{ $badgeTone }}"><span class="status-dot"></span>{{ $badgeLabel }}</span>
                                            @endforeach
                                        </span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="attendance-history-footer">
                    <a class="panel-link" href="{{ route('timesheets.index') }}">View full timesheet <x-icon name="chevron-right" /></a>
                </div>
            </section>
        @endif
    </div>
@endsection
