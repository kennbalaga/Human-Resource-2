/**
 * The recurring-schedule and assign-a-shift forms on the schedules page, and
 * the read-back dialog shown after either one creates something.
 *
 * Recurring schedule: every change re-asks the server which dates the series
 * would create and which it would skip (and why), so the preview on the right
 * is the same answer the create path will act on — the browser never decides
 * on its own whether a date clashes.
 *
 * Assign a shift: shows the cover already standing on the chosen shift and
 * date before anyone is picked, and can turn the assignment into a weekly
 * series on the same weekday, sent through the recurring-schedule endpoint.
 *
 * Both forms ask before discarding what was typed when they are closed.
 */
import { confirmAction } from './confirm-actions';

const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

const SHORT_DAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
const LONG_DAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

const parseDate = (value) => new Date(`${value}T00:00:00`);
const isoDate = (date) => [date.getFullYear(), String(date.getMonth() + 1).padStart(2, '0'), String(date.getDate()).padStart(2, '0')].join('-');
const shortDate = (value) => {
    const date = parseDate(value);

    return `${MONTHS[date.getMonth()]} ${date.getDate()}`;
};
const longDate = (value) => {
    const date = parseDate(value);

    return `${SHORT_DAYS[date.getDay()]}, ${MONTHS[date.getMonth()]} ${date.getDate()}`;
};
const plural = (count, one, many) => `${count} ${count === 1 ? one : many}`;

const element = (tag, className, text) => {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined) node.textContent = text;

    return node;
};

const firstError = (payload, fallback) => payload?.message && !payload.errors
    ? payload.message
    : Object.values(payload?.errors ?? {})[0]?.[0] ?? fallback;

/** The form's current values as one comparable string. */
const snapshot = (form) => JSON.stringify([...new FormData(form).entries()].filter(([name]) => name !== '_token'));

/**
 * Ask before a form with unsaved changes is closed. Submitting, or a close
 * the reviewer already confirmed, passes straight through.
 */
const guardDiscard = (modalElement, form, { text, isDirty }) => {
    if (!modalElement || !form) return () => {};
    let allowClose = false;
    let submitting = false;

    form.addEventListener('submit', () => { submitting = true; });
    modalElement.addEventListener('show.bs.modal', () => {
        allowClose = false;
        submitting = false;
    });
    modalElement.addEventListener('hide.bs.modal', (event) => {
        if (allowClose || submitting || !isDirty()) return;
        event.preventDefault();
        confirmAction({ text, button: 'Discard', cancel: 'Keep editing', tone: 'caution' }).then((confirmed) => {
            if (!confirmed) return;
            allowClose = true;
            window.bootstrap?.Modal.getOrCreateInstance(modalElement).hide();
        });
    });

    // Lets a close that carries the typed values somewhere else skip the prompt.
    return () => { allowClose = true; };
};

document.addEventListener('DOMContentLoaded', () => {
    // -----------------------------------------------------------------
    // Confirmation read-back after a create
    // -----------------------------------------------------------------
    const confirmation = document.querySelector('#scheduleConfirmationModal');
    if (confirmation && window.bootstrap) {
        const dialog = window.bootstrap.Modal.getOrCreateInstance(confirmation);
        // "Create another" closes this read-back first, then opens the form it
        // came from, so two modals are never stacked.
        confirmation.querySelector('[data-confirmation-again]')?.addEventListener('click', (event) => {
            const target = document.querySelector(event.currentTarget.dataset.bsTarget);
            confirmation.addEventListener('hidden.bs.modal', () => {
                if (target) window.bootstrap.Modal.getOrCreateInstance(target).show();
            }, { once: true });
            dialog.hide();
        });
        dialog.show();
    }

    // -----------------------------------------------------------------
    // Recurring schedule
    // -----------------------------------------------------------------
    const recurringModal = document.querySelector('#recurringScheduleModal');
    const recurringForm = document.querySelector('#recurringScheduleForm');

    if (recurringForm) {
        const preview = recurringForm.querySelector('[data-recurring-preview]');
        const summary = recurringForm.querySelector('[data-recurrence-summary]');
        const summarySub = recurringForm.querySelector('[data-recurring-summary-sub]');
        const createCount = recurringForm.querySelector('[data-recurring-create]');
        const createNote = recurringForm.querySelector('[data-recurring-create-note]');
        const hoursValue = recurringForm.querySelector('[data-recurring-hours]');
        const hoursNote = recurringForm.querySelector('[data-recurring-hours-note]');
        const calendar = recurringForm.querySelector('[data-recurring-calendar]');
        const checks = recurringForm.querySelector('[data-recurring-checks]');
        const conflicts = recurringForm.querySelector('[data-recurring-conflicts]');
        const conflictTitle = recurringForm.querySelector('[data-recurring-conflicts-title]');
        const conflictList = recurringForm.querySelector('[data-recurring-conflict-list]');
        const skipToggle = recurringForm.querySelector('[data-recurring-skip-toggle]');
        const skipField = recurringForm.querySelector('[data-recurring-skip]');
        const empty = recurringForm.querySelector('[data-recurring-preview-empty]');
        const submit = recurringForm.querySelector('[data-recurring-submit]');
        const reason = recurringForm.querySelector('[data-recurring-reason]');
        const weekdaySelector = recurringForm.querySelector('[data-weekday-selector]');
        const presets = recurringForm.querySelector('[data-weekday-presets]');
        const patternOn = recurringForm.querySelector('[data-recurring-pattern-on]');
        const employeeSelect = recurringForm.querySelector('[data-recurring-employee-select]');
        const employeeMeta = recurringForm.querySelector('[data-recurring-employee-meta]');
        const endModes = [...recurringForm.querySelectorAll('[data-recurring-end-mode]')];
        const endDate = recurringForm.querySelector('[data-recurring-end-date]');
        const occurrences = recurringForm.querySelector('[data-recurring-occurrences]');
        let lastPreview = null;
        let previewTimer = null;
        let previewSequence = 0;
        let initialState = snapshot(recurringForm);

        const syncEmployeeMeta = () => {
            if (!employeeMeta || !employeeSelect) return;
            const option = employeeSelect.selectedOptions[0];
            const parts = [option?.dataset.position, option?.dataset.number].filter(Boolean);
            employeeMeta.textContent = parts.join(' · ');
            employeeMeta.hidden = parts.length === 0;
        };

        // Only the live mode posts. The other's control is disabled, which keeps
        // it out of the FormData and out of the tab order, so "ends on a date"
        // and "ends after N shifts" can never both arrive.
        const syncEndMode = () => {
            const after = recurringForm.elements.end_mode?.value === 'after';
            if (endDate) { endDate.disabled = after; endDate.required = !after; }
            if (occurrences) { occurrences.disabled = !after; occurrences.required = after; }
            endModes.forEach((radio) => radio.closest('.recurring-end-mode')?.classList.toggle('is-active', radio.checked));
        };

        const selectedIsoDays = () => [...recurringForm.querySelectorAll('input[name="weekdays[]"]:checked')].map((input) => Number(input.value));

        const setReason = (text) => {
            reason.hidden = !text;
            reason.textContent = text ?? '';
            submit.disabled = Boolean(text);
        };

        const patternText = () => {
            if (recurringForm.elements.recurrence_type.value === 'daily') return 'Every day';
            const interval = Number(recurringForm.elements.interval_weeks.value) || 1;
            const days = [...recurringForm.querySelectorAll('input[name="weekdays[]"]:checked')]
                .map((input) => input.nextElementSibling.textContent.trim());
            const everyText = interval === 1 ? 'Every week' : `Every ${interval} weeks`;
            const iso = selectedIsoDays();
            let dayText = days.join(', ');
            if (iso.length === 5 && [1, 2, 3, 4, 5].every((day) => iso.includes(day))) dayText = 'weekdays';
            if (iso.length === 2 && iso.includes(6) && iso.includes(7)) dayText = 'weekends';

            return days.length ? `${everyText} on ${dayText}` : `${everyText} on no days yet`;
        };

        const syncPattern = () => {
            const weekly = recurringForm.elements.recurrence_type.value === 'weekly';
            weekdaySelector.hidden = !weekly;
            if (presets) presets.hidden = !weekly;
            if (patternOn) patternOn.hidden = !weekly;
            recurringForm.elements.interval_weeks.disabled = !weekly;
            recurringForm.elements.interval_weeks.hidden = !weekly;
            summary.textContent = patternText();
        };

        const resetPreview = (message) => {
            lastPreview = null;
            createCount.textContent = '—';
            createNote.textContent = '';
            hoursValue.textContent = '—';
            hoursNote.textContent = '';
            calendar.hidden = true;
            calendar.replaceChildren();
            checks.replaceChildren();
            conflicts.hidden = true;
            empty.hidden = false;
            empty.textContent = message;
            summarySub.textContent = '';
            submit.textContent = 'Create series';
        };

        const renderCalendar = (data) => {
            calendar.replaceChildren();
            const startsOn = Number(data.limits?.week_starts_on ?? 0);
            const byDate = new Map(data.dates.map((row) => [row.date, row]));
            const first = parseDate(data.range.start);
            const last = parseDate(data.range.end);
            const gridStart = new Date(first);
            gridStart.setDate(gridStart.getDate() - ((gridStart.getDay() - startsOn + 7) % 7));
            const totalWeeks = Math.floor((last - gridStart) / (7 * 86400000)) + 1;
            const shownWeeks = Math.min(6, totalWeeks);

            const table = element('div', 'recurring-calendar-table');
            table.setAttribute('role', 'table');
            table.setAttribute('aria-label', 'Series calendar');
            const head = element('div', 'recurring-calendar-row');
            head.setAttribute('role', 'row');
            for (let offset = 0; offset < 7; offset += 1) {
                const cell = element('span', 'recurring-calendar-head', SHORT_DAYS[(startsOn + offset) % 7]);
                cell.setAttribute('role', 'columnheader');
                head.append(cell);
            }
            table.append(head);

            for (let week = 0; week < shownWeeks; week += 1) {
                const row = element('div', 'recurring-calendar-row');
                row.setAttribute('role', 'row');
                for (let day = 0; day < 7; day += 1) {
                    const date = new Date(gridStart);
                    date.setDate(gridStart.getDate() + (week * 7) + day);
                    const key = isoDate(date);
                    const entry = byDate.get(key);
                    const inRange = date >= first && date <= last;
                    const label = date.getDate() === 1 || (week === 0 && day === 0) ? `${MONTHS[date.getMonth()]} ${date.getDate()}` : String(date.getDate());
                    const cell = element('span', 'recurring-calendar-cell', label);
                    cell.setAttribute('role', 'cell');
                    const notes = [];
                    if (!inRange) cell.classList.add('is-outside');
                    if (entry?.status === 'scheduled') { cell.classList.add('is-scheduled'); notes.push('scheduled'); }
                    if (entry?.status === 'skipped') { cell.classList.add('is-skipped'); notes.push('skipped, conflict'); }
                    if (entry?.holiday) { cell.classList.add('is-holiday'); notes.push(entry.holiday); }
                    if (entry?.over_cover) { cell.classList.add('is-over-cover'); notes.push('already covered'); }
                    cell.setAttribute('aria-label', `${LONG_DAYS[date.getDay()]}, ${MONTHS[date.getMonth()]} ${date.getDate()}${notes.length ? `, ${notes.join(', ')}` : ''}`);
                    if (entry?.reason) cell.title = entry.reason;
                    else if (entry?.holiday) cell.title = `${entry.holiday} (${entry.holiday_type})`;
                    row.append(cell);
                }
                table.append(row);
            }

            calendar.append(table);
            if (totalWeeks > shownWeeks) {
                calendar.append(element('p', 'recurring-calendar-more', `Showing the first 6 weeks · ${plural(totalWeeks - shownWeeks, 'more week', 'more weeks')} in the series.`));
            }
            const legend = element('div', 'recurring-calendar-legend');
            [['is-scheduled', 'Scheduled'], ['is-skipped', 'Conflict'], ['is-outside', 'Outside range'], ['is-holiday', 'Regular holiday']].forEach(([modifier, text]) => {
                const item = element('span');
                item.append(element('i', `recurring-legend-swatch ${modifier}`), document.createTextNode(text));
                legend.append(item);
            });
            calendar.append(legend);
            calendar.hidden = false;
        };

        const renderChecks = (data) => {
            checks.replaceChildren();
            const skipped = data.summary.skipped;
            const busiest = data.busiest_week;
            const limit = data.limits.max_hours_per_week;
            const over = data.checks.over_cover_dates ?? [];
            const holidays = data.checks.holidays ?? [];
            const rows = [
                {
                    ok: skipped === 0,
                    title: 'Conflicts',
                    detail: skipped === 0
                        ? 'No clashes with existing shifts, leave, days off, locks, or the minimum rest rule.'
                        : `${plural(skipped, 'date clashes', 'dates clash')} with an existing shift, leave, a day off, a lock, or the minimum rest rule.`,
                },
                {
                    ok: data.checks.rest_ok,
                    title: 'Weekly rest day · Labor Code Art. 91',
                    detail: data.checks.rest_ok
                        ? 'Every week keeps a rest day once existing shifts are counted.'
                        : 'At least one week would leave no rest day once existing shifts are counted.',
                },
                {
                    ok: data.checks.hours_ok,
                    title: 'Weekly hours',
                    detail: busiest
                        ? `Busiest week: ${busiest.hours} of ${limit} paid hours (week of ${shortDate(busiest.week_start)}), counting existing shifts.`
                        : 'No hours in this range yet.',
                },
                {
                    ok: over.length === 0,
                    title: 'Coverage',
                    detail: over.length === 0
                        ? 'Nobody else already fills this shift on these dates.'
                        : `This shift is already fully staffed on ${over.slice(0, 5).map(shortDate).join(', ')}${over.length > 5 ? ` and ${over.length - 5} more` : ''}; those dates would be over cover.`,
                },
                {
                    ok: holidays.length === 0,
                    title: 'Holidays · Labor Code Art. 94',
                    detail: holidays.length === 0
                        ? 'No holidays on record fall on these dates.'
                        : `${holidays.slice(0, 4).map((holiday) => `${shortDate(holiday.date)} (${holiday.name})`).join(', ')}${holidays.length > 4 ? ` and ${holidays.length - 4} more` : ''}: holiday pay applies.`,
                },
            ];
            rows.forEach((row) => {
                const item = element('li', `recurring-check ${row.ok ? 'is-ok' : 'is-review'}`);
                item.append(element('span', 'validation-check-mark', row.ok ? '✓' : '!'));
                item.lastChild.setAttribute('aria-hidden', 'true');
                const body = element('div');
                body.append(element('span', 'visually-hidden', row.ok ? 'Passed: ' : 'Needs review: '), element('strong', null, row.title), element('small', null, row.detail));
                item.append(body);
                checks.append(item);
            });
        };

        const renderConflicts = (data) => {
            const skippedRows = data.dates.filter((row) => row.status === 'skipped');
            conflicts.hidden = skippedRows.length === 0;
            conflictList.replaceChildren();
            if (!skippedRows.length) return;
            conflictTitle.textContent = `${plural(skippedRows.length, 'conflict', 'conflicts')} found`;
            skippedRows.forEach((row) => {
                const item = element('li');
                item.append(element('strong', null, longDate(row.date)), element('span', null, row.reason));
                conflictList.append(item);
            });
        };

        const syncSubmit = () => {
            if (!lastPreview) return;
            const { create, skipped } = lastPreview.summary;
            skipField.value = skipped > 0 && skipToggle.checked ? '1' : '0';
            submit.textContent = `Create ${plural(create, 'shift', 'shifts')}`;
            if (create === 0) {
                setReason('Every date in this series clashes, so there is nothing to create.');
            } else if (skipped > 0 && !skipToggle.checked) {
                setReason(`Skip or resolve ${plural(skipped, 'conflict', 'conflicts')} to continue.`);
            } else {
                setReason(null);
            }
        };

        const renderPreview = (data) => {
            lastPreview = data;
            empty.hidden = true;
            summarySub.textContent = `${data.shift.name} · ${data.shift.time} · ${data.employee.name}`;
            createCount.textContent = String(data.summary.create);
            createNote.textContent = `${shortDate(data.range.start)} – ${shortDate(data.range.end)}${data.summary.skipped ? ` · ${data.summary.skipped} skipped` : ''}`;
            hoursValue.textContent = data.busiest_week ? String(data.busiest_week.hours) : '0';
            hoursNote.textContent = data.busiest_week
                ? `Week of ${shortDate(data.busiest_week.week_start)} · limit ${data.limits.max_hours_per_week}`
                : `Limit ${data.limits.max_hours_per_week}`;
            renderCalendar(data);
            renderChecks(data);
            renderConflicts(data);
            syncSubmit();
        };

        const requestPreview = async () => {
            syncPattern();
            const values = recurringForm.elements;
            const weekly = values.recurrence_type.value === 'weekly';
            if (!values.employee_id.value || !recurringForm.querySelector('input[name="shift_id"]:checked')) {
                resetPreview('Choose an employee and a shift to preview the series.');
                setReason('Choose an employee and a shift.');

                return;
            }
            const endsAfter = values.end_mode?.value === 'after';
            if (!values.start_date.value || (endsAfter ? !values.occurrences.value : !values.end_date.value)) {
                resetPreview(endsAfter ? 'Choose a start date and how many shifts to create.' : 'Choose the dates the series runs between.');
                setReason(endsAfter ? 'Say how many shifts.' : 'Choose a start and an end date.');

                return;
            }
            if (weekly && selectedIsoDays().length === 0) {
                resetPreview('Pick at least one day of the week.');
                setReason('Pick at least one day.');

                return;
            }

            const sequence = ++previewSequence;
            preview.setAttribute('aria-busy', 'true');
            try {
                const body = new FormData(recurringForm);
                const response = await fetch(recurringForm.dataset.previewUrl, {
                    method: 'POST',
                    headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken() },
                    body,
                });
                const payload = await response.json().catch(() => ({}));
                if (sequence !== previewSequence) return;
                if (!response.ok) {
                    const message = firstError(payload, 'The preview could not be loaded. Check the fields and try again.');
                    resetPreview(message);
                    setReason(message);

                    return;
                }
                renderPreview(payload);
            } catch {
                if (sequence === previewSequence) {
                    resetPreview('The preview could not be loaded. Check your connection and try again.');
                    setReason('The preview is unavailable.');
                }
            } finally {
                if (sequence === previewSequence) preview.removeAttribute('aria-busy');
            }
        };

        const schedulePreview = () => {
            window.clearTimeout(previewTimer);
            previewTimer = window.setTimeout(requestPreview, 250);
        };

        recurringForm.addEventListener('change', (event) => {
            if (event.target === employeeSelect) syncEmployeeMeta();
            if (endModes.includes(event.target)) syncEndMode();
            if (event.target === skipToggle) {
                syncSubmit();

                return;
            }
            schedulePreview();
        });
        recurringForm.addEventListener('input', (event) => {
            if (event.target.matches('input[type="date"]')) schedulePreview();
        });
        presets?.addEventListener('click', (event) => {
            const button = event.target.closest('[data-weekday-preset]');
            if (!button) return;
            const pick = { weekdays: [1, 2, 3, 4, 5], weekends: [6, 7], clear: [] }[button.dataset.weekdayPreset] ?? [];
            recurringForm.querySelectorAll('input[name="weekdays[]"]').forEach((input) => {
                input.checked = pick.includes(Number(input.value));
            });
            schedulePreview();
        });
        recurringForm.addEventListener('submit', (event) => {
            if (submit.disabled) event.preventDefault();
        });

        recurringModal?.addEventListener('shown.bs.modal', () => {
            syncEmployeeMeta();
            syncEndMode();
            initialState = snapshot(recurringForm);
            requestPreview();
        });
        recurringModal?.addEventListener('hidden.bs.modal', () => {
            recurringForm.reset();
            skipField.value = '0';
            syncPattern();
            syncEmployeeMeta();
            syncEndMode();
            resetPreview('Choose an employee and a shift to preview the series.');
            setReason('Choose an employee and a shift.');
        });
        guardDiscard(recurringModal, recurringForm, {
            text: 'Discard this series? The pattern and dates you set here will be lost. No shifts have been created yet.',
            isDirty: () => snapshot(recurringForm) !== initialState,
        });

        syncPattern();
        syncEmployeeMeta();
        syncEndMode();
        resetPreview('Choose an employee and a shift to preview the series.');
        setReason('Choose an employee and a shift.');
    }

    // -----------------------------------------------------------------
    // Assign a shift: coverage, weekly repeat, discard guard
    // -----------------------------------------------------------------
    const assignmentModal = document.querySelector('#scheduleAssignmentModal');
    const assignmentForm = document.querySelector('#scheduleAssignmentForm');

    if (assignmentForm) {
        const coverage = assignmentForm.querySelector('[data-coverage-status]');
        const departmentFilter = assignmentForm.querySelector('[data-assignment-department-filter]');
        const employeeSelect = assignmentForm.elements.employee_id;
        const repeatBlock = assignmentForm.querySelector('[data-assignment-repeat]');
        const repeatToggle = assignmentForm.querySelector('[data-repeat-toggle]');
        const repeatFields = assignmentForm.querySelector('[data-repeat-fields]');
        const repeatUntil = assignmentForm.querySelector('[data-repeat-until]');
        const repeatHint = assignmentForm.querySelector('[data-repeat-hint]');
        const repeatSummary = assignmentForm.querySelector('[data-repeat-summary]');
        const submitButton = assignmentForm.querySelector('button[type="submit"]');
        let coverageSequence = 0;
        let repeatSequence = 0;
        let initialState = snapshot(assignmentForm);

        const isEditMode = () => assignmentForm.querySelector('[data-method-field]')?.value === 'PUT';

        // The employee's own department wins over the filter once someone is
        // picked: cover is counted in the unit the person actually works in.
        const departmentId = () => employeeSelect.selectedOptions[0]?.dataset.departmentId || departmentFilter?.value || '';

        const refreshCoverage = async () => {
            if (!coverage) return;
            const shiftId = assignmentForm.elements.shift_id.value;
            const workDate = assignmentForm.elements.work_date.value;
            const department = departmentId();
            if (!shiftId || !workDate || !department) {
                coverage.hidden = true;

                return;
            }

            const sequence = ++coverageSequence;
            const params = new URLSearchParams({ department_id: department, shift_id: shiftId, work_date: workDate });
            const editing = assignmentForm.dataset.editingId;
            if (isEditMode() && editing) params.set('exclude_assignment_id', editing);
            try {
                const response = await fetch(`${assignmentForm.dataset.coverageUrl}?${params}`, { headers: { Accept: 'application/json' } });
                if (sequence !== coverageSequence) return;
                if (!response.ok) throw new Error('unavailable');
                const data = await response.json();
                const full = data.required > 0 && data.staffed >= data.required;
                const names = data.names?.length ? ` (${data.names.slice(0, 3).join(', ')}${data.names.length > 3 ? ` and ${data.names.length - 3} more` : ''})` : '';
                coverage.classList.toggle('is-full', full);
                coverage.classList.toggle('is-open', !full);
                coverage.querySelector('span').textContent = `Coverage in ${data.department}: ${data.staffed} of ${data.required} already staffed${names}. ${full ? 'Adding someone puts this shift over cover.' : 'This shift still needs someone.'}`;
                coverage.hidden = false;
            } catch {
                if (sequence === coverageSequence) coverage.hidden = true;
            }
        };

        const weekdayOf = (value) => (value ? LONG_DAYS[parseDate(value).getDay()] : '');
        const isoWeekday = (value) => {
            const day = parseDate(value).getDay();

            return day === 0 ? 7 : day;
        };

        const removeRepeatInputs = () => assignmentForm.querySelectorAll('[data-repeat-input]').forEach((input) => input.remove());

        // The repeat block's own reason joins the ones the availability check
        // raises, so the footer shows a single answer for why Save is held.
        const setRepeatError = (message) => {
            assignmentForm.dataset.repeatError = message;
            assignmentForm.dispatchEvent(new CustomEvent('assignment:gate', { bubbles: true }));
        };

        const refreshRepeat = async () => {
            if (!repeatBlock) return;
            repeatBlock.hidden = isEditMode();
            const workDate = assignmentForm.elements.work_date.value;
            const on = Boolean(repeatToggle?.checked) && !isEditMode();
            repeatFields.hidden = !on;
            repeatHint.textContent = workDate ? `Also assign this shift every ${weekdayOf(workDate)}.` : 'Same shift, same day, every week.';
            if (submitButton && !isEditMode()) submitButton.textContent = on ? 'Save weekly shifts' : 'Save assignment';
            if (!on) {
                repeatSummary.textContent = '';
                setRepeatError('');

                return;
            }
            if (!workDate || !repeatUntil.value || repeatUntil.value <= workDate) {
                repeatSummary.textContent = 'Choose an end date after the work date.';
                setRepeatError('Choose a repeat end date after the work date.');

                return;
            }
            setRepeatError('');
            if (!employeeSelect.value || !assignmentForm.elements.shift_id.value) {
                repeatSummary.textContent = `Every ${weekdayOf(workDate)} until ${shortDate(repeatUntil.value)}. Choose an employee and a shift to see which dates can be created.`;

                return;
            }

            const sequence = ++repeatSequence;
            const body = new FormData();
            body.append('employee_id', employeeSelect.value);
            body.append('shift_id', assignmentForm.elements.shift_id.value);
            body.append('start_date', workDate);
            body.append('end_date', repeatUntil.value);
            body.append('recurrence_type', 'weekly');
            body.append('weekdays[]', String(isoWeekday(workDate)));
            body.append('interval_weeks', '1');
            try {
                const response = await fetch(repeatBlock.dataset.previewUrl, {
                    method: 'POST',
                    headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken() },
                    body,
                });
                const payload = await response.json().catch(() => ({}));
                if (sequence !== repeatSequence) return;
                if (!response.ok) {
                    repeatSummary.textContent = firstError(payload, 'The repeat dates could not be checked.');

                    return;
                }
                const skipped = payload.dates.filter((row) => row.status === 'skipped');
                repeatSummary.textContent = `${plural(payload.summary.create, 'shift', 'shifts')} · every ${weekdayOf(workDate)} until ${shortDate(repeatUntil.value)}.`
                    + (skipped.length ? ` Skipped: ${skipped.slice(0, 4).map((row) => shortDate(row.date)).join(', ')}${skipped.length > 4 ? ` and ${skipped.length - 4} more` : ''} (${skipped[0].kind === 'leave' ? 'approved leave' : 'conflict'}${skipped.length > 1 ? ' and others' : ''}).` : '');
            } catch {
                if (sequence === repeatSequence) repeatSummary.textContent = 'The repeat dates could not be checked.';
            }
        };

        assignmentForm.addEventListener('change', (event) => {
            if (event.target.matches('[data-assignment-department-filter], [data-assignment-position-filter], select[name="employee_id"], select[name="shift_id"], input[name="work_date"]')) {
                refreshCoverage();
                refreshRepeat();
            }
            if (event.target === repeatToggle || event.target === repeatUntil) refreshRepeat();
        });

        // A weekly repeat is a recurring series, so it goes to that endpoint;
        // the same employee, shift and notes fields carry across unchanged.
        assignmentForm.addEventListener('submit', (event) => {
            removeRepeatInputs();
            if (!repeatToggle?.checked || isEditMode() || !repeatBlock) {
                assignmentForm.action = isEditMode() ? assignmentForm.action : assignmentForm.dataset.storeUrl;

                return;
            }
            const workDate = assignmentForm.elements.work_date.value;
            if (!repeatUntil.value || repeatUntil.value <= workDate) {
                event.preventDefault();
                repeatSummary.textContent = 'Choose an end date after the work date.';
                repeatUntil.focus();

                return;
            }
            const add = (name, value) => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = name;
                input.value = value;
                input.dataset.repeatInput = '';
                assignmentForm.append(input);
            };
            add('start_date', workDate);
            add('end_date', repeatUntil.value);
            add('recurrence_type', 'weekly');
            add('weekdays[]', String(isoWeekday(workDate)));
            add('interval_weeks', '1');
            add('skip_conflicts', '1');
            add('origin', 'assignment');
            assignmentForm.action = repeatBlock.dataset.recurringUrl;
        });

        assignmentModal?.addEventListener('shown.bs.modal', () => {
            if (repeatToggle) repeatToggle.checked = false;
            if (repeatUntil && assignmentForm.elements.work_date.value) {
                const until = parseDate(assignmentForm.elements.work_date.value);
                until.setDate(until.getDate() + 28);
                repeatUntil.value = isoDate(until);
            }
            removeRepeatInputs();
            setRepeatError('');
            refreshCoverage();
            refreshRepeat();
            initialState = snapshot(assignmentForm);
        });
        const releaseAssignment = guardDiscard(assignmentModal, assignmentForm, {
            text: 'Discard this assignment? The shift details you entered will be lost. Nothing has been saved yet.',
            isDirty: () => snapshot(assignmentForm) !== initialState,
        });

        // "More repeat options" hands the employee, shift, weekday and dates
        // over to the full recurring form instead of making HR type them again.
        assignmentForm.querySelector('[data-repeat-more]')?.addEventListener('click', () => {
            const target = document.querySelector('#recurringScheduleForm');
            const targetModal = document.querySelector('#recurringScheduleModal');
            if (!target || !targetModal || !window.bootstrap) return;
            const workDate = assignmentForm.elements.work_date.value;
            targetModal.addEventListener('show.bs.modal', () => {
                target.elements.employee_id.value = employeeSelect.value;
                const shift = target.querySelector(`input[name="shift_id"][value="${CSS.escape(assignmentForm.elements.shift_id.value)}"]`);
                if (shift) shift.checked = true;
                target.elements.recurrence_type.value = 'weekly';
                target.elements.interval_weeks.value = '1';
                if (workDate) {
                    target.elements.start_date.value = workDate;
                    target.querySelectorAll('input[name="weekdays[]"]').forEach((input) => {
                        input.checked = Number(input.value) === isoWeekday(workDate);
                    });
                }
                if (repeatUntil.value) target.elements.end_date.value = repeatUntil.value;
                target.elements.notes.value = assignmentForm.elements.notes.value;
            }, { once: true });
            assignmentModal.addEventListener('hidden.bs.modal', () => {
                window.bootstrap.Modal.getOrCreateInstance(targetModal).show();
            }, { once: true });
            releaseAssignment();
            window.bootstrap.Modal.getOrCreateInstance(assignmentModal).hide();
        });
    }
});
