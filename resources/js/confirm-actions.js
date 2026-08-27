/**
 * Confirmation prompts for destructive form submissions.
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
 */
document.addEventListener('submit', (event) => {
    const form = event.target.closest('form[data-confirm]');

    if (!form) {
        return;
    }

    const message = form.dataset.confirm.trim();

    if (message !== '' && !window.confirm(message)) {
        event.preventDefault();
    }
}, true);

// The same contract for a button that acts without submitting a form.
document.addEventListener('click', (event) => {
    const trigger = event.target.closest('button[data-confirm], a[data-confirm]');

    if (!trigger || trigger.closest('form[data-confirm]')) {
        return;
    }

    const message = trigger.dataset.confirm.trim();

    if (message !== '' && !window.confirm(message)) {
        event.preventDefault();
    }
}, true);
