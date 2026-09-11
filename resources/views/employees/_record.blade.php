{{-- The employee record card, rendered as the fragment the directory slides in
     over the list. This is the whole of the profile now — the standalone page it
     used to share has been removed.

     The layout follows the staff-detail reference: identity across the top, the
     information rail down the left where it reads as the record's own summary,
     and the tabbed sections filling the space beside it. --}}
@php
    $account = $employee->user;
    $initials = strtoupper(substr($employee->first_name, 0, 1).substr($employee->last_name, 0, 1));
    $hireDate = $employee->hire_date;
    $tenure = $hireDate?->isPast()
        ? $hireDate->diffForHumans(['syntax' => \Carbon\CarbonInterface::DIFF_ABSOLUTE, 'parts' => 2, 'join' => true])
        : null;
    $directReports = $employee->directReports;

    // Writing to somebody is the most-reached-for thing on a profile, so it is
    // a button of its own beside Edit rather than a line inside an overflow
    // menu — which is what the reference does with its two header actions, and
    // which left the menu holding nothing worth a click.
    $canEmail = $canViewPrivate && $account?->email;

    // Days are stored to two decimals but read as "1.5" and "3", never "3.00".
    $days = fn ($value) => rtrim(rtrim(number_format((float) $value, 1), '0'), '.');

    // A check-in is stored in UTC and only means anything in the timezone of
    // the door it was scanned at.
    $clock = fn (?\Carbon\CarbonInterface $at, $record) => $at
        ?->timezone($record->officeLocation?->timezone ?? config('schedule.timezone'))
        ->format('g:i A') ?? '—';

    // Tabs are built from what this viewer is actually allowed to act on, so the
    // strip never shows a section that would render empty.
    $tabs = [
        ['id' => 'employment', 'label' => 'Employment', 'icon' => 'briefcase'],
        ['id' => 'reporting', 'label' => 'Reporting', 'icon' => 'users'],
    ];
    if ($canViewPrivate) {
        $tabs[] = ['id' => 'time-off', 'label' => 'Time off', 'icon' => 'leave'];
        $tabs[] = ['id' => 'attendance', 'label' => 'Attendance', 'icon' => 'clock'];
    }

    // The strip only has room for a single row, and the administrative sections
    // are the ones nobody opens twice a day, so they fold into a menu at the end
    // of it rather than pushing the whole strip onto a second line. They stay
    // one press of that menu away — the menu holds them directly, with no
    // submenu and nothing to scroll past.
    $menuTabs = [];
    if ($canReissueAttendanceQr) {
        $menuTabs[] = ['id' => 'badge', 'label' => 'Attendance badge', 'icon' => 'fingerprint'];
    }
    if ($canResetTwoFactor) {
        $menuTabs[] = ['id' => 'security', 'label' => 'Security', 'icon' => 'shield'];
    }
@endphp

<section class="panel employee-record">
        <header class="employee-record-header">
            <div class="employee-record-identity">
                <span class="avatar employee-record-avatar">{{ $initials }}</span>
                <div class="employee-record-headline">
                    {{-- Name and identifier only. That this is an employee is
                         what the directory is; the employment status is a row of
                         its own in the rail, and saying it twice a hand's width
                         apart only made the header louder. --}}
                    <h2 class="employee-record-name">{{ $employee->full_name }}</h2>
                    <p class="employee-record-id">Employee ID: <span>{{ $employee->employee_number }}</span></p>
                </div>
            </div>
            <div class="employee-record-actions">
                {{-- Outlined next to Edit, solid when it is the only thing this
                     viewer can do, so the header always has one filled button. --}}
                @if($canEmail)
                    <a class="btn {{ $canManage ? 'btn-outline-primary' : 'btn-primary' }} dashboard-action" href="mailto:{{ $account->email }}"><x-icon name="mail" /> Send email</a>
                @endif
                @if($canManage)
                    <a class="btn btn-primary dashboard-action" data-employee-edit href="{{ route('employees.edit', $employee) }}"><x-icon name="edit" /> Edit employee</a>
                @endif
            </div>
        </header>

        {{-- Said once, at the top, before anything else on the record is read.
             Everything below still works — the profile is meant to stay
             readable — so without this line a viewer sees an ordinary record
             and cannot tell why the person is missing from every picker. --}}
        @if($employee->isArchived())
            <div class="attendance-alert attendance-alert-warning employee-record-archived" role="status">
                <x-icon name="alert" />
                <span>
                    This record is archived, so {{ $employee->first_name }} is not offered for scheduling and cannot sign in.
                    Archived {{ $employee->archived_at?->timezone(config('schedule.timezone'))->format('j M Y') }}@if($employee->archiver) by {{ $employee->archiver->name }}@endif.
                </span>
            </div>
        @endif

        <div class="employee-record-body">
            {{-- The information rail leads, the way the reference puts the facts
                 about a person to the left of whatever section is open. --}}
            <aside class="employee-record-side">
                <section class="employee-side-group">
                    <h3><x-icon name="users" /> Personal Information</h3>
                    <dl class="employee-side-list">
                        <div><dt><x-icon name="calendar" /> Hire date</dt><dd>{{ $hireDate?->format('j M, Y') ?? 'Not recorded' }}</dd></div>
                        <div><dt><x-icon name="clock" /> Service</dt><dd>{{ $tenure ?? 'Not yet counted' }}</dd></div>
                        <div><dt><x-icon name="check-circle" /> Status</dt><dd>{{ str($employee->employment_status)->replace('_', ' ')->headline() }}@if($employee->isArchived()) · Archived @endif</dd></div>
                        <div><dt><x-icon name="users" /> Gender</dt><dd>{{ $employee->gender ? ucfirst($employee->gender) : 'Not recorded' }}</dd></div>
                        {{-- A solo parent ID is a government-issued number, so
                             it sits behind the same gate as the contact details
                             rather than on the open part of the record. --}}
                        @if($canViewPrivate)
                            <div><dt><x-icon name="shield" /> Solo parent ID</dt><dd>{{ $employee->soloParentSummary() }}@if($employee->solo_parent_id_expires_on && ! $employee->hasValidSoloParentId())<span class="employee-side-blank"> — solo parent leave withheld until renewed</span>@endif</dd></div>
                        @endif
                    </dl>
                </section>

                <section class="employee-side-group">
                    <h3><x-icon name="map-pin" /> Address &amp; Contact Information</h3>
                    @if($canViewPrivate)
                        <dl class="employee-side-list">
                            {{-- A work address is wider than the rail, so it is
                                 given a break opportunity after the @: without
                                 one the only place left to wrap is the middle of
                                 the domain. --}}
                            <div><dt><x-icon name="mail" /> Email</dt><dd>@if($account?->email)<a class="employee-side-pill" href="mailto:{{ $account->email }}">{!! str_replace('@', '@<wbr>', e($account->email)) !!}</a>@else<span class="employee-side-blank">Not recorded</span>@endif</dd></div>
                            <div><dt><x-icon name="phone" /> Phone</dt><dd>@if($employee->contact_number)<a class="employee-side-pill" href="tel:{{ $employee->contact_number }}">{{ $employee->contact_number }}</a>@else<span class="employee-side-blank">Not recorded</span>@endif</dd></div>
                            <div><dt><x-icon name="map-pin" /> Location</dt><dd>{{ $employee->address ?: 'Not recorded' }}</dd></div>
                        </dl>
                    @else
                        <p class="employee-side-restricted"><x-icon name="lock" /> Contact details are visible to HR managers and to the employee themselves.</p>
                    @endif
                </section>

                <section class="employee-side-group">
                    <h3><x-icon name="briefcase" /> Employment Information</h3>
                    <dl class="employee-side-list">
                        <div><dt><x-icon name="building" /> Department</dt><dd>{{ $employee->department?->name ?? 'Unassigned' }}</dd></div>
                        <div><dt><x-icon name="briefcase" /> Position</dt><dd>{{ $employee->position?->title ?? 'Unassigned' }}</dd></div>
                        <div><dt><x-icon name="users" /> Supervisor</dt><dd>{{ $employee->supervisor?->full_name ?? 'None assigned' }}</dd></div>
                    </dl>
                </section>

                @if($canViewPrivate)
                    <section class="employee-side-group">
                        <h3><x-icon name="lock" /> Account &amp; Access</h3>
                        <dl class="employee-side-list">
                            <div><dt><x-icon name="lock" /> Access</dt><dd>{{ $account?->is_active ? 'Enabled' : 'Disabled' }}</dd></div>
                            <div><dt><x-icon name="shield" /> Two-factor</dt><dd>{{ $account?->two_factor_secret ? 'Enabled' : 'Not enabled' }}</dd></div>
                            <div><dt><x-icon name="clock" /> Last sign-in</dt><dd>{{ $account?->last_login_at?->format('M j, Y · g:i A') ?? 'No recorded sign-in' }}</dd></div>
                        </dl>
                    </section>
                @endif
            </aside>

            <div class="employee-record-main">
                <nav class="employee-record-tabs" role="tablist" aria-label="Employee record sections">
                    @foreach($tabs as $tab)
                        <button
                            class="employee-record-tab @if($loop->first) active @endif"
                            id="employee-tab-{{ $tab['id'] }}"
                            type="button"
                            role="tab"
                            data-bs-toggle="tab"
                            data-bs-target="#employee-pane-{{ $tab['id'] }}"
                            aria-controls="employee-pane-{{ $tab['id'] }}"
                            aria-selected="{{ $loop->first ? 'true' : 'false' }}"
                        ><x-icon :name="$tab['icon']" /> <span>{{ $tab['label'] }}</span></button>
                    @endforeach

                    @if($menuTabs)
                        {{-- .nav-item and .dropdown are what Bootstrap's tab plugin
                             looks for to carry the selection out to the toggle: pick a
                             section in here and the button underlines like any other
                             tab. .dropdown-toggle is what keeps the button itself from
                             being counted as one. --}}
                        <div class="nav-item dropdown dashboard-action-menu employee-record-tab-menu">
                            <button
                                class="employee-record-tab dropdown-toggle"
                                type="button"
                                data-bs-toggle="dropdown"
                                data-bs-auto-close="true"
                                data-dashboard-action-menu
                                aria-expanded="false"
                                aria-label="More sections"
                            ><x-icon name="more" /></button>
                            <div class="dropdown-menu dropdown-menu-end">
                                @foreach($menuTabs as $tab)
                                    <button
                                        class="dropdown-item"
                                        id="employee-tab-{{ $tab['id'] }}"
                                        type="button"
                                        role="tab"
                                        data-bs-toggle="tab"
                                        data-bs-target="#employee-pane-{{ $tab['id'] }}"
                                        aria-controls="employee-pane-{{ $tab['id'] }}"
                                        aria-selected="false"
                                    ><x-icon :name="$tab['icon']" /> <span>{{ $tab['label'] }}</span></button>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </nav>

                <div class="tab-content employee-record-panes">
                    <div class="tab-pane fade show active" id="employee-pane-employment" role="tabpanel" aria-labelledby="employee-tab-employment" tabindex="0">
                        <article class="employee-card">
                            <div class="employee-card-top">
                                <span class="employee-card-mark">{{ $employee->department?->code ?? '—' }}</span>
                                <div class="employee-card-title">
                                    <h3>{{ $employee->department?->name ?? 'Unassigned' }}</h3>
                                    <p>Department</p>
                                </div>
                            </div>
                            <div class="employee-card-foot">
                                <dl class="employee-card-meta">
                                    <div><dt>Category</dt><dd>{{ str($employee->department?->category ?? 'Not set')->headline() }}</dd></div>
                                    <div><dt>Status</dt><dd>{{ $employee->department?->is_active ? 'Active' : 'Inactive' }}</dd></div>
                                </dl>
                            </div>
                        </article>

                        <article class="employee-card">
                            <div class="employee-card-top">
                                <span class="employee-card-mark">{{ $employee->position?->code ?? '—' }}</span>
                                <div class="employee-card-title">
                                    <h3>{{ $employee->position?->title ?? 'Unassigned' }}</h3>
                                    <p>Position</p>
                                </div>
                            </div>
                            <div class="employee-card-foot">
                                <dl class="employee-card-meta">
                                    <div><dt>Seniority rank</dt><dd>{{ $employee->position?->seniority_rank ?? 'Not set' }}</dd></div>
                                    <div><dt>Employee ID</dt><dd>{{ $employee->employee_number }}</dd></div>
                                </dl>
                            </div>
                        </article>

                        <article class="employee-card">
                            <div class="employee-card-top">
                                <span class="employee-card-mark"><x-icon name="calendar" /></span>
                                <div class="employee-card-title">
                                    <h3>{{ $hireDate?->format('F j, Y') ?? 'Hire date not recorded' }}</h3>
                                    <p>{{ $tenure ? $tenure.' with the hospital' : 'Service length not yet counted' }}</p>
                                </div>
                            </div>
                            <div class="employee-card-foot">
                                <dl class="employee-card-meta">
                                    <div><dt>Employment status</dt><dd>{{ str($employee->employment_status)->replace('_', ' ')->headline() }}</dd></div>
                                    <div><dt>Account access</dt><dd>{{ $account?->is_active ? 'Enabled' : 'Disabled' }}</dd></div>
                                </dl>
                            </div>
                        </article>
                    </div>

                    <div class="tab-pane fade" id="employee-pane-reporting" role="tabpanel" aria-labelledby="employee-tab-reporting" tabindex="0">
                        @if($employee->supervisor)
                            <article class="employee-card">
                                <div class="employee-card-top">
                                    <span class="avatar employee-card-avatar">{{ strtoupper(substr($employee->supervisor->first_name, 0, 1).substr($employee->supervisor->last_name, 0, 1)) }}</span>
                                    <div class="employee-card-title">
                                        <h3>{{ $employee->supervisor->full_name }}</h3>
                                        <p>Reports to · {{ $employee->supervisor->position?->title ?? 'Position unassigned' }}</p>
                                    </div>
                                </div>
                                <div class="employee-card-foot">
                                    <dl class="employee-card-meta">
                                        <div><dt>Employee ID</dt><dd>{{ $employee->supervisor->employee_number }}</dd></div>
                                    </dl>
                                    <a class="btn btn-light employee-card-action" data-employee-panel href="{{ route('employees.show', $employee->supervisor) }}">View details</a>
                                </div>
                            </article>
                        @else
                            <p class="employee-record-empty"><x-icon name="users" /> No supervisor is assigned to this employee.</p>
                        @endif

                        <p class="employee-record-subhead">Direct reports <span>{{ $directReports->count() }}</span></p>

                        @forelse($directReports as $report)
                            <article class="employee-card">
                                <div class="employee-card-top">
                                    <span class="avatar employee-card-avatar">{{ strtoupper(substr($report->first_name, 0, 1).substr($report->last_name, 0, 1)) }}</span>
                                    <div class="employee-card-title">
                                        <h3>{{ $report->full_name }}</h3>
                                        <p>{{ $report->position?->title ?? 'Position unassigned' }}</p>
                                    </div>
                                </div>
                                <div class="employee-card-foot">
                                    <dl class="employee-card-meta">
                                        <div><dt>Employee ID</dt><dd>{{ $report->employee_number }}</dd></div>
                                        <div><dt>Status</dt><dd>{{ str($report->employment_status)->replace('_', ' ')->headline() }}</dd></div>
                                    </dl>
                                    <a class="btn btn-light employee-card-action" data-employee-panel href="{{ route('employees.show', $report) }}">View details</a>
                                </div>
                            </article>
                        @empty
                            <p class="employee-record-empty"><x-icon name="users" /> Nobody currently reports to this employee.</p>
                        @endforelse
                    </div>

                    @if($canViewPrivate)
                        <div class="tab-pane fade" id="employee-pane-time-off" role="tabpanel" aria-labelledby="employee-tab-time-off" tabindex="0">
                            <article class="employee-card">
                                <div class="employee-card-top">
                                    <span class="employee-card-mark"><x-icon name="leave" /></span>
                                    <div class="employee-card-title">
                                        <h3>Leave credits</h3>
                                        <p>{{ $timeOff['year'] }} entitlement</p>
                                    </div>
                                </div>
                                @if($timeOff['balances']->isNotEmpty())
                                    <div class="employee-card-table">
                                        <table class="dashboard-table dashboard-table-fit">
                                            <caption class="visually-hidden">Leave credits for {{ $timeOff['year'] }}</caption>
                                            <thead>
                                                <tr><th scope="col">Leave type</th><th scope="col">Available</th><th scope="col">Used</th><th scope="col">Entitled</th></tr>
                                            </thead>
                                            <tbody>
                                                @foreach($timeOff['balances'] as $balance)
                                                    <tr>
                                                        <th scope="row">{{ $balance->leaveType?->name ?? 'Unknown type' }}</th>
                                                        <td><b>{{ $days($balance->available_days) }}</b></td>
                                                        <td>{{ $days($balance->used_days) }}</td>
                                                        {{-- What was carried in counts towards the year's entitlement, so it is shown as one figure rather than two. --}}
                                                        <td>{{ $days((float) $balance->entitled_days + (float) $balance->carried_over_days) }}</td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                @else
                                    <div class="employee-card-inner"><p class="employee-record-empty"><x-icon name="leave" /> No leave credits have been opened for {{ $timeOff['year'] }}.</p></div>
                                @endif
                            </article>

                            <p class="employee-record-subhead">Recent requests <span>{{ $timeOff['requests']->count() }}</span></p>

                            @forelse($timeOff['requests'] as $leaveRequest)
                                <article class="employee-card">
                                    <div class="employee-card-top">
                                        <span class="employee-card-mark"><x-icon name="leave" /></span>
                                        <div class="employee-card-title">
                                            <h3>{{ $leaveRequest->leaveType?->name ?? 'Leave' }}</h3>
                                            <p>{{ $leaveRequest->start_date?->format('j M Y') }} – {{ $leaveRequest->end_date?->format('j M Y') }}</p>
                                        </div>
                                        <x-status-badge :status="str($leaveRequest->lifecycle_status)->replace('_', ' ')" />
                                    </div>
                                    <div class="employee-card-foot">
                                        <dl class="employee-card-meta">
                                            <div><dt>Days</dt><dd>{{ $days($leaveRequest->requested_days) }}</dd></div>
                                            <div><dt>Filed</dt><dd>{{ $leaveRequest->created_at?->format('j M Y') ?? 'Not recorded' }}</dd></div>
                                        </dl>
                                    </div>
                                </article>
                            @empty
                                <p class="employee-record-empty"><x-icon name="leave" /> This employee has not filed any leave.</p>
                            @endforelse
                        </div>

                        <div class="tab-pane fade" id="employee-pane-attendance" role="tabpanel" aria-labelledby="employee-tab-attendance" tabindex="0">
                            <article class="employee-card">
                                <div class="employee-card-top">
                                    <span class="employee-card-mark"><x-icon name="clock" /></span>
                                    <div class="employee-card-title">
                                        <h3>Recent attendance</h3>
                                        <p>Most recent recorded days</p>
                                    </div>
                                </div>
                                @if($attendance['records']->isNotEmpty())
                                    <div class="employee-card-table">
                                        <table class="dashboard-table dashboard-table-fit">
                                            <caption class="visually-hidden">Most recent attendance records for {{ $employee->full_name }}</caption>
                                            <thead>
                                                <tr><th scope="col">Date</th><th scope="col">In</th><th scope="col">Out</th><th scope="col">Status</th></tr>
                                            </thead>
                                            <tbody>
                                                @foreach($attendance['records'] as $record)
                                                    <tr>
                                                        <th scope="row">{{ $record->attendance_date?->format('j M Y') ?? 'Undated' }}</th>
                                                        <td>{{ $clock($record->check_in_at, $record) }}</td>
                                                        <td>{{ $clock($record->check_out_at, $record) }}</td>
                                                        <td>{{ str($record->status)->replace('_', ' ')->headline() }}@if($record->late_minutes) · {{ $record->late_minutes }}m late @endif</td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                @else
                                    <div class="employee-card-inner"><p class="employee-record-empty"><x-icon name="clock" /> No attendance has been recorded for this employee yet.</p></div>
                                @endif
                            </article>

                            <p class="employee-record-subhead">Upcoming shifts <span>{{ $attendance['upcoming']->count() }}</span></p>

                            @forelse($attendance['upcoming'] as $assignment)
                                <article class="employee-card">
                                    <div class="employee-card-top">
                                        <span class="employee-card-mark"><x-icon name="calendar" /></span>
                                        <div class="employee-card-title">
                                            <h3>{{ $assignment->work_date?->format('l, j F Y') ?? 'Undated' }}</h3>
                                            <p>{{ $assignment->shift?->name ?? 'Shift unassigned' }}</p>
                                        </div>
                                        <x-status-badge :status="str($assignment->status)->replace('_', ' ')" />
                                    </div>
                                    <div class="employee-card-foot">
                                        <dl class="employee-card-meta">
                                            <div><dt>Hours</dt><dd>@if($assignment->shift){{ \Illuminate\Support\Carbon::parse($assignment->shift->start_time)->format('g:i A') }} – {{ \Illuminate\Support\Carbon::parse($assignment->shift->end_time)->format('g:i A') }}@else Not set @endif</dd></div>
                                            <div><dt>Shift code</dt><dd>{{ $assignment->shift?->code ?? 'Not set' }}</dd></div>
                                        </dl>
                                    </div>
                                </article>
                            @empty
                                <p class="employee-record-empty"><x-icon name="calendar" /> Nothing is rostered for this employee from today onwards.</p>
                            @endforelse
                        </div>
                    @endif

                    @if($canReissueAttendanceQr)
                        <div class="tab-pane fade" id="employee-pane-badge" role="tabpanel" aria-labelledby="employee-tab-badge" tabindex="0">
                            <article class="employee-card employee-qr-panel">
                                <div class="employee-card-top">
                                    <span class="employee-card-mark"><x-icon name="fingerprint" /></span>
                                    <div class="employee-card-title">
                                        <h3>Attendance badge</h3>
                                        <p>Time &amp; attendance</p>
                                    </div>
                                </div>
                                <div class="profile-qr-body">
                                    <figure class="profile-qr-code" role="img" aria-label="Attendance QR code for {{ $employee->full_name }}">{!! $attendanceQrSvg !!}</figure>
                                    <div class="profile-qr-copy">
                                        <p>{{ $employee->full_name }} presents this at the entrance scanner. They can download it themselves from My Profile.</p>
                                        <p class="profile-qr-warning"><x-icon name="shield" /> <span>Issue a new badge if this one has been lost, shared, or photographed. Every printed copy of their current code stops scanning immediately, and they will need to download the replacement.</span></p>
                                        <form method="POST" action="{{ route('employees.attendance-qr.reissue', $employee) }}" data-confirm="Issue a new attendance badge for {{ $employee->full_name }}? Their current code will stop working immediately.">
                                            @csrf
                                            <button class="btn btn-outline-primary" type="submit"><x-icon name="refresh" /> Issue new badge</button>
                                        </form>
                                    </div>
                                </div>
                            </article>
                        </div>
                    @endif

                    @if($canResetTwoFactor)
                        <div class="tab-pane fade" id="employee-pane-security" role="tabpanel" aria-labelledby="employee-tab-security" tabindex="0">
                            <article class="employee-card">
                                <div class="employee-card-top">
                                    <span class="employee-card-mark"><x-icon name="shield" /></span>
                                    <div class="employee-card-title">
                                        <h3>Reset employee two-factor authentication</h3>
                                        <p>Security recovery</p>
                                    </div>
                                </div>
                                <div class="employee-card-inner">
                                    <div class="settings-security-note"><x-icon name="shield" /><p>Use only after verifying the employee’s identity outside this system. The reset signs out existing database sessions, revokes API tokens, and is recorded in Audit Logs.</p></div>
                                    <form method="POST" action="{{ route('employees.two-factor.reset', $employee) }}" class="profile-settings-form">
                                        @csrf
                                        <label><span>Your administrator password</span><input type="password" name="current_password" autocomplete="current-password" required></label>
                                        <label class="two-factor-identity-check"><input type="checkbox" name="identity_verified" value="1" required><span>I confirm that I verified this employee’s identity using the hospital’s approved process.</span></label>
                                        <button class="btn btn-danger" type="submit">Reset employee 2FA</button>
                                    </form>
                                </div>
                            </article>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </section>
