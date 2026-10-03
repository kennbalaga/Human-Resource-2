# Sunday roster import — 2026-10-04

Four SQL files that put a full day of shifts onto Sunday **4 October 2026** in
the HostForge `hrms-db`: **5 employees on Morning, 5 on Afternoon, 5 on Night**,
15 assignments in total.

Biometric enrolment is deliberately not a condition. The 15 people are rostered
whether or not their fingerprints are on the terminal yet, so enrolment can
happen afterwards in any order.

| File | What it does | Where to run it |
| --- | --- | --- |
| `2026-10-04_sunday_roster_preflight.sql` | Read-only. Shows whether the import can run and names the exact 15 people it will pick. | hrms-db SQL console (`>_`) |
| `2026-10-04_sunday_roster_import.sql` | The import. Inserts the 15 assignments and their audit rows. | **Import from repository** |
| `2026-10-04_sunday_roster_verify.sql` | Read-only. Confirms 15 rows, 5 per shift, and runs seven integrity checks. | hrms-db SQL console (`>_`) |
| `2026-10-04_sunday_roster_rollback.sql` | Undoes the import, and only the import. | hrms-db SQL console (`>_`) |

## Running it

1. Commit and push these files to `main`. HostForge's **Import from repository**
   reads the copy of the repo it keeps for builds, so a file that only exists
   locally is invisible to it.
2. Optional but worth it: paste `..._preflight.sql` into the hrms-db SQL console
   (the `>_` button next to the database) and read the three result sets. Every
   row in the first one should say `OK`, and the second lists the 15 names.
3. HostForge → Workspaces → WorkForce → Databases → `hrms-db` → **Import from
   repository** → application `hrms` → the import file.
4. Paste `..._verify.sql` into the console. Expect 15 assignments, 5 per shift,
   15 audit rows, and `0` on every row of the last result set.

## Why it is safe to run against the live database

* **It only inserts.** No `UPDATE`, no `DELETE`, no `DROP`, no schema change.
  Nothing already in `hrms-db` is modified.
* **One transaction.** All 15 rows land, or none do.
* **Nobody is hardcoded.** The employees are chosen by the database at import
  time, so the file cannot point at an id that means someone else here.
* **Running it twice changes nothing.** Each shift inserts only while it has
  nothing on 2026-10-04 yet.
* **It is reversible.** The rollback file is scoped to the date, the note the
  import stamps on every row, and `created_via = 'manual'` together.

## Who it picks, and who it refuses

Ordered by clinical departments first, then support, by employee id within each.
The first five go to Morning, the next five to Afternoon, the last five to Night.

Left out on purpose:

* anyone not `active`, or archived, or soft-deleted;
* **administrative departments** — Sunday is their standing rest day
  (`Department::restsOnSundays()`), and every fill path in the app refuses a
  Sunday shift for those units. This roster is clinical and support staff;
* anyone already rostered on **3, 4 or 5 October**. Skipping the neighbouring
  days is what keeps the 12-hour minimum rest between shifts and the
  6-consecutive-workday cap intact without having to reason about which shift
  the person already held;
* anyone with a scheduled day off or an approved leave covering the date;
* anyone whose department has a schedule lock over the date.

## Two things to know afterwards

* **The app will not let you edit this day.** `ScheduleService::firstEditableDate()`
  makes today and everything before it read-only — a roster for a day that has
  started is a record, not a plan. An approved shift swap is the only in-app way
  to move a shift on 2026-10-04. Use the rollback file if you need the day clear.
* **The night shift's rows carry `work_date = 2026-10-04`** even though the shift
  runs to 06:00 on the 5th. That is how every read in the app counts a night
  shift — by the date it starts.
