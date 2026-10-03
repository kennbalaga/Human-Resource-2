-- =============================================================================
-- Undo for the 2026-10-04 Sunday roster import.
--
-- Deletes ONLY the rows that import created. It is scoped three ways at once:
-- the work date, the notes marker the import stamps on every row, and
-- created_via = 'manual'. A schedule someone creates in the app will not carry
-- that exact note, so it is not touched.
--
-- The audit rows are left in place on purpose: schedule_assignment_audits is
-- append-only by design, and a 'deleted' row is added below instead, which is
-- what the app itself would write.
--
-- Best run in the hrms-db SQL console (the >_ button on the database in
-- HostForge) so you can read the list of rows it prints before they go.
-- Plain SQL only, so it runs the same on MariaDB as on MySQL.
-- =============================================================================

START TRANSACTION;

-- See what is about to go, before it goes.
SELECT a.id, s.name AS shift, e.employee_number,
       CONCAT(e.first_name, ' ', e.last_name) AS employee
FROM schedule_assignments a
JOIN shifts s ON s.id = a.shift_id
JOIN employees e ON e.id = a.employee_id
WHERE a.work_date = '2026-10-04'
  AND a.created_via = 'manual'
  AND a.notes = 'Imported for biometric terminal testing (2026-10-04).'
ORDER BY s.start_time, e.last_name;

-- Record the removal first, while the assignments still exist to be read.
INSERT INTO schedule_assignment_audits
    (schedule_assignment_id, employee_id, work_date, action, actor_id,
     created_via, unattended, context, created_at)
SELECT a.id, a.employee_id, a.work_date, 'deleted', a.created_by,
       a.created_via, 1, NULL, NOW()
FROM schedule_assignments a
WHERE a.work_date = '2026-10-04'
  AND a.created_via = 'manual'
  AND a.notes = 'Imported for biometric terminal testing (2026-10-04).';

DELETE FROM schedule_assignments
WHERE work_date = '2026-10-04'
  AND created_via = 'manual'
  AND notes = 'Imported for biometric terminal testing (2026-10-04).';

COMMIT;

-- Should come back 0.
SELECT COUNT(*) AS remaining_imported_rows
FROM schedule_assignments
WHERE work_date = '2026-10-04'
  AND notes = 'Imported for biometric terminal testing (2026-10-04).';
