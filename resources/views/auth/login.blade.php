<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dr. Jose N. Rodriguez Memorial Hospital - Login</title>
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
                    <h1>Welcome Back</h1>
                    <p>Enter your employee ID and password to access your account.</p>
                </div>

                <form id="loginForm" method="POST" action="{{ route('login') }}">
                    @csrf

                    @if (session('status'))
                        <p role="status" class="auth-alert auth-alert-success">
                            {{ session('status') }}
                        </p>
                    @endif

                    @if ($errors->any())
                        <p role="alert" class="auth-alert auth-alert-error">
                            {{ $errors->first() }}
                        </p>
                    @endif

                    <div class="input-group">
                        <label for="employee_id">Employee Id</label>
                        <input
                            type="text"
                            id="employee_id"
                            name="employee_id"
                            value="{{ old('employee_id') }}"
                            autocomplete="username"
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
                            <input type="checkbox" id="remember" name="remember" value="1" @checked(old('remember'))>
                            <label for="remember">Remember Me</label>
                        </div>
                        <a href="{{ route('password.request') }}" class="forgot-password">Forgot Your Password?</a>
                    </div>

                    <button type="submit" class="btn-primary">Log In</button>

                </form>
            </main>

            <footer class="footer-links">
                <span class="copyright">Copyright © 2026 Dr. Jose N. Rodriguez MHS.</span>
                <a href="#" class="privacy">Privacy Policy</a>
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

</body>
</html>
