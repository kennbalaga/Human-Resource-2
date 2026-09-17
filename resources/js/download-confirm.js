import { Modal } from 'bootstrap';

/*
 * The password check in front of a download, asked in place.
 *
 * Every link that hands out a file is marked `data-download`. A click on one
 * opens this modal instead of navigating, and once the password is accepted the
 * link is clicked again for real -- so the download starts from the page the
 * user was already on, with its filters intact. Anything that fetches a file
 * without a link to click (the PDF print export) calls confirmDownload() for
 * itself.
 *
 * The modal is convenience, not the control: ConfirmPasswordForDownload guards
 * the routes, and a browser that never runs this file still meets the
 * confirmation as a full page. So nothing here needs to be trusted -- the worst
 * a tampered-with `confirmedUntil` achieves is a wasted navigation that the
 * server answers with that page.
 */
const element = document.querySelector('[data-download-confirm]');

// Both in epoch milliseconds: the server said when the confirmation it holds
// runs out, and the page stops asking until then.
let confirmedUntil = Number(element?.dataset.confirmedUntil ?? 0) * 1000;

/**
 * Resolves true once the password is in, false if the user backs out.
 *
 * Callers do not need to check the clock first: an answer still inside the
 * window resolves immediately without showing anything.
 *
 * @returns {Promise<boolean>}
 */
export const confirmDownload = () => {
    if (!element || Date.now() < confirmedUntil) {
        return Promise.resolve(true);
    }

    return element.openConfirmation();
};

if (element) {
    const modal = Modal.getOrCreateInstance(element, { backdrop: 'static' });
    const form = element.querySelector('[data-download-confirm-form]');
    const input = element.querySelector('[data-download-password]');
    const error = element.querySelector('[data-download-error]');
    const submit = element.querySelector('[data-download-submit]');
    const timeoutMs = Number(element.dataset.timeoutSeconds) * 1000;
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

    let settle = null;
    let returnFocusTo = null;

    const showError = (message) => {
        error.textContent = message;
        error.hidden = false;
        input.select();
    };

    // Dismissing the modal is an answer too, so no caller is left waiting on a
    // promise that never settles.
    const answer = (confirmed) => {
        const respond = settle;
        settle = null;
        respond?.(confirmed);
    };

    element.openConfirmation = () => {
        answer(false);
        error.hidden = true;
        input.value = '';
        submit.disabled = false;
        modal.show();

        return new Promise((resolve) => { settle = resolve; });
    };

    element.addEventListener('shown.bs.modal', () => input.focus());

    element.addEventListener('hidden.bs.modal', () => {
        input.value = '';
        answer(false);
        returnFocusTo?.focus();
        returnFocusTo = null;
    });

    /*
     * Capture phase, so a download link another module also listens for is
     * stopped before that module reacts to a download which has not started
     * yet. The replayed click reaches every listener in the usual order.
     */
    document.addEventListener('click', (event) => {
        const link = event.target.closest('a[data-download]');

        if (!link
            || event.defaultPrevented
            || event.button !== 0
            || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey
            || Date.now() < confirmedUntil) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();
        returnFocusTo = link;

        const url = link.href;

        element.openConfirmation().then((confirmed) => {
            if (!confirmed) {
                return;
            }

            // Clicking the link again rather than assigning its href: the
            // audit-log export decorates its own links on click, and that has
            // to happen for the page to know the file is on its way.
            if (link.isConnected) {
                link.click();

                return;
            }

            window.markIntentionalNavigation?.();
            window.location.assign(url);
        });
    }, true);

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        submit.disabled = true;
        error.hidden = true;

        let response;

        try {
            response = await fetch(element.dataset.confirmUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ password: input.value }),
            });
        } catch (networkError) {
            submit.disabled = false;
            showError('The password could not be checked. Check your connection and try again.');

            return;
        }

        if (response.ok) {
            confirmedUntil = Date.now() + timeoutMs;
            // Before hiding, so the dismissal does not answer first.
            answer(true);
            modal.hide();

            return;
        }

        submit.disabled = false;

        // 419 is an expired CSRF token and 401 a session that has ended; either
        // way the answer is the sign-in page, which a reload reaches.
        if (response.status === 419 || response.status === 401) {
            window.markIntentionalNavigation?.();
            window.location.reload();

            return;
        }

        if (response.status === 429) {
            showError('Too many attempts. Wait a minute before trying again.');

            return;
        }

        const body = await response.json().catch(() => ({}));

        showError(body.errors?.password?.[0] ?? body.message ?? 'The password is incorrect.');
    });
}
