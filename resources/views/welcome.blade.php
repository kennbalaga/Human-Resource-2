@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
    @php
        $greetingNow = now(config('workforce.timezone', 'Asia/Manila'));
        $greeting = match (true) {
            $greetingNow->hour < 12 => 'Good morning',
            $greetingNow->hour < 18 => 'Good afternoon',
            default => 'Good evening',
        };

        // Prefer the workforce profile's first name; accounts without one fall
        // back to the leading word of the display name.
        $greetingUser = auth()->user();
        $greetingName = $greetingUser->employee?->first_name ?: str($greetingUser->name)->explode(' ')->first();

        // The headcount card is the only one with a real comparison behind it, so
        // it is the only one that earns an arrow.
        $newThisMonth = $stats['new_this_month'];
        $newLastMonth = $stats['new_last_month'];
        $hireTrend = match (true) {
            $newThisMonth > $newLastMonth => 'up',
            $newThisMonth < $newLastMonth => 'down',
            default => null,
        };
        $hireTrendLabel = $hireTrend === null
            ? null
            : ($hireTrend === 'up' ? 'Up from ' : 'Down from ').$newLastMonth.' last month';
    @endphp

    <section class="page-heading">
        <div>
            <p class="eyebrow">HRMS Overview</p>
            <h1>{{ $greeting }}, {{ $greetingName }}.</h1>
            {{-- The date is stated here rather than left to the topbar clock. The
                 sentence claims to describe "today" and every panel below it is
                 dated, so the page should say which day it means. --}}
            <p>{{ $greetingNow->format('l, F j, Y') }} · here’s what’s happening across your hospital workforce.</p>
        </div>
        @if ($canManageWorkforce)
            <a class="btn btn-primary dashboard-action" href="{{ route('employees.create') }}">
                <x-icon name="plus" /> Add employee
            </a>
        @endif
    </section>

    {{-- Approving a timesheet or reissuing a badge can redirect back here. Without
         this the confirmation was written to the session and silently dropped. --}}
    @include('partials.organization-feedback')

    <section class="stats-grid" aria-label="Workforce summary">
        <x-stat-card
            title="Total employees"
            :value="number_format($stats['employees'])"
            icon="users"
            :detail="$newThisMonth.' new this month'"
            :trend="$hireTrend"
            :trend-label="$hireTrendLabel"
            :href="route('employees.index')"
        />
        <x-stat-card
            title="Active workforce"
            :value="number_format($stats['active_employees'])"
            icon="check-circle"
            :detail="$stats['active_share'].'% of headcount'"
            :href="$canManageWorkforce ? route('attendance.reports.index') : route('attendance.index')"
        />
        <x-stat-card
            title="Departments"
            :value="number_format($stats['departments'])"
            icon="building"
            detail="Operational units"
            :href="route('departments.index')"
        />
        <x-stat-card
            title="Positions"
            :value="number_format($stats['positions'])"
            icon="briefcase"
            detail="Defined roles"
            :href="route('positions.index')"
        />
    </section>

    {{-- Decisions before description. What a manager has to answer, and who is
         missing from the floor, both lose their value the later they are read;
         the charts below them are just as true after lunch. --}}
    @if ($approvals && $exceptions)
        <div class="dashboard-grid">
            <x-approval-queue :queue="$approvals" />
            <x-today-exceptions :exceptions="$exceptions" />
        </div>
    @endif

    <x-shift-overview :overview="$shiftOverview" />

    <x-attendance-overview :overview="$attendanceOverview" :can-manage-workforce="$canManageWorkforce" />

    <div class="dashboard-grid">
        <section class="panel panel-wide" id="employee-overview">
            <div class="panel-header">
                <div>
                    <p class="panel-kicker">Employee directory</p>
                    <h2>Recently added employees</h2>
                </div>
            </div>

            <div class="table-responsive">
                <table class="dashboard-table dashboard-table-fit table-stack">
                    <colgroup>
                        <col style="width: 30%">
                        <col style="width: 16%">
                        <col style="width: 18%">
                        <col style="width: 16%">
                        <col style="width: 12%">
                        <col style="width: 8%">
                    </colgroup>
                    <thead>
                        <tr>
                            <th>Employee</th>
                            <th>Employee ID</th>
                            <th>Department</th>
                            <th>Position</th>
                            <th>Status</th>
                            <th><span class="visually-hidden">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($recentEmployees as $employee)
                            @php
                                $initials = strtoupper(substr($employee->first_name, 0, 1).substr($employee->last_name, 0, 1));
                            @endphp
                            <tr>
                                <td data-label="Employee">
                                    <div class="employee-cell">
                                        <span class="avatar avatar-table">{{ $initials }}</span>
                                        <div>
                                            {{-- The record's own address. There is no profile page
                                                 behind it any more: following it lands on the
                                                 directory with this employee's panel already open,
                                                 which is what the directory's own View button does
                                                 without the navigation. The name was previously
                                                 dead text, leaving the row's overflow menu as the
                                                 only way through to the person. --}}
                                            @if ($canManageWorkforce)
                                                <a class="employee-cell-link" href="{{ route('employees.show', $employee) }}">{{ $employee->full_name }}</a>
                                            @else
                                                <strong>{{ $employee->full_name }}</strong>
                                            @endif
                                            <span>{{ $employee->user?->email ?? 'No linked email' }}</span>
                                        </div>
                                    </div>
                                </td>
                                <td data-label="Employee ID"><span class="employee-number">{{ $employee->employee_number }}</span></td>
                                <td data-label="Department">{{ $employee->department?->name ?? 'Unassigned' }}</td>
                                <td data-label="Position">{{ $employee->position?->title ?? 'Unassigned' }}</td>
                                <td data-label="Status"><x-status-badge :status="$employee->employment_status" /></td>
                                <td>
                                    {{-- Only a manager gets a menu here. The other branch used to
                                         offer "Open my schedule" and "Open my attendance", which
                                         are the viewer's own records rather than this row's — and
                                         the only non-managers who reach this page at all are
                                         accounts with no workforce profile, so those links led
                                         nowhere useful for exactly the people who saw them. --}}
                                    @if ($canManageWorkforce)
                                        <x-dashboard-action-menu
                                            :label="'Actions for '.$employee->full_name"
                                            :items="[
                                                ['label' => 'View employee', 'url' => route('employees.show', $employee), 'icon' => 'users'],
                                                ['label' => 'View schedule', 'url' => route('schedules.index', ['employee_id' => $employee->id]), 'icon' => 'calendar'],
                                                ['label' => 'View attendance', 'url' => route('attendance.reports.index', ['employee_id' => $employee->id]), 'icon' => 'clock'],
                                                ['label' => 'View leave records', 'url' => route('leaves.index', ['employee_id' => $employee->id]), 'icon' => 'leave'],
                                            ]"
                                        />
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="empty-table-cell">
                                    <x-icon name="users" />
                                    <strong>No employees yet</strong>
                                    <span>Employee records will appear here once added.</span>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($recentEmployees->hasPages())
                <div class="report-pagination panel-pagination">{{ $recentEmployees->onEachSide(1)->links('pagination::bootstrap-5') }}</div>
            @endif

            <a href="{{ route('employees.index') }}" class="panel-footer-link">View all employees <x-icon name="chevron-right" /></a>
        </section>

        <aside class="panel department-panel">
            <div class="panel-header">
                <div>
                    <p class="panel-kicker">Organization</p>
                    <h2>Workforce by department</h2>
                </div>
                @if ($canManageWorkforce)
                    <x-dashboard-action-menu
                        label="Department options"
                        :items="[
                            ['label' => 'Open workforce analytics', 'url' => route('analytics.index'), 'icon' => 'analytics'],
                            ['label' => 'Filter schedules by department', 'url' => route('schedules.index'), 'icon' => 'calendar'],
                            ['label' => 'Open attendance reports', 'url' => route('attendance.reports.index'), 'icon' => 'report'],
                        ]"
                    />
                @endif
            </div>

            <div class="department-list">
                @forelse ($departments as $department)
                    @php
                        $percentage = $stats['active_employees'] > 0
                            ? round(($department->active_employees_count / $stats['active_employees']) * 100)
                            : 0;
                    @endphp
                    <article class="department-row">
                        <div class="department-row-top">
                            <span class="department-icon">{{ strtoupper(substr($department->code, 0, 2)) }}</span>
                            <div>
                                <strong>{{ $department->name }}</strong>
                                <span>{{ $department->active_employees_count }} active {{ str('employee')->plural($department->active_employees_count) }}</span>
                            </div>
                            <b>{{ $percentage }}%</b>
                        </div>
                        <div class="progress department-progress" role="progressbar" aria-label="{{ $department->name }} workforce share" aria-valuenow="{{ $percentage }}" aria-valuemin="0" aria-valuemax="100">
                            <div class="progress-bar" style="width: {{ $percentage }}%"></div>
                        </div>
                    </article>
                @empty
                    <div class="compact-empty-state">
                        <x-icon name="building" />
                        <p>No active departments found.</p>
                    </div>
                @endforelse
            </div>

            <a href="{{ route('departments.index') }}" class="department-footer-link">{{ $canManageWorkforce ? 'Manage' : 'View' }} departments <x-icon name="chevron-right" /></a>
        </aside>
    </div>

    @if ($analyticsPreview)
        <x-workforce-analytics-preview :preview="$analyticsPreview" />
    @endif
@endsection
