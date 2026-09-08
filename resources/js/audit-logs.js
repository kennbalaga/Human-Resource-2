/**
 * Audit Logs page behaviour: the shared event-detail dialog and export feedback.
 *
 * Both features are delegated from the document rather than bound per element.
 * The table is re-rendered on every page of the paginator, and a Content
 * Security Policy forbids the inline handlers this would otherwise reach for,
 * so one listener that survives the markup is the only arrangement that stays
 * correct.
 */

/* Populate the single detail modal from the row button that opened it. */
document.addEventListener('click', (event) => {
    const trigger = event.target.closest('[data-audit-detail]');
    const modal = document.getElementById('auditDetailModal');

    if (!trigger || !modal) {
        return;
    }

    const set = (field, value) => {
        modal.querySelectorAll(`[data-detail="${field}"]`).forEach((node) => {
            node.textContent = value;
        });
    };

    set('event-id', `#${trigger.dataset.eventId}`);
    set('action', trigger.dataset.action);
    set('action-raw', trigger.dataset.actionRaw);
    set('route', trigger.dataset.route);
    set('user', trigger.dataset.user);
    set('user-role', trigger.dataset.userRole);
    set('activity', trigger.dataset.activity);
    set('module', trigger.dataset.module);
    set('subject', trigger.dataset.subject);
    set('method', trigger.dataset.method);
    set('endpoint', trigger.dataset.endpoint);
    set('ip', trigger.dataset.ip);
    set('request-id', trigger.dataset.requestId);
    set('device', trigger.dataset.device);
    set('status', trigger.dataset.status);
    set('status-label', trigger.dataset.statusLabel);

    // An empty field list is the ordinary case for a GET-shaped action such as
    // a sign-out, and printing nothing there reads as a rendering fault.
    set('fields', trigger.dataset.fields || 'No form fields were submitted with this request.');

    const timestamp = modal.querySelector('[data-detail="timestamp"]');
    if (timestamp) {
        timestamp.textContent = trigger.dataset.timestamp;
        timestamp.setAttribute('datetime', trigger.dataset.timestampIso);
    }

    // The badge keeps its tone class in step with the row it came from, so the
    // dialog cannot show a green pill for a blocked request.
    const badge = modal.querySelector('[data-detail="status-badge"]');
    if (badge) {
        badge.className = `audit-status audit-status-${trigger.dataset.statusTone}`;
    }
});

/*
 * Export feedback.
 *
 * A streamed CSV download replaces no document and fires no load event, so the
 * page has nothing of its own to listen for. The request carries a token the
 * page invented; the server echoes it back as a cookie once it starts flushing
 * the file. Seeing that cookie appear is the only honest "the download has
 * begun" signal available here — anything else would be a guess dressed up as
 * confirmation.
 */
const EXPORT_COOKIE = 'audit_export_token';
const EXPORT_TIMEOUT_MS = 60000;
const EXPORT_POLL_MS = 400;

const clearExportCookie = () => {
    document.cookie = `${EXPORT_COOKIE}=; Max-Age=0; path=/`;
};

document.addEventListener('click', (event) => {
    const link = event.target.closest('[data-audit-export]');
    const status = document.querySelector('[data-audit-export-status]');

    if (!link || !status) {
        return;
    }

    const token = `${Date.now()}-${Math.random().toString(36).slice(2, 10)}`;
    const url = new URL(link.href, window.location.origin);
    url.searchParams.set('export_token', token);
    link.href = url.toString();

    const scope = url.searchParams.get('scope') === 'all' ? 'the full audit trail' : 'the filtered results';

    status.hidden = false;
    status.dataset.state = 'working';
    status.textContent = `Preparing the CSV export of ${scope}…`;

    clearExportCookie();
    const startedAt = Date.now();
    const poll = window.setInterval(() => {
        const ready = document.cookie.split('; ').some((entry) => entry.startsWith(`${EXPORT_COOKIE}=`));

        if (ready) {
            window.clearInterval(poll);
            clearExportCookie();
            status.dataset.state = 'done';
            status.textContent = `Export ready — the CSV of ${scope} has been sent to your downloads.`;

            return;
        }

        if (Date.now() - startedAt > EXPORT_TIMEOUT_MS) {
            window.clearInterval(poll);
            status.dataset.state = 'stalled';
            status.textContent = 'The export is taking longer than expected. It may still be downloading — check your browser downloads before trying again.';
        }
    }, EXPORT_POLL_MS);
});
