@if ($aiSchedulingEnabled)
    <section
        class="ai-scheduling"
        data-ai-scheduling
        data-generate-url="{{ route('schedules.ai-recommendations.store') }}"
        aria-labelledby="aiSchedulingTitle"
    >
        <div class="ai-scheduling-heading">
            <span class="ai-scheduling-icon"><x-icon name="ai" /></span>
            <div>
                <p>AI Scheduling Assistant</p>
                <h3 id="aiSchedulingTitle">AI-Generated Recommendation</h3>
            </div>
            <span class="ai-scheduling-advisory">Advisory only</span>
        </div>

        <p class="ai-scheduling-notice">Uses available department, position, schedules, approved leave, attendance, overtime, and workload records. HR review is required.</p>

        <div class="ai-scheduling-controls">
            <label><span>Target department</span><select data-ai-department><option value="">Select department</option>@foreach($departments as $department)<option value="{{ $department->id }}">{{ $department->name }}</option>@endforeach</select></label>
            <label><span>Required position</span><select data-ai-position disabled><option value="">Select position</option>@foreach($aiPositions as $position)<option value="{{ $position->id }}" data-department-id="{{ $position->department_id }}" hidden disabled>{{ $position->title }}</option>@endforeach</select></label>
            <button class="btn btn-outline-primary" type="button" data-ai-generate><x-icon name="ai" /> Generate AI Recommendation</button>
        </div>

        <div class="ai-scheduling-status" data-ai-status role="status" aria-live="polite" hidden></div>
        <div class="ai-scheduling-results" data-ai-results hidden>
            <p class="ai-scheduling-explanation" data-ai-explanation></p>
            <div data-ai-recommended></div>
            <details data-ai-breakdown><summary>View score breakdown</summary><div data-ai-breakdown-list></div></details>
            <div data-ai-alternatives></div>
            <details data-ai-ineligible><summary>View ineligible candidates</summary><div data-ai-ineligible-list></div></details>
            <div class="ai-scheduling-result-actions">
                <button class="btn btn-primary" type="button" data-ai-apply disabled>Apply Recommendation</button>
                <button class="btn btn-outline-primary" type="button" data-ai-regenerate>Regenerate</button>
                <button class="btn btn-light" type="button" data-ai-ignore>Ignore</button>
                <button class="btn btn-light" type="button" data-ai-reject>Reject with reason</button>
                <button class="btn btn-light" type="button" data-ai-close>Close</button>
            </div>
            <div class="ai-scheduling-rejection" data-ai-rejection hidden>
                <label for="aiRejectionReason">Reason for rejecting this recommendation</label>
                <textarea id="aiRejectionReason" data-ai-rejection-reason rows="3" maxlength="1000" placeholder="Provide at least 5 characters for the audit record."></textarea>
                <button class="btn btn-outline-primary" type="button" data-ai-confirm-reject>Confirm rejection</button>
            </div>
        </div>
    </section>
@endif
