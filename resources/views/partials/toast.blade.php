{{-- The shared toast, filled in by toast.js. Every "it worked" message in the
     app ends up here: the success flash of a page that just reloaded, drawn
     below, and a quick action that saved without reloading. Anything that
     still needs the reader — an error, a warning, or a success with a next
     step ('notice') — stays a banner on the page, because a message that fades
     on its own can be missed by someone who was interrupted.

     The flash is drawn visible, so without the script it simply stays on
     screen; toast.js takes it over, times it and announces it. --}}
@php($flash = session('success'))
<div class="app-toast" role="status" aria-live="polite" aria-atomic="true" data-toast @unless($flash) hidden @endunless>
    <div class="app-toast-row">
        <span class="app-toast-icon" aria-hidden="true"><x-icon name="check-circle" /></span>
        <span class="app-toast-message" data-toast-message>{{ $flash }}</span>
        <button class="app-toast-action" type="button" data-toast-action hidden></button>
    </div>
    <span class="app-toast-timer" aria-hidden="true" data-toast-timer></span>
</div>
