<div
    class="modal fade session-timeout-modal"
    id="sessionTimeoutModal"
    tabindex="-1"
    aria-labelledby="sessionTimeoutTitle"
    aria-describedby="sessionTimeoutMessage"
    aria-hidden="true"
    data-session-timeout
    data-timeout-seconds="{{ $sessionTimeoutSeconds }}"
    data-warning-seconds="{{ $sessionWarningSeconds }}"
    data-heartbeat-seconds="{{ max(3, (int) config('security.session.heartbeat_seconds', 5)) }}"
    data-keep-alive-url="{{ route('session.keep-alive') }}"
    data-logout-url="{{ route('logout') }}"
    data-login-url="{{ route('login') }}"
>
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content session-timeout-card">
            <div class="session-timeout-icon" aria-hidden="true">
                <x-icon name="clock" />
            </div>

            <div class="modal-body">
                <p class="session-timeout-kicker" data-session-kicker>Security reminder</p>
                <h2 class="modal-title" id="sessionTimeoutTitle" data-session-title>Are you still working?</h2>
                <p id="sessionTimeoutMessage" data-session-message>
                    Your session will end after {{ round($sessionTimeoutSeconds / 60) }} minutes of inactivity to protect workforce information.
                </p>

                <div class="session-timeout-countdown" data-session-countdown-wrap aria-live="polite">
                    <span>Time remaining</span>
                    <strong data-session-countdown>05:00</strong>
                </div>
            </div>

            <div class="modal-footer session-timeout-actions">
                <button class="btn btn-light" type="button" data-session-sign-out>Sign out now</button>
                <button class="btn btn-primary" type="button" data-session-continue>Stay signed in</button>
                <button class="btn btn-primary d-none" type="button" data-session-login disabled>Sign in again</button>
            </div>
        </div>
    </div>
</div>
