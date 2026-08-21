<?php

namespace Tests\Feature\Schedule;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\ScheduleAssignment;
use App\Models\Shift;
use App\Models\User;
use App\Services\Scheduling\RosterWriteContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiScheduleRankingTest extends TestCase
{
    use RefreshDatabase;

    public function test_ranking_is_deterministic_traceable_and_returns_two_alternatives(): void
    {
        $this->seed();
        config(['ai_workforce_scheduling.enabled' => true]);
        $manager = User::query()->where('email', 'hr.manager@hrms.local')->firstOrFail();
        $department = Department::query()->where('code', 'HR')->firstOrFail();
        $position = Position::query()->where('code', 'HR-OFFICER')->firstOrFail();
        $day = Shift::query()->where('code', 'ADMIN-0800')->firstOrFail();
        $night = Shift::query()->where('code', 'NIGHT-2200')->firstOrFail();
        $existing = Employee::query()->where('employee_number', 'HR-2026-0002')->firstOrFail();
        $light = $this->employee($department, $position, 'HR-0100', 'Light');
        $heavy = $this->employee($department, $position, 'HR-0200', 'Heavy');

        $this->assignment($existing, $day, '2027-11-05', $manager);
        foreach (['2027-11-01', '2027-11-02', '2027-11-03', '2027-11-04', '2027-11-05'] as $date) {
            $this->assignment($heavy, $date === '2027-11-04' ? $night : $day, $date, $manager);
        }
        $payload = [
            'department_id' => $department->id,
            'position_id' => $position->id,
            'shift_id' => $day->id,
            'work_date' => '2027-11-08',
        ];

        $first = $this->actingAs($manager)->postJson(route('schedules.ai-recommendations.store'), $payload)->assertOk();
        $second = $this->actingAs($manager)->postJson(route('schedules.ai-recommendations.store'), $payload)->assertOk();

        $first->assertJsonPath('data.recommended.employee_id', $light->id)->assertJsonCount(2, 'data.alternatives');
        $recommended = $first->json('data.recommended');
        $this->assertSame($first->json('data.eligible'), $second->json('data.eligible'));
        $this->assertSame($recommended['score'], array_sum(array_column($recommended['score_breakdown'], 'points')));
        $this->assertSame(40.0, (float) $recommended['score_breakdown']['eligibility']['points']);
        $this->assertArrayHasKey('weekly_workload_minutes', $recommended['metrics']);
        $this->assertArrayHasKey('rest_hours', $recommended['metrics']);
        $this->assertArrayHasKey('level', $recommended['workload_risk']);
        $this->assertArrayHasKey('weekly_workload_vs_pool', $recommended['fairness']);
        $this->assertNotEmpty($recommended['recommendation_reasons']);

        $scores = collect($first->json('data.eligible'))->pluck('score')->all();
        $this->assertSame($scores, collect($scores)->sortDesc()->values()->all());
    }

    private function employee(Department $department, Position $position, string $number, string $firstName): Employee
    {
        return Employee::query()->create([
            'department_id' => $department->id,
            'position_id' => $position->id,
            'employee_number' => $number,
            'first_name' => $firstName,
            'last_name' => 'Candidate',
            'employment_status' => 'active',
            'hire_date' => '2020-01-01',
        ]);
    }

    private function assignment(Employee $employee, Shift $shift, string $date, User $manager): void
    {
        RosterWriteContext::allowUnattended(fn () => ScheduleAssignment::query()->create([
            'employee_id' => $employee->id,
            'shift_id' => $shift->id,
            'work_date' => $date,
            'status' => 'scheduled',
            'created_by' => $manager->id,
        ]));
    }
}
