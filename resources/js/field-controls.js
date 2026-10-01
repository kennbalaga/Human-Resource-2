/**
 * The two pieces of a form this theme cannot reach.
 *
 * A <select>'s option list and a date input's calendar are drawn by the
 * browser itself, outside the page: they arrive in the operating system's own
 * typeface and accent colour, ignore every token in tokens.css, and look
 * different on each machine the hospital runs. No stylesheet can touch them.
 *
 * So the popups are replaced here — and only the popups. The native control
 * stays exactly where it is, keeps its name, value, required state, min/max,
 * native validation, and every CSS rule already written against `select` or
 * `input[type="date"]` anywhere in the app. Code elsewhere that reads
 * `select.value`, assigns it, hides an <option>, or dispatches a change event
 * behaves exactly as it did before, because the element it is talking to is
 * still the real one. Nothing on any page needed changing for this to apply.
 *
 * Accessibility follows from that: focus never leaves the native control while
 * a list is open, arrow keys still drive the select itself, and a screen
 * reader reads the same element it always did. The panel is a mirror of the
 * control's state, not a replacement for its semantics.
 *
 * Touch devices keep the native popups. A phone's own wheel and sheet are
 * built for a thumb and are better than anything a page can draw.
 */

const POINTER_IS_FINE = () => window.matchMedia('(hover: hover) and (pointer: fine)').matches;

const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
const WEEKDAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

const isoDate = (date) => [
    date.getFullYear(),
    String(date.getMonth() + 1).padStart(2, '0'),
    String(date.getDate()).padStart(2, '0'),
].join('-');

const parseIsoDate = (value) => {
    const parts = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(value ?? '').trim());

    return parts ? new Date(Number(parts[1]), Number(parts[2]) - 1, Number(parts[3])) : null;
};

const element = (tag, className, text) => {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined) node.textContent = text;

    return node;
};

/* ------------------------------------------------------------------ *
 * The floating layer
 *
 * One panel is open at a time, appended to <body> and positioned against its
 * anchor. Appending to the body rather than beside the field is what lets a
 * panel open out of a modal body or a scrolling table without being clipped by
 * either; being `position: fixed` is what keeps it on the anchor afterwards.
 * ------------------------------------------------------------------ */
let openPanel = null;

const closePanel = ({ restoreFocus = false } = {}) => {
    if (!openPanel) return;
    const { panel, anchor, onClose } = openPanel;
    openPanel = null;
    panel.remove();
    onClose?.();
    if (restoreFocus && anchor.isConnected) anchor.focus();
};

const positionPanel = () => {
    if (!openPanel) return;
    const { panel, anchor } = openPanel;
    if (!anchor.isConnected) {
        closePanel();

        return;
    }

    const rect = anchor.getBoundingClientRect();
    const margin = 8;
    const height = panel.offsetHeight;
    const width = panel.offsetWidth;
    // Below the field by default, above it when the room below runs out —
    // a list that opens off the bottom of the window is a list nobody can read.
    const below = window.innerHeight - rect.bottom - margin;
    const openUp = below < height && rect.top - margin > below;

    panel.style.top = openUp
        ? `${Math.max(margin, rect.top - height - 4)}px`
        : `${Math.min(window.innerHeight - height - margin, rect.bottom + 4)}px`;
    panel.style.left = `${Math.max(margin, Math.min(window.innerWidth - width - margin, rect.left))}px`;
};

const showPanel = (anchor, panel, { minWidth = true, onClose = null } = {}) => {
    closePanel();
    panel.classList.add('hr-field-panel');
    if (minWidth) panel.style.minWidth = `${anchor.getBoundingClientRect().width}px`;
    // Inside a modal the panel is parented to the modal, not the body: Bootstrap
    // pulls focus back whenever it lands outside the modal, which would take the
    // calendar's arrow keys away the moment a day was focused. Being
    // `position: fixed` is what keeps it unclipped either way — overflow on an
    // ancestor does not clip a fixed element.
    (anchor.closest('.modal') ?? document.body).append(panel);
    openPanel = { panel, anchor, onClose };
    positionPanel();
};

// Any scroll under the panel moves the field it belongs to, so the panel
// follows rather than hanging in place over unrelated content.
window.addEventListener('scroll', () => positionPanel(), true);
window.addEventListener('resize', () => positionPanel());
document.addEventListener('pointerdown', (event) => {
    if (!openPanel) return;
    if (openPanel.panel.contains(event.target) || openPanel.anchor === event.target) return;
    closePanel();
}, true);

/* ------------------------------------------------------------------ *
 * Select
 * ------------------------------------------------------------------ */

const enhanceableSelect = (node) => node instanceof HTMLSelectElement
    && !node.multiple
    && node.size <= 1
    && !node.disabled
    && !node.closest('[data-no-enhance]')
    && !node.hasAttribute('data-no-enhance');

/** The options a reader would actually see, in order, with their group. */
const visibleOptions = (select) => [...select.options]
    .filter((option) => !option.hidden && !option.closest('optgroup')?.hidden)
    .map((option) => ({ option, group: option.closest('optgroup')?.label ?? null }));

const openSelectPanel = (select) => {
    const rows = visibleOptions(select);
    if (!rows.length) return;

    const valueOnOpen = select.value;
    const panel = element('div', 'hr-select-panel');
    const list = element('div', 'hr-select-list');
    list.setAttribute('role', 'presentation');
    let lastGroup = null;
    const items = [];

    rows.forEach(({ option, group }) => {
        if (group !== lastGroup) {
            lastGroup = group;
            if (group) list.append(element('p', 'hr-select-group', group));
        }

        const item = element('button', 'hr-select-option');
        item.type = 'button';
        item.tabIndex = -1;
        item.disabled = option.disabled;
        item.dataset.value = option.value;
        item.append(element('span', 'hr-select-tick'), element('span', 'hr-select-label', option.textContent.trim()));
        if (option.selected) item.classList.add('is-selected');
        item.addEventListener('click', () => {
            select.value = option.value;
            select.dispatchEvent(new Event('input', { bubbles: true }));
            select.dispatchEvent(new Event('change', { bubbles: true }));
            closePanel({ restoreFocus: true });
        });
        // Hover highlights; it does not commit. Committing on hover is how a
        // mis-swiped mouse changes a schedule without anyone meaning to.
        item.addEventListener('mousemove', () => {
            list.querySelectorAll('.is-active').forEach((node) => node.classList.remove('is-active'));
            item.classList.add('is-active');
        });
        items.push(item);
        list.append(item);
    });

    panel.append(list);
    select.classList.add('hr-select-open');
    showPanel(select, panel, { onClose: () => select.classList.remove('hr-select-open') });

    // The highlight mirrors the native control: arrow keys still move the
    // select itself, and this follows whatever it lands on.
    const syncHighlight = () => {
        items.forEach((item) => {
            const current = item.dataset.value === select.value;
            item.classList.toggle('is-selected', current);
            item.classList.toggle('is-active', current);
        });
        items.find((item) => item.classList.contains('is-selected'))?.scrollIntoView({ block: 'nearest' });
    };
    syncHighlight();
    select.addEventListener('change', syncHighlight);
    openPanel.onClose = () => {
        select.classList.remove('hr-select-open');
        select.removeEventListener('change', syncHighlight);
    };

    return valueOnOpen;
};

let selectValueOnOpen = null;

document.addEventListener('mousedown', (event) => {
    const select = event.target instanceof Element ? event.target.closest('select') : null;
    if (!select || !POINTER_IS_FINE() || !enhanceableSelect(select)) return;

    // Suppressing the browser's own list is the whole point; without this both
    // would open at once.
    event.preventDefault();
    if (openPanel?.anchor === select) {
        closePanel({ restoreFocus: true });

        return;
    }
    select.focus();
    selectValueOnOpen = openSelectPanel(select);
}, true);

document.addEventListener('keydown', (event) => {
    const select = event.target instanceof Element ? event.target.closest('select') : null;
    if (!select || !POINTER_IS_FINE() || !enhanceableSelect(select)) return;

    const isOpen = openPanel?.anchor === select;
    const opens = event.key === ' ' || event.key === 'F4' || (event.altKey && event.key === 'ArrowDown');

    if (!isOpen && opens) {
        event.preventDefault();
        selectValueOnOpen = openSelectPanel(select);

        return;
    }

    if (!isOpen) return;

    if (event.key === 'Escape') {
        // What the native list does: back out, and put back what was there.
        event.preventDefault();
        event.stopPropagation();
        if (selectValueOnOpen !== null && select.value !== selectValueOnOpen) {
            select.value = selectValueOnOpen;
            select.dispatchEvent(new Event('change', { bubbles: true }));
        }
        closePanel({ restoreFocus: true });
    } else if (event.key === 'Enter' || event.key === 'Tab') {
        // Enter confirms the arrowed-to option rather than submitting the form
        // out from under a list the reviewer is still reading.
        if (event.key === 'Enter') event.preventDefault();
        closePanel({ restoreFocus: event.key === 'Enter' });
    }
}, true);

/* ------------------------------------------------------------------ *
 * Date
 *
 * The field itself is left alone — typing a date still goes through the
 * browser's own segmented input, which already handles locale, min/max and
 * validation. Only the calendar behind it is ours, reached through a button
 * that replaces the browser's indicator.
 * ------------------------------------------------------------------ */

const enhanceableDate = (input) => input instanceof HTMLInputElement
    && input.type === 'date'
    && !input.closest('[data-no-enhance]')
    && !input.hasAttribute('data-no-enhance')
    && !input.parentElement?.classList.contains('hr-date-field');

const buildCalendar = (input, button) => {
    const selected = parseIsoDate(input.value);
    const min = parseIsoDate(input.min);
    const max = parseIsoDate(input.max);
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    let cursor = new Date((selected ?? today).getFullYear(), (selected ?? today).getMonth(), 1);

    const panel = element('div', 'hr-date-panel');
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-modal', 'false');
    panel.setAttribute('aria-label', 'Choose a date');

    const head = element('header', 'hr-date-head');
    const previous = element('button', 'hr-date-step');
    previous.type = 'button';
    previous.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>';
    previous.setAttribute('aria-label', 'Previous month');
    const title = element('strong', 'hr-date-title');
    title.setAttribute('aria-live', 'polite');
    const next = element('button', 'hr-date-step');
    next.type = 'button';
    next.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>';
    next.setAttribute('aria-label', 'Next month');
    head.append(previous, title, next);

    const weekdays = element('div', 'hr-date-weekdays');
    WEEKDAYS.forEach((day) => {
        const cell = element('span', null, day);
        cell.setAttribute('aria-hidden', 'true');
        weekdays.append(cell);
    });

    const grid = element('div', 'hr-date-grid');
    grid.setAttribute('role', 'grid');

    const foot = element('footer', 'hr-date-foot');
    const todayButton = element('button', 'hr-date-action', 'Today');
    todayButton.type = 'button';
    const clearButton = element('button', 'hr-date-action', 'Clear');
    clearButton.type = 'button';
    foot.append(todayButton);
    if (!input.required && !input.readOnly) foot.append(clearButton);

    panel.append(head, weekdays, grid, foot);

    const outOfRange = (date) => (min && date < min) || (max && date > max);

    const commit = (value) => {
        input.value = value;
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
        closePanel();
        input.focus();
    };

    const draw = () => {
        title.textContent = `${MONTHS[cursor.getMonth()]} ${cursor.getFullYear()}`;
        grid.replaceChildren();

        // A fixed six-row month: the panel then keeps one height from March to
        // February instead of jumping as the reviewer pages through it.
        const first = new Date(cursor.getFullYear(), cursor.getMonth(), 1);
        const start = new Date(first);
        start.setDate(1 - first.getDay());

        for (let index = 0; index < 42; index += 1) {
            const date = new Date(start);
            date.setDate(start.getDate() + index);
            const value = isoDate(date);
            const day = element('button', 'hr-date-day', String(date.getDate()));
            day.type = 'button';
            day.dataset.date = value;
            day.tabIndex = -1;
            day.classList.toggle('is-outside', date.getMonth() !== cursor.getMonth());
            day.classList.toggle('is-today', value === isoDate(today));
            day.classList.toggle('is-selected', value === input.value);
            if (value === input.value) day.setAttribute('aria-current', 'date');
            day.disabled = outOfRange(date);
            day.addEventListener('click', () => commit(value));
            grid.append(day);
        }

        previous.disabled = Boolean(min) && new Date(cursor.getFullYear(), cursor.getMonth(), 0) < min;
        next.disabled = Boolean(max) && new Date(cursor.getFullYear(), cursor.getMonth() + 1, 1) > max;
        todayButton.disabled = outOfRange(today);
    };

    const move = (days) => {
        const from = parseIsoDate(input.value) ?? (outOfRange(today) ? (min ?? today) : today);
        const target = new Date(from);
        target.setDate(target.getDate() + days);
        if (outOfRange(target)) return;
        input.value = isoDate(target);
        cursor = new Date(target.getFullYear(), target.getMonth(), 1);
        draw();
        grid.querySelector('.is-selected')?.focus();
    };

    const page = (months) => {
        cursor = new Date(cursor.getFullYear(), cursor.getMonth() + months, 1);
        draw();
    };

    previous.addEventListener('click', () => page(-1));
    next.addEventListener('click', () => page(1));
    todayButton.addEventListener('click', () => commit(isoDate(today)));
    clearButton.addEventListener('click', () => commit(''));

    panel.addEventListener('keydown', (event) => {
        const steps = {
            ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7,
        };
        if (event.key in steps) {
            event.preventDefault();
            move(steps[event.key]);
        } else if (event.key === 'PageUp' || event.key === 'PageDown') {
            event.preventDefault();
            page(event.key === 'PageUp' ? -1 : 1);
        } else if (event.key === 'Escape') {
            event.preventDefault();
            closePanel();
            input.focus();
        }
    });

    draw();
    showPanel(button, panel, { minWidth: false, onClose: () => button.setAttribute('aria-expanded', 'false') });
    panel.style.left = `${Math.max(8, Math.min(window.innerWidth - panel.offsetWidth - 8, input.getBoundingClientRect().left))}px`;
    button.setAttribute('aria-expanded', 'true');
    (grid.querySelector('.is-selected:not(:disabled)') ?? grid.querySelector('.hr-date-day:not(.is-outside):not(:disabled)'))?.focus();
};

/* ------------------------------------------------------------------ *
 * Time
 *
 * Same bargain as the date field: the segmented input still types, and only
 * the browser's own three-column popup is replaced. The columns stay three —
 * hour, minute, AM/PM is the shape a clock field is read in, and inventing a
 * different one would only make a familiar control strange.
 * ------------------------------------------------------------------ */

const enhanceableTime = (input) => input instanceof HTMLInputElement
    && input.type === 'time'
    && !input.closest('[data-no-enhance]')
    && !input.hasAttribute('data-no-enhance')
    && !input.parentElement?.classList.contains('hr-time-field');

/** "14:05" as the three parts the panel is read in. */
const parseTime = (value) => {
    const parts = /^(\d{1,2}):(\d{2})/.exec(String(value ?? '').trim());
    if (!parts) return null;
    const hour = Number(parts[1]);

    return {
        hour12: hour % 12 === 0 ? 12 : hour % 12,
        minute: Number(parts[2]),
        meridiem: hour < 12 ? 'AM' : 'PM',
    };
};

const toTimeValue = ({ hour12, minute, meridiem }) => {
    const hour = meridiem === 'PM' ? (hour12 % 12) + 12 : hour12 % 12;

    return `${String(hour).padStart(2, '0')}:${String(minute).padStart(2, '0')}`;
};

const buildClock = (input, button) => {
    const now = new Date();
    // An empty field opens on the current time rather than on midnight: a
    // theatre list is written during the day it belongs to.
    const parts = parseTime(input.value) ?? {
        hour12: now.getHours() % 12 === 0 ? 12 : now.getHours() % 12,
        minute: now.getMinutes(),
        meridiem: now.getHours() < 12 ? 'AM' : 'PM',
    };
    let committed = Boolean(parseTime(input.value));

    const panel = element('div', 'hr-time-panel');
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-modal', 'false');
    panel.setAttribute('aria-label', 'Choose a time');

    const groups = [];
    const readout = element('strong', 'hr-time-readout');

    const commit = () => {
        committed = true;
        input.value = toTimeValue(parts);
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
        groups.forEach((group) => group.sync());
    };

    const syncReadout = () => {
        readout.textContent = committed
            ? `${String(parts.hour12).padStart(2, '0')}:${String(parts.minute).padStart(2, '0')} ${parts.meridiem}`
            : 'No time set';
        readout.classList.toggle('is-empty', !committed);
    };

    /** A block of choices laid out as a grid — every option in view at once. */
    const grid = (label, values, key, format, className) => {
        const section = element('div', 'hr-time-group');
        section.append(element('p', 'hr-time-label', label));
        const box = element('div', `hr-time-grid ${className}`);
        box.setAttribute('role', 'listbox');
        box.setAttribute('aria-label', label);

        const cells = values.map((value) => {
            const cell = element('button', 'hr-time-cell', format(value));
            cell.type = 'button';
            cell.setAttribute('role', 'option');
            cell.addEventListener('click', () => {
                parts[key] = value;
                commit();
            });
            box.append(cell);

            return { cell, value };
        });

        section.append(box);
        groups.push({
            sync: () => cells.forEach(({ cell, value }) => {
                const current = committed && value === parts[key];
                cell.classList.toggle('is-selected', current);
                cell.setAttribute('aria-selected', String(current));
            }),
        });

        return section;
    };

    const head = element('header', 'hr-time-head');
    head.append(readout);
    const meridiem = element('div', 'hr-time-meridiem');
    meridiem.setAttribute('role', 'listbox');
    meridiem.setAttribute('aria-label', 'AM or PM');
    ['AM', 'PM'].forEach((value) => {
        const cell = element('button', 'hr-time-half', value);
        cell.type = 'button';
        cell.setAttribute('role', 'option');
        cell.addEventListener('click', () => {
            parts.meridiem = value;
            commit();
        });
        meridiem.append(cell);
        groups.push({
            sync: () => {
                const current = committed && parts.meridiem === value;
                cell.classList.toggle('is-selected', current);
                cell.setAttribute('aria-selected', String(current));
            },
        });
    });
    head.append(meridiem);

    /*
     * Minutes in fives, not in sixties. A shift template and a theatre list are
     * written on the clock's own marks, and sixty rows behind a scrollbar made
     * the common case — half past, quarter to — the slow one. An existing value
     * that falls off those marks keeps its own cell, so a 7:37 handover already
     * on record is still shown and still selectable; anything else off the
     * marks is typed into the field itself, which never stopped accepting it.
     */
    // Only a value already on record earns an extra cell. Seeding this from the
    // opening time would put a stray 01 or 37 in the grid of every empty field.
    const onRecord = parseTime(input.value)?.minute;
    const minutes = [...new Set([
        ...Array.from({ length: 12 }, (unused, index) => index * 5),
        ...(onRecord === undefined ? [] : [onRecord]),
    ])].sort((a, b) => a - b);

    panel.append(
        head,
        grid('Hour', Array.from({ length: 12 }, (unused, index) => index + 1), 'hour12', (value) => String(value).padStart(2, '0'), 'is-hours'),
        grid('Minute', minutes, 'minute', (value) => String(value).padStart(2, '0'), 'is-minutes'),
    );

    const foot = element('footer', 'hr-time-foot');
    const nowButton = element('button', 'hr-date-action', 'Now');
    nowButton.type = 'button';
    nowButton.addEventListener('click', () => {
        const at = new Date();
        parts.hour12 = at.getHours() % 12 === 0 ? 12 : at.getHours() % 12;
        // Rounded to the same marks the grid offers, so what is set is a cell
        // that can be seen and changed rather than a value with no row.
        parts.minute = Math.round(at.getMinutes() / 5) % 12 * 5;
        parts.meridiem = at.getHours() < 12 ? 'AM' : 'PM';
        commit();
        syncReadout();
    });
    foot.append(nowButton);
    if (!input.required && !input.readOnly) {
        const clear = element('button', 'hr-date-action', 'Clear');
        clear.type = 'button';
        clear.addEventListener('click', () => {
            input.value = '';
            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.dispatchEvent(new Event('change', { bubbles: true }));
            closePanel();
            input.focus();
        });
        foot.append(clear);
    }
    panel.append(foot);

    panel.addEventListener('click', syncReadout);
    panel.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' || event.key === 'Enter') {
            event.preventDefault();
            closePanel();
            input.focus();
        }
    });

    showPanel(button, panel, { minWidth: false, onClose: () => button.setAttribute('aria-expanded', 'false') });
    panel.style.left = `${Math.max(8, Math.min(window.innerWidth - panel.offsetWidth - 8, input.getBoundingClientRect().left))}px`;
    button.setAttribute('aria-expanded', 'true');
    groups.forEach((group) => group.sync());
    syncReadout();
    (panel.querySelector('.hr-time-cell.is-selected') ?? panel.querySelector('.hr-time-cell'))?.focus();
};

/* ------------------------------------------------------------------ *
 * The trigger both fields share
 * ------------------------------------------------------------------ */

/**
 * Wraps a native field, hides the browser's own indicator through the wrapper
 * class, and hangs our panel off a button in its place. Everything either
 * field needs beyond its own panel is here, so the two cannot drift apart.
 */
const attachTrigger = (input, { field, inset, label, icon, open }) => {
    const wrapper = element('span', field);
    input.replaceWith(wrapper);
    wrapper.append(input);

    const button = element('button', `${field.replace('-field', '')}-trigger`);
    button.type = 'button';
    button.tabIndex = -1;
    button.setAttribute('aria-expanded', 'false');
    button.setAttribute('aria-label', label);
    button.innerHTML = icon;
    button.addEventListener('click', () => {
        if (openPanel?.anchor === button) {
            closePanel();

            return;
        }
        if (!input.disabled && !input.readOnly) open(input, button);
    });
    wrapper.append(button);

    // The field can be narrower than the cell it sits in, so the button is
    // placed against the field's own right edge rather than the wrapper's.
    const trackWidth = () => {
        const gap = wrapper.offsetWidth - input.offsetLeft - input.offsetWidth;
        wrapper.style.setProperty(inset, `${Math.max(0, gap)}px`);
    };
    trackWidth();
    if (window.ResizeObserver) new ResizeObserver(trackWidth).observe(input);

    const syncDisabled = () => {
        button.disabled = input.disabled || input.readOnly;
        wrapper.classList.toggle('is-disabled', button.disabled);
    };
    syncDisabled();
    new MutationObserver(syncDisabled).observe(input, { attributes: true, attributeFilter: ['disabled', 'readonly'] });

    // Alt+Down and F4 are how a keyboard opens one of these; they would
    // otherwise open the browser's.
    input.addEventListener('keydown', (event) => {
        if (event.key === 'F4' || (event.altKey && event.key === 'ArrowDown')) {
            event.preventDefault();
            if (!input.disabled && !input.readOnly) open(input, button);
        }
    });
};

const enhanceDate = (input) => {
    if (!enhanceableDate(input)) return;
    attachTrigger(input, {
        field: 'hr-date-field',
        inset: '--hr-date-inset',
        label: 'Open the calendar',
        icon: '<svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4.5" width="18" height="16" rx="2.5"/><path d="M16 2.5v4M8 2.5v4M3 10h18"/></svg>',
        open: buildCalendar,
    });
};

const enhanceTime = (input) => {
    if (!enhanceableTime(input)) return;
    attachTrigger(input, {
        field: 'hr-time-field',
        inset: '--hr-time-inset',
        label: 'Open the clock',
        icon: '<svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7.5V12l3 1.8"/></svg>',
        open: buildClock,
    });
};

const enhanceFieldsIn = (root) => {
    if (!POINTER_IS_FINE()) return;
    const scope = root instanceof Element ? root : document;
    if (scope instanceof HTMLInputElement) {
        enhanceDate(scope);
        enhanceTime(scope);

        return;
    }
    scope.querySelectorAll?.('input[type="date"]').forEach(enhanceDate);
    scope.querySelectorAll?.('input[type="time"]').forEach(enhanceTime);
};

document.addEventListener('DOMContentLoaded', () => {
    enhanceFieldsIn(document);

    // Fields that arrive later — a row added to a form, a panel rendered by
    // another module — are picked up without that module knowing about this one.
    new MutationObserver((records) => {
        records.forEach((record) => record.addedNodes.forEach((node) => {
            if (node.nodeType === Node.ELEMENT_NODE) enhanceFieldsIn(node);
        }));
    }).observe(document.body, { childList: true, subtree: true });
});

// A modal closing while a list is open would otherwise leave the list behind.
document.addEventListener('hide.bs.modal', () => closePanel(), true);
