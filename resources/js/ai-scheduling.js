const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

const element = (tag, className, text) => {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined) node.textContent = text;
    return node;
};

document.addEventListener('DOMContentLoaded', () => {
    const component = document.querySelector('[data-ai-scheduling]');
    const form = document.querySelector('#scheduleAssignmentForm');
    if (!component || !form) return;

    // The assistant reads the same department and position the manual fields
    // on the left use, rather than keeping a second pair of pickers that can
    // disagree with them.
    const department = form.querySelector('[data-assignment-department-filter]');
    const position = form.querySelector('[data-assignment-position-filter]');
    const context = component.querySelector('[data-ai-context]');
    const generateButton = component.querySelector('[data-ai-generate]');
    const status = component.querySelector('[data-ai-status]');
    const results = component.querySelector('[data-ai-results]');
    const criteria = component.querySelector('[data-ai-criteria]');
    const candidateList = component.querySelector('[data-ai-candidates]');
    const resultsTitle = component.querySelector('[data-ai-results-title]');
    const weeklyLimit = Number(component.dataset.weeklyLimit) || 48;
    let currentResult = null;
    let selectedCandidateId = null;
    let appliedCandidateId = null;
    let requestSequence = 0;
    let activeRequest = null;

    const initials = (name) => name.split(/\s+/).filter(Boolean).map((word) => word[0]).slice(0, 2).join('').toUpperCase();
    const plural = (count, one, many) => `${count} ${count === 1 ? one : many}`;
    const hours = (minutes) => Math.round((Number(minutes) || 0) / 6) / 10;
    const shiftHours = () => Number(form.elements.shift_id.selectedOptions[0]?.dataset.hours) || 0;
    // A candidate's own position, read from the employee field's own options
    // rather than assumed — the two lists are the same people.
    const positionOf = (employeeId) => form.elements.employee_id
        .querySelector(`option[value="${CSS.escape(String(employeeId))}"]`)?.dataset.position ?? '';
    const workDateLabel = () => {
        const value = form.elements.work_date.value;
        if (!value) return 'this date';

        return new Intl.DateTimeFormat('en-PH', { weekday: 'short', month: 'short', day: 'numeric' }).format(new Date(`${value}T00:00:00`));
    };

    const showStatus = (message, tone = 'neutral') => {
        status.textContent = message;
        status.dataset.tone = tone;
        status.hidden = false;
    };

    const setLoading = (loading) => {
        generateButton.disabled = loading;
        generateButton.classList.toggle('loading', loading);
        generateButton.textContent = loading ? 'Checking staff…' : (currentResult ? 'Recommend again' : 'Generate AI Recommendation');
        if (loading) showStatus('Analyzing eligible employees and workload records…');
    };

    // The criteria list is what stands in for a result, so it shows whenever
    // there is none to read.
    const showCriteria = (show) => {
        if (criteria) criteria.hidden = !show;
    };

    const isEditMode = () => form.querySelector('[data-method-field]')?.value === 'PUT';

    const optionText = (select, fallback) => (select?.value ? select.selectedOptions[0]?.textContent.trim() : '') || fallback;
    const contextKey = () => [department?.value, position?.value, form.elements.shift_id.value, form.elements.work_date.value].join('|');
    let resultKey = null;

    // "Recommending for …" mirrors the left-hand fields, so what the assistant
    // is about to be asked is always in view.
    const syncContext = () => {
        if (context) {
            const shift = form.elements.shift_id.value ? form.elements.shift_id.selectedOptions[0]?.textContent.trim() : 'No shift yet';
            // The same long date the candidate chips read, not the raw value
            // off the input: '2026-10-03' is the one thing on this line nobody
            // is reading the line to find out.
            const date = form.elements.work_date.value ? workDateLabel() : 'no date';
            context.textContent = [shift, date, optionText(department, 'All departments'), optionText(position, 'All positions')].join(' · ');
        }
        if (currentResult && !results.hidden && resultKey !== contextKey()) {
            results.hidden = true;
            showCriteria(true);
            showStatus('The shift, date, department, or position changed. Recommend again for up-to-date candidates.', 'warning');
        }
    };

    /**
     * One ranked candidate: where they place, who they are, what the assistant
     * checked, the paid hours this shift would add, and the button that puts
     * them in the employee field.
     */
    const candidateCard = (candidate, rank) => {
        const chosen = Number(candidate.employee_id) === appliedCandidateId;
        const card = element('article', `ai-candidate${rank === 1 ? ' ai-candidate-primary' : ''}${chosen ? ' is-chosen' : ''}`);

        const heading = element('div', 'ai-candidate-heading');
        heading.append(element('span', 'ai-candidate-rank', String(rank)), element('span', 'ai-candidate-avatar', initials(candidate.name)));
        const identity = element('div');
        identity.append(element('strong', null, candidate.name), element('span', null, [positionOf(candidate.employee_id), candidate.employee_number].filter(Boolean).join(' · ')));
        heading.append(identity, element('b', null, `${Number(candidate.score).toFixed(1)} / 100`));
        const use = element('button', 'btn btn-sm btn-outline-primary ai-candidate-use', chosen ? 'Selected' : 'Use this employee');
        use.type = 'button';
        use.disabled = chosen;
        use.dataset.aiUseCandidate = String(candidate.employee_id);
        use.setAttribute('aria-label', `Use ${candidate.name} for this shift`);
        heading.append(use);
        card.append(heading);

        // Every listed candidate cleared the hard constraints, so these say
        // which ones — the ranking below is what separates them.
        const chips = element('div', 'ai-chip-row');
        const rest = Number(candidate.metrics?.rest_hours);
        [
            `Free on ${workDateLabel()}`,
            'No approved leave or day off',
            // 72 is the cap the scoring uses for "no shift anywhere near this one".
            Number.isFinite(rest) ? (rest >= 72 ? 'No shift within 72 h either side' : `${rest} h rest around this shift`) : null,
        ].filter(Boolean).forEach((text) => {
            const chip = element('span', 'ai-chip ai-chip-ok');
            chip.append(element('span', 'ai-chip-tick', '✓'), element('span', null, text));
            chips.append(chip);
        });
        // Risk chips only where there is a risk. Every candidate on this list
        // already cleared the hard constraints, so "low" and "not assessed"
        // appeared on all three cards at once and said nothing about any of
        // them -- three rows of status colour marking the absence of status.
        // A moderate or high reading is a real reason to pause, and it stands
        // out now that it is the only one of its kind on the card.
        const notable = (level) => level === 'moderate' || level === 'high';
        const risk = candidate.workload_risk;
        if (risk && notable(risk.level)) {
            chips.append(element('span', `ai-risk ai-risk-${risk.level}`, `${risk.label}: ${risk.level}`));
        }
        const burnout = candidate.burnout_risk;
        if (burnout && notable(burnout.level)) {
            const burnoutChip = element('span', `ai-risk ai-risk-${burnout.level}`, `Burnout risk: ${burnout.level}`);
            if (burnout.drivers?.length) burnoutChip.title = burnout.drivers.join('\n');
            chips.append(burnoutChip);
        }
        card.append(chips);

        if (candidate.burnout_protected) {
            card.append(element('p', 'ai-burnout-note', 'High burnout risk: ranked after the other eligible staff. Choose only if nobody else can cover.'));
        }

        // Hours already on this week, and what this shift would add to them.
        const now = hours(candidate.metrics?.weekly_workload_minutes);
        const add = shiftHours();
        const load = element('div', 'ai-candidate-load');
        const bar = element('span', 'ai-load-bar');
        const filled = element('span', 'ai-load-now');
        filled.style.width = `${Math.min(100, (now / weeklyLimit) * 100)}%`;
        const added = element('span', 'ai-load-add');
        added.style.width = `${Math.min(100 - Math.min(100, (now / weeklyLimit) * 100), (add / weeklyLimit) * 100)}%`;
        bar.append(filled, added);
        load.append(bar, element('span', 'ai-load-text', `${now} → ${Math.round((now + add) * 10) / 10} of ${weeklyLimit} h`));
        card.append(load);

        return card;
    };

    const renderBreakdown = (candidate) => {
        const list = component.querySelector('[data-ai-breakdown-list]');
        list.replaceChildren();
        Object.values(candidate.score_breakdown).forEach((factor) => {
            const row = element('div', 'ai-breakdown-row');
            row.append(element('span', null, factor.label), element('strong', null, `${Number(factor.points).toFixed(1)} / ${Number(factor.maximum).toFixed(1)}`));
            list.append(row);
        });
    };

    const renderResult = (data) => {
        currentResult = data;
        appliedCandidateId = null;
        const ranked = [data.recommended, ...data.alternatives].filter(Boolean);
        selectedCandidateId = ranked[0] ? Number(ranked[0].employee_id) : null;
        results.hidden = false;
        showCriteria(false);
        component.querySelector('[data-ai-explanation]').textContent = data.explanation || '';
        const ineligible = component.querySelector('[data-ai-ineligible-list]');
        component.querySelector('[data-ai-breakdown-list]').replaceChildren();
        candidateList.replaceChildren();
        ineligible.replaceChildren();

        if (!ranked.length) {
            resultsTitle.textContent = 'Nobody in this department and position is available';
            candidateList.append(element('p', 'ai-scheduling-empty', 'No eligible employee was found for the selected requirements. The employees left out, and why, are listed below.'));
        } else {
            resultsTitle.textContent = `Top ${ranked.length} of ${plural(data.eligible.length, 'available employee', 'available employees')}`;
            ranked.forEach((candidate, index) => candidateList.append(candidateCard(candidate, index + 1)));
            renderBreakdown(ranked[0]);
        }

        data.ineligible.forEach((candidate) => {
            const row = element('div', 'ai-ineligible-row');
            row.append(element('strong', null, `${candidate.name} · ${candidate.employee_number}`));
            row.append(element('span', null, candidate.reasons.map((reason) => reason.message).join(' ')));
            ineligible.append(row);
        });
        const ineligibleTitle = component.querySelector('[data-ai-ineligible-title]');
        if (ineligibleTitle) ineligibleTitle.textContent = `${plural(data.ineligible.length, 'employee', 'employees')} left out`;
        component.querySelector('[data-ai-ineligible]').hidden = data.ineligible.length === 0;
        component.querySelector('[data-ai-breakdown]').hidden = ranked.length === 0;
        // Deliberately not announced. The notice repeats the Advisory only
        // pill, the explainer above and the footer note, and a success
        // message whose content is "here are the results" sits directly on
        // top of the results. Warnings and failures still speak.
        status.hidden = true;
    };

    const responseError = (payload, fallback) => payload.message
        || Object.values(payload.errors || {})[0]?.[0]
        || fallback;

    const postJson = async (url, body) => {
        const response = await fetch(url, {
            method: 'POST',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken() },
            body: JSON.stringify(body),
        });
        const payload = await response.json();
        if (!response.ok) throw new Error(responseError(payload, 'The AI recommendation action could not be completed.'));

        return payload.data;
    };

    const applySelected = async (button = null) => {
        if (!currentResult || !selectedCandidateId) return;
        if (button) button.disabled = true;
        showStatus('Revalidating the selected employee against current scheduling records…');

        try {
            const data = await postJson(currentResult.apply_url, {
                employee_id: selectedCandidateId,
                department_id: Number(department.value),
                position_id: Number(position.value),
                shift_id: Number(form.elements.shift_id.value),
                work_date: form.elements.work_date.value,
            });
            // Marked before the change fires so the form keeps the "From AI
            // recommendation" badge instead of treating this as a hand pick.
            form.elements.employee_id.dataset.aiApplied = '1';
            form.elements.employee_id.value = String(data.employee_id);
            form.elements.employee_id.dispatchEvent(new Event('change', { bubbles: true }));
            form.dispatchEvent(new CustomEvent('assignment:from-ai', { bubbles: true }));
            if (form.elements.recommendation_id) form.elements.recommendation_id.value = data.recommendation_id;
            appliedCandidateId = Number(data.employee_id);
            component.querySelectorAll('[data-ai-use-candidate]').forEach((node) => {
                const chosen = Number(node.dataset.aiUseCandidate) === appliedCandidateId;
                node.disabled = chosen;
                node.textContent = chosen ? 'Selected' : 'Use this employee';
                node.closest('.ai-candidate')?.classList.toggle('is-chosen', chosen);
            });
            showStatus(`${data.message} Review the form, then use Save assignment when ready.`, 'success');
        } catch (error) {
            showStatus(error.message, 'danger');
            if (button) button.disabled = false;
        }
    };

    const generate = async () => {
        if (isEditMode()) {
            showStatus('AI recommendations are protected in edit mode. Continue editing the assignment manually.', 'warning');
            return;
        }

        const shiftId = form.elements.shift_id.value;
        const workDate = form.elements.work_date.value;
        if (!department?.value || !position?.value || !shiftId || !workDate) {
            showStatus('Choose a department, a position, a shift, and a work date on the left first.', 'warning');
            return;
        }

        activeRequest?.abort();
        activeRequest = new AbortController();
        const sequence = ++requestSequence;
        setLoading(true);

        try {
            const response = await fetch(component.dataset.generateUrl, {
                method: 'POST',
                signal: activeRequest.signal,
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken() },
                body: JSON.stringify({
                    department_id: Number(department.value),
                    position_id: Number(position.value),
                    shift_id: Number(shiftId),
                    work_date: workDate,
                }),
            });
            if (sequence !== requestSequence) return;
            const payload = await response.json();
            if (!response.ok) throw new Error(responseError(payload, 'AI recommendation is currently unavailable. You may continue scheduling manually.'));
            resultKey = contextKey();
            renderResult(payload.data);
        } catch (error) {
            if (error.name !== 'AbortError' && sequence === requestSequence) {
                showStatus(error.message || 'AI recommendation is currently unavailable. You may continue scheduling manually.', 'danger');
            }
        } finally {
            if (sequence === requestSequence) setLoading(false);
        }
    };

    form.addEventListener('change', (event) => {
        if (event.target.matches('[data-assignment-department-filter], [data-assignment-position-filter], select[name="shift_id"], input[name="work_date"]')) syncContext();
    });
    document.querySelector('#scheduleAssignmentModal')?.addEventListener('shown.bs.modal', syncContext);
    syncContext();
    generateButton.addEventListener('click', generate);

    // "Use this employee" is the apply step: the choice is revalidated against
    // current records before it reaches the form, whichever card it came from.
    component.addEventListener('click', (event) => {
        const useButton = event.target.closest('[data-ai-use-candidate]');
        if (!useButton || !currentResult) return;
        selectedCandidateId = Number(useButton.dataset.aiUseCandidate);
        applySelected(useButton);
    });
});
