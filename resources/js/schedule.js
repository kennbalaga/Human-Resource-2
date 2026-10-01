import { scrollBehavior } from './motion';
import { confirmAction } from './confirm-actions';

const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

const replaceRouteId = (template, id) => template.replace('__ID__', String(id));

const SHORT_WEEKDAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

const element = (tag, className, text) => {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined) node.textContent = text;

    return node;
};

const formatScheduleDate = (date) => {
    if (!date) return '—';

    return new Intl.DateTimeFormat('en-PH', {
        weekday: 'short',
        month: 'long',
        day: 'numeric',
        year: 'numeric',
    }).format(new Date(`${date}T00:00:00`));
};

// Narrows a plain employee <select> to a chosen department and/or position so
// picking one person out of the whole roster doesn't mean scanning every
// employee at once, mirroring the filtering the bulk roster builder already does.
const wireEmployeeDirectoryFilter = (form, { departmentSelector, positionSelector, employeeSelector }) => {
    if (!form) return;
    const departmentFilter = form.querySelector(departmentSelector);
    const positionFilter = form.querySelector(positionSelector);
    const employeeSelect = form.querySelector(employeeSelector);
    if (!departmentFilter || !positionFilter || !employeeSelect) return;

    const positionOptions = [...positionFilter.querySelectorAll('option[data-department-id]')];
    const employeeOptions = [...employeeSelect.querySelectorAll('option')].filter((option) => option.value);

    const syncPositions = () => {
        const departmentId = departmentFilter.value;
        positionOptions.forEach((option) => {
            option.hidden = Boolean(departmentId) && option.dataset.departmentId !== departmentId;
        });
        if (positionFilter.selectedOptions[0]?.hidden) positionFilter.value = '';
    };

    const syncEmployees = () => {
        const departmentId = departmentFilter.value;
        const positionId = positionFilter.value;
        employeeOptions.forEach((option) => {
            option.hidden = (Boolean(departmentId) && option.dataset.departmentId !== departmentId)
                || (Boolean(positionId) && option.dataset.positionId !== positionId);
        });
        if (employeeSelect.selectedOptions[0]?.hidden) employeeSelect.value = '';
        // A group whose whole staff list was filtered out would otherwise leave
        // its position heading behind with nothing under it.
        employeeSelect.querySelectorAll('optgroup').forEach((group) => {
            group.hidden = [...group.querySelectorAll('option')].every((option) => option.hidden);
        });
        employeeSelect.dispatchEvent(new CustomEvent('employees:filtered', { bubbles: true }));
    };

    departmentFilter.addEventListener('change', () => {
        syncPositions();
        syncEmployees();
    });
    positionFilter.addEventListener('change', syncEmployees);
};

document.addEventListener('DOMContentLoaded', () => {
    const assignmentModalElement = document.querySelector('#scheduleAssignmentModal');
    const assignmentForm = document.querySelector('#scheduleAssignmentForm');
    const dayRosterModalElement = document.querySelector('#dayRosterModal');
    let activeAssignment = null;
    let conflictRequest = 0;

    const setAssignmentMode = (assignment = null, preferredDate = null) => {
        if (!assignmentForm) return;

        assignmentForm.reset();
        assignmentForm.action = assignment
            ? replaceRouteId(assignmentForm.dataset.updateUrlTemplate, assignment.id)
            : assignmentForm.dataset.storeUrl;
        assignmentForm.querySelector('[data-method-field]').value = assignment ? 'PUT' : 'POST';
        assignmentForm.dataset.editingId = assignment?.id ?? '';
        assignmentForm.querySelector('.modal-title').textContent = assignment ? 'Edit schedule assignment' : 'Assign a shift';
        assignmentForm.querySelector('button[type="submit"]').textContent = assignment ? 'Update assignment' : 'Save assignment';

        assignmentForm.elements.employee_id.value = assignment?.employee_id ?? '';
        assignmentForm.elements.shift_id.value = assignment?.shift_id ?? '';
        assignmentForm.elements.work_date.value = assignment?.date ?? preferredDate ?? assignmentForm.elements.work_date.defaultValue;
        assignmentForm.elements.notes.value = assignment?.notes ?? '';
        setFromAi(false);
        syncEmployeeHelp();
        updateConflictStatus('idle', 'Availability', 'Choose an employee, shift, and date to check availability.');

        if (assignment) checkConflicts(assignment.id);
    };

    const chosenEmployee = () => assignmentForm?.elements.employee_id.selectedOptions[0] ?? null;
    const employeeName = () => chosenEmployee()?.dataset.name || 'This employee';

    // The badge stays on only while the employee the assistant applied is the
    // one in the field — picking someone else by hand takes it off again.
    const setFromAi = (on) => {
        const badge = assignmentForm?.querySelector('[data-assignment-from-ai]');
        if (badge) badge.hidden = !on;
    };

    const syncEmployeeHelp = () => {
        const help = assignmentForm?.querySelector('[data-assignment-employee-help]');
        if (!help) return;
        const option = chosenEmployee();
        help.textContent = option?.value
            ? [option.dataset.position, option.dataset.number].filter(Boolean).join(' · ')
            : help.dataset.idle;
    };

    // What is still missing before this can be saved, in the order the form is
    // filled in, shown beside the button it holds back.
    const syncSaveGate = () => {
        const submit = assignmentForm?.querySelector('[data-assignment-submit]');
        const reason = assignmentForm?.querySelector('[data-assignment-reason]');
        if (!submit || !reason) return;
        const status = assignmentForm.querySelector('[data-conflict-status]');
        const message = !assignmentForm.elements.shift_id.value ? 'Choose a shift.'
            : !assignmentForm.elements.work_date.value ? 'Choose a work date.'
                : !assignmentForm.elements.employee_id.value ? 'Choose an employee.'
                    : status?.classList.contains('conflict') ? 'This employee is not available for this shift.'
                        : assignmentForm.dataset.repeatError || '';
        submit.disabled = message !== '';
        reason.hidden = message === '';
        reason.querySelector('span').textContent = message;
    };

    const updateConflictStatus = (state, title, text) => {
        const status = assignmentForm?.querySelector('[data-conflict-status]');
        if (!status) return;

        status.classList.remove('checking', 'available', 'conflict');
        if (state !== 'idle') status.classList.add(state);
        status.querySelector('[data-conflict-title]').textContent = title;
        status.querySelector('[data-conflict-text]').textContent = text;
        syncSaveGate();
    };

    const checkConflicts = async (excludeId = null) => {
        if (!assignmentForm) return;

        const employeeId = assignmentForm.elements.employee_id.value;
        const shiftId = assignmentForm.elements.shift_id.value;
        const workDate = assignmentForm.elements.work_date.value;
        if (!employeeId || !shiftId || !workDate) {
            updateConflictStatus('idle', 'Availability', 'Choose an employee, shift, and date to check availability.');
            return;
        }

        const requestId = ++conflictRequest;
        updateConflictStatus('checking', 'Checking availability…', `Reading ${employeeName()}’s shifts, approved leave, and rest hours.`);

        try {
            const response = await fetch(assignmentForm.dataset.conflictUrl, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify({
                    employee_id: Number(employeeId),
                    shift_id: Number(shiftId),
                    work_date: workDate,
                    exclude_assignment_id: excludeId,
                }),
            });

            if (requestId !== conflictRequest) return;
            if (!response.ok) throw new Error('Unable to check conflicts.');

            const result = await response.json();
            const conflicts = excludeId
                ? result.conflicts.filter((conflict) => Number(conflict.id) !== Number(excludeId))
                : result.conflicts;
            const restConflicts = excludeId
                ? result.rest_conflicts.filter((conflict) => Number(conflict.id) !== Number(excludeId))
                : result.rest_conflicts;

            const name = employeeName();
            const blocked = `${name} can’t take this shift`;

            if (result.day_off) {
                updateConflictStatus('conflict', blocked, `A scheduled day off falls on ${formatScheduleDate(result.day_off.date)}.`);
            } else if (result.leave) {
                updateConflictStatus('conflict', blocked, `On approved leave from ${formatScheduleDate(result.leave.start_date)} to ${formatScheduleDate(result.leave.end_date)}.`);
            } else if (conflicts.length) {
                const conflict = conflicts[0];
                updateConflictStatus('conflict', blocked, `Already on the ${conflict.shift} on ${formatScheduleDate(conflict.date)} (${conflict.time}).`);
            } else if (restConflicts.length) {
                const conflict = restConflicts[0];
                updateConflictStatus('conflict', blocked, `Not enough rest before or after the ${conflict.shift} on ${formatScheduleDate(conflict.date)} (${conflict.time}).`);
            } else {
                // The weekly limit is advisory on a hand-made assignment: it is
                // read back with the hours rather than blocking the save, so
                // approved overtime can still be scheduled deliberately.
                const week = result.week;
                const hours = week
                    ? `${week.scheduled_hours} of ${week.limit} paid hours this week · ${week.after_hours} after this shift.${week.after_hours > week.limit ? ' Past the weekly limit — the extra time is overtime.' : ''}`
                    : 'No overlap found.';
                updateConflictStatus('available', `${name} is available`, hours);
            }
        } catch (error) {
            if (requestId === conflictRequest) updateConflictStatus('conflict', 'Availability could not be checked', error.message);
        }
    };

    assignmentForm?.querySelectorAll('select[name="employee_id"], select[name="shift_id"], input[name="work_date"]').forEach((field) => {
        field.addEventListener('change', () => {
            if (field.name === 'employee_id' && !field.dataset.aiApplied) setFromAi(false);
            delete field.dataset.aiApplied;
            syncEmployeeHelp();
            checkConflicts(activeAssignment?.id ?? null);
        });
    });
    // The repeat block and the assistant re-ask for the gate when their own
    // state changes, so one function decides whether the save is allowed.
    assignmentForm?.addEventListener('assignment:gate', syncSaveGate);
    assignmentForm?.addEventListener('assignment:from-ai', () => {
        setFromAi(true);
        syncEmployeeHelp();
    });

    document.querySelectorAll('[data-quick-schedule-date]').forEach((button) => {
        button.addEventListener('click', () => {
            activeAssignment = null;
            setAssignmentMode(null, button.dataset.quickScheduleDate);
            window.bootstrap.Modal.getOrCreateInstance(assignmentModalElement).show();
        });
    });

    document.querySelectorAll('[data-bs-target="#scheduleAssignmentModal"]:not([data-quick-schedule-date])').forEach((button) => {
        button.addEventListener('click', () => {
            activeAssignment = null;
            setAssignmentMode();
        });
    });

    // Clicking any shift opens the full published roster for that date rather
    // than a popup about the one shift clicked — the whole day's coverage is
    // what a manager actually needs to see, and each row still carries its own
    // edit/remove so nothing from the old single-assignment popup is lost.
    const dayRosterBoard = dayRosterModalElement?.querySelector('[data-day-roster-board]');
    const dayRosterEmpty = dayRosterModalElement?.querySelector('[data-day-roster-empty]');
    const dayRosterLoading = dayRosterModalElement?.querySelector('[data-day-roster-loading]');
    const dayRosterDateLabel = dayRosterModalElement?.querySelector('[data-day-roster-date]');
    const dayRosterSearchInput = dayRosterModalElement?.querySelector('[data-day-roster-search]');
    const dayRosterShiftFilterMenu = dayRosterModalElement?.querySelector('[data-day-roster-shift-filter]');
    const dayRosterExportButton = dayRosterModalElement?.querySelector('[data-day-roster-export]');
    let dayRosterPayload = null;
    let dayRosterShiftFilter = '';

    const SHIFT_TYPE_LABELS = { day: 'Day Shift', evening: 'Evening Shift', night: 'Night Shift' };

    const buildDayRosterRow = (row) => {
        const tr = document.createElement('tr');
        tr.dataset.search = `${row.employee} ${row.position} ${row.employee_number}`.toLowerCase();
        tr.dataset.shiftType = row.shift_type;

        const position = document.createElement('td');
        position.textContent = row.position;
        const staff = document.createElement('td');
        staff.textContent = row.employee;
        const employeeId = document.createElement('td');
        employeeId.className = 'day-roster-id';
        employeeId.textContent = row.employee_number;

        const assignment = document.createElement('td');
        const badge = document.createElement('span');
        badge.className = `day-roster-badge day-roster-badge-${row.shift_type}`;
        badge.textContent = SHIFT_TYPE_LABELS[row.shift_type] ?? row.shift;
        assignment.append(badge);

        const time = document.createElement('td');
        time.textContent = row.time;

        const actions = document.createElement('td');
        actions.className = 'row-action-group';
        if (row.editable) {
            const editButton = document.createElement('button');
            editButton.type = 'button';
            editButton.className = 'icon-button subtle';
            editButton.setAttribute('aria-label', `Edit ${row.employee}’s shift`);
            editButton.append(rosterIcon('edit'));
            editButton.addEventListener('click', () => {
                activeAssignment = row;
                window.bootstrap.Modal.getOrCreateInstance(dayRosterModalElement).hide();
                setAssignmentMode(row);
                dayRosterModalElement.addEventListener('hidden.bs.modal', () => {
                    window.bootstrap.Modal.getOrCreateInstance(assignmentModalElement).show();
                }, { once: true });
            });

            const deleteForm = document.createElement('form');
            deleteForm.method = 'POST';
            deleteForm.action = replaceRouteId(dayRosterModalElement.dataset.updateUrlTemplate, row.id);
            deleteForm.dataset.confirm = 'Remove this schedule assignment?';
            const methodField = document.createElement('input');
            methodField.type = 'hidden';
            methodField.name = '_method';
            methodField.value = 'DELETE';
            const tokenField = document.createElement('input');
            tokenField.type = 'hidden';
            tokenField.name = '_token';
            tokenField.value = csrfToken();
            const deleteButton = document.createElement('button');
            deleteButton.type = 'submit';
            deleteButton.className = 'icon-button subtle text-danger';
            deleteButton.setAttribute('aria-label', `Remove ${row.employee}’s shift`);
            deleteButton.append(rosterIcon('trash'));
            deleteForm.append(methodField, tokenField, deleteButton);

            actions.append(editButton, deleteForm);
        }

        tr.append(position, staff, employeeId, assignment, time, actions);

        return tr;
    };

    const buildDayRosterDepartment = (department) => {
        const section = document.createElement('section');
        section.className = 'day-roster-department';

        const header = document.createElement('button');
        header.type = 'button';
        header.className = 'day-roster-department-header';
        header.setAttribute('aria-expanded', 'true');
        const caret = rosterIcon('chevron-down');
        caret.classList.add('day-roster-department-caret');
        const name = document.createElement('strong');
        name.textContent = department.name;
        const count = document.createElement('span');
        count.textContent = `${department.rows.length} Scheduled Staff`;
        header.append(caret, name, count);

        const tableWrap = document.createElement('div');
        tableWrap.className = 'table-responsive';
        const table = document.createElement('table');
        table.className = 'dashboard-table day-roster-table';
        table.innerHTML = '<thead><tr><th>Position / Role</th><th>Staff Member</th><th>Employee ID</th><th>Assignment</th><th>Shift Schedule</th><th></th></tr></thead>';
        const tbody = document.createElement('tbody');
        department.rows.forEach((row) => tbody.append(buildDayRosterRow(row)));
        table.append(tbody);
        tableWrap.append(table);

        header.addEventListener('click', () => {
            const expanded = header.getAttribute('aria-expanded') === 'true';
            header.setAttribute('aria-expanded', String(!expanded));
            tableWrap.hidden = expanded;
        });

        section.append(header, tableWrap);

        return section;
    };

    const applyDayRosterFilters = () => {
        if (!dayRosterPayload) return;
        const search = (dayRosterSearchInput?.value ?? '').trim().toLowerCase();
        let anyVisible = false;

        dayRosterBoard.querySelectorAll('.day-roster-department').forEach((section) => {
            let visibleInSection = 0;
            section.querySelectorAll('tbody tr').forEach((row) => {
                const matchesSearch = !search || row.dataset.search.includes(search);
                const matchesShift = !dayRosterShiftFilter || row.dataset.shiftType === dayRosterShiftFilter;
                const visible = matchesSearch && matchesShift;
                row.hidden = !visible;
                if (visible) visibleInSection += 1;
            });
            section.hidden = visibleInSection === 0;
            if (visibleInSection > 0) anyVisible = true;
        });

        if (dayRosterEmpty) dayRosterEmpty.hidden = anyVisible;
    };

    const renderDayRoster = (payload) => {
        dayRosterPayload = payload;
        if (dayRosterDateLabel) dayRosterDateLabel.textContent = payload.formatted_date;
        dayRosterBoard.replaceChildren();
        payload.departments.forEach((department) => dayRosterBoard.append(buildDayRosterDepartment(department)));

        const totalField = dayRosterModalElement.querySelector('[data-day-roster-total]');
        const departmentsField = dayRosterModalElement.querySelector('[data-day-roster-departments]');
        if (totalField) totalField.textContent = payload.summary.total_staff;
        if (departmentsField) departmentsField.textContent = payload.summary.departments_active;
        ['day', 'evening', 'night'].forEach((type) => {
            const field = dayRosterModalElement.querySelector(`[data-day-roster-coverage-${type}]`);
            if (field) field.textContent = payload.summary.coverage[type] ?? 0;
        });

        applyDayRosterFilters();
    };

    const showDayRoster = async (date) => {
        if (!dayRosterModalElement) return;

        window.bootstrap.Modal.getOrCreateInstance(dayRosterModalElement).show();
        dayRosterBoard.replaceChildren();
        if (dayRosterEmpty) dayRosterEmpty.hidden = true;
        if (dayRosterLoading) dayRosterLoading.hidden = false;
        if (dayRosterDateLabel) dayRosterDateLabel.textContent = formatScheduleDate(date);

        try {
            const response = await fetch(`${dayRosterModalElement.dataset.dayRosterUrl}?date=${encodeURIComponent(date)}`, {
                headers: { Accept: 'application/json' },
            });
            if (!response.ok) throw new Error('Unable to load the published roster for this date.');
            renderDayRoster(await response.json());
        } catch (error) {
            if (dayRosterEmpty) {
                dayRosterEmpty.hidden = false;
                dayRosterEmpty.querySelector('p').textContent = error.message;
            }
        } finally {
            if (dayRosterLoading) dayRosterLoading.hidden = true;
        }
    };

    document.querySelectorAll('[data-schedule-event]').forEach((eventButton) => {
        eventButton.addEventListener('click', () => showDayRoster(JSON.parse(eventButton.dataset.scheduleEvent).date));
    });

    dayRosterSearchInput?.addEventListener('input', applyDayRosterFilters);
    dayRosterShiftFilterMenu?.querySelectorAll('[data-shift-filter]')?.forEach((button) => {
        button.addEventListener('click', () => {
            dayRosterShiftFilter = button.dataset.shiftFilter;
            dayRosterShiftFilterMenu.querySelectorAll('[data-shift-filter]').forEach((other) => other.classList.toggle('active', other === button));
            applyDayRosterFilters();
        });
    });

    dayRosterExportButton?.addEventListener('click', () => {
        if (!dayRosterPayload) return;
        const lines = [['Department', 'Position/Role', 'Staff Member', 'Employee ID', 'Assignment', 'Shift Schedule'].join(',')];
        dayRosterPayload.departments.forEach((department) => {
            department.rows.forEach((row) => {
                lines.push([department.name, row.position, row.employee, row.employee_number, SHIFT_TYPE_LABELS[row.shift_type] ?? row.shift, row.time]
                    .map((value) => `"${String(value).replace(/"/g, '""')}"`).join(','));
            });
        });
        const blob = new Blob([lines.join('\n')], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = `published-schedule-${dayRosterPayload.date}.csv`;
        link.click();
        URL.revokeObjectURL(link.href);
    });

    // The recurring form's pattern summary and live preview live in
    // schedule-forms.js; only the employee directory filter is wired here.
    const recurringForm = document.querySelector('#recurringScheduleForm');

    wireEmployeeDirectoryFilter(assignmentForm, {
        departmentSelector: '[data-assignment-department-filter]',
        positionSelector: '[data-assignment-position-filter]',
        employeeSelector: '[data-assignment-employee-select]',
    });
    wireEmployeeDirectoryFilter(recurringForm, {
        departmentSelector: '[data-recurring-department-filter]',
        positionSelector: '[data-recurring-position-filter]',
        employeeSelector: '[data-recurring-employee-select]',
    });

    const bulkModalElement = document.querySelector('#bulkScheduleModal');
    const bulkForm = document.querySelector('#bulkScheduleForm');
    const bulkReview = bulkForm?.querySelector('[data-bulk-review]');
    const bulkSaveButton = bulkForm?.querySelector('[data-bulk-save]');
    const bulkSelectedCount = bulkForm?.querySelector('[data-bulk-selected-count]');
    const bulkApproval = bulkForm?.querySelector('[data-bulk-approval]');
    const bulkApprovalWrap = bulkForm?.querySelector('[data-bulk-approval-wrap]');
    const bulkEmployeeOptions = [...(bulkForm?.querySelectorAll('[data-bulk-employee-list] .bulk-employee-option') ?? [])];
    const bulkMaster = bulkForm?.querySelector('[data-bulk-master]');
    const bulkShowButtons = [...(bulkForm?.querySelectorAll('[data-bulk-show]') ?? [])];
    const bulkClearSelection = bulkForm?.querySelector('[data-bulk-clear-selection]');
    // Which slice of the eligible staff the list shows: everyone, only the
    // ticked names, or only the unticked ones.
    let bulkShow = 'all';
    const isAiSchedule = () => ['rotation', 'custom'].includes(bulkForm?.elements.schedule_method?.value);

    const updateBulkReview = (title, message, state = 'idle', skipped = []) => {
        if (!bulkReview) return;
        bulkReview.classList.remove('has-skips', 'no-ready');
        if (state !== 'idle') bulkReview.classList.add(state);

        const copy = bulkReview.querySelector('div');
        copy.replaceChildren();
        const heading = document.createElement('strong');
        heading.textContent = title;
        const detail = document.createElement('span');
        detail.textContent = message;
        copy.append(heading, detail);

        if (skipped.length) {
            const list = document.createElement('ul');
            skipped.forEach((item) => {
                const entry = document.createElement('li');
                entry.textContent = `${item.employee} — ${formatScheduleDate(item.date)}: ${item.reason}.`;
                list.append(entry);
            });
            copy.append(list);
        }
    };

    const selectedBulkEmployees = () => bulkEmployeeOptions
        .filter((option) => option.querySelector('input').checked)
        .map((option) => Number(option.querySelector('input').value));

    const selectedBulkPositionIds = () => (bulkForm
        ? [...bulkForm.querySelectorAll('input[name="position_ids[]"]:checked')].map((input) => input.value)
        : []);

    const updateBulkSelectedCount = () => {
        if (bulkSelectedCount) bulkSelectedCount.textContent = `${selectedBulkEmployees().length} selected`;
        syncBulkSelectionTools();
    };

    const invalidateBulkReview = () => {
        if (!bulkForm) return;
        bulkSaveButton.disabled = true;
        if (bulkApproval) bulkApproval.checked = false;
        if (bulkApprovalWrap) bulkApprovalWrap.hidden = true;
        updateBulkReview(
            'Build the roster below, then publish',
            isAiSchedule()
                ? 'Select employees and at least two shifts, then let the assistant rotate them.'
                : 'Select employees, a shift, and dates, then fill the roster.',
        );
    };

    // The pool's instruction changes with what is ticked, because the rule
    // itself does: two shifts are needed to cover a day between them, and a
    // standalone office shift needs no partner.
    const syncShiftPoolHelp = () => {
        const help = bulkForm?.querySelector('[data-shift-pool-help]');
        if (!help) return;

        if (rostersStandaloneShiftsOnly()) {
            help.textContent = 'This shift covers a full working day on its own, so no second shift is needed — and nothing else can be added beside it.';

            return;
        }

        const chosen = selectedShiftOptions().length;
        if (chosen === 1) {
            help.textContent = 'A rotation needs at least two shifts. Add another, or switch the pattern to Fixed.';

            return;
        }

        help.textContent = bulkForm.elements.schedule_method?.value === 'custom'
            ? 'Select at least two shifts. The assistant creates a stable custom mix across employees while balancing coverage.'
            : 'Select at least two shifts. The assistant balances coverage and rotates employees weekly.';
    };

    // What the chosen pattern will do to people, said where it is chosen.
    const syncSchedulePatternHint = () => {
        const hint = bulkForm?.querySelector('[data-schedule-method-hint]');
        if (!hint) return;
        hint.textContent = {
            rotation: 'Staff move between the pooled shifts, balanced by the assistant.',
            custom: 'Each person keeps a stable mix of the pooled shifts across the period.',
            fixed: 'Each person keeps one shift for the whole period.',
        }[bulkForm.elements.schedule_method?.value] ?? '';
    };

    /**
     * A standalone shift and a rotating one cannot share a pool. The 8-to-5
     * office day already fills the day it covers, so pairing it with a Night
     * leg would ask the assistant to roster the same person across two
     * incompatible patterns.
     *
     * Nothing is ever unticked automatically — the offending box is simply
     * closed off while the other kind is chosen, so the roster in front of
     * someone never changes out from under them. Untick to open it back up.
     */
    const syncShiftPoolExclusivity = () => {
        const boxes = [...(bulkForm?.querySelectorAll('input[name="shift_ids[]"]') ?? [])];
        if (boxes.length === 0 || !isAiSchedule()) return;

        const checked = boxes.filter((box) => box.checked);
        const standaloneChosen = checked.some((box) => box.dataset.rotating === '0');
        const rotatingChosen = checked.some((box) => box.dataset.rotating === '1');

        boxes.forEach((box) => {
            const blocked = box.checked
                ? false
                : (standaloneChosen || (rotatingChosen && box.dataset.rotating === '0'));

            box.disabled = blocked;
            const label = box.closest('label');
            label?.classList.toggle('is-unavailable', blocked);
            label?.setAttribute(
                'title',
                blocked
                    ? 'A standalone shift cannot be combined with a rotating one. Untick the current selection to choose this instead.'
                    : '',
            );
            // Said on the tile, not only in a tooltip a mouse has to find.
            const reason = label?.querySelector('[data-shift-reason]');
            if (reason) {
                reason.hidden = !blocked;
                reason.textContent = blocked
                    ? (box.dataset.rotating === '0'
                        ? 'Unavailable beside a rotating shift'
                        : 'Unavailable while a standalone shift is chosen')
                    : '';
            }
        });
    };

    const syncScheduleMethod = () => {
        if (!bulkForm || !bulkForm.elements.schedule_method) return;
        const aiSchedule = isAiSchedule();
        const method = bulkForm.elements.schedule_method.value;
        const fixedField = bulkForm.querySelector('[data-fixed-shift]');
        const rotationField = bulkForm.querySelector('[data-rotation-shifts]');
        const weekendField = bulkForm.querySelector('.bulk-weekend-toggle');
        fixedField.hidden = aiSchedule;
        fixedField.querySelector('select').disabled = aiSchedule;
        fixedField.querySelector('select').required = !aiSchedule;
        rotationField.hidden = !aiSchedule;
        rotationField.querySelectorAll('input').forEach((input) => {
            input.disabled = !aiSchedule;
            if (!aiSchedule) input.closest('label')?.classList.remove('is-unavailable');
        });
        weekendField.hidden = aiSchedule;
        // "Put everyone on the selected shift" only makes sense for a fixed
        // shift; in rotation/custom mode shift_id is disabled and posting it
        // would just fail BulkScheduleAssignmentRequest's required check.
        const shiftFillButton = bulkForm.querySelector('[data-roster-fill-shift]');
        if (shiftFillButton) shiftFillButton.hidden = aiSchedule;
        bulkForm.elements.include_weekends.checked = aiSchedule;
        syncShiftPoolExclusivity();
        syncShiftPoolHelp();
        syncSchedulePatternHint();
        bulkSaveButton.textContent = 'Approve & publish';
        invalidateBulkReview();
    };

    const parseScheduleDate = (value) => new Date(`${value}T00:00:00`);
    const dateInputValue = (date) => [date.getFullYear(), String(date.getMonth() + 1).padStart(2, '0'), String(date.getDate()).padStart(2, '0')].join('-');
    const monthInputValue = (date) => [date.getFullYear(), String(date.getMonth() + 1).padStart(2, '0')].join('-');

    const syncBulkPeriod = () => {
        if (!bulkForm) return;
        const period = bulkForm.elements.schedule_period.value;
        const periodStart = bulkForm.elements.period_start;
        const periodMonth = bulkForm.elements.period_month;
        const startField = bulkForm.querySelector('[data-period-start]');
        const monthField = bulkForm.querySelector('[data-period-month]');
        const isMonthly = period === 'monthly';
        startField.hidden = isMonthly;
        monthField.hidden = !isMonthly;

        const anchor = parseScheduleDate(isMonthly ? `${periodMonth.value}-01` : periodStart.value);
        if (Number.isNaN(anchor.getTime())) return;

        let start = new Date(anchor);
        let end = new Date(anchor);
        if (period === 'weekly') end.setDate(end.getDate() + 6);
        if (period === 'two_weeks') end.setDate(end.getDate() + 13);
        if (isMonthly) {
            start = new Date(anchor.getFullYear(), anchor.getMonth(), 1);
            end = new Date(anchor.getFullYear(), anchor.getMonth() + 1, 0);
        }

        bulkForm.elements.start_date.value = dateInputValue(start);
        bulkForm.elements.end_date.value = dateInputValue(end);
        bulkForm.querySelector('[data-bulk-period-range]').textContent = `${formatScheduleDate(dateInputValue(start))} – ${formatScheduleDate(dateInputValue(end))}`;
    };

    const moveBulkPeriod = (direction) => {
        if (!bulkForm) return;
        const period = bulkForm.elements.schedule_period.value;
        const isMonthly = period === 'monthly';
        const field = isMonthly ? bulkForm.elements.period_month : bulkForm.elements.period_start;
        const date = parseScheduleDate(isMonthly ? `${field.value}-01` : field.value);
        if (Number.isNaN(date.getTime())) return;

        if (isMonthly) date.setMonth(date.getMonth() + direction);
        else date.setDate(date.getDate() + direction * (period === 'two_weeks' ? 14 : 7));

        field.value = isMonthly ? monthInputValue(date) : dateInputValue(date);
        syncBulkPeriod();
        refreshStaffPeriodLoad();
        invalidateBulkReview();
    };

    // The select-all box, the All / Selected / Not selected counts, and the
    // "N of M selected" line, all read from the same eligible pool so they
    // can never disagree with the list under them.
    const syncBulkSelectionTools = () => {
        if (!bulkForm) return;
        const eligible = bulkEmployeeOptions.filter((option) => option.dataset.eligible === '1');
        const selected = eligible.filter((option) => option.querySelector('input').checked).length;
        const counts = { all: eligible.length, selected, unselected: eligible.length - selected };
        bulkShowButtons.forEach((button) => {
            const key = button.dataset.bulkShow;
            button.setAttribute('aria-pressed', String(key === bulkShow));
            const count = button.querySelector('[data-bulk-show-count]');
            if (count) count.textContent = String(counts[key] ?? 0);
        });

        const visible = bulkEmployeeOptions.filter((option) => !option.hidden);
        const visibleSelected = visible.filter((option) => option.querySelector('input').checked).length;
        if (bulkMaster) {
            bulkMaster.disabled = visible.length === 0;
            bulkMaster.checked = visible.length > 0 && visibleSelected === visible.length;
            bulkMaster.indeterminate = visibleSelected > 0 && visibleSelected < visible.length;
            const label = bulkForm.querySelector('[data-bulk-master-label]');
            if (label) label.textContent = bulkMaster.checked ? 'Deselect all shown' : 'Select all shown';
        }

        const summary = bulkForm.querySelector('[data-bulk-selection-summary]');
        const totalSelected = selectedBulkEmployees().length;
        if (summary) summary.textContent = eligible.length ? `${totalSelected} of ${eligible.length} selected` : `${totalSelected} selected`;
        if (bulkClearSelection) bulkClearSelection.hidden = totalSelected === 0;
    };

    // What the chosen period already holds for each employee in the chosen
    // department — read once per department/period change and written into the
    // "This period" column, so ticking a name is an informed choice.
    let staffLoadSequence = 0;
    let staffLoadTimer = null;
    const staffPeriodCell = (option) => option.querySelector('[data-bulk-employee-period]');

    const renderStaffPeriodLoad = (load) => {
        const note = bulkForm?.querySelector('[data-bulk-period-note]');
        let busy = 0;
        bulkEmployeeOptions.forEach((option) => {
            const cell = staffPeriodCell(option);
            if (!cell) return;
            const row = load?.[option.dataset.employeeId];
            cell.classList.remove('is-busy', 'is-leave');
            if (!row) {
                cell.textContent = '—';

                return;
            }
            const parts = [];
            if (row.shifts) parts.push(`${row.shifts} shift${row.shifts === 1 ? '' : 's'} already scheduled`);
            if (row.leave_days) parts.push(`${row.leave_days} day${row.leave_days === 1 ? '' : 's'} on leave`);
            if (row.days_off) parts.push(`${row.days_off} rest day${row.days_off === 1 ? '' : 's'}`);
            if (row.shifts || row.leave_days) busy += 1;
            cell.classList.toggle('is-busy', row.shifts > 0);
            cell.classList.toggle('is-leave', row.shifts === 0 && row.leave_days > 0);
            cell.textContent = parts.length ? parts.join(' · ') : 'Nothing yet';
        });
        if (note) {
            note.textContent = load
                ? (busy
                    ? `${busy} of the staff shown already have shifts or approved leave in this period.`
                    : 'Nobody shown has shifts or approved leave in this period yet.')
                : 'Select the employees to include in this schedule.';
        }
    };

    const refreshStaffPeriodLoad = () => {
        const list = bulkForm?.querySelector('[data-bulk-employee-list]');
        if (!list?.dataset.staffLoadUrl) return;
        const departmentId = bulkForm.elements.department_id.value;
        const start = bulkForm.elements.start_date.value;
        const end = bulkForm.elements.end_date.value;
        if (!departmentId || !start || !end) {
            renderStaffPeriodLoad(null);

            return;
        }

        clearTimeout(staffLoadTimer);
        staffLoadTimer = setTimeout(async () => {
            const sequence = ++staffLoadSequence;
            const params = new URLSearchParams({ department_id: departmentId, start_date: start, end_date: end });
            try {
                const response = await fetch(`${list.dataset.staffLoadUrl}?${params}`, { headers: { Accept: 'application/json' } });
                if (sequence !== staffLoadSequence) return;
                if (!response.ok) throw new Error('unavailable');
                renderStaffPeriodLoad((await response.json()).employees);
            } catch {
                if (sequence === staffLoadSequence) renderStaffPeriodLoad(null);
            }
        }, 250);
    };

    const filterBulkEmployees = () => {
        if (!bulkForm) return;
        const departmentId = bulkForm.querySelector('[data-bulk-department-filter]').value;
        const positionIds = selectedBulkPositionIds();
        const search = bulkForm.querySelector('[data-bulk-employee-search]').value.trim().toLowerCase();

        bulkEmployeeOptions.forEach((option) => {
            const eligible = Boolean(departmentId) && positionIds.length > 0
                && option.dataset.departmentId === departmentId
                && positionIds.includes(option.dataset.positionId)
                && (!search || option.dataset.search.includes(search));
            const checked = option.querySelector('input').checked;
            option.dataset.eligible = eligible ? '1' : '0';
            option.hidden = !eligible
                || (bulkShow === 'selected' && !checked)
                || (bulkShow === 'unselected' && checked);
        });
        const empty = bulkForm.querySelector('[data-bulk-employee-empty]');
        const list = bulkForm.querySelector('[data-bulk-employee-list]');
        const hasVisibleEmployees = bulkEmployeeOptions.some((option) => !option.hidden);
        const hasEligibleEmployees = bulkEmployeeOptions.some((option) => option.dataset.eligible === '1');
        empty.hidden = hasVisibleEmployees;
        // The column headings belong to rows; with none there they are a label
        // for nothing.
        list?.classList.toggle('is-empty', !hasVisibleEmployees);
        syncBulkSelectionTools();

        // Every empty state says what is missing and, where there is one, the
        // single action that fills it.
        let title = 'No employees to show';
        let body = 'No active employees match the selected filters.';
        let action = null;
        if (hasEligibleEmployees && !hasVisibleEmployees) {
            if (bulkShow === 'selected') {
                title = 'Nobody selected yet';
                body = 'Tick the employees to schedule, or switch back to All.';
                action = ['Show all staff', () => setBulkShow('all')];
            } else {
                title = 'Everyone shown is selected';
                body = 'There is nobody left to add under the current filters.';
                action = ['Show all staff', () => setBulkShow('all')];
            }
        } else if (!departmentId) {
            title = 'Choose a department';
            body = 'Select a department to load its active employees.';
        } else if (positionIds.length === 0) {
            const departmentOption = bulkForm.querySelector('[data-bulk-department-filter]').selectedOptions[0];
            const count = Number(departmentOption?.dataset.employeeCount ?? 0);
            const departmentName = departmentOption?.textContent?.trim() ?? 'this department';
            title = count ? 'Choose a position' : 'No active staff on record';
            body = count
                ? `Selecting a position lists the ${count} active employee${count === 1 ? '' : 's'} in ${departmentName}.`
                : `No active employees are on record for ${departmentName} yet.`;
        } else if (search) {
            title = `No employees match “${search}”`;
            body = 'Check the spelling, or search by employee ID instead.';
            action = ['Clear search', () => {
                bulkForm.querySelector('[data-bulk-employee-search]').value = '';
                filterBulkEmployees();
            }];
        }

        empty.querySelector('[data-bulk-employee-empty-title]').textContent = title;
        empty.querySelector('[data-bulk-employee-empty-body]').textContent = body;
        const actionButton = empty.querySelector('[data-bulk-employee-empty-action]');
        actionButton.hidden = action === null;
        if (action) {
            actionButton.textContent = action[0];
            actionButton.onclick = action[1];
        }
    };

    // Rosters are built one department at a time, so the position list only
    // ever shows roles that exist in the chosen department, and staff
    // selection stays locked until at least one position narrows down who is
    // eligible.
    const syncBulkPositionOptions = () => {
        if (!bulkForm) return;
        const departmentId = bulkForm.elements.department_id.value;
        const picker = bulkForm.querySelector('[data-bulk-position-picker]');
        const help = picker.querySelector('[data-bulk-position-help]');
        const options = [...picker.querySelectorAll('[data-bulk-position-options] .bulk-position-option')];
        let visibleCount = 0;
        options.forEach((option) => {
            const matches = option.dataset.departmentId === departmentId;
            option.hidden = !matches;
            const input = option.querySelector('input');
            input.disabled = !matches;
            if (!matches) input.checked = false;
            if (matches) visibleCount += 1;
        });
        // Naming the department is the point of the line: the list below it is
        // already filtered to one, and "include" alone never said which.
        const departmentName = bulkForm.elements.department_id.selectedOptions[0]?.textContent.trim();

        if (!departmentId) {
            help.textContent = 'Select a department first';
        } else if (visibleCount === 0) {
            help.textContent = 'No positions are on record for this department yet.';
        } else {
            help.textContent = departmentName
                ? `Select one or more positions in the ${departmentName}.`
                : 'Select one or more positions to include.';
        }
    };

    // The shifts this run will actually roster: the ticked pool in AI mode, or
    // the single chosen template in fixed mode.
    const selectedShiftOptions = () => (isAiSchedule()
        ? [...bulkForm.querySelectorAll('input[name="shift_ids[]"]:checked')]
        : [...bulkForm.querySelectorAll('select[name="shift_id"] option:checked')].filter((option) => option.value));

    // A pool of standalone shifts only — an 8-to-5 office day and nothing else.
    // Nothing here rotates, so there is no second leg to pair it with.
    const rostersStandaloneShiftsOnly = () => {
        const selected = selectedShiftOptions();

        return selected.length > 0 && selected.every((option) => option.dataset.rotating === '0');
    };

    // Night-shift limit and consecutive-night-streak rules exist for units
    // that actually run overnight clinical shifts — a non-clinical
    // department (Administration, HR, Finance, ...) has no such shift to
    // limit, so these fields are hidden there rather than asking someone to
    // set a night rule for a 9-to-5 unit. The same applies to a clinical unit
    // rostering only its administrative day: no night in the pool, no night
    // rule to set, and no charge-cover question either. Disabled, not just
    // hidden, so a stale value doesn't quietly submit.
    const syncClinicalOnlyFields = () => {
        if (!bulkForm) return;
        const departmentSelect = bulkForm.elements.department_id;
        const isClinical = departmentSelect?.selectedOptions[0]?.dataset.category === 'clinical';
        const standaloneOnly = rostersStandaloneShiftsOnly();
        const hasNightShift = selectedShiftOptions().some((option) => option.dataset.night === '1');

        const apply = (selector, visible) => {
            bulkForm.querySelectorAll(selector).forEach((field) => {
                field.hidden = !visible;
                const input = field.querySelector('input, select, textarea');
                if (input) input.disabled = !visible;
            });
        };

        apply('[data-clinical-only]', isClinical && hasNightShift);
        apply('[data-rotating-only]', !standaloneOnly);
    };

    // A name ticked under a position that has since been unticked is no
    // longer eligible, so it is dropped rather than left selected out of sight.
    const syncBulkPositionAvailability = () => {
        if (!bulkForm) return;
        const positionIds = selectedBulkPositionIds();
        bulkEmployeeOptions.forEach((option) => {
            if (!positionIds.includes(option.dataset.positionId)) option.querySelector('input').checked = false;
        });
    };

    const syncEmployeeScope = () => {
        if (!bulkForm) return;
        const search = bulkForm.querySelector('[data-bulk-employee-search]');
        search.disabled = selectedBulkPositionIds().length === 0;
        filterBulkEmployees();
        updateBulkSelectedCount();
        invalidateBulkReview();
    };

    const setBulkShow = (show) => {
        bulkShow = show;
        filterBulkEmployees();
    };




    bulkForm?.querySelectorAll('select[name="shift_id"], input[name="start_date"], input[name="end_date"], input[name="include_weekends"], select[name="days_off_per_week"], input[name="max_hours_per_week"], input[name="night_shift_limit"], input[name="maximum_staff_per_shift"], input[name="overtime_allowed"], input[name="holiday_dates_csv"]').forEach((field) => {
        field.addEventListener('change', () => {
            updateBulkSelectedCount();
            invalidateBulkReview();
        });
    });

    // Ticking a name only changes who the fill buttons and the per-shift pickers
    // can draw from; the roster itself is untouched. Tearing the review panel down
    // on every tick resized the page under the person doing the ticking.
    bulkForm?.querySelectorAll('input[name="employee_ids[]"]').forEach((field) => {
        field.addEventListener('change', () => {
            updateBulkSelectedCount();
            refreshRosterPickers();
        });
    });
    bulkForm?.querySelectorAll('select[name="schedule_method"], input[name="shift_ids[]"], select[name="shift_id"]').forEach((field) => {
        field.addEventListener('change', () => {
            if (field.name === 'schedule_method') syncScheduleMethod();
            else invalidateBulkReview();

            // Which shifts are chosen decides both how many the pool needs and
            // whether the night and charge-cover rules apply at all. The Next
            // gate re-runs on its own from the form-level change listener.
            syncShiftPoolExclusivity();
            syncShiftPoolHelp();
            syncClinicalOnlyFields();
        });
    });
    bulkForm?.querySelectorAll('[data-schedule-period], input[name="period_start"], input[name="period_month"]').forEach((field) => {
        field.addEventListener('change', () => {
            syncBulkPeriod();
            refreshStaffPeriodLoad();
            invalidateBulkReview();
        });
    });
    bulkForm?.querySelector('[data-bulk-department-filter]')?.addEventListener('change', (event) => {
        bulkEmployeeOptions.forEach((option) => {
            if (option.dataset.departmentId !== event.target.value) option.querySelector('input').checked = false;
        });
        syncBulkPositionOptions();
        syncBulkPositionAvailability();
        syncEmployeeScope();
        syncClinicalOnlyFields();
        refreshStaffPeriodLoad();
    });
    bulkForm?.querySelector('[data-bulk-position-picker]')?.addEventListener('change', (event) => {
        if (!event.target.matches('[data-bulk-position-filter]')) return;
        syncBulkPositionAvailability();
        syncEmployeeScope();
    });
    bulkForm?.querySelector('[data-bulk-employee-search]')?.addEventListener('input', filterBulkEmployees);
    bulkMaster?.addEventListener('change', () => {
        const visible = bulkEmployeeOptions.filter((option) => !option.hidden);
        const shouldSelect = visible.some((option) => !option.querySelector('input').checked);
        visible.forEach((option) => {
            option.querySelector('input').checked = shouldSelect;
        });
        updateBulkSelectedCount();
        refreshRosterPickers();
    });
    bulkShowButtons.forEach((button) => button.addEventListener('click', () => setBulkShow(button.dataset.bulkShow)));
    bulkClearSelection?.addEventListener('click', () => {
        bulkEmployeeOptions.forEach((option) => { option.querySelector('input').checked = false; });
        filterBulkEmployees();
        updateBulkSelectedCount();
        refreshRosterPickers();
    });
    bulkForm?.querySelector('[data-bulk-period-previous]')?.addEventListener('click', () => moveBulkPeriod(-1));
    bulkForm?.querySelector('[data-bulk-period-next]')?.addEventListener('click', () => moveBulkPeriod(1));
    // ---------------------------------------------------------------------
    // Roster board
    //
    // The roster the nursing office is actually looking at: grouped by day and
    // shift, editable, and published exactly as left. The assistant fills it in
    // as a starting point rather than deciding it.
    // ---------------------------------------------------------------------
    const rosterBoard = bulkForm?.querySelector('[data-roster-board]');
    const rosterDays = rosterBoard?.querySelector('[data-roster-days]');
    const rosterSummary = rosterBoard?.querySelector('[data-roster-summary]');
    const rosterFillButton = rosterBoard?.querySelector('[data-roster-fill]');
    const rosterFillShiftButton = rosterBoard?.querySelector('[data-roster-fill-shift]');
    const rosterClearButton = rosterBoard?.querySelector('[data-roster-clear]');
    const rosterSaveDraftButton = rosterBoard?.querySelector('[data-roster-save-draft]');
    const rosterDraftStatus = rosterBoard?.querySelector('[data-roster-draft-status]');
    const rosterAssistantNotice = rosterBoard?.querySelector('[data-roster-assistant-notice]');
    const rosterGapPanel = rosterBoard?.querySelector('[data-roster-gap-panel]');
    const rosterGapTitle = rosterGapPanel?.querySelector('[data-roster-gap-title]');
    const rosterGapList = rosterGapPanel?.querySelector('[data-roster-gap-list]');
    const rosterGapToggle = rosterGapPanel?.querySelector('[data-roster-gap-toggle]');
    const rosterGapBody = rosterGapPanel?.querySelector('[data-roster-gap-body]');
    const rosterNightStreakPanel = rosterBoard?.querySelector('[data-roster-night-streak-panel]');
    const rosterNightStreakTitle = rosterNightStreakPanel?.querySelector('[data-roster-night-streak-title]');
    const rosterNightStreakList = rosterNightStreakPanel?.querySelector('[data-roster-night-streak-list]');
    const rosterBurnoutPanel = rosterBoard?.querySelector('[data-roster-burnout-panel]');
    const rosterBurnoutTitle = rosterBurnoutPanel?.querySelector('[data-roster-burnout-title]');
    const rosterBurnoutList = rosterBurnoutPanel?.querySelector('[data-roster-burnout-list]');
    const rosterAlreadyRosteredPanel = rosterBoard?.querySelector('[data-roster-already-rostered-panel]');
    const rosterAlreadyRosteredTitle = rosterAlreadyRosteredPanel?.querySelector('[data-roster-already-rostered-title]');
    const rosterAlreadyRosteredList = rosterAlreadyRosteredPanel?.querySelector('[data-roster-already-rostered-list]');
    const rosterAlreadyRosteredToggle = rosterAlreadyRosteredPanel?.querySelector('[data-roster-already-rostered-toggle]');
    const rosterAlreadyRosteredBody = rosterAlreadyRosteredPanel?.querySelector('[data-roster-already-rostered-body]');
    const rosterViewButtons = [...(rosterBoard?.querySelectorAll('[data-roster-view]') ?? [])];
    const rosterPagePrevious = rosterBoard?.querySelector('[data-roster-page-previous]');
    const rosterPageNext = rosterBoard?.querySelector('[data-roster-page-next]');
    const rosterPageLabel = rosterBoard?.querySelector('[data-roster-page-label]');
    let rosterEntries = [];
    let rosterEvaluateTimer = null;
    let currentDraftUuid = null;
    let lastEvaluation = null;
    // A month of days at once is unreadable, so the board shows one week — or
    // one day — and pages through the rest. Held here rather than recomputed,
    // so a re-evaluation redraws the page being read instead of jumping home.
    let rosterView = 'week';
    let rosterPage = 0;
    let rosterPages = [];
    // The card being dragged. dataTransfer cannot be read during dragover, and
    // dragover is where a lane decides whether to light up as a target.
    let draggedCard = null;

    // Sunday first, matching Date.getDay() so a date indexes its own column.
    const ROSTER_WEEKDAY_LABELS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

    const rosterKey = (entry) => `${entry.employee_id}|${entry.work_date}`;

    // The assistant's own explanation of the pattern it chose — most useful
    // when it also flags a shift it could not have reached no matter how it
    // arranged people (e.g. too few staff for the rest rule in play).
    // Cleared on any manual edit or fill run that doesn't supply a fresh one,
    // so it never claims to explain a board it no longer describes.
    const setAssistantNotice = (text) => {
        if (!rosterAssistantNotice) return;
        rosterAssistantNotice.hidden = !text;
        rosterAssistantNotice.textContent = text ?? '';
    };

    const selectedEmployees = () => bulkEmployeeOptions
        .filter((option) => option.querySelector('input').checked)
        .map((option) => ({
            id: Number(option.querySelector('input').value),
            name: option.querySelector('.bulk-employee-name').textContent,
        }));

    // Shift colour is what tells two lanes apart at a glance once the card
    // itself is down to a name and a title.
    const shiftColour = (shiftId) => bulkForm
        ?.querySelector(`input[name="shift_ids[]"][value="${shiftId}"], select[name="shift_id"] option[value="${shiftId}"]`)
        ?.dataset.color || '#19704b';

    const SVG_NS = 'http://www.w3.org/2000/svg';
    const ICON_PATHS = {
        plus: 'M12 5v14M5 12h14',
        trash: 'M3 6h18M8 6V4h8v2M19 6l-1 15H6L5 6M10 11v5M14 11v5',
        edit: 'M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L8 18l-4 1 1-4Z',
        'chevron-down': 'm7 10 5 5 5-5',
    };

    /** The same stroked 24×24 shape the x-icon component draws, built in JS. */
    const rosterIcon = (name) => {
        const svg = document.createElementNS(SVG_NS, 'svg');
        svg.setAttribute('class', 'ui-icon');
        svg.setAttribute('viewBox', '0 0 24 24');
        svg.setAttribute('fill', 'none');
        svg.setAttribute('stroke', 'currentColor');
        svg.setAttribute('stroke-width', '1.8');
        svg.setAttribute('stroke-linecap', 'round');
        svg.setAttribute('stroke-linejoin', 'round');
        svg.setAttribute('aria-hidden', 'true');
        const path = document.createElementNS(SVG_NS, 'path');
        path.setAttribute('d', ICON_PATHS[name]);
        svg.append(path);

        return svg;
    };

    const rosterRangePayload = () => ({
        department_id: bulkForm.elements.department_id?.value,
        start_date: bulkForm.elements.start_date?.value,
        end_date: bulkForm.elements.end_date?.value,
    });

    // The Step 2 rules travel with every evaluate call so the live board and
    // the final publish check are held to the same policy the roster was
    // built under, not a looser fallback default.
    // A disabled field (e.g. the clinical-only night-shift fields, hidden for
    // a non-clinical department) still exposes .value in JS even though a
    // native form submit would drop it — read it as absent here too, so a
    // stale or zeroed leftover value can't quietly override the sensible
    // server-side default for a department these rules were never shown for.
    const valueUnlessDisabled = (field) => (field && !field.disabled ? field.value : undefined);

    const rulesPayload = () => ({
        days_off_per_week: bulkForm.elements.days_off_per_week?.value,
        max_hours_per_week: bulkForm.elements.max_hours_per_week?.value,
        night_shift_limit: valueUnlessDisabled(bulkForm.elements.night_shift_limit),
        max_consecutive_nights: valueUnlessDisabled(bulkForm.elements.max_consecutive_nights),
        minimum_rest_hours: bulkForm.elements.minimum_rest_hours?.value,
        overtime_allowed: bulkForm.elements.overtime_allowed?.checked ?? false,
        overtime_justification: bulkForm.elements.overtime_justification?.value,
        night_streak_justification: bulkForm.elements.night_streak_justification?.value,
        burnout_justification: bulkForm.elements.burnout_justification?.value,
        maximum_staff_per_shift: bulkForm.elements.maximum_staff_per_shift?.value,
        minimum_senior_per_shift: valueUnlessDisabled(bulkForm.elements.minimum_senior_per_shift),
        senior_rank_threshold: valueUnlessDisabled(bulkForm.elements.senior_rank_threshold),
        holiday_dates_csv: bulkForm.elements.holiday_dates_csv?.value,
    });

    // Which shift(s) this run is actually about — the single fixed shift, or
    // the checked rotation/custom pool — so the live board and its coverage
    // gate judge only what this roster was actually built for, not every
    // active shift in the department.
    const relevantShiftIds = () => (isAiSchedule()
        ? [...bulkForm.querySelectorAll('input[name="shift_ids[]"]:checked')].map((input) => input.value)
        : [bulkForm.elements.shift_id?.value].filter(Boolean));

    // Turning on overtime requires a reason on record; the field only
    // appears (and is only required) once the toggle is actually checked.
    const overtimeToggle = bulkForm?.elements.overtime_allowed;
    const overtimeJustificationWrap = bulkForm?.querySelector('[data-overtime-justification-wrap]');
    const overtimeJustificationField = bulkForm?.querySelector('[data-overtime-justification]');
    const syncOvertimeJustification = () => {
        if (!overtimeToggle || !overtimeJustificationWrap) return;
        const allowed = overtimeToggle.checked;
        overtimeJustificationWrap.hidden = !allowed;
        if (overtimeJustificationField) overtimeJustificationField.required = allowed;
        if (!allowed && overtimeJustificationField) overtimeJustificationField.value = '';
    };
    overtimeToggle?.addEventListener('change', syncOvertimeJustification);

    const setRosterEntries = (entries) => {
        rosterEntries = entries;
        scheduleRosterEvaluate();
    };

    const removeRosterEntry = (employeeId, date) => {
        rosterEntries = rosterEntries.filter((entry) => rosterKey(entry) !== `${employeeId}|${date}`);
        scheduleRosterEvaluate();
    };

    const addRosterEntry = (employeeId, shiftId, date) => {
        // One placement per person per day: adding to a shift moves them there.
        rosterEntries = rosterEntries.filter((entry) => rosterKey(entry) !== `${employeeId}|${date}`);
        rosterEntries.push({ employee_id: employeeId, shift_id: shiftId, work_date: date });
        scheduleRosterEvaluate();
    };

    // A drop is a move, not a copy: one placement per person per day means the
    // person leaves wherever they were standing and lands here. Landing back on
    // the shift they already hold rewrites the same entry and changes nothing.
    const moveRosterEntry = (employeeId, fromDate, toDate, shiftId) => {
        rosterEntries = rosterEntries.filter((entry) => {
            const key = rosterKey(entry);

            return key !== `${employeeId}|${fromDate}` && key !== `${employeeId}|${toDate}`;
        });
        rosterEntries.push({ employee_id: employeeId, shift_id: shiftId, work_date: toDate });
        scheduleRosterEvaluate();
    };

    const rosterDayNumber = (date) => new Date(`${date}T00:00:00`).getDate();
    const rosterWeekday = (date) => new Date(`${date}T00:00:00`).getDay();
    const rosterShortDate = (date) => new Intl.DateTimeFormat('en-PH', { month: 'short', day: 'numeric' })
        .format(new Date(`${date}T00:00:00`));

    /**
     * Break the scheduled range into what the board shows at one time: a
     * calendar week of seven weekday columns, or a single day.
     *
     * Week pages keep an empty column for a weekday the range does not cover,
     * so Tuesday stays under Tuesday whether or not anyone works it — a grid
     * that reflowed around missing days would be unreadable a fortnight in.
     */
    const buildRosterPages = (days) => {
        if (rosterView === 'day') {
            return days.map((day) => ({ slots: [day], label: formatScheduleDate(day.date) }));
        }

        const weeks = [];
        days.forEach((day) => {
            const weekday = rosterWeekday(day.date);
            const current = weeks[weeks.length - 1];

            if (!current || weekday <= current.lastWeekday) {
                weeks.push({ slots: new Array(7).fill(null), lastWeekday: weekday, covered: [day] });
                weeks[weeks.length - 1].slots[weekday] = day;

                return;
            }

            current.slots[weekday] = day;
            current.lastWeekday = weekday;
            current.covered.push(day);
        });

        return weeks.map((week) => {
            const first = week.covered[0].date;
            const last = week.covered[week.covered.length - 1].date;

            return {
                slots: week.slots,
                label: first === last ? formatScheduleDate(first) : `${rosterShortDate(first)} – ${rosterShortDate(last)}`,
            };
        });
    };

    const rosterPageIndexForDate = (date) => rosterPages
        .findIndex((page) => page.slots.some((day) => day?.date === date));

    const renderPersonCard = (person, day) => {
        const item = document.createElement('li');
        item.className = 'roster-person';
        if (person.blocked) item.classList.add('is-blocked');
        item.draggable = true;
        item.dataset.employeeId = String(person.employee_id);
        item.dataset.date = day.date;

        const body = document.createElement('div');
        body.className = 'roster-person-body';
        const name = document.createElement('strong');
        name.textContent = person.name;
        if (person.is_senior) {
            const badge = document.createElement('em');
            badge.textContent = 'senior';
            name.append(' ', badge);
        }
        if (person.burnout_level === 'high' || person.burnout_level === 'moderate') {
            const risk = document.createElement('em');
            risk.className = `roster-burnout roster-burnout-${person.burnout_level}`;
            risk.textContent = person.burnout_level === 'high' ? 'burnout risk' : 'strained';
            risk.title = `Burnout risk: ${person.burnout_level}`;
            name.append(' ', risk);
        }
        const role = document.createElement('small');
        role.textContent = person.position ?? '';
        body.append(name, role);

        // Kept out of the way until the card is pointed at or focused, so a
        // full week of cards reads as names rather than a wall of buttons.
        const remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'roster-remove';
        remove.title = `Remove ${person.name}`;
        remove.setAttribute('aria-label', `Remove ${person.name} from ${formatScheduleDate(day.date)}`);
        remove.append(rosterIcon('trash'));
        remove.addEventListener('click', () => removeRosterEntry(person.employee_id, day.date));

        item.append(body, remove);

        item.addEventListener('dragstart', (event) => {
            draggedCard = { employeeId: person.employee_id, date: day.date };
            item.classList.add('is-dragging');
            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('text/plain', person.name);
        });
        item.addEventListener('dragend', () => {
            draggedCard = null;
            item.classList.remove('is-dragging');
            rosterDays?.querySelectorAll('.is-drop-target')
                .forEach((lane) => lane.classList.remove('is-drop-target'));
        });

        return item;
    };

    const renderShiftLane = (day, shift) => {
        const lane = document.createElement('section');
        lane.className = `roster-shift${shift.meets_requirement ? '' : ' is-short'}`;
        lane.dataset.date = day.date;
        lane.dataset.shiftId = String(shift.shift_id);

        const heading = document.createElement('header');
        const dot = document.createElement('span');
        dot.className = 'roster-shift-dot';
        dot.style.background = shiftColour(shift.shift_id);
        const shiftName = document.createElement('strong');
        shiftName.textContent = shift.shift;
        shiftName.title = shift.time ? `${shift.shift} · ${shift.time}` : shift.shift;
        const coverage = document.createElement('span');
        coverage.className = 'roster-coverage';
        coverage.textContent = shift.senior_required
            ? `${shift.count}/${shift.required} · ${shift.senior_count}/${shift.senior_required} sr`
            : `${shift.count}/${shift.required}`;
        coverage.title = `Required by the ${shift.requirement_source}.`;
        heading.append(dot, shiftName, coverage);
        lane.append(heading);

        const list = document.createElement('ul');
        list.className = 'roster-people';
        shift.assigned.forEach((person) => list.append(renderPersonCard(person, day)));
        lane.append(list);

        // People an already-published schedule puts on this shift. They count
        // towards the cover shown above but are not cards on this board — it
        // edits this roster, not the one already standing — so without saying so
        // the lane reads as meeting its requirement with nobody in it.
        if (shift.already_rostered > 0) {
            const standing = document.createElement('p');
            standing.className = 'roster-standing';
            standing.textContent = `${shift.already_rostered} already scheduled`;
            standing.title = 'Already published for this shift and date. Publishing this roster will not schedule them a second time.';
            lane.append(standing);
        }

        const picker = document.createElement('select');
        picker.className = 'roster-add';
        rosterPickerOptions(picker);
        picker.addEventListener('change', () => {
            if (!picker.value) return;
            addRosterEntry(Number(picker.value), shift.shift_id, day.date);
        });
        lane.append(picker);

        lane.addEventListener('dragover', (event) => {
            if (!draggedCard) return;
            // Only a handled dragover marks a valid drop; without preventDefault
            // the browser refuses the drop outright.
            event.preventDefault();
            event.dataTransfer.dropEffect = 'move';
            lane.classList.add('is-drop-target');
        });
        lane.addEventListener('dragleave', (event) => {
            if (lane.contains(event.relatedTarget)) return;
            lane.classList.remove('is-drop-target');
        });
        lane.addEventListener('drop', (event) => {
            event.preventDefault();
            lane.classList.remove('is-drop-target');
            if (!draggedCard) return;
            moveRosterEntry(draggedCard.employeeId, draggedCard.date, day.date, shift.shift_id);
            draggedCard = null;
        });

        return lane;
    };

    const renderRosterDayCell = (day) => {
        const cell = document.createElement('article');
        const isToday = day.date === dateInputValue(new Date());
        cell.className = `roster-cell${day.fully_covered ? '' : ' is-short'}${day.is_weekend ? ' is-weekend' : ''}${isToday ? ' is-today' : ''}`;
        cell.dataset.date = day.date;

        const heading = document.createElement('header');
        const number = document.createElement('strong');
        number.textContent = String(rosterDayNumber(day.date));
        const weekday = document.createElement('span');
        weekday.className = 'roster-cell-weekday';
        weekday.textContent = day.weekday;

        // Only surfaces on the day being pointed at: seven always-on add
        // buttons compete with the names, which are what the grid is for.
        const add = document.createElement('button');
        add.type = 'button';
        add.className = 'roster-cell-add';
        add.title = 'Add someone to this day';
        add.setAttribute('aria-label', `Add someone on ${formatScheduleDate(day.date)}`);
        add.append(rosterIcon('plus'));
        add.addEventListener('click', () => {
            const adding = cell.classList.toggle('is-adding');
            add.setAttribute('aria-expanded', String(adding));
            if (adding) cell.querySelector('.roster-add')?.focus();
        });
        add.setAttribute('aria-expanded', 'false');

        heading.append(number, weekday);
        if (isToday) {
            const today = document.createElement('span');
            today.className = 'roster-cell-today';
            today.textContent = 'Today';
            heading.append(today);
        }
        heading.append(add);
        cell.append(heading);

        day.shifts.forEach((shift) => cell.append(renderShiftLane(day, shift)));

        if (day.day_offs.length) {
            const rest = document.createElement('p');
            rest.className = 'roster-cell-rest';
            rest.textContent = `Rest day · ${day.day_offs.length}`;
            rest.title = day.day_offs.map((person) => person.name).join(', ');
            cell.append(rest);
        }

        return cell;
    };

    /** A weekday this range does not reach — held open so the columns line up. */
    const renderEmptyDayCell = () => {
        const cell = document.createElement('article');
        cell.className = 'roster-cell is-empty';
        cell.setAttribute('aria-hidden', 'true');

        return cell;
    };

    const renderRosterCalendar = (evaluation) => {
        if (!rosterDays) return;

        rosterPages = buildRosterPages(evaluation.days);
        rosterDays.classList.toggle('is-day-view', rosterView === 'day');
        rosterDays.replaceChildren();

        if (!rosterPages.length) {
            if (rosterPageLabel) rosterPageLabel.textContent = '—';

            return;
        }

        rosterPage = Math.min(Math.max(rosterPage, 0), rosterPages.length - 1);
        const page = rosterPages[rosterPage];

        if (rosterView === 'week') {
            ROSTER_WEEKDAY_LABELS.forEach((label) => {
                const head = document.createElement('span');
                head.className = 'roster-column-head';
                head.textContent = label;
                rosterDays.append(head);
            });
        }

        page.slots.forEach((day) => rosterDays.append(day ? renderRosterDayCell(day) : renderEmptyDayCell()));

        if (rosterPageLabel) rosterPageLabel.textContent = page.label;
        if (rosterPagePrevious) rosterPagePrevious.disabled = rosterPage === 0;
        if (rosterPageNext) rosterPageNext.disabled = rosterPage >= rosterPages.length - 1;
    };

    /** Bring a date into view, turning the page to it first if it is not on screen. */
    const revealRosterShift = (date, shiftId) => {
        const index = rosterPageIndexForDate(date);
        if (index >= 0 && index !== rosterPage) {
            rosterPage = index;
            if (lastEvaluation) renderRosterCalendar(lastEvaluation);
        }

        const selector = shiftId
            ? `.roster-shift[data-date="${date}"][data-shift-id="${shiftId}"]`
            : `.roster-shift[data-date="${date}"]`;
        const target = rosterDays?.querySelector(selector);
        if (!target) return;
        target.scrollIntoView({ behavior: scrollBehavior(), block: 'center' });
        target.classList.add('roster-shift-flash');
        window.setTimeout(() => target.classList.remove('roster-shift-flash'), 1600);
    };

    const rosterPickerOptions = (picker) => {
        const current = picker.value;
        picker.replaceChildren();
        const placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = 'Add someone to this shift…';
        picker.append(placeholder);
        selectedEmployees().forEach((employee) => {
            const option = document.createElement('option');
            option.value = String(employee.id);
            option.textContent = employee.name;
            picker.append(option);
        });
        picker.value = current;
    };

    /**
     * Bring the per-shift pickers in line with the current selection without
     * rebuilding the board, so the page does not resize while someone is working.
     */
    const refreshRosterPickers = () => {
        rosterDays?.querySelectorAll('.roster-add').forEach(rosterPickerOptions);
    };

    rosterViewButtons.forEach((button) => {
        button.addEventListener('click', () => {
            if (rosterView === button.dataset.rosterView) return;

            // Land on whatever was already on screen rather than back at the
            // start of the period, so switching views does not lose the reader.
            const anchor = rosterPages[rosterPage]?.slots.find(Boolean)?.date;
            rosterView = button.dataset.rosterView;
            rosterViewButtons.forEach((other) => {
                const active = other === button;
                other.classList.toggle('is-active', active);
                other.setAttribute('aria-pressed', String(active));
            });

            if (!lastEvaluation) return;
            rosterPages = buildRosterPages(lastEvaluation.days);
            rosterPage = Math.max(0, anchor ? rosterPageIndexForDate(anchor) : 0);
            renderRosterCalendar(lastEvaluation);
        });
    });

    const turnRosterPage = (step) => {
        rosterPage += step;
        if (lastEvaluation) renderRosterCalendar(lastEvaluation);
    };
    rosterPagePrevious?.addEventListener('click', () => turnRosterPage(-1));
    rosterPageNext?.addEventListener('click', () => turnRosterPage(1));

    // Both hard panels fold the same way: the heading is the button, and the
    // body it hides is its next sibling.
    const bindPanelFold = (toggle, body) => {
        toggle?.addEventListener('click', () => {
            const expanded = toggle.getAttribute('aria-expanded') === 'true';
            toggle.setAttribute('aria-expanded', String(!expanded));
            if (body) body.hidden = expanded;
        });
    };

    bindPanelFold(rosterGapToggle, rosterGapBody);
    bindPanelFold(rosterAlreadyRosteredToggle, rosterAlreadyRosteredBody);

    // Tier A, hard: every under-covered (day, shift) pair, each row jumping
    // straight to that block. There is no justification field here — this
    // panel cannot be dismissed, only resolved by fixing the roster or
    // editing the requirement on Step 2.
    const renderCoverageGaps = (evaluation) => {
        if (!rosterGapPanel) return;
        const gaps = [];
        evaluation.days.forEach((day) => {
            day.shifts.forEach((shift) => {
                if (!shift.meets_requirement) gaps.push({ date: day.date, shift });
            });
        });

        rosterGapPanel.hidden = gaps.length === 0;
        if (!gaps.length) return;

        rosterGapTitle.textContent = `${gaps.length} shift${gaps.length === 1 ? '' : 's'} below required cover. This blocks publishing — there is no override.`;
        rosterGapList.replaceChildren();
        gaps.forEach((gap) => {
            const item = document.createElement('li');
            const button = document.createElement('button');
            button.type = 'button';
            const seniorNote = gap.shift.senior_required ? ` · ${gap.shift.senior_count}/${gap.shift.senior_required} senior` : '';
            button.textContent = `${formatScheduleDate(gap.date)} · ${gap.shift.shift} — ${gap.shift.count}/${gap.shift.required} staff${seniorNote}`;
            button.addEventListener('click', () => revealRosterShift(gap.date, gap.shift.shift_id));
            item.append(button);
            rosterGapList.append(item);
        });
    };

    // Hard: every (date, shift) a previous roster run already published. This
    // is the block the reviewer needs before anything else on the step, since
    // no amount of editing the board below makes the period publishable.
    //
    // Keyed on rostered_by_run, never on already_rostered — the latter counts
    // every published assignment, so a single name added to one day by hand
    // would read as a duplicate run and lock the unit out of its own week.
    const renderAlreadyRostered = (evaluation) => {
        if (!rosterAlreadyRosteredPanel) return;
        const covered = [];
        evaluation.days.forEach((day) => {
            day.shifts.forEach((shift) => {
                if (shift.rostered_by_run > 0) covered.push({ date: day.date, shift });
            });
        });

        rosterAlreadyRosteredPanel.hidden = covered.length === 0;
        if (!covered.length) return;

        const assignments = covered.reduce((total, entry) => total + entry.shift.rostered_by_run, 0);
        const days = new Set(covered.map((entry) => entry.date)).size;
        const period = `${formatScheduleDate(covered[0].date)} – ${formatScheduleDate(covered[covered.length - 1].date)}`;

        rosterAlreadyRosteredTitle.textContent = `${days === 1 ? formatScheduleDate(covered[0].date) : period} already has a published roster — ${assignments} assignment${assignments === 1 ? '' : 's'} across ${days} day${days === 1 ? '' : 's'}. Publishing again is blocked.`;
        rosterAlreadyRosteredList.replaceChildren();
        covered.forEach((entry) => {
            const item = document.createElement('li');
            const button = document.createElement('button');
            button.type = 'button';
            button.textContent = `${formatScheduleDate(entry.date)} · ${entry.shift.shift} — ${entry.shift.rostered_by_run} already scheduled`;
            button.addEventListener('click', () => revealRosterShift(entry.date, entry.shift.shift_id));
            item.append(button);
            rosterAlreadyRosteredList.append(item);
        });
    };

    // Tier B, soft: every (employee, date) placed on a consecutive-night
    // streak beyond the configured limit. Unlike the coverage panel above,
    // these entries are still on the board — publishing them just needs a
    // reason on record.
    const renderNightStreakWarnings = (evaluation) => {
        if (!rosterNightStreakPanel) return;
        const warnings = evaluation.night_streak_warnings ?? [];

        rosterNightStreakPanel.hidden = warnings.length === 0;
        if (!warnings.length) return;

        rosterNightStreakTitle.textContent = `${warnings.length} night shift${warnings.length === 1 ? '' : 's'} beyond the consecutive-night limit. Publishing needs a justification below.`;
        rosterNightStreakList.replaceChildren();
        warnings.forEach((warning) => {
            const item = document.createElement('li');
            const button = document.createElement('button');
            button.type = 'button';
            button.textContent = `${formatScheduleDate(warning.work_date)} · ${warning.employee} — ${warning.shift}`;
            button.addEventListener('click', () => revealRosterShift(warning.work_date, null));
            item.append(button);
            rosterNightStreakList.append(item);
        });
    };

    // Tier B, soft, the same shape as the night streak: someone at high
    // burnout risk placed past their protected limits by hand. Still on the
    // board; publishing just needs a reason on record.
    const renderBurnoutWarnings = (evaluation) => {
        if (!rosterBurnoutPanel) return;
        const warnings = evaluation.burnout_warnings ?? [];

        rosterBurnoutPanel.hidden = warnings.length === 0;
        if (!warnings.length) return;

        const people = new Set(warnings.map((warning) => warning.employee_id)).size;
        rosterBurnoutTitle.textContent = `${warnings.length} placement${warnings.length === 1 ? '' : 's'} take${warnings.length === 1 ? 's' : ''} ${people} employee${people === 1 ? '' : 's'} at high burnout risk past their protected limits. Publishing needs a justification below.`;
        rosterBurnoutList.replaceChildren();
        warnings.forEach((warning) => {
            const item = document.createElement('li');
            const button = document.createElement('button');
            button.type = 'button';
            button.textContent = `${formatScheduleDate(warning.work_date)} · ${warning.employee} — ${warning.shift}: ${warning.reason}`;
            button.addEventListener('click', () => revealRosterShift(warning.work_date, null));
            item.append(button);
            rosterBurnoutList.append(item);
        });
    };

    // Which colour is which shift on the board. Drawn from the evaluation so
    // it names only the shifts this roster actually uses.
    const renderRosterLegend = (evaluation) => {
        const legend = bulkForm?.querySelector('[data-roster-legend]');
        const shifts = legend?.querySelector('[data-roster-legend-shifts]');
        if (!legend || !shifts) return;

        const colours = new Map([...bulkForm.querySelectorAll('input[name="shift_ids[]"], select[name="shift_id"] option')]
            .filter((node) => node.value)
            .map((node) => [String(node.value), node.dataset.color]));
        const seen = new Map();
        (evaluation.days ?? []).forEach((day) => day.shifts.forEach((row) => {
            if (!seen.has(row.shift_id)) seen.set(row.shift_id, row);
        }));

        shifts.replaceChildren();
        seen.forEach((row, shiftId) => {
            const item = document.createElement('span');
            item.className = 'roster-legend-shift';
            const dot = document.createElement('span');
            dot.className = 'roster-legend-dot';
            const colour = colours.get(String(shiftId));
            if (colour) dot.style.background = colour;
            const name = document.createElement('strong');
            name.textContent = row.shift;
            item.append(dot, name);
            if (row.time) {
                const time = document.createElement('small');
                time.textContent = row.time;
                item.append(time);
            }
            shifts.append(item);
        });
        legend.hidden = seen.size === 0;
    };

    /**
     * How the draft lands on each person: shifts, paid hours against the weekly
     * maximum in play, and the rest days they end up with. The board is
     * arranged by day, so this is the only place the fairness of a roster —
     * the thing the whole run is judged on — can actually be read.
     */
    const renderRosterWorkload = (evaluation) => {
        const panel = bulkForm?.querySelector('[data-roster-workload]');
        const list = panel?.querySelector('[data-roster-workload-list]');
        if (!panel || !list) return;

        const shiftHours = new Map([...bulkForm.querySelectorAll('input[name="shift_ids[]"], select[name="shift_id"] option')]
            .filter((node) => node.value)
            .map((node) => [String(node.value), Number(node.dataset.hours) || 0]));
        const people = new Map();
        const record = (id, name) => {
            if (!people.has(id)) people.set(id, { name, shifts: 0, hours: 0, rest: [] });

            return people.get(id);
        };
        const weekdayOf = (date) => SHORT_WEEKDAYS[parseScheduleDate(date).getDay()];

        (evaluation.days ?? []).forEach((day) => {
            day.shifts.forEach((row) => row.assigned.filter((person) => !person.blocked).forEach((person) => {
                const entry = record(person.employee_id, person.name);
                entry.shifts += 1;
                entry.hours += shiftHours.get(String(row.shift_id)) ?? 0;
            }));
            day.day_offs.forEach((person) => record(person.employee_id, person.name).rest.push(weekdayOf(day.date)));
        });

        const weeks = Math.max(1, Math.round((evaluation.days?.length ?? 7) / 7));
        const limit = (Number(bulkForm.elements.max_hours_per_week?.value) || 48) * weeks;
        const rows = [...people.values()].sort((a, b) => b.hours - a.hours || a.name.localeCompare(b.name));

        list.replaceChildren();
        rows.forEach((person) => {
            const item = document.createElement('li');
            const heading = document.createElement('div');
            heading.className = 'roster-workload-heading';
            heading.append(
                element('strong', null, person.name),
                element('span', null, `${person.shifts} shift${person.shifts === 1 ? '' : 's'} · ${Math.round(person.hours * 10) / 10} h`),
            );
            const bar = document.createElement('span');
            bar.className = 'roster-workload-bar';
            const fill = document.createElement('span');
            const share = limit > 0 ? Math.min(100, (person.hours / limit) * 100) : 0;
            fill.style.width = `${share}%`;
            fill.className = share >= 90 ? 'is-heavy' : '';
            bar.append(fill);
            item.append(heading, bar, element('small', null, person.rest.length
                ? `Rest ${person.rest.join(', ')}`
                : 'No rest day in this period'));
            list.append(item);
        });

        panel.querySelector('[data-roster-workload-note]').textContent = weeks === 1
            ? `Paid hours against the ${limit}-hour weekly limit`
            : `Paid hours against ${limit} h — ${weeks} weeks at the weekly limit`;
        const shared = rows.length ? Math.round((rows[0].hours - rows[rows.length - 1].hours) * 10) / 10 : 0;
        panel.querySelector('[data-roster-workload-tally]').textContent = rows.length
            ? `${rows.length} employee${rows.length === 1 ? '' : 's'} · ${shared} h between the heaviest and lightest`
            : '';
        panel.hidden = rows.length === 0;
    };

    const renderRoster = (evaluation) => {
        if (!rosterDays) return;
        lastEvaluation = evaluation;

        // Redrawing the board changes its height; holding the scroll position
        // keeps whatever the reviewer was reading in place.
        const scroller = bulkForm.querySelector('.bulk-schedule-form');
        const previousScroll = scroller?.scrollTop ?? 0;

        renderRosterCalendar(evaluation);
        renderCoverageGaps(evaluation);
        renderAlreadyRostered(evaluation);
        renderNightStreakWarnings(evaluation);
        renderBurnoutWarnings(evaluation);
        renderRosterLegend(evaluation);
        renderRosterWorkload(evaluation);
        if (scroller) scroller.scrollTop = previousScroll;

        if (rosterSummary) {
            const {
                assignments, day_offs: rest, blocked, shifts_short: short, night_streak_warnings: streaks,
                burnout_warnings: burnout, burnout_protected: atRisk,
            } = evaluation.summary;
            const parts = [`${assignments} assignment(s)`, `${rest} rest day(s)`];
            if (blocked) parts.push(`${blocked} cannot be scheduled`);
            parts.push(short ? `${short} shift(s) below the required cover` : 'every shift meets its requirement');
            if (streaks) parts.push(`${streaks} beyond the consecutive-night limit`);
            if (atRisk) parts.push(`${atRisk} employee(s) at high burnout risk${burnout ? `, ${burnout} placement(s) past their limits` : ''}`);
            rosterSummary.textContent = `${parts.join(' · ')}.${evaluation.coverage_standard ? ` ${evaluation.coverage_standard}` : ''}`;
        }

        if (bulkApprovalWrap) bulkApprovalWrap.hidden = evaluation.summary.assignments === 0;
        rosterBoard.hidden = false;
        // A fresh evaluation can open or close the coverage-gap gate without
        // the reviewer touching any field directly (e.g. after a fill-button
        // run), so re-check Next immediately rather than waiting for the next
        // native input/change event.
        refreshStepGate();
    };

    const evaluateRoster = async () => {
        const range = rosterRangePayload();
        if (!range.department_id || !range.start_date || !range.end_date) return;

        try {
            const response = await fetch(bulkForm.dataset.rosterEvaluateUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken() },
                body: JSON.stringify({ ...range, ...rulesPayload(), shift_ids: relevantShiftIds(), entries: rosterEntries }),
            });
            if (response.status === 419) throw new Error('Your session needs to be refreshed — reopen this window and try again.');
            const payload = await response.json();
            if (!response.ok) throw new Error(Object.values(payload.errors ?? {}).flat()[0] ?? 'Unable to check the roster.');
            // This is a plain re-check of whatever is on the board, not a
            // fresh proposal, so any notice from the last fill run no longer
            // describes it.
            setAssistantNotice(null);
            renderRoster(payload.data);
        } catch (error) {
            // rosterSummary lives inside .roster-board, which is still hidden
            // until a *successful* evaluate response unhides it — writing the
            // error there means a failed check shows nothing at all. The
            // always-visible review panel above the board is where a reviewer
            // will actually see it.
            updateBulkReview('Could not check the roster', error.message, 'no-ready');
        }
    };

    const scheduleRosterEvaluate = () => {
        window.clearTimeout(rosterEvaluateTimer);
        rosterEvaluateTimer = window.setTimeout(evaluateRoster, 250);
    };

    // Clearing or refilling throws away whatever is on the board, including
    // hand edits that exist nowhere else yet. Ask only when there is something
    // to lose; an empty board just fills.
    const confirmBoardLoss = ({ title, action, button }) => {
        const count = rosterEntries.length;
        if (count === 0) return Promise.resolve(true);

        return confirmAction({
            title,
            message: `The ${count} ${count === 1 ? 'shift assignment' : 'shift assignments'} on the board now will be ${action}. Nothing already published changes.`,
            button,
            tone: 'caution',
        });
    };

    // Both fill buttons only propose: they replace what is on the board, and
    // nothing reaches the database until the roster is published.
    const fillRosterFrom = async (button, url, busyLabel, failure) => {
        if (!(await confirmBoardLoss({
            title: 'Replace the roster board?',
            action: 'swapped for a new fill',
            button: 'Replace board',
        }))) return;

        const original = button.textContent;
        button.disabled = true;
        button.textContent = busyLabel;
        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken() },
                body: new FormData(bulkForm),
            });
            const payload = await response.json();
            if (!response.ok) throw new Error(Object.values(payload.errors ?? {}).flat()[0] ?? failure);
            rosterEntries = payload.data.entries;
            // Only the rotation assistant's response carries a notice; a
            // plain shift fill has nothing to say, so this clears it there.
            setAssistantNotice(payload.data.notice ?? null);
            renderRoster(payload.data.evaluation);
        } catch (error) {
            if (rosterSummary) rosterSummary.textContent = error.message;
        } finally {
            button.disabled = false;
            button.textContent = original;
        }
    };

    rosterFillButton?.addEventListener('click', () => fillRosterFrom(
        rosterFillButton,
        bulkForm.dataset.rosterSuggestUrl,
        'Rotating…',
        'The assistant could not build a roster.',
    ));

    rosterFillShiftButton?.addEventListener('click', () => fillRosterFrom(
        rosterFillShiftButton,
        bulkForm.dataset.rosterFillUrl,
        'Filling…',
        'That shift could not be filled.',
    ));

    rosterClearButton?.addEventListener('click', async () => {
        if (!(await confirmBoardLoss({
            title: 'Clear the roster board?',
            action: 'removed',
            button: 'Clear board',
        }))) return;

        setAssistantNotice(null);
        setRosterEntries([]);
    });

    // Persists the board exactly as it stands, so a second reviewer can pick up
    // where the first left off instead of the draft only living in this tab.
    const saveDraft = async () => {
        const range = rosterRangePayload();
        if (!range.department_id || !range.start_date || !range.end_date) return;
        if (!rosterSaveDraftButton) return;

        const original = rosterSaveDraftButton.textContent;
        rosterSaveDraftButton.disabled = true;
        rosterSaveDraftButton.textContent = 'Saving…';
        try {
            const response = await fetch(bulkForm.dataset.rosterDraftSaveUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken() },
                body: JSON.stringify({ ...range, ...rulesPayload(), shift_ids: relevantShiftIds(), entries: rosterEntries, draft_uuid: currentDraftUuid }),
            });
            const payload = await response.json();
            if (!response.ok) throw new Error(Object.values(payload.errors ?? {}).flat()[0] ?? 'Unable to save the draft.');
            currentDraftUuid = payload.data.uuid;
            if (rosterDraftStatus) {
                rosterDraftStatus.hidden = false;
                rosterDraftStatus.textContent = `Draft saved ${new Date(payload.data.updated_at).toLocaleTimeString()}. It stays open for another reviewer until this is published or discarded.`;
            }
        } catch (error) {
            if (rosterDraftStatus) {
                rosterDraftStatus.hidden = false;
                rosterDraftStatus.textContent = error.message;
            }
        } finally {
            rosterSaveDraftButton.disabled = false;
            rosterSaveDraftButton.textContent = original;
        }
    };

    rosterSaveDraftButton?.addEventListener('click', saveDraft);

    // Puts a resumed draft's Step 2 rules and shift selection back
    // into the form, so re-evaluating it uses what was actually on screen
    // when it was saved rather than whatever the form defaults to on a
    // freshly reopened modal.
    const applyDraftRules = (rules) => {
        ['days_off_per_week', 'max_hours_per_week', 'night_shift_limit', 'max_consecutive_nights',
            'minimum_rest_hours', 'maximum_staff_per_shift', 'minimum_senior_per_shift',
            'senior_rank_threshold', 'holiday_dates_csv', 'overtime_justification', 'night_streak_justification',
            'burnout_justification']
            .forEach((field) => {
                if (rules[field] !== undefined && rules[field] !== null && bulkForm.elements[field]) {
                    bulkForm.elements[field].value = rules[field];
                }
            });

        if (bulkForm.elements.overtime_allowed) bulkForm.elements.overtime_allowed.checked = Boolean(rules.overtime_allowed);
        syncOvertimeJustification();

        const shiftIds = (rules.shift_ids ?? []).map(String);
        if (bulkForm.elements.schedule_method) {
            bulkForm.elements.schedule_method.value = shiftIds.length ? 'rotation' : 'fixed';
            syncScheduleMethod();
        }
        if (shiftIds.length) {
            bulkForm.querySelectorAll('input[name="shift_ids[]"]').forEach((input) => {
                input.checked = shiftIds.includes(input.value);
            });
        } else if (rules.shift_id && bulkForm.elements.shift_id) {
            bulkForm.elements.shift_id.value = rules.shift_id;
        }

        // A resumed draft arrives with its pool already ticked, so the pool's
        // own rules have to be re-applied to it rather than left as the empty
        // form had them.
        syncShiftPoolExclusivity();
        syncShiftPoolHelp();
        syncClinicalOnlyFields();
    };

    // Offers any drafts already open for the selected department as a "resume"
    // option, so switching to the roster later does not mean starting blank.
    const offerOpenDrafts = async () => {
        const departmentId = bulkForm.elements.department_id?.value;
        if (!departmentId || !bulkForm.dataset.rosterDraftsUrl || !rosterDraftStatus) return;

        try {
            const response = await fetch(`${bulkForm.dataset.rosterDraftsUrl}?department_id=${departmentId}`, {
                headers: { Accept: 'application/json' },
            });
            const payload = await response.json();
            const drafts = payload.data ?? [];
            if (!drafts.length) return;

            rosterDraftStatus.hidden = false;
            rosterDraftStatus.replaceChildren();
            const label = document.createElement('span');
            label.textContent = `${drafts.length} saved draft(s) for this department: `;
            rosterDraftStatus.append(label);
            drafts.forEach((draft) => {
                const resume = document.createElement('button');
                resume.type = 'button';
                resume.className = 'btn btn-sm btn-outline-primary';
                resume.textContent = `Resume (updated ${new Date(draft.updated_at).toLocaleDateString()})`;
                resume.addEventListener('click', () => {
                    currentDraftUuid = draft.uuid;
                    bulkForm.elements.start_date.value = draft.start_date;
                    bulkForm.elements.end_date.value = draft.end_date;
                    if (bulkForm.elements.period_start) bulkForm.elements.period_start.value = draft.start_date;
                    applyDraftRules(draft.rules ?? {});
                    setRosterEntries(draft.entries ?? []);
                });

                const discard = document.createElement('button');
                discard.type = 'button';
                discard.className = 'btn btn-sm btn-light';
                discard.textContent = 'Discard';
                discard.addEventListener('click', async () => {
                    const confirmed = await confirmAction({
                        title: 'Discard this saved draft?',
                        message: 'Anyone picking it up to continue will lose it too. This can’t be undone.',
                        button: 'Discard draft',
                        tone: 'danger',
                    });
                    if (!confirmed) return;

                    await fetch(bulkForm.dataset.rosterDraftDiscardUrlTemplate.replace('__ID__', draft.id), {
                        method: 'DELETE',
                        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken() },
                    });
                    offerOpenDrafts();
                });

                rosterDraftStatus.append(' ', resume, discard);
            });
        } catch {
            // Not finding a draft to resume is not worth interrupting the reviewer for.
        }
    };

    // The board appears as soon as there is a unit and a date range to roster.
    bulkForm?.querySelectorAll('select[name="department_id"], input[name="start_date"], input[name="end_date"], input[name="period_start"], input[name="period_month"], [data-schedule-period]').forEach((field) => {
        field.addEventListener('change', () => window.setTimeout(scheduleRosterEvaluate, 0));
    });
    bulkForm?.querySelector('[data-bulk-department-filter]')?.addEventListener('change', () => window.setTimeout(offerOpenDrafts, 0));

    bulkApproval?.addEventListener('change', () => {
        bulkSaveButton.disabled = !bulkApproval.checked;
    });
    bulkForm?.addEventListener('submit', (event) => {
        if (!bulkApproval?.checked) {
            event.preventDefault();
            updateBulkReview('Approval required', 'Review the generated recommendation and confirm HR approval before publishing.', 'no-ready');

            return;
        }

        // Publish exactly what is on screen.
        if (rosterEntries.length) {
            bulkForm.querySelectorAll('[data-roster-entry-input]').forEach((input) => input.remove());
            if (currentDraftUuid) {
                const draftInput = document.createElement('input');
                draftInput.type = 'hidden';
                draftInput.name = 'draft_uuid';
                draftInput.value = currentDraftUuid;
                draftInput.setAttribute('data-roster-entry-input', '');
                bulkForm.append(draftInput);
            }
            rosterEntries.forEach((entry, index) => {
                Object.entries({
                    employee_id: entry.employee_id,
                    shift_id: entry.shift_id ?? '',
                    work_date: entry.work_date,
                }).forEach(([field, value]) => {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = `entries[${index}][${field}]`;
                    input.value = value;
                    input.setAttribute('data-roster-entry-input', '');
                    bulkForm.append(input);
                });
            });
        }
    });
    // ---------------------------------------------------------------------
    // Wizard navigation
    //
    // One step panel visible at a time, gated by Next/Back, so the stepper
    // actually reflects where the reviewer is instead of step 1 staying
    // permanently "active" no matter how far the form has progressed.
    // ---------------------------------------------------------------------
    const stepPanels = [...(bulkForm?.querySelectorAll('[data-step-panel]') ?? [])];
    const stepItems = [...(bulkForm?.querySelectorAll('[data-step-item]') ?? [])];
    const stepBackButton = bulkForm?.querySelector('[data-bulk-back]');
    const stepNextButton = bulkForm?.querySelector('[data-bulk-next]');
    const stepErrorElement = bulkForm?.querySelector('[data-bulk-step-error]');
    const publishSummaryGrid = bulkForm?.querySelector('[data-publish-summary-grid]');
    const publishSummaryGap = bulkForm?.querySelector('[data-publish-summary-gap]');
    let currentStep = 1;

    const setStepError = (message) => {
        if (!stepErrorElement) return;
        stepErrorElement.hidden = !message;
        stepErrorElement.textContent = message ?? '';
    };

    const validationRecapList = bulkForm?.querySelector('[data-validation-recap]');
    const validationCheckList = bulkForm?.querySelector('[data-validation-checks]');
    const validationTally = bulkForm?.querySelector('[data-validation-tally]');
    const validationEmpty = bulkForm?.querySelector('[data-validation-empty]');

    const countDays = (start, end) => {
        if (!start || !end) return null;
        const from = new Date(`${start}T00:00:00`);
        const to = new Date(`${end}T00:00:00`);
        if (Number.isNaN(from) || Number.isNaN(to)) return null;

        return Math.round((to - from) / 86400000) + 1;
    };

    // Step 1 and Step 2 read back in the operator's own terms. A rule that was
    // never shown for this department is left out rather than printed as a
    // default they did not choose: `rulesPayload()` already drops the disabled
    // fields, so an absent value here means "not asked for", not "zero".
    const validationRecap = () => {
        const rules = rulesPayload();
        const department = bulkForm.elements.department_id?.selectedOptions?.[0]?.textContent.trim();
        const staff = selectedBulkEmployees().length;
        const positions = selectedBulkPositionIds().length;
        const start = bulkForm.elements.start_date?.value;
        const end = bulkForm.elements.end_date?.value;
        const span = countDays(start, end);
        // The pool's labels hold the name and the time in separate elements, so
        // textContent alone runs them together ("Morning Shift8:00 AM–5:00 PM").
        // Read apart and rejoined with the separator the fixed-shift <option>
        // already prints, so both paths read the same way.
        const shifts = isAiSchedule()
            ? [...bulkForm.querySelectorAll('input[name="shift_ids[]"]:checked')]
                .map((input) => {
                    const label = input.closest('label');
                    const parts = [label?.querySelector('strong'), label?.querySelector('small')]
                        .map((node) => node?.textContent.trim())
                        .filter(Boolean);

                    return parts.join(' · ') || label?.textContent.trim();
                })
                .filter(Boolean)
            : [bulkForm.elements.shift_id?.selectedOptions?.[0]?.textContent.trim()].filter(Boolean);

        return [
            ['Department', department || '—'],
            ['Positions', `${positions} selected`],
            ['Staff', `${staff} ${staff === 1 ? 'employee' : 'employees'}`],
            ['Period', start && end
                ? `${formatScheduleDate(start)} – ${formatScheduleDate(end)}${span ? ` · ${span} days` : ''}`
                : '—'],
            ['Shifts', shifts.length ? shifts.join(', ') : '—'],
            ['Weekends', bulkForm.elements.include_weekends?.checked ? 'Included' : 'Excluded'],
            ['Rest days per week', rules.days_off_per_week],
            ['Max hours per week', rules.max_hours_per_week],
            ['Max consecutive nights', rules.max_consecutive_nights],
            ['Minimum rest between shifts', rules.minimum_rest_hours ? `${rules.minimum_rest_hours} hours` : undefined],
            ['Minimum senior per shift', rules.minimum_senior_per_shift],
            ['Overtime', rules.overtime_allowed ? 'Allowed' : 'Not allowed'],
        ].filter(([, value]) => value !== undefined && value !== null && value !== '');
    };

    // Every check is read straight off the Step 3 evaluation, so the answers
    // here and the board there cannot disagree.
    const validationChecks = () => {
        if (!lastEvaluation) return [];

        const days = lastEvaluation.days ?? [];
        const issues = lastEvaluation.issues ?? [];
        const streaks = lastEvaluation.night_streak_warnings ?? [];
        const burnout = lastEvaluation.burnout_warnings ?? [];
        const shiftRows = days.flatMap((day) => day.shifts ?? []);
        const understaffed = shiftRows.filter((row) => row.count < row.required);
        const shortSenior = shiftRows.filter((row) => row.senior_count < row.senior_required);
        const covered = days.filter((day) => day.fully_covered);

        return [
            {
                label: 'Shift coverage',
                ok: understaffed.length === 0,
                detail: understaffed.length === 0
                    ? `All ${shiftRows.length} shift(s) meet their required staff.`
                    : `${understaffed.length} of ${shiftRows.length} shift(s) are below required staff.`,
            },
            {
                label: 'Senior cover',
                ok: shortSenior.length === 0,
                detail: shortSenior.length === 0
                    ? 'Every shift has the senior cover it requires.'
                    : `${shortSenior.length} shift(s) are below the required senior count.`,
            },
            {
                label: 'Staff placement',
                ok: issues.length === 0,
                detail: issues.length === 0
                    ? 'Every selected employee was placed.'
                    : `${issues.length} placement(s) blocked — the reasons are listed on Step 3.`,
            },
            {
                label: 'Consecutive nights',
                ok: streaks.length === 0,
                detail: streaks.length === 0
                    ? 'Nobody exceeds the consecutive-night limit.'
                    : `${streaks.length} employee(s) exceed the limit — Step 5 will ask you to justify this.`,
            },
            {
                label: 'Burnout risk',
                ok: burnout.length === 0,
                detail: burnout.length === 0
                    ? 'Nobody at high burnout risk is placed past their protected limits.'
                    : `${burnout.length} placement(s) take high-risk staff past their limits — a justification is required.`,
            },
            {
                label: 'Days covered',
                ok: days.length > 0 && covered.length === days.length,
                // Spelled out at both ends rather than "N of N", which reads as
                // a tally of what is fine when it is counting what is not.
                detail: covered.length === days.length
                    ? `All ${days.length} day(s) are fully covered.`
                    : covered.length === 0
                        ? `No day in the period is fully covered yet.`
                        : `${days.length - covered.length} of ${days.length} day(s) still have a gap.`,
            },
        ];
    };

    const laborCompliance = bulkForm?.querySelector('[data-labor-compliance]');
    const laborComplianceList = bulkForm?.querySelector('[data-labor-compliance-list]');
    const laborComplianceTally = bulkForm?.querySelector('[data-labor-compliance-tally]');

    // Read off the same evaluation as the checks above: the server judges the
    // roster publishing would write against each Labor Code article it touches.
    const renderLaborCompliance = () => {
        if (!laborCompliance || !laborComplianceList) return;
        const rows = lastEvaluation?.compliance ?? [];
        laborCompliance.hidden = rows.length === 0;
        laborComplianceList.replaceChildren();
        if (!rows.length) return;

        const ok = rows.filter((row) => row.state === 'ok').length;
        const review = rows.filter((row) => row.state === 'review').length;
        const pending = rows.filter((row) => row.state === 'pending').length;
        if (laborComplianceTally) {
            laborComplianceTally.textContent = [`${ok} compliant`, review ? `${review} to review` : '', pending ? `${pending} not set up` : '']
                .filter(Boolean).join(' · ');
            laborComplianceTally.className = `labor-compliance-tally${review ? ' is-review' : ''}`;
        }

        const stateText = { ok: 'Compliant: ', review: 'Needs review: ', pending: 'Not checked yet: ' };
        const glyph = { ok: '✓', review: '!', pending: '–' };
        rows.forEach((row) => {
            const item = document.createElement('li');
            item.className = `labor-compliance-row is-${row.state}`;
            const mark = document.createElement('span');
            mark.className = 'validation-check-mark';
            mark.setAttribute('aria-hidden', 'true');
            mark.textContent = glyph[row.state] ?? '•';
            const body = document.createElement('div');
            const state = document.createElement('span');
            state.className = 'visually-hidden';
            state.textContent = stateText[row.state] ?? '';
            const label = document.createElement('strong');
            label.textContent = row.rule;
            const detail = document.createElement('small');
            detail.textContent = row.detail;
            body.append(state, label, detail);
            const basis = document.createElement('span');
            basis.className = 'labor-compliance-basis';
            basis.textContent = row.article;
            item.append(mark, body, basis);
            laborComplianceList.append(item);
        });
    };

    const renderValidation = () => {
        if (!validationRecapList || !validationCheckList) return;
        renderLaborCompliance();

        validationRecapList.replaceChildren();
        validationRecap().forEach(([label, value]) => {
            const term = document.createElement('dt');
            term.textContent = label;
            const detail = document.createElement('dd');
            detail.textContent = value;
            validationRecapList.append(term, detail);
        });

        const checks = validationChecks();
        validationCheckList.replaceChildren();
        if (validationEmpty) validationEmpty.hidden = checks.length > 0;
        if (validationTally) {
            const passed = checks.filter((check) => check.ok).length;
            validationTally.textContent = checks.length
                ? `${passed} passed · ${checks.length - passed} to review`
                : '';
        }

        checks.forEach((check) => {
            const item = document.createElement('li');
            item.className = check.ok ? 'validation-check is-ok' : 'validation-check is-warning';
            const mark = document.createElement('span');
            mark.className = 'validation-check-mark';
            mark.setAttribute('aria-hidden', 'true');
            mark.textContent = check.ok ? '✓' : '!';
            const body = document.createElement('div');
            const label = document.createElement('strong');
            label.textContent = check.label;
            const detail = document.createElement('small');
            detail.textContent = check.detail;
            // The icon is decorative, so the state has to reach a screen reader
            // some other way than by colour and a glyph.
            const state = document.createElement('span');
            state.className = 'visually-hidden';
            state.textContent = check.ok ? 'Passed: ' : 'Needs review: ';
            body.append(state, label, detail);
            item.append(mark, body);
            validationCheckList.append(item);
        });
    };

    // What Step 5's attestation is actually agreeing to, computed from the
    // same evaluation the Step 3 board already rendered — no separate call.
    const renderPublishSummary = () => {
        if (!publishSummaryGrid) return;
        publishSummaryGrid.replaceChildren();
        if (!lastEvaluation) return;

        const {
            assignments, day_offs: restDays, employees_affected: employees, night_differential_hours: nightHours,
            shifts_short: short, night_streak_warnings: streaks, burnout_warnings: burnout,
        } = lastEvaluation.summary;
        [
            ['Assignments', assignments],
            ['Rest days', restDays],
            ['Employees notified', employees],
            ['Night-differential hrs', nightHours],
        ].forEach(([label, value]) => {
            const tile = document.createElement('div');
            tile.className = 'publish-stat';
            const strong = document.createElement('strong');
            strong.textContent = value;
            const span = document.createElement('span');
            span.textContent = label;
            tile.append(strong, span);
            publishSummaryGrid.append(tile);
        });

        // Coverage is a hard block at Step 3 — this step should be
        // unreachable with shifts still short — but the fallback below is a
        // defensive guard, not an expected path.
        if (publishSummaryGap) {
            if (short) {
                publishSummaryGap.hidden = false;
                publishSummaryGap.textContent = `${short} shift(s) are still below required cover — this should not be publishable. Go back and fix the roster.`;
            } else if (streaks || burnout) {
                publishSummaryGap.hidden = false;
                const notes = [];
                if (streaks) {
                    const justification = bulkForm.elements.night_streak_justification?.value.trim() || '—';
                    notes.push(`${streaks} night shift(s) beyond the consecutive-night limit. Justification on record: "${justification}"`);
                }
                if (burnout) {
                    const justification = bulkForm.elements.burnout_justification?.value.trim() || '—';
                    notes.push(`${burnout} placement(s) past a high-burnout-risk employee's limits. Justification on record: "${justification}"`);
                }
                publishSummaryGap.textContent = notes.join(' ');
            } else {
                publishSummaryGap.hidden = true;
            }
        }
    };

    // One short line under each finished or current step, so the choices made
    // so far stay in view once the stepper runs across the top.
    const stepSummary = (stepNumber) => {
        const plural = (count, one, many) => `${count} ${count === 1 ? one : many}`;
        if (stepNumber === 1) {
            const staff = selectedBulkEmployees().length;

            return staff ? plural(staff, 'employee', 'employees') : '';
        }
        if (stepNumber === 2) {
            const start = bulkForm.elements.start_date?.value;
            const end = bulkForm.elements.end_date?.value;
            const range = start && end ? `${rosterShortDate(start)} – ${rosterShortDate(end)}` : '';
            const shifts = selectedShiftOptions().length;

            return [range, shifts ? plural(shifts, 'shift', 'shifts') : ''].filter(Boolean).join(' · ');
        }
        if (stepNumber === 3) {
            return lastEvaluation ? plural(lastEvaluation.summary?.assignments ?? 0, 'assignment', 'assignments') : 'Not generated yet';
        }
        if (stepNumber === 4) {
            const checks = validationChecks();

            return checks.length ? `${checks.filter((check) => check.ok).length} of ${checks.length} passed` : '';
        }

        return 'Awaiting approval';
    };

    const syncHeaderContext = () => {
        const context = bulkForm?.querySelector('[data-bulk-header-context]');
        if (!context) return;
        const department = bulkForm.elements.department_id?.value
            ? bulkForm.elements.department_id.selectedOptions[0]?.textContent.trim()
            : '';
        context.textContent = department || 'Choose a department to begin.';
    };

    const syncStepBar = (step) => {
        stepItems.forEach((item) => {
            const stepNumber = Number(item.dataset.stepItem);
            const done = stepNumber < step;
            const current = stepNumber === step;
            item.classList.toggle('active', current);
            item.classList.toggle('done', done);
            const button = item.querySelector('.bulk-flow-step');
            if (button) {
                button.disabled = !done;
                if (current) button.setAttribute('aria-current', 'step');
                else button.removeAttribute('aria-current');
            }
            const state = item.querySelector('[data-step-state]');
            if (state) state.textContent = current ? '(current step)' : done ? '(completed)' : '(not started)';
            const sub = item.querySelector('[data-step-sub]');
            if (sub) sub.textContent = done || current ? stepSummary(stepNumber) : '';
        });
        syncHeaderContext();
    };

    const showStep = (step) => {
        currentStep = step;
        stepPanels.forEach((panel) => { panel.hidden = Number(panel.dataset.stepPanel) !== step; });
        syncStepBar(step);
        if (stepBackButton) stepBackButton.hidden = step === 1;
        if (stepNextButton) stepNextButton.hidden = step === 5;
        if (bulkSaveButton) bulkSaveButton.hidden = step !== 5;
        setStepError(null);
        // Nullable, unlike the other two uses in this file: both of those are
        // reached only from the roster board, while showStep(1) runs on load —
        // and schedule.js loads on every page, most of which have no wizard.
        const scroller = bulkForm?.querySelector('.bulk-schedule-form');
        if (scroller) scroller.scrollTop = 0;
        // Entering the draft step should reflect whatever was just
        // configured, not a stale board from an earlier pass.
        if (step === 3) scheduleRosterEvaluate();
        if (step === 4) renderValidation();
        if (step === 5) renderPublishSummary();
        refreshStepGate();
    };

    const validateStep1 = () => {
        if (!bulkForm.elements.department_id.value) return 'Select a department to continue.';
        if (selectedBulkPositionIds().length === 0) return 'Select at least one position to continue.';
        if (selectedBulkEmployees().length === 0) return 'Select at least one employee to continue.';

        return null;
    };

    const validateStep2 = () => {
        const isMonthly = bulkForm.elements.schedule_period.value === 'monthly';
        const anchor = isMonthly ? bulkForm.elements.period_month : bulkForm.elements.period_start;
        if (!anchor.value) return 'Choose a schedule period to continue.';
        if (!isMonthly && anchor.value < dateInputValue(new Date())) return 'Choose a start date that is not in the past.';

        if (isAiSchedule()) {
            const checkedShifts = bulkForm.querySelectorAll('input[name="shift_ids[]"]:checked').length;

            if (checkedShifts === 0) return 'Select a shift for the shift pool.';

            // One shift is a complete answer when it stands alone — an 8-to-5
            // office day covers its unit by itself. A rotating leg does not:
            // Morning on its own leaves the afternoon and the night unstaffed.
            if (checkedShifts < 2 && !rostersStandaloneShiftsOnly()) {
                return 'Select at least two shifts — a rotating shift covers only part of the day.';
            }
        } else if (!bulkForm.elements.shift_id.value) {
            return 'Select a shift to continue.';
        }

        return null;
    };

    const validateStep3 = () => {
        // Ahead of the empty-board check on purpose: when the assistant finds
        // the period already rostered it proposes nothing at all, so the board
        // is empty for that very reason. "Build a roster first" would send the
        // reviewer back to press a button that cannot produce anything.
        const rostered = lastEvaluation?.summary?.shifts_already_rostered ?? 0;
        if (rostered > 0) {
            const days = lastEvaluation?.summary?.days_already_rostered ?? 0;
            const existing = lastEvaluation?.summary?.assignments_already_rostered ?? 0;

            return `This period already has a published roster — ${existing} assignment(s) across ${days} day(s). Publishing again would schedule this department twice. Remove those assignments from the calendar, or choose a period that is not yet rostered — there is no override.`;
        }

        if (rosterEntries.length === 0) return 'Build a roster below — fill it or let the assistant rotate staff — before continuing.';

        // Tier A, hard: no justification unlocks this one. The only way past
        // it is to actually meet the requirement or edit it on Step 2.
        const short = lastEvaluation?.summary?.shifts_short ?? 0;
        if (short > 0) {
            return `${short} shift(s) are still below required cover. Fix the roster or lower the minimum staff / senior requirement on Step 2 to continue — there is no override.`;
        }

        // Tier B, soft: publishable, but only with a reason on record.
        const streaks = lastEvaluation?.summary?.night_streak_warnings ?? 0;
        if (streaks > 0 && !bulkForm.elements.night_streak_justification?.value.trim()) {
            return `${streaks} night shift(s) exceed the consecutive-night limit. Enter a justification above to publish anyway, or adjust the roster.`;
        }

        const burnout = lastEvaluation?.summary?.burnout_warnings ?? 0;
        if (burnout > 0 && !bulkForm.elements.burnout_justification?.value.trim()) {
            return `${burnout} placement(s) take employees at high burnout risk past their protected limits. Enter a justification above to publish anyway, or adjust the roster.`;
        }

        return null;
    };

    const stepValidators = { 1: validateStep1, 2: validateStep2, 3: validateStep3 };

    // Keeps Next disabled — with the blocking reason as its title/tooltip —
    // for as long as the current step's validator fails, instead of only
    // rejecting the attempt after it's clicked.
    const refreshStepGate = () => {
        syncStepBar(currentStep);
        if (!stepNextButton) return;
        const message = stepValidators[currentStep]?.();
        stepNextButton.disabled = Boolean(message);
        stepNextButton.title = message ?? '';
    };
    bulkForm?.addEventListener('input', refreshStepGate);
    bulkForm?.addEventListener('change', refreshStepGate);

    stepNextButton?.addEventListener('click', () => {
        const message = stepValidators[currentStep]?.();
        if (message) {
            setStepError(message);

            return;
        }
        showStep(Math.min(5, currentStep + 1));
    });
    stepBackButton?.addEventListener('click', () => showStep(Math.max(1, currentStep - 1)));
    bulkForm?.querySelectorAll('[data-bulk-goto-step]').forEach((button) => {
        button.addEventListener('click', () => showStep(Number(button.dataset.bulkGotoStep)));
    });
    // Jumping back to an already-completed step is safe; jumping ahead stays
    // gated behind Next so a step can't be skipped without its data.
    stepItems.forEach((item) => {
        item.addEventListener('click', () => {
            const stepNumber = Number(item.dataset.stepItem);
            if (stepNumber < currentStep) showStep(stepNumber);
        });
    });

    bulkModalElement?.addEventListener('shown.bs.modal', () => {
        const modalBody = bulkForm.querySelector('.bulk-schedule-form');
        const employeePicker = bulkForm.querySelector('.bulk-employee-picker');
        const scheduleDetails = bulkForm.querySelector('.bulk-schedule-details');
        if (modalBody && employeePicker && scheduleDetails) {
            modalBody.insertBefore(employeePicker, scheduleDetails);
            employeePicker.hidden = false;
            employeePicker.style.removeProperty('display');
        }
        showStep(1);
        bulkForm.elements.department_id?.focus({ preventScroll: true });
    });
    bulkModalElement?.addEventListener('hidden.bs.modal', () => {
        bulkForm.reset();
        bulkForm.action = bulkForm.dataset.rosterPublishUrl;
        bulkForm.querySelectorAll('[data-roster-entry-input]').forEach((input) => input.remove());
        rosterEntries = [];
        currentDraftUuid = null;
        if (rosterDraftStatus) {
            rosterDraftStatus.hidden = true;
            rosterDraftStatus.replaceChildren();
        }
        if (rosterDays) rosterDays.replaceChildren();
        if (rosterBoard) rosterBoard.hidden = true;
        // A fresh run starts at the beginning of its own period, not on
        // whatever week the last one was left open at.
        rosterPages = [];
        rosterPage = 0;
        if (rosterGapPanel) rosterGapPanel.hidden = true;
        if (rosterGapBody) rosterGapBody.hidden = true;
        rosterGapToggle?.setAttribute('aria-expanded', 'false');
        if (rosterNightStreakPanel) rosterNightStreakPanel.hidden = true;
        if (rosterBurnoutPanel) rosterBurnoutPanel.hidden = true;
        if (rosterAlreadyRosteredPanel) rosterAlreadyRosteredPanel.hidden = true;
        if (rosterAlreadyRosteredBody) rosterAlreadyRosteredBody.hidden = true;
        rosterAlreadyRosteredToggle?.setAttribute('aria-expanded', 'false');
        if (publishSummaryGrid) publishSummaryGrid.replaceChildren();
        if (publishSummaryGap) publishSummaryGap.hidden = true;
        lastEvaluation = null;
        bulkShow = 'all';
        syncOvertimeJustification();
        syncScheduleMethod();
        syncBulkPeriod();
        syncBulkPositionOptions();
        syncBulkPositionAvailability();
        syncEmployeeScope();
        syncClinicalOnlyFields();
        updateBulkSelectedCount();
        syncSchedulePatternHint();
        renderStaffPeriodLoad(null);
        refreshStaffPeriodLoad();
        invalidateBulkReview();
        showStep(1);
    });
    syncOvertimeJustification();
    syncScheduleMethod();
    syncBulkPeriod();
    syncBulkPositionOptions();
    syncBulkPositionAvailability();
    syncEmployeeScope();
    syncClinicalOnlyFields();
    updateBulkSelectedCount();
    syncSchedulePatternHint();
    showStep(1);

    const shiftModalElement = document.querySelector('#shiftTemplateModal');
    const shiftForm = document.querySelector('#shiftTemplateForm');
    const setShiftMode = (shift = null) => {
        if (!shiftForm) return;
        shiftForm.reset();
        shiftForm.action = shift
            ? replaceRouteId(shiftForm.dataset.updateUrlTemplate, shift.id)
            : shiftForm.dataset.storeUrl;
        shiftForm.querySelector('[data-method-field]').value = shift ? 'PUT' : 'POST';
        shiftForm.querySelector('.modal-title').textContent = shift ? 'Edit shift template' : 'New shift template';
        shiftForm.querySelector('button[type="submit"]').textContent = shift ? 'Update template' : 'Save template';
        shiftForm.querySelector('[data-shift-code-preview]').value = shift ? shift.code : '';

        if (!shift) return;
        shiftForm.elements.name.value = shift.name;
        shiftForm.elements.start_time.value = shift.start_time;
        shiftForm.elements.end_time.value = shift.end_time;
        shiftForm.elements.break_minutes.value = shift.break_minutes;
        shiftForm.elements.color.value = shift.color;
        shiftForm.elements.is_active.checked = Boolean(shift.is_active);
        shiftForm.elements.is_rotating.checked = Boolean(shift.is_rotating);
    };

    document.querySelector('[data-new-shift]')?.addEventListener('click', () => setShiftMode());
    document.querySelectorAll('[data-edit-shift]').forEach((button) => {
        button.addEventListener('click', () => setShiftMode(JSON.parse(button.dataset.shift)));
    });

    shiftModalElement?.addEventListener('hidden.bs.modal', () => setShiftMode());
});
