{{--
    Why an org-wide account will not open on a phone.

    Drawn in the same shell as the sign-in pages because that is where whoever
    reads it has just been sent from, and because it is read by a guest: the
    refusal ends the session before landing here.

    `script` is off — there is no password field on this page to reveal, and the
    bundle that does the revealing is the only thing it would load.
--}}
<x-auth-shell
    :title="config('branding.organization').' - Use a computer'"
    :script="false"
    lede="Your account is one of the ones that runs the hospital's records, so it stays on a hospital computer."
>

    <div class="auth-card-icon" aria-hidden="true">
        <i class="fa-solid fa-desktop"></i>
    </div>

    <p class="auth-card-kicker">Wrong device, not a wrong password</p>
    <h1 class="auth-card-title">Use a computer for this account</h1>

    {{-- The refusal wording comes from the policy, so the sentence on the sign-in
         form and the sentence here cannot drift apart. --}}
    <p class="auth-card-sub">{{ $message }}</p>

    <div class="auth-note">
        <p>
            <strong>Nothing is wrong with your account.</strong>
            Your password is fine and your access has not changed — this app is
            simply not available on a phone or tablet for roles that can see the
            whole organisation's records. Signing in from a hospital computer works
            as it always has.
        </p>
        <p>
            Staff who only see their own records — their schedule, their attendance
            and their leave — can install the app on a phone and use it there.
        </p>
    </div>

    {{-- Offered even though this page is reached signed out: somebody who arrives
         here having been turned out mid-session is looking for the way back, and
         the way back is the sign-in form on a different device. --}}
    <a href="{{ route('login') }}" class="btn-primary">
        <x-icon name="log-in" /> Back to sign in
    </a>

</x-auth-shell>
