@php
    /*
     * Device app lock — the setup panel.
     *
     * This panel is `display: none` from app-lock.css and only revealed when
     * app-lock.js sets data-available="true", which happens on a touch device
     * with a phone- or tablet-sized screen in a secure context. On a desktop
     * the Settings page renders exactly as it did before this feature existed.
     *
     * There is no form, no action attribute and no CSRF token anywhere below,
     * because nothing here is submitted. The PIN is hashed with PBKDF2 in the
     * browser and the digest is written to this device's localStorage; the
     * fingerprint never leaves the phone's secure element at all — WebAuthn
     * hands back a key handle, never the biometric. That is what keeps this
     * inside the Data Privacy Act's rules on sensitive personal information:
     * the hospital never becomes the custodian of a biometric record.
     */
@endphp

<article class="panel settings-panel" id="device-lock" data-device-lock-panel data-available="false">
    <div class="panel-header">
        <div>
            <p class="panel-kicker">This device only</p>
            <h2>App lock (PIN &amp; fingerprint)</h2>
        </div>
        <span class="device-lock-status" data-device-lock-status data-state="off">Not set up</span>
    </div>

    <div class="device-lock-intro">
        <x-icon name="lock" />
        <div>
            <strong>Lock the app between shifts</strong>
            <p>
                A 6-digit PIN is asked for when the app is reopened or left in the background, so a
                phone handed to a colleague or left on a ward desk does not expose your roster,
                payroll figures, or your team's records.
            </p>
            <p>
                It also takes over from your authenticator code on this phone. Once the PIN is set,
                signing in here asks for your password and then this PIN or your fingerprint instead
                of a 6-digit code from an app — the lock is already asking the same question. Signing
                in on a computer is unchanged, and removing the lock brings the code back.
            </p>
        </div>
    </div>

    <div class="device-lock-privacy">
        <x-icon name="shield" />
        <div>
            <p>
                <strong>Your PIN and fingerprint never leave this phone.</strong>
                The PIN is stored here as a salted PBKDF2 hash, and the fingerprint option uses your
                phone's own sensor — the app receives a yes/no answer and a key handle, never your
                fingerprint. Removing the app lock, clearing this browser's data, or uninstalling the
                app erases both permanently. HR and system administrators cannot view, recover, or
                reset them.
            </p>
            <p>
                The one thing that is recorded is that a lock exists on this phone, so that sign-in
                knows to ask for it instead of an authenticator code. That record holds no PIN, no
                fingerprint and nothing that could be used to guess either, and it is deleted the
                moment you remove the lock.
            </p>
        </div>
    </div>

    <div class="device-lock-body">
        {{-- ---- Step 1: choose a PIN ---- --}}
        <section class="device-lock-step" data-device-lock-step="create" hidden>
            <header>
                <strong>1. Choose a 6-digit PIN</strong>
                <small>Avoid your birth year, your employee number, or a repeated or sequential run such as 111111 or 123456.</small>
            </header>

            <div class="device-lock-pin" data-device-lock-pin="create">
                <label class="visually-hidden" for="deviceLockPinCreate">New 6-digit PIN</label>
                <input
                    id="deviceLockPinCreate"
                    type="password"
                    inputmode="numeric"
                    autocomplete="off"
                    autocorrect="off"
                    autocapitalize="off"
                    spellcheck="false"
                    maxlength="6"
                    pattern="[0-9]*"
                    data-device-lock-pin-input
                >
                @for ($cell = 0; $cell < 6; $cell++)
                    <span class="device-lock-pin-cell" data-filled="false" aria-hidden="true"></span>
                @endfor
            </div>

            <p class="device-lock-feedback" data-device-lock-feedback="create" role="status"></p>

            <div class="device-lock-actions">
                <button class="btn btn-primary" type="button" data-device-lock-action="continue-create">
                    <x-icon name="chevron-right" /> Continue
                </button>
                <button class="btn btn-light" type="button" data-device-lock-action="cancel" hidden>Cancel</button>
            </div>
        </section>

        {{-- ---- Step 2: confirm it ---- --}}
        <section class="device-lock-step" data-device-lock-step="confirm" hidden>
            <header>
                <strong>2. Re-enter the PIN</strong>
                <small>There is no recovery. If you forget it you can sign out and set a new one after signing in again.</small>
            </header>

            <div class="device-lock-pin" data-device-lock-pin="confirm">
                <label class="visually-hidden" for="deviceLockPinConfirm">Confirm 6-digit PIN</label>
                <input
                    id="deviceLockPinConfirm"
                    type="password"
                    inputmode="numeric"
                    autocomplete="off"
                    autocorrect="off"
                    autocapitalize="off"
                    spellcheck="false"
                    maxlength="6"
                    pattern="[0-9]*"
                    data-device-lock-pin-input
                >
                @for ($cell = 0; $cell < 6; $cell++)
                    <span class="device-lock-pin-cell" data-filled="false" aria-hidden="true"></span>
                @endfor
            </div>

            <p class="device-lock-feedback" data-device-lock-feedback="confirm" role="status"></p>

            <div class="device-lock-actions">
                <button class="btn btn-primary" type="button" data-device-lock-action="save">
                    <x-icon name="check-circle" /> Save PIN
                </button>
                <button class="btn btn-light" type="button" data-device-lock-action="back">Back</button>
            </div>
        </section>

        {{-- ---- Step 3: the optional fingerprint ---- --}}
        <section class="device-lock-step" data-device-lock-step="biometric" hidden>
            <header>
                <strong>3. Add your fingerprint (optional)</strong>
                <small>
                    Unlock with the fingerprint or face sensor this phone already uses, instead of
                    typing six digits every time. Your PIN keeps working either way, and you can skip
                    this and add it later.
                </small>
            </header>

            <p class="device-lock-feedback" data-device-lock-feedback="biometric" role="status"></p>

            <div class="device-lock-actions">
                <button class="btn btn-primary" type="button" data-device-lock-action="enroll-biometric">
                    <x-icon name="fingerprint" /> Use this device's fingerprint
                </button>
                <button class="btn btn-light" type="button" data-device-lock-action="skip-biometric">Not now</button>
            </div>
        </section>

        {{-- ---- Enrolled ---- --}}
        <section class="device-lock-step" data-device-lock-step="enrolled" hidden>
            {{--
                Shown whenever the PIN is set, this device has a sensor, and the
                fingerprint has not been turned on — which is exactly the state
                someone lands in after tapping "Not now" during setup. The row
                further down can already turn it on, but a summary row reads as
                a setting that is deliberately off rather than an offer still
                open, so the offer is made plainly here instead.
            --}}
            <div class="device-lock-nudge" data-device-lock-nudge hidden>
                <x-icon name="fingerprint" />
                <div>
                    <strong>Add fingerprint unlock</strong>
                    <p>Your PIN is active. You can also unlock with the fingerprint or face sensor this phone already uses, instead of typing six digits each time. It stays on this device and your PIN keeps working.</p>
                </div>
                <button class="btn btn-primary" type="button" data-device-lock-action="toggle-biometric">
                    <x-icon name="fingerprint" /> Set up fingerprint
                </button>
            </div>

            <div class="device-lock-summary">
                <div class="device-lock-summary-row">
                    <span>
                        <strong>6-digit PIN</strong>
                        <small data-device-lock-pin-summary>Active on this device, and used in place of your authenticator code when you sign in here.</small>
                    </span>
                    <button class="btn btn-light" type="button" data-device-lock-action="change-pin">Change PIN</button>
                </div>

                <div class="device-lock-summary-row" data-device-lock-biometric-row>
                    <span>
                        <strong>
                            Fingerprint unlock
                            <span class="device-lock-biometric-badge" data-device-lock-biometric-badge data-state="off">
                                <x-icon name="fingerprint" /> <span data-device-lock-biometric-label>Off</span>
                            </span>
                        </strong>
                        <small data-device-lock-biometric-summary>Optional. Uses this phone's own sensor; the fingerprint itself never reaches the app.</small>
                    </span>
                    <button class="btn btn-light" type="button" data-device-lock-action="toggle-biometric">Turn on</button>
                </div>

                <div class="device-lock-summary-row">
                    <label class="device-lock-autolock">
                        <span>Lock after</span>
                        <select data-device-lock-autolock>
                            <option value="0">Immediately when the app is closed or hidden</option>
                            <option value="60">1 minute in the background</option>
                            <option value="300">5 minutes in the background</option>
                            <option value="900">15 minutes in the background</option>
                        </select>
                        <small>The app always asks for the PIN when it is opened fresh, whatever this is set to.</small>
                    </label>
                </div>
            </div>

            <p class="device-lock-feedback" data-device-lock-feedback="enrolled" role="status"></p>

            <div class="device-lock-actions">
                <button class="btn btn-primary" type="button" data-device-lock-action="lock-now">
                    <x-icon name="lock" /> Lock now
                </button>
                <button class="btn btn-danger" type="button" data-device-lock-action="remove">
                    <x-icon name="trash" /> Remove app lock
                </button>
            </div>
        </section>

        {{-- ---- Not set up yet ---- --}}
        <section class="device-lock-step" data-device-lock-step="idle" hidden>
            <div class="device-lock-actions">
                <button class="btn btn-primary" type="button" data-device-lock-action="start">
                    <x-icon name="lock" /> Set up a 6-digit PIN
                </button>
            </div>
        </section>
    </div>
</article>
