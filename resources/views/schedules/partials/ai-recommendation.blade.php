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
        <details class="ai-scheduling-about" data-ai-criteria>
            <summary>What the assistant looks at</summary>
            <p class="ai-scheduling-notice">Uses available department, position, schedules, approved leave, attendance, overtime, and workload records. HR review is required.</p>
            <div class="ai-scheduling-criteria">
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
            <div class="ai-scheduling-results-heading">
                <strong data-ai-results-title></strong>
                <small>Fewest paid hours this week ranks first</small>
            </div>
            {{-- One ranked list rather than a pick and a separate runner-up
                 column: the comparison a reviewer makes is between the people,
                 and each card carries the button that puts that person on the
                 shift. Who was left out follows it, because those two lists
                 are read against each other; the score behind the order is
                 the one thing folded away. --}}
            <div class="ai-candidate-list" data-ai-candidates></div>
            <section class="ai-ineligible" data-ai-ineligible hidden aria-labelledby="aiIneligibleTitle"><p class="ai-ineligible-title" id="aiIneligibleTitle" data-ai-ineligible-title></p><div data-ai-ineligible-list></div></section>
            {{-- The ranking in prose, kept with the numbers it describes: it
                 says the same thing on every result, and in front of the cards
                 it was two paragraphs standing between the question and the
                 answer. --}}
            <details data-ai-breakdown><summary>Why this ranking</summary><p class="ai-scheduling-explanation" data-ai-explanation></p><div data-ai-breakdown-list></div></details>
            {{-- No row of review buttons under the list. Regenerate repeated
                 the Recommend again button in the header, Close undid a panel
                 that already steps aside when the shift or date changes, and
                 the two that recorded a decision asked the reviewer to answer
                 the assistant before answering the question the form is
                 actually asking. Declining a suggestion here is picking
                 somebody else, or picking nobody. The decision endpoint and
                 its audit table are untouched. --}}
        </div>
    </section>
@endif
