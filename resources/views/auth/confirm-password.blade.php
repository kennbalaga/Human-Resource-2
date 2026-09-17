<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Confirm Password - {{ config('branding.organization') }}</title>
    @include('partials.favicon')
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    @vite(['resources/css/style.css', 'resources/js/script.js'])
</head>
<body>
    <div class="split-screen">
        <div class="left-pane">
            <header class="logo"><x-brand-mark :size="56" class="logo-seal" /> {{ config('branding.organization') }} <br> {{ config('branding.tagline') }}</header>
            <main class="login-container two-factor-challenge">
                <div class="two-factor-challenge-icon"><i class="fa-solid fa-file-shield"></i></div>
                <div class="login-header">
                    <h1>Confirm it’s you</h1>
                    <p>You are about to download a file containing personal records. Enter the password for <strong>{{ auth()->user()->name }}</strong> to continue.</p>
                </div>

                @if($errors->any())<p role="alert" class="auth-alert auth-alert-error">{{ $errors->first() }}</p>@endif

                <form method="POST" action="{{ route('password.confirm.store') }}">
                    @csrf
                    <div class="input-group">
                        <label for="password">Password</label>
                        <div class="password-wrapper">
                            <input type="password" id="password" name="password" autocomplete="current-password" required autofocus>
                            <button type="button" class="toggle-password" data-toggle-password="password" aria-label="Show password">
                                <i class="fa-regular fa-eye-slash" aria-hidden="true"></i>
                            </button>
                        </div>
                    </div>
                    <button type="submit" class="btn-primary">Confirm and download</button>
                </form>

                <p class="two-factor-help"><i class="fa-solid fa-circle-info"></i> You will not be asked again for {{ intdiv((int) config('security.downloads.password_timeout_seconds', 900), 60) }} minutes. If this was not you, cancel and change your password.</p>
                <a href="{{ $cancelUrl }}" class="back-to-login"><i class="fa-solid fa-arrow-left"></i> Cancel</a>
            </main>
            <footer class="footer-links"><span class="copyright">&copy; {{ now()->year }} {{ config('branding.organization') }}. All rights reserved.</span><a href="{{ route('privacy-policy') }}" class="privacy">Privacy Policy</a></footer>
        </div>
        <div class="right-pane">
            <div class="hero-content"><h2>Records stay with the people they belong to.</h2><p>A second check before any file leaves the system, in case this screen was left signed in.</p><img src="{{ URL('images/doctors.png') }}" alt="Hospital workforce illustration" class="hero-image"></div>
            <div class="bg-shape shape-1"></div><div class="bg-shape shape-2"></div>
        </div>
    </div>
</body>
</html>
