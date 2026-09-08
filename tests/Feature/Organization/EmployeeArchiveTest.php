<?php

namespace Tests\Feature\Organization;

use App\Models\Employee;
use App\Models\LeaveType;
use App\Models\ScheduleAssignment;
use App\Models\Shift;
use App\Models\User;
use App\Services\Attendance\AttendanceQrService;
use App\Services\Scheduling\EmployeeEligibilityService;
use App\Support\SessionNotice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Archiving is a shelf, not a delete.
 *
 * The record stays in the database whole — that is the point of it, since every
 * timesheet, leave balance and attendance row an employee ever produced points
 * back at this row. What changes is where it is offered: out of the directory's
 * working list, out of the pickers that hand somebody work, and into the
 * Archived filter, from which it comes back unchanged.
 */
class EmployeeArchiveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
        Notification::fake();
    }

    public function test_an_employee_with_work_still_on_the_books_cannot_be_archived(): void
    {
        $manager = $this->manager();
        // Seeded with four rostered shifts from today onwards, and nothing has
        // been settled: exactly the record that must not be filed away yet.
        $employee = $this->employee('HR-OFFICER-2026-0001');

        $this->actingAs($manager)
            ->post(route('employees.archive', $employee))
            ->assertRedirect()
            ->assertSessionHas('warning', fn (string $message) => str_contains($message, 'scheduled shift'));

        $this->assertNull($employee->fresh()->archived_at);
        $this->assertTrue($employee->user->fresh()->is_active);
    }

    public function test_the_block_names_every_kind_of_loose_end_at_once(): void
    {
        $manager = $this->manager();
        // Rostered, awaiting a leave decision, and supervising seven people.
        $employee = $this->employee('HR-MGR-2026-0001');
        $employee->leaveRequests()->create([
            'uuid' => (string) Str::uuid(),
            'leave_type_id' => LeaveType::query()->value('id'),
            'start_date' => now()->addWeek()->toDateString(),
            'end_date' => now()->addWeek()->addDay()->toDateString(),
            'requested_days' => 2,
            'reason' => 'Filed and not yet decided.',
            'status' => 'pending',
        ]);

        $this->actingAs($manager)
            ->post(route('employees.archive', $employee))
            ->assertRedirect()
            ->assertSessionHas('warning', fn (string $message) => str_contains($message, 'scheduled shift')
                && str_contains($message, 'pending leave')
                && str_contains($message, 'direct report'));

        $this->assertNull($employee->fresh()->archived_at);
    }

    public function test_archiving_keeps_the_row_and_moves_it_out_of_the_working_list(): void
    {
        $manager = $this->manager();
        $employee = $this->settledEmployee('HR-OFFICER-2026-0001');

        $this->actingAs($manager)
            ->post(route('employees.archive', $employee))
            ->assertRedirect()
            ->assertSessionHas('success');

        // Still there, and still itself: the archive changes where the record
        // is listed, not what it holds.
        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
            'employee_number' => $employee->employee_number,
            'first_name' => $employee->first_name,
            'archived_by' => $manager->id,
        ]);
        $this->assertNotNull($employee->fresh()->archived_at);
        $this->assertNull($employee->fresh()->deleted_at);

        $this->actingAs($manager)->get(route('employees.index'))
            ->assertOk()
            ->assertDontSee($employee->employee_number);

        $this->actingAs($manager)->get(route('employees.index', ['status' => 'archived']))
            ->assertOk()
            ->assertSee($employee->employee_number);
    }

    public function test_a_restored_employee_returns_to_the_directory(): void
    {
        $manager = $this->manager();
        $employee = $this->settledEmployee('HR-OFFICER-2026-0001');

        $this->actingAs($manager)->post(route('employees.archive', $employee))->assertRedirect();
        $this->actingAs($manager)->post(route('employees.restore', $employee))
            ->assertRedirect()
            ->assertSessionHas('success');

        $employee->refresh();
        $this->assertNull($employee->archived_at);
        $this->assertNull($employee->archived_by);

        $this->actingAs($manager)->get(route('employees.index'))
            ->assertOk()
            ->assertSee($employee->employee_number);
    }

    public function test_an_archived_employee_is_no_longer_offered_as_somebody_to_assign(): void
    {
        $manager = $this->manager();
        $employee = $this->settledEmployee('HR-OFFICER-2026-0001');
        $this->actingAs($manager)->post(route('employees.archive', $employee))->assertRedirect();

        // The supervisor picker on the employee form, and the pickers that hand
        // out work: none of them may still offer a filed-away colleague.
        // Asserted against the option lists themselves rather than the rendered
        // page, because a seeded employee's name ("HR Officer") also reads as a
        // position title elsewhere in the same markup.
        $this->actingAs($manager)->get(route('employees.create'))
            ->assertOk()
            ->assertViewHas('supervisors', fn ($supervisors) => ! $supervisors->contains('id', $employee->id));

        $this->actingAs($manager)->get(route('leaves.index'))
            ->assertOk()
            ->assertViewHas('employees', fn ($employees) => ! $employees->contains('id', $employee->id));

        // And the rule behind the form refuses one posted by hand.
        $subject = $this->employee('HR-MGR-2026-0001');

        $this->actingAs($manager)
            ->patch(route('employees.update', $subject), $this->payloadFor($subject, ['supervisor_id' => $employee->id]))
            ->assertSessionHasErrors('supervisor_id');
    }

    public function test_an_archived_employee_still_counts_as_a_workforce_record(): void
    {
        $manager = $this->manager();
        $employee = $this->settledEmployee('HR-OFFICER-2026-0001');
        $before = Employee::query()->count();

        $this->actingAs($manager)->post(route('employees.archive', $employee))->assertRedirect();

        // Archiving is not a headcount change. Reports, dashboards and the
        // history behind them keep seeing the same organisation.
        $this->assertSame($before, Employee::query()->count());
    }

    public function test_a_department_head_cannot_archive(): void
    {
        $head = User::query()->whereHas('roles', fn ($query) => $query->where('slug', 'department-head'))->firstOrFail();
        $employee = $this->settledEmployee('HR-OFFICER-2026-0001');

        $this->actingAs($head)
            ->post(route('employees.archive', $employee))
            ->assertForbidden();

        $this->assertNull($employee->fresh()->archived_at);
    }

    public function test_archiving_an_already_archived_employee_says_so_rather_than_restamping_it(): void
    {
        $manager = $this->manager();
        $employee = $this->settledEmployee('HR-OFFICER-2026-0001');

        $this->actingAs($manager)->post(route('employees.archive', $employee))->assertRedirect();
        $archivedAt = $employee->fresh()->archived_at;

        $this->actingAs($manager)
            ->post(route('employees.archive', $employee))
            ->assertRedirect()
            ->assertSessionHas('warning');

        $this->assertEquals($archivedAt, $employee->fresh()->archived_at);
    }

    public function test_archiving_closes_the_sign_in_and_editing_the_record_does_not_reopen_it(): void
    {
        $manager = $this->manager();
        $employee = $this->settledEmployee('HR-OFFICER-2026-0001');
        $this->assertTrue($employee->user->is_active);

        $this->actingAs($manager)->post(route('employees.archive', $employee))->assertRedirect();
        $this->assertFalse($employee->user->fresh()->is_active);

        // Employment status still reads active, and the form recomputes access
        // from it — the archive has to outrank that.
        $this->actingAs($manager)
            ->patch(route('employees.update', $employee), $this->payloadFor($employee->fresh()))
            ->assertSessionHasNoErrors();

        $this->assertFalse($employee->user->fresh()->is_active);

        // Restoring hands access back, by the same employment rule.
        $this->actingAs($manager)->post(route('employees.restore', $employee))->assertRedirect();
        $this->assertTrue($employee->user->fresh()->is_active);
    }

    public function test_an_archived_or_terminated_employee_cannot_be_rostered_by_hand(): void
    {
        $manager = $this->manager();
        $archived = $this->settledEmployee('HR-OFFICER-2026-0001');
        $this->actingAs($manager)->post(route('employees.archive', $archived))->assertRedirect();

        $shift = Shift::query()->where('code', 'ADMIN-0800')->firstOrFail();

        // The pickers no longer offer them, so this is the request a stale page
        // or a hand-written id would send.
        $this->actingAs($manager)->post('/schedules', [
            'employee_id' => $archived->id,
            'shift_id' => $shift->id,
            'work_date' => '2027-10-04',
        ])->assertSessionHasErrors('employee_id');

        $terminated = $this->settledEmployee('HR-MGR-2026-0001');
        $terminated->forceFill(['employment_status' => 'terminated'])->save();

        $this->actingAs($manager)->post('/schedules', [
            'employee_id' => $terminated->id,
            'shift_id' => $shift->id,
            'work_date' => '2027-10-04',
        ])->assertSessionHasErrors('employee_id');

        $this->assertDatabaseCount('schedule_assignments', ScheduleAssignment::query()->count());
    }

    public function test_neither_is_offered_or_even_listed_by_the_roster_candidate_search(): void
    {
        $manager = $this->manager();
        $archived = $this->settledEmployee('HR-OFFICER-2026-0001');
        $this->actingAs($manager)->post(route('employees.archive', $archived))->assertRedirect();

        $terminated = $this->settledEmployee('HR-MGR-2026-0001');
        $terminated->forceFill(['employment_status' => 'terminated'])->save();

        $analysis = app(EmployeeEligibilityService::class)->analyze(
            $archived->department,
            $archived->position,
            Shift::query()->where('code', 'ADMIN-0800')->firstOrFail(),
            '2027-10-04',
        );

        $named = collect($analysis['eligible'])->merge($analysis['ineligible'])
            ->pluck('employee.id')
            ->filter()
            ->all();

        // Not merely rejected with a reason — absent. A colleague who has left
        // is not a line the person building next week's roster should read.
        $this->assertNotContains($archived->id, $named);
        $this->assertNotContains($terminated->id, $named);
    }

    public function test_a_closed_account_is_signed_out_on_its_next_request(): void
    {
        $employee = $this->settledEmployee('HR-OFFICER-2026-0001');
        $user = $employee->user;

        $this->actingAs($user)->get(route('dashboard'))->assertOk();

        // Terminated mid-session: the session itself must stop working, not
        // just the next sign-in.
        $user->update(['is_active' => false]);

        $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_the_open_screen_is_told_why_rather_than_left_to_guess(): void
    {
        $employee = $this->settledEmployee('HR-OFFICER-2026-0001');
        $user = $employee->user;

        // The heartbeat the open page runs every few seconds is what carries
        // the news back to the screen, so its answer is the one that matters.
        $this->actingAs($user)->getJson(route('session.keep-alive'))->assertOk();

        $user->update(['is_active' => false]);

        $this->actingAs($user)
            ->getJson(route('session.keep-alive'))
            ->assertUnauthorized()
            ->assertJsonPath('reason', SessionNotice::AccountClosed->value)
            ->assertJsonPath('title', SessionNotice::AccountClosed->title());
    }

    public function test_an_archived_badge_no_longer_opens_the_attendance_day(): void
    {
        $manager = $this->manager();
        $employee = $this->settledEmployee('HR-OFFICER-2026-0001');
        $badge = app(AttendanceQrService::class)->payloadFor($employee);

        $this->actingAs($manager)->post(route('employees.archive', $employee))->assertRedirect();

        // The printed badge outlives the record, so the record is what decides.
        $this->expectException(ValidationException::class);
        app(AttendanceQrService::class)->resolve($badge);
    }

    /**
     * A seeded employee with nothing left on the books: the archive guard
     * refuses anybody still rostered, which every seeded person is.
     */
    private function settledEmployee(string $employeeNumber): Employee
    {
        $employee = $this->employee($employeeNumber);
        $employee->scheduleAssignments()->delete();
        $employee->leaveRequests()->where('status', 'pending')->delete();
        Employee::query()->where('supervisor_id', $employee->id)->update(['supervisor_id' => null]);

        return $employee->fresh(['user']);
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function payloadFor(Employee $employee, array $overrides = []): array
    {
        return [
            'first_name' => $employee->first_name,
            'last_name' => $employee->last_name,
            'email' => $employee->user->email,
            'department_id' => $employee->department_id,
            'position_id' => $employee->position_id,
            'employment_status' => $employee->employment_status,
            'hire_date' => $employee->hire_date->toDateString(),
        ] + $overrides;
    }

    private function employee(string $employeeNumber): Employee
    {
        return Employee::query()->with('user')->where('employee_number', $employeeNumber)->firstOrFail();
    }

    private function manager(): User
    {
        return User::query()->whereHas('roles', fn ($query) => $query->where('slug', 'hr-manager'))->firstOrFail();
    }
}
