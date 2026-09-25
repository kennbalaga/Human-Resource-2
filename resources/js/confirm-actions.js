/**
 * Confirmation prompts for destructive actions.
 *
 * These used to live in `onsubmit="return confirm('...')"` attributes. An
 * inline handler is script in an attribute, so a Content Security Policy can
 * only permit it with `script-src 'unsafe-inline'` — and that one allowance
 * also permits any <script> an attacker manages to inject, which is the whole
 * thing the policy exists to stop. A nonce does not help: it applies to script
 * elements, never to attributes.
 *
 * So the message moves to `data-confirm` and the behaviour moves here, to one
 * delegated listener on the document. Markup that arrives later — a panel
 * re-rendered over AJAX, a row added to a table — is covered without
 * re-binding, because nothing is bound per element in the first place.
 *
 * The prompt is the app's own dialog (partials/confirm-dialog) rather than the
 * browser's confirm(), which labels every choice "OK", shows the server's
 * hostname, ignores dark mode and is suppressed outright by some installed PWAs.
 *
 * Markup:
 *   data-confirm          "Question? What happens." The first sentence up to
 *                         the question mark is the title, the rest explains it.
 *   data-confirm-button   The confirm label: the verb and object, never "OK".
 *   data-confirm-cancel   The back-out label. "Cancel" unless the action is
 *                         itself a cancel, then "Keep request" and the like.
 *   data-confirm-tone     neutral (default) | caution | danger.
 *
 * A button wired up in script cannot be stopped by this listener — cancelling
 * a click does nothing to another script's click handler — so those call
 * confirmAction() and wait for the answer instead.
 */

const TONES = ['neutral', 'caution', 'danger'];
const KICKERS = { neutral: 'Please confirm', caution: 'Please confirm', danger: 'This can’t be undone' };

const dialog = document.querySelector('[data-confirm-dialog]');
const homeParent = dialog?.parentElement ?? null;
let pending = null;

// "Archive Maria Santos? The record is kept…" → title and explanation.
const splitMessage = (text) => {
    const match = text.match(/^(.+?\?)\s+([\s\S]+)$/);

    return match ? [match[1], match[2]] : [text, ''];
};

/**
 * Ask, and resolve true only on an explicit confirm. Esc, a click on the
 * backdrop and the cancel button all resolve false.
 *
 * @param {{ title?: string, message?: string, text?: string, button?: string, cancel?: string, tone?: string }} options
 * @returns {Promise<boolean>}
 */
export const confirmAction = (options) => {
    let { title = '', message = '' } = options;

    if (options.text) {
        [title, message] = splitMessage(options.text.trim());
    }

    // Pages outside the app layout (sign-in, two-factor) have no dialog; the
    // browser's box is still better than acting without asking.
    if (!dialog || typeof dialog.showModal !== 'function') {
        return Promise.resolve(window.confirm([title, message].filter(Boolean).join('\n\n')));
    }

    // A prompt already showing is answered "no" and handed over; the dialog
    // stays open for this one rather than closing and reopening.
    pending?.();

    const tone = TONES.includes(options.tone) ? options.tone : 'neutral';
    const ok = dialog.querySelector('[data-confirm-ok]');
    const cancel = dialog.querySelector('[data-confirm-cancel]');

    dialog.dataset.tone = tone;
    dialog.querySelector('[data-confirm-kicker]').textContent = KICKERS[tone];
    dialog.querySelector('[data-confirm-title]').textContent = title;
    dialog.querySelector('[data-confirm-message]').textContent = message;
    ok.textContent = options.button || 'Confirm';
    ok.className = `btn ${tone === 'danger' ? 'btn-danger' : 'btn-primary'}`;
    cancel.textContent = options.cancel || 'Cancel';

    // An open Bootstrap offcanvas or modal traps focus inside itself and would
    // pull it straight back out of a dialog that sits elsewhere in the DOM.
    // Parenting the dialog inside it keeps focus "inside" as far as that trap
    // is concerned; showModal() still lifts it into the top layer visually.
    const trap = document.querySelector('.offcanvas.show, .modal.show');
    const parent = trap ?? homeParent;
    if (parent && dialog.parentElement !== parent) {
        parent.append(dialog);
    }

    const opener = document.activeElement;

    return new Promise((resolve) => {
        const listening = new AbortController();
        const finish = (answer) => {
            listening.abort();
            pending = null;
            resolve(answer);
        };
        pending = () => finish(false);

        dialog.returnValue = 'cancel';
        dialog.addEventListener('close', () => {
            finish(dialog.returnValue === 'ok');
            // Home again, so a panel that re-renders its contents cannot take
            // the one dialog with it.
            if (homeParent && dialog.parentElement !== homeParent) homeParent.append(dialog);
            if (opener instanceof HTMLElement && opener.isConnected) opener.focus({ preventScroll: true });
        }, { signal: listening.signal });

        if (!dialog.open) dialog.showModal();

        // Enter must never delete anything: on a danger prompt the safe
        // choice holds focus.
        (tone === 'danger' ? cancel : ok).focus();
    });
};

dialog?.addEventListener('click', (event) => {
    // A click on the ::backdrop lands on the dialog element itself.
    if (event.target === dialog) dialog.close('cancel');
});

const optionsFrom = (element) => ({
    text: element.dataset.confirm,
    button: element.dataset.confirmButton,
    cancel: element.dataset.confirmCancel,
    tone: element.dataset.confirmTone,
});

// Forms and triggers the user has already said yes to, so the replayed event
// goes through instead of asking again.
const approved = new WeakSet();

document.addEventListener('submit', (event) => {
    const form = event.target.closest('form[data-confirm]');

    if (!form || form.dataset.confirm.trim() === '') {
        return;
    }

    if (approved.has(form)) {
        approved.delete(form);
        return;
    }

    event.preventDefault();
    // Stop here so no later submit listener acts on a submission that has not
    // happened yet; the replay below reaches them.
    event.stopImmediatePropagation();

    const submitter = event.submitter;

    confirmAction(optionsFrom(form)).then((confirmed) => {
        if (!confirmed) return;

        approved.add(form);
        // requestSubmit keeps the button that was pressed — its name, value and
        // formaction — and runs validation, which form.submit() would skip.
        if (submitter && submitter.form === form) {
            form.requestSubmit(submitter);
        } else {
            form.requestSubmit();
        }
    });
}, true);

// The same contract for a button or link that acts without submitting a form.
document.addEventListener('click', (event) => {
    const trigger = event.target.closest('button[data-confirm], a[data-confirm]');

    if (!trigger || trigger.closest('form[data-confirm]') || trigger.dataset.confirm.trim() === '') {
        return;
    }

    if (approved.has(trigger)) {
        approved.delete(trigger);
        return;
    }

    event.preventDefault();
    event.stopImmediatePropagation();

    confirmAction(optionsFrom(trigger)).then((confirmed) => {
        if (!confirmed) return;

        approved.add(trigger);
        trigger.click();
    });
}, true);
