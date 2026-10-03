-- =============================================================================
-- Undo for the 2026-10-03 night shift import.
--
-- Scoped three ways at once: the work date, the note the import stamps on every
-- row, and created_via = 'manual'. A schedule made in the app will not carry
-- that exact note, so it is not touched.
--
-- Run in the hrms-db SQL console (the >_ button on the database) so you can
-- read the list it prints before the rows go.
-- =============================================================================

START TRANSACTION;

SELECT a.id, s.name AS shift, e.employee_number,
       CONCAT(e.first_name, ' ', e.last_name) AS employee
FROM schedule_assignments a
JOIN shifts s ON s.id = a.shift_id
JOIN employees e ON e.id = a.employee_id
WHERE a.work_date = '2026-10-03'
  AND a.created_via = 'manual'
  AND a.notes = 'Imported for biometric terminal testing (2026-10-03 night).'
ORDER BY e.last_name;

-- Record the removal while the assignments still exist to be read.
INSERT INTO schedule_assignment_audits
    (schedule_assignment_id, employee_id, work_date, action, actor_id,
     created_via, unattended, context, created_at)
SELECT a.id, a.employee_id, a.work_date, 'deleted', a.created_by,
       a.created_via, 1, NULL, NOW()
FROM schedule_assignments a
WHERE a.work_date = '2026-10-03'
  AND a.created_via = 'manual'
  AND a.notes = 'Imported for biometric terminal testing (2026-10-03 night).';

-- A punch that already bound to one of these keeps its attendance record: the
-- foreign key is nullOnDelete, so the binding clears itself and nothing here
-- has to touch attendance_records.
DELETE FROM schedule_assignments
WHERE work_date = '2026-10-03'
  AND created_via = 'manual'
  AND notes = 'Imported for biometric terminal testing (2026-10-03 night).';

COMMIT;

-- Should come back 0.
SELECT COUNT(*) AS remaining_imported_rows
FROM schedule_assignments
WHERE work_date = '2026-10-03'
  AND notes = 'Imported for biometric terminal testing (2026-10-03 night).';
