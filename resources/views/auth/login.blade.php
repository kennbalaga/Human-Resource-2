<x-auth-shell :title="config('branding.organization').' - Login'">

    <h1 class="auth-card-title">Sign in to {{ config('branding.short_name') }}</h1>
    <p class="auth-card-sub">Use your hospital credentials to continue to the operations workspace.</p>

    <form id="loginForm" method="POST" action="{{ route('login') }}">
        @csrf

        {{-- Filled by mobile-access.js from this device's own storage when its app
             lock has been set up, and left empty everywhere else. A handset that
             offers a live token skips the authenticator code, because the PIN or
             fingerprint in front of the app has already asked the same question
             the code would. Nothing about the PIN itself is in here: the value is
             an opaque token the server issued and can match to this browser. --}}
        <input type="hidden" name="mobile_trust_tokens" value="" data-mobile-trust-field>

        @if (session('status'))
            <p role="status" class="auth-alert auth-alert-success">
                {{ session('status') }}
            </p>
        @endif

        @if ($sessionNotice)
            <p role="status" class="auth-alert auth-alert-notice">
                {{ $sessionNotice->message() }}
            </p>
        @endif

        @if ($errors->any())
            <p role="alert" class="auth-alert auth-alert-error">
                {{ $errors->first() }}
            </p>
        @endif

        <div class="input-group">
            <label for="employee_id">Employee ID or Email Address</label>
            <input
                type="text"
                id="employee_id"
                name="employee_id"
                value="{{ old('employee_id') }}"
                autocomplete="username"
                placeholder="name@example.com"
                required
                autofocus
            >
        </div>

        <div class="input-group">
            <label for="password">Password</label>
            <div class="password-wrapper">
                <input
                    type="password"
                    id="password"
                    name="password"
                    placeholder="Enter your password"
                    autocomplete="current-password"
                    required
                >
                <button
                    type="button"
                    class="toggle-password"
                    data-toggle-password="password"
                    aria-controls="password"
                    aria-label="Show password"
                >
                    <i class="fa-regular fa-eye-slash" aria-hidden="true"></i>
                </button>
            </div>
        </div>

        <button type="submit" class="btn-primary">
            <x-icon name="log-in" /> Log In
        </button>

        <a href="{{ route('password.request') }}" class="forgot-password">Forgot Your Password?</a>
    </form>

    <x-slot:after>
        @if ($sessionNotice)
            {{-- The alert on the form says the same thing and stays behind once
                 this is dismissed. This is here to be unmissable: whoever reaches
                 this page was sent, not brought, and is owed the reason before
                 they start typing their password in again. --}}
            <dialog class="auth-dialog" data-auth-dialog aria-labelledby="authDialogTitle">
                <div class="auth-dialog-icon" aria-hidden="true">
                    <i class="fa-solid fa-user-lock"></i>
                </div>

                <p class="auth-dialog-kicker">Session ended</p>
                <h2 class="auth-dialog-title" id="authDialogTitle">{{ $sessionNotice->title() }}</h2>
                <p class="auth-dialog-message">{{ $sessionNotice->message() }}</p>

                <button type="button" class="btn-primary" data-auth-dialog-close autofocus>Got it</button>
            </dialog>
        @endif
    </x-slot:after>

</x-auth-shell>
