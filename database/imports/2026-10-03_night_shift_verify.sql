-- =============================================================================
-- Check the 2026-10-03 night shift import. READ-ONLY.
-- Run in the hrms-db SQL console (the >_ button on the database).
-- Plain SQL only, so it runs the same on MariaDB as on MySQL.
-- =============================================================================

-- 1. Did it land, and will a punch be able to bind to it?
--    The bind window is [shift start - early_window, shift start + late_bind].
--    NIGHT-2200 starts at 22:00 on the 3rd, so with the stock 60/240 settings
--    a punch binds between 21:00 on the 3rd and 02:00 on the 4th.
SELECT 'Night rows on 2026-10-03' AS fact,
       CAST((SELECT COUNT(*) FROM schedule_assignments a
              JOIN shifts s ON s.id = a.shift_id
             WHERE a.work_date = '2026-10-03' AND s.code = 'NIGHT-2200') AS CHAR) AS value
UNION ALL
SELECT 'Of those, written by this import',
       CAST((SELECT COUNT(*) FROM schedule_assignments
              WHERE work_date = '2026-10-03'
                AND notes = 'Imported for biometric terminal testing (2026-10-03 night).') AS CHAR)
UNION ALL
SELECT 'Status is scheduled (the resolver requires it)',
       CAST((SELECT COUNT(*) FROM schedule_assignments
              WHERE work_date = '2026-10-03' AND status = 'scheduled') AS CHAR)
UNION ALL
SELECT 'Database time right now',
       CAST(NOW() AS CHAR)
UNION ALL
SELECT 'Bind window for this shift opens',
       '2026-10-03 21:00:00  (22:00 minus early_window_minutes)'
UNION ALL
SELECT 'Bind window for this shift closes',
       '2026-10-04 02:00:00  (22:00 plus late_bind_minutes)'
UNION ALL
SELECT 'Settings in force (early / grace / late_bind)',
       COALESCE((SELECT CONCAT(early_window_minutes, ' / ', grace_minutes, ' / ', late_bind_minutes)
                 FROM attendance_schedule_settings ORDER BY id DESC LIMIT 1),
                'no row — the app falls back to 60 / 15 / 240')
UNION ALL
SELECT 'Each person has a created audit row',
       IF((SELECT COUNT(*) FROM schedule_assignments a
            WHERE a.work_date = '2026-10-03'
              AND NOT EXISTS (SELECT 1 FROM schedule_assignment_audits t
                              WHERE t.schedule_assignment_id = a.id AND t.action = 'created')) = 0,
          'OK', 'MISSING for one or more rows');

-- 2. Who is on it. Enrol these people on the terminal.
SELECT e.employee_number,
       CONCAT(e.first_name, ' ', e.last_name) AS employee,
       d.name AS department, p.title AS position,
       a.work_date, s.code AS shift,
       CONCAT(TIME_FORMAT(s.start_time, '%l:%i %p'), ' - ', TIME_FORMAT(s.end_time, '%l:%i %p')) AS hours,
       a.status
FROM schedule_assignments a
JOIN shifts s ON s.id = a.shift_id
JOIN employees e ON e.id = a.employee_id
JOIN departments d ON d.id = e.department_id
JOIN positions p ON p.id = e.position_id
WHERE a.work_date = '2026-10-03'
ORDER BY e.last_name, e.first_name;

-- 3. Nothing about these people should contradict the roster.
SELECT 'Rostered but inactive or archived' AS check_name,
       COUNT(*) AS offending_rows
FROM schedule_assignments a
JOIN employees e ON e.id = a.employee_id
WHERE a.work_date = '2026-10-03'
  AND (e.employment_status <> 'active' OR e.archived_at IS NOT NULL OR e.deleted_at IS NOT NULL)
UNION ALL
SELECT 'Rostered but on approved leave', COUNT(*)
FROM schedule_assignments a
WHERE a.work_date = '2026-10-03'
  AND EXISTS (SELECT 1 FROM leave_requests l WHERE l.employee_id = a.employee_id
                AND l.status = 'approved' AND l.start_date <= '2026-10-03' AND l.end_date >= '2026-10-03')
UNION ALL
SELECT 'Rostered but has a day off that date', COUNT(*)
FROM schedule_assignments a
WHERE a.work_date = '2026-10-03'
  AND EXISTS (SELECT 1 FROM schedule_day_offs o WHERE o.employee_id = a.employee_id AND o.work_date = '2026-10-03')
UNION ALL
SELECT 'Also rostered on Oct 2 or Oct 4 (rest risk)', COUNT(*)
FROM schedule_assignments a
WHERE a.work_date = '2026-10-03'
  AND EXISTS (SELECT 1 FROM schedule_assignments n WHERE n.employee_id = a.employee_id
                AND n.work_date IN ('2026-10-02', '2026-10-04'));
