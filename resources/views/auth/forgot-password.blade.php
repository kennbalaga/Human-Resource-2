<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="referrer" content="no-referrer">
    <title>Forgot Password - Dr. Jose N. Rodriguez Memorial Hospital</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    @vite(['resources/css/style.css', 'resources/js/script.js'])
</head>
<body>
    <div class="split-screen">
        <div class="left-pane">
            <header class="logo">
                <i class="fa-solid fa-hospital icon-logo"></i> Dr. Jose N. Rodriguez <br> Memorial Hospital and Sanitarium
            </header>

            <main class="login-container">
                <div class="login-header">
                    <h1>Reset Your Password</h1>
                    <p>Enter your employee ID and registered work email. We will send a secure reset link if they match an active account.</p>
                </div>

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

                    <button type="submit" class="btn-primary">Send Reset Link</button>
                    <a href="{{ route('login') }}" class="back-to-login"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Back to login</a>
                </form>
            </main>

            <footer class="footer-links">
                <span class="copyright">Copyright © 2026 Dr. Jose N. Rodriguez MHS.</span>
                <a href="#" class="privacy">Privacy Policy</a>
            </footer>
        </div>

        <div class="right-pane">
            <div class="hero-content">
                <h2>Secure account recovery for your workforce.</h2>
                <p>Reset access through your verified work email without exposing whether an account exists.</p>
                <img src="{{ URL('images/doctors.png') }}" alt="Hospital workforce illustration" class="hero-image">
            </div>
            <div class="bg-shape shape-1"></div>
            <div class="bg-shape shape-2"></div>
        </div>
    </div>
</body>
</html>
