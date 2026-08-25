@php
    /*
     * One cache-busting stamp for every icon reference on the page. A favicon is
     * one of the most aggressively cached resources a browser holds — without a
     * new stamp, staff would keep seeing the old glyph for days after a deploy.
     */
    $iconVersion = '20260826';
@endphp

{{-- The hospital seal, at the three sizes browsers actually request. These are
     transparent PNGs: the tab strip is near-white in the light theme and
     near-black in the dark one, and the seal is a filled circle that reads
     correctly on both without a plate behind it. --}}
<link rel="icon" type="image/png" sizes="48x48" href="{{ asset('images/icons/favicon-48.png') }}?v={{ $iconVersion }}">
<link rel="icon" type="image/png" sizes="32x32" href="{{ asset('images/icons/favicon-32.png') }}?v={{ $iconVersion }}">
<link rel="icon" type="image/png" sizes="16x16" href="{{ asset('images/icons/favicon-16.png') }}?v={{ $iconVersion }}">
<link rel="shortcut icon" type="image/png" href="{{ asset('images/icons/favicon-32.png') }}?v={{ $iconVersion }}">

{{-- The browser paints the address bar and the Android task-switcher header
     with this. Two values so the installed app matches the theme the user
     actually chose rather than flashing brand green over a dark UI. --}}
<meta name="theme-color" content="#176b43" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#0a1f18" media="(prefers-color-scheme: dark)">

{{--
    Installable-app wiring. asset() is used rather than a literal "/..." so
    both a document-root and a subdirectory deployment resolve correctly; the
    service worker's scope follows its own URL, so getting this wrong would
    scope the worker above the app.
--}}
{{-- The version query is load-bearing, not decoration. The manifest is what
     tells the browser the installed app's name and icon, and it is served with
     no cache headers, so a browser is free to hold the old one for days — which
     is exactly why the launcher kept showing the previous icon after it was
     replaced. Changing the URL forces a refetch.

     Safe to change because the app's identity comes from the manifest's own
     `id` field ("./dashboard"), not from this URL. Without that `id`, a new
     manifest URL would register as a SECOND installed app. --}}
<link rel="manifest" href="{{ asset('manifest.webmanifest') }}?v={{ $iconVersion }}">
<meta name="sw-url" content="{{ asset('sw.js') }}">

{{-- iOS ignores the manifest entirely: the home-screen icon, the title under
     it, and whether the app opens in its own window all come from these tags.
     `apple-mobile-web-app-capable` is what makes it a standalone app rather
     than a Safari bookmark. The touch icon is the seal on white because iOS
     composites a transparent icon onto black. --}}
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="HRMS">
<link rel="apple-touch-icon" sizes="180x180" href="{{ asset('images/icons/apple-touch-icon.png') }}?v={{ $iconVersion }}">

{{-- Phone-number autolinking turns every employee ID and every time like
     "07:00-15:00" into a blue tappable link on iOS. --}}
<meta name="format-detection" content="telephone=no">
