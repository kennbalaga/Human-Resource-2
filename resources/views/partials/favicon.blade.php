<link rel="icon" type="image/svg+xml" sizes="any" href="{{ asset('favicon.svg') }}?v=20260716">
<link rel="shortcut icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}?v=20260716">
<link rel="mask-icon" href="{{ asset('favicon.svg') }}?v=20260716" color="#176b43">
<meta name="theme-color" content="#176b43">

{{--
    Installable-app wiring. asset() is used rather than a literal "/..." so
    both a document-root and a subdirectory deployment resolve correctly; the
    service worker's scope follows its own URL, so getting this wrong would
    scope the worker above the app.

    Icon note: the manifest points at the SVG favicon, which Chromium accepts
    for installability. iOS home screens and some Android launchers want real
    192x192 / 512x512 PNGs and will fall back to a generic glyph until those
    are added — dropping two PNG files in public/ and listing them in
    public/manifest.webmanifest is the whole fix, no code change needed.
--}}
<link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
<meta name="sw-url" content="{{ asset('sw.js') }}">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="HRMS">
<link rel="apple-touch-icon" href="{{ asset('favicon.svg') }}?v=20260716">
