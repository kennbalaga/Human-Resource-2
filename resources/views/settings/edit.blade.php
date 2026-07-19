@extends('layouts.app')

@section('title', 'Account Settings')

@section('content')
    <section class="page-heading workforce-heading"><div><p class="eyebrow">Personal preferences</p><h1>Account Settings</h1><p>Manage account security, notifications, timezone, and interface preferences.</p></div><a class="btn btn-outline-primary profile-heading-action" href="{{ route('profile.show') }}"><x-icon name="users" /> View profile</a></section>

    @if(session('success'))<div class="attendance-alert attendance-alert-success"><x-icon name="check-circle" /><span>{{ session('success') }}</span></div>@endif
    @if(session('warning'))<div class="attendance-alert attendance-alert-warning"><x-icon name="settings" /><span>{{ session('warning') }}</span></div>@endif
    @if(session('two_factor_required'))<div class="attendance-alert attendance-alert-danger"><x-icon name="shield" /><span>{{ session('two_factor_required') }}</span></div>@endif
    @if($twoFactorSetupReset)<div class="attendance-alert attendance-alert-warning"><x-icon name="shield" /><span>An incomplete 2FA setup from another environment could not be decrypted and was safely reset. Start the setup again on this device.</span></div>@endif
    @if($errors->any())<div class="attendance-alert attendance-alert-danger"><x-icon name="close" /><span>{{ $errors->first() }}</span></div>@endif

    <section class="settings-layout">
        <aside class="panel settings-section-nav" aria-label="Settings sections">
            <a href="#account"><x-icon name="users" /><span><strong>Account</strong><small>Email and identity</small></span></a>
            @if($canManageEmployeeNumberSettings || $canManageAttendanceSettings)<a href="#system-controls"><x-icon name="settings" /><span><strong>System controls</strong><small>IDs and attendance capture</small></span></a>@endif
            <a href="#preferences"><x-icon name="moon" /><span><strong>Appearance</strong><small>Theme and display</small></span></a>
            <a href="#two-factor"><x-icon name="shield" /><span><strong>Two-factor security</strong><small>Authenticator and recovery</small></span></a>
            <a href="#security"><x-icon name="settings" /><span><strong>Password</strong><small>Password and API tokens</small></span></a>
        </aside>

        <div class="settings-panels">
            <article class="panel settings-panel" id="account">
                <div class="panel-header"><div><p class="panel-kicker">Account identity</p><h2>Email address</h2></div><span class="settings-account-id">{{ $user->employee?->employee_number ?? 'User #'.$user->id }}</span></div>
                <form method="POST" action="{{ route('settings.account.update') }}" class="profile-settings-form settings-inline-form">
                    @csrf @method('PATCH')
                    <label><span>Email address</span><input type="email" name="email" value="{{ old('email', $user->email) }}" autocomplete="email" required maxlength="255"><small>Used for account identification and future email notifications.</small></label>
                    <button class="btn btn-primary" type="submit">Update email</button>
                </form>
            </article>

            @if($canManageEmployeeNumberSettings)
                <article class="panel settings-panel" id="system-controls">
                    <div class="panel-header"><div><p class="panel-kicker">Organization policy</p><h2>Employee ID generation</h2></div><x-status-badge :status="$employeeNumberAutoGenerate ? 'active' : 'inactive'" /></div>
                    <form method="POST" action="{{ route('settings.employee-numbers.update') }}" class="profile-settings-form">
                        @csrf @method('PATCH')
                        <div class="settings-toggle-list">
                            <label class="settings-toggle"><span><strong>Automatically generate employee IDs</strong><small>New employees receive the next department-based ID, such as HR-0003 or NUR-0002.</small></span><input type="checkbox" name="auto_generate" value="1" @checked(old('auto_generate', $employeeNumberAutoGenerate))><i aria-hidden="true"></i></label>
                        </div>
                        <div class="settings-security-note"><x-icon name="shield" /><p>Generated IDs are concurrency-safe, never reuse deleted employee IDs, and remain permanent when an employee changes department.</p></div>
                        <small>
                            {{ $employeeNumberSettingSource === 'admin_setting' ? 'Controlled by the saved system setting' : 'Using the deployment default' }}
                            @if($employeeNumberSettingUpdatedBy) · Last changed by {{ $employeeNumberSettingUpdatedBy }}@endif
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

            <article class="panel settings-panel" id="preferences">
                <div class="panel-header"><div><p class="panel-kicker">Workspace behavior</p><h2>Preferences</h2></div><x-icon name="settings" /></div>
                <form method="POST" action="{{ route('settings.preferences.update') }}" class="profile-settings-form">
                    @csrf @method('PATCH')
                    <fieldset class="appearance-options">
                        <legend>Color theme</legend>
                        <p>Choose a theme or follow your device appearance automatically.</p>
                        <div>
                            @foreach(['light' => ['sun', 'Light', 'Bright and clear'], 'dark' => ['moon', 'Dark', 'Easy on the eyes'], 'system' => ['settings', 'System', 'Follow this device']] as $value => [$icon, $label, $description])
                                <label class="appearance-option"><input type="radio" name="theme" value="{{ $value }}" @checked(old('theme', $preference->theme) === $value)><span><x-icon :name="$icon" /><strong>{{ $label }}</strong><small>{{ $description }}</small></span></label>
                            @endforeach
                        </div>
                    </fieldset>
                    <input type="hidden" name="timezone" value="Asia/Manila">
                    <div class="settings-toggle-list">
                        @foreach([
                            'email_notifications' => ['Email notifications', 'Master switch for attendance, schedule, and leave emails.'],
                            'attendance_reminders' => ['Attendance reminders', 'Receive weekday check-in and check-out reminders.'],
                            'schedule_updates' => ['Schedule updates', 'Receive an email when a schedule is assigned, changed, or removed.'],
                            'leave_updates' => ['Leave updates', 'Receive submission, approval, rejection, and cancellation updates.'],
                            'compact_navigation' => ['Compact navigation', 'Reduce spacing in the sidebar navigation.'],
                            'reduce_motion' => ['Reduce motion', 'Minimize interface transitions and animations.'],
                        ] as $field => [$title, $description])
                            <label class="settings-toggle"><span><strong>{{ $title }}</strong><small>{{ $description }}</small></span><input type="checkbox" name="{{ $field }}" value="1" @checked(old($field, $preference->{$field}))><i aria-hidden="true"></i></label>
                        @endforeach
                    </div>
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
        </div>
    </section>
@endsection
