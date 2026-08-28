# Schedule-Aware Attendance — Implementation Plan

**System:** HIMS-HR · Laravel / MySQL
**Closes:** Seam 1 — *attendance is not schedule-aware*
**Companion figure:** `hims_hr_l2_tobe_schedule_aware.svg` (Figure 2, to-be L2)
**Date:** 18 August 2026

**Scope.** This document now carries two workstreams against the same roster code path:

- **Part A (§1–§13)** — making attendance schedule-aware, closing Seam 1. §9 flags the governing rule as too narrow and restates it as an invariant.
- **Part B (§14–§23)** — implementing that invariant, so *"AI must never write directly to `schedule_assignments`"* becomes enforceable rather than conventional.

They share a subject and can share a review, but they are independently shippable and answer to different reviewers: Part A is a data-correctness change, Part B a governance control. Part B's first two phases can ship ahead of Part A entirely.

---

## 1. The defect, stated precisely

`AttendanceService::checkIn()` and `checkOut()` compute lateness, overtime and undertime against the employee's assigned `OfficeLocation` hours. They never look at the `ScheduleAssignment` published for that employee on that date. The roster re-enters only in `AttendanceOverviewService`, which cross-references published assignments *after the fact*, purely to compute an "absent" bucket for reporting.

Consequence: someone rostered 14:00–22:00 who badges in at 09:00 reads as **on-time**, because 09:00 is inside office hours. The system has both facts and never compares them.

Everything downstream inherits the error. `Timesheet` entries mirror attendance minutes, so an overtime figure computed against the wrong baseline flows into approved timesheets and into every analytics tier without a single validation step in between.

---

## 2. Decisions taken

| # | Decision | Chosen | Consequence |
|---|---|---|---|
| D1 | Shift binding | **Hard FK, resolved once at check-in and frozen** | `attendance_records.schedule_assignment_id` plus a frozen copy of the shift window. Auditable and cheap to report on. A roster edit made *after* the punch will not retroactively rewrite the record — drift must be surfaced, not silently corrected. |
| D2 | No published shift for that date | **Block the check-in** | Punch is refused and escalated to a manager, who may authorise it with a logged reason. No shift and no authorisation means no attendance record. |
| D3 | Deliverable | Plan + revised L2 diagram | This document and Figure 2. |

### 2.1 Flag on D2 — read this before scheduling the work

A hard block on check-in is the strictest option, and in a hospital it carries real operational risk: an unpublished roster, a last-minute coverage call, or a bank/agency nurse arriving for an unrostered shift all become *someone cannot clock in*. The plan therefore does two things you should consciously accept or override:

1. **The manager authorisation path is part of the design, not a softening of it.** The block is real — the refusal end event exists and produces no `AttendanceRecord`. But a manager, HR user or dept-head with `attendance.override` can authorise the punch with a mandatory reason, which is logged. Without that lane, D2 is a lockout, not a control.
2. **Blocking ships last (Phase 4), behind its own flag.** Phases 1–3 make attendance schedule-*aware* without refusing anyone. The block only turns on once shadow data shows roster coverage is high enough that refusals will be rare. Turning it on before then converts a data-quality defect into a staffing incident.

If you want the pure form — no override lane at all — delete the `attendance.override` permission and the authorisation gateway from the design, and say so; the rest of the plan is unaffected.

---

## 3. Target behaviour

1. A punch resolves to a published `ScheduleAssignment` **before anything is timed**.
2. Lateness is measured against **shift start + grace**, not office opening.
3. Overtime is measured against **shift end**; undertime against **rostered duration**.
4. The binding, and a frozen copy of the shift window, are stored on the `AttendanceRecord`.
5. A punch that resolves to a date carrying **approved leave** does not bind — the contradiction surfaces at the door.
6. No published shift → refused, unless a manager authorises it on the record.
7. `AttendanceOverviewService` stops re-deriving the schedule link; it reads the FK.

---

## 4. Data model changes

### 4.1 Migration — `attendance_records`

| Column | Type | Null | Purpose |
|---|---|---|---|
| `schedule_assignment_id` | FK → `schedule_assignments` | yes | The bound shift. Null only for `override` records. `nullOnDelete`. |
| `shift_start_at` | datetime | yes | Frozen copy of the shift window at bind time. |
| `shift_end_at` | datetime | yes | As above. Survives later roster edits. |
| `binding_source` | enum(`scheduled`,`override`) | no, default `scheduled` | How the record came to exist. |
| `schedule_status` | enum(`on_shift`,`early`,`late`,`off_shift`,`unscheduled`) | no, default `on_shift` | Adherence outcome, kept **separate** from `status`. |
| `early_minutes` | int | no, default 0 | Counterpart to the existing late/overtime minutes. |
| `override_authorised_by` | FK → `users` | yes | Who authorised an unscheduled punch. |
| `override_reason` | text | yes | Mandatory when `binding_source = override`. |

Index: `(employee_id, date)` and `(schedule_assignment_id)`.

**Why `schedule_status` is a new column and not new values on `status`.** The existing `status` (`present` / `late`) is read by the timesheet sync and by every analytics consumer. Widening its enum would silently change the meaning of existing queries. Adding a parallel column is additive — nothing downstream breaks on day one, and each consumer opts in deliberately.

### 4.2 Migration — `timesheet_entries`

| Column | Type | Purpose |
|---|---|---|
| `scheduled_minutes` | int, null | The rostered figure for that day, so plan-vs-actual variance is reportable without re-joining the roster. |

### 4.3 New system settings (admin-only, matching the existing settings gate)

| Setting | Suggested default | Meaning |
|---|---|---|
| `attendance.early_window_minutes` | 60 | How long *before* shift start a punch still binds to that shift. |
| `attendance.grace_minutes` | reuse existing office-hours grace | Lateness grace after shift start. |
| `attendance.late_bind_minutes` | 240 | How long *after* shift start a punch can still bind rather than being treated as off-shift. |
| `attendance.schedule_aware` | flag | Master switch for Phases 2–3. |
| `attendance.enforce_published_shift` | flag, off | The D2 block. Phase 4. |

Defaults are proposals, not recommendations — they should be set from the shadow-mode distribution in Phase 1, not guessed.

---

## 5. `ShiftResolver` — the new component

A single service, `ShiftResolver::forPunch(Employee $e, CarbonInterface $at)`, returning a resolution object: `{assignment|null, schedule_status, matched_window, reason}`.

```
1. Candidate set = published ScheduleAssignments for $e whose shift window
   overlaps [$at - late_bind, $at + early_window].
   The window is built from the Shift's start/end times applied to the
   assignment date, with end rolled to the next day when end < start
   (overnight shifts).

2. Drop any candidate whose date carries an approved LeaveRequest for $e.
   Record reason = 'leave_conflict' if this empties the set.

3. Drop any candidate already bound to a closed AttendanceRecord
   (prevents a second punch re-binding a completed shift).

4. If 0 candidates  -> {null, 'unscheduled', reason}
   If 1 candidate   -> bind
   If >1 candidates -> bind to the one whose start is nearest to $at
                       (split shifts); tie-break on earliest start.

5. Classify against the bound window:
      $at <  start - early_window   -> 'off_shift'   (should not occur; window guards it)
      $at <  start                  -> 'early'
      $at <= start + grace          -> 'on_shift'
      $at <= start + late_bind      -> 'late'
      else                          -> 'off_shift'
```

The resolver is **pure and side-effect free** — it reads, classifies, and returns. Only `checkIn()` writes. This keeps it directly unit-testable against fixtures and keeps the write path in one place.

---

## 6. Service changes

### 6.1 `AttendanceService::checkIn()`

```
resolve  -> ShiftResolver::forPunch()
  bound:
     write schedule_assignment_id, shift_start_at, shift_end_at,
           binding_source='scheduled', schedule_status
     status = 'late' when schedule_status = 'late', else 'present'
     late_minutes  = max(0, $at - (start + grace))
     early_minutes = max(0, start - $at)
  unbound:
     if !enforce_published_shift  -> create with binding_source='override',
                                     schedule_status='unscheduled',
                                     timed against OfficeLocation as today
     if  enforce_published_shift  -> refuse; return a refusal result carrying
                                     the reason, and surface the manager
                                     authorisation route
```

### 6.2 `AttendanceService::checkOut()`

- `worked_minutes` — unchanged (out − in, less breaks if that already exists).
- `overtime_minutes` = `max(0, $out − shift_end_at)` — **was** measured against office close.
- `undertime_minutes` = `max(0, rostered_duration − worked_minutes)` — **was** measured against office-hours length.
- Where `shift_end_at` is null (override records), fall back to today's office-hours behaviour so nothing crashes.

### 6.3 Manager authorisation

New permission `attendance.override`, granted to the same set that already approves attendance (Admin, HR Manager, Dept-Head). Requires a reason string; writes `override_authorised_by` and `override_reason`. Self-authorisation is blocked, consistent with the existing self-approval rules for leave, attendance and timesheet review.

### 6.4 `AttendanceOverviewService`

The after-the-fact cross-reference that computes the "absent" bucket becomes a direct query: assignments for the period with no `AttendanceRecord` pointing at them. Delete the duplicated matching logic rather than leaving two sources of truth.

---

## 7. Downstream impact

| Area | Impact | Action |
|---|---|---|
| **Timesheet sync** | Shape unchanged — still one entry per approved day. The *minutes* change, because overtime and undertime now have a different baseline. | Populate `scheduled_minutes`. Do not backfill historical timesheets. |
| **Overtime figures** | Will move, possibly materially, the day Phase 3 lands. This is the point of the change, but it will look like a regression to anyone not briefed. | Communicate before cutover. Keep the shadow-mode comparison as evidence. |
| **Analytics** | New metrics available: schedule adherence %, off-shift rate, override rate, plan-vs-actual variance. | Add to `/analytics`; the AI insight layer keeps receiving aggregates only — no employee-level data leaves the app. |
| **`ScheduleComplianceService`** | Can now check *actual* against *planned*, not just planned against rules. | Extend findings; still HR-triggered, still the only write on the read path. |
| **QR payload** | Unchanged. The badge stays employee-bound and HMAC-signed; the shift is resolved server-side. Encoding a shift into the QR would break the reinstall-survival property and create a forgeable claim. | No change — record the reasoning. |
| **Seam 2** | Not closed. Reduced: the resolver refuses to bind a punch to a leave-covered shift, so the contradiction surfaces at the door. The stale published assignment itself still survives until a manager clears it or HR runs the compliance check. | Separate work item — a reconciliation step on leave approval. |

---

## 8. Shift swap — an adjacent path, unverified

### 8.1 Status

**Shift swap does not appear anywhere in the as-is architecture.** There is no `shift_swaps` table, no status field, no service, and no entry in the entity reference. I cannot tell from the material available whether the feature does not exist, or exists and was omitted from the document. Everything in this section is therefore conditional and must be confirmed before it is estimated or built.

It is recorded here because if the feature does exist, it is a **larger hole than the one this plan closes**, and it lands on exactly the same code path.

### 8.2 A swap must re-publish, not override

Two mechanisms are easy to conflate, and conflating them is where the hole opens:

| | Override (§6.3) | Swap |
|---|---|---|
| Trigger | At the door, unplanned | In advance, planned |
| Roster state | No assignment exists | An assignment exists and is being reassigned |
| Correct outcome | Attest to a punch that nothing planned; log it as an anomaly | Rewrite the roster so the punch resolves normally |
| Record | `binding_source = override`, `schedule_status = unscheduled` | `binding_source = scheduled` — indistinguishable from any other rostered punch |

An approved swap must result in a `ScheduleAssignment` that reflects who is actually working. The resolver then finds it and binds with no new logic at all. A swap should **never** be the thing that authorises a clock-in, because the moment it is, the override lane becomes a routine path rather than an exception — and its audit value collapses.

### 8.3 The failure mode if a swap does not touch `schedule_assignments`

If swap approval writes only its own row and leaves the published assignment untouched, the swap is invisible to `ShiftResolver`. Two records go wrong simultaneously:

- The covering employee is **refused at the door** despite holding an approved swap.
- The original employee's assignment still stands, so they read as a **no-show** in the absent bucket.

This is Seam 2's shape repeating: an approved decision that never reaches backward into an already-published assignment. Under Phase 4 enforcement it stops being a reporting defect and becomes a staffing incident.

### 8.4 The material risk: a bypass of the only rule engine

Timing is not the real exposure. The exposure is that `evaluate()` / `bulkAssignmentBlockReason()` is the only place labour rules are enforced, and it runs at publish time. If swap approval writes an assignment without re-running it, a swap becomes the one path in the system that can place someone on a shift scheduling itself would have refused:

- a rest-period violation created by back-to-back coverage;
- a date already carrying approved leave for the incoming employee;
- a coverage, headcount or skill-mix breach on the ward.

**Proposed governing rule, alongside the existing one:** *no path may write to `schedule_assignments` except `publish()`, and every write re-runs the rule engine in the same transaction.* A swap that fails the rule engine is refused, exactly as a roster edit is.

### 8.5 Lead time

A swap approved two days before the shift is the **safe** case. The frozen snapshot (D1) only binds at punch time, so any roster change committed before the punch is picked up cleanly by the resolver — there is no lead-time floor needed for correctness.

The case that needs a guard is the opposite one. A swap approved *after* a check-in has already occurred must not silently rewrite that record's `schedule_assignment_id`, `shift_start_at` or `shift_end_at`; the punch was truthful to what was published when it happened. Such a swap should be refused, or accepted with the existing attendance record flagged for manager review — never applied retroactively.

### 8.6 Required behaviour, if the feature exists or is built

1. Swap approval runs inside the same transaction and rule engine as `publish()`; a blocked swap is refused with the block reason surfaced to the approver.
2. On approval, the assignment is reassigned — same shift, same date, new employee — so the resolver needs no swap-specific logic.
3. Swap approval is refused where any affected date already carries a closed `AttendanceRecord` for that assignment (§8.5).
4. Self-approval is blocked, consistent with leave, attendance and timesheet review.
5. The swap record is retained for audit — who swapped with whom, who approved, when — but is never read by `ShiftResolver`.

### 8.7 Tests this adds

- Swap approved two days out → covering employee binds normally, `binding_source = scheduled`; original employee does not appear in the absent bucket.
- Swap that would create a rest-period violation → refused at approval, not discovered at the door.
- Swap onto a date carrying approved leave for the incoming employee → refused.
- Swap approved after check-in → refused or flagged; the existing binding is unchanged.

### 8.8 Decision needed

Confirm whether shift swap exists in the codebase today and whether it writes to `schedule_assignments`. If it exists and does not go through `publish()`, it should be raised as **Seam 3** on the architecture diagram and sequenced *before* Phase 4 — enforcement will otherwise turn an invisible data defect into refused staff at the badge reader.

---

## 9. The governing rule — flagged as too narrow

### 9.1 What it says today

> "AI must never write directly to `schedule_assignments`."

The flow honours it well. A recommendation lands as `for_hr_review`; a human reviews and applies it; `apply()` revalidates but persists nothing; only `publish()` writes. The AI path is, on the evidence of the as-is architecture, **the most heavily guarded write path in the system**.

### 9.2 The flag

The rule constrains **an actor**, not **the table**. It says who may not write, rather than what the table guarantees. Everything that is not the AI engine is unaddressed by it:

- bulk fill and any future roster tooling;
- imports, seeders and console commands;
- a shift-swap approval (§8);
- an integration, a scheduled job, or a hotfix run straight against the database.

Each of those can create an assignment that never passed `evaluate()` / `bulkAssignmentBlockReason()` — and the rule as written would not have been broken. The stated risk and the actual risk are pointing in different directions: the guarded path is the one the rule names, and the unguarded paths are the ones it doesn't.

### 9.3 Proposed restatement

> **Invariant:** `schedule_assignments` is written only by `publish()`, inside a transaction, and only after `evaluate()` / `bulkAssignmentBlockReason()` passes. This binds every actor — human, AI, scheduled job, import or integration.

The existing rule survives unchanged as a **corollary**, so nothing already written or presented is invalidated: if only `publish()` writes, and `publish()` is human-initiated, then the AI cannot write. The restatement widens the guarantee without weakening the original claim.

### 9.4 It is currently prose, not a constraint

Nothing structural enforces it — the rule lives in documentation, and the database will accept a write from anywhere. Options, cheapest first:

| | Mechanism | Catches |
|---|---|---|
| 1 | **Architecture test** — assert no class outside the publish path calls `create` / `update` / `save` / `insert` on `ScheduleAssignment` (Pest arch test or a PHPStan rule). | Violations at CI time. Cheap; turns the slogan into a check. |
| 2 | **Model guard** — a `saving` event on `ScheduleAssignment` that throws unless a publish-context marker set by `publish()` is present. | Violations at runtime, including tinker, console commands and future code. |
| 3 | **Single writer** — route every write through one repository/action; everything else is read-only by construction. | Design-time; the strongest, and a larger refactor. |
| 4 | **Database grants** — a separate credential for the publish path. | Everything, including raw SQL. Likely disproportionate here. |

Recommend **1 + 2** as the minimum viable enforcement: a CI check plus a runtime guard. Together they cost little and make the rule falsifiable, which prose cannot be.

*Superseded by decision.* **Part B (§14–§23) implements all four layers**, not 1 + 2. The reasoning for escalating past this recommendation — and the cost objection to option 4 that still stands — is set out in §14.2.

### 9.5 Why this matters specifically to this plan

Once an `AttendanceRecord` binds to a `ScheduleAssignment` (D1), the assignment stops being a planning artefact. It becomes the baseline for lateness, overtime, undertime — and, downstream of the timesheet, for pay. An assignment written outside the rule engine is then no longer merely a bad roster entry; it is a **bad payroll baseline**. Phase 4 compounds this: an unvalidated assignment becomes the thing that decides whether a person may clock in at all.

There is also a direct consequence for the stated goals. **100% scheduling conflict detection presupposes this invariant.** If any path can write an assignment without running the rule engine, 100% detection is unattainable by construction — no improvement to the rule engine can recover it, because the rule engine is simply not on that path.

### 9.6 Scope note

This is a tightening of an existing constraint, not a new capability. It does not alter the five research questions, the five sub-modules, or the out-of-scope list; it introduces no autonomous scheduling, since `publish()` remains human-initiated; and it leaves the measurable goals as stated.

---

## 10. Edge cases

| Case | Behaviour |
|---|---|
| Overnight shift 22:00–06:00 | Window rolls end to next day. Check-out on D+1 still resolves to the D assignment via the open check-in. |
| Split shift (two assignments, one day) | Binds to the nearest start; the completed first shift is excluded from the second punch's candidate set. |
| Arrival 3 h early | Outside `early_window` → no candidate → refused/override. Prevents a morning punch binding an evening shift. |
| Arrival after `late_bind` | No candidate → refused/override. Prevents a very late punch silently consuming the shift. |
| Approved leave on a published-shift date | Resolver refuses to bind; reason `leave_conflict`. Surfaces Seam 2 at the door. |
| Roster edited after check-in | Frozen snapshot keeps the record truthful to what was published at punch time. Drift is a reporting concern, not a rewrite. |
| Shift swapped before the punch | Resolver binds to whatever is published at punch time — correct, provided the swap rewrote the assignment. See §8. |
| Shift swapped after check-in | The record keeps the original binding; the swap must not rewrite it retroactively. Flag for manager review. See §8.5. |
| DST / timezone | All windows built in the location's timezone, stored UTC. Explicit test for the spring-forward and fall-back days. |
| Missing check-out | Unchanged from today's behaviour. |
| Employee with no `OfficeLocation` | Previously undefined-ish; now irrelevant when bound, and the override fallback must not assume a location exists. |

---

## 11. Phased rollout

Each phase is independently shippable and independently revertible.

| Phase | Scope | Exit criteria |
|---|---|---|
| **0 — Baseline** | Agree tolerances, confirm codebase assumptions (§13) **including whether shift swap exists (§8)**, snapshot current overtime/lateness distributions. | Baseline numbers recorded, so §7's "figures will move" is measurable rather than argued. Swap question answered. |
| **1 — Schema + resolver, shadow mode** | Migrations. `ShiftResolver` built and unit-tested. `checkIn()` calls it and **writes only the new columns** — `status`, `late_minutes`, `overtime_minutes` keep their office-hours values. | 2–4 weeks of live data. Report: % of punches that resolve, distribution of early/late offsets, count of leave conflicts, count of unscheduled punches by department. Tolerances tuned from *this*, not from guesses. |
| **2 — Authoritative timing** | Flip `attendance.schedule_aware`. `status`, lateness, overtime and undertime now derive from the shift. Unscheduled punches still allowed, flagged `unscheduled`. | Overtime/lateness deltas explained and accepted by HR. Timesheet totals reconcile. |
| **3 — Downstream** | `scheduled_minutes` on entries, adherence metrics in `/analytics`, `AttendanceOverviewService` simplified, `ScheduleComplianceService` extended. | Dashboards show adherence; absent-bucket numbers match the previous implementation. |
| **4 — Enforcement (D2)** | Flip `attendance.enforce_published_shift`. Refusal path + manager authorisation go live. | Phase 1–2 data shows unscheduled punches are rare and concentrated in explainable cases. **Shift swap confirmed to write through `publish()` (§8) — this is a hard gate, not a nice-to-have.** Pilot one department first. Rollback = flip the flag. |

**Do not compress Phases 1 and 2.** The shadow window is what makes the tolerance settings defensible and the overtime shift explainable; without it you are changing payroll-adjacent numbers on an assumption.

---

## 12. Test plan

**Unit — `ShiftResolver`:** one test per row of §10, plus the swap cases in §8.7, plus no-candidates, exactly-one, multiple-candidates, and each classification boundary (`start − early_window`, `start`, `start + grace`, `start + late_bind`) tested at the boundary and one minute either side.

**Feature — attendance:** check-in bound / early / late / off-shift; check-in refused with enforcement on; manager authorisation happy path; self-authorisation blocked; check-out overtime against shift end; check-out fallback on an override record; approve → timesheet entry carries `scheduled_minutes`.

**Regression:** the 14:00–22:00 shift with a 09:00 punch — the original defect — must classify `off_shift` or refuse, and must never read `present`/on-time.

**Data:** a replay harness that runs historical punches through the resolver and reports what would have changed. This is the Phase 1 deliverable and the evidence base for Phase 2.

---

## 13. Assumptions to verify against the codebase

I do not have the repository, so these are read from the architecture document and must be confirmed before estimating:

1. `ScheduleAssignment` reaches shift times through a `Shift` relation with start/end times, and the assignment carries a date — the resolver's window construction depends on this shape.
2. `schedule_assignments.status` only ever holds `scheduled`, so "published" is equivalent to "row exists". If a soft-delete or cancelled state exists, the candidate query needs it.
3. Break deduction, if any, already lives in `worked_minutes` — §6.2 assumes the resolver does not need to model breaks.
4. Grace period is a single value on `OfficeLocation` (or settings) and is reusable for shift start.
5. Overnight shifts are representable at all in the current `Shift` model. **If end-before-start is not currently supported, that is a prerequisite, not an edge case** — flag it early.
6. `attendance_records` has a usable natural key per employee/day; the resolver's "already bound and closed" check depends on how multiple same-day records are currently handled.
7. Whether agency/bank staff are rostered in `schedule_assignments` at all. If they are not, D2 blocks them from the building on day one — this is the single most likely way Phase 4 hurts.
8. Whether shift swap exists, and if so whether it writes to `schedule_assignments` and whether that write re-runs the rule engine. See §8 — this is the assumption with the widest blast radius, because it can both refuse legitimate staff and admit rule-violating placements.

Items 5, 7 and 8 are the ones that can change the plan's shape, not just its estimate.

---

# Part B — Enforcing the write boundary

## 14. What Part B implements

### 14.1 The invariant being enforced

Part B is the implementation of §9. It does not re-argue the case — §9.2 established that the rule constrains an actor rather than the table, and §9.3 restated it as the invariant this part enforces:

> `schedule_assignments` is written only by `publish()`, inside a transaction, and only after `evaluate()` / `bulkAssignmentBlockReason()` passes. This binds every actor — human, AI, scheduled job, import or integration.

The original rule survives as a corollary: if only `publish()` writes and `publish()` is human-initiated, the AI cannot write. Everything below enforces the invariant; the AI guarantee falls out of it for free, and so does the shift-swap constraint proposed in §8.4. **One control set, three problems.**

### 14.2 Where this departs from §9.4, and why

§9.4 recommends mechanisms 1 + 2 and judges database grants "likely disproportionate here." Part B implements all four. That is a deliberate escalation, and the cost objection has not gone away — grants mean ops involvement, a second connection, and errors that surface as SQL failures rather than clean domain exceptions.

What changes the calculus is §9.5. Once D1 binds an `AttendanceRecord` to a `ScheduleAssignment`, the assignment is no longer a planning artefact:

- it becomes the **baseline for lateness, overtime and undertime**, and through the timesheet, for pay;
- under Phase 4 it becomes the thing that decides whether a person **may enter the building**.

A control proportionate to a rostering mistake is not automatically proportionate to a payroll baseline or a door gate. Mechanisms 1 and 2 are code, and code can be edited by the same person who would introduce the violation; they defend against accident, not against drift with commit rights. If the project intends to claim that no assignment can exist without human authorisation, that claim needs a layer that survives someone deleting the guard.

If the ops cost proves prohibitive, **drop Layer 4 (§20) and keep 1, 2, 3 and 5** — that lands close to §9.4's recommendation plus detection, and is a defensible position. What should not happen is shipping Layer 4 half-built.

## 15. Defining the boundary

An invariant binding "every actor" needs actors to be identifiable, or it cannot be tested.

### 15.1 AI-origin, enumerated

| Category | Component | Current write posture |
|---|---|---|
| Recommendation generation | `ScheduleRecommendationService` | Writes `ScheduleRecommendation` only |
| Recommendation application | `RecommendationLifecycleService::apply()` | In-memory board only — persists nothing |
| Model-derived board fill | the "AI rotation" route in bulk fill | Feeds the board; `publish()` still required |
| Analytics narrative | Gemini insight over aggregates | Read-only |

### 15.2 Deny by default

Enumeration alone ages badly — it only covers what exists today. The process rule that catches the rest:

> **Any execution context without an authenticated human actor — queued job, scheduler tick, console command, import — cannot open the write context.**

This inverts the burden. New code does not have to be recognised as AI to be blocked; it has to be recognised as *human-initiated* to be allowed. No human in the request, no roster write. That is what makes the invariant hold for actors nobody has thought of yet, which is precisely the gap §9.2 identified.

### 15.3 One rule that keeps the loop closed

AI output is **data for humans, never instructions for code.** No AI-generated text is parsed into a write payload. There is no such loop today; this rule keeps one from appearing — which is also what keeps prompt injection out of the roster, since the Gemini path receives aggregates and returns prose that only ever reaches a screen.

## 16. The five controls

| Layer | Control | Stops | Cost | Fails when |
|---|---|---|---|---|
| 1 | Provenance, `created_by` NOT NULL (§17) | A row existing without a human author | One migration + backfill | An actor id is supplied that no human actually chose |
| 2 | Runtime write context (§18) | Any write outside `publish()` | Small service + model hooks | Someone edits the guard |
| 3 | CI architecture tests (§19) | The violating code being merged | Test suite + CI time | Tests are deleted or skipped |
| 4 | Database privilege separation (§20) | The credentials executing the write at all | Ops work, second connection | A DBA changes the grant |
| 5 | Audit + conformance (§21) | Nothing — it makes violations visible | Table + report | Nobody reads the report |

Layers 1 and 4 are structural, 2 and 3 procedural, 5 is what tells you the others slipped. This maps onto §9.4's options 1, 2 and 4; option 3 (single-writer repository) is subsumed — Layer 2 achieves the same guarantee without the refactor, and Layer 3 enforces it statically.

## 17. Layer 1 — Provenance: no assignment without a human author

Migration on `schedule_assignments`:

| Column | Type | Purpose |
|---|---|---|
| `created_by` | FK → `users`, **NOT NULL** | The human who published this assignment. |
| `created_via` | enum(`manual`,`bulk_fill`,`recommendation_apply`,`legacy`) NOT NULL | How the placement reached the board. |
| `source_recommendation_id` | FK → `schedule_recommendations`, null | Provenance, **not** authorship. |

**`created_by NOT NULL` is the cheapest control in this document.** It turns a policy sentence into a database fact: a schedule assignment cannot exist without a human author. Any path that omits one fails at insert — including paths nobody has written yet, which is exactly the class §9.2 flagged.

`source_recommendation_id` is deliberately a separate column. An AI suggestion can *influence* a placement; it can never be its author. Keeping the two apart is what lets you report "% of published shifts that originated in a recommendation" without ever implying the AI decided.

**Backfill.** Derive the author from the publishing `RosterDraft` where possible. Where it is not derivable, set `created_via = 'legacy'` with a designated migration actor. Keep `legacy` as its own enum value — never fold it into `manual`, or unverified history becomes indistinguishable from verified provenance. `NOT NULL` applies only after backfill coverage is measured (§23, Phase B).

## 18. Layer 2 — Runtime write context

§9.4's mechanism 2, specified.

```php
final class RosterWriteContext
{
    private static bool $open = false;

    public static function allow(User $actor, callable $write): mixed
    {
        // actor authenticated, human, holds the roster permission;
        // opened only inside publish()'s transaction, after evaluate() passes
    }

    public static function isOpen(): bool { ... }
}
```

`ScheduleAssignment::boot()` hooks `creating`, `updating`, `deleting`, `saving`; each throws `UnauthorisedRosterWrite` when the context is closed. Sitting on model events catches every Eloquent route — mass assignment, `upsert`, relationship saves, tinker, console commands. **Raw query-builder and direct SQL bypass model events entirely**, which is exactly why Layers 3 and 4 exist; this layer alone does not satisfy the invariant.

Constraints on the context:

- Opened only inside `RosterPublicationService::publish()`, inside the existing transaction, **after** the rule engine returns a pass — so the invariant's two clauses are enforced by the same gate.
- Requires `auth()->user()` present and holding the roster permission (constant to verify — §23.3).
- A queued job cannot open it implicitly; it must carry an explicit human actor **and** the recommendation decision id. A bare user id in a job payload is not a human decision (§22.3).
- Denied attempts log at security level with a stack trace and are **never swallowed**. Production should read zero.

## 19. Layer 3 — CI architecture tests

§9.4's mechanism 1, specified. Pest `arch()`, PHPArkitect or Deptrac — whichever the team already runs:

1. AI-namespace classes must not depend on the `ScheduleAssignment` model at all. Give AI code a read-only query service or DTO, so the dependency is impossible rather than discouraged.
2. Only `RosterPublicationService` may reference `RosterWriteContext::allow`.
3. Only `RosterPublicationService` may call `create|save|update|delete|insert|upsert` on `ScheduleAssignment`.
4. AI-namespace classes must use the `ai` connection (§20).

Plus one behavioural test static analysis cannot express: **`RecommendationLifecycleService::apply()` performs zero writes to `schedule_assignments`** — asserted by row count and checksum either side of the call, not by reading the code. That test is what keeps the "validates only, no write" claim in Figure 1 honest as the code evolves.

This is the layer that survives staff turnover. A new developer need not know the rule; the build tells them.

## 20. Layer 4 — Database privilege separation

The only layer that is a real boundary, and the one §9.4 judged disproportionate. See §14.2 for why it is included.

- A second MySQL user, `hims_ai`: `SELECT` on `schedule_assignments`, `shifts`, `leave_requests`, `employees`; `INSERT`/`UPDATE` on `schedule_recommendations` only. **No write privilege on `schedule_assignments` in any form.**
- A matching `ai` connection in `config/database.php`; AI services declare `protected $connection = 'ai'`.
- Rule 4 of §19 stops AI code silently falling back to the default connection.

Trade-off to accept consciously: a violation surfaces as a SQL permission error, and the stack trace points at the query rather than the policy. Wrap it in a handler that reports it as a governing-rule violation so the message matches the cause.

This is also what makes the claim defensible to an external reader. "We wrote a guard" invites *what if someone removes the guard*. "The credentials that path runs under have no write privilege on that table" does not.

## 21. Layer 5 — Audit and conformance detection

- `schedule_assignment_audits`, append-only (no `UPDATE`/`DELETE` grant to the app user): assignment id, actor, `created_via`, `source_recommendation_id`, the rule-engine verdict snapshot, connection identity, timestamp.
- Extend `ScheduleComplianceService` — already the one component that writes on the read path — with conformance findings: assignments lacking human provenance; `legacy` rows surviving the backfill window; `UnauthorisedRosterWrite` events in the period.
- Alert on any denied write. Zero is the expected value; a non-zero value is the signal that a new path tried to cross the boundary.

Note the dependency this creates: conformance findings only surface when HR runs the compliance check on demand, since nothing triggers it automatically. If Layer 5 is to function as detection rather than as an archive, that component needs a schedule — which is a change to the as-is behaviour and should be raised deliberately, not assumed.

## 22. Where this design is genuinely weak

Four limitations. The first three are engineering; the fourth is the one that matters.

1. **The test and seeder hatch.** Factories, seeders and migrations must bypass the guard, and that hatch is the likeliest accidental hole. Guard it with an explicit non-production environment check and cover it with a test asserting it is unreachable in production.
2. **The guard is code, and code can be edited.** Layers 2 and 3 raise the cost of violation; they do not make it impossible. Layer 4 is what survives a deleted guard — the whole argument of §14.2.
3. **`created_by` can name a human who did not decide.** A queued job passing an arbitrary user id satisfies `NOT NULL` while representing no judgement at all. Mitigate by requiring the recommendation decision id alongside the actor, so authorship traces to a recorded decision rather than a bare foreign key.
4. **None of this makes human review meaningful.** These controls guarantee a human *authored* every assignment. They cannot guarantee the human *thought about it*. A manager who accepts every recommendation produces perfectly compliant records and functionally autonomous scheduling.

   That is a UI and process question, not an access-control one. The apply screen should show what the recommendation changed and why it was eligible, and the decision log should record modifications as readily as acceptances — the as-is architecture already distinguishes `applied` from `modified`, which makes the measurement available for free. **The accept-rate on recommendations is the metric to watch:** a rate near 100% is evidence the loop has become ceremonial. Worth stating in the write-up as a limitation rather than leaving a reader to find it.

## 23. Rollout, tests, and evidence

### 23.1 Phases

| Phase | Scope | Exit criteria |
|---|---|---|
| **A** | Provenance columns, nullable. Backfill from roster drafts. | Backfill coverage measured; `legacy` count known and explained. |
| **B** | `created_by` set NOT NULL. | No insert path in the codebase omits an actor. |
| **C** | Runtime guard in **report-only** mode — logs, throws nothing. | Two weeks with zero legitimate paths tripping it. Same discipline as Part A's shadow mode: prove the control before it can break production. |
| **D** | Guard enforcing. | Denied-write counter reads zero over a full roster cycle. |
| **E** | Architecture tests in CI. | Build fails on a deliberately introduced violation — verify the test actually catches it. |
| **F** | Database privilege separation. | AI connection provably lacks `INSERT`, asserted by integration test against real MySQL, not by inspection. |
| **G** | Audit table, conformance findings, alerting. | Conformance report shows 100% human-authored assignments. |

Phases A–B are independent of everything else and carry most of the structural value for the least work. They can ship ahead of Part A.

### 23.2 Tests and the evidence artefact

| Test | Asserts |
|---|---|
| `apply()` writes nothing | Row count and checksum unchanged across the call |
| `publish()` records authorship | `created_by` equals the authenticated user; `source_recommendation_id` set where applicable |
| Direct write outside context | `ScheduleAssignment::create()` throws `UnauthorisedRosterWrite` |
| Queued job without actor | Cannot open the write context |
| AI connection privileges | `INSERT` fails at the database, against a real MySQL instance |
| Gemini insight path | Performs zero writes of any kind |
| Conformance query | 100% of assignments carry human provenance |
| Production hatch | Seeder bypass unreachable when the environment is production |

Together these are the **evidence artefact**. The project lists "no autonomous scheduling without human approval" as an out-of-scope boundary; a boundary stated in prose is an assertion, a passing suite plus a conformance report is a verified property. For the write-up this is the difference between claiming human-in-the-loop and demonstrating it — subject to §22.4.

### 23.3 Assumptions to verify — Part B

1. The namespace and location of the AI services, and whether recommendation code sits behind a service boundary or is called inline from controllers.
2. The roster permission constant. The as-is document shows `workforce.manage` on leave cancellation and `hr.manage` on compliance; the publish-time permission is not stated.
3. That `publish()` already runs inside a DB transaction — the document says so, and the write context depends on it.
4. Whether queued jobs or scheduled commands touch scheduling today. Any that do need explicit actors before Phase D or they will begin failing.
5. Whether the application uses a single shared MySQL user (near-certain), and whether the deployment can support a second.
6. Whether `schedule_assignments` already carries `created_by` or audit columns these should extend rather than duplicate.
7. **Whether the "AI rotation" route in bulk fill is genuinely model-derived or a deterministic rotation algorithm.** The name implies AI; the behaviour may be arithmetic. This changes whether it belongs in §15.1 at all — worth settling before the classification hardens into documentation.

Item 7 is the one to settle first: it is cheap to answer and it determines whether the AI surface area is three components or four.

---

## 24. Traceability to the stated goals

The project's measurable goals are unchanged. This work bears on two of them:

- **100% scheduling conflict detection** — schedule-aware attendance adds *detection at the point of work*, which the roster-time rule engine cannot provide. It also makes the leave/published-shift contradiction (Seam 2) visible even though it does not resolve it.
- **10% reduction in premium-pay staffing** — overtime measured against the rostered shift end rather than office close is a precondition for trusting any premium-pay figure. Expect the baseline itself to move in Phase 2; the reduction target should be re-based against post-Phase-2 numbers rather than pre-change ones.

The remaining goals (90% perceived schedule fairness, 95% shift-fill rate) are unaffected by this change.

Part B bears on the first of those two more directly than Part A does. As §9.5 argues, **100% scheduling conflict detection presupposes the invariant**: if any path can write an assignment without running the rule engine, 100% detection is unattainable by construction, and no improvement to the rule engine recovers it — the engine is simply not on that path. Part A adds detection at the point of work; Part B is what makes the denominator trustworthy.

Out-of-scope boundaries are respected throughout, and Part B strengthens two of them from stated commitments into verified properties:

- *No autonomous scheduling without human approval* — enforced by §17's `created_by NOT NULL` and §18's write context, and evidenced by the §23.2 suite. Subject to the honest limitation in §22.4: the controls guarantee human authorship, not human judgement.
- *No AI-driven performance or disciplinary decisions* — untouched here, but the same five-layer pattern is the obvious candidate should the equivalent guarantee ever be wanted on attendance approval or timesheet review.

Neither part alters the five research questions, the five sub-modules, or the out-of-scope list; no biometric hardware development, no native mobile work, and no AI involvement in the resolution, refusal or publication decision.
