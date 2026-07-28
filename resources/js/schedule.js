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
    const bulkReviewButton = bulkForm?.querySelector('[data-bulk-review-button]');
    const bulkSelectedCount = bulkForm?.querySelector('[data-bulk-selected-count]');
    const rotationPreview = bulkForm?.querySelector('[data-rotation-preview]');
    const bulkEmployeeOptions = [...(bulkForm?.querySelectorAll('[data-bulk-employee-list] .bulk-employee-option') ?? [])];
    const isRotationSchedule = () => bulkForm?.elements.schedule_method?.value === 'rotation';

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
        if (rotationPreview) {
            rotationPreview.hidden = true;
            rotationPreview.replaceChildren();
        }
        updateBulkReview(
            'Review before saving',
            isRotationSchedule()
                ? 'Select employees and at least two rotation shifts, then generate the balanced schedule.'
                : 'Select employees, a shift, and dates, then review availability.',
        );
    };

    const syncScheduleMethod = () => {
        if (!bulkForm || !bulkForm.elements.schedule_method) return;
        const rotation = isRotationSchedule();
        const fixedField = bulkForm.querySelector('[data-fixed-shift]');
        const rotationField = bulkForm.querySelector('[data-rotation-shifts]');
        const weekendField = bulkForm.querySelector('.bulk-weekend-toggle');
        fixedField.hidden = rotation;
        fixedField.querySelector('select').disabled = rotation;
        fixedField.querySelector('select').required = !rotation;
        rotationField.hidden = !rotation;
        rotationField.querySelectorAll('input').forEach((input) => { input.disabled = !rotation; });
        weekendField.hidden = rotation;
        bulkForm.elements.include_weekends.checked = rotation;
        bulkForm.action = rotation ? bulkForm.dataset.rotationStoreUrl : bulkForm.dataset.storeUrl;
        bulkReviewButton.textContent = rotation ? 'Generate AI rotation' : 'Review assignments';
        bulkSaveButton.textContent = rotation ? 'Save generated schedule' : 'Save valid assignments';
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

    const renderRotationPreview = (result) => {
        if (!rotationPreview) return;
        rotationPreview.replaceChildren();
        const heading = document.createElement('div');
        heading.className = 'rotation-preview-heading';
        const title = document.createElement('strong');
        title.textContent = 'Generated weekly rotation';
        const caption = document.createElement('span');
        caption.textContent = `${result.assignment_count} shifts · ${result.day_off_count} new day offs · ${result.skipped_count} skipped`;
        heading.append(title, caption);
        rotationPreview.append(heading);

        const rows = document.createElement('div');
        rows.className = 'rotation-preview-rows';
        result.rows.forEach((row) => {
            const employee = document.createElement('article');
            employee.className = 'rotation-preview-employee';
            const identity = document.createElement('div');
            identity.className = 'rotation-preview-identity';
            const name = document.createElement('strong');
            name.textContent = row.employee;
            const number = document.createElement('small');
            number.textContent = row.employee_number;
            identity.append(name, number);
            employee.append(identity);

            const weeks = document.createElement('div');
            weeks.className = 'rotation-preview-weeks';
            row.weeks.forEach((week) => {
                const card = document.createElement('div');
                card.className = 'rotation-preview-week';
                card.style.setProperty('--rotation-color', week.color);
                const period = document.createElement('small');
                period.textContent = `${formatScheduleDate(week.start_date)} – ${formatScheduleDate(week.end_date)}`;
                const shift = document.createElement('strong');
                shift.textContent = `${week.shift} · ${week.shift_time}`;
                const dayOff = document.createElement('span');
                dayOff.textContent = week.day_off ? `Day off: ${formatScheduleDate(week.day_off)}` : 'No safe day off available';
                const counts = document.createElement('small');
                counts.textContent = `${week.assignments} assignments${week.skipped ? ` · ${week.skipped} skipped` : ''}`;
                card.append(period, shift, dayOff, counts);
                weeks.append(card);
            });
            employee.append(weeks);
            rows.append(employee);
        });
        rotationPreview.append(rows);
        rotationPreview.hidden = false;
    };

    const reviewBulkAssignments = async () => {
        if (!bulkForm) return;
        const employeeIds = selectedBulkEmployees();
        const rotation = isRotationSchedule();
        const shiftId = bulkForm.elements.shift_id?.value;
        const shiftIds = [...bulkForm.querySelectorAll('input[name="shift_ids[]"]:checked')].map((input) => Number(input.value));
        const startDate = bulkForm.elements.start_date.value;
        const endDate = bulkForm.elements.end_date.value;
        if (!employeeIds.length || (!rotation && !shiftId) || (rotation && shiftIds.length < 2) || !startDate || !endDate) {
            updateBulkReview(
                'Review unavailable',
                rotation
                    ? 'Select at least one employee and at least two shifts for the rotation.'
                    : 'Select at least one employee, a shift, and a date range first.',
                'no-ready',
            );
            return;
        }

        bulkReviewButton.disabled = true;
        updateBulkReview('Checking availability', 'Reviewing schedules and approved leave…');
        try {
            const body = {
                department_id: Number(bulkForm.elements.department_id.value),
                employee_ids: employeeIds,
                schedule_period: bulkForm.elements.schedule_period.value,
                period_start: bulkForm.elements.period_start.value,
                period_month: bulkForm.elements.period_month.value,
                start_date: startDate,
                end_date: endDate,
            };
            if (rotation) body.shift_ids = shiftIds;
            else {
                body.shift_id = Number(shiftId);
                body.include_weekends = bulkForm.elements.include_weekends.checked;
            }
            const response = await fetch(rotation ? bulkForm.dataset.rotationPreviewUrl : bulkForm.dataset.previewUrl, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify(body),
            });
            const payload = await response.json();
            if (!response.ok) {
                const message = Object.values(payload.errors ?? {}).flat()[0] ?? 'Unable to review assignments.';
                throw new Error(message);
            }
            const result = rotation ? payload.data : payload;

            if (rotation) {
                renderRotationPreview(result);
                const summary = `${result.assignment_count} work assignments and ${result.day_off_count} new day offs are ready. ${result.skipped_count} conflicts will be skipped.`;
                updateBulkReview(
                    result.assignment_count ? 'AI rotation ready for HR review' : 'No assignments can be created',
                    summary,
                    result.assignment_count === 0 ? 'no-ready' : (result.skipped_count ? 'has-skips' : 'idle'),
                    result.skipped,
                );
                bulkSaveButton.disabled = result.assignment_count === 0;
                return;
            }

            const summary = result.skipped_count
                ? `${result.ready_count} ready to save; ${result.skipped_count} will be skipped. Existing schedules will not be changed.`
                : `${result.ready_count} assignments are ready to save. No conflicts or approved leave found.`;
            const state = result.ready_count === 0 ? 'no-ready' : (result.skipped_count ? 'has-skips' : 'idle');
            updateBulkReview(
                result.ready_count === 0 ? 'No assignments can be created' : 'Review complete',
                summary,
                state,
                result.skipped,
            );
            bulkSaveButton.disabled = result.ready_count === 0;
        } catch (error) {
            updateBulkReview('Review unavailable', error.message, 'no-ready');
        } finally {
            bulkReviewButton.disabled = false;
        }
    };

    bulkForm?.querySelectorAll('select[name="shift_id"], input[name="start_date"], input[name="end_date"], input[name="include_weekends"], input[name="employee_ids[]"]').forEach((field) => {
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
        });
    });
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
    bulkReviewButton?.addEventListener('click', reviewBulkAssignments);
    bulkModalElement?.addEventListener('hidden.bs.modal', () => {
        bulkForm.reset();
        syncScheduleMethod();
        syncBulkPeriod();
        filterBulkEmployees();
        updateBulkSelectedCount();
        invalidateBulkReview();
    });
    syncScheduleMethod();
    syncBulkPeriod();
    filterBulkEmployees();
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
        shiftForm.querySelector('[data-shift-code-preview]').textContent = shift
            ? shift.code
            : 'Generated automatically after saving';

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
