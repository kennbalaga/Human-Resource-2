-- =============================================================================
-- Night shift for Saturday 2026-10-03 — 2 employees
--
-- NIGHT-2200 runs 22:00 on the 3rd through 06:00 on the 4th, so this is the
-- shift that is actually in progress during the small hours of Sunday the 4th.
-- That is the row a punch made overnight binds to: ShiftResolver looks at
-- work_date from the day before the punch to the day after, and the shift's
-- own date is the date it starts.
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
-- Same safety properties as the 2026-10-04 import: inserts only, one
-- transaction, nobody hardcoded, re-running adds nothing, and the companion
-- rollback undoes exactly this and nothing else.
--
-- To change the headcount, change the LIMIT at the bottom of the first
-- statement. Nothing else in the file depends on it being 2.
-- =============================================================================

START TRANSACTION;

INSERT INTO schedule_assignments
    (employee_id, shift_id, recurring_schedule_id, work_date, status, notes,
     created_by, created_via, source_recommendation_id, created_at, updated_at)
SELECT
    picked.id,
    (SELECT s.id FROM shifts s WHERE s.code = 'NIGHT-2200' AND s.is_active = 1 AND s.deleted_at IS NULL),
    NULL,
    '2026-10-03',
    'scheduled',
    'Imported for biometric terminal testing (2026-10-03 night).',
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
      -- Sunday is an administrative office's standing rest day and a night
      -- shift is not their shift at all — see Department::restsOnSundays().
      -- Clinical first, then support.
      AND d.category <> 'administrative'
      -- Nothing on the 2nd, 3rd or 4th. The 4th matters most here: a night
      -- that ends at 06:00 on the 4th leaves no rest at all before a Morning
      -- Shift starting 06:00 that same day, so anyone already rostered then
      -- is not a candidate for this.
      AND NOT EXISTS (
          SELECT 1 FROM schedule_assignments a
          WHERE a.employee_id = e.id
            AND a.work_date BETWEEN '2026-10-02' AND '2026-10-04'
      )
      AND NOT EXISTS (
          SELECT 1 FROM schedule_day_offs o
          WHERE o.employee_id = e.id AND o.work_date = '2026-10-03'
      )
      AND NOT EXISTS (
          SELECT 1 FROM leave_requests l
          WHERE l.employee_id = e.id
            AND l.status = 'approved'
            AND l.start_date <= '2026-10-03'
            AND l.end_date >= '2026-10-03'
      )
      AND NOT EXISTS (
          SELECT 1 FROM schedule_locks k
          WHERE k.department_id = e.department_id
            AND k.unlocked_at IS NULL
            AND k.start_date <= '2026-10-03'
            AND k.end_date >= '2026-10-03'
      )
      -- The re-run guard. Evaluated once while this derived table is
      -- materialised, before a single row is inserted.
      AND (
          SELECT COUNT(*) FROM schedule_assignments g
          WHERE g.work_date = '2026-10-03'
            AND g.shift_id = (SELECT s2.id FROM shifts s2 WHERE s2.code = 'NIGHT-2200')
      ) = 0
    ORDER BY (d.category = 'clinical') DESC, e.id
    LIMIT 2
) AS picked;

-- The provenance trail the model would have written, so this does not land on
-- the compliance report as an assignment that predates audit tracking.
INSERT INTO schedule_assignment_audits
    (schedule_assignment_id, employee_id, work_date, action, actor_id,
     created_via, unattended, context, created_at)
SELECT
    a.id, a.employee_id, a.work_date, 'created', a.created_by,
    a.created_via, 1, NULL, NOW()
FROM schedule_assignments a
WHERE a.work_date = '2026-10-03'
  AND a.notes = 'Imported for biometric terminal testing (2026-10-03 night).'
  AND NOT EXISTS (
      SELECT 1 FROM schedule_assignment_audits t
      WHERE t.schedule_assignment_id = a.id AND t.action = 'created'
  );

COMMIT;
