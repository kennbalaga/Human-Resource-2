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
/*
 * Searchable user picker.
 *
 * The audit trail can name every account that ever wrote to it, which on a
 * hospital roster is a scroll rather than a choice. A plain text search would
 * have been the easy answer and the wrong one: the filter submits a user_id,
 * and matching on a typed name would put both Luz Santos and Luz Cruz in the
 * result on a page whose whole job is saying exactly whose actions these were.
 *
 * So the <select> stays. It stays in the form, it stays the element that
 * submits, and it stays the single source of truth for what is chosen — this
 * only draws a text field over it and writes the choice back. With scripting
 * unavailable nothing runs, the select is never hidden, and the filter works as
 * it always did.
 */
const buildUserPicker = (field) => {
    const select = field.querySelector('[data-user-picker-select]');

    if (!select) {
        return;
    }

    const options = Array.from(select.options);
    const listId = 'auditUserPickerList';

    const input = document.createElement('input');
    input.type = 'text';
    input.className = 'audit-combobox-input';
    input.autocomplete = 'off';
    input.placeholder = 'Search staff, or leave blank for all';
    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-expanded', 'false');
    input.setAttribute('aria-controls', listId);
    input.setAttribute('aria-autocomplete', 'list');
    input.setAttribute('aria-labelledby', 'auditUserLabel');

    const list = document.createElement('ul');
    list.className = 'audit-combobox-list';
    list.id = listId;
    list.setAttribute('role', 'listbox');
    list.setAttribute('aria-labelledby', 'auditUserLabel');
    list.hidden = true;

    const shell = document.createElement('div');
    shell.className = 'audit-combobox';
    shell.append(input, list);
    select.after(shell);
    select.hidden = true;

    // The select's own selected option is the label at rest, so a filtered page
    // reloads showing the name it is filtered by rather than an empty box.
    const labelFor = (option) => (option.value === '' ? '' : option.text.trim());
    let active = -1;
    let matches = [];

    const close = () => {
        list.hidden = true;
        input.setAttribute('aria-expanded', 'false');
        input.removeAttribute('aria-activedescendant');
        active = -1;
    };

    const commit = (option) => {
        select.value = option.value;
        input.value = labelFor(option);
        close();
    };

    const setActive = (next) => {
        const items = Array.from(list.children).filter((item) => item.dataset.value !== undefined);

        if (items.length === 0) {
            return;
        }

        active = (next + items.length) % items.length;
        items.forEach((item, index) => {
            const isActive = index === active;
            item.classList.toggle('is-active', isActive);
            item.setAttribute('aria-selected', isActive ? 'true' : 'false');
            if (isActive) {
                input.setAttribute('aria-activedescendant', item.id);
                item.scrollIntoView({ block: 'nearest' });
            }
        });
    };

    const render = (query) => {
        const needle = query.trim().toLowerCase();
        matches = options.filter((option) => option.value === '' || option.text.toLowerCase().includes(needle));
        list.textContent = '';

        if (matches.length === 0) {
            const empty = document.createElement('li');
            empty.className = 'audit-combobox-empty';
            empty.textContent = 'No staff match that name.';
            list.append(empty);
        }

        matches.forEach((option, index) => {
            const item = document.createElement('li');
            item.id = `${listId}-${index}`;
            item.className = 'audit-combobox-option';
            item.dataset.value = option.value;
            item.setAttribute('role', 'option');
            item.setAttribute('aria-selected', 'false');
            item.textContent = option.text.trim();
            if (option.value === select.value) {
                item.classList.add('is-chosen');
            }
            // mousedown, not click: blur fires first on click and would close
            // the list out from under the pointer.
            item.addEventListener('mousedown', (event) => {
                event.preventDefault();
                commit(option);
            });
            list.append(item);
        });

        list.hidden = false;
        input.setAttribute('aria-expanded', 'true');
        setActive(0);
    };

    input.value = labelFor(select.selectedOptions[0] ?? options[0]);

    input.addEventListener('input', () => render(input.value));
    input.addEventListener('focus', () => render(''));

    input.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            if (list.hidden) {
                render(input.value);

                return;
            }
            setActive(active + (event.key === 'ArrowDown' ? 1 : -1));

            return;
        }

        if (event.key === 'Enter' && !list.hidden) {
            // Only swallow Enter when it is choosing from an open list; on a
            // closed field it should submit the filter form like any other input.
            event.preventDefault();
            if (matches[active]) {
                commit(matches[active]);
            }

            return;
        }

        if (event.key === 'Escape' && !list.hidden) {
            event.stopPropagation();
            close();
        }
    });

    // A half-typed name is not a choice. Anything left in the box that was not
    // committed reverts to whatever the select still holds, so the visible text
    // and the value that would submit can never disagree.
    input.addEventListener('blur', () => {
        window.setTimeout(() => {
            input.value = labelFor(select.selectedOptions[0] ?? options[0]);
            close();
        }, 0);
    });
};

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-user-picker]').forEach(buildUserPicker);
});
