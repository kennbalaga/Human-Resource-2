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

            if (conflicts.length) {
                const conflict = conflicts[0];
                updateConflictStatus('conflict', `Conflict: ${conflict.shift} on ${formatScheduleDate(conflict.date)} (${conflict.time}).`);
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

        if (!shift) return;
        shiftForm.elements.code.value = shift.code;
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
