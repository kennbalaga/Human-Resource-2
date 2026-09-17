<?php

namespace Tests\Feature\Burnout;

use App\Models\AttendanceRecord;
use App\Models\BurnoutRiskSnapshot;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Position;
use App\Models\ScheduleAssignment;
use App\Models\Shift;
use App\Models\User;
use App\Services\Burnout\BurnoutMetricsCollector;
use App\Services\Burnout\BurnoutRiskService;
use App\Services\Scheduling\RosterWriteContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class BurnoutRiskServiceTest extends TestCase
{
    use RefreshDatabase;

    /** 10:00 in Manila on Sept 17, so the current window is Aug 20 - Sept 16. */
    private const NOW = '2026-09-17 02:00:00';

    private Department $ward;

    private Position $position;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(self::NOW);
        $this->seed();

        $this->ward = Department::query()->create([
            'code' => 'BR-WARD',
            'name' => 'Burnout Ward',
            'category' => Department::CATEGORY_CLINICAL,
            'is_active' => true,
        ]);
        $this->position = Position::query()->create([
            'department_id' => $this->ward->id,
            'code' => 'BR-NURSE',
            'title' => 'Burnout Ward Nurse',
            'seniority_rank' => 1,
            'is_active' => true,
        ]);
    }

    public function test_it_reads_hours_overtime_streak_nights_rest_and_leave_from_the_records(): void
    {
        $nurse = $this->employee('BR-0001');
        $night = Shift::query()->where('code', 'NIGHT-2200')->firstOrFail();

        // Rostered with nobody recording it: counted at its rostered length.
        $this->assign($nurse, $night, '2026-09-10');

        $this->punch($nurse, '2026-09-12', '14:00', '23:00', 480, 60);
        // Eight hours after the 23:00 finish, under the twelve-hour minimum.
        $this->punch($nurse, '2026-09-13', '07:00', '16:00', 480, 0);
        foreach (['2026-09-14', '2026-09-15', '2026-09-16'] as $date) {
            $this->punch($nurse, $date, '08:00', '17:00', 480, 60);
        }
        // A rejected record is not a day worked.
        $this->punch($nurse, '2026-09-05', '08:00', '20:00', 720, 240, 'rejected');

        $this->leave($nurse, 'VAC', '2026-08-01', '2026-08-03');
        $this->leave($nurse, 'SICK', '2026-09-01', '2026-09-01');

        $metrics = app(BurnoutMetricsCollector::class)
            ->collect(collect([$nurse]), Carbon::parse('2026-09-17', 'Asia/Manila'))[$nurse->id];

        $this->assertSame([
            // (420 + 5 x 480) minutes over four weeks.
            'weekly_hours' => 11.8,
            'overtime_hours' => 4.0,
            'work_streak' => 5,
            'night_shifts' => 1,
            'short_rest' => 1,
            'days_since_leave' => 15,
            'unplanned_leave' => 1,
            'duty_days' => 6,
        ], $metrics['current']);

        // The window before: nothing worked, and the sick day had not happened.
        $this->assertSame(0.0, $metrics['previous']['weekly_hours']);
        $this->assertSame(16, $metrics['previous']['days_since_leave']);
        $this->assertSame(0, $metrics['previous']['unplanned_leave']);
    }

    public function test_a_long_month_with_no_break_is_assessed_as_high(): void
    {
        $nurse = $this->employee('BR-0002');

        // Twenty-four long days in the last four weeks, two hours of each paid
        // as overtime, back in eleven and a half hours after every one, and no
        // leave for years.
        foreach (range(1, 27) as $offset) {
            if ($offset % 7 === 0) {
                continue;
            }

            $date = Carbon::parse('2026-09-17')->subDays($offset)->toDateString();
            $this->punch($nurse, $date, '07:00', '19:30', 660, 120);
        }

        $assessment = app(BurnoutRiskService::class)->forEmployee($nurse);

        $this->assertSame('high', $assessment['level']);
        $this->assertTrue($assessment['protected']);
        $this->assertSame('rising', $assessment['trend']);
        $this->assertSame('weekly_hours', $assessment['drivers'][0]['key']);
        $this->assertDatabaseHas('burnout_risk_snapshots', [
            'employee_id' => $nurse->id,
            'as_of_date' => '2026-09-17',
            'level' => 'high',
        ]);
    }

    public function test_the_days_assessment_is_made_once_and_then_read_back(): void
    {
        $nurse = $this->employee('BR-0003');
        $service = app(BurnoutRiskService::class);

        $service->current([$nurse->id]);
        DB::enableQueryLog();
        $again = $service->current([$nurse->id]);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertTrue($again->has($nurse->id));
        $this->assertSame(1, $queries);
        $this->assertSame(1, BurnoutRiskSnapshot::query()->where('employee_id', $nurse->id)->count());
    }

    public function test_a_recent_hire_has_no_earlier_window_to_trend_against(): void
    {
        $nurse = $this->employee('BR-0004', ['hire_date' => '2026-08-25']);

        $assessment = app(BurnoutRiskService::class)->forEmployee($nurse);

        $this->assertNull($assessment['previous_score']);
        $this->assertSame('new', $assessment['trend']);
        // Three weeks in is not three weeks without a break.
        $leave = collect($assessment['factors'])->firstWhere('key', 'days_since_leave');
        $this->assertEquals(0, $leave['points']);
    }

    public function test_people_no_longer_employed_are_not_assessed(): void
    {
        $gone = $this->employee('BR-0005', ['employment_status' => 'inactive']);

        $this->assertFalse(app(BurnoutRiskService::class)->current([$gone->id])->has($gone->id));
        $this->assertDatabaseMissing('burnout_risk_snapshots', ['employee_id' => $gone->id]);
    }

    public function test_the_nightly_command_assesses_everyone_active_and_prunes_old_assessments(): void
    {
        config(['burnout.retention_days' => 365]);
        $nurse = $this->employee('BR-0006');
        $stale = BurnoutRiskSnapshot::query()->create([
            'employee_id' => $nurse->id,
            'as_of_date' => '2025-01-01',
            'score' => 10,
            'level' => 'low',
            'factors' => [],
        ]);

        $this->artisan('burnout:snapshot')->assertSuccessful();

        $active = app(BurnoutRiskService::class)->assessableEmployees()->count();
        $this->assertSame($active, BurnoutRiskSnapshot::query()->where('as_of_date', '2026-09-17')->count());
        $this->assertModelMissing($stale);
    }

    /** @param  array<string, mixed>  $overrides */
    private function employee(string $number, array $overrides = []): Employee
    {
        return Employee::query()->create($overrides + [
            'department_id' => $this->ward->id,
            'position_id' => $this->position->id,
            'employee_number' => $number,
            'first_name' => 'Burnout',
            'last_name' => 'Nurse '.$number,
            'employment_status' => 'active',
            'hire_date' => '2020-01-01',
        ]);
    }

    private function punch(Employee $employee, string $date, string $in, string $out, int $worked, int $overtime, string $approval = 'approved'): void
    {
        $checkIn = Carbon::parse("{$date} {$in}", 'Asia/Manila');
        $checkOut = Carbon::parse("{$date} {$out}", 'Asia/Manila');

        AttendanceRecord::query()->create([
            'employee_id' => $employee->id,
            'attendance_date' => $date,
            'check_in_at' => $checkIn->utc(),
            'check_out_at' => $checkOut->utc(),
            'status' => 'present',
            'approval_status' => $approval,
            'worked_minutes' => $worked,
            'overtime_minutes' => $overtime,
        ]);
    }

    private function assign(Employee $employee, Shift $shift, string $date): void
    {
        $manager = User::query()->where('email', 'hr.manager@hrms.local')->firstOrFail();

        RosterWriteContext::allowUnattended(fn () => ScheduleAssignment::query()->create([
            'employee_id' => $employee->id,
            'shift_id' => $shift->id,
            'work_date' => $date,
            'status' => 'scheduled',
            'created_by' => $manager->id,
        ]));
    }

    private function leave(Employee $employee, string $code, string $start, string $end): void
    {
        LeaveRequest::query()->create([
            'uuid' => (string) Str::uuid(),
            'employee_id' => $employee->id,
            'leave_type_id' => LeaveType::query()->where('code', $code)->firstOrFail()->id,
            'start_date' => $start,
            'end_date' => $end,
            'requested_days' => 1,
            'reason' => 'Test leave',
            'status' => 'approved',
        ]);
    }
}
