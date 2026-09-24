@extends('layouts.app')

@section('title', 'Account Settings')

@section('content')
    <section class="page-heading workforce-heading"><div><p class="eyebrow">Account</p><h1>Settings</h1><p>Your profile, appearance, notifications, and the operational tools you administer.</p></div><a class="btn btn-outline-primary profile-heading-action" href="{{ route('profile.show') }}"><x-icon name="users" /> View profile</a></section>

    @if(session('success'))<div class="attendance-alert attendance-alert-success"><x-icon name="check-circle" /><span>{{ session('success') }}</span></div>@endif
    @if(session('warning'))<div class="attendance-alert attendance-alert-warning"><x-icon name="settings" /><span>{{ session('warning') }}</span></div>@endif
    @if(session('two_factor_required'))<div class="attendance-alert attendance-alert-danger"><x-icon name="shield" /><span>{{ session('two_factor_required') }}</span></div>@endif
    @if($twoFactorSetupReset)<div class="attendance-alert attendance-alert-warning"><x-icon name="shield" /><span>An incomplete 2FA setup from another environment could not be decrypted and was safely reset. Start the setup again on this device.</span></div>@endif
    @if($errors->any())<div class="attendance-alert attendance-alert-danger"><x-icon name="close" /><span>{{ $errors->first() }}</span></div>@endif

    @php
        // Every system panel is optional, so the group only appears when the
        // signed-in role actually has one of them.
        $hasSystemSettings = $canManageEmployeeNumberSettings
            || $canManageTwoFactorEnforcement
            || $canManageNotificationEmails
            || $canManageAttendanceSettings
            || $canAccessSystemAdministration;
    @endphp

    <section class="settings-layout">
        <aside class="panel settings-section-nav" aria-label="Settings sections">
            <p class="settings-nav-group">Your account</p>
            <a href="#account"><x-icon name="users" /><span><strong>Account</strong><small>Email and identity</small></span></a>
            <a href="#preferences"><x-icon name="moon" /><span><strong>Appearance</strong><small>Theme, display, motion</small></span></a>
            <a href="#two-factor"><x-icon name="shield" /><span><strong>Two-factor security</strong><small>Authenticator and recovery</small></span></a>
            <a href="#security"><x-icon name="settings" /><span><strong>Password</strong><small>Password and API tokens</small></span></a>
            {{-- Mobile-only. app-lock.js sets data-available="true" on a touch
                 device; on a desktop this entry is never rendered visible. --}}
            <a href="#device-lock" data-device-lock-nav data-available="false"><x-icon name="lock" /><span><strong>App lock</strong><small>PIN and fingerprint on this device</small></span></a>

            @if($hasSystemSettings)
                <p class="settings-nav-group">System administration</p>
                @if($canManageEmployeeNumberSettings)<a href="#system-controls"><x-icon name="users" /><span><strong>Employee IDs</strong><small>Automatic ID generation</small></span></a>@endif
                @if($canManageTwoFactorEnforcement)<a href="#two-factor-enforcement"><x-icon name="shield" /><span><strong>2FA enforcement</strong><small>Require 2FA by role</small></span></a>@endif
                @if($canManageNotificationEmails)<a href="#notification-emails"><x-icon name="settings" /><span><strong>Notification emails</strong><small>Email delivery for all users</small></span></a>@endif
                @if($canManageAttendanceSettings)<a href="#attendance-capture"><x-icon name="clock" /><span><strong>Attendance capture</strong><small>Biometric and manual modes</small></span></a>@endif
                @if($canManageAttendanceSettings)<a href="#attendance-schedule"><x-icon name="calendar" /><span><strong>Schedule-aware attendance</strong><small>Roster-based lateness and overtime</small></span></a>@endif
                @if($canManageAttendanceSettings && $biometricSimulatorAvailable)<a href="#biometric-simulator"><x-icon name="settings" /><span><strong>Scanner simulator</strong><small>Local testing tool</small></span></a>@endif
                @if($canAccessSystemAdministration)<a href="#system-administration"><x-icon name="plug" /><span><strong>Operational tools</strong><small>Integrations and audit logs</small></span></a>@endif
            @endif
        </aside>

        <div class="settings-panels">
            <article class="panel settings-panel" id="account">
                <div class="panel-header"><div><p class="panel-kicker">Account identity</p><h2>Email address</h2></div><span class="settings-account-id">{{ $user->employee?->employee_number ?? 'User #'.$user->id }}</span></div>
                <form method="POST" action="{{ route('settings.account.update') }}" class="profile-settings-form settings-inline-form">
                    @csrf @method('PATCH')
                    <label><span>Email address</span><input type="email" name="email" value="{{ old('email', $user->email) }}" autocomplete="email" required maxlength="255"><small>Used for account identification and for the notification emails HRMS sends you.</small></label>
                    <button class="btn btn-primary" type="submit">Update email</button>
                </form>
            </article>

            <article class="panel settings-panel" id="preferences">
                {{-- Named for what the sidebar link that lands here calls it. "Preferences"
                     under an "Appearance" nav entry made the reader check they had
                     arrived. --}}
                <div class="panel-header"><div><p class="panel-kicker">Appearance</p><h2>How the app looks</h2></div><x-icon name="settings" /></div>
                <form method="POST" action="{{ route('settings.preferences.update') }}" class="profile-settings-form">
                    @csrf @method('PATCH')
                    <fieldset class="appearance-options">
                        <legend>Color theme</legend>
                        <p>System follows your device setting and changes with it. Your choice is saved to your account, so it travels to every device you sign in on.</p>
                        <div>
                            @foreach(['light' => ['sun', 'Light', 'Bright and clear'], 'dark' => ['moon', 'Dark', 'Easy on the eyes'], 'system' => ['settings', 'System', 'Follow this device']] as $value => [$icon, $label, $description])
                                <label class="appearance-option"><input type="radio" name="theme" value="{{ $value }}" @checked(old('theme', $preference->theme) === $value)><span><x-icon :name="$icon" /><strong>{{ $label }}</strong><small>{{ $description }}</small></span></label>
                            @endforeach
                        </div>
                    </fieldset>
                    <div class="settings-toggle-list">
                        @foreach([
                            'compact_navigation' => ['Compact navigation', 'Reduce spacing in the sidebar navigation.'],
                            'reduce_motion' => ['Reduce motion', 'Turns off transitions and the sidebar slide. Already on if your device asks for reduced motion.'],
                        ] as $field => [$title, $description])
                            <label class="settings-toggle"><span><strong>{{ $title }}</strong><small>{{ $description }}</small></span><input type="checkbox" name="{{ $field }}" value="1" @checked(old($field, $preference->{$field}))><i aria-hidden="true"></i></label>
                        @endforeach
                        {{-- Not a choice: the request forces this zone whatever is
                             posted, so it is stated instead of offered. It used to
                             ride along as a hidden field, which meant the one thing
                             every timestamp on the page depends on was invisible. --}}
                        <div class="settings-toggle is-static">
                            <span><strong>Time zone</strong><small>Used for your attendance timestamps and the clock on the dashboard.</small></span>
                            <span class="settings-static-value">{{ config('workforce.timezone', 'Asia/Manila') }} (PHT)</span>
                        </div>
                    </div>
                    {{-- Said here because the switches that used to sit in this
                         list are gone. Without a word, their absence reads as a
                         bug rather than a policy. --}}
                    <div class="settings-security-note"><x-icon name="settings" /><p>Attendance, schedule, and leave notifications are emailed to you as well as shown here. Notification email is set for everyone by a System Administrator, so it is not switched off per account.</p></div>
                    <button class="btn btn-primary" type="submit"><x-icon name="check-circle" /> Save preferences</button>
                </form>
            </article>

            <article class="panel settings-panel" id="two-factor">
                <div class="panel-header">
                    <div><p class="panel-kicker">Account protection</p><h2>Authenticator-app two-factor authentication</h2></div>
                    <span class="two-factor-status {{ $twoFactorEnabled ? 'is-enabled' : ($twoFactorPending ? 'is-pending' : '') }}">{{ $twoFactorEnabled ? 'Protected' : ($twoFactorPending ? 'Setup pending' : 'Not enabled') }}</span>
                </div>
                <div class="two-factor-intro"><x-icon name="shield" /><div><strong>{{ $twoFactorRequired ? 'Required for your role' : 'Optional extra protection' }}</strong><p>After your password, enter a rotating six-digit code from Google Authenticator, Microsoft Authenticator, Authy, or another TOTP-compatible app.</p></div></div>

                @if(session('two_factor_recovery_codes'))
                    <section class="two-factor-recovery-codes" aria-labelledby="recovery-codes-title">
                        <div><h3 id="recovery-codes-title">Save these recovery codes now</h3><p>Each code works once. Store them offline and never share them with HR or an administrator.</p></div>
                        <div class="recovery-code-grid">@foreach(session('two_factor_recovery_codes') as $recoveryCode)<code>{{ $recoveryCode }}</code>@endforeach</div>
                    </section>
                @endif

                @if($twoFactorPending)
                    <div class="two-factor-setup-grid">
                        <div class="two-factor-qr"><span>1. Scan this QR code</span><div>{!! $twoFactorQrCode !!}</div><small>The QR code contains your private setup key. Do not screenshot or share it.</small></div>
                        <form method="POST" action="{{ route('two-factor.settings.confirm') }}" class="profile-settings-form two-factor-confirm-form">
                            @csrf
                            <div class="two-factor-step"><strong>2. Confirm setup</strong><small>Enter the current six-digit code from your app. Protection is not active until this succeeds.</small></div>
                            <label><span>Current password</span><input type="password" name="current_password" autocomplete="current-password" required></label>
                            <label><span>6-digit authenticator code</span><input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,7}" maxlength="7" required></label>
                            <button class="btn btn-primary" type="submit"><x-icon name="check-circle" /> Confirm and enable</button>
                        </form>
                    </div>
                    <form method="POST" action="{{ route('two-factor.settings.disable') }}" class="two-factor-cancel-form">
                        @csrf @method('DELETE')
                        <label><span>Current password to cancel setup</span><input type="password" name="current_password" autocomplete="current-password" required></label>
                        <button class="btn btn-light" type="submit">Cancel setup</button>
                    </form>
                @elseif($twoFactorEnabled)
                    <div class="two-factor-actions">
                        <details><summary>Show recovery codes</summary><form method="POST" action="{{ route('two-factor.settings.recovery-codes.show') }}" class="profile-settings-form">@csrf<p>Confirm both factors before displaying your recovery codes.</p><label><span>Current password</span><input type="password" name="current_password" autocomplete="current-password" required></label><label><span>Authenticator or recovery code</span><input type="text" name="verification_code" autocomplete="one-time-code" required maxlength="50"></label><button class="btn btn-light" type="submit">Show codes</button></form></details>
                        <details><summary>Generate new recovery codes</summary><form method="POST" action="{{ route('two-factor.settings.recovery-codes.regenerate') }}" class="profile-settings-form">@csrf<p>All previous recovery codes will stop working immediately.</p><label><span>Current password</span><input type="password" name="current_password" autocomplete="current-password" required></label><label><span>Authenticator or recovery code</span><input type="text" name="verification_code" autocomplete="one-time-code" required maxlength="50"></label><button class="btn btn-light" type="submit">Regenerate codes</button></form></details>
                        <details class="two-factor-danger"><summary>Disable two-factor authentication</summary><form method="POST" action="{{ route('two-factor.settings.disable') }}" class="profile-settings-form">@csrf @method('DELETE')<p>This removes the second login check and revokes existing API tokens.</p><label><span>Current password</span><input type="password" name="current_password" autocomplete="current-password" required></label><label><span>Authenticator or recovery code</span><input type="text" name="verification_code" autocomplete="one-time-code" required maxlength="50"></label><button class="btn btn-danger" type="submit">Disable 2FA</button></form></details>
                    </div>
                @else
                    <form method="POST" action="{{ route('two-factor.settings.enable') }}" class="profile-settings-form two-factor-enable-form">
                        @csrf
                        <label><span>Current password</span><input type="password" name="current_password" autocomplete="current-password" required><small>Password confirmation prevents another person using an unlocked browser from enrolling their own authenticator.</small></label>
                        <button class="btn btn-primary" type="submit"><x-icon name="shield" /> Start secure setup</button>
                    </form>
                @endif
            </article>

            <article class="panel settings-panel" id="security">
                <div class="panel-header"><div><p class="panel-kicker">Account protection</p><h2>Change password</h2></div><x-icon name="shield" /></div>
                <div class="settings-security-note"><x-icon name="shield" /><p>Changing your password revokes all existing API tokens. Your current browser session remains signed in.</p></div>
                <form method="POST" action="{{ route('settings.password.update') }}" class="profile-settings-form settings-password-grid">
                    @csrf @method('PUT')
                    <label><span>Current password</span><input type="password" name="current_password" autocomplete="current-password" required></label>
                    <label><span>New password</span><input type="password" name="password" autocomplete="new-password" required><small>At least 12 characters with uppercase, lowercase, a number, and a symbol.</small></label>
                    <label><span>Confirm new password</span><input type="password" name="password_confirmation" autocomplete="new-password" required></label>
                    <button class="btn btn-primary" type="submit">Update password</button>
                </form>
            </article>

            @include('settings.partials.device-lock')

            @if($canManageEmployeeNumberSettings)
                <article class="panel settings-panel" id="system-controls">
                    <div class="panel-header"><div><p class="panel-kicker">Organization policy</p><h2>Employee ID generation</h2></div><x-status-badge :status="$employeeNumberAutoGenerate ? 'active' : 'inactive'" /></div>
                    <form method="POST" action="{{ route('settings.employee-numbers.update') }}" class="profile-settings-form">
                        @csrf @method('PATCH')
                        <div class="settings-toggle-list">
                            <label class="settings-toggle"><span><strong>Automatically generate employee IDs</strong><small>New employees receive the next position- and hire-year-based ID, such as NUR-HEAD-OPD-2026-0009 or HR-OFFICER-2026-0003.</small></span><input type="checkbox" name="auto_generate" value="1" @checked(old('auto_generate', $employeeNumberAutoGenerate))><i aria-hidden="true"></i></label>
                        </div>
                        <div class="settings-security-note"><x-icon name="shield" /><p>Generated IDs are concurrency-safe, never reuse deleted employee IDs, and remain permanent when an employee changes department or position.</p></div>
                        <small>
                            {{ $employeeNumberSettingSource === 'admin_setting' ? 'Controlled by the saved system setting' : 'Using the deployment default' }}
                            @if($employeeNumberSettingUpdatedBy) · Last changed by {{ $employeeNumberSettingUpdatedBy }}@endif
                        </small>
                        <button class="btn btn-primary" type="submit"><x-icon name="check-circle" /> Save system setting</button>
                    </form>
                </article>
            @endif

            @if($canManageTwoFactorEnforcement)
                <article class="panel settings-panel" id="two-factor-enforcement">
                    <div class="panel-header"><div><p class="panel-kicker">Security policy</p><h2>Two-factor enforcement</h2></div><x-status-badge :status="$twoFactorEnforcementEnabled ? 'active' : 'inactive'" /></div>
                    <form method="POST" action="{{ route('settings.two-factor-enforcement.update') }}" class="profile-settings-form">
                        @csrf @method('PATCH')
                        <div class="settings-toggle-list">
                            <label class="settings-toggle"><span><strong>Require two-factor authentication</strong><small>When enabled, System Administrator, HR Manager, and Department Head accounts must enroll in two-factor authentication before using the app.</small></span><input type="checkbox" name="enabled" value="1" @checked(old('enabled', $twoFactorEnforcementEnabled))><i aria-hidden="true"></i></label>
                        </div>
                        <div class="settings-security-note"><x-icon name="shield" /><p>Disabling this does not remove two-factor authentication already enrolled on individual accounts &mdash; it only pauses the requirement to enroll. Re-enable before deployment.</p></div>
                        <small>
                            {{ $twoFactorEnforcementEnabled ? 'Currently enforced' : 'Currently disabled system-wide' }}
                            @if($twoFactorEnforcementUpdatedBy) · Last changed by {{ $twoFactorEnforcementUpdatedBy }}@endif
                        </small>
                        <button class="btn btn-primary" type="submit"><x-icon name="check-circle" /> Save system setting</button>
                    </form>
                </article>
            @endif

            @if($canManageNotificationEmails)
                <article class="panel settings-panel" id="notification-emails">
                    <div class="panel-header"><div><p class="panel-kicker">Notification policy</p><h2>Notification emails</h2></div><x-status-badge :status="$notificationEmailsEnabled ? 'active' : 'inactive'" /></div>
                    <form method="POST" action="{{ route('settings.notification-emails.update') }}" class="profile-settings-form">
                        @csrf @method('PATCH')
                        <div class="settings-toggle-list">
                            <label class="settings-toggle"><span><strong>Email every notification</strong><small>When enabled, every attendance, schedule, and leave notification is also sent to the employee's email address, so they are reached without opening HRMS.</small></span><input type="checkbox" name="enabled" value="1" @checked(old('enabled', $notificationEmailsEnabled))><i aria-hidden="true"></i></label>
                        </div>
                        <div class="settings-security-note"><x-icon name="settings" /><p>This is the only switch for notification email &mdash; employees cannot turn it off for themselves. Security messages, such as a two-factor reset, are always sent regardless of this setting.</p></div>
                        <small>
                            {{ $notificationEmailsEnabled ? 'Currently emailing every employee' : 'Currently paused system-wide' }}
                            @if($notificationEmailsUpdatedBy) · Last changed by {{ $notificationEmailsUpdatedBy }}@endif
                        </small>
                        <button class="btn btn-primary" type="submit"><x-icon name="check-circle" /> Save system setting</button>
                    </form>
                </article>
            @endif

            @if($canManageAttendanceSettings)
                <article
                    class="panel settings-panel"
                    id="attendance-capture"
                    data-attendance-settings-sync
                    data-attendance-capture-state="{{ $attendanceCaptureState }}"
                    data-attendance-state-url="{{ route('attendance.state') }}"
                    @if ($attendanceCaptureMode === \App\Services\AttendanceCaptureSettings::EMERGENCY_MANUAL && $attendanceManualModeExpiresAt)
                        data-manual-mode-expires-at="{{ $attendanceManualModeExpiresAt->getTimestamp() }}"
                    @endif
                >
                    <div class="panel-header"><div><p class="panel-kicker">Attendance policy</p><h2>Attendance capture mode</h2></div><x-status-badge :status="$attendanceCaptureMode === 'biometric_only' ? 'active' : 'pending'" /></div>
                    <form method="POST" action="{{ route('settings.attendance-capture.update') }}" class="profile-settings-form">
                        @csrf @method('PATCH')
                        <fieldset class="appearance-options">
                            <legend>Allowed capture methods</legend>
                            <p>Changes apply immediately. Existing attendance records are not modified.</p>
                            <div>
                                @foreach([
                                    'biometric_only' => ['shield', 'Biometric only', 'Website check-in/out is disabled.'],
                                    'hybrid' => ['settings', 'Hybrid', 'Biometric and manual website attendance are allowed.'],
                                    'emergency_manual' => ['clock', 'Emergency manual', 'Biometric events pause and website attendance requires a reason.'],
                                ] as $value => [$icon, $label, $description])
                                    <label class="appearance-option"><input type="radio" name="capture_mode" value="{{ $value }}" @checked(old('capture_mode', $attendanceCaptureMode) === $value)><span><x-icon :name="$icon" /><strong>{{ $label }}</strong><small>{{ $description }}</small></span></label>
                                @endforeach
                            </div>
                        </fieldset>
                        <label><span>Emergency reason</span><textarea name="manual_mode_reason" rows="2" maxlength="1000" placeholder="Example: Front entrance scanner is offline">{{ old('manual_mode_reason', $attendanceManualModeReason) }}</textarea><small>Required only for Emergency Manual mode and shown to administrators.</small></label>
                        <label><span>Emergency mode expiry (Philippine time)</span><input type="datetime-local" name="manual_mode_expires_at" value="{{ old('manual_mode_expires_at', $attendanceManualModeExpiresAt?->copy()->timezone(config('workforce.timezone'))->format('Y-m-d\TH:i')) }}"><small>Required for Emergency Manual mode. When it expires, the effective mode automatically returns to Biometric Only.</small></label>
                        <div class="settings-security-note"><x-icon name="shield" /><p>Manual entries remain pending for review and are labeled separately from biometric scans. Every mode change is included in the audit log.</p></div>
                        @if($attendanceSettingUpdatedBy)<small>Last changed by {{ $attendanceSettingUpdatedBy }}</small>@endif
                        <button class="btn btn-primary" type="submit"><x-icon name="check-circle" /> Save attendance mode</button>
                    </form>
                </article>

                <article class="panel settings-panel" id="attendance-schedule">
                    <div class="panel-header"><div><p class="panel-kicker">Attendance policy</p><h2>Schedule-aware attendance</h2></div><x-status-badge :status="$attendanceScheduleAware ? 'active' : 'pending'" /></div>
                    <form method="POST" action="{{ route('settings.attendance-schedule.update') }}" class="profile-settings-form">
                        @csrf @method('PATCH')
                        <div class="settings-security-note"><x-icon name="shield" /><p>Turning on <strong>Schedule-aware timing</strong> changes payroll-adjacent lateness/overtime figures for every future punch — flip it only after reviewing the shadow-mode data these columns have been collecting. Turning on <strong>Enforce published shift</strong> refuses a check-in with no published shift unless a manager authorises it from the <a href="{{ route('attendance.override.index') }}">attendance override</a> page.</p></div>
                        <label><span>Early window (minutes)</span><input type="number" name="early_window_minutes" min="0" max="1440" value="{{ old('early_window_minutes', $attendanceScheduleEarlyWindowMinutes) }}" required><small>How long before a shift's start a punch still binds to it.</small></label>
                        <label><span>Grace period (minutes)</span><input type="number" name="grace_minutes" min="0" max="1440" value="{{ old('grace_minutes', $attendanceScheduleGraceMinutes) }}" required><small>Lateness grace after shift start.</small></label>
                        <label><span>Late-bind window (minutes)</span><input type="number" name="late_bind_minutes" min="0" max="1440" value="{{ old('late_bind_minutes', $attendanceScheduleLateBindMinutes) }}" required><small>How long after shift start a punch can still bind rather than being treated as off-shift.</small></label>
                        <fieldset class="appearance-options">
                            <legend>Flags</legend>
                            <label class="appearance-option"><input type="checkbox" name="schedule_aware" value="1" @checked(old('schedule_aware', $attendanceScheduleAware))><span><x-icon name="calendar" /><strong>Schedule-aware timing</strong><small>Lateness, overtime and undertime derive from the published shift instead of office hours.</small></span></label>
                            <label class="appearance-option"><input type="checkbox" name="enforce_published_shift" value="1" @checked(old('enforce_published_shift', $attendanceScheduleEnforcePublishedShift))><span><x-icon name="shield" /><strong>Enforce published shift</strong><small>Refuse a check-in with no published shift unless a manager authorises it.</small></span></label>
                        </fieldset>
                        @if($attendanceScheduleSettingUpdatedBy)<small>Last changed by {{ $attendanceScheduleSettingUpdatedBy }}</small>@endif
                        <button class="btn btn-primary" type="submit"><x-icon name="check-circle" /> Save schedule-aware settings</button>
                    </form>
                </article>

                @if($biometricSimulatorAvailable)
                    <article class="panel settings-panel" id="biometric-simulator">
                        <div class="panel-header"><div><p class="panel-kicker">Development tool</p><h2>Biometric scanner simulator</h2></div><x-status-badge status="testing" /></div>
                        <div class="settings-security-note"><x-icon name="shield" /><p>This simulator is available only in local/testing environments. It creates device events and attendance records without collecting or storing fingerprints.</p></div>
                        <form method="POST" action="{{ route('settings.biometric-simulator.store') }}" class="profile-settings-form settings-inline-form">
                            @csrf
                            <label><span>Simulated fingerprint identity</span><select name="employee_id" required><option value="">Select an enrolled employee</option>@foreach($biometricSimulatorEmployees as $simulatorEmployee)<option value="{{ $simulatorEmployee->id }}">{{ $simulatorEmployee->employee_number }} · {{ $simulatorEmployee->full_name }}</option>@endforeach</select></label>
                            <label><span>Scan action</span><select name="event_type" required><option value="check_in">Check in</option><option value="check_out">Check out</option></select></label>
                            <button class="btn btn-primary" type="submit"><x-icon name="clock" /> Simulate fingerprint scan</button>
                        </form>

                        @if($recentBiometricEvents->isNotEmpty())
                            <div class="table-responsive">
                                <table class="dashboard-table">
                                    <thead><tr><th>Received</th><th>Employee</th><th>Event</th><th>Device</th><th>Status</th></tr></thead>
                                    <tbody>
                                        @foreach($recentBiometricEvents as $biometricEvent)
                                            <tr><td>{{ $biometricEvent->received_at->format('M j, g:i:s A') }}</td><td>{{ $biometricEvent->employee?->full_name ?? 'Unmatched identity' }}</td><td>{{ str($biometricEvent->event_type)->replace('_', ' ')->title() }}</td><td>{{ $biometricEvent->device->name }}</td><td><x-status-badge :status="$biometricEvent->status" /></td></tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </article>
                @endif
            @endif

            @if($canAccessSystemAdministration)
                <article class="panel settings-panel" id="system-administration">
                    <div class="panel-header"><div><p class="panel-kicker">System administration</p><h2>Operational tools</h2></div><x-icon name="shield" /></div>
                    <div class="settings-resource-links">
                        <a href="{{ route('integrations.index') }}"><span class="settings-resource-icon"><x-icon name="plug" /></span><span><strong>Integrations</strong><small>Manage AI scheduling and the Gemini connection.</small></span><x-icon name="chevron-right" /></a>
                        <a href="{{ route('audit-logs.index') }}"><span class="settings-resource-icon"><x-icon name="report" /></span><span><strong>Audit logs</strong><small>Review security and compliance activity.</small></span><x-icon name="chevron-right" /></a>
                    </div>
                </article>
            @endif
        </div>
    </section>
@endsection
