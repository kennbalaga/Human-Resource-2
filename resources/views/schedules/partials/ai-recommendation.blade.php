@if ($aiSchedulingEnabled)
    <section
        class="ai-scheduling"
        data-ai-scheduling
        data-generate-url="{{ route('schedules.ai-recommendations.store') }}"
        data-weekly-limit="{{ (int) config('schedule.compliance.max_hours_per_week') }}"
        data-minimum-rest="{{ (int) config('schedule.minimum_rest_hours') }}"
        aria-labelledby="aiSchedulingTitle"
    >
        <div class="ai-scheduling-heading">
            <span class="ai-scheduling-icon"><x-icon name="ai" /></span>
            <div>
                <p>AI Scheduling Assistant</p>
                <h3 id="aiSchedulingTitle">Who can take this shift?</h3>
            </div>
            <span class="ai-scheduling-advisory">Advisory only</span>
        </div>

        {{-- The assistant works from the department, position, shift and date
             set on the left, so it has no pickers of its own to disagree with them. --}}
        <div class="ai-scheduling-context">
            <div><small>Recommending for</small><strong data-ai-context>No shift yet</strong></div>
            <button class="btn btn-outline-primary" type="button" data-ai-generate><x-icon name="ai" /> Generate AI Recommendation</button>
        </div>

        {{-- What the assistant weighs, said before it is asked, so the advisory
             result is read against a known list rather than a black box.

             Folded, not dropped: it is the same list on every shift, and left
             open it pushed the recommendation itself below the fold. It sits
             under the button rather than over it, so what the panel is for is
             the first thing read. --}}
        <details class="ai-scheduling-about">
            <summary>What the assistant looks at</summary>
            <p class="ai-scheduling-notice">Uses available department, position, schedules, approved leave, attendance, overtime, and workload records. HR review is required.</p>
            <div class="ai-scheduling-criteria" data-ai-criteria>
                <p>The assistant checks each employee for:</p>
                <ul>
                    <li>Existing shifts on the same day</li>
                    <li>Approved leave and scheduled days off</li>
                    <li>Paid hours already scheduled this week</li>
                    <li>At least {{ (int) config('schedule.minimum_rest_hours') }} hours of rest between shifts</li>
                </ul>
                <small>Performance ratings and disciplinary records are never used.</small>
            </div>
        </details>

        <div class="ai-scheduling-status" data-ai-status role="status" aria-live="polite" hidden></div>
        <div class="ai-scheduling-results" data-ai-results hidden>
            <p class="ai-scheduling-explanation" data-ai-explanation></p>
            <div class="ai-scheduling-results-heading">
                <strong data-ai-results-title></strong>
                <small>Fewest paid hours this week ranks first</small>
            </div>
            {{-- One ranked list rather than a pick and a separate runner-up
                 column: the comparison a reviewer makes is between the people,
                 and each card carries the button that puts that person on the
                 shift. The score breakdown and the people who were left out
                 stay in disclosures below. --}}
            <div class="ai-candidate-list" data-ai-candidates></div>
            <details data-ai-breakdown><summary>View score breakdown</summary><div data-ai-breakdown-list></div></details>
            <section class="ai-ineligible" data-ai-ineligible hidden aria-labelledby="aiIneligibleTitle"><p class="ai-ineligible-title" id="aiIneligibleTitle" data-ai-ineligible-title></p><div data-ai-ineligible-list></div></section>
            <div class="ai-scheduling-result-actions">
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
