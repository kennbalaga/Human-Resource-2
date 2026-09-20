@props([
    // Set when /privacy-policy sent a guest here to read the notice: the
    // dialog is rendered already open, so someone who asked for the notice is
    // never shown a sign-in form and left to find it.
    'autoOpen' => false,
])

@php
    $updatedAt = \Illuminate\Support\Carbon::parse(config('privacy.policy.updated_at'));
@endphp

{{--
    The privacy notice, read without leaving the sign-in form.

    Someone deciding whether to hand this system their location and their
    medical certificates should not have to abandon a half-typed password to
    find out what happens to them. The footer link still points at
    /privacy-policy and still works with JavaScript off, middle-clicked, or
    printed — privacy-modal.js only intercepts the plain left click.

    The text is included from the same partials the standalone page renders, so
    the notice cannot say one thing here and another there.

    The title is a <p>, not a heading, for the reason auth-shell gives for the
    brand name: each section of the notice owns an <h2>, and a heading above
    them would push the whole document a level down in every screen reader's
    outline for the sake of one line. aria-labelledby names the dialog instead.
--}}
<dialog
    class="privacy-modal"
    id="privacyModal"
    closedby="any"
    aria-labelledby="privacyModalTitle"
    data-privacy-modal
    {{-- The bare `open` attribute, not showModal(), because this is rendered
         by the server: it shows the notice to a reader with no JavaScript
         instead of a sign-in form they did not ask for. privacy-modal.js swaps
         it for a real modal — backdrop, focus trap, Esc — the moment it runs. --}}
    @if ($autoOpen) open data-privacy-autoopen @endif
>
    <header class="privacy-modal-head">
        <div class="privacy-modal-heading">
            <p class="legal-eyebrow">Privacy notice</p>
            <p class="privacy-modal-title" id="privacyModalTitle">Privacy Policy</p>

            <ul class="privacy-modal-meta">
                <li>Last updated <b><time datetime="{{ $updatedAt->toDateString() }}">{{ $updatedAt->format('j F Y') }}</time></b></li>
                <li>Applies to <b>Everyone with an HRMS account</b></li>
                <li>Governing law <b>RA 10173 and its IRR</b></li>
            </ul>
        </div>

        <button type="button" class="privacy-modal-close" data-privacy-close aria-label="Close the privacy notice">
            <i class="fa-solid fa-xmark" aria-hidden="true"></i>
        </button>

        {{-- Thirteen sections in a box: without this the reader has no idea
             whether they are near the end or nowhere near it. --}}
        <div class="privacy-modal-progress" aria-hidden="true"><i></i></div>
    </header>

    <div class="privacy-modal-body">
        <nav class="privacy-modal-toc" aria-labelledby="privacyModalTocHeading">
            <p class="privacy-modal-toc-title" id="privacyModalTocHeading">On this page</p>
            <ol>
                @include('legal.partials.policy-contents')
            </ol>
        </nav>

        {{-- tabindex="0" because this, not the dialog, is what scrolls: a
             scrollable region that cannot be focused is a region Page Down
             never reaches. --}}
        <div
            class="privacy-modal-doc legal-doc"
            id="privacyModalDoc"
            tabindex="0"
            role="region"
            aria-labelledby="privacyModalTitle"
            data-privacy-doc
        >
            @include('legal.partials.policy-lead')
            @include('legal.partials.policy-sections')
        </div>
    </div>

    <footer class="privacy-modal-foot">
        <span class="privacy-modal-stamp">
            Last updated {{ $updatedAt->format('j F Y') }} &middot; RA 10173
        </span>

        <div class="privacy-modal-actions">
            {{-- The escape hatch. A modal alone takes away printing it,
                 bookmarking it, and sending it to somebody, and a privacy
                 notice is a document people are entitled to do all three to. --}}
            <a href="{{ route('privacy-policy', ['plain' => 1]) }}" class="privacy-modal-full" target="_blank" rel="noopener">
                Open the full page <i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i>
            </a>
            <button type="button" class="btn-primary" data-privacy-close>Close</button>
        </div>
    </footer>
</dialog>
