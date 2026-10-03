-- =============================================================================
-- Sunday roster import — 2026-10-04 (Asia/Manila)
--
-- Fills an empty Sunday with 15 shift assignments: 5 Morning, 5 Afternoon,
-- 5 Night.
--
-- Written for HostForge → Databases → hrms-db → Import from repository,
-- pointed at the `hrms` application. HostForge reads this file from the copy
-- of the repo it keeps for builds, so it has to be committed and pushed to
-- main before the import can see it.
--
-- hrms-db is MariaDB, so this file sticks to plain SQL that both MariaDB and
-- MySQL accept: no window functions, no CTEs, no stored procedures, no
-- DELIMITER, no session variables, no temporary tables.
--
-- Run the WHOLE file in one go. It is one transaction: either all 15 rows
-- land or none do.
--
-- Safety properties, in case this is ever read before it is trusted:
--   * It only INSERTs. No UPDATE, no DELETE, no DDL, no schema change.
--   * No session variables and no temporary tables, so it does not care
--     whether the importer runs each statement on its own connection.
--   * Every employee is chosen by the database at import time, not by a
--     hardcoded id, so it cannot point at a row that does not exist here.
--   * Each shift inserts only while it has nothing on this date yet, so
--     running the file twice adds nothing the second time.
--   * It picks nobody who is inactive, archived, soft-deleted, on approved
--     leave, on a day off, already rostered on 2026-10-03/04/05, or in a
--     department whose schedule is locked.
--   * Biometric enrolment is deliberately NOT considered — these 15 people
--     are rostered whether or not their fingerprints are on the device yet.
--
-- Run 2026-10-04_sunday_roster_preflight.sql first to see what it will do,
-- and 2026-10-04_sunday_roster_rollback.sql to undo it.
-- =============================================================================

START TRANSACTION;

-- -----------------------------------------------------------------------------
-- Morning Shift — 06:00 to 14:00
-- -----------------------------------------------------------------------------
INSERT INTO schedule_assignments
    (employee_id, shift_id, recurring_schedule_id, work_date, status, notes,
     created_by, created_via, source_recommendation_id, created_at, updated_at)
SELECT
    picked.id,
    (SELECT s.id FROM shifts s WHERE s.code = 'MORNING-0600' AND s.is_active = 1 AND s.deleted_at IS NULL),
    NULL,
    '2026-10-04',
    'scheduled',
    'Imported for biometric terminal testing (2026-10-04).',
    COALESCE(
        (SELECT u.id FROM users u WHERE u.email = 'admin@hrms.local' ORDER BY u.id LIMIT 1),
        (SELECT MIN(u2.id) FROM users u2)
    ),
    'manual',
    NULL,
    NOW(),
    NOW()
FROM (
    SELECT e.id
    FROM employees e
    JOIN departments d ON d.id = e.department_id
    WHERE e.employment_status = 'active'
      AND e.archived_at IS NULL
      AND e.deleted_at IS NULL
      -- Sunday is an administrative office's standing rest day, not a day
      -- their roster happens to leave blank — see Department::restsOnSundays().
      -- Every fill path in the app refuses a Sunday shift for those units, so
      -- this one does too. A clinical ward runs every day and a support unit
      -- covers one, so the roster is clinical first, then support.
      AND d.category <> 'administrative'
      -- Nothing on the day itself, and nothing on either neighbouring day:
      -- that keeps the 12-hour minimum rest and the 6-consecutive-workday
      -- cap intact without having to reason about which shift they held.
      AND NOT EXISTS (
          SELECT 1 FROM schedule_assignments a
          WHERE a.employee_id = e.id
            AND a.work_date BETWEEN '2026-10-03' AND '2026-10-05'
      )
      AND NOT EXISTS (
          SELECT 1 FROM schedule_day_offs o
          WHERE o.employee_id = e.id AND o.work_date = '2026-10-04'
      )
      AND NOT EXISTS (
          SELECT 1 FROM leave_requests l
          WHERE l.employee_id = e.id
            AND l.status = 'approved'
            AND l.start_date <= '2026-10-04'
            AND l.end_date >= '2026-10-04'
      )
      AND NOT EXISTS (
          SELECT 1 FROM schedule_locks k
          WHERE k.department_id = e.department_id
            AND k.unlocked_at IS NULL
            AND k.start_date <= '2026-10-04'
            AND k.end_date >= '2026-10-04'
      )
      -- The re-run guard. Evaluated once while this derived table is
      -- materialised, before a single row is inserted.
      AND (
          SELECT COUNT(*) FROM schedule_assignments g
          WHERE g.work_date = '2026-10-04'
            AND g.shift_id = (SELECT s2.id FROM shifts s2 WHERE s2.code = 'MORNING-0600')
      ) = 0
    ORDER BY (d.category = 'clinical') DESC, e.id
    LIMIT 5
) AS picked;

-- -----------------------------------------------------------------------------
-- Afternoon Shift — 14:00 to 22:00
-- The five people taken above now hold a 2026-10-04 row, so the same filter
-- skips them here and this statement reaches the next five.
-- -----------------------------------------------------------------------------
INSERT INTO schedule_assignments
    (employee_id, shift_id, recurring_schedule_id, work_date, status, notes,
     created_by, created_via, source_recommendation_id, created_at, updated_at)
SELECT
    picked.id,
    (SELECT s.id FROM shifts s WHERE s.code = 'AFTERNOON-1400' AND s.is_active = 1 AND s.deleted_at IS NULL),
    NULL,
    '2026-10-04',
    'scheduled',
    'Imported for biometric terminal testing (2026-10-04).',
    COALESCE(
        (SELECT u.id FROM users u WHERE u.email = 'admin@hrms.local' ORDER BY u.id LIMIT 1),
        (SELECT MIN(u2.id) FROM users u2)
    ),
    'manual',
    NULL,
    NOW(),
    NOW()
FROM (
    SELECT e.id
    FROM employees e
    JOIN departments d ON d.id = e.department_id
    WHERE e.employment_status = 'active'
      AND e.archived_at IS NULL
      AND e.deleted_at IS NULL
      AND d.category <> 'administrative'
      AND NOT EXISTS (
          SELECT 1 FROM schedule_assignments a
          WHERE a.employee_id = e.id
            AND a.work_date BETWEEN '2026-10-03' AND '2026-10-05'
      )
      AND NOT EXISTS (
          SELECT 1 FROM schedule_day_offs o
          WHERE o.employee_id = e.id AND o.work_date = '2026-10-04'
      )
      AND NOT EXISTS (
          SELECT 1 FROM leave_requests l
          WHERE l.employee_id = e.id
            AND l.status = 'approved'
            AND l.start_date <= '2026-10-04'
            AND l.end_date >= '2026-10-04'
      )
      AND NOT EXISTS (
          SELECT 1 FROM schedule_locks k
          WHERE k.department_id = e.department_id
            AND k.unlocked_at IS NULL
            AND k.start_date <= '2026-10-04'
            AND k.end_date >= '2026-10-04'
      )
      AND (
          SELECT COUNT(*) FROM schedule_assignments g
          WHERE g.work_date = '2026-10-04'
            AND g.shift_id = (SELECT s2.id FROM shifts s2 WHERE s2.code = 'AFTERNOON-1400')
      ) = 0
    ORDER BY (d.category = 'clinical') DESC, e.id
    LIMIT 5
) AS picked;

-- -----------------------------------------------------------------------------
-- Night Shift — 22:00 to 06:00 the next morning
-- work_date stays 2026-10-04: the date a night shift belongs to is the date
-- it starts, which is how every read in the app counts it.
-- -----------------------------------------------------------------------------
INSERT INTO schedule_assignments
    (employee_id, shift_id, recurring_schedule_id, work_date, status, notes,
     created_by, created_via, source_recommendation_id, created_at, updated_at)
SELECT
    picked.id,
    (SELECT s.id FROM shifts s WHERE s.code = 'NIGHT-2200' AND s.is_active = 1 AND s.deleted_at IS NULL),
    NULL,
    '2026-10-04',
    'scheduled',
    'Imported for biometric terminal testing (2026-10-04).',
    COALESCE(
        (SELECT u.id FROM users u WHERE u.email = 'admin@hrms.local' ORDER BY u.id LIMIT 1),
        (SELECT MIN(u2.id) FROM users u2)
    ),
    'manual',
    NULL,
    NOW(),
    NOW()
FROM (
    SELECT e.id
    FROM employees e
    JOIN departments d ON d.id = e.department_id
    WHERE e.employment_status = 'active'
      AND e.archived_at IS NULL
      AND e.deleted_at IS NULL
      AND d.category <> 'administrative'
      AND NOT EXISTS (
          SELECT 1 FROM schedule_assignments a
          WHERE a.employee_id = e.id
            AND a.work_date BETWEEN '2026-10-03' AND '2026-10-05'
      )
      AND NOT EXISTS (
          SELECT 1 FROM schedule_day_offs o
          WHERE o.employee_id = e.id AND o.work_date = '2026-10-04'
      )
      AND NOT EXISTS (
          SELECT 1 FROM leave_requests l
          WHERE l.employee_id = e.id
            AND l.status = 'approved'
            AND l.start_date <= '2026-10-04'
            AND l.end_date >= '2026-10-04'
      )
      AND NOT EXISTS (
          SELECT 1 FROM schedule_locks k
          WHERE k.department_id = e.department_id
            AND k.unlocked_at IS NULL
            AND k.start_date <= '2026-10-04'
            AND k.end_date >= '2026-10-04'
      )
      AND (
          SELECT COUNT(*) FROM schedule_assignments g
          WHERE g.work_date = '2026-10-04'
            AND g.shift_id = (SELECT s2.id FROM shifts s2 WHERE s2.code = 'NIGHT-2200')
      ) = 0
    ORDER BY (d.category = 'clinical') DESC, e.id
    LIMIT 5
) AS picked;

-- -----------------------------------------------------------------------------
-- The provenance trail.
--
-- The app writes one 'created' audit row per assignment through the model, and
-- the HR compliance review raises "assignment(s) that predate audit tracking"
-- for any assignment missing one. A raw import bypasses the model, so the rows
-- are written here instead — otherwise this roster would show up as a warning
-- on the compliance report. `unattended` is 1 because no one was signed in.
-- -----------------------------------------------------------------------------
INSERT INTO schedule_assignment_audits
    (schedule_assignment_id, employee_id, work_date, action, actor_id,
     created_via, unattended, context, created_at)
SELECT
    a.id, a.employee_id, a.work_date, 'created', a.created_by,
    a.created_via, 1, NULL, NOW()
FROM schedule_assignments a
WHERE a.work_date = '2026-10-04'
  AND a.notes = 'Imported for biometric terminal testing (2026-10-04).'
  AND NOT EXISTS (
      SELECT 1 FROM schedule_assignment_audits t
      WHERE t.schedule_assignment_id = a.id AND t.action = 'created'
  );

COMMIT;
