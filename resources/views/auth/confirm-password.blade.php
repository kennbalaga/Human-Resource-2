<x-auth-shell :title="'Confirm Password - '.config('branding.organization')"
    lede="A second check before any file leaves the system, in case this screen was left signed in."
>

    <div class="auth-card-icon" aria-hidden="true">
        <i class="fa-solid fa-file-shield"></i>
    </div>

    <p class="auth-card-kicker">Security check</p>
    <h1 class="auth-card-title">Confirm it&rsquo;s you</h1>
    <p class="auth-card-sub">You are about to download a file containing personal records. Enter the password for <strong>{{ auth()->user()->name }}</strong> to continue.</p>

    @if ($errors->any())
        <p role="alert" class="auth-alert auth-alert-error">{{ $errors->first() }}</p>
    @endif

    <form method="POST" action="{{ route('password.confirm.store') }}">
        @csrf

        <div class="input-group">
            <label for="password">Password</label>
            <div class="password-wrapper">
                <input type="password" id="password" name="password" autocomplete="current-password" required autofocus>
                <button type="button" class="toggle-password" data-toggle-password="password" aria-controls="password" aria-label="Show password">
                    <i class="fa-regular fa-eye-slash" aria-hidden="true"></i>
                </button>
            </div>
        </div>

        <button type="submit" class="btn-primary">
            <x-icon name="download" /> Confirm and download
        </button>
    </form>

    <p class="two-factor-help">
        <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
        You will not be asked again for {{ intdiv((int) config('security.downloads.password_timeout_seconds', 900), 60) }} minutes. If this was not you, cancel and change your password.
    </p>

    <a href="{{ $cancelUrl }}" class="back-to-login">
        <x-icon name="arrow-left" /> Cancel
    </a>

</x-auth-shell>
