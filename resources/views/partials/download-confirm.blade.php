{{-- Asked in front of the page the download was clicked on, so nobody loses
     their filters to a detour. The full page at /confirm-password stays as the
     fallback for a browser that reaches a download URL without this script:
     the middleware, not this modal, is what actually guards the file. --}}
<div
    class="modal fade download-confirm-modal"
    id="downloadConfirmModal"
    tabindex="-1"
    aria-labelledby="downloadConfirmTitle"
    aria-hidden="true"
    data-download-confirm
    data-confirm-url="{{ route('password.confirm.store') }}"
    data-confirmed-until="{{ $downloadConfirmedUntil }}"
    data-timeout-seconds="{{ $downloadPasswordTimeout }}"
>
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content download-confirm-card">
            <div class="download-confirm-icon" aria-hidden="true">
                <x-icon name="download" />
            </div>

            <form method="POST" action="{{ route('password.confirm.store') }}" class="profile-settings-form" data-download-confirm-form>
                @csrf

                <div class="modal-body">
                    <p class="download-confirm-kicker">Security check</p>
                    <h2 class="modal-title" id="downloadConfirmTitle">Confirm it’s you</h2>
                    <p id="downloadConfirmMessage">
                        This file contains personal records. Enter your password to download it.
                    </p>

                    <p class="download-confirm-error" role="alert" data-download-error hidden></p>

                    <label>
                        <span>Password</span>
                        <input
                            type="password"
                            id="downloadConfirmPassword"
                            name="password"
                            autocomplete="current-password"
                            data-download-password
                            required
                        >
                    </label>

                    <p class="download-confirm-hint">
                        <x-icon name="shield" />
                        You will not be asked again for {{ intdiv($downloadPasswordTimeout, 60) }} minutes.
                    </p>
                </div>

                <div class="modal-footer download-confirm-actions">
                    <button class="btn btn-light" type="button" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn btn-primary" type="submit" data-download-submit>Confirm and download</button>
                </div>
            </form>
        </div>
    </div>
</div>
