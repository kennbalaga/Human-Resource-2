@php
    /*
     * The public privacy notice. Reachable without signing in, because the
     * people who most need it are the ones staring at the login form deciding
     * whether to hand this system their location and their leave attachments.
     *
     * The text itself lives in legal/partials/policy-sections.blade.php, which
     * this page and the modal on the auth pages both include. This file is the
     * standalone, linkable, printable copy of it — the one a person can send to
     * someone else, and the one the modal's "Open the full page" link reaches.
     */
    $updatedAt = \Illuminate\Support\Carbon::parse(config('privacy.policy.updated_at'));
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Privacy Policy - {{ config('branding.organization') }}</title>
    <meta name="description" content="How the {{ config('branding.organization') }} system collects, uses, and protects personnel data under RA 10173.">
    @include('partials.favicon')
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    @vite(['resources/css/style.css'])
</head>
<body class="legal-page">

    <a href="#policy" class="legal-skip">Skip to the policy</a>

    <header class="legal-topbar">
        <a href="{{ route('login') }}" class="legal-brand">
            <x-brand-mark :size="44" class="logo-seal" />
            <span>{{ config('branding.organization') }} <br> {{ config('branding.tagline') }}</span>
        </a>
        <a href="{{ route('login') }}" class="legal-back">
            <i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Back to login
        </a>
    </header>

    <main class="legal-doc" id="policy">
        <p class="legal-eyebrow">Privacy notice</p>
        <h1>Privacy Policy</h1>
        @include('legal.partials.policy-lead')

        <dl class="legal-meta">
            <div>
                <dt>Last updated</dt>
                <dd><time datetime="{{ $updatedAt->toDateString() }}">{{ $updatedAt->format('j F Y') }}</time></dd>
            </div>
            <div>
                <dt>Applies to</dt>
                <dd>Everyone with an HRMS account</dd>
            </div>
            <div>
                <dt>Governing law</dt>
                <dd>RA 10173 and its IRR</dd>
            </div>
        </dl>

        <nav class="legal-toc" aria-labelledby="toc-heading">
            <h2 id="toc-heading">On this page</h2>
            <ol>
                @include('legal.partials.policy-contents')
            </ol>
        </nav>

        @include('legal.partials.policy-sections')

    </main>

    <footer class="legal-footer">
        <span class="copyright">&copy; {{ now()->year }} {{ config('branding.organization') }}. All rights reserved.</span>
        <a href="{{ route('login') }}">Back to login</a>
    </footer>

</body>
</html>
