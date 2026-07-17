@extends('layouts.app')

@section('title', 'Shift & Schedule Management')

@section('content')
    @php
        $calendarTitle = $calendarView === 'week'
            ? $rangeStart->format('M j').' – '.$rangeEnd->format('M j, Y')
            : $focusDate->format('F Y');
        $queryFor = fn (array $values) => route('schedules.index', array_merge(request()->except('page'), $values));
        $weekdays = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
    @endphp

    <section class="page-heading schedule-heading">
        <div>
            <p class="eyebrow">Workforce Management</p>
            <h1>Shift & Schedule Management</h1>
            <p>Plan coverage, assign recurring shifts, and prevent employee schedule conflicts.</p>
        </div>
        @if ($canManage)
            <div class="schedule-heading-actions">
                <a class="btn btn-outline-primary dashboard-action" href="{{ route('shifts.index') }}"><x-icon name="repeat" /> Shift templates</a>
                <button class="btn btn-outline-primary dashboard-action" type="button" data-bs-toggle="modal" data-bs-target="#recurringScheduleModal"><x-icon name="repeat" /> Recurring schedule</button>
                <button class="btn btn-primary dashboard-action" type="button" data-bs-toggle="modal" data-bs-target="#scheduleAssignmentModal"><x-icon name="plus" /> Assign shift</button>
            </div>
        @endif
    </section>

    @if (session('success'))
        <div class="attendance-alert attendance-alert-success" role="status"><x-icon name="check-circle" /><span>{{ session('success') }}</span></div>
    @endif

    @if ($errors->any())
        <div class="attendance-alert attendance-alert-danger" role="alert"><x-icon name="close" /><span>{{ $errors->first() }}</span></div>
    @endif

    <section class="schedule-stats-grid">
        <article class="report-stat"><span class="report-stat-icon report-stat-blue"><x-icon name="calendar" /></span><div><span>Assignments shown</span><strong>{{ number_format($stats['assignments']) }}</strong></div></article>
        <article class="report-stat"><span class="report-stat-icon report-stat-green"><x-icon name="users" /></span><div><span>Employees scheduled</span><strong>{{ number_format($stats['employees']) }}</strong></div></article>
        <article class="report-stat"><span class="report-stat-icon report-stat-violet"><x-icon name="clock" /></span><div><span>Scheduled hours</span><strong>{{ number_format($stats['hours'], 1) }}</strong></div></article>
        <article class="report-stat"><span class="report-stat-icon report-stat-amber"><x-icon name="repeat" /></span><div><span>Overnight assignments</span><strong>{{ number_format($stats['overnight']) }}</strong></div></article>
    </section>

    <section class="panel schedule-calendar-panel">
        <div class="schedule-toolbar">
            <div class="calendar-navigation">
                <a class="calendar-nav-button" href="{{ $queryFor(['date' => $previousDate->toDateString()]) }}" aria-label="Previous period"><x-icon name="chevron-right" class="flip-horizontal" /></a>
                <a class="calendar-today-button" href="{{ $queryFor(['date' => now(config('schedule.timezone'))->toDateString()]) }}">Today</a>
                <a class="calendar-nav-button" href="{{ $queryFor(['date' => $nextDate->toDateString()]) }}" aria-label="Next period"><x-icon name="chevron-right" /></a>
                <h2>{{ $calendarTitle }}</h2>
            </div>

            <div class="schedule-toolbar-right">
                @if ($canManage)
                    <form method="GET" action="{{ route('schedules.index') }}" class="schedule-inline-filters">
                        <input type="hidden" name="date" value="{{ $focusDate->toDateString() }}">
                        <input type="hidden" name="view" value="{{ $calendarView }}">
                        <select name="department_id" aria-label="Filter by department" onchange="this.form.submit()">
                            <option value="">All departments</option>
                            @foreach ($departments as $department)
                                <option value="{{ $department->id }}" @selected(($filters['department_id'] ?? '') == $department->id)>{{ $department->name }}</option>
                            @endforeach
                        </select>
                        <select name="employee_id" aria-label="Filter by employee" onchange="this.form.submit()">
                            <option value="">All employees</option>
                            @foreach ($employees as $employee)
                                <option value="{{ $employee->id }}" @selected(($filters['employee_id'] ?? '') == $employee->id)>{{ $employee->employee_number }} · {{ $employee->full_name }}</option>
                            @endforeach
                        </select>
                    </form>
                @endif

                <div class="calendar-view-switch" aria-label="Calendar view">
                    @foreach (['month' => 'Month', 'week' => 'Week', 'list' => 'List'] as $viewKey => $viewLabel)
                        <a href="{{ $queryFor(['view' => $viewKey]) }}" @class(['active' => $calendarView === $viewKey])>{{ $viewLabel }}</a>
                    @endforeach
                </div>
            </div>
        </div>

        @if ($calendarView === 'month')
            <div class="month-calendar">
                <div class="month-weekdays">
                    @foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $dayName)
                        <span>{{ $dayName }}</span>
                    @endforeach
                </div>
                <div class="month-days">
                    @foreach ($calendarDays as $day)
                        <article @class(['calendar-day', 'outside-month' => ! $day['is_current_month'], 'is-today' => $day['is_today']])>
                            <div class="calendar-day-header">
                                <span>{{ $day['date']->day }}</span>
                                @if ($day['is_today'])<small>Today</small>@endif
                            </div>
                            <div class="calendar-day-events">
                                @foreach ($day['assignments']->take(3) as $assignment)
                                    @php
                                        $eventPayload = [
                                            'id' => $assignment->id,
                                            'employee_id' => $assignment->employee_id,
                                            'employee' => $assignment->employee->full_name,
                                            'employee_number' => $assignment->employee->employee_number,
                                            'shift_id' => $assignment->shift_id,
                                            'shift' => $assignment->shift->name,
                                            'time' => $assignment->shift->formatted_time,
                                            'date' => $assignment->work_date->toDateString(),
                                            'department' => $assignment->employee->department?->name,
                                            'notes' => $assignment->notes,
                                            'recurring' => $assignment->recurring_schedule_id !== null,
                                        ];
                                    @endphp
                                    <button
                                        class="schedule-event"
                                        type="button"
                                        style="--event-color: {{ $assignment->shift->color }}"
                                        data-schedule-event='{{ json_encode($eventPayload, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_TAG) }}'
                                    >
                                        <span class="event-color"></span>
                                        <span class="event-copy"><strong>{{ $assignment->shift->name }}</strong><small>{{ $assignment->employee->first_name }} {{ $assignment->employee->last_name }}</small></span>
                                        @if ($assignment->recurring_schedule_id)<x-icon name="repeat" />@endif
                                    </button>
                                @endforeach
                                @if ($day['assignments']->count() > 3)
                                    <span class="more-events">+{{ $day['assignments']->count() - 3 }} more</span>
                                @endif
                                @foreach($day['leaves']->take(2) as $leave)
                                    <span class="schedule-leave-event" style="--leave-color: {{ $leave->leaveType->color }}"><x-icon name="leave" /><span><strong>{{ $leave->employee->first_name }} {{ $leave->employee->last_name }}</strong><small>{{ $leave->leaveType->name }}</small></span></span>
                                @endforeach
                            </div>
                            @if ($canManage)
                                <button class="quick-add-schedule" type="button" data-quick-schedule-date="{{ $day['date']->toDateString() }}" aria-label="Add schedule on {{ $day['date']->format('F j') }}"><x-icon name="plus" /></button>
                            @endif
                        </article>
                    @endforeach
                </div>
            </div>
        @elseif ($calendarView === 'week')
            <div class="week-calendar">
                @foreach ($calendarDays as $day)
                    <article @class(['week-day-column', 'is-today' => $day['is_today']])>
                        <header><span>{{ $day['date']->format('D') }}</span><strong>{{ $day['date']->day }}</strong></header>
                        <div class="week-day-events">
                            @forelse ($day['assignments'] as $assignment)
                                @php
                                    $eventPayload = ['id' => $assignment->id, 'employee_id' => $assignment->employee_id, 'employee' => $assignment->employee->full_name, 'employee_number' => $assignment->employee->employee_number, 'shift_id' => $assignment->shift_id, 'shift' => $assignment->shift->name, 'time' => $assignment->shift->formatted_time, 'date' => $assignment->work_date->toDateString(), 'department' => $assignment->employee->department?->name, 'notes' => $assignment->notes, 'recurring' => $assignment->recurring_schedule_id !== null];
                                @endphp
                                <button class="week-schedule-event" type="button" style="--event-color: {{ $assignment->shift->color }}" data-schedule-event='{{ json_encode($eventPayload, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_TAG) }}'>
                                    <span class="week-event-time">{{ \Carbon\Carbon::parse($assignment->shift->start_time)->format('g:i A') }}</span>
                                    <strong>{{ $assignment->shift->name }}</strong>
                                    <small>{{ $assignment->employee->full_name }}</small>
                                </button>
                            @empty
                                @if($day['leaves']->isEmpty())<span class="week-empty">No shifts</span>@endif
                            @endforelse
                            @foreach($day['leaves'] as $leave)<span class="schedule-leave-event week-leave-event" style="--leave-color: {{ $leave->leaveType->color }}"><x-icon name="leave" /><span><strong>{{ $leave->employee->full_name }}</strong><small>{{ $leave->leaveType->name }}</small></span></span>@endforeach
                        </div>
                    </article>
                @endforeach
            </div>
        @else
            <div class="schedule-list-view">
                @forelse ($assignmentsByDate as $date => $dateAssignments)
                    <section class="schedule-list-day">
                        <div class="schedule-list-date"><span>{{ \Carbon\Carbon::parse($date)->format('D') }}</span><strong>{{ \Carbon\Carbon::parse($date)->day }}</strong><small>{{ \Carbon\Carbon::parse($date)->format('M Y') }}</small></div>
                        <div class="schedule-list-items">
                            @foreach ($dateAssignments as $assignment)
                                @php
                                    $eventPayload = ['id' => $assignment->id, 'employee_id' => $assignment->employee_id, 'employee' => $assignment->employee->full_name, 'employee_number' => $assignment->employee->employee_number, 'shift_id' => $assignment->shift_id, 'shift' => $assignment->shift->name, 'time' => $assignment->shift->formatted_time, 'date' => $assignment->work_date->toDateString(), 'department' => $assignment->employee->department?->name, 'notes' => $assignment->notes, 'recurring' => $assignment->recurring_schedule_id !== null];
                                @endphp
                                <button class="schedule-list-item" type="button" data-schedule-event='{{ json_encode($eventPayload, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_TAG) }}'>
                                    <span class="event-color" style="background: {{ $assignment->shift->color }}"></span>
                                    <span class="schedule-list-time">{{ $assignment->shift->formatted_time }}</span>
                                    <span class="schedule-list-employee"><strong>{{ $assignment->employee->full_name }}</strong><small>{{ $assignment->employee->employee_number }} · {{ $assignment->employee->department?->name }}</small></span>
                                    <span class="schedule-list-shift">{{ $assignment->shift->name }} @if($assignment->recurring_schedule_id)<x-icon name="repeat" />@endif</span>
                                    <x-icon name="chevron-right" />
                                </button>
                            @endforeach
                        </div>
                    </section>
                @empty
                    <div class="calendar-empty-state"><x-icon name="calendar" /><strong>No schedules in this period</strong><span>Assignments will appear here after shifts are scheduled.</span></div>
                @endforelse
            </div>
        @endif
    </section>

    @if ($canManage)
        <section class="panel recurring-series-panel">
            <div class="panel-header"><div><p class="panel-kicker">Recurring coverage</p><h2>Active schedule series</h2></div><span class="history-caption">{{ $activeSeries->count() }} active</span></div>
            <div class="recurring-series-grid">
                @forelse ($activeSeries as $series)
                    <article class="recurring-series-card">
                        <span class="series-color" style="background: {{ $series->shift->color }}"></span>
                        <div class="series-main"><strong>{{ $series->employee->full_name }}</strong><span>{{ $series->shift->name }} · {{ $series->shift->formatted_time }}</span><small>{{ $series->start_date->format('M j') }}–{{ $series->end_date->format('M j, Y') }} · {{ str($series->recurrence_type)->headline() }}</small></div>
                        <form method="POST" action="{{ route('recurring-schedules.destroy', $series) }}" onsubmit="return confirm('Cancel this recurring series and remove its future assignments?')">@csrf @method('DELETE')<button class="icon-button subtle text-danger" type="submit" aria-label="Cancel recurring series"><x-icon name="trash" /></button></form>
                    </article>
                @empty
                    <div class="compact-empty-state"><x-icon name="repeat" /><p>No active recurring schedule series.</p></div>
                @endforelse
            </div>
        </section>
    @endif

    @if ($canManage)
        <div class="modal fade" id="scheduleAssignmentModal" tabindex="-1" aria-labelledby="scheduleAssignmentModalLabel" aria-hidden="true">
            <div @class(['modal-dialog modal-dialog-centered', 'schedule-assignment-dialog' => $aiSchedulingEnabled])><div class="modal-content schedule-modal-content">
                <form method="POST" action="{{ route('schedules.store') }}" id="scheduleAssignmentForm" data-store-url="{{ route('schedules.store') }}" data-update-url-template="{{ route('schedules.update', ['scheduleAssignment' => '__ID__']) }}" data-conflict-url="{{ route('schedules.conflicts') }}">
                    @csrf
                    <input type="hidden" name="_method" value="POST" data-method-field>
                    <div class="modal-header"><div><p class="panel-kicker">Schedule assignment</p><h2 class="modal-title" id="scheduleAssignmentModalLabel">Assign a shift</h2></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
                    <div @class(['modal-body', 'schedule-assignment-workspace' => $aiSchedulingEnabled, 'schedule-form-grid' => ! $aiSchedulingEnabled])>
                        @if ($aiSchedulingEnabled)
                            <section class="schedule-assignment-manual" aria-labelledby="manualAssignmentTitle">
                                <div class="schedule-assignment-section-heading">
                                    <span><x-icon name="calendar" /></span>
                                    <div><p>Manual assignment</p><h3 id="manualAssignmentTitle">Assignment details</h3></div>
                                </div>
                                <p class="schedule-assignment-section-copy">Set the actual shift details here. AI can recommend an employee, but these fields and the final save remain under HR control.</p>
                                <div class="schedule-form-grid schedule-assignment-fields">
                        @endif
                                    <label class="full-width"><span>Employee</span><select name="employee_id" required><option value="">Select employee</option>@foreach($employees as $employee)<option value="{{ $employee->id }}">{{ $employee->employee_number }} · {{ $employee->full_name }} ({{ $employee->department?->code }})</option>@endforeach</select></label>
                                    <label><span>Shift</span><select name="shift_id" required><option value="">Select shift</option>@foreach($shifts as $shift)<option value="{{ $shift->id }}">{{ $shift->name }} · {{ $shift->formatted_time }}</option>@endforeach</select></label>
                                    <label><span>Work date</span><input type="date" name="work_date" value="{{ $focusDate->toDateString() }}" required></label>
                                    <label class="full-width"><span>Notes</span><textarea name="notes" rows="3" maxlength="500" placeholder="Optional assignment note"></textarea></label>
                                    <div class="schedule-conflict-status full-width" data-conflict-status><x-icon name="check-circle" /><span>Select an employee, shift, and date to check availability.</span></div>
                        @if ($aiSchedulingEnabled)
                                </div>
                            </section>
                            @include('schedules.partials.ai-recommendation')
                        @endif
                    </div>
                    <div class="modal-footer">
                        @if ($aiSchedulingEnabled)<p class="schedule-save-note"><x-icon name="shield" /> AI suggestions never save automatically.</p>@endif
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary">Save assignment</button>
                    </div>
                </form>
            </div></div>
        </div>

        <div class="modal fade" id="recurringScheduleModal" tabindex="-1" aria-labelledby="recurringScheduleModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-lg"><div class="modal-content schedule-modal-content">
                <form method="POST" action="{{ route('recurring-schedules.store') }}" id="recurringScheduleForm">@csrf
                    <div class="modal-header"><div><p class="panel-kicker">Recurring coverage</p><h2 class="modal-title" id="recurringScheduleModalLabel">Create recurring schedule</h2></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
                    <div class="modal-body schedule-form-grid recurring-form-grid">
                        <label><span>Employee</span><select name="employee_id" required><option value="">Select employee</option>@foreach($employees as $employee)<option value="{{ $employee->id }}">{{ $employee->employee_number }} · {{ $employee->full_name }}</option>@endforeach</select></label>
                        <label><span>Shift</span><select name="shift_id" required><option value="">Select shift</option>@foreach($shifts as $shift)<option value="{{ $shift->id }}">{{ $shift->name }} · {{ $shift->formatted_time }}</option>@endforeach</select></label>
                        <label><span>Starts</span><input type="date" name="start_date" value="{{ $focusDate->toDateString() }}" required></label>
                        <label><span>Ends</span><input type="date" name="end_date" value="{{ $focusDate->copy()->addMonth()->toDateString() }}" required></label>
                        <label><span>Repeats</span><select name="recurrence_type" data-recurrence-type><option value="weekly">Weekly</option><option value="daily">Every day</option></select></label>
                        <label><span>Week interval</span><select name="interval_weeks"><option value="1">Every week</option><option value="2">Every 2 weeks</option><option value="3">Every 3 weeks</option><option value="4">Every 4 weeks</option></select></label>
                        <fieldset class="weekday-selector full-width" data-weekday-selector><legend>Repeat on</legend><div>@foreach($weekdays as $dayNumber => $dayName)<label><input type="checkbox" name="weekdays[]" value="{{ $dayNumber }}" @checked($dayNumber <= 5)><span>{{ substr($dayName, 0, 3) }}</span></label>@endforeach</div></fieldset>
                        <label class="full-width"><span>Notes</span><textarea name="notes" rows="2" maxlength="500" placeholder="Optional series note"></textarea></label>
                        <div class="recurrence-summary full-width"><x-icon name="repeat" /><span data-recurrence-summary>Repeats weekly on weekdays.</span></div>
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary">Create series</button></div>
                </form>
            </div></div>
        </div>

        <div class="modal fade" id="scheduleDetailModal" tabindex="-1" aria-labelledby="scheduleDetailModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered"><div class="modal-content schedule-modal-content">
                <div class="modal-header"><div><p class="panel-kicker">Assignment details</p><h2 class="modal-title" id="scheduleDetailModalLabel" data-detail-shift>Scheduled shift</h2></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
                <div class="modal-body schedule-detail-grid"><div><span>Employee</span><strong data-detail-employee>—</strong></div><div><span>Employee ID</span><strong data-detail-employee-number>—</strong></div><div><span>Date</span><strong data-detail-date>—</strong></div><div><span>Shift time</span><strong data-detail-time>—</strong></div><div><span>Department</span><strong data-detail-department>—</strong></div><div><span>Series</span><strong data-detail-recurring>—</strong></div><div class="full-width"><span>Notes</span><strong data-detail-notes>—</strong></div></div>
                <div class="modal-footer"><form method="POST" action="#" data-delete-assignment-form onsubmit="return confirm('Remove this schedule assignment?')">@csrf @method('DELETE')<button class="btn btn-outline-danger" type="submit"><x-icon name="trash" /> Remove</button></form><button class="btn btn-outline-primary" type="button" data-edit-assignment><x-icon name="edit" /> Edit</button><button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button></div>
            </div></div>
        </div>
    @endif
@endsection
