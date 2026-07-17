<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Two-Factor Verification - Dr. Jose N. Rodriguez Memorial Hospital</title>
    @include('partials.favicon')
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    @vite(['resources/css/style.css'])
</head>
<body>
    <div class="split-screen">
        <div class="left-pane">
            <header class="logo"><i class="fa-solid fa-hospital icon-logo"></i> Dr. Jose N. Rodriguez <br> Memorial Hospital and Sanitarium</header>
            <main class="login-container two-factor-challenge">
                <div class="two-factor-challenge-icon"><i class="fa-solid fa-shield-halved"></i></div>
                <div class="login-header">
                    <h1>Verify it’s you</h1>
                    <p>Your password was accepted. Enter the current code from your authenticator app to finish signing in.</p>
                </div>

                @if($errors->any())<p role="alert" class="auth-alert auth-alert-error">{{ $errors->first() }}</p>@endif

                <form method="POST" action="{{ route('two-factor.login.store') }}">
                    @csrf
                    <div class="input-group">
                        <label for="code">6-digit authenticator code</label>
                        <input class="one-time-code" type="text" id="code" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,7}" maxlength="7" placeholder="000 000" autofocus required>
                    </div>
                    <button type="submit" class="btn-primary">Verify and continue</button>
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

                <p class="two-factor-help"><i class="fa-solid fa-circle-info"></i> Codes rotate every 30 seconds. If you lost your authenticator and recovery codes, contact the System Administrator for an audited identity-verified reset.</p>
                <a href="{{ route('login') }}" class="back-to-login"><i class="fa-solid fa-arrow-left"></i> Back to login</a>
            </main>
            <footer class="footer-links"><span class="copyright">Copyright © {{ now()->year }} Dr. Jose N. Rodriguez MHS.</span><a href="#" class="privacy">Privacy Policy</a></footer>
        </div>
        <div class="right-pane">
            <div class="hero-content"><h2>Secure workforce access at every sign-in.</h2><p>Your authenticator code provides a second check even if a password is exposed.</p><img src="{{ URL('images/doctors.png') }}" alt="Hospital workforce illustration" class="hero-image"></div>
            <div class="bg-shape shape-1"></div><div class="bg-shape shape-2"></div>
        </div>
    </div>
</body>
</html>
