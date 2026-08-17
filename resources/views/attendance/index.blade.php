@extends('layouts.app')

@section('title', 'Time & Attendance')

@section('content')
    <div
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
                <p class="eyebrow">Workforce Management</p>
                <h1>Time & Attendance</h1>
                <p>Record your daily attendance and keep track of your work hours.</p>
            </div>
            @if (auth()->user()->roles->pluck('slug')->intersect(['system-administrator', 'hr-manager', 'department-head'])->isNotEmpty())
                <a class="btn btn-outline-primary dashboard-action" href="{{ route('attendance.reports.index') }}">
                    <x-icon name="report" /> View reports
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

        <div class="attendance-alert {{ $manualAttendanceAllowed ? 'attendance-alert-warning' : 'attendance-alert-success' }}" role="status">
            <x-icon :name="$manualAttendanceAllowed ? 'settings' : 'shield'" />
            <span>
                Attendance mode: <strong>{{ str($attendanceCaptureMode)->replace('_', ' ')->title() }}</strong>.
                @if (! $manualAttendanceAllowed)
                    Website check-in/out is disabled; use the biometric terminal.
                @elseif ($manualAttendanceReasonRequired)
                    Manual attendance is temporarily enabled and a reason is required.
                    @if($manualAttendanceExpiresAt) This mode expires {{ $manualAttendanceExpiresAt->timezone($office->timezone)->format('M j, Y g:i A') }}.@endif
                @else
                    Website and biometric attendance are both available.
                @endif
            </span>
        </div>

        <div class="attendance-layout">
            <section class="panel attendance-clock-panel">
                <div class="attendance-clock-hero">
                    <p>{{ now()->timezone($office->timezone)->format('l, F j, Y') }}</p>
                    <time id="liveAttendanceClock" datetime="{{ now()->toIso8601String() }}">
                        {{ now()->timezone($office->timezone)->format('g:i:s A') }}
                    </time>
                    <span>{{ $office->timezone }}</span>
                </div>

                <div class="attendance-state">
                    @if (! $todayRecord?->check_in_at)
                        <span class="attendance-state-icon state-ready"><x-icon name="log-in" /></span>
                        <div>
                            <p>Ready to start your day?</p>
                            <h2>Check in to {{ $office->name }}</h2>
                            <span>Your attendance will be timestamped when you check in.</span>
                        </div>
                    @elseif (! $todayRecord->check_out_at)
                        <span class="attendance-state-icon state-working"><x-icon name="clock" /></span>
                        <div>
                            <p>Currently checked in</p>
                            <h2>Working since {{ $todayRecord->check_in_at->timezone($office->timezone)->format('g:i A') }}</h2>
                            <span>Check out when your workday is complete.</span>
                        </div>
                    @else
                        <span class="attendance-state-icon state-complete"><x-icon name="check-circle" /></span>
                        <div>
                            <p>Attendance complete</p>
                            <h2>{{ $todayRecord->worked_hours }} recorded today</h2>
                            <span>Checked out at {{ $todayRecord->check_out_at->timezone($office->timezone)->format('g:i A') }}.</span>
                        </div>
                    @endif
                </div>

                @if (! $todayRecord?->check_out_at && $manualAttendanceAllowed)
                    <form
                        method="POST"
                        action="{{ $todayRecord?->check_in_at ? route('attendance.check-out') : route('attendance.check-in') }}"
                        class="attendance-form"
                        data-attendance-action="{{ $todayRecord?->check_in_at ? 'check-out' : 'check-in' }}"
                    >
                        @csrf
                        <input type="hidden" name="office_location_id" value="{{ $office->id }}">

                        <label class="attendance-note">
                            <span>{{ $manualAttendanceReasonRequired ? 'Reason for manual attendance' : 'Optional note' }}</span>
                            <textarea name="notes" rows="2" maxlength="500" placeholder="{{ $manualAttendanceReasonRequired ? 'Explain why the biometric terminal cannot be used...' : 'Add a note for HR...' }}" @required($manualAttendanceReasonRequired)>{{ old('notes') }}</textarea>
                        </label>

                        <button class="btn attendance-submit {{ $todayRecord?->check_in_at ? 'attendance-checkout' : 'attendance-checkin' }}" type="submit">
                            <x-icon :name="$todayRecord?->check_in_at ? 'log-out' : 'log-in'" />
                            <span>{{ $todayRecord?->check_in_at ? 'Check out now' : 'Check in now' }}</span>
                        </button>
                    </form>
                @elseif (! $todayRecord?->check_out_at)
                    <div class="attendance-policy">
                        <div><span>Manual attendance</span><strong>Disabled by System Administrator</strong></div>
                    </div>
                @endif

                <div class="attendance-policy">
                    <div><span>Work hours</span><strong>{{ \Carbon\Carbon::parse($office->work_start_time)->format('g:i A') }}–{{ \Carbon\Carbon::parse($office->work_end_time)->format('g:i A') }}</strong></div>
                    <div><span>Grace period</span><strong>{{ $office->grace_period_minutes }} minutes</strong></div>
                </div>
            </section>
        </div>

        @if ($canScanQr)
            <section class="panel attendance-scanner-panel" data-qr-scanner data-scan-url="{{ route('attendance.qr-scan.store') }}">
                <div class="panel-header">
                    <div>
                        <p class="panel-kicker">Entrance scanner</p>
                        <h2>Scan employee QR</h2>
                    </div>
                    <span class="history-caption">Records for the badge holder, not for you</span>
                </div>

                <div class="attendance-scanner-body">
                    <div class="attendance-scanner-viewport">
                        <video data-scanner-video playsinline muted></video>
                        <div class="attendance-scanner-reticle" aria-hidden="true"></div>
                    </div>

                    <div class="attendance-scanner-side">
                        <p class="attendance-scanner-status" data-scanner-status><x-icon name="fingerprint" /><span>Camera is off.</span></p>

                        <div class="attendance-scanner-result" data-scanner-result hidden>
                            <span class="attendance-scanner-avatar" data-result-initials>—</span>
                            <div>
                                <strong data-result-name>—</strong>
                                <small data-result-meta>—</small>
                                <p><span data-result-action>—</span> <span data-result-time></span></p>
                            </div>
                        </div>

                        <div class="attendance-scanner-actions">
                            <button class="btn btn-primary" type="button" data-scanner-start><x-icon name="log-in" /> Open camera</button>
                            <button class="btn btn-light" type="button" data-scanner-stop hidden><x-icon name="close" /> Stop camera</button>
                        </div>

                        <p class="attendance-scanner-hint">The first scan of the day records a time in and the next records a time out. Every scan is saved against the employee on the badge, with your name on the record as the scanning officer.</p>
                    </div>
                </div>
            </section>
        @endif

        <section class="panel attendance-history-panel">
            <div class="panel-header">
                <div>
                    <p class="panel-kicker">Personal log</p>
                    <h2>Recent attendance</h2>
                </div>
                <span class="history-caption">Latest 7 records</span>
            </div>

            <div class="table-responsive">
                <table class="dashboard-table attendance-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Location</th>
                            <th>Check in</th>
                            <th>In source</th>
                            <th>Check out</th>
                            <th>Out source</th>
                            <th>Worked</th>
                            <th>Late</th>
                            <th>Overtime</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($recentRecords as $record)
                            <tr>
                                <td><strong>{{ $record->attendance_date->format('M j, Y') }}</strong></td>
                                <td>{{ $record->officeLocation?->name ?? 'Not available' }}</td>
                                <td>{{ $record->check_in_at?->timezone($office->timezone)->format('g:i A') ?? '—' }}</td>
                                <td>
                                    <strong>{{ $record->check_in_method_label }}</strong>
                                    @if($record->checkInBiometricDevice)<small>{{ $record->checkInBiometricDevice->name }}</small>@endif
                                </td>
                                <td>{{ $record->check_out_at?->timezone($office->timezone)->format('g:i A') ?? '—' }}</td>
                                <td>
                                    @if($record->check_out_at)
                                        <strong>{{ $record->check_out_method_label }}</strong>
                                        @if($record->checkOutBiometricDevice)<small>{{ $record->checkOutBiometricDevice->name }}</small>@endif
                                    @else
                                        —
                                    @endif
                                </td>
                                <td>{{ $record->worked_hours }}</td>
                                <td>{{ $record->late_minutes ? $record->late_minutes.' min' : '—' }}</td>
                                <td>{{ $record->overtime_minutes ? $record->overtime_minutes.' min' : '—' }}</td>
                                <td><x-status-badge :status="$record->status" /></td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="10" class="empty-table-cell">
                                    <x-icon name="clock" />
                                    <strong>No attendance records yet</strong>
                                    <span>Your first check-in will appear here.</span>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
@endsection
