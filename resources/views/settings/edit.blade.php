@extends('layouts.app')

@section('title', 'Account Settings')

@section('content')
    <section class="page-heading workforce-heading"><div><p class="eyebrow">Personal preferences</p><h1>Account Settings</h1><p>Manage account security, notifications, timezone, and interface preferences.</p></div><a class="btn btn-outline-primary profile-heading-action" href="{{ route('profile.show') }}"><x-icon name="users" /> View profile</a></section>

    @if(session('success'))<div class="attendance-alert attendance-alert-success"><x-icon name="check-circle" /><span>{{ session('success') }}</span></div>@endif
    @if($errors->any())<div class="attendance-alert attendance-alert-danger"><x-icon name="close" /><span>{{ $errors->first() }}</span></div>@endif

    <section class="settings-layout">
        <aside class="panel settings-section-nav" aria-label="Settings sections">
            <a href="#account"><x-icon name="users" /><span><strong>Account</strong><small>Email and identity</small></span></a>
            <a href="#preferences"><x-icon name="moon" /><span><strong>Appearance</strong><small>Theme and display</small></span></a>
            <a href="#security"><x-icon name="shield" /><span><strong>Security</strong><small>Password and tokens</small></span></a>
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
                    <label class="settings-select"><span>Display timezone</span><select name="timezone" required>@foreach($timezones as $value => $label)<option value="{{ $value }}" @selected(old('timezone', $preference->timezone) === $value)>{{ $label }}</option>@endforeach</select><small>Controls dates shown in the application header.</small></label>
                    <div class="settings-toggle-list">
                        @foreach([
                            'email_notifications' => ['Email notifications', 'Allow the HRMS to send account and workflow emails.'],
                            'attendance_reminders' => ['Attendance reminders', 'Receive reminders related to check-in and check-out.'],
                            'schedule_updates' => ['Schedule updates', 'Receive updates when assigned schedules change.'],
                            'leave_updates' => ['Leave updates', 'Receive status changes for leave requests.'],
                            'compact_navigation' => ['Compact navigation', 'Reduce spacing in the sidebar navigation.'],
                            'reduce_motion' => ['Reduce motion', 'Minimize interface transitions and animations.'],
                        ] as $field => [$title, $description])
                            <label class="settings-toggle"><span><strong>{{ $title }}</strong><small>{{ $description }}</small></span><input type="checkbox" name="{{ $field }}" value="1" @checked(old($field, $preference->{$field}))><i aria-hidden="true"></i></label>
                        @endforeach
                    </div>
                    <button class="btn btn-primary" type="submit"><x-icon name="check-circle" /> Save preferences</button>
                </form>
            </article>

            <article class="panel settings-panel" id="security">
                <div class="panel-header"><div><p class="panel-kicker">Account protection</p><h2>Change password</h2></div><x-icon name="shield" /></div>
                <div class="settings-security-note"><x-icon name="shield" /><p>Changing your password revokes all existing API tokens. Your current browser session remains signed in.</p></div>
                <form method="POST" action="{{ route('settings.password.update') }}" class="profile-settings-form settings-password-grid">
                    @csrf @method('PUT')
                    <label><span>Current password</span><input type="password" name="current_password" autocomplete="current-password" required></label>
                    <label><span>New password</span><input type="password" name="password" autocomplete="new-password" required><small>At least 12 characters with upper/lowercase letters and a number.</small></label>
                    <label><span>Confirm new password</span><input type="password" name="password_confirmation" autocomplete="new-password" required></label>
                    <button class="btn btn-primary" type="submit">Update password</button>
                </form>
            </article>
        </div>
    </section>
@endsection
