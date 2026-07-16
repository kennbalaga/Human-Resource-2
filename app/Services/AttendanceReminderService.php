<?php

namespace App\Services;

use App\Models\AttendanceRecord;
use App\Models\OfficeLocation;
use App\Models\User;
use App\Notifications\PreferenceMailNotification;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

class AttendanceReminderService
{
    public function __construct(private readonly PreferenceNotificationService $notifications) {}

    public function send(string $type): int
    {
        if (! in_array($type, ['check-in', 'check-out'], true)) {
            throw new InvalidArgumentException('Attendance reminder type must be check-in or check-out.');
        }

        $office = OfficeLocation::query()->where('is_active', true)->first();

        if ($office === null) {
            return 0;
        }

        $today = now($office->timezone)->toDateString();
        $users = User::query()
            ->where('is_active', true)
            ->whereHas('employee', fn ($query) => $query->where('employment_status', 'active'))
            ->with(['employee', 'preference'])
            ->get();
        $records = AttendanceRecord::query()
            ->whereIn('employee_id', $users->pluck('employee.id')->filter())
            ->whereDate('attendance_date', $today)
            ->get()
            ->keyBy('employee_id');
        $sent = 0;

        foreach ($users as $user) {
            $record = $records->get($user->employee->id);
            $needsReminder = $type === 'check-in'
                ? $record?->check_in_at === null
                : $record?->check_in_at !== null && $record?->check_out_at === null;

            if (! $needsReminder) {
                continue;
            }

            $cacheKey = "attendance-reminder:{$today}:{$type}:{$user->id}";

            if (Cache::has($cacheKey)) {
                continue;
            }

            $isCheckIn = $type === 'check-in';
            $notification = new PreferenceMailNotification(
                $isCheckIn ? 'Attendance check-in reminder' : 'Attendance check-out reminder',
                [
                    $isCheckIn
                        ? 'This is a reminder to record your check-in for today.'
                        : 'You are currently checked in. Please remember to record your check-out.',
                    'Office schedule: '.date('g:i A', strtotime($office->work_start_time)).'–'.date('g:i A', strtotime($office->work_end_time)).'.',
                ],
                'Open Attendance',
                route('attendance.index'),
            );

            if ($this->notifications->send($user, 'attendance_reminders', $notification)) {
                Cache::put($cacheKey, true, now()->addHours(26));
                $sent++;
            }
        }

        return $sent;
    }
}
