@extends('layouts.app')

@section('title', 'Time & Attendance')

@section('content')
    <div id="attendanceApp" data-office-timezone="{{ $office->timezone }}">
        <section class="page-heading attendance-heading">
            <div>
                <p class="eyebrow">Workforce Management</p>
                <h1>Time & Attendance</h1>
                <p>Record your daily attendance and keep track of your work hours.</p>
            </div>
            @if (auth()->user()->roles()->whereIn('slug', ['system-administrator', 'hr-manager', 'department-head'])->exists())
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

                @if (! $todayRecord?->check_out_at)
                    <form
                        method="POST"
                        action="{{ $todayRecord?->check_in_at ? route('attendance.check-out') : route('attendance.check-in') }}"
                        class="attendance-form"
                        data-attendance-action="{{ $todayRecord?->check_in_at ? 'check-out' : 'check-in' }}"
                    >
                        @csrf
                        <input type="hidden" name="office_location_id" value="{{ $office->id }}">

                        <label class="attendance-note">
                            <span>Optional note</span>
                            <textarea name="notes" rows="2" maxlength="500" placeholder="Add a note for HR...">{{ old('notes') }}</textarea>
                        </label>

                        <button class="btn attendance-submit {{ $todayRecord?->check_in_at ? 'attendance-checkout' : 'attendance-checkin' }}" type="submit">
                            <x-icon :name="$todayRecord?->check_in_at ? 'log-out' : 'log-in'" />
                            <span>{{ $todayRecord?->check_in_at ? 'Check out now' : 'Check in now' }}</span>
                        </button>
                    </form>
                @endif

                <div class="attendance-policy">
                    <div><span>Work hours</span><strong>{{ \Carbon\Carbon::parse($office->work_start_time)->format('g:i A') }}–{{ \Carbon\Carbon::parse($office->work_end_time)->format('g:i A') }}</strong></div>
                    <div><span>Grace period</span><strong>{{ $office->grace_period_minutes }} minutes</strong></div>
                </div>
            </section>
        </div>

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
                            <th>Check out</th>
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
                                <td>{{ $record->check_out_at?->timezone($office->timezone)->format('g:i A') ?? '—' }}</td>
                                <td>{{ $record->worked_hours }}</td>
                                <td>{{ $record->late_minutes ? $record->late_minutes.' min' : '—' }}</td>
                                <td>{{ $record->overtime_minutes ? $record->overtime_minutes.' min' : '—' }}</td>
                                <td><x-status-badge :status="$record->status" /></td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="empty-table-cell">
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
