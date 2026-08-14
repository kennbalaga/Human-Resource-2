<?php

namespace Tests\Feature\Schedule;

use App\Models\AttendanceRecord;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Position;
use App\Models\RosterDraft;
use App\Models\ScheduleAssignment;
use App\Models\ScheduleLock;
use App\Models\ScheduleRecommendation;
use App\Models\ScheduleRecommendationDecision;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AiScheduleRecommendationLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private Employee $employee;

    private Department $department;

    private Position $position;

    private Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        config(['ai_workforce_scheduling.enabled' => true]);
        $this->manager = User::query()->where('email', 'hr.manager@hrms.local')->firstOrFail();
        $this->employee = Employee::query()->where('employee_number', 'HR-2026-0002')->firstOrFail();
        $this->department = Department::query()->where('code', 'HR')->firstOrFail();
        $this->position = Position::query()->where('code', 'HR-OFFICER')->firstOrFail();
        $this->shift = Shift::query()->where('code', 'ADMIN-0800')->firstOrFail();
    }

    public function test_generation_creates_ai_audit_record_without_saving_a_schedule(): void
    {
        $privateReason = 'Private medical detail that must never enter recommendation audit data.';
        LeaveRequest::query()->create([
            'uuid' => (string) Str::uuid(),
            'employee_id' => $this->employee->id,
            'leave_type_id' => LeaveType::query()->firstOrFail()->id,
            'start_date' => '2027-10-01',
            'end_date' => '2027-10-01',
            'requested_days' => 1,
            'reason' => $privateReason,
            'status' => 'approved',
        ]);
        $before = ScheduleAssignment::query()->count();
        $response = $this->generate()->assertOk();
        $record = ScheduleRecommendation::query()->where('uuid', $response->json('data.recommendation_id'))->firstOrFail();

        $this->assertSame($before, ScheduleAssignment::query()->count());
        $this->assertSame('AI_SYSTEM', $record->actor_type);
        $this->assertSame('AI Scheduling Assistant', $record->actor_name);
        $this->assertSame('GENERATED_RECOMMENDATION', $record->action_type);
        $this->assertSame('for_hr_review', $record->status);
        $response->assertJsonStructure(['data' => ['apply_url', 'decision_url', 'expires_at', 'explanation']]);

        $auditPayload = json_encode([$record->eligibility_results, $record->alternative_candidates, $record->warnings]);
        $this->assertStringNotContainsString($this->employee->full_name, $auditPayload);
        $this->assertStringNotContainsString($this->employee->employee_number, $auditPayload);
        $this->assertStringNotContainsString($this->employee->user->email, $auditPayload);
        $this->assertStringNotContainsString($privateReason, $auditPayload);
    }

    public function test_apply_revalidates_and_returns_only_the_employee_field_without_saving_a_schedule(): void
    {
        $before = ScheduleAssignment::query()->count();
        $generated = $this->generate()->assertOk()->json('data');

        $this->actingAs($this->manager)->postJson($generated['apply_url'], $this->applyPayload($this->employee->id))
            ->assertOk()
            ->assertJsonPath('data.employee_id', $this->employee->id)
            ->assertJsonPath('data.status', 'applied');

        $this->assertSame($before, ScheduleAssignment::query()->count());
        $this->assertDatabaseHas('schedule_recommendation_decisions', [
            'user_id' => $this->manager->id,
            'action' => 'applied',
            'final_selected_employee_id' => $this->employee->id,
        ]);
        $this->assertDatabaseHas('schedule_recommendations', ['uuid' => $generated['recommendation_id'], 'status' => 'applied']);
    }

    public function test_apply_materializes_the_recommendation_into_a_draft_roster_row(): void
    {
        $before = ScheduleAssignment::query()->count();
        $generated = $this->generate()->assertOk()->json('data');
        $recommendationId = ScheduleRecommendation::query()->where('uuid', $generated['recommendation_id'])->value('id');

        $data = $this->actingAs($this->manager)->postJson($generated['apply_url'], $this->applyPayload($this->employee->id))
            ->assertOk()
            ->assertJsonPath('data.status', 'applied')
            ->assertJsonPath('data.employee_id', $this->employee->id)
            ->json('data');

        $this->assertArrayHasKey('draft_id', $data);
        $draft = RosterDraft::query()->where('uuid', $data['draft_id'])->firstOrFail();
        $this->assertSame($this->department->id, $draft->department_id);
        $this->assertSame('open', $draft->status);

        $entry = collect($draft->entries)->firstWhere('schedule_recommendation_id', $recommendationId);
        $this->assertNotNull($entry);
        $this->assertSame($this->employee->id, $entry['employee_id']);
        $this->assertSame($this->shift->id, $entry['shift_id']);
        $this->assertSame('2027-10-01', $entry['work_date']);
        $this->assertSame('ai', $entry['source']);
        $this->assertSame($this->manager->id, $entry['applied_by']);
        $this->assertFalse($entry['was_modified']);

        // Publishing, not applying, is what saves an actual schedule.
        $this->assertSame($before, ScheduleAssignment::query()->count());
    }

    public function test_applying_the_same_recommendation_twice_is_idempotent(): void
    {
        $generated = $this->generate()->assertOk()->json('data');
        $recommendationId = ScheduleRecommendation::query()->where('uuid', $generated['recommendation_id'])->value('id');
        $payload = $this->applyPayload($this->employee->id);

        $first = $this->actingAs($this->manager)->postJson($generated['apply_url'], $payload)->assertOk()->json('data');
        $second = $this->actingAs($this->manager)->postJson($generated['apply_url'], $payload)->assertOk()->json('data');

        $this->assertSame($first['draft_id'], $second['draft_id']);
        $this->assertSame('applied', $second['status']);
        $this->assertSame(1, RosterDraft::query()->count());

        $draft = RosterDraft::query()->where('uuid', $first['draft_id'])->firstOrFail();
        $matching = collect($draft->entries)->where('schedule_recommendation_id', $recommendationId);
        $this->assertCount(1, $matching);

        // The second call short-circuited before recording another decision.
        $this->assertSame(1, ScheduleRecommendationDecision::query()->count());
    }

    public function test_apply_is_blocked_and_rolled_back_when_the_period_is_locked(): void
    {
        ScheduleLock::query()->create([
            'department_id' => $this->department->id,
            'start_date' => '2027-10-01',
            'end_date' => '2027-10-01',
            'locked_by' => $this->manager->id,
            'locked_at' => now(),
        ]);
        $generated = $this->generate()->assertOk()->json('data');

        $this->actingAs($this->manager)->postJson($generated['apply_url'], $this->applyPayload($this->employee->id))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('schedule');

        $this->assertSame(0, RosterDraft::query()->count());
        $this->assertSame(0, ScheduleRecommendationDecision::query()->count());
        $this->assertDatabaseHas('schedule_recommendations', [
            'uuid' => $generated['recommendation_id'],
            'status' => 'for_hr_review',
        ]);
    }

    public function test_selecting_a_recorded_alternative_is_a_modified_human_decision(): void
    {
        $alternative = Employee::query()->create([
            'department_id' => $this->department->id,
            'position_id' => $this->position->id,
            'employee_number' => 'HR-ALT-001',
            'first_name' => 'Qualified',
            'last_name' => 'Alternative',
            'employment_status' => 'active',
        ]);
        $before = ScheduleAssignment::query()->count();
        $generated = $this->generate()->assertOk()->json('data');
        $this->assertContains($alternative->id, collect($generated['alternatives'])->pluck('employee_id'));

        $this->actingAs($this->manager)->postJson($generated['apply_url'], $this->applyPayload($alternative->id))
            ->assertOk()->assertJsonPath('data.status', 'modified');

        $decision = ScheduleRecommendationDecision::query()->firstOrFail();
        $this->assertSame('modified', $decision->action);
        $this->assertSame($alternative->id, $decision->final_selected_employee_id);
        $this->assertSame($this->manager->id, $decision->user_id);
        $this->assertSame($before, ScheduleAssignment::query()->count());
    }

    public function test_ignore_and_reject_are_separate_human_decisions(): void
    {
        $ignored = $this->generate()->assertOk()->json('data');
        $this->actingAs($this->manager)->postJson($ignored['decision_url'], ['action' => 'ignored'])
            ->assertOk()->assertJsonPath('data.status', 'ignored');

        $rejected = $this->generate('2027-10-02')->assertOk()->json('data');
        $this->actingAs($this->manager)->postJson($rejected['decision_url'], [
            'action' => 'rejected',
            'reason' => 'The ward coverage plan changed.',
        ])->assertOk()->assertJsonPath('data.status', 'rejected');

        $this->assertDatabaseHas('schedule_recommendation_decisions', ['action' => 'ignored', 'user_id' => $this->manager->id]);
        $this->assertDatabaseHas('schedule_recommendation_decisions', [
            'action' => 'rejected',
            'reason' => 'The ward coverage plan changed.',
            'user_id' => $this->manager->id,
        ]);
    }

    public function test_changed_schedule_leave_employee_shift_and_attendance_each_make_a_recommendation_stale(): void
    {
        $mutations = [
            fn (string $date) => ScheduleAssignment::query()->create([
                'employee_id' => $this->employee->id,
                'shift_id' => $this->shift->id,
                'work_date' => $date,
                'status' => 'scheduled',
                'created_by' => $this->manager->id,
            ]),
            fn (string $date) => LeaveRequest::query()->create([
                'uuid' => (string) Str::uuid(),
                'employee_id' => $this->employee->id,
                'leave_type_id' => LeaveType::query()->firstOrFail()->id,
                'start_date' => $date,
                'end_date' => $date,
                'requested_days' => 1,
                'reason' => 'Private leave reason must not enter recommendation audit data.',
                'status' => 'approved',
            ]),
            fn (string $date) => $this->employee->update(['employment_status' => 'inactive']),
            fn (string $date) => $this->shift->update(['break_minutes' => $this->shift->break_minutes + 1]),
            fn (string $date) => AttendanceRecord::query()->create([
                'employee_id' => $this->employee->id,
                'attendance_date' => $date,
                'status' => 'present',
                'approval_status' => 'approved',
                'worked_minutes' => 480,
                'overtime_minutes' => 0,
            ]),
        ];

        foreach ($mutations as $index => $mutation) {
            $date = sprintf('2027-10-%02d', $index + 1);
            $generated = $this->generate($date)->assertOk()->json('data');
            $mutation($date);

            $this->actingAs($this->manager)->postJson($generated['apply_url'], $this->applyPayload($this->employee->id, $date))
                ->assertUnprocessable()
                ->assertJsonValidationErrors('recommendation')
                ->assertJsonPath('errors.recommendation.0', 'This AI recommendation is no longer valid because the scheduling information has changed. Please generate a new recommendation.');

            $this->assertDatabaseHas('schedule_recommendations', ['uuid' => $generated['recommendation_id'], 'status' => 'expired']);
            ScheduleAssignment::query()->delete();
            LeaveRequest::query()->delete();
            AttendanceRecord::query()->delete();
            $this->employee->update(['employment_status' => 'active']);
            $this->shift->refresh()->update(['break_minutes' => 60]);
        }
    }

    public function test_expired_or_target_changed_recommendations_cannot_be_applied(): void
    {
        $before = ScheduleAssignment::query()->count();
        $expired = $this->generate()->assertOk()->json('data');
        $this->travel((int) config('ai_workforce_scheduling.recommendation_ttl_minutes') + 1)->minutes();
        $this->actingAs($this->manager)->postJson($expired['apply_url'], $this->applyPayload($this->employee->id))
            ->assertUnprocessable()->assertJsonValidationErrors('recommendation');
        $this->travelBack();

        $changed = $this->generate()->assertOk()->json('data');
        $payload = $this->applyPayload($this->employee->id);
        $payload['work_date'] = '2027-10-03';
        $this->actingAs($this->manager)->postJson($changed['apply_url'], $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('recommendation');

        $this->assertSame($before, ScheduleAssignment::query()->count());
    }

    private function generate(string $date = '2027-10-01')
    {
        return $this->actingAs($this->manager)->postJson(route('schedules.ai-recommendations.store'), [
            'department_id' => $this->department->id,
            'position_id' => $this->position->id,
            'shift_id' => $this->shift->id,
            'work_date' => $date,
        ]);
    }

    /** @return array<string, mixed> */
    private function applyPayload(int $employeeId, string $date = '2027-10-01'): array
    {
        return [
            'employee_id' => $employeeId,
            'department_id' => $this->department->id,
            'position_id' => $this->position->id,
            'shift_id' => $this->shift->id,
            'work_date' => $date,
        ];
    }
}
