<x-auth-shell
    :title="'Two-Factor Verification - '.config('branding.organization')"
    lede="Your authenticator code is a second check on every sign-in, even if a password has been exposed."
    :script="false"
>

    <div class="auth-card-icon" aria-hidden="true">
        <i class="fa-solid fa-shield-halved"></i>
    </div>

    <p class="auth-card-kicker">Two-factor verification</p>
    <h1 class="auth-card-title">Verify it&rsquo;s you</h1>
    <p class="auth-card-sub">Your password was accepted. Enter the current code from your authenticator app to finish signing in.</p>

    @if ($errors->any())
        <p role="alert" class="auth-alert auth-alert-error">{{ $errors->first() }}</p>
    @endif

    <form method="POST" action="{{ route('two-factor.login.store') }}">
        @csrf

        <div class="input-group">
            <label for="code">6-digit authenticator code</label>
            <input
                class="one-time-code"
                type="text"
                id="code"
                name="code"
                inputmode="numeric"
                autocomplete="one-time-code"
                pattern="[0-9 ]{6,7}"
                maxlength="7"
                placeholder="000 000"
                autofocus
                required
            >
        </div>

        <button type="submit" class="btn-primary">
            <x-icon name="shield" /> Verify and continue
        </button>
    </form>

    <details class="recovery-login">
        <summary>Use a recovery code instead</summary>

        <form method="POST" action="{{ route('two-factor.login.store') }}">
            @csrf

            <div class="input-group">
                <label for="recovery_code">One-time recovery code</label>
                <input type="text" id="recovery_code" name="recovery_code" autocomplete="one-time-code" maxlength="50" required>
            </div>

            <button type="submit" class="btn-primary">Use recovery code</button>
        </form>
    </details>

    <p class="two-factor-help">
        <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
        Codes rotate every 30 seconds. If you lost your authenticator and recovery codes, contact the System Administrator for an audited identity-verified reset.
    </p>

    <a href="{{ route('login') }}" class="back-to-login">
        <x-icon name="arrow-left" /> Back to login
    </a>

</x-auth-shell>
