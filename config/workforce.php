<?php

return [
    'timezone' => 'Asia/Manila',
    'standard_daily_minutes' => (int) env('WORKFORCE_STANDARD_DAILY_MINUTES', 480),
    'analytics_max_days' => (int) env('WORKFORCE_ANALYTICS_MAX_DAYS', 366),
    'leave_max_days' => (int) env('WORKFORCE_LEAVE_MAX_DAYS', 30),
    'attachment_max_kilobytes' => (int) env('WORKFORCE_ATTACHMENT_MAX_KB', 5120),
    // Leave attachments are medical and personal records, so this stays a
    // separate setting from FILESYSTEM_DISK: pointing the app's general
    // default at a public disk must never make supporting documents
    // reachable by URL.
    //
    // `database` keeps the file in the database beside its row, so every
    // computer running against the shared database can open it, and nothing
    // is lost to an ephemeral filesystem. Any filesystem disk name still works;
    // see LeaveAttachmentStorage.
    'attachment_disk' => env('WORKFORCE_ATTACHMENT_DISK', 'database'),
    'employee_number_auto_generate' => env('WORKFORCE_EMPLOYEE_ID_AUTO_GENERATE', true),
    // How many days a record sits in Terminated before `employees:archive-terminated`
    // files it away on its own. HR can still archive by hand the moment the
    // termination is saved, and can restore an archived record at any time —
    // this only decides when nobody has to remember to. Set it to 0 to switch
    // the automatic half off and leave archiving entirely manual.
    'terminated_archive_after_days' => (int) env('WORKFORCE_TERMINATED_ARCHIVE_AFTER_DAYS', 30),
    'attendance_capture_mode' => env('WORKFORCE_ATTENDANCE_CAPTURE_MODE', 'hybrid'),
    'local_employee_default_password' => env('LOCAL_EMPLOYEE_DEFAULT_PASSWORD', 'ChangeMe123!'),
];
