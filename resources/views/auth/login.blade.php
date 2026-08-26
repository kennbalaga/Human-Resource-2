<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Dr. Jose N. Rodriguez Memorial Hospital - Login</title>
    {{-- The shared partial, so this page's tab icon can never drift from the
         rest of the app again — and so the install prompt is reachable from
         the sign-in screen, not only from inside the app. It also replaces the
         300KB original that was being served as a 16px favicon. --}}
    @include('partials.favicon')
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    @vite(['resources/css/style.css', 'resources/js/script.js'])
</head>
<body>

    <div class="split-screen">
        <div class="left-pane">
            <header class="logo">
                <img
                    src="{{ asset('images/icons/logo-mark-96.png') }}?v=20260826"
                    srcset="{{ asset('images/icons/logo-mark-96.png') }}?v=20260826 1x, {{ asset('images/icons/logo-mark-192.png') }}?v=20260826 2x"
                    alt=""
                    width="56"
                    height="56"
                    class="logo-seal"
                > Dr. Jose N. Rodriguez <br> Memorial Hospital and Sanitarium
            </header>

            <main class="login-container">
                <div class="login-header">
                    <h1>Welcome Back</h1>
                    <p>Enter your employee ID or work email and password to access your account.</p>
                </div>

                <form id="loginForm" method="POST" action="{{ route('login') }}">
                    @csrf

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
                            value="{{ old('employee_id', $rememberedEmployeeId) }}"
                            autocomplete="username"
                            placeholder="name@example.com"
                            required
                            autofocus
                        >
                    </div>

                    <div class="input-group">
                        <label for="password">Password</label>
                        <div class="password-wrapper">
                            <input type="password" id="password" name="password" autocomplete="current-password" required>
                            <i class="fa-regular fa-eye-slash toggle-password" id="togglePassword"></i>
                        </div>
                    </div>

                    <div class="form-actions">
                        <div class="remember-me">
                            <input type="hidden" name="remember" value="0">
                            <input type="checkbox" id="remember" name="remember" value="1" @checked((bool) old('remember', $rememberedEmployeeSelected))>
                            <label for="remember">Remember Me</label>
                        </div>
                        <a href="{{ route('password.request') }}" class="forgot-password">Forgot Your Password?</a>
                    </div>

                    <button type="submit" class="btn-primary">Log In</button>

                </form>
            </main>

            <footer class="footer-links">
                <span class="copyright">&copy; {{ now()->year }} Dr. Jose N. Rodriguez MHS. All rights reserved.</span>
                <a href="{{ route('privacy-policy') }}" class="privacy">Privacy Policy</a>
            </footer>
        </div>

        <div class="right-pane">
            <div class="hero-content">
                <h2>Empower your hospital workforce with confidence.</h2>
                <p>Log in to your management portal to coordinate staff, ensure seamless coverage, and support your healthcare professionals.</p>

                <img src="{{ URL('images/doctors.png')}}" alt="CRM Dashboard Preview" class="hero-image">
            </div>
            <div class="bg-shape shape-1"></div>
            <div class="bg-shape shape-2"></div>
        </div>
    </div>

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

</body>
</html>
