@props([
    'title',
    // The right-hand pane used to carry a different headline per page. That
    // copy lives on here as the brand column's lede, so each auth page still
    // says something about what it is for rather than repeating one sentence.
    'lede' => 'One connected workspace for rostering, attendance, leave and payroll across every hospital department.',
    // The reset pages send no referrer, so a reset token in the URL cannot
    // leak to Font Awesome's CDN or anywhere else the page touches.
    'noReferrer' => false,
    // The two-factor page has no password field and so has never loaded the
    // reveal script. Kept opt-out rather than always-on to preserve that.
    'script' => true,
])

{{--
    The shell every auth page is drawn in: brand column on white, form card on
    the sunken ground, one footer beneath the column.

    It exists because these five pages are standalone HTML documents — they sit
    outside layouts/app.blade.php on purpose — and were carrying five copies of
    the same <head>, the same header and the same footer. Five copies is five
    chances for them to drift.

    The brand name is a <p>, not a heading: each page's own <h1> belongs to the
    card, so a screen reader hears what the page is for rather than hearing the
    organisation's name announced as the heading of all five.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    @if ($noReferrer)
        <meta name="referrer" content="no-referrer">
    @endif
    <title>{{ $title }}</title>
    @include('partials.favicon')
    {{-- Font Awesome is here for the password reveal, whose open and shut
         states script.js swaps by toggling fa-eye / fa-eye-slash, and for the
         few status glyphs beside it. Everything else is an inline <x-icon>. --}}
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    {{-- privacy-modal.js is unconditional: the footer link below is on all five
         of these pages, and the two-factor page opting out of script.js should
         not be the reason one of them sends the reader away instead. --}}
    @vite(array_filter([
        'resources/css/style.css',
        'resources/js/privacy-modal.js',
        $script ? 'resources/js/script.js' : null,
    ]))
</head>
<body>

    {{-- A grid, not two flex panes, so the footer can be a single element: it
         sits under the brand column on a wide screen and under the card on a
         phone without being rendered twice and toggled per breakpoint. --}}
    <div class="auth-split">
        <div class="brand-pane">
            <div class="brand-col">
                <div class="brand-tile">
                    <x-brand-mark :size="28" />
                </div>

                <p class="brand-kicker">{{ config('branding.tagline') }}</p>
                <p class="brand-title">{{ config('branding.organization') }}</p>
                <p class="brand-lede">{{ $lede }}</p>

                <ul class="brand-features">
                    <li><x-icon name="shield" /> Two-factor secured sign-in</li>
                    <li><x-icon name="users" /> Role-based access</li>
                    <li><x-icon name="hospital" /> Centralized hospital operations</li>
                </ul>
            </div>
        </div>

        <div class="form-pane">
            <main class="auth-card">
                {{ $slot }}
            </main>
        </div>

        <footer class="auth-foot">
            <div class="auth-foot-row">
                <span class="copyright">&copy; {{ now()->year }} {{ config('branding.organization') }}. All rights reserved.</span>
                {{-- A real link to a real page, which privacy-modal.js upgrades
                     into the modal below on a plain left click. Ctrl-click, the
                     middle button and a browser with no JavaScript all still get
                     the page itself. --}}
                <a href="{{ route('privacy-policy') }}" class="privacy" data-privacy-open>Privacy Policy</a>
            </div>
        </footer>
    </div>

    {{ $after ?? '' }}

    {{-- Last in the body, outside .auth-split: a modal dialog is drawn in the
         top layer wherever it sits, and keeping it out of the grid means it
         cannot inherit a column from it. --}}
    <x-privacy-modal :auto-open="request()->boolean('privacy')" />

</body>
</html>
