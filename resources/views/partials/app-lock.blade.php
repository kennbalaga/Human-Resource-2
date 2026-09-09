@php
    /*
     * Device app lock — the unlock screen.
     *
     * Rendered on every authenticated page but inert until app-lock.js decides
     * three things are true: this is a touch device with a small screen, the
     * page is a secure context, and this account has actually set a PIN on THIS
     * device. On a desktop browser it stays display:none and nothing runs.
     *
     * Nothing about the PIN or the fingerprint is rendered here or posted
     * anywhere. The only server-side value this markup carries is the account
     * identifier, and that is used solely to namespace the browser-local
     * record so two staff sharing a ward phone cannot unlock each other's app.
     */
    $lockUser = auth()->user();
@endphp

<div
    class="app-lock"
    id="appLock"
    role="dialog"
    aria-modal="true"
    aria-labelledby="appLockTitle"
    data-app-lock
    data-state="hidden"
    data-user-key="{{ $lockUser->id }}"
    data-user-name="{{ $lockUser->name }}"
    data-user-email="{{ $lockUser->email }}"
    data-logout-url="{{ route('logout') }}"
    data-csrf="{{ csrf_token() }}"
    hidden
>
    <div class="app-lock-head">
        <span class="app-lock-mark">
            <x-brand-mark :size="42" />
        </span>
        <h1 class="app-lock-title" id="appLockTitle">{{ $lockUser->name }}</h1>
        <p class="app-lock-subtitle" data-app-lock-subtitle>Enter your 6-digit PIN to unlock the app.</p>
    </div>

    <div class="app-lock-body">
        <div class="app-lock-dots" data-app-lock-dots role="status" aria-live="polite" aria-label="PIN entry progress">
            @for ($digit = 0; $digit < 6; $digit++)
                <span class="app-lock-dot" data-filled="false"></span>
            @endfor
        </div>

        <p class="app-lock-message" data-app-lock-message role="alert"></p>

        <div class="app-lock-keypad" data-app-lock-keypad>
            @foreach ([1, 2, 3, 4, 5, 6, 7, 8, 9] as $key)
                <button class="app-lock-key" type="button" data-key="{{ $key }}" aria-label="{{ $key }}">{{ $key }}</button>
            @endforeach

            {{-- Bottom row: fingerprint, zero, backspace. The fingerprint key is
                 hidden unless this device has an enrolled platform authenticator
                 for this account, so it never offers something that will fail. --}}
            <button class="app-lock-key" type="button" data-action="biometric" data-app-lock-biometric aria-label="Unlock with fingerprint" hidden>
                <x-icon name="fingerprint" />
            </button>
            <button class="app-lock-key" type="button" data-key="0" aria-label="0">0</button>
            <button class="app-lock-key" type="button" data-action="delete" aria-label="Delete last digit">
                <x-icon name="close" />
            </button>
        </div>
    </div>

    <div class="app-lock-foot">
        <button class="app-lock-signout" type="button" data-app-lock-signout>Sign out instead</button>
        <p class="app-lock-note">
            Your PIN and fingerprint stay on this device. They are never uploaded, and no one at HR can see or reset them.
        </p>
    </div>
</div>
