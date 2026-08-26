<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="referrer" content="no-referrer">
    <title>Choose New Password - Dr. Jose N. Rodriguez Memorial Hospital</title>
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
                    <h1>Choose a New Password</h1>
                    <p>Use a strong password that you do not use on another account.</p>
                </div>

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
                            <button type="button" class="toggle-password" data-toggle-password="password" aria-label="Show new password">
                                <i class="fa-regular fa-eye-slash" aria-hidden="true"></i>
                            </button>
                        </div>
                    </div>

                    <div class="input-group">
                        <label for="password_confirmation">Confirm new password</label>
                        <div class="password-wrapper">
                            <input type="password" id="password_confirmation" name="password_confirmation" autocomplete="new-password" required>
                            <button type="button" class="toggle-password" data-toggle-password="password_confirmation" aria-label="Show password confirmation">
                                <i class="fa-regular fa-eye-slash" aria-hidden="true"></i>
                            </button>
                        </div>
                        <p class="password-hint">At least 12 characters with uppercase, lowercase, a number, and a symbol.</p>
                    </div>

                    <button type="submit" class="btn-primary">Reset Password</button>
                    <a href="{{ route('login') }}" class="back-to-login"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Back to login</a>
                </form>
            </main>

            <footer class="footer-links">
                <span class="copyright">&copy; {{ now()->year }} Dr. Jose N. Rodriguez MHS. All rights reserved.</span>
                <a href="{{ route('privacy-policy') }}" class="privacy">Privacy Policy</a>
            </footer>
        </div>

        <div class="right-pane">
            <div class="hero-content">
                <h2>Protect every workforce account.</h2>
                <p>Changing your password signs out existing sessions and revokes active API tokens.</p>
                <img src="{{ URL('images/doctors.png') }}" alt="Hospital workforce illustration" class="hero-image">
            </div>
            <div class="bg-shape shape-1"></div>
            <div class="bg-shape shape-2"></div>
        </div>
    </div>
</body>
</html>
