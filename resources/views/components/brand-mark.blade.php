@props(['size' => 56])

{{--
    The placeholder mark that stands in for the hospital seal until we are
    cleared to carry it. Inline rather than an <img> so it stays crisp at every
    size, costs no request, and cannot be left pointing at a deleted file.

    One compound path: the tower and its two wings are solid, the cross and the
    door are evenodd cutouts, so the background shows through them instead of
    being painted over. That is what lets the same drawing sit on a white auth
    page and on the sidebar's dark gradient.

    It paints in currentColor for the same reason — brand green where it sits
    on white, white where it sits on the sidebar — so the colour belongs to the
    context that places it, not to this file.

    The app-icon framing of the same drawing (white on a green tile) is in
    public/favicon.svg and the PNGs under public/images/icons: a tab strip and
    a launcher need an opaque tile behind the glyph, a page does not.

    aria-hidden because the organisation name sits beside it everywhere it is
    used — a screen reader would otherwise announce the brand twice.
--}}
<svg
    {{ $attributes }}
    width="{{ $size }}"
    height="{{ $size }}"
    viewBox="0 0 64 64"
    fill="currentColor"
    aria-hidden="true"
    focusable="false"
>
    <path
        fill-rule="evenodd"
        d="M21 15a5 5 0 0 1 5-5h12a5 5 0 0 1 5 5v38H21zM6 33a4 4 0 0 1 4-4h11v24H6zM58 33a4 4 0 0 0-4-4H43v24h15zM29.5 17h5v5h5v5h-5v5h-5v-5h-5v-5h5zM28 53v-8a4 4 0 0 1 8 0v8z"
    />
</svg>
