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
    @endphp

    <section class="page-heading">
        <div>
            <p class="eyebrow">HRMS Overview</p>
            <h1>{{ $greeting }}, {{ $greetingName }}.</h1>
            <p>Here’s what’s happening across your hospital workforce today.</p>
        </div>
    </section>

    <section class="stats-grid" aria-label="Workforce summary">
        <x-stat-card
            title="Total employees"
            :value="number_format($stats['employees'])"
            icon="users"
            tone="primary"
            :detail="$stats['new_this_month'].' new this month'"
            :href="route('employees.index')"
        />
        <x-stat-card
            title="Active workforce"
            :value="number_format($stats['active_employees'])"
            icon="check-circle"
            tone="success"
            detail="Ready for duty"
            :href="$canManageWorkforce ? route('attendance.reports.index') : route('attendance.index')"
        />
        <x-stat-card
            title="Departments"
            :value="number_format($stats['departments'])"
            icon="building"
            tone="violet"
            detail="Operational units"
            :href="route('departments.index')"
        />
        <x-stat-card
            title="Positions"
            :value="number_format($stats['positions'])"
            icon="briefcase"
            tone="amber"
            detail="Defined roles"
            :href="route('positions.index')"
        />
    </section>

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
                <table class="dashboard-table dashboard-table-fit">
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
                                <td>
                                    <div class="employee-cell">
                                        <span class="avatar avatar-table">{{ $initials }}</span>
                                        <div>
                                            <strong>{{ $employee->full_name }}</strong>
                                            <span>{{ $employee->user?->email ?? 'No linked email' }}</span>
                                        </div>
                                    </div>
                                </td>
                                <td><span class="employee-number">{{ $employee->employee_number }}</span></td>
                                <td>{{ $employee->department?->name ?? 'Unassigned' }}</td>
                                <td>{{ $employee->position?->title ?? 'Unassigned' }}</td>
                                <td><x-status-badge :status="$employee->employment_status" /></td>
                                <td>
                                    <x-dashboard-action-menu
                                        :label="'Actions for '.$employee->full_name"
                                        :items="$canManageWorkforce ? [
                                            ['label' => 'View schedule', 'url' => route('schedules.index', ['employee_id' => $employee->id]), 'icon' => 'calendar'],
                                            ['label' => 'View attendance', 'url' => route('attendance.reports.index', ['employee_id' => $employee->id]), 'icon' => 'clock'],
                                            ['label' => 'View leave records', 'url' => route('leaves.index', ['employee_id' => $employee->id]), 'icon' => 'leave'],
                                        ] : [
                                            ['label' => 'Open my schedule', 'url' => route('schedules.index'), 'icon' => 'calendar'],
                                            ['label' => 'Open my attendance', 'url' => route('attendance.index'), 'icon' => 'clock'],
                                            ['label' => 'Open my leave requests', 'url' => route('leaves.index'), 'icon' => 'leave'],
                                        ]"
                                    />
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

        <aside class="panel department-panel" id="department-overview">
            <div class="panel-header">
                <div>
                    <p class="panel-kicker">Organization</p>
                    <h2>Workforce by department</h2>
                </div>
                <x-dashboard-action-menu
                    label="Department options"
                    :items="$canManageWorkforce ? [
                        ['label' => 'Open workforce analytics', 'url' => route('analytics.index'), 'icon' => 'analytics'],
                        ['label' => 'Filter schedules by department', 'url' => route('schedules.index'), 'icon' => 'calendar'],
                        ['label' => 'Open attendance reports', 'url' => route('attendance.reports.index'), 'icon' => 'report'],
                    ] : [
                        ['label' => 'Open schedule calendar', 'url' => route('schedules.index'), 'icon' => 'calendar'],
                        ['label' => 'Open leave management', 'url' => route('leaves.index'), 'icon' => 'leave'],
                    ]"
                />
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

    <section class="quick-actions" id="position-overview">
        <div class="quick-action-copy">
            <span class="quick-action-icon"><x-icon name="briefcase" /></span>
            <div>
                <p class="panel-kicker">Organization readiness</p>
                <h2>Your HRMS foundation is ready.</h2>
                <p>Roles, departments, positions, employees, and secure session authentication are connected.</p>
            </div>
        </div>
        <div class="quick-action-meta">
            <span><x-icon name="check-circle" /> {{ $stats['positions'] }} positions configured</span>
            <span><x-icon name="check-circle" /> {{ $stats['departments'] }} departments active</span>
        </div>
    </section>
@endsection
