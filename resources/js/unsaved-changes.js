// A form somewhere on the page holding typed-but-unsubmitted data is easy to
// lose to an accidental tab close, reload, or back/forward navigation — the
// browser's own "Leave site?" prompt is the only warning that reaches that,
// so it is tracked here site-wide rather than per feature.
document.addEventListener('DOMContentLoaded', () => {
    const dirtyForms = new Set();

    // A GET form is a filter or search — reloading loses nothing worth
    // guarding, and warning on it would just be noise on ordinary browsing.
    const isGuardedForm = (form) => form instanceof HTMLFormElement
        && form.method.toUpperCase() !== 'GET'
        && !form.hasAttribute('data-no-dirty-check');

    const markDirty = (event) => {
        const form = event.target.closest('form');
        if (isGuardedForm(form)) dirtyForms.add(form);
    };

    document.addEventListener('input', markDirty);
    document.addEventListener('change', markDirty);

    // A real submit is the deliberate save this guard exists to protect —
    // the page navigating away right after is not the accident beforeunload
    // is meant to catch.
    document.addEventListener('submit', (event) => dirtyForms.delete(event.target));

    // Closing a modal — Cancel, its × button, the backdrop, Escape — is the
    // user explicitly abandoning whatever they typed into it. That choice
    // already happened, so a tab close afterwards has nothing left to warn
    // about for that form.
    document.addEventListener('hidden.bs.modal', (event) => {
        event.target.querySelectorAll('form').forEach((form) => dirtyForms.delete(form));
    });

    window.addEventListener('beforeunload', (event) => {
        if (!dirtyForms.size) return;
        event.preventDefault();
        event.returnValue = '';
    });
});
