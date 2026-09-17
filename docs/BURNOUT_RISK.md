# Burnout risk indicator

A non-medical workload and rest indicator for every active employee, and the scheduling rules that act on it. It is built only from records the system already keeps: attendance, the published roster, and leave.

It does not diagnose burnout. It must not be used for disciplinary, performance, or promotion decisions. The weights and thresholds are application defaults for hospital HR to review, not hospital policy.

## Where it appears

| Screen | Who sees it | What it shows |
|---|---|---|
| **My workload & rest** card, on whichever dashboard the person lands on | Every employee, for themselves only, including HR managers, department heads and system administrators | Level, score, trend, the factors driving it, and one suggestion (file leave, set preferences, talk to a supervisor) |
| Organisation dashboard, **Closest to burnout** | HR managers for every department. Department heads for their own unit only | The five highest scores, the high, moderate and rising counts, and links into the tab |
| Workforce Analytics, **Burnout Risk** tab | Same as above | Everyone assessed, highest risk first, with live filters, a per-department breakdown and a 12-week sparkline |
| Workforce Analytics, **Overview** tab | Same as above | A count of high and moderate risk, linking to the tab. Counts only, never names |
| Schedule assignment modal (AI recommendation) | Anyone who can use the assistant | A burnout chip beside each candidate |
| Roster board (bulk scheduling) | Anyone who can build rosters | A marker beside anyone at moderate or high risk, and a Tier B panel for placements past a high-risk employee's limits |

**System administrators see only their own card.** They can open Workforce Analytics, but the Burnout Risk tab, the Overview counts and the dashboard watchlist are withheld from them. They run the platform, not the people in it. Being read-only, they cannot reach the scheduling screens that show other people's levels either. All of this hangs off one gate, `burnout.view-workforce` in `AppServiceProvider` (HR managers and department heads), and `BurnoutRiskService::workforce()` checks it too, so a new screen cannot leak the list by forgetting.

The Burnout Risk tab's filters are live: changing one refreshes the results in place and updates the URL, so refresh, bookmarks and Back all work. Without JavaScript the form submits normally.

The burnout counts are stripped from what the Overview's **Generate insights** button sends to Gemini. So are the factors in AI scheduling explanations: only the level goes out.

## How the score works

Each assessment reads the last 28 days, ending yesterday, and compares them with the 28 days before. Seven factors each score nothing at or below a floor and their full points at or above a ceiling, rising in a straight line between the two. The points add up to 100.

| Factor | Floor → ceiling | Points |
|---|---|---|
| Average weekly hours | 40 → 56 hours | 25 |
| Overtime in the window | 0 → 16 hours | 20 |
| Longest run of workdays | 5 → 8 days | 15 |
| Night shifts | 4 → 12 shifts | 10 |
| Quick returns under the minimum rest (12 h) | 0 → 3 times | 10 |
| Days since last approved leave | 90 → 180 days | 10 |
| Sick and emergency leave in the last 90 days | 1 → 4 requests | 10 |

- **Low** is below 35. **Moderate** is 35 to 59. **High** is 60 and up.
- **Trend** is rising or easing when the score moved 5 points or more from the window before. It is *new* when the employee was hired after that earlier window began.
- A worked day is a punch, or a rostered shift with no punch. That second rule overstates the load of someone who simply did not come in. That is the safer mistake, because a missing punch is common.
- Someone with no leave on record counts from their hire date, so a new starter is not scored as someone who has gone months without a break.

Everything above is in [config/burnout.php](../config/burnout.php) and can be tuned through `BURNOUT_*` environment variables.

## What scheduling does with it

Employees at **high** risk are *protected*. On top of every ordinary rule, protection keeps them to:

- 2 rest days a week (`BURNOUT_PROTECTED_DAYS_OFF`)
- 40 hours a week (`BURNOUT_PROTECTED_MAX_WEEKLY_HOURS`)
- 2 night shifts a week (`BURNOUT_PROTECTED_MAX_NIGHTS`)

| Path | Behaviour |
|---|---|
| **Bulk fill** | Protected employees are placed after everyone else, so a staffing ceiling fills with others first. Placements past their limits are skipped with a reason starting "High burnout risk". |
| **Rotation assistant** | Protected employees get the extra rest days. They are placed after the rest of the team and steered off night shifts, unless a night is still short of cover. |
| **Roster board** (Tier B) | A placement past a protected employee's limits stays on the board but is listed in a warning panel. Publishing requires a burnout justification, which is written to the assignment notes and the publish audit log. |
| **Single-shift recommendation** | Burnout risk is a 15-point factor. Eligibility went from 40 to 25 points, so the total is still 100. Separately, high-risk candidates are ranked after every other eligible candidate, whatever their points. They stay on the list with a warning. |

`BURNOUT_PROTECTION_ENABLED=false` switches every one of these off and leaves the indicator itself in place.

## Storage and running it

Assessments are stored one per employee per day in `burnout_risk_snapshots`. Every screen reads the indicator through `BurnoutRiskService::current()`, which returns today's stored assessment or makes it on the spot. Nothing depends on a scheduler running. The first viewer of the day on a machine that never runs one simply waits a moment longer.

`php artisan burnout:snapshot` assesses every active employee and deletes assessments older than `BURNOUT_RETENTION_DAYS` (365 by default). It is scheduled daily at 01:30 Manila time, so it only runs where `php artisan schedule:work` (or cron) is running. `--date=YYYY-MM-DD` assesses as of another day.

The whole population is read in three queries: attendance, roster, and leave. Scores are then worked out in memory, because this database is latency-bound. Do not add per-employee queries to `BurnoutMetricsCollector`.

## Deploying

A new migration creates `burnout_risk_snapshots`. On the shared database, run `php artisan migrate` once, as with any pull that adds a migration.

## Before relying on it

- Have hospital HR review the floors, ceilings, points and thresholds against real rosters.
- Agree the retention window with the Data Protection Officer. See [DATA_PRIVACY.md](DATA_PRIVACY.md).
- Tell staff the indicator exists, what it reads, and that it is not used for performance decisions.
