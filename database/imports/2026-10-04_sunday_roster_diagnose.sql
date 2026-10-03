-- =============================================================================
-- Why is 2026-10-04 still empty on screen?
--
-- READ-ONLY. Run in the hrms-db SQL console (the >_ button on the database).
-- It separates the two possible answers: the import wrote nothing, or it wrote
-- rows that the screen is not showing you.
--
-- Plain SQL only, so it runs the same on MariaDB as on MySQL.
-- =============================================================================

-- 1. The answer to "did anything land?", and if not, what stopped it.
SELECT 'Database thinks today is' AS fact,
       CAST(CURDATE() AS CHAR) AS value
UNION ALL
SELECT 'Assignments on 2026-10-04 (any source)',
       CAST((SELECT COUNT(*) FROM schedule_assignments WHERE work_date = '2026-10-04') AS CHAR)
UNION ALL
SELECT 'Of those, written by this import',
       CAST((SELECT COUNT(*) FROM schedule_assignments
              WHERE work_date = '2026-10-04'
                AND notes = 'Imported for biometric terminal testing (2026-10-04).') AS CHAR)
UNION ALL
SELECT 'Of those, status = scheduled (what the board reads)',
       CAST((SELECT COUNT(*) FROM schedule_assignments
              WHERE work_date = '2026-10-04' AND status = 'scheduled') AS CHAR)
UNION ALL
SELECT 'Shift templates found (need 3)',
       CAST((SELECT COUNT(*) FROM shifts
              WHERE code IN ('MORNING-0600','AFTERNOON-1400','NIGHT-2200')
                AND is_active = 1 AND deleted_at IS NULL) AS CHAR)
UNION ALL
SELECT 'Active, non-administrative employees in total',
       CAST((SELECT COUNT(*) FROM employees e JOIN departments d ON d.id = e.department_id
              WHERE e.employment_status = 'active' AND e.archived_at IS NULL AND e.deleted_at IS NULL
                AND d.category <> 'administrative') AS CHAR)
UNION ALL
SELECT 'Still eligible right now (import needs 15)',
       CAST((SELECT COUNT(*) FROM employees e JOIN departments d ON d.id = e.department_id
              WHERE e.employment_status = 'active' AND e.archived_at IS NULL AND e.deleted_at IS NULL
                AND d.category <> 'administrative'
                AND NOT EXISTS (SELECT 1 FROM schedule_assignments a WHERE a.employee_id = e.id AND a.work_date BETWEEN '2026-10-03' AND '2026-10-05')
                AND NOT EXISTS (SELECT 1 FROM schedule_day_offs o WHERE o.employee_id = e.id AND o.work_date = '2026-10-04')
                AND NOT EXISTS (SELECT 1 FROM leave_requests l WHERE l.employee_id = e.id AND l.status = 'approved' AND l.start_date <= '2026-10-04' AND l.end_date >= '2026-10-04')
                AND NOT EXISTS (SELECT 1 FROM schedule_locks k WHERE k.department_id = e.department_id AND k.unlocked_at IS NULL AND k.start_date <= '2026-10-04' AND k.end_date >= '2026-10-04')
             ) AS CHAR)
UNION ALL
SELECT '  ...held back: already rostered on Oct 3 (Sat)',
       CAST((SELECT COUNT(*) FROM employees e JOIN departments d ON d.id = e.department_id
              WHERE e.employment_status = 'active' AND e.archived_at IS NULL AND e.deleted_at IS NULL
                AND d.category <> 'administrative'
                AND EXISTS (SELECT 1 FROM schedule_assignments a WHERE a.employee_id = e.id AND a.work_date = '2026-10-03')) AS CHAR)
UNION ALL
SELECT '  ...held back: already rostered on Oct 5 (Mon)',
       CAST((SELECT COUNT(*) FROM employees e JOIN departments d ON d.id = e.department_id
              WHERE e.employment_status = 'active' AND e.archived_at IS NULL AND e.deleted_at IS NULL
                AND d.category <> 'administrative'
                AND EXISTS (SELECT 1 FROM schedule_assignments a WHERE a.employee_id = e.id AND a.work_date = '2026-10-05')) AS CHAR)
UNION ALL
SELECT '  ...held back: day off, approved leave, or locked unit',
       CAST((SELECT COUNT(*) FROM employees e JOIN departments d ON d.id = e.department_id
              WHERE e.employment_status = 'active' AND e.archived_at IS NULL AND e.deleted_at IS NULL
                AND d.category <> 'administrative'
                AND (EXISTS (SELECT 1 FROM schedule_day_offs o WHERE o.employee_id = e.id AND o.work_date = '2026-10-04')
                  OR EXISTS (SELECT 1 FROM leave_requests l WHERE l.employee_id = e.id AND l.status = 'approved' AND l.start_date <= '2026-10-04' AND l.end_date >= '2026-10-04')
                  OR EXISTS (SELECT 1 FROM schedule_locks k WHERE k.department_id = e.department_id AND k.unlocked_at IS NULL AND k.start_date <= '2026-10-04' AND k.end_date >= '2026-10-04'))) AS CHAR);

-- 2. If rows DID land: which departments hold them. This is where to point the
--    schedule board's department filter — and if your account only supervises
--    one unit, a department not on this list is why your screen looks empty.
SELECT d.name AS department, d.category, s.name AS shift, COUNT(*) AS employees
FROM schedule_assignments a
JOIN employees e ON e.id = a.employee_id
JOIN departments d ON d.id = e.department_id
JOIN shifts s ON s.id = a.shift_id
WHERE a.work_date = '2026-10-04'
GROUP BY d.name, d.category, s.name, s.start_time
ORDER BY d.name, s.start_time;

-- 3. The rows themselves, exactly as stored.
SELECT a.id, a.work_date, a.status, a.created_via, s.code AS shift,
       e.employee_number, CONCAT(e.first_name, ' ', e.last_name) AS employee
FROM schedule_assignments a
JOIN shifts s ON s.id = a.shift_id
JOIN employees e ON e.id = a.employee_id
WHERE a.work_date = '2026-10-04'
ORDER BY s.start_time, e.last_name;
