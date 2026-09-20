<x-auth-shell
    :title="'Forgot Password - '.config('branding.organization')"
    lede="Reset access through your verified work email, without the form ever revealing whether an account exists."
    :no-referrer="true"
>

    <p class="auth-card-kicker">Account recovery</p>
    <h1 class="auth-card-title">Reset Your Password</h1>
    <p class="auth-card-sub">Enter your employee ID and registered work email. We will send a secure reset link if they match an active account.</p>

    <form method="POST" action="{{ route('password.email') }}">
        @csrf

        @if (session('status'))
            <p role="status" class="auth-alert auth-alert-success">{{ session('status') }}</p>
        @endif

        @if ($errors->any())
            <p role="alert" class="auth-alert auth-alert-error">{{ $errors->first() }}</p>
        @endif

        <div class="input-group">
            <label for="employee_id">Employee ID</label>
            <input type="text" id="employee_id" name="employee_id" value="{{ old('employee_id') }}" autocomplete="username" required autofocus>
        </div>

        <div class="input-group">
            <label for="email">Registered work email</label>
            <input type="email" id="email" name="email" value="{{ old('email') }}" autocomplete="email" required>
        </div>

        <div class="security-note">
            <i class="fa-solid fa-shield-halved" aria-hidden="true"></i>
            <span>The link expires in 30 minutes and can only be used once.</span>
        </div>

        <button type="submit" class="btn-primary">
            <x-icon name="mail" /> Send Reset Link
        </button>

        <a href="{{ route('login') }}" class="back-to-login">
            <x-icon name="arrow-left" /> Back to login
        </a>
    </form>

</x-auth-shell>
