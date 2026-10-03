@extends('layouts.app')

@section('title', 'Shift & Schedule Management')

@section('content')
    @php
        $calendarTitle = $calendarView === 'week'
            ? $rangeStart->format('M j').' – '.$rangeEnd->format('M j, Y')
            : $focusDate->format('F Y');
        $queryFor = fn (array $values) => route('schedules.index', array_merge(request()->except('page'), $values));
        $weekdays = \App\Support\ScheduleWeek::isoWeekdayNames();
        $scheduleListDates = $assignmentsByDate->keys()->merge($dayOffsByDate->keys())->unique()->sort()->values();
        $today = now(config('schedule.timezone'))->startOfDay();
        $rosterPeriodStart = $focusDate->lt($today) ? $today : $focusDate;
        // A day that has already started is a record of work, not a plan: today and
        // every earlier day are view-only here, and only an approved shift swap can
        // still move today's shift.
        $isEditableDate = fn ($date) => \Carbon\Carbon::parse(
            $date instanceof \Carbon\CarbonInterface ? $date->toDateString() : $date,
            config('schedule.timezone'),
        )->startOfDay()->greaterThan($today);
        $firstEditableDate = $today->copy()->addDay();
    @endphp

    <section class="page-heading schedule-heading">
        <div>
            <p class="eyebrow">Workforce Management</p>
            <h1>Shift & Schedule Management</h1>
            <p>Plan coverage, assign recurring shifts, and prevent employee schedule conflicts.</p>
        </div>
        @if ($canManageData)
            {{-- Four buttons of near-equal weight gave no answer to "what do I do
                 here?". Now: one primary action, the two narrower ways to add an
                 assignment folded behind it, and shift templates demoted to the
                 link it always was - it navigates elsewhere, it does not act. --}}
            <div class="schedule-heading-actions">
                <a class="schedule-heading-link" href="{{ route('shifts.index') }}"><x-icon name="repeat" /> Shift templates</a>

                <div class="dropdown dashboard-action-menu">
                    <button class="btn btn-outline-primary dashboard-action dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <x-icon name="plus" /> New assignment
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li>
                            <button class="dropdown-item" type="button" data-bs-toggle="modal" data-bs-target="#scheduleAssignmentModal">
                                <x-icon name="plus" /> <span>Single assignment</span>
                            </button>
                        </li>
                        <li>
                            <button class="dropdown-item" type="button" data-bs-toggle="modal" data-bs-target="#recurringScheduleModal">
                                <x-icon name="repeat" /> <span>Recurring schedule</span>
                            </button>
                        </li>
                    </ul>
                </div>

                <button class="btn btn-primary dashboard-action" type="button" data-bs-toggle="modal" data-bs-target="#bulkScheduleModal"><x-icon :name="$aiSchedulingEnabled ? 'ai' : 'users'" /> {{ $aiSchedulingEnabled ? 'AI bulk schedule' : 'Department schedule' }}</button>
            </div>
        @endif
    </section>

    @if ($errors->any())
        <div class="attendance-alert attendance-alert-danger" role="alert"><x-icon name="close" /><span>{{ $errors->first() }}</span></div>
    @endif

    {{-- The phone's read of this page: one day at a time. The calendar, its
         toolbar and the stats above it are hidden beneath it on a phone, where
         a month grid and four tallies are neither readable nor what was being
         asked for. --}}
    <div class="schedule-phone-fold">
        @include('schedules._phone')
    </div>

    <section class="schedule-stats-grid schedule-desk-fold">
        <article class="report-stat"><span class="report-stat-icon report-stat-blue"><x-icon name="calendar" /></span><div><span>Assignments shown</span><strong>{{ number_format($stats['assignments']) }}</strong></div></article>
        <article class="report-stat"><span class="report-stat-icon report-stat-green"><x-icon name="users" /></span><div><span>Employees scheduled</span><strong>{{ number_format($stats['employees']) }}</strong></div></article>
        <article class="report-stat"><span class="report-stat-icon report-stat-violet"><x-icon name="clock" /></span><div><span>Scheduled hours</span><strong>{{ number_format($stats['hours'], 1) }}</strong></div></article>
        <article class="report-stat"><span class="report-stat-icon report-stat-amber"><x-icon name="repeat" /></span><div><span>Overnight assignments</span><strong>{{ number_format($stats['overnight']) }}</strong></div></article>
    </section>

    <section class="panel schedule-calendar-panel schedule-desk-fold">
        <div class="schedule-toolbar">
            <div class="calendar-navigation">
                <a class="calendar-nav-button" href="{{ $queryFor(['date' => $previousDate->toDateString()]) }}" aria-label="Previous period"><x-icon name="chevron-right" class="flip-horizontal" /></a>
                <a class="calendar-today-button" href="{{ $queryFor(['date' => now(config('schedule.timezone'))->toDateString()]) }}">Today</a>
                <a class="calendar-nav-button" href="{{ $queryFor(['date' => $nextDate->toDateString()]) }}" aria-label="Next period"><x-icon name="chevron-right" /></a>
                <h2>{{ $calendarTitle }}</h2>
            </div>

            <div class="schedule-toolbar-right">
                @if ($canManage)
                    {{-- These selects used to submit on change. A keyboard user
                         arrowing through departments fired a page load per
                         keystroke, and every submission threw away the scroll
                         position with no indication anything was happening.
                         Filtering is now an explicit, single action. --}}
                    <form method="GET" action="{{ route('schedules.index') }}" class="schedule-inline-filters">
                        <input type="hidden" name="date" value="{{ $focusDate->toDateString() }}">
                        <input type="hidden" name="view" value="{{ $calendarView }}">
                        <select name="department_id" aria-label="Filter by department">
                            <option value="">All departments</option>
                            @foreach ($departments as $department)
                                <option value="{{ $department->id }}" @selected(($filters['department_id'] ?? '') == $department->id)>{{ $department->name }}</option>
                            @endforeach
                        </select>
                        <select name="employee_id" aria-label="Filter by employee">
                            <option value="">All employees</option>
                            @foreach ($employees as $employee)
                                <option value="{{ $employee->id }}" @selected(($filters['employee_id'] ?? '') == $employee->id)>{{ $employee->employee_number }} · {{ $employee->full_name }}</option>
                            @endforeach
                        </select>
                        <button class="btn btn-outline-primary schedule-filter-apply" type="submit">Apply</button>
                        @if (($filters['department_id'] ?? '') !== '' || ($filters['employee_id'] ?? '') !== '')
                            <a class="schedule-filter-clear" href="{{ $queryFor(['department_id' => null, 'employee_id' => null]) }}">Clear</a>
                        @endif
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
                    @foreach (\App\Support\ScheduleWeek::shortNames() as $dayName)
                        <span>{{ $dayName }}</span>
                    @endforeach
                </div>
                <div class="month-days">
                    @foreach ($calendarDays as $day)
                        <article @class(['calendar-day', 'outside-month' => ! $day['is_current_month'], 'is-today' => $day['is_today'], 'is-locked-day' => ! $isEditableDate($day['date'])])>
                            <div class="calendar-day-header">
                                <span>{{ $day['date']->day }}</span>
                                @if ($day['is_today'])<small>Today</small>@endif
                                @if ($canManageData && ! $isEditableDate($day['date']))
                                    <span class="calendar-day-lock" title="{{ $day['is_today'] ? 'Today’s schedule is view only — an approved shift swap is the only way to change it.' : 'This date has passed. Schedules here are view only.' }}"><x-icon name="lock" /></span>
                                @endif
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
                                            'editable' => $canManageData && $isEditableDate($assignment->work_date),
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
                                    {{-- A link, not a label. The month cell shows three shifts and a count,
                                         and on a busy day that leaves most of the roster behind it: ten
                                         assigned means seven invisible. Saying "+7 more" without anywhere to
                                         go reads as a promise the page does not keep. The week view has no
                                         cap, so it is where the rest of that day actually lives, and
                                         $queryFor carries the department and employee filters across. --}}
                                    <a
                                        class="more-events"
                                        href="{{ $queryFor(['view' => 'week', 'date' => $day['date']->toDateString()]) }}"
                                        aria-label="Show all {{ $day['assignments']->count() }} shifts on {{ $day['date']->format('F j') }}"
                                    >+{{ $day['assignments']->count() - 3 }}{{-- Clipped out of sight on a phone, where the cell is 47px wide and the count is the whole message. --}}<span> more</span></a>
                                @endif
                                @foreach($day['day_offs']->take(2) as $dayOff)
                                    <span class="schedule-day-off-event"><x-icon name="calendar" /><span><strong>{{ $dayOff->employee->full_name }}</strong><small>Day off</small></span>@if($canManageData && $isEditableDate($dayOff->work_date))<form method="POST" action="{{ route('schedule-day-offs.destroy', $dayOff) }}" data-confirm="Remove {{ $dayOff->employee->full_name }}’s day off on {{ $dayOff->work_date->format('M j') }}? They can be scheduled that day again." data-confirm-button="Remove day off" data-confirm-tone="caution">@csrf @method('DELETE')<button type="submit" aria-label="Remove day off"><x-icon name="close" /></button></form>@endif</span>
                                @endforeach
                                @foreach($day['leaves']->take(2) as $leave)
                                    <span class="schedule-leave-event" style="--leave-color: {{ $leave->leaveType->color }}"><x-icon name="leave" /><span><strong>{{ $leave->employee->first_name }} {{ $leave->employee->last_name }}</strong><small>{{ $leave->leaveType->name }}</small></span></span>
                                @endforeach
                            </div>
                            @if ($canManageData && $isEditableDate($day['date']))
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
                                    $eventPayload = ['id' => $assignment->id, 'employee_id' => $assignment->employee_id, 'employee' => $assignment->employee->full_name, 'employee_number' => $assignment->employee->employee_number, 'shift_id' => $assignment->shift_id, 'shift' => $assignment->shift->name, 'time' => $assignment->shift->formatted_time, 'date' => $assignment->work_date->toDateString(), 'department' => $assignment->employee->department?->name, 'notes' => $assignment->notes, 'recurring' => $assignment->recurring_schedule_id !== null, 'editable' => $canManageData && $isEditableDate($assignment->work_date)];
                                @endphp
                                <button class="week-schedule-event" type="button" style="--event-color: {{ $assignment->shift->color }}" data-schedule-event='{{ json_encode($eventPayload, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_TAG) }}'>
                                    <span class="week-event-time">{{ \Carbon\Carbon::parse($assignment->shift->start_time)->format('g:i A') }}</span>
                                    <strong>{{ $assignment->shift->name }}</strong>
                                    <small>{{ $assignment->employee->full_name }}</small>
                                </button>
                            @empty
                                @if($day['leaves']->isEmpty() && $day['day_offs']->isEmpty())<span class="week-empty">No shifts</span>@endif
                            @endforelse
                            @foreach($day['day_offs'] as $dayOff)<span class="schedule-day-off-event week-day-off-event"><x-icon name="calendar" /><span><strong>{{ $dayOff->employee->full_name }}</strong><small>Day off</small></span>@if($canManageData && $isEditableDate($dayOff->work_date))<form method="POST" action="{{ route('schedule-day-offs.destroy', $dayOff) }}" data-confirm="Remove {{ $dayOff->employee->full_name }}’s day off on {{ $dayOff->work_date->format('M j') }}? They can be scheduled that day again." data-confirm-button="Remove day off" data-confirm-tone="caution">@csrf @method('DELETE')<button type="submit" aria-label="Remove day off"><x-icon name="close" /></button></form>@endif</span>@endforeach
                            @foreach($day['leaves'] as $leave)<span class="schedule-leave-event week-leave-event" style="--leave-color: {{ $leave->leaveType->color }}"><x-icon name="leave" /><span><strong>{{ $leave->employee->full_name }}</strong><small>{{ $leave->leaveType->name }}</small></span></span>@endforeach
                        </div>
                    </article>
                @endforeach
            </div>
        @else
            <div class="schedule-list-view">
                @forelse ($scheduleListDates as $date)
                    @php
                        $dateAssignments = $assignmentsByDate->get($date, collect());
                    @endphp
                    <section class="schedule-list-day">
                        <div class="schedule-list-date"><span>{{ \Carbon\Carbon::parse($date)->format('D') }}</span><strong>{{ \Carbon\Carbon::parse($date)->day }}</strong><small>{{ \Carbon\Carbon::parse($date)->format('M Y') }}</small></div>
                        <div class="schedule-list-items">
                            @foreach ($dateAssignments as $assignment)
                                @php
                                    $eventPayload = ['id' => $assignment->id, 'employee_id' => $assignment->employee_id, 'employee' => $assignment->employee->full_name, 'employee_number' => $assignment->employee->employee_number, 'shift_id' => $assignment->shift_id, 'shift' => $assignment->shift->name, 'time' => $assignment->shift->formatted_time, 'date' => $assignment->work_date->toDateString(), 'department' => $assignment->employee->department?->name, 'notes' => $assignment->notes, 'recurring' => $assignment->recurring_schedule_id !== null, 'editable' => $canManageData && $isEditableDate($assignment->work_date)];
                                @endphp
                                <button class="schedule-list-item" type="button" data-schedule-event='{{ json_encode($eventPayload, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_TAG) }}'>
                                    <span class="event-color" style="background: {{ $assignment->shift->color }}"></span>
                                    <span class="schedule-list-time">{{ $assignment->shift->formatted_time }}</span>
                                    <span class="schedule-list-employee"><strong>{{ $assignment->employee->full_name }}</strong><small>{{ $assignment->employee->employee_number }} · {{ $assignment->employee->department?->name }}</small></span>
                                    <span class="schedule-list-shift">{{ $assignment->shift->name }} @if($assignment->recurring_schedule_id)<x-icon name="repeat" />@endif</span>
                                    <x-icon name="chevron-right" />
                                </button>
                            @endforeach
                            @foreach ($dayOffsByDate->get($date, collect()) as $dayOff)
                                <div class="schedule-list-item schedule-list-day-off"><span class="event-color"></span><span class="schedule-list-time">All day</span><span class="schedule-list-employee"><strong>{{ $dayOff->employee->full_name }}</strong><small>{{ $dayOff->employee->employee_number }} · {{ $dayOff->employee->department?->name }}</small></span><span class="schedule-list-shift">Day off</span>@if($canManageData && $isEditableDate($dayOff->work_date))<form method="POST" action="{{ route('schedule-day-offs.destroy', $dayOff) }}" data-confirm="Remove {{ $dayOff->employee->full_name }}’s day off on {{ $dayOff->work_date->format('M j') }}? They can be scheduled that day again." data-confirm-button="Remove day off" data-confirm-tone="caution">@csrf @method('DELETE')<button class="icon-button subtle" type="submit" aria-label="Remove day off"><x-icon name="close" /></button></form>@endif</div>
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
                        @if($canManageData)<form method="POST" action="{{ route('recurring-schedules.destroy', $series) }}" data-confirm="Cancel {{ $series->employee->full_name }}’s {{ $series->shift->name }} series? Its future assignments are removed. Past shifts stay on record." data-confirm-button="Cancel series" data-confirm-cancel="Keep series" data-confirm-tone="danger">@csrf @method('DELETE')<button class="icon-button subtle text-danger" type="submit" aria-label="Cancel recurring series"><x-icon name="trash" /></button></form>@endif
                    </article>
                @empty
                    <div class="compact-empty-state"><x-icon name="repeat" /><p>No active recurring schedule series.</p></div>
                @endforelse
            </div>
        </section>
    @endif

    @if ($canLockSchedule)
        @php
            $latestReviewByDepartment = $recentComplianceReviews->unique('department_id')->keyBy('department_id');
        @endphp
        <section class="panel compliance-lock-panel">
            <div class="panel-header"><div><p class="panel-kicker">HR final validation</p><h2>Compliance & schedule lock</h2></div></div>
            <p class="history-caption">Run a compliance check before locking a period, so a rest, hours, or staffing violation is caught before the schedule is frozen.</p>

            <form method="POST" action="{{ route('schedule-compliance-reviews.store') }}" class="workforce-filters">
                @csrf
                <label><span>Department</span><select name="department_id" required><option value="">Select department</option>@foreach($departments as $department)<option value="{{ $department->id }}">{{ $department->name }}</option>@endforeach</select></label>
                <label><span>From</span><input type="date" name="start_date" required></label>
                <label><span>To</span><input type="date" name="end_date" required></label>
                <button class="btn btn-outline-primary" type="submit"><x-icon name="shield" /> Run compliance check</button>
            </form>

            <div class="table-responsive"><table class="dashboard-table workforce-table"><thead><tr><th>Department</th><th>Range</th><th>Status</th><th>Findings</th><th>Checked</th></tr></thead><tbody>
                @forelse($recentComplianceReviews as $review)
                    <tr>
                        <td><strong>{{ $review->department->name }}</strong></td>
                        <td>{{ $review->start_date->format('M j, Y') }} – {{ $review->end_date->format('M j, Y') }}</td>
                        <td><x-status-badge :status="$review->status" /></td>
                        <td>@if(count($review->findings))<span class="truncate-reason" title="{{ collect($review->findings)->pluck('message')->implode(' ') }}">{{ count($review->findings) }} finding(s): {{ collect($review->findings)->first()['message'] }}</span>@else<span>None</span>@endif</td>
                        <td>{{ $review->created_at->format('M j, Y g:i A') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="empty-table-cell"><x-icon name="shield" /><strong>No compliance checks yet</strong><span>Run one before locking a period.</span></td></tr>
                @endforelse
            </tbody></table></div>

            <p class="history-caption">Lock a department's schedule for a date range once it is finalized, so nobody can change it without deliberately unlocking it first.</p>
            <form method="POST" action="{{ route('schedule-locks.store') }}" class="workforce-filters" data-lock-form>
                @csrf
                <label><span>Department</span><select name="department_id" required data-lock-department>
                    <option value="">Select department</option>
                    @foreach($departments as $department)
                        <option value="{{ $department->id }}" data-latest-review-status="{{ $latestReviewByDepartment->get($department->id)?->status }}">{{ $department->name }}</option>
                    @endforeach
                </select></label>
                <label><span>From</span><input type="date" name="start_date" required></label>
                <label><span>To</span><input type="date" name="end_date" required></label>
                <label class="full-width"><span>Notes (optional)</span><input type="text" name="notes" maxlength="500"></label>
                <p class="history-caption full-width" data-lock-warning hidden><x-icon name="close" /> The latest compliance check for this department failed. Locking is still allowed, but review the findings above first.</p>
                <button class="btn btn-primary" type="submit" data-lock-submit>Lock period</button>
            </form>
            <script @if(isset($cspNonce)) nonce="{{ $cspNonce }}" @endif>
                document.querySelector('[data-lock-department]')?.addEventListener('change', (event) => {
                    const status = event.target.selectedOptions[0]?.dataset.latestReviewStatus;
                    const warning = document.querySelector('[data-lock-warning]');
                    if (warning) warning.hidden = status !== 'failed';
                });
            </script>

            <div class="table-responsive"><table class="dashboard-table workforce-table"><thead><tr><th>Department</th><th>Locked range</th><th>Locked by</th><th>Locked at</th><th>Actions</th></tr></thead><tbody>
                @forelse($activeLocks as $lock)
                    <tr>
                        <td><strong>{{ $lock->department->name }}</strong></td>
                        <td>{{ $lock->start_date->format('M j, Y') }} – {{ $lock->end_date->format('M j, Y') }}</td>
                        <td>{{ $lock->lockedBy?->name ?? '—' }}</td>
                        <td>{{ $lock->locked_at->format('M j, Y g:i A') }}</td>
                        <td><form method="POST" action="{{ route('schedule-locks.destroy', $lock) }}" data-confirm="Unlock {{ $lock->department->name }}, {{ $lock->start_date->format('M j') }}–{{ $lock->end_date->format('M j, Y') }}? Schedules in this period can be edited again." data-confirm-button="Unlock period" data-confirm-tone="caution">@csrf @method('DELETE')<button class="btn btn-sm btn-light" type="submit">Unlock</button></form></td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="empty-table-cell"><x-icon name="shield" /><strong>No locked periods</strong><span>Locked schedules will appear here.</span></td></tr>
                @endforelse
            </tbody></table></div>
        </section>
    @endif

    @if ($canManageData)
        <div class="modal fade" id="scheduleAssignmentModal" tabindex="-1" aria-labelledby="scheduleAssignmentModalLabel" aria-hidden="true">
            <div @class(['modal-dialog modal-dialog-centered', 'schedule-assignment-dialog' => $aiSchedulingEnabled])><div class="modal-content schedule-modal-content">
                <form method="POST" action="{{ route('schedules.store') }}" id="scheduleAssignmentForm" data-store-url="{{ route('schedules.store') }}" data-coverage-url="{{ route('schedules.coverage') }}" data-update-url-template="{{ route('schedules.update', ['scheduleAssignment' => '__ID__']) }}" data-conflict-url="{{ route('schedules.conflicts') }}">
                    @csrf
                    <input type="hidden" name="_method" value="POST" data-method-field>
                    <input type="hidden" name="recommendation_id" data-ai-recommendation-id-field>
                    <div class="modal-header"><div><p class="panel-kicker">Schedule assignment</p><h2 class="modal-title" id="scheduleAssignmentModalLabel">Assign a shift</h2><small data-assignment-subtitle>{{ $aiSchedulingEnabled ? 'Fill in the shift yourself, or ask the assistant who is free and least loaded.' : 'Fill in the shift details, then save.' }}</small></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
                    <div @class(['modal-body', 'schedule-assignment-workspace' => $aiSchedulingEnabled, 'schedule-form-grid' => ! $aiSchedulingEnabled])>
                        @if ($aiSchedulingEnabled)
                            <section class="schedule-assignment-manual" aria-labelledby="manualAssignmentTitle">
                                <div class="schedule-assignment-section-heading">
                                    <span><x-icon name="calendar" /></span>
                                    <div><p>Manual assignment</p><h3 id="manualAssignmentTitle">Assignment details</h3></div>
                                </div>
                                <p class="schedule-assignment-section-copy">These fields and the final save stay with HR.</p>
                                <div class="schedule-form-grid schedule-assignment-fields">
                        @endif
                                    <label><span>Department</span><select data-assignment-department-filter><option value="">All departments</option>@foreach($departments as $department)<option value="{{ $department->id }}">{{ $department->name }}</option>@endforeach</select></label>
                                    <label><span>Position</span><select data-assignment-position-filter><option value="">All positions</option>@foreach($positions as $position)<option value="{{ $position->id }}" data-department-id="{{ $position->department_id }}">{{ $position->title }}</option>@endforeach</select></label>
                                    <label><span>Shift</span><select name="shift_id" required><option value="">Select shift</option>@foreach($shifts as $shift)<option value="{{ $shift->id }}" data-hours="{{ round($shift->duration_minutes / 60, 1) }}">{{ $shift->name }} · {{ $shift->formatted_time }}</option>@endforeach</select></label>
                                    <label><span>Work date</span><input type="date" name="work_date" value="{{ ($isEditableDate($focusDate) ? $focusDate : $firstEditableDate)->toDateString() }}" min="{{ $firstEditableDate->toDateString() }}" required></label>
                                    {{-- The cover already standing on this shift is read before anyone is
                                         picked, so an over-covered shift is visible while it can still be changed. --}}
                                    <div class="schedule-coverage-status full-width" data-coverage-status role="status" hidden><x-icon name="users" /><span></span></div>
                                    <div class="schedule-employee-field full-width">
                                        <span class="schedule-employee-label"><span id="assignmentEmployeeLabel">Employee</span><span class="schedule-from-ai" data-assignment-from-ai hidden>From AI recommendation</span></span>
                                        {{-- Grouped by position: one department's staff list is scanned by
                                             role, which is how the shift is thought about in the first place. --}}
                                        <select name="employee_id" required data-assignment-employee-select aria-labelledby="assignmentEmployeeLabel" aria-describedby="assignmentEmployeeHelp">
                                            <option value="">Select employee</option>
                                            @foreach($employees->groupBy(fn ($employee) => $employee->position?->title ?? 'No position') as $positionTitle => $positionEmployees)
                                                <optgroup label="{{ $positionTitle }}">@foreach($positionEmployees as $employee)<option value="{{ $employee->id }}" data-department-id="{{ $employee->department_id }}" data-position-id="{{ $employee->position_id }}" data-name="{{ $employee->full_name }}" data-position="{{ $employee->position?->title }}" data-number="{{ $employee->employee_number }}">{{ $employee->employee_number }} · {{ $employee->full_name }} ({{ $employee->department?->code }})</option>@endforeach</optgroup>
                                            @endforeach
                                        </select>
                                        @php($employeeHelpIdle = $aiSchedulingEnabled ? 'Pick someone yourself, or use a recommendation on the right.' : 'Pick the employee to put on this shift.')
                                        <small id="assignmentEmployeeHelp" data-assignment-employee-help data-idle="{{ $employeeHelpIdle }}">{{ $employeeHelpIdle }}</small>
                                    </div>
                                    <div class="schedule-conflict-status full-width" data-conflict-status><x-icon name="check-circle" /><span data-conflict-title>Availability</span><small data-conflict-text>Choose an employee, shift, and date to check availability.</small></div>
                                    <div class="assignment-repeat full-width" data-assignment-repeat data-recurring-url="{{ route('recurring-schedules.store') }}" data-preview-url="{{ route('recurring-schedules.preview') }}">
                                        <label class="assignment-repeat-toggle"><input type="checkbox" data-repeat-toggle><span><strong>Repeat weekly</strong><small data-repeat-hint>Also assign this shift every week on the same day.</small></span></label>
                                        <div class="assignment-repeat-fields" data-repeat-fields hidden>
                                            <label><span>Until</span><input type="date" data-repeat-until min="{{ $firstEditableDate->toDateString() }}"></label>
                                            <p class="assignment-repeat-summary" data-repeat-summary aria-live="polite"></p>
                                            <button type="button" class="btn btn-link btn-sm assignment-repeat-more" data-repeat-more>More repeat options</button>
                                        </div>
                                    </div>
                                    <label class="full-width"><span>Notes</span><textarea name="notes" rows="3" maxlength="500" placeholder="Optional assignment note"></textarea></label>
                        @if ($aiSchedulingEnabled)
                                </div>
                            </section>
                            @include('schedules.partials.ai-recommendation')
                        @endif
                    </div>
                    <div class="modal-footer">
                        @if ($aiSchedulingEnabled)<p class="schedule-save-note"><x-icon name="shield" /> AI suggestions never save automatically.</p>@endif
                        {{-- Why the save is held back, next to the button it holds back. --}}
                        <p class="schedule-save-reason" id="assignmentSaveReason" data-assignment-reason hidden><x-icon name="info" /><span></span></p>
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary" data-assignment-submit aria-describedby="assignmentSaveReason">Save assignment</button>
                    </div>
                </form>
            </div></div>
        </div>

        <div class="modal fade" id="bulkScheduleModal" tabindex="-1" aria-labelledby="bulkScheduleModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered bulk-schedule-dialog"><div class="modal-content schedule-modal-content">
                <form method="POST" action="{{ route('schedules.roster.publish') }}" id="bulkScheduleForm" data-roster-fill-url="{{ route('schedules.roster.fill') }}" data-roster-evaluate-url="{{ route('schedules.roster.evaluate') }}" data-roster-publish-url="{{ route('schedules.roster.publish') }}" data-roster-draft-save-url="{{ route('schedules.roster.drafts.save') }}" data-roster-drafts-url="{{ route('schedules.roster.drafts') }}" data-roster-draft-discard-url-template="{{ route('schedules.roster.drafts.discard', ['rosterDraft' => '__ID__']) }}" @if($aiSchedulingEnabled) data-roster-suggest-url="{{ route('schedules.roster.suggest') }}" @endif>@csrf
                    <div class="modal-header bulk-schedule-header"><span class="bulk-ai-icon"><x-icon :name="$aiSchedulingEnabled ? 'ai' : 'users'" /></span><div><p class="panel-kicker">{{ $aiSchedulingEnabled ? 'AI Scheduling Assistant' : 'Department scheduling' }}</p><h2 class="modal-title" id="bulkScheduleModalLabel">Generate a bulk schedule</h2><small data-bulk-header-context>Choose a department to begin.</small></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
                    <div class="bulk-schedule-shell">
                        <div class="bulk-flow-rail"><ol class="bulk-flow-steps" data-bulk-flow-steps aria-label="Bulk scheduling workflow"><li data-step-item="1" class="active"><button type="button" class="bulk-flow-step" disabled><span class="bulk-flow-dot" aria-hidden="true"><em>1</em><x-icon name="check" /></span><span class="bulk-flow-copy"><strong>Department & staff</strong><small data-step-sub></small></span><span class="visually-hidden" data-step-state></span></button></li><li data-step-item="2"><button type="button" class="bulk-flow-step" disabled><span class="bulk-flow-dot" aria-hidden="true"><em>2</em><x-icon name="check" /></span><span class="bulk-flow-copy"><strong>Period & rules</strong><small data-step-sub></small></span><span class="visually-hidden" data-step-state></span></button></li><li data-step-item="3"><button type="button" class="bulk-flow-step" disabled><span class="bulk-flow-dot" aria-hidden="true"><em>3</em><x-icon name="check" /></span><span class="bulk-flow-copy"><strong>Draft generation</strong><small data-step-sub></small></span><span class="visually-hidden" data-step-state></span></button></li><li data-step-item="4"><button type="button" class="bulk-flow-step" disabled><span class="bulk-flow-dot" aria-hidden="true"><em>4</em><x-icon name="check" /></span><span class="bulk-flow-copy"><strong>Validation</strong><small data-step-sub></small></span><span class="visually-hidden" data-step-state></span></button></li><li data-step-item="5"><button type="button" class="bulk-flow-step" disabled><span class="bulk-flow-dot" aria-hidden="true"><em>5</em><x-icon name="check" /></span><span class="bulk-flow-copy"><strong>Approve & publish</strong><small data-step-sub></small></span><span class="visually-hidden" data-step-state></span></button></li></ol></div>
                        <div class="modal-body bulk-schedule-form">
                        <div class="schedule-form-grid bulk-schedule-details bulk-step-panel" data-step-panel="1">
                            <div class="bulk-inline-step-heading full-width">
                                <h3>Choose who to schedule</h3>
                            </div>
                            <p class="bulk-schedule-intro full-width">Choose a department and position, then select specific employees or include all active staff. {{ $aiSchedulingEnabled ? 'The assistant generates one reviewed recommendation' : 'The system generates one reviewed bulk plan' }}.</p>
                            <label><span>Department</span><select name="department_id" data-bulk-department-filter required><option value="">Select department</option>@foreach($departments as $department)<option value="{{ $department->id }}" data-employee-count="{{ $employees->where('department_id', $department->id)->count() }}" data-category="{{ $department->category }}">{{ $department->name }}</option>@endforeach</select></label>
                            {{-- Not full-width: it shares the row with the department select,
                                 which would otherwise leave half the grid empty and cost the
                                 staff list a row of height. Step 1's grid-template-rows in
                                 schedule.css counts on the two sitting together. --}}
                            <fieldset class="bulk-position-picker" data-bulk-position-picker><legend>Position</legend><p data-bulk-position-help>Select a department first</p><div data-bulk-position-options>@foreach($positions as $position)<label class="bulk-position-option" data-department-id="{{ $position->department_id }}" hidden><input type="checkbox" name="position_ids[]" value="{{ $position->id }}" data-bulk-position-filter disabled><span>{{ $position->title }}</span><em class="bulk-position-count">{{ $employees->where('position_id', $position->id)->count() }}</em></label>@endforeach</div></fieldset>
                            <div class="bulk-staff-toolbar full-width" data-bulk-staff-toolbar>
                                <div class="bulk-staff-search"><x-icon name="search" /><input type="search" data-bulk-employee-search placeholder="Search employee name or ID" aria-label="Search employee name or ID" disabled></div>
                                <div class="bulk-show-filter" role="group" aria-label="Show employees">
                                    <button type="button" data-bulk-show="all" aria-pressed="true">All <span data-bulk-show-count="all">0</span></button>
                                    <button type="button" data-bulk-show="selected" aria-pressed="false">Selected <span data-bulk-show-count="selected">0</span></button>
                                    <button type="button" data-bulk-show="unselected" aria-pressed="false">Not selected <span data-bulk-show-count="unselected">0</span></button>
                                </div>
                                <div class="bulk-selection-status" role="status"><span data-bulk-selection-summary>0 selected</span><button type="button" class="btn btn-sm btn-light" data-bulk-clear-selection hidden>Clear selection</button></div>
                            </div>
                            {{-- A table, not a list: who someone is, their number, their
                                 role and what the chosen period already holds for them are
                                 four things read across, and the last is the one that
                                 decides whether ticking them is sensible at all. --}}
                            <div class="bulk-inline-employee-list full-width" data-bulk-employee-list data-staff-load-url="{{ route('schedules.staff-load') }}">
                                <div class="bulk-employee-head"><label class="bulk-master-check"><input type="checkbox" data-bulk-master disabled><span class="visually-hidden" data-bulk-master-label>Select all shown</span></label><span>Employee</span><span>Employee ID</span><span>Position</span><span>This period</span></div>
                                <div class="bulk-employee-empty" data-bulk-employee-empty>
                                    <strong data-bulk-employee-empty-title>Choose a department</strong>
                                    <span data-bulk-employee-empty-body>Select a department to load its active employees.</span>
                                    <button type="button" class="btn btn-sm btn-light" data-bulk-employee-empty-action hidden></button>
                                </div>
                                @foreach($employees as $employee)
                                    <label class="bulk-employee-option" data-department-id="{{ $employee->department_id }}" data-position-id="{{ $employee->position_id }}" data-employee-id="{{ $employee->id }}" data-search="{{ strtolower($employee->employee_number.' '.$employee->full_name.' '.$employee->department?->name.' '.$employee->position?->title) }}">
                                        <input type="checkbox" name="employee_ids[]" value="{{ $employee->id }}">
                                        <span class="bulk-employee-check"><x-icon name="check" /></span>
                                        <span class="bulk-employee-avatar" aria-hidden="true">{{ collect(explode(' ', $employee->full_name))->filter()->take(2)->map(fn ($part) => strtoupper($part[0]))->join('') }}</span>
                                        <span class="bulk-employee-name">{{ $employee->full_name }}</span>
                                        <span class="bulk-employee-number">{{ $employee->employee_number }}</span>
                                        <span class="bulk-employee-position">{{ $employee->position?->title ?? 'No position' }}</span>
                                        <span class="bulk-employee-period" data-bulk-employee-period>—</span>
                                    </label>
                                @endforeach
                            </div>
                            <div class="bulk-inline-employee-actions full-width"><span data-bulk-period-note>Select the employees to include in this schedule.</span></div>
                        </div>

                        {{-- Period, pattern and rules read as one decision — how the
                             schedule is shaped — so they are set on one step rather
                             than split across a Next button. --}}
                        <div class="bulk-step-panel bulk-step-stack" data-step-panel="2" hidden>
                            <div class="schedule-form-grid bulk-schedule-details">
                                @if($aiSchedulingEnabled)
                                    <label class="bulk-pattern-field"><span>Shift pattern</span><select name="schedule_method" data-schedule-method><option value="rotation">Rotating · AI balanced</option><option value="custom">Custom · AI optimized mix</option><option value="fixed">Fixed · same shift</option></select><small data-schedule-method-hint></small></label>
                                @endif
                                <label @class(['full-width' => ! $aiSchedulingEnabled]) data-fixed-shift><span>Shift</span><select name="shift_id" required><option value="">Select shift</option>@foreach($shifts as $shift)<option value="{{ $shift->id }}" data-rotating="{{ $shift->is_rotating ? '1' : '0' }}" data-night="{{ $shift->is_night_shift ? '1' : '0' }}" data-color="{{ $shift->color }}" data-hours="{{ round($shift->duration_minutes / 60, 1) }}">{{ $shift->name }} · {{ $shift->formatted_time }}</option>@endforeach</select></label>
                                @if($aiSchedulingEnabled)
                                    <fieldset class="rotation-shift-picker full-width" data-rotation-shifts><legend>Shift pool for AI recommendation</legend><p data-shift-pool-help>Select at least two shifts. The assistant balances coverage and rotates employees weekly.</p><div>@foreach($shifts as $shift)<label><input type="checkbox" name="shift_ids[]" value="{{ $shift->id }}" data-rotating="{{ $shift->is_rotating ? '1' : '0' }}" data-night="{{ $shift->is_night_shift ? '1' : '0' }}" data-color="{{ $shift->color }}" data-hours="{{ round($shift->duration_minutes / 60, 1) }}"><span class="shift-color" style="background:{{ $shift->color }}"></span><span><strong>{{ $shift->name }}</strong><small>{{ $shift->formatted_time }}</small><em class="shift-unavailable-reason" data-shift-reason hidden></em></span></label>@endforeach</div></fieldset>
                                @endif
                                <label><span>Schedule period</span><select name="schedule_period" data-schedule-period><option value="weekly">Weekly · 7 days</option><option value="two_weeks">Two weeks · 14 days</option><option value="monthly">Monthly · calendar month</option></select></label>
                                <label data-period-start><span>Schedule starts</span><input type="date" name="period_start" value="{{ $rosterPeriodStart->toDateString() }}" min="{{ $today->toDateString() }}" required></label>
                                <label data-period-month hidden><span>Schedule month</span><input type="month" name="period_month" value="{{ $focusDate->format('Y-m') }}"></label>
                                <input type="hidden" name="start_date" value="{{ $focusDate->toDateString() }}">
                                <input type="hidden" name="end_date" value="{{ $focusDate->copy()->addDays(6)->toDateString() }}">
                                <div class="bulk-period-range full-width"><button type="button" class="icon-button subtle" data-bulk-period-previous aria-label="Previous schedule period"><x-icon name="chevron-right" class="flip-horizontal" /></button><strong data-bulk-period-range>Weekly period</strong><button type="button" class="icon-button subtle" data-bulk-period-next aria-label="Next schedule period"><x-icon name="chevron-right" /></button></div>
                                <label class="bulk-weekend-toggle full-width"><input type="checkbox" name="include_weekends" value="1"><span><strong>Include weekends</strong><small>Weekdays are scheduled by default for a fixed pattern.</small></span></label>
                            </div>

                            <fieldset class="bulk-rule-panel">
                                <legend>Scheduling rules</legend>
                                <div class="bulk-rule-grid">
                                    <label><span>Days off / week</span><select name="days_off_per_week"><option value="1">1 day</option><option value="2">2 days</option><option value="0">No automatic day off</option></select></label>
                                    <label><span>Maximum paid hours / week</span><input type="number" name="max_hours_per_week" value="48" min="1" max="168"><small>Paid hours only, net of each shift's unpaid meal period (Labor Code Art. 85) — a shift's clocked span runs longer than this total.</small></label>
                                    <label data-clinical-only><span>Night shift limit / week</span><input type="number" name="night_shift_limit" value="6" min="0" max="7"></label>
                                    <label data-clinical-only><span>Maximum consecutive nights</span><input type="number" name="max_consecutive_nights" value="4" min="1" max="7"><small>Caps a night-shift streak even when the weekly limit and days-off would otherwise allow it.</small></label>
                                    <label><span>Minimum rest between shifts (hrs)</span><input type="number" name="minimum_rest_hours" value="{{ config('schedule.minimum_rest_hours') }}" min="1" max="48"></label>
                                    <label><span>Maximum staff / shift</span><input type="number" name="maximum_staff_per_shift" value="1" min="1" max="100"><small>The assistant never puts more than this on one shift. Minimum cover is held to the unit's own standard.</small></label>
                                    <label data-rotating-only><span>Minimum senior / shift</span><input type="number" name="minimum_senior_per_shift" value="1" min="0" max="100"><small>Flags any shift left to entry-level staff alone. Set to 0 to skip the check.</small></label>
                                    <label data-rotating-only><span>Counts as senior</span><select name="senior_rank_threshold">@foreach($seniorRankOptions as $rank => $label)<option value="{{ $rank }}" @selected($rank === $defaultSeniorRank)>Rank {{ $rank }}+ — {{ $label }}</option>@endforeach</select></label>
                                    <label class="bulk-rule-holidays"><span>Holiday / closure dates</span><input type="text" name="holiday_dates_csv" placeholder="2026-12-25, 2026-12-30"><small>Comma-separated dates are protected from assignment.</small></label>
                                </div>
                                <label class="bulk-weekend-toggle bulk-overtime-toggle"><input type="checkbox" name="overtime_allowed" value="1" data-overtime-toggle><span><strong>Allow overtime recommendations</strong><small>When off, recommendations exceeding maximum weekly hours are blocked.</small></span></label>
                                <label class="bulk-justification-field" data-overtime-justification-wrap hidden>
                                    <span>Reason for allowing overtime this run</span>
                                    <textarea name="overtime_justification" rows="2" maxlength="500" placeholder="Explain why overtime is necessary for this roster (e.g. short-staffed shift, approved coverage need)." data-overtime-justification></textarea>
                                </label>
                                <p class="bulk-protected-rules"><x-icon name="shield" /> Approved leave, holidays, existing days off, overlapping shifts, and the minimum rest period above are always protected.</p>
                            </fieldset>
                        </div>

                        <div class="bulk-step-panel" data-step-panel="3" hidden>
                            <section class="bulk-schedule-review" data-bulk-review aria-live="polite"><x-icon name="shield" /><div><strong>Build the roster below, then publish</strong><span>Leave, double shifts, rest, maximum hours, night limits, and the unit's coverage standard are checked on every change.</span></div></section>
                            <section class="roster-board" data-roster-board hidden aria-live="polite">
                                <header class="roster-board-heading">
                                    <div><strong>Roster</strong><span data-roster-summary>Nobody is rostered yet.</span></div>
                                    <div class="roster-board-actions">
                                        <button type="button" class="btn btn-sm btn-outline-primary" data-roster-fill-shift><x-icon name="users" /> Put everyone on the selected shift</button>
                                        @if($aiSchedulingEnabled)<button type="button" class="btn btn-sm btn-outline-primary" data-roster-fill><x-icon name="ai" /> Let the assistant rotate them</button>@endif
                                        <button type="button" class="btn btn-sm btn-outline-primary" data-roster-save-draft>Save draft</button>
                                        <button type="button" class="btn btn-sm btn-light" data-roster-clear>Clear</button>
                                    </div>
                                </header>

                                {{-- A fortnight or a month is too much to read at once, so the
                                     board pages through it a week — or a single day — at a time. --}}
                                <div class="roster-calendar-bar">
                                    <div class="roster-view-switch" role="group" aria-label="Roster view">
                                        <button type="button" class="is-active" data-roster-view="week" aria-pressed="true">Week</button>
                                        <button type="button" data-roster-view="day" aria-pressed="false">Day</button>
                                    </div>
                                    <div class="roster-pager">
                                        <button type="button" class="icon-button subtle" data-roster-page-previous aria-label="Previous"><x-icon name="chevron-right" class="flip-horizontal" /></button>
                                        <strong data-roster-page-label>—</strong>
                                        <button type="button" class="icon-button subtle" data-roster-page-next aria-label="Next"><x-icon name="chevron-right" /></button>
                                    </div>
                                    <p class="roster-drag-hint">Drag a name to another day or shift to move it.</p>
                                </div>
                                {{-- The board is read by colour, so the colours are named:
                                     which shift each one is, and what a cell's tint says
                                     about the cover on it. --}}
                                <div class="roster-legend" data-roster-legend hidden>
                                    <div class="roster-legend-shifts" data-roster-legend-shifts></div>
                                    <div class="roster-legend-key">
                                        <span class="roster-legend-swatch is-met">Met</span>
                                        <span class="roster-legend-swatch is-below">Below</span>
                                        <span class="roster-legend-swatch is-over">Over</span>
                                    </div>
                                </div>
                                <div class="roster-draft-status" data-roster-draft-status hidden></div>
                                <div class="roster-assistant-notice" data-roster-assistant-notice hidden></div>
                                {{-- Hard, and deliberately first: a period a previous roster
                                     run already published cannot be rostered a second time, so
                                     this is the one thing worth reading before the board itself.
                                     Same fold as the coverage panel below — the headline carries
                                     the block, the list is there for whoever wants the dates. --}}
                                <div class="roster-gap-panel is-hard" data-roster-already-rostered-panel hidden>
                                    <button type="button" class="roster-gap-heading" data-roster-already-rostered-toggle aria-expanded="false">
                                        <x-icon name="close" />
                                        <strong data-roster-already-rostered-title></strong>
                                        <span class="roster-gap-caret"><x-icon name="chevron-down" /></span>
                                    </button>
                                    <div class="roster-gap-body" data-roster-already-rostered-body hidden>
                                        <ul data-roster-already-rostered-list></ul>
                                        <p class="roster-gap-note">Publishing again would schedule this department twice over the same dates. Remove the existing assignments from the calendar first, or pick a period that is not yet rostered. There is no override for this one.</p>
                                    </div>
                                </div>
                                {{-- Folded shut by default. Fourteen red rows above the board
                                     is the loudest thing on the step and buries the board it is
                                     asking you to fix; the count alone carries the same block. --}}
                                <div class="roster-gap-panel is-hard" data-roster-gap-panel hidden>
                                    <button type="button" class="roster-gap-heading" data-roster-gap-toggle aria-expanded="false">
                                        <x-icon name="close" />
                                        <strong data-roster-gap-title></strong>
                                        <span class="roster-gap-caret"><x-icon name="chevron-down" /></span>
                                    </button>
                                    <div class="roster-gap-body" data-roster-gap-body hidden>
                                        <ul data-roster-gap-list></ul>
                                        <p class="roster-gap-note">Coverage must actually be met — fix the roster below or lower the minimum staff / senior requirement on Step 2. There is no override for this one.</p>
                                    </div>
                                </div>
                                <div class="roster-warn-panel" data-roster-night-streak-panel hidden>
                                    <div class="roster-gap-heading"><x-icon name="shield" /><strong data-roster-night-streak-title></strong></div>
                                    <ul data-roster-night-streak-list></ul>
                                    <label class="bulk-justification-field">
                                        <span>Consecutive night shift justification</span>
                                        <textarea name="night_streak_justification" rows="2" maxlength="500" placeholder="Explain why publishing with the consecutive-night streak(s) above is necessary." data-night-streak-justification></textarea>
                                    </label>
                                </div>
                                {{-- Tier B, like the night streak above. Placements are
                                     only listed here when a person at high burnout risk
                                     is taken past their protected limits by hand; the
                                     fill and rotation assistants never do that. --}}
                                <div class="roster-warn-panel" data-roster-burnout-panel hidden>
                                    <div class="roster-gap-heading"><x-icon name="alert" /><strong data-roster-burnout-title></strong></div>
                                    <ul data-roster-burnout-list></ul>
                                    <label class="bulk-justification-field">
                                        <span>Burnout risk justification</span>
                                        <textarea name="burnout_justification" rows="2" maxlength="500" placeholder="Explain why these employees must work beyond their protected limits." data-burnout-justification></textarea>
                                    </label>
                                </div>
                                <div class="roster-board-days" data-roster-days></div>
                                {{-- The board answers "is this day covered"; this answers
                                     "is this person carrying a fair share", which is the
                                     question the grid cannot show because it is arranged
                                     by day rather than by employee. --}}
                                <section class="roster-workload" data-roster-workload hidden>
                                    <header>
                                        <div><strong>Workload this period</strong><span data-roster-workload-note></span></div>
                                        <span class="roster-workload-tally" data-roster-workload-tally></span>
                                    </header>
                                    <ul data-roster-workload-list></ul>
                                </section>
                            </section>
                        </div>

                        {{-- The read-back before anyone approves anything: what was
                             asked for on Steps 1-2, and what the Step 3 draft made
                             of it. Both halves are rendered from the evaluation the
                             board already holds, so opening this step costs nothing.
                             It reports; it does not gate. Coverage is already a hard
                             block on Step 3 and the night-streak justification is
                             required on Step 5, so refusing Next here would only be
                             a second lock on doors that are already shut. --}}
                        <div class="bulk-step-panel" data-step-panel="4" hidden>
                            <section class="validation-recap">
                                <header>
                                    <div><strong>What you asked for</strong><span>Check this against what you meant to enter before approving.</span></div>
                                    {{-- Reading something wrong here means going back, so the
                                         way back is on the line that named it. --}}
                                    <div class="validation-recap-actions">
                                        <button type="button" class="btn btn-sm btn-light" data-bulk-goto-step="1">Edit staff</button>
                                        <button type="button" class="btn btn-sm btn-light" data-bulk-goto-step="2">Edit period and rules</button>
                                    </div>
                                </header>
                                <dl class="validation-recap-list" data-validation-recap></dl>
                            </section>

                            <section class="validation-checks">
                                <header>
                                    <strong>Automatic checks</strong>
                                    <span data-validation-tally></span>
                                </header>
                                <ul class="validation-check-list" data-validation-checks></ul>
                                <p class="validation-checks-empty" data-validation-empty hidden>
                                    No draft to check yet. Go back to Step 3 and generate one.
                                </p>
                            </section>

                            <section class="validation-checks labor-compliance" data-labor-compliance hidden>
                                <header>
                                    <div><strong>Labor compliance</strong><small>Philippine Labor Code and hospital HR policy</small></div>
                                    <span class="labor-compliance-tally" data-labor-compliance-tally></span>
                                </header>
                                <ul class="labor-compliance-list" data-labor-compliance-list></ul>
                            </section>
                        </div>

                        <div class="bulk-step-panel" data-step-panel="5" hidden>
                            <section class="publish-summary" data-publish-summary>
                                <header><strong>What publishing will do</strong><span>Computed from the reviewed roster on Step 3.</span></header>
                                <div class="publish-summary-grid" data-publish-summary-grid></div>
                                <p class="publish-summary-gap" data-publish-summary-gap hidden></p>
                            </section>
                            <label class="bulk-schedule-notes"><span>Notes</span><textarea name="notes" rows="2" maxlength="500" placeholder="Optional note for all created assignments"></textarea></label>
                            <label class="bulk-approval" data-bulk-approval-wrap hidden><input type="checkbox" data-bulk-approval><span><strong>I reviewed the summary above and approve this bulk schedule</strong><small>Publishing creates exactly the assignments and rest days summarized above, and notifies every employee they affect.</small></span></label>
                        </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <p class="schedule-save-note"><x-icon name="shield" /> {{ $aiSchedulingEnabled ? 'AI recommendations' : 'Bulk plans' }} never publish automatically.</p>
                        <span class="bulk-step-error" data-bulk-step-error hidden></span>
                        <button type="button" class="btn btn-light" data-bulk-back hidden>Back</button>
                        <button type="button" class="btn btn-primary bulk-next-button" data-bulk-next>Next <x-icon name="chevron-right" /></button>
                        <button type="submit" class="btn btn-primary" data-bulk-save disabled hidden>Approve & publish</button>
                    </div>
                </form>
            </div></div>
        </div>

        <div class="modal fade" id="recurringScheduleModal" tabindex="-1" aria-labelledby="recurringScheduleModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered recurring-dialog"><div class="modal-content schedule-modal-content">
                <form method="POST" action="{{ route('recurring-schedules.store') }}" id="recurringScheduleForm" data-preview-url="{{ route('recurring-schedules.preview') }}">@csrf
                    <input type="hidden" name="skip_conflicts" value="0" data-recurring-skip>
                    <div class="modal-header"><div><p class="panel-kicker">Recurring coverage</p><h2 class="modal-title" id="recurringScheduleModalLabel">Create recurring schedule</h2><small>Repeat one shift for one employee across a date range.</small></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
                    <div class="modal-body recurring-workspace">
                        <div class="recurring-form">
                            <section class="recurring-section" aria-labelledby="recurringWhoTitle">
                                <h3 class="recurring-section-title" id="recurringWhoTitle">1 · Who</h3>
                                <div class="schedule-form-grid">
                                    <label><span>Department</span><select data-recurring-department-filter><option value="">All departments</option>@foreach($departments as $department)<option value="{{ $department->id }}">{{ $department->name }}</option>@endforeach</select></label>
                                    <label><span>Position</span><select data-recurring-position-filter><option value="">All positions</option>@foreach($positions as $position)<option value="{{ $position->id }}" data-department-id="{{ $position->department_id }}">{{ $position->title }}</option>@endforeach</select></label>
                                    <label class="full-width"><span>Employee</span><select name="employee_id" required data-recurring-employee-select><option value="">Select employee</option>@foreach($employees as $employee)<option value="{{ $employee->id }}" data-department-id="{{ $employee->department_id }}" data-position-id="{{ $employee->position_id }}" data-position="{{ $employee->position?->title }}" data-number="{{ $employee->employee_number }}">{{ $employee->employee_number }} · {{ $employee->full_name }}</option>@endforeach</select><small class="recurring-employee-meta" data-recurring-employee-meta hidden></small></label>
                                </div>
                            </section>
                            <fieldset class="recurring-section">
                                <legend class="recurring-section-title">2 · Shift</legend>
                                <div class="recurring-shift-options">
                                    @foreach($shifts as $shift)
                                        <label class="recurring-shift-option"><input type="radio" name="shift_id" value="{{ $shift->id }}" required><span class="recurring-shift-swatch" style="--shift-color: {{ $shift->color }}" aria-hidden="true"></span><span><strong>{{ $shift->name }}</strong><small>{{ $shift->formatted_time }}</small></span></label>
                                    @endforeach
                                </div>
                            </fieldset>
                            <section class="recurring-section" aria-labelledby="recurringPatternTitle">
                                <h3 class="recurring-section-title" id="recurringPatternTitle">3 · Pattern</h3>
                                {{-- Read as a sentence -- "Repeat every 1 week on
                                     ..." -- rather than as three labelled fields,
                                     so the pattern is legible before the weekday
                                     row below is even looked at. The kind select
                                     leads it because "every day" and "every N
                                     weeks" answer the same question. --}}
                                <div class="recurring-pattern-row">
                                    <span class="recurring-pattern-lead">Repeat</span>
                                    <select name="recurrence_type" data-recurrence-type aria-label="How the series repeats"><option value="weekly">every</option><option value="daily">every day</option></select>
                                    <select name="interval_weeks" data-recurring-interval aria-label="Repeat interval"><option value="1">1 week</option><option value="2">2 weeks</option><option value="3">3 weeks</option><option value="4">4 weeks</option></select>
                                    <span class="recurring-pattern-lead" data-recurring-pattern-on>on</span>
                                    <div class="recurring-presets" data-weekday-presets>
                                        <button type="button" class="btn btn-sm btn-light" data-weekday-preset="weekdays">Weekdays</button>
                                        <button type="button" class="btn btn-sm btn-light" data-weekday-preset="weekends">Weekends</button>
                                        <button type="button" class="btn btn-sm btn-light" data-weekday-preset="clear">Clear</button>
                                    </div>
                                </div>
                                <fieldset class="weekday-selector" data-weekday-selector><legend class="visually-hidden">Repeat on</legend><div>@foreach($weekdays as $dayNumber => $dayName)<label><input type="checkbox" name="weekdays[]" value="{{ $dayNumber }}" aria-label="{{ $dayName }}" @checked($dayNumber <= 5)><span>{{ substr($dayName, 0, 3) }}</span></label>@endforeach</div></fieldset>
                            </section>
                            <section class="recurring-section" aria-labelledby="recurringRangeTitle">
                                <h3 class="recurring-section-title" id="recurringRangeTitle">4 · Date range</h3>
                                <div class="recurring-range">
                                    <label class="recurring-range-start"><span>Starts</span><input type="date" name="start_date" value="{{ ($isEditableDate($focusDate) ? $focusDate : $firstEditableDate)->toDateString() }}" min="{{ $firstEditableDate->toDateString() }}" required></label>
                                    {{-- Two ways to stop, and only one of them live:
                                         the control belonging to the mode that is
                                         off is disabled, so it neither posts nor
                                         takes a tab stop, and the request decides
                                         on the mode alone rather than on whichever
                                         field happens to be filled in. --}}
                                    <fieldset class="recurring-range-end" data-recurring-end>
                                        <legend>Ends</legend>
                                        <label class="recurring-end-mode is-active">
                                            <input type="radio" name="end_mode" value="on" checked data-recurring-end-mode>
                                            <span>On</span>
                                            <input type="date" name="end_date" value="{{ ($isEditableDate($focusDate) ? $focusDate : $firstEditableDate)->copy()->addMonth()->toDateString() }}" min="{{ $firstEditableDate->toDateString() }}" data-recurring-end-date aria-label="Series end date" required>
                                        </label>
                                        <label class="recurring-end-mode">
                                            <input type="radio" name="end_mode" value="after" data-recurring-end-mode>
                                            <span>After</span>
                                            <input type="number" name="occurrences" value="10" min="1" max="{{ (int) config('schedule.max_recurrence_days') }}" step="1" data-recurring-occurrences aria-label="Number of shifts" disabled>
                                            <span>shifts</span>
                                        </label>
                                    </fieldset>
                                </div>
                            </section>
                            <div class="schedule-form-grid"><label class="full-width"><span>Notes <small>(optional)</small></span><textarea name="notes" rows="2" maxlength="500" placeholder="Saved on every shift in this series"></textarea></label></div>
                        </div>
                        <aside class="recurring-preview" data-recurring-preview aria-live="polite" aria-labelledby="recurringPreviewTitle">
                            <p class="recurring-section-title" id="recurringPreviewTitle">Preview</p>
                            <div>
                                <strong class="recurring-summary" data-recurrence-summary>Every week on weekdays</strong>
                                <div class="recurring-summary-sub" data-recurring-summary-sub></div>
                            </div>
                            <div class="recurring-stats">
                                <div><span>Shifts to create</span><strong data-recurring-create>—</strong><small data-recurring-create-note></small></div>
                                <div><span>Busiest week, paid hours</span><strong data-recurring-hours>—</strong><small data-recurring-hours-note></small></div>
                            </div>
                            <p class="recurring-preview-empty" data-recurring-preview-empty>Choose an employee and a shift to preview the series.</p>
                            <div class="recurring-calendar" data-recurring-calendar hidden></div>
                            <ul class="recurring-checks" data-recurring-checks></ul>
                            <div class="recurring-conflicts" data-recurring-conflicts hidden>
                                <strong data-recurring-conflicts-title></strong>
                                <ul data-recurring-conflict-list></ul>
                                <label class="recurring-skip"><input type="checkbox" data-recurring-skip-toggle checked> Skip these dates and create the rest</label>
                            </div>
                        </aside>
                    </div>
                    <div class="modal-footer">
                        <p class="schedule-save-note"><x-icon name="shield" /> Each shift in the series can still be edited or removed on its own.</p>
                        <span class="recurring-reason" data-recurring-reason hidden></span>
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary" data-recurring-submit>Create series</button>
                    </div>
                </form>
            </div></div>
        </div>

        @include('schedules.partials.confirmation-modal')

        <div class="modal fade day-roster-modal" id="dayRosterModal" tabindex="-1" aria-labelledby="dayRosterModalLabel" aria-hidden="true" data-schedule-today="{{ $today->toDateString() }}" data-day-roster-url="{{ route('schedules.day-roster') }}" data-update-url-template="{{ route('schedules.update', ['scheduleAssignment' => '__ID__']) }}">
            <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-xl day-roster-dialog"><div class="modal-content schedule-modal-content">
                <div class="modal-header day-roster-header">
                    <div><p class="eyebrow">Operations Control</p><h2 class="modal-title" id="dayRosterModalLabel">Published Schedules</h2><p data-day-roster-date>—</p></div>
                    <div class="day-roster-controls">
                        <label class="day-roster-search"><x-icon name="search" /><input type="search" placeholder="Search positions or staff…" data-day-roster-search></label>
                        <div class="dropdown">
                            <button class="btn btn-outline-primary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false"><x-icon name="chevron-down" /> Filter Shifts</button>
                            <ul class="dropdown-menu dropdown-menu-end" data-day-roster-shift-filter>
                                <li><button class="dropdown-item active" type="button" data-shift-filter="">All shifts</button></li>
                                <li><button class="dropdown-item" type="button" data-shift-filter="day">Day</button></li>
                                <li><button class="dropdown-item" type="button" data-shift-filter="evening">Evening</button></li>
                                <li><button class="dropdown-item" type="button" data-shift-filter="night">Night</button></li>
                            </ul>
                        </div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body day-roster-body">
                    <div class="day-roster-board" data-day-roster-board></div>
                    <div class="compact-empty-state" data-day-roster-empty hidden><x-icon name="calendar" /><p>No shifts match the current search or filter.</p></div>
                    <div class="compact-empty-state" data-day-roster-loading hidden><x-icon name="clock" /><p>Loading the published roster…</p></div>
                </div>
                <div class="modal-footer day-roster-summary">
                    <div class="day-roster-summary-stat"><span>Total staff on duty</span><strong data-day-roster-total>0</strong></div>
                    <div class="day-roster-summary-stat day-roster-coverage">
                        <span>Shift coverage</span>
                        <div>
                            <em class="coverage-dot coverage-day"></em><strong data-day-roster-coverage-day>0</strong>
                            <em class="coverage-dot coverage-evening"></em><strong data-day-roster-coverage-evening>0</strong>
                            <em class="coverage-dot coverage-night"></em><strong data-day-roster-coverage-night>0</strong>
                        </div>
                    </div>
                    <div class="day-roster-summary-stat"><span>Departments active</span><strong data-day-roster-departments>0</strong></div>
                    <button type="button" class="btn btn-primary" data-day-roster-export><x-icon name="download" /> Export Roster</button>
                </div>
            </div></div>
        </div>
    @endif
@endsection
