<x-auth-shell
    :title="'Choose New Password - '.config('branding.organization')"
    lede="Changing your password signs out every existing session and revokes any active API token."
    :no-referrer="true"
>

    <h1 class="auth-card-title">Choose a New Password</h1>
    <p class="auth-card-sub">Use a strong password that you do not use on another account.</p>

    <form method="POST" action="{{ route('password.update') }}">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">

        @if ($errors->any())
            <p role="alert" class="auth-alert auth-alert-error">{{ $errors->first() }}</p>
        @endif

        <div class="input-group">
            <label for="email">Registered work email</label>
            <input type="email" id="email" name="email" value="{{ old('email', $email) }}" autocomplete="email" required readonly>
        </div>

        <div class="input-group">
            <label for="password">New password</label>
            <div class="password-wrapper">
                <input type="password" id="password" name="password" autocomplete="new-password" required>
                <button type="button" class="toggle-password" data-toggle-password="password" aria-controls="password" aria-label="Show new password">
                    <i class="fa-regular fa-eye-slash" aria-hidden="true"></i>
                </button>
            </div>
        </div>

        <div class="input-group">
            <label for="password_confirmation">Confirm new password</label>
            <div class="password-wrapper">
                <input type="password" id="password_confirmation" name="password_confirmation" autocomplete="new-password" required>
                <button type="button" class="toggle-password" data-toggle-password="password_confirmation" aria-controls="password_confirmation" aria-label="Show password confirmation">
                    <i class="fa-regular fa-eye-slash" aria-hidden="true"></i>
                </button>
            </div>
            <p class="password-hint">At least 12 characters with uppercase, lowercase, a number, and a symbol.</p>
        </div>

        <button type="submit" class="btn-primary">
            <x-icon name="lock" /> Reset Password
        </button>

        <a href="{{ route('login') }}" class="back-to-login">
            <x-icon name="arrow-left" /> Back to login
        </a>
    </form>

</x-auth-shell>
