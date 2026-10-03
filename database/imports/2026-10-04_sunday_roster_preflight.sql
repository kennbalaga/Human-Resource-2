-- =============================================================================
-- Preflight for the 2026-10-04 Sunday roster import. READ-ONLY — this file
-- changes nothing.
--
-- Run this in the hrms-db SQL console (the >_ button on the database in
-- HostForge), not through "Import from repository" — that applies a file and
-- does not show you query results.
--
-- Plain SQL only: no window functions, no CTEs, no session variables, so it
-- runs the same on MariaDB as on MySQL.
-- =============================================================================

-- 1. Does everything the import depends on exist, and is the Sunday really empty?
--    Every row should read OK. Anything else, stop and fix that first.
SELECT 'Morning shift template' AS requirement,
       IF(EXISTS (SELECT 1 FROM shifts WHERE code = 'MORNING-0600' AND is_active = 1 AND deleted_at IS NULL),
          'OK', 'MISSING — shift MORNING-0600 is absent or inactive') AS result
UNION ALL
SELECT 'Afternoon shift template',
       IF(EXISTS (SELECT 1 FROM shifts WHERE code = 'AFTERNOON-1400' AND is_active = 1 AND deleted_at IS NULL),
          'OK', 'MISSING — shift AFTERNOON-1400 is absent or inactive')
UNION ALL
SELECT 'Night shift template',
       IF(EXISTS (SELECT 1 FROM shifts WHERE code = 'NIGHT-2200' AND is_active = 1 AND deleted_at IS NULL),
          'OK', 'MISSING — shift NIGHT-2200 is absent or inactive')
UNION ALL
SELECT 'Author for created_by (NOT NULL)',
       IF((SELECT COUNT(*) FROM users) > 0,
          CONCAT('OK — user id ', COALESCE(
              (SELECT u.id FROM users u WHERE u.email = 'admin@hrms.local' ORDER BY u.id LIMIT 1),
              (SELECT MIN(u2.id) FROM users u2))),
          'MISSING — no users exist, created_by cannot be filled')
UNION ALL
SELECT '2026-10-04 is empty',
       IF((SELECT COUNT(*) FROM schedule_assignments WHERE work_date = '2026-10-04') = 0,
          'OK — nothing rostered yet',
          CONCAT('ALREADY HAS ',
                 (SELECT COUNT(*) FROM schedule_assignments WHERE work_date = '2026-10-04'),
                 ' assignment(s) — the import will skip any shift that is already filled'))
UNION ALL
SELECT '2026-10-04 is not a holiday',
       IF((SELECT COUNT(*) FROM holidays WHERE date = '2026-10-04') = 0,
          'OK', CONCAT('HOLIDAY — ', (SELECT name FROM holidays WHERE date = '2026-10-04')))
UNION ALL
SELECT 'Eligible employees (15 needed)',
       IF((SELECT COUNT(*) FROM employees e JOIN departments d ON d.id = e.department_id
           WHERE e.employment_status = 'active' AND e.archived_at IS NULL AND e.deleted_at IS NULL
             AND d.category <> 'administrative'
             AND NOT EXISTS (SELECT 1 FROM schedule_assignments a WHERE a.employee_id = e.id AND a.work_date BETWEEN '2026-10-03' AND '2026-10-05')
             AND NOT EXISTS (SELECT 1 FROM schedule_day_offs o WHERE o.employee_id = e.id AND o.work_date = '2026-10-04')
             AND NOT EXISTS (SELECT 1 FROM leave_requests l WHERE l.employee_id = e.id AND l.status = 'approved' AND l.start_date <= '2026-10-04' AND l.end_date >= '2026-10-04')
             AND NOT EXISTS (SELECT 1 FROM schedule_locks k WHERE k.department_id = e.department_id AND k.unlocked_at IS NULL AND k.start_date <= '2026-10-04' AND k.end_date >= '2026-10-04')
          ) >= 15,
          CONCAT('OK — ', (SELECT COUNT(*) FROM employees e JOIN departments d ON d.id = e.department_id
           WHERE e.employment_status = 'active' AND e.archived_at IS NULL AND e.deleted_at IS NULL
             AND d.category <> 'administrative'
             AND NOT EXISTS (SELECT 1 FROM schedule_assignments a WHERE a.employee_id = e.id AND a.work_date BETWEEN '2026-10-03' AND '2026-10-05')
             AND NOT EXISTS (SELECT 1 FROM schedule_day_offs o WHERE o.employee_id = e.id AND o.work_date = '2026-10-04')
             AND NOT EXISTS (SELECT 1 FROM leave_requests l WHERE l.employee_id = e.id AND l.status = 'approved' AND l.start_date <= '2026-10-04' AND l.end_date >= '2026-10-04')
             AND NOT EXISTS (SELECT 1 FROM schedule_locks k WHERE k.department_id = e.department_id AND k.unlocked_at IS NULL AND k.start_date <= '2026-10-04' AND k.end_date >= '2026-10-04')
          ), ' eligible'),
          'TOO FEW — fewer than 15 eligible employees; the import would fill partially');

-- 2. Exactly who the import will roster, and onto which shift.
--    Same filter and same ORDER BY the import uses, sliced three ways —
--    the import reaches the second five because the first five are no longer
--    free once their Morning row exists.
(SELECT 'Morning Shift (06:00-14:00)' AS will_be_assigned_to,
        e.employee_number, CONCAT(e.first_name, ' ', e.last_name) AS employee_name,
        d.name AS department, p.title AS position
 FROM employees e
 JOIN departments d ON d.id = e.department_id
 JOIN positions p ON p.id = e.position_id
 WHERE e.employment_status = 'active' AND e.archived_at IS NULL AND e.deleted_at IS NULL
   AND d.category <> 'administrative'
   AND NOT EXISTS (SELECT 1 FROM schedule_assignments a WHERE a.employee_id = e.id AND a.work_date BETWEEN '2026-10-03' AND '2026-10-05')
   AND NOT EXISTS (SELECT 1 FROM schedule_day_offs o WHERE o.employee_id = e.id AND o.work_date = '2026-10-04')
   AND NOT EXISTS (SELECT 1 FROM leave_requests l WHERE l.employee_id = e.id AND l.status = 'approved' AND l.start_date <= '2026-10-04' AND l.end_date >= '2026-10-04')
   AND NOT EXISTS (SELECT 1 FROM schedule_locks k WHERE k.department_id = e.department_id AND k.unlocked_at IS NULL AND k.start_date <= '2026-10-04' AND k.end_date >= '2026-10-04')
 ORDER BY (d.category = 'clinical') DESC, e.id
 LIMIT 5)
UNION ALL
(SELECT 'Afternoon Shift (14:00-22:00)',
        e.employee_number, CONCAT(e.first_name, ' ', e.last_name),
        d.name, p.title
 FROM employees e
 JOIN departments d ON d.id = e.department_id
 JOIN positions p ON p.id = e.position_id
 WHERE e.employment_status = 'active' AND e.archived_at IS NULL AND e.deleted_at IS NULL
   AND d.category <> 'administrative'
   AND NOT EXISTS (SELECT 1 FROM schedule_assignments a WHERE a.employee_id = e.id AND a.work_date BETWEEN '2026-10-03' AND '2026-10-05')
   AND NOT EXISTS (SELECT 1 FROM schedule_day_offs o WHERE o.employee_id = e.id AND o.work_date = '2026-10-04')
   AND NOT EXISTS (SELECT 1 FROM leave_requests l WHERE l.employee_id = e.id AND l.status = 'approved' AND l.start_date <= '2026-10-04' AND l.end_date >= '2026-10-04')
   AND NOT EXISTS (SELECT 1 FROM schedule_locks k WHERE k.department_id = e.department_id AND k.unlocked_at IS NULL AND k.start_date <= '2026-10-04' AND k.end_date >= '2026-10-04')
 ORDER BY (d.category = 'clinical') DESC, e.id
 LIMIT 5 OFFSET 5)
UNION ALL
(SELECT 'Night Shift (22:00-06:00)',
        e.employee_number, CONCAT(e.first_name, ' ', e.last_name),
        d.name, p.title
 FROM employees e
 JOIN departments d ON d.id = e.department_id
 JOIN positions p ON p.id = e.position_id
 WHERE e.employment_status = 'active' AND e.archived_at IS NULL AND e.deleted_at IS NULL
   AND d.category <> 'administrative'
   AND NOT EXISTS (SELECT 1 FROM schedule_assignments a WHERE a.employee_id = e.id AND a.work_date BETWEEN '2026-10-03' AND '2026-10-05')
   AND NOT EXISTS (SELECT 1 FROM schedule_day_offs o WHERE o.employee_id = e.id AND o.work_date = '2026-10-04')
   AND NOT EXISTS (SELECT 1 FROM leave_requests l WHERE l.employee_id = e.id AND l.status = 'approved' AND l.start_date <= '2026-10-04' AND l.end_date >= '2026-10-04')
   AND NOT EXISTS (SELECT 1 FROM schedule_locks k WHERE k.department_id = e.department_id AND k.unlocked_at IS NULL AND k.start_date <= '2026-10-04' AND k.end_date >= '2026-10-04')
 ORDER BY (d.category = 'clinical') DESC, e.id
 LIMIT 5 OFFSET 10);

-- 3. What is already on the Sunday, if anything.
SELECT s.name AS shift, COUNT(*) AS existing_rows
FROM schedule_assignments a
JOIN shifts s ON s.id = a.shift_id
WHERE a.work_date = '2026-10-04'
GROUP BY s.name
ORDER BY s.name;
