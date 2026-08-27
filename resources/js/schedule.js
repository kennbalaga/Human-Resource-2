const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

const replaceRouteId = (template, id) => template.replace('__ID__', String(id));

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
    const detailModalElement = document.querySelector('#scheduleDetailModal');
    let activeAssignment = null;
    let conflictRequest = 0;

    const setAssignmentMode = (assignment = null, preferredDate = null) => {
        if (!assignmentForm) return;

        assignmentForm.reset();
        assignmentForm.action = assignment
            ? replaceRouteId(assignmentForm.dataset.updateUrlTemplate, assignment.id)
            : assignmentForm.dataset.storeUrl;
        assignmentForm.querySelector('[data-method-field]').value = assignment ? 'PUT' : 'POST';
        assignmentForm.querySelector('.modal-title').textContent = assignment ? 'Edit schedule assignment' : 'Assign a shift';
        assignmentForm.querySelector('button[type="submit"]').textContent = assignment ? 'Update assignment' : 'Save assignment';

        assignmentForm.elements.employee_id.value = assignment?.employee_id ?? '';
        assignmentForm.elements.shift_id.value = assignment?.shift_id ?? '';
        assignmentForm.elements.work_date.value = assignment?.date ?? preferredDate ?? assignmentForm.elements.work_date.defaultValue;
        assignmentForm.elements.notes.value = assignment?.notes ?? '';
        updateConflictStatus('idle', 'Select an employee, shift, and date to check availability.');

        if (assignment) checkConflicts(assignment.id);
    };

    const updateConflictStatus = (state, message) => {
        const status = assignmentForm?.querySelector('[data-conflict-status]');
        if (!status) return;

        status.classList.remove('checking', 'available', 'conflict');
        if (state !== 'idle') status.classList.add(state);
        status.querySelector('span').textContent = message;
    };

    const checkConflicts = async (excludeId = null) => {
        if (!assignmentForm) return;

        const employeeId = assignmentForm.elements.employee_id.value;
        const shiftId = assignmentForm.elements.shift_id.value;
        const workDate = assignmentForm.elements.work_date.value;
        if (!employeeId || !shiftId || !workDate) {
            updateConflictStatus('idle', 'Select an employee, shift, and date to check availability.');
            return;
        }

        const requestId = ++conflictRequest;
        updateConflictStatus('checking', 'Checking this employee’s availability…');

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

            if (result.day_off) {
                updateConflictStatus('conflict', `Conflict: this employee has a scheduled day off on ${formatScheduleDate(result.day_off.date)}.`);
            } else if (conflicts.length) {
                const conflict = conflicts[0];
                updateConflictStatus('conflict', `Conflict: ${conflict.shift} on ${formatScheduleDate(conflict.date)} (${conflict.time}).`);
            } else if (restConflicts.length) {
                const conflict = restConflicts[0];
                updateConflictStatus('conflict', `Rest conflict: not enough rest before or after ${conflict.shift} on ${formatScheduleDate(conflict.date)} (${conflict.time}).`);
            } else {
                updateConflictStatus('available', 'No overlap found. This employee is available.');
            }
        } catch (error) {
            if (requestId === conflictRequest) updateConflictStatus('conflict', error.message);
        }
    };

    assignmentForm?.querySelectorAll('select[name="employee_id"], select[name="shift_id"], input[name="work_date"]').forEach((field) => {
        field.addEventListener('change', () => checkConflicts(activeAssignment?.id ?? null));
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

    const showDetails = (assignment) => {
        if (!detailModalElement) return;
        activeAssignment = assignment;
        const values = {
            '[data-detail-shift]': assignment.shift,
            '[data-detail-employee]': assignment.employee,
            '[data-detail-employee-number]': assignment.employee_number,
            '[data-detail-date]': formatScheduleDate(assignment.date),
            '[data-detail-time]': assignment.time,
            '[data-detail-department]': assignment.department || 'Not assigned',
            '[data-detail-recurring]': assignment.recurring ? 'Recurring series' : 'One-time assignment',
            '[data-detail-notes]': assignment.notes || 'No notes',
        };

        Object.entries(values).forEach(([selector, value]) => {
            detailModalElement.querySelector(selector).textContent = value;
        });

        // A day that has already started is view only: today's roster moves only
        // through an approved shift swap, and a past one not at all.
        const deleteForm = detailModalElement.querySelector('[data-delete-assignment-form]');
        const editButton = detailModalElement.querySelector('[data-edit-assignment]');
        const lockNotice = detailModalElement.querySelector('[data-detail-lock]');
        const editable = assignment.editable !== false;

        deleteForm.action = replaceRouteId(assignmentForm.dataset.updateUrlTemplate, assignment.id);
        deleteForm.hidden = !editable;
        if (editButton) editButton.hidden = !editable;
        if (lockNotice) {
            lockNotice.hidden = editable;
            lockNotice.querySelector('[data-detail-lock-message]').textContent = assignment.date === detailModalElement.dataset.scheduleToday
                ? 'Today’s schedule is view only. An approved shift swap is the only way to change it.'
                : 'This date has already passed. Its schedule is kept as a record and can no longer be changed.';
        }

        window.bootstrap.Modal.getOrCreateInstance(detailModalElement).show();
    };

    document.querySelectorAll('[data-schedule-event]').forEach((eventButton) => {
        eventButton.addEventListener('click', () => showDetails(JSON.parse(eventButton.dataset.scheduleEvent)));
    });

    detailModalElement?.querySelector('[data-edit-assignment]')?.addEventListener('click', () => {
        if (!activeAssignment) return;
        const detailModal = window.bootstrap.Modal.getOrCreateInstance(detailModalElement);
        detailModal.hide();
        setAssignmentMode(activeAssignment);
        detailModalElement.addEventListener('hidden.bs.modal', () => {
            window.bootstrap.Modal.getOrCreateInstance(assignmentModalElement).show();
        }, { once: true });
    });

    const recurringForm = document.querySelector('#recurringScheduleForm');
    const updateRecurrenceForm = () => {
        if (!recurringForm) return;
        const type = recurringForm.elements.recurrence_type.value;
        const weekly = type === 'weekly';
        const weekdaySelector = recurringForm.querySelector('[data-weekday-selector]');
        const intervalSelect = recurringForm.elements.interval_weeks;
        weekdaySelector.hidden = !weekly;
        intervalSelect.disabled = !weekly;

        const selectedDays = [...recurringForm.querySelectorAll('input[name="weekdays[]"]:checked')]
            .map((input) => input.nextElementSibling.textContent);
        const interval = Number(intervalSelect.value);
        recurringForm.querySelector('[data-recurrence-summary]').textContent = weekly
            ? `Repeats every ${interval === 1 ? 'week' : `${interval} weeks`} on ${selectedDays.join(', ') || 'no selected days'}.`
            : 'Repeats every day within the selected date range.';
    };

    recurringForm?.addEventListener('change', updateRecurrenceForm);
    updateRecurrenceForm();

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

        help.textContent = bulkForm.elements.schedule_method?.value === 'custom'
            ? 'Select at least two shifts. The assistant creates a stable custom mix across employees while balancing coverage.'
            : 'Select at least two shifts. The assistant balances coverage and rotates employees weekly.';
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
            box.closest('label')?.classList.toggle('is-unavailable', blocked);
            box.closest('label')?.setAttribute(
                'title',
                blocked
                    ? 'A standalone shift cannot be combined with a rotating one. Untick the current selection to choose this instead.'
                    : '',
            );
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
        invalidateBulkReview();
    };

    const filterBulkEmployees = () => {
        if (!bulkForm) return;
        const departmentId = bulkForm.querySelector('[data-bulk-department-filter]').value;
        const positionIds = selectedBulkPositionIds();
        const search = bulkForm.querySelector('[data-bulk-employee-search]').value.trim().toLowerCase();

        bulkEmployeeOptions.forEach((option) => {
            const matches = Boolean(departmentId) && positionIds.length > 0
                && option.dataset.departmentId === departmentId
                && positionIds.includes(option.dataset.positionId)
                && (!search || option.dataset.search.includes(search));
            option.hidden = !matches;
        });
        const empty = bulkForm.querySelector('[data-bulk-employee-empty]');
        const hasVisibleEmployees = bulkEmployeeOptions.some((option) => !option.hidden);
        empty.hidden = hasVisibleEmployees;
        if (!departmentId) {
            empty.textContent = 'Select a department to load its active employees.';
        } else if (positionIds.length === 0) {
            const departmentOption = bulkForm.querySelector('[data-bulk-department-filter]').selectedOptions[0];
            const count = Number(departmentOption?.dataset.employeeCount ?? 0);
            const departmentName = departmentOption?.textContent ?? 'this department';
            empty.textContent = count
                ? `Loads ${count} active employee${count === 1 ? '' : 's'} in ${departmentName} once at least one position is selected.`
                : `No active employees are on record for ${departmentName} yet.`;
        } else {
            empty.textContent = 'No active employees match the selected filters.';
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
        if (!departmentId) {
            help.textContent = 'Select a department first';
        } else if (visibleCount === 0) {
            help.textContent = 'No positions are on record for this department yet.';
        } else {
            help.textContent = 'Select one or more positions to include.';
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

    const syncBulkPositionAvailability = () => {
        if (!bulkForm) return;
        const hasPosition = selectedBulkPositionIds().length > 0;
        const employeeScope = bulkForm.elements.employee_scope;
        if (!employeeScope) return;
        employeeScope.disabled = !hasPosition;
        if (!hasPosition) employeeScope.value = 'specific';
    };

    const syncEmployeeScope = () => {
        if (!bulkForm) return;
        const scope = bulkForm.elements.employee_scope?.value ?? 'specific';
        const allStaff = scope === 'all';
        const departmentId = bulkForm.elements.department_id.value;
        const positionIds = selectedBulkPositionIds();
        const search = bulkForm.querySelector('[data-bulk-employee-search]');
        const selectAll = bulkForm.querySelector('[data-bulk-select-all]');
        search.disabled = allStaff || positionIds.length === 0;
        selectAll.hidden = allStaff;

        if (allStaff) {
            search.value = '';
            bulkEmployeeOptions.forEach((option) => {
                option.querySelector('input').checked = Boolean(departmentId) && positionIds.length > 0
                    && option.dataset.departmentId === departmentId
                    && positionIds.includes(option.dataset.positionId);
            });
        }

        filterBulkEmployees();
        updateBulkSelectedCount();
        invalidateBulkReview();
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
    });
    bulkForm?.querySelector('[data-bulk-position-picker]')?.addEventListener('change', (event) => {
        if (!event.target.matches('[data-bulk-position-filter]')) return;
        syncBulkPositionAvailability();
        syncEmployeeScope();
    });
    bulkForm?.querySelector('[data-bulk-employee-search]')?.addEventListener('input', filterBulkEmployees);
    bulkForm?.querySelector('[data-bulk-employee-scope]')?.addEventListener('change', syncEmployeeScope);
    bulkForm?.querySelector('[data-bulk-select-all]')?.addEventListener('click', () => {
        const visible = bulkEmployeeOptions.filter((option) => !option.hidden);
        const shouldSelect = visible.some((option) => !option.querySelector('input').checked);
        visible.forEach((option) => {
            option.querySelector('input').checked = shouldSelect;
        });
        updateBulkSelectedCount();
        invalidateBulkReview();
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
            name: option.querySelector('strong').textContent,
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
        cell.className = `roster-cell${day.fully_covered ? '' : ' is-short'}`;
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

        heading.append(number, weekday, add);
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
        target.scrollIntoView({ behavior: 'smooth', block: 'center' });
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

    rosterGapToggle?.addEventListener('click', () => {
        const expanded = rosterGapToggle.getAttribute('aria-expanded') === 'true';
        rosterGapToggle.setAttribute('aria-expanded', String(!expanded));
        if (rosterGapBody) rosterGapBody.hidden = expanded;
    });

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

    const renderRoster = (evaluation) => {
        if (!rosterDays) return;
        lastEvaluation = evaluation;

        // Redrawing the board changes its height; holding the scroll position
        // keeps whatever the reviewer was reading in place.
        const scroller = bulkForm.querySelector('.bulk-schedule-form');
        const previousScroll = scroller?.scrollTop ?? 0;

        renderRosterCalendar(evaluation);
        renderCoverageGaps(evaluation);
        renderNightStreakWarnings(evaluation);
        if (scroller) scroller.scrollTop = previousScroll;

        if (rosterSummary) {
            const { assignments, day_offs: rest, blocked, shifts_short: short, night_streak_warnings: streaks } = evaluation.summary;
            const parts = [`${assignments} assignment(s)`, `${rest} rest day(s)`];
            if (blocked) parts.push(`${blocked} cannot be scheduled`);
            parts.push(short ? `${short} shift(s) below the required cover` : 'every shift meets its requirement');
            if (streaks) parts.push(`${streaks} beyond the consecutive-night limit`);
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

    // Both fill buttons only propose: they replace what is on the board, and
    // nothing reaches the database until the roster is published.
    const fillRosterFrom = async (button, url, busyLabel, failure) => {
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

    rosterClearButton?.addEventListener('click', () => {
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
            'senior_rank_threshold', 'holiday_dates_csv', 'overtime_justification', 'night_streak_justification']
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

    // What Step 5's attestation is actually agreeing to, computed from the
    // same evaluation the Step 3 board already rendered — no separate call.
    const renderPublishSummary = () => {
        if (!publishSummaryGrid) return;
        publishSummaryGrid.replaceChildren();
        if (!lastEvaluation) return;

        const {
            assignments, day_offs: restDays, employees_affected: employees, night_differential_hours: nightHours,
            shifts_short: short, night_streak_warnings: streaks,
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
            } else if (streaks) {
                publishSummaryGap.hidden = false;
                const justification = bulkForm.elements.night_streak_justification?.value.trim() || '—';
                publishSummaryGap.textContent = `${streaks} night shift(s) beyond the consecutive-night limit. Justification on record: "${justification}"`;
            } else {
                publishSummaryGap.hidden = true;
            }
        }
    };

    const showStep = (step) => {
        currentStep = step;
        stepPanels.forEach((panel) => { panel.hidden = Number(panel.dataset.stepPanel) !== step; });
        stepItems.forEach((item) => {
            const stepNumber = Number(item.dataset.stepItem);
            item.classList.toggle('active', stepNumber === step);
            item.classList.toggle('done', stepNumber < step);
        });
        if (stepBackButton) stepBackButton.hidden = step === 1;
        if (stepNextButton) stepNextButton.hidden = step === 5;
        if (bulkSaveButton) bulkSaveButton.hidden = step !== 5;
        setStepError(null);
        const scroller = bulkForm.querySelector('.bulk-schedule-form');
        if (scroller) scroller.scrollTop = 0;
        // Entering the draft step should reflect whatever was just
        // configured, not a stale board from an earlier pass.
        if (step === 3) scheduleRosterEvaluate();
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

        return null;
    };

    const stepValidators = { 1: validateStep1, 2: validateStep2, 3: validateStep3 };

    // Keeps Next disabled — with the blocking reason as its title/tooltip —
    // for as long as the current step's validator fails, instead of only
    // rejecting the attempt after it's clicked.
    const refreshStepGate = () => {
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
        if (publishSummaryGrid) publishSummaryGrid.replaceChildren();
        if (publishSummaryGap) publishSummaryGap.hidden = true;
        lastEvaluation = null;
        syncOvertimeJustification();
        syncScheduleMethod();
        syncBulkPeriod();
        syncBulkPositionOptions();
        syncBulkPositionAvailability();
        syncEmployeeScope();
        syncClinicalOnlyFields();
        updateBulkSelectedCount();
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
