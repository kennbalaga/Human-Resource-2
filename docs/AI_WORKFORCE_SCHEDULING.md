# AI-Driven Workforce Scheduling Assistant

## Status and safety boundary

The assistant is an optional, advisory layer inside the existing schedule-assignment modal. It is disabled by default. It does not create, update, delete, approve, or publish a schedule. Applying a recommendation only fills the existing employee field after a fresh server-side eligibility check. HR must still review the form and use the existing **Save assignment** action.

The deterministic Laravel engine owns eligibility, scores, and ranking. Gemini, when separately enabled, can explain an already-computed result but cannot select or rerank an employee.

## Enablement

Hospital HR and the system owner must approve the initial weights and workload references before production activation.

```dotenv
AI_WORKFORCE_SCHEDULING_ENABLED=false
AI_SCHEDULING_GEMINI_EXPLANATIONS=false
AI_SCHEDULING_RECOMMENDATION_TTL_MINUTES=15
AI_SCHEDULING_HISTORY_DAYS=28
```

After changing environment configuration, refresh Laravel's cached configuration using the deployment process already used by the project. Gemini explanations also require the existing `GEMINI_ENABLED`, `GEMINI_API_KEY`, model, and endpoint configuration. Never commit a real API key.

At runtime, a System Administrator can use **System → Integrations → Scheduling Intelligence** to enable or disable the assistant and its optional Gemini explanations. Until an administrator saves that form, the `.env` values above remain the fallback. Once saved, the database-backed admin setting overrides `.env` and takes effect immediately for the scheduling UI and web/API recommendation endpoints. HR Managers may view the current global state but cannot change it.

Recommended rollout:

1. Keep both feature flags false while deploying the migrations and code.
2. Review the weights and workload-risk reference values in `config/ai_workforce_scheduling.php` with hospital HR.
3. Enable the Laravel assistant in a test environment and validate results against representative schedules.
4. Pilot with authorized HR managers or department heads. Review generated, applied, modified, ignored, rejected, expired, and stale cases.
5. Enable in production only after sign-off. Keep Gemini explanations off unless the privacy and external-processing review is complete.

## Decision flow

1. An authorized manager opens the existing assignment modal.
2. The manager selects the target department, required position, existing shift, and work date.
3. **Generate AI Recommendation** performs read-only analysis and persists a time-limited recommendation audit record.
4. The manager reviews the recommended employee, score breakdown, alternatives, ineligible reasons, workload indicator, limitations, and explanation.
5. **Apply Recommendation** or a selected alternative triggers server-side target, freshness, expiry, conflict, leave, and eligibility revalidation.
6. A successful apply fills only `employee_id` in the existing form and triggers the existing conflict check. The schedule remains unsaved.
7. The manager may manually change any form field and decides whether to use the existing **Save assignment** button.

Closing the panel preserves the form. Ignoring or rejecting records a human decision without changing the form. A rejection requires a reason.

## Current eligibility and ranking inputs

Hard constraints currently evaluated:

- active department, position, shift, and employee;
- position belongs to the selected department;
- employee department and position match the target;
- no approved leave covering any part of the shift, including an overnight shift's following date;
- no overlapping scheduled assignment, using the existing `ScheduleService` conflict logic.

Eligible employees are ranked deterministically with a fixed employee-ID tie-breaker. The configured 100-point model (`config/ai_workforce_scheduling.php`) uses:

- available hard constraints: 25;
- burnout risk: 15;
- weekly workload: 15;
- approved overtime: 10;
- recent assignments: 10;
- recent overnight assignments: 10;
- consecutive scheduled duties: 5;
- matches declared shift preference: 5;
- nearest rest interval: 5.

Weekly workload uses the greater of scheduled minutes and approved worked minutes for the target week. Lower workload, overtime, recent assignments, overnight assignments, and consecutive duties score more favorably relative to the current eligible pool. A longer nearest rest interval scores more favorably.

Burnout risk is scored on its fixed 0–100 scale rather than against the pool. A candidate with no assessment earns half the points. Independently of points, candidates at **high** burnout risk are ranked after every other eligible candidate, so they are only recommended when nobody else is eligible. They stay on the list with a warning. See [BURNOUT_RISK.md](BURNOUT_RISK.md), which also covers how bulk fill, the rotation assistant and the roster board protect high-risk employees.

These weights and reference values are application defaults, not hospital policy, clinical guidance, or a guarantee of safety or adequate staffing.

## Workload-risk indicator

The low, moderate, or high indicator is operational decision support only. It is not a diagnosis, fitness-for-duty decision, clinical risk score, or substitute for labor rules and hospital policy. HR must validate the moderate/high thresholds and every reference value before production use.

The indicator currently considers weekly workload, approved overtime, recent assignments, consecutive duties, overnight assignments, and nearest rest interval. The exact configuration is in `config/ai_workforce_scheduling.php` and can be changed without altering the manual scheduling workflow.

This prospective indicator asks whether *this shift* would overload the candidate. The separate burnout risk indicator asks how the last four weeks have treated them. Both are shown on each candidate.

## Deliberately unavailable factors

The current database does not contain reliable normalized data for competencies, certifications, declared availability, official rest days, shift preferences, holidays, minimum staffing, or hospital scheduling policies. The assistant explicitly warns that these factors were not evaluated. It does not infer or fabricate them.

An optional future schema can add these independently after requirements and data ownership are approved:

- `skills` and `employee_skills` with proficiency and verification metadata;
- `certifications` and `employee_certifications` with issuer, verification, issue, and expiry dates;
- `employee_availability_windows` with timezone-aware start/end and availability type;
- `employee_rest_days` or effective-dated work patterns;
- `employee_shift_preferences` with effective dates and preference strength;
- `scheduling_holidays` scoped by facility or organizational unit;
- effective-dated `scheduling_policies` for rest, consecutive duty, overtime, coverage, and staffing constraints;
- position/shift requirements that reference verified skills or certifications.

Each new dataset should have an authoritative owner, validation rules, effective dates, an audit trail, and tests before its factor is enabled. Missing data must remain visible as a limitation, never converted into a favorable score.

## Freshness and concurrency

Recommendations expire after the configured TTL. A fingerprint covers the target, candidate employment state, relevant schedules and shifts, overlapping approved leave, and target-week attendance/workload records. Apply is rejected with a regenerate message if the target changes, time expires, or any covered record changes.

Apply uses a database lock for the human decision record. It still does not reserve an employee or save a schedule. The existing schedule save validation remains the final authority and protects against changes that occur after recommendation apply.

## Audit separation

`schedule_recommendations` records the automated action with:

- actor type `AI_SYSTEM`;
- actor name `AI Scheduling Assistant`;
- action type `GENERATED_RECOMMENDATION`;
- target identifiers, selected identifiers, safe eligibility factors, fingerprint, status, explanation source, generation time, and expiry.

`schedule_recommendation_decisions` separately records the authenticated human actor and the applied, modified, ignored, or rejected decision. Applying the original recommendation is `applied`; choosing a recorded alternative is `modified`. These records do not replace the project's normal schedule write audit.

Persisted AI eligibility payloads use employee IDs and reason codes rather than names, employee numbers, emails, contact details, or leave reasons. Burnout risk is persisted as level and score only. The factors behind it, which can include sick-leave counts, are not kept in the recommendation record.

## Gemini privacy boundary

When enabled, the explanation request contains only pseudonyms such as `CANDIDATE_1`, numeric scores, score-factor labels and values, workload-risk values, each candidate's burnout risk level (never its factors), fairness deltas, counts, and generic limitation notices. It excludes database IDs, names, employee numbers, emails, contact details, leave reasons, and protected characteristics.

Gemini output is explanation text only. Any timeout, HTTP error, malformed response, missing key, or disabled flag safely falls back to a deterministic Laravel explanation. Attempted Gemini explanation calls are recorded as `scheduling.explain` integration events with status, response code, duration, endpoint host, and candidate count—not prompt PII.

## Endpoints

Authenticated web routes:

- `POST /schedules/ai-recommendations`
- `POST /schedules/ai-recommendations/{uuid}/apply`
- `POST /schedules/ai-recommendations/{uuid}/decision`

Sanctum API equivalents use `/api/v1/schedule-recommendations` and require a manager role plus `workforce:write` ability.

Example generation JSON:

```json
{
  "department_id": 1,
  "position_id": 2,
  "shift_id": 3,
  "work_date": "2027-10-01"
}
```

Example apply JSON:

```json
{
  "employee_id": 4,
  "department_id": 1,
  "position_id": 2,
  "shift_id": 3,
  "work_date": "2027-10-01"
}
```

Example decision JSON:

```json
{
  "action": "rejected",
  "reason": "Coverage requirements changed after review."
}
```

## Disable and rollback

For an immediate functional rollback, set `AI_WORKFORCE_SCHEDULING_ENABLED=false` and refresh configuration. The existing manual schedule UI, conflict checks, routes, validation, and save behavior continue to operate.

Keep recommendation audit data unless the approved retention policy says otherwise. If code and schema rollback is required, back up the database first and use a reviewed deployment migration plan rather than manually dropping tables. The two feature tables are independent of `schedule_assignments`, so disabling the feature does not require deleting or modifying any schedule.

## Verification checklist

- feature is absent and endpoints return 404 when disabled;
- standard employees cannot generate or apply recommendations;
- generate, apply, ignore, and reject never create a schedule;
- apply changes only the existing employee field in the browser;
- existing conflict checks and manual save remain active;
- approved leave and overnight overlap cases are blocked;
- stale, expired, resolved, and target-changed recommendations are blocked;
- deterministic tie ordering and score totals remain stable;
- Gemini failure preserves ranking and uses Laravel fallback;
- prompt privacy tests and full Laravel regression suite pass;
- production asset build and dark/mobile layouts are verified.
