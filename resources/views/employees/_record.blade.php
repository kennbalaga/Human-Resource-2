{{-- The employee record card. Rendered as the body of the profile page and,
     with $inPanel, as the fragment the directory slides in over the list, so
     the two can never drift apart. --}}
@php
    $inPanel = $inPanel ?? false;
    $account = $employee->user;
    $initials = strtoupper(substr($employee->first_name, 0, 1).substr($employee->last_name, 0, 1));
    $employeeRole = $account?->roles->first()?->name;
    $hireDate = $employee->hire_date;
    $tenure = $hireDate?->isPast()
        ? $hireDate->diffForHumans(['syntax' => \Carbon\CarbonInterface::DIFF_ABSOLUTE, 'parts' => 2, 'join' => true])
        : null;
    $directReports = $employee->directReports;

    // The record header carries the two affordances the reference layout has:
    // one primary action, everything else folded behind the overflow menu.
    $menuItems = [
        $inPanel
            ? ['label' => 'Open full profile', 'icon' => 'chevron-right', 'url' => route('employees.show', $employee)]
            : ['label' => 'Back to directory', 'icon' => 'users', 'url' => route('employees.index')],
    ];
    if ($canViewPrivate && $account?->email) {
        $menuItems[] = ['label' => 'Send email', 'icon' => 'mail', 'url' => 'mailto:'.$account->email];
    }

    // Tabs are built from what this viewer is actually allowed to act on, so the
    // strip never shows a section that would render empty.
    $tabs = [
        ['id' => 'employment', 'label' => 'Employment', 'icon' => 'briefcase'],
        ['id' => 'reporting', 'label' => 'Reporting line', 'icon' => 'users'],
    ];
    if ($canReissueAttendanceQr) {
        $tabs[] = ['id' => 'badge', 'label' => 'Attendance badge', 'icon' => 'fingerprint'];
    }
    if ($canResetTwoFactor) {
        $tabs[] = ['id' => 'security', 'label' => 'Security', 'icon' => 'shield'];
    }
@endphp

<section class="panel employee-record">
        <header class="employee-record-header">
            <div class="employee-record-identity">
                <span class="avatar employee-record-avatar">{{ $initials }}</span>
                <div class="employee-record-headline">
                    <div class="employee-record-name">
                        <h2>{{ $employee->full_name }}</h2>
                        @if($employeeRole)<span class="employee-record-chip"><x-icon name="shield" /> {{ str($employeeRole)->headline() }}</span>@endif
                        <x-status-badge :status="str($employee->employment_status)->replace('_', ' ')" />
                    </div>
                    <dl class="employee-record-summary">
                        <div><dt>Employee ID</dt><dd>{{ $employee->employee_number }}</dd></div>
                        <div><dt>Hire date</dt><dd>{{ $hireDate?->format('j F Y') ?? 'Not recorded' }}</dd></div>
                        <div><dt>Assigned to</dt><dd>{{ $employee->department?->name ?? 'Unassigned' }}</dd></div>
                    </dl>
                </div>
            </div>
            <div class="employee-record-actions">
                <x-dashboard-action-menu label="More employee options" :items="$menuItems" />
                @if($canManage)
                    <a class="btn btn-primary dashboard-action" href="{{ route('employees.edit', $employee) }}"><x-icon name="edit" /> Edit employee</a>
                @elseif($canViewPrivate && $account?->email)
                    <a class="btn btn-primary dashboard-action" href="mailto:{{ $account->email }}"><x-icon name="mail" /> Send email</a>
                @endif
            </div>
        </header>

        <div class="employee-record-body">
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

            <aside class="employee-record-side">
                <section class="employee-side-group">
                    <h3>Personal Information</h3>
                    @if($canViewPrivate)
                        <dl class="employee-side-list">
                            <div class="is-stacked"><dt><x-icon name="mail" /> Email address</dt><dd>@if($account?->email)<a class="employee-side-pill" href="mailto:{{ $account->email }}">{{ $account->email }}</a>@else<span class="employee-side-blank">Not recorded</span>@endif</dd></div>
                            <div><dt><x-icon name="phone" /> Contact number</dt><dd>@if($employee->contact_number)<a class="employee-side-pill" href="tel:{{ $employee->contact_number }}">{{ $employee->contact_number }}</a>@else<span class="employee-side-blank">Not recorded</span>@endif</dd></div>
                            <div class="is-stacked"><dt><x-icon name="map-pin" /> Home address</dt><dd>{{ $employee->address ?: 'Not recorded' }}</dd></div>
                        </dl>
                    @else
                        <p class="employee-side-restricted"><x-icon name="lock" /> Contact details are visible to HR managers and to the employee themselves.</p>
                    @endif
                </section>

                <section class="employee-side-group">
                    <h3>Employment Information</h3>
                    <dl class="employee-side-list">
                        <div><dt><x-icon name="building" /> Department</dt><dd>{{ $employee->department?->name ?? 'Unassigned' }}</dd></div>
                        <div><dt><x-icon name="briefcase" /> Position</dt><dd>{{ $employee->position?->title ?? 'Unassigned' }}</dd></div>
                        <div><dt><x-icon name="users" /> Supervisor</dt><dd>{{ $employee->supervisor?->full_name ?? 'None assigned' }}</dd></div>
                        <div><dt><x-icon name="calendar" /> Hire date</dt><dd>{{ $hireDate?->format('F j, Y') ?? 'Not recorded' }}</dd></div>
                        <div><dt><x-icon name="check-circle" /> Employment status</dt><dd>{{ str($employee->employment_status)->replace('_', ' ')->headline() }}</dd></div>
                    </dl>
                </section>

                @if($canViewPrivate)
                    <section class="employee-side-group">
                        <h3>Account &amp; Access</h3>
                        <dl class="employee-side-list">
                            <div><dt><x-icon name="lock" /> Account access</dt><dd>{{ $account?->is_active ? 'Enabled' : 'Disabled' }}</dd></div>
                            <div><dt><x-icon name="shield" /> Two-factor</dt><dd>{{ $account?->two_factor_secret ? 'Enabled' : 'Not enabled' }}</dd></div>
                            <div><dt><x-icon name="clock" /> Last sign-in</dt><dd>{{ $account?->last_login_at?->format('M j, Y · g:i A') ?? 'No recorded sign-in' }}</dd></div>
                        </dl>
                    </section>
                @endif
            </aside>
        </div>
    </section>
