-- =============================================================================
-- Verification for the 2026-10-04 Sunday roster import. READ-ONLY.
-- Run after the import. Expect 3 shifts x 5 people = 15 assignments,
-- 15 audit rows, and no employee appearing twice.
--
-- Run this in the hrms-db SQL console (the >_ button on the database in
-- HostForge), not through "Import from repository" — that applies a file and
-- does not show you query results.
--
-- Plain SQL only: no window functions, no CTEs, no session variables, so it
-- runs the same on MariaDB as on MySQL.
-- =============================================================================

-- 1. The headline count.
SELECT
    (SELECT COUNT(*) FROM schedule_assignments WHERE work_date = '2026-10-04') AS assignments_on_sunday,
    (SELECT COUNT(DISTINCT employee_id) FROM schedule_assignments WHERE work_date = '2026-10-04') AS distinct_employees,
    -- Joined to the assignments rather than counted by date: the audit table
    -- is append-only, so a date that was imported and rolled back once still
    -- carries the older rows, and a bare count by date would overstate this.
    (SELECT COUNT(*) FROM schedule_assignment_audits t
       JOIN schedule_assignments a2 ON a2.id = t.schedule_assignment_id
      WHERE a2.work_date = '2026-10-04' AND t.action = 'created') AS audit_rows,
    IF((SELECT COUNT(*) FROM schedule_assignments WHERE work_date = '2026-10-04') =
       (SELECT COUNT(DISTINCT employee_id) FROM schedule_assignments WHERE work_date = '2026-10-04'),
       'OK — nobody double-booked', 'PROBLEM — an employee holds more than one shift') AS double_booking_check;

-- 2. Five per shift.
SELECT s.code, s.name AS shift,
       CONCAT(TIME_FORMAT(s.start_time, '%l:%i %p'), ' - ', TIME_FORMAT(s.end_time, '%l:%i %p')) AS hours,
       COUNT(*) AS employees,
       IF(COUNT(*) = 5, 'OK', 'CHECK — expected 5') AS result
FROM schedule_assignments a
JOIN shifts s ON s.id = a.shift_id
WHERE a.work_date = '2026-10-04'
GROUP BY s.code, s.name, s.start_time, s.end_time
ORDER BY s.start_time;

-- 3. The roster itself, as it will read on screen.
SELECT s.name AS shift, e.employee_number,
       CONCAT(e.first_name, ' ', e.last_name) AS employee,
       d.name AS department, p.title AS position,
       a.status, a.created_via
FROM schedule_assignments a
JOIN shifts s ON s.id = a.shift_id
JOIN employees e ON e.id = a.employee_id
JOIN departments d ON d.id = e.department_id
JOIN positions p ON p.id = e.position_id
WHERE a.work_date = '2026-10-04'
ORDER BY s.start_time, e.last_name, e.first_name;

-- 4. Integrity: no orphans, no missing author, no missing audit row.
SELECT 'Assignments with no author' AS check_name,
       COUNT(*) AS offending_rows
FROM schedule_assignments a
WHERE a.work_date = '2026-10-04' AND a.created_by IS NULL
UNION ALL
SELECT 'Assignments whose author is not a real user',
       COUNT(*)
FROM schedule_assignments a
WHERE a.work_date = '2026-10-04'
  AND NOT EXISTS (SELECT 1 FROM users u WHERE u.id = a.created_by)
UNION ALL
SELECT 'Assignments with no created audit row',
       COUNT(*)
FROM schedule_assignments a
WHERE a.work_date = '2026-10-04'
  AND NOT EXISTS (SELECT 1 FROM schedule_assignment_audits t
                  WHERE t.schedule_assignment_id = a.id AND t.action = 'created')
UNION ALL
SELECT 'Rostered employees who are inactive or archived',
       COUNT(*)
FROM schedule_assignments a
JOIN employees e ON e.id = a.employee_id
WHERE a.work_date = '2026-10-04'
  AND (e.employment_status <> 'active' OR e.archived_at IS NOT NULL OR e.deleted_at IS NOT NULL)
UNION ALL
SELECT 'Rostered employees who also have a day off that date',
       COUNT(*)
FROM schedule_assignments a
WHERE a.work_date = '2026-10-04'
  AND EXISTS (SELECT 1 FROM schedule_day_offs o
              WHERE o.employee_id = a.employee_id AND o.work_date = '2026-10-04')
UNION ALL
SELECT 'Rostered employees who are on approved leave',
       COUNT(*)
FROM schedule_assignments a
WHERE a.work_date = '2026-10-04'
  AND EXISTS (SELECT 1 FROM leave_requests l
              WHERE l.employee_id = a.employee_id AND l.status = 'approved'
                AND l.start_date <= '2026-10-04' AND l.end_date >= '2026-10-04')
UNION ALL
SELECT 'Rostered employees with a neighbouring-day shift (rest risk)',
       COUNT(*)
FROM schedule_assignments a
WHERE a.work_date = '2026-10-04'
  AND EXISTS (SELECT 1 FROM schedule_assignments n
              WHERE n.employee_id = a.employee_id
                AND n.work_date IN ('2026-10-03', '2026-10-05'));
