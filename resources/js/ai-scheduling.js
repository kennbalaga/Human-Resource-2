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

    const department = component.querySelector('[data-ai-department]');
    const position = component.querySelector('[data-ai-position]');
    const generateButton = component.querySelector('[data-ai-generate]');
    const status = component.querySelector('[data-ai-status]');
    const results = component.querySelector('[data-ai-results]');
    const applyButton = component.querySelector('[data-ai-apply]');
    let currentResult = null;
    let selectedCandidateId = null;
    let requestSequence = 0;
    let activeRequest = null;

    const showStatus = (message, tone = 'neutral') => {
        status.textContent = message;
        status.dataset.tone = tone;
        status.hidden = false;
    };

    const setLoading = (loading) => {
        generateButton.disabled = loading;
        generateButton.classList.toggle('loading', loading);
        if (loading) showStatus('Analyzing eligible employees and workload records…');
    };

    const isEditMode = () => form.querySelector('[data-method-field]')?.value === 'PUT';

    const filterPositions = () => {
        const departmentId = department.value;
        position.value = '';
        position.disabled = !departmentId;
        [...position.options].forEach((option) => {
            if (!option.value) return;
            const visible = option.dataset.departmentId === departmentId;
            option.hidden = !visible;
            option.disabled = !visible;
        });
    };

    const candidateCard = (candidate, alternative = false) => {
        const card = element('article', alternative ? 'ai-candidate ai-candidate-alternative' : 'ai-candidate ai-candidate-primary');
        const heading = element('div', 'ai-candidate-heading');
        const identity = element('div');
        identity.append(element('strong', null, candidate.name), element('span', null, candidate.employee_number));
        const score = element('b', null, `${Number(candidate.score).toFixed(1)} / 100`);
        heading.append(identity, score);
        card.append(heading);
        const risk = element('span', `ai-risk ai-risk-${candidate.workload_risk.level}`, `${candidate.workload_risk.label}: ${candidate.workload_risk.level}`);
        card.append(risk);
        const reasons = element('ul');
        candidate.recommendation_reasons.forEach((reason) => reasons.append(element('li', null, reason)));
        card.append(reasons);

        if (alternative) {
            const choose = element('button', 'btn btn-sm btn-outline-primary', 'Select alternative');
            choose.type = 'button';
            choose.dataset.aiSelectCandidate = String(candidate.employee_id);
            card.append(choose);
        }

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
        selectedCandidateId = data.recommended ? Number(data.recommended.employee_id) : null;
        results.hidden = false;
        component.querySelector('[data-ai-rejection]').hidden = true;
        component.querySelector('[data-ai-rejection-reason]').value = '';
        component.querySelector('[data-ai-explanation]').textContent = data.explanation || '';
        const recommended = component.querySelector('[data-ai-recommended]');
        const alternatives = component.querySelector('[data-ai-alternatives]');
        const ineligible = component.querySelector('[data-ai-ineligible-list]');
        component.querySelector('[data-ai-breakdown-list]').replaceChildren();
        recommended.replaceChildren();
        alternatives.replaceChildren();
        ineligible.replaceChildren();

        if (!data.recommended) {
            recommended.append(element('p', 'ai-scheduling-empty', 'No eligible employee was found for the selected requirements.'));
            applyButton.disabled = true;
        } else {
            recommended.append(element('p', 'ai-result-label', 'Highest-ranked eligible employee'), candidateCard(data.recommended));
            renderBreakdown(data.recommended);
            applyButton.disabled = false;
            applyButton.textContent = 'Apply Recommendation';
        }

        if (data.alternatives.length) {
            alternatives.append(element('p', 'ai-result-label', 'Qualified alternatives'));
            data.alternatives.forEach((candidate) => alternatives.append(candidateCard(candidate, true)));
        }

        data.ineligible.forEach((candidate) => {
            const row = element('div', 'ai-ineligible-row');
            row.append(element('strong', null, `${candidate.name} · ${candidate.employee_number}`));
            row.append(element('span', null, candidate.reasons.map((reason) => reason.message).join(' ')));
            ineligible.append(row);
        });
        component.querySelector('[data-ai-ineligible]').hidden = data.ineligible.length === 0;
        showStatus(data.notice, 'success');
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

    const applySelected = async () => {
        if (!currentResult || !selectedCandidateId) return;
        applyButton.disabled = true;
        showStatus('Revalidating the selected employee against current scheduling records…');

        try {
            const data = await postJson(currentResult.apply_url, {
                employee_id: selectedCandidateId,
                department_id: Number(department.value),
                position_id: Number(position.value),
                shift_id: Number(form.elements.shift_id.value),
                work_date: form.elements.work_date.value,
            });
            form.elements.employee_id.value = String(data.employee_id);
            form.elements.employee_id.dispatchEvent(new Event('change', { bubbles: true }));
            if (form.elements.recommendation_id) form.elements.recommendation_id.value = data.recommendation_id;
            results.hidden = true;
            showStatus(`${data.message} Review the form, then use the existing Save assignment button when ready.`, 'success');
        } catch (error) {
            showStatus(error.message, 'danger');
            applyButton.disabled = false;
        }
    };

    const recordDecision = async (action, reason = null) => {
        if (!currentResult) return;
        try {
            await postJson(currentResult.decision_url, { action, reason });
            results.hidden = true;
            showStatus(action === 'rejected'
                ? 'Recommendation rejected and recorded. Your manual schedule form was preserved.'
                : 'Recommendation ignored and recorded. Your manual schedule form was preserved.', 'neutral');
        } catch (error) {
            showStatus(error.message, 'danger');
        }
    };

    const generate = async () => {
        if (isEditMode()) {
            showStatus('AI recommendations are protected in edit mode. Continue editing the assignment manually.', 'warning');
            return;
        }

        const shiftId = form.elements.shift_id.value;
        const workDate = form.elements.work_date.value;
        if (!department.value || !position.value || !shiftId || !workDate) {
            showStatus('Select a target department, required position, shift, and work date first.', 'warning');
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
            renderResult(payload.data);
        } catch (error) {
            if (error.name !== 'AbortError' && sequence === requestSequence) {
                showStatus(error.message || 'AI recommendation is currently unavailable. You may continue scheduling manually.', 'danger');
            }
        } finally {
            if (sequence === requestSequence) setLoading(false);
        }
    };

    department.addEventListener('change', filterPositions);
    generateButton.addEventListener('click', generate);
    component.querySelector('[data-ai-regenerate]').addEventListener('click', generate);
    component.querySelector('[data-ai-close]').addEventListener('click', () => { results.hidden = true; status.hidden = true; });
    applyButton.addEventListener('click', applySelected);
    component.querySelector('[data-ai-ignore]').addEventListener('click', () => recordDecision('ignored'));
    component.querySelector('[data-ai-reject]').addEventListener('click', () => {
        const rejection = component.querySelector('[data-ai-rejection]');
        rejection.hidden = !rejection.hidden;
        if (!rejection.hidden) component.querySelector('[data-ai-rejection-reason]').focus();
    });
    component.querySelector('[data-ai-confirm-reject]').addEventListener('click', () => {
        const reason = component.querySelector('[data-ai-rejection-reason]').value.trim();
        if (reason.length < 5) {
            showStatus('Enter a rejection reason with at least 5 characters.', 'warning');
            return;
        }
        recordDecision('rejected', reason);
    });
    component.addEventListener('click', (event) => {
        const candidateButton = event.target.closest('[data-ai-select-candidate]');
        if (!candidateButton || !currentResult) return;
        const candidateId = Number(candidateButton.dataset.aiSelectCandidate);
        const candidate = currentResult.alternatives.find((item) => Number(item.employee_id) === candidateId);
        if (candidate) {
            selectedCandidateId = candidateId;
            applyButton.disabled = false;
            applyButton.textContent = 'Apply Selected Alternative';
            showStatus(`${candidate.name} is selected for revalidation before application.`, 'neutral');
        }
    });
});
