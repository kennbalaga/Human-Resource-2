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
        detailModalElement.querySelector('[data-delete-assignment-form]').action = replaceRouteId(assignmentForm.dataset.updateUrlTemplate, assignment.id);
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
        rotationField.querySelectorAll('input').forEach((input) => { input.disabled = !aiSchedule; });
        weekendField.hidden = aiSchedule;
        bulkForm.elements.include_weekends.checked = aiSchedule;
        const help = bulkForm.querySelector('[data-shift-pool-help]');
        if (help) help.textContent = method === 'custom'
            ? 'Select at least two shifts. The assistant creates a stable custom mix across employees while balancing coverage.'
            : 'Select at least two shifts. The assistant balances coverage and rotates employees weekly.';
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
        const positionId = bulkForm.querySelector('[data-bulk-position-filter]').value;
        const search = bulkForm.querySelector('[data-bulk-employee-search]').value.trim().toLowerCase();

        bulkEmployeeOptions.forEach((option) => {
            const matches = Boolean(departmentId) && option.dataset.departmentId === departmentId
                && (!positionId || option.dataset.positionId === positionId)
                && (!search || option.dataset.search.includes(search));
            option.hidden = !matches;
        });
        const empty = bulkForm.querySelector('[data-bulk-employee-empty]');
        const hasVisibleEmployees = bulkEmployeeOptions.some((option) => !option.hidden);
        empty.hidden = hasVisibleEmployees;
        empty.textContent = departmentId
            ? 'No active employees match the selected filters.'
            : 'Select a department to load active employees.';
    };

    const syncEmployeeScope = () => {
        if (!bulkForm) return;
        const scope = bulkForm.elements.employee_scope?.value ?? 'specific';
        const allStaff = scope === 'all';
        const departmentId = bulkForm.elements.department_id.value;
        const positionFilter = bulkForm.querySelector('[data-bulk-position-filter]');
        const search = bulkForm.querySelector('[data-bulk-employee-search]');
        const selectAll = bulkForm.querySelector('[data-bulk-select-all]');
        positionFilter.disabled = allStaff;
        search.disabled = allStaff;
        selectAll.hidden = allStaff;

        if (allStaff) {
            positionFilter.value = '';
            search.value = '';
            bulkEmployeeOptions.forEach((option) => {
                option.querySelector('input').checked = Boolean(departmentId) && option.dataset.departmentId === departmentId;
            });
        }

        filterBulkEmployees();
        updateBulkSelectedCount();
        invalidateBulkReview();
    };




    bulkForm?.querySelectorAll('select[name="shift_id"], input[name="start_date"], input[name="end_date"], input[name="include_weekends"], input[name="employee_ids[]"], select[name="days_off_per_week"], input[name="max_hours_per_week"], input[name="night_shift_limit"], input[name="minimum_staff_per_shift"], input[name="overtime_allowed"], input[name="holiday_dates_csv"]').forEach((field) => {
        field.addEventListener('change', () => {
            updateBulkSelectedCount();
            invalidateBulkReview();
        });
    });
    bulkForm?.querySelectorAll('select[name="schedule_method"], input[name="shift_ids[]"]').forEach((field) => {
        field.addEventListener('change', () => {
            if (field.name === 'schedule_method') syncScheduleMethod();
            else invalidateBulkReview();
        });
    });
    bulkForm?.querySelectorAll('[data-schedule-period], input[name="period_start"], input[name="period_month"]').forEach((field) => {
        field.addEventListener('change', () => {
            syncBulkPeriod();
            invalidateBulkReview();
        });
    });
    bulkForm?.querySelectorAll('[data-bulk-department-filter], [data-bulk-position-filter], [data-bulk-employee-search]').forEach((field) => {
        field.addEventListener(field.type === 'search' ? 'input' : 'change', () => {
            if (field.matches('[data-bulk-department-filter]')) {
                bulkEmployeeOptions.forEach((option) => {
                    if (option.dataset.departmentId !== field.value) option.querySelector('input').checked = false;
                });
                updateBulkSelectedCount();
                invalidateBulkReview();
            }
            filterBulkEmployees();
            if (field.matches('[data-bulk-department-filter]') && bulkForm.elements.employee_scope?.value === 'all') {
                syncEmployeeScope();
            }
        });
    });
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
    let rosterEntries = [];
    let rosterEvaluateTimer = null;

    const rosterKey = (entry) => `${entry.employee_id}|${entry.work_date}`;

    const selectedEmployees = () => bulkEmployeeOptions
        .filter((option) => option.querySelector('input').checked)
        .map((option) => ({
            id: Number(option.querySelector('input').value),
            name: option.querySelector('strong').textContent,
        }));

    const rosterRangePayload = () => ({
        department_id: bulkForm.elements.department_id?.value,
        start_date: bulkForm.elements.start_date?.value,
        end_date: bulkForm.elements.end_date?.value,
    });

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

    const renderRosterDay = (day) => {
        const article = document.createElement('article');
        article.className = `roster-day${day.fully_covered ? '' : ' is-short'}`;

        const heading = document.createElement('header');
        const dayTitle = document.createElement('strong');
        dayTitle.textContent = `${formatScheduleDate(day.date)} · ${day.weekday}`;
        heading.append(dayTitle);
        article.append(heading);

        day.shifts.forEach((shift) => {
            const block = document.createElement('section');
            block.className = `roster-shift${shift.meets_requirement ? '' : ' is-short'}`;

            const shiftHeading = document.createElement('header');
            const shiftName = document.createElement('strong');
            shiftName.textContent = shift.shift;
            const shiftTime = document.createElement('small');
            shiftTime.textContent = shift.time ?? '';
            const coverage = document.createElement('span');
            coverage.className = 'roster-coverage';
            const seniorNote = shift.senior_required
                ? ` · ${shift.senior_count}/${shift.senior_required} senior`
                : '';
            coverage.textContent = `${shift.count}/${shift.required} staff${seniorNote}`;
            coverage.title = `Required by the ${shift.requirement_source}.`;
            shiftHeading.append(shiftName, shiftTime, coverage);
            block.append(shiftHeading);

            const list = document.createElement('ul');
            list.className = 'roster-people';
            shift.assigned.forEach((person) => {
                const item = document.createElement('li');
                if (person.blocked) item.classList.add('is-blocked');
                const label = document.createElement('span');
                label.textContent = person.name;
                if (person.is_senior) {
                    const badge = document.createElement('em');
                    badge.textContent = 'senior';
                    label.append(' ', badge);
                }
                const number = document.createElement('small');
                number.textContent = person.employee_number ?? '';
                const remove = document.createElement('button');
                remove.type = 'button';
                remove.className = 'roster-remove';
                remove.textContent = 'Remove';
                remove.addEventListener('click', () => removeRosterEntry(person.employee_id, day.date));
                item.append(label, number, remove);
                list.append(item);
            });
            block.append(list);

            const picker = document.createElement('select');
            picker.className = 'roster-add';
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
            picker.addEventListener('change', () => {
                if (!picker.value) return;
                addRosterEntry(Number(picker.value), shift.shift_id, day.date);
            });
            block.append(picker);

            article.append(block);
        });

        if (day.day_offs.length) {
            const rest = document.createElement('p');
            rest.className = 'roster-rest';
            rest.textContent = `Rest day: ${day.day_offs.map((person) => person.name).join(', ')}`;
            article.append(rest);
        }

        return article;
    };

    const renderRoster = (evaluation) => {
        if (!rosterDays) return;
        rosterDays.replaceChildren();
        evaluation.days.forEach((day) => rosterDays.append(renderRosterDay(day)));

        if (rosterSummary) {
            const { assignments, day_offs: rest, blocked, shifts_short: short } = evaluation.summary;
            const parts = [`${assignments} assignment(s)`, `${rest} rest day(s)`];
            if (blocked) parts.push(`${blocked} cannot be scheduled`);
            parts.push(short ? `${short} shift(s) below the required cover` : 'every shift meets its requirement');
            rosterSummary.textContent = `${parts.join(' · ')}.${evaluation.coverage_standard ? ` ${evaluation.coverage_standard}` : ''}`;
        }

        if (bulkApprovalWrap) bulkApprovalWrap.hidden = evaluation.summary.assignments === 0;
        rosterBoard.hidden = false;
    };

    const evaluateRoster = async () => {
        const range = rosterRangePayload();
        if (!range.department_id || !range.start_date || !range.end_date) return;

        try {
            const response = await fetch(bulkForm.dataset.rosterEvaluateUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken() },
                body: JSON.stringify({ ...range, entries: rosterEntries }),
            });
            const payload = await response.json();
            if (!response.ok) throw new Error(Object.values(payload.errors ?? {}).flat()[0] ?? 'Unable to check the roster.');
            renderRoster(payload.data);
        } catch (error) {
            if (rosterSummary) rosterSummary.textContent = error.message;
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

    rosterClearButton?.addEventListener('click', () => setRosterEntries([]));

    // The board appears as soon as there is a unit and a date range to roster.
    bulkForm?.querySelectorAll('select[name="department_id"], input[name="start_date"], input[name="end_date"], input[name="period_start"], input[name="period_month"], [data-schedule-period]').forEach((field) => {
        field.addEventListener('change', () => window.setTimeout(scheduleRosterEvaluate, 0));
    });

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
    bulkModalElement?.addEventListener('shown.bs.modal', () => {
        const modalBody = bulkForm.querySelector('.bulk-schedule-form');
        const employeePicker = bulkForm.querySelector('.bulk-employee-picker');
        const scheduleDetails = bulkForm.querySelector('.bulk-schedule-details');
        if (modalBody && employeePicker && scheduleDetails) {
            modalBody.insertBefore(employeePicker, scheduleDetails);
            employeePicker.hidden = false;
            employeePicker.style.removeProperty('display');
        }
        if (modalBody) modalBody.scrollTop = 0;
        bulkForm.elements.department_id?.focus({ preventScroll: true });
    });
    bulkModalElement?.addEventListener('hidden.bs.modal', () => {
        bulkForm.reset();
        bulkForm.action = bulkForm.dataset.rosterPublishUrl;
        bulkForm.querySelectorAll('[data-roster-entry-input]').forEach((input) => input.remove());
        rosterEntries = [];
        if (rosterDays) rosterDays.replaceChildren();
        if (rosterBoard) rosterBoard.hidden = true;
        syncScheduleMethod();
        syncBulkPeriod();
        filterBulkEmployees();
        syncEmployeeScope();
        updateBulkSelectedCount();
        invalidateBulkReview();
    });
    syncScheduleMethod();
    syncBulkPeriod();
    filterBulkEmployees();
    syncEmployeeScope();
    updateBulkSelectedCount();

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
    };

    document.querySelector('[data-new-shift]')?.addEventListener('click', () => setShiftMode());
    document.querySelectorAll('[data-edit-shift]').forEach((button) => {
        button.addEventListener('click', () => setShiftMode(JSON.parse(button.dataset.shift)));
    });

    shiftModalElement?.addEventListener('hidden.bs.modal', () => setShiftMode());
});
