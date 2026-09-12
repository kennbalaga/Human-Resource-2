<?php

namespace Tests\Feature\Organization;

use App\Models\Employee;
use App\Models\LeaveType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The half of archiving nobody has to remember.
 *
 * A termination is signed and then a month of loose ends follows — the last
 * timesheet, the clearance, the final pay. Making HR come back afterwards to
 * file the record is how directories end up as a list of everyone who has ever
 * worked here, so the sweep does it: thirty days in Terminated and the record
 * goes on the shelf on its own.
 *
 * What it must not do is act while a person is still working on that record, or
 * close an account that still owes the organisation an answer. Both of those
 * are asserted here alongside the filing itself.
 */
class TerminatedEmployeeAutoArchiveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
        Notification::fake();
    }

    public function test_a_record_terminated_longer_than_the_window_is_filed_on_its_own(): void
    {
        $employee = $this->terminatedDaysAgo('HR-OFFICER-2026-0001', 31);

        $this->artisan('employees:archive-terminated')
            ->expectsOutputToContain('Archived: '.$employee->full_name)
            ->assertSuccessful();

        $employee->refresh();
        $this->assertNotNull($employee->archived_at);
        // No archiver: nobody signed in to do this, and naming somebody who did
        // not act would be the one thing the trail must never say. The record
        // reads back as "archived automatically".
        $this->assertNull($employee->archived_by);
        $this->assertTrue($employee->wasArchivedAutomatically());

        // And it does everything the button does — an account left open would
        // be a person the directory no longer shows still clocking in.
        $this->assertFalse($employee->user->fresh()->is_active);
    }

    public function test_a_record_still_inside_the_window_is_left_alone(): void
    {
        $employee = $this->terminatedDaysAgo('HR-OFFICER-2026-0001', 29);

        $this->artisan('employees:archive-terminated')->assertSuccessful();

        $this->assertNull($employee->fresh()->archived_at);
        // Still reachable in the directory, which is the point of the window:
        // the month after a termination is when people still need the record.
        $this->assertTrue($employee->fresh()->isTerminated());
    }

    public function test_the_sweep_refuses_the_same_loose_ends_the_button_refuses(): void
    {
        $employee = $this->terminatedDaysAgo('HR-OFFICER-2026-0001', 60);
        $employee->leaveRequests()->create([
            'uuid' => (string) Str::uuid(),
            'leave_type_id' => LeaveType::query()->value('id'),
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->subMonth()->addDay()->toDateString(),
            'requested_days' => 2,
            'reason' => 'Filed before they left and never decided.',
            'status' => 'pending',
        ]);

        $this->artisan('employees:archive-terminated')
            // Named, not counted: this is a record somebody has to go and
            // settle, and a bare number tells nobody which one.
            ->expectsOutputToContain($employee->full_name.' — 1 pending leave request')
            ->assertSuccessful();

        $this->assertNull($employee->fresh()->archived_at);
        $this->assertTrue($employee->user->fresh()->is_active);
    }

    public function test_restoring_a_record_buys_one_more_window_rather_than_an_exemption(): void
    {
        $manager = $this->manager();
        $employee = $this->terminatedDaysAgo('HR-OFFICER-2026-0001', 90);

        $this->actingAs($manager)->post(route('employees.archive', $employee))->assertRedirect();
        $this->actingAs($manager)->post(route('employees.restore', $employee))->assertRedirect();

        $this->assertNotNull($employee->fresh()->restored_at);

        // Terminated three months ago, so a sweep that only read the
        // termination date would file it again tonight — in the middle of
        // whatever the manager pulled it out to check.
        $this->artisan('employees:archive-terminated')->assertSuccessful();
        $this->assertNull($employee->fresh()->archived_at);

        // And this is the half that matters: nobody re-filed it, and a month
        // later it goes back on its own anyway. Forgetting to archive a record
        // by hand is exactly the failure the thirty days exist to cover, and
        // restoring one must not quietly buy an exemption from it.
        $this->travel(31)->days();
        $this->artisan('employees:archive-terminated')->assertSuccessful();

        $employee->refresh();
        $this->assertNotNull($employee->archived_at);
        $this->assertNull($employee->archived_by);
    }

    public function test_archiving_by_hand_inside_the_window_still_names_who_did_it(): void
    {
        $manager = $this->manager();
        $employee = $this->terminatedDaysAgo('HR-OFFICER-2026-0001', 90);

        $this->actingAs($manager)->post(route('employees.archive', $employee))->assertRedirect();
        $this->actingAs($manager)->post(route('employees.restore', $employee))->assertRedirect();
        $this->actingAs($manager)->post(route('employees.archive', $employee))->assertRedirect();

        $employee->refresh();
        $this->assertNotNull($employee->archived_at);
        $this->assertSame($manager->id, $employee->archived_by);
        $this->assertFalse($employee->wasArchivedAutomatically());
    }

    public function test_the_window_is_a_setting_rather_than_a_number_in_the_code(): void
    {
        $employee = $this->terminatedDaysAgo('HR-OFFICER-2026-0001', 10);

        config(['workforce.terminated_archive_after_days' => 7]);
        $this->artisan('employees:archive-terminated')->assertSuccessful();
        $this->assertNotNull($employee->fresh()->archived_at);

        // And one run can be asked for a different window without changing the
        // setting, which is how a backlog gets cleared on purpose.
        $other = $this->terminatedDaysAgo('NUR-HEAD-DERM-2026-0001', 3);
        config(['workforce.terminated_archive_after_days' => 30]);

        $this->artisan('employees:archive-terminated', ['--days' => 2])->assertSuccessful();
        $this->assertNotNull($other->fresh()->archived_at);
    }

    public function test_a_window_of_zero_switches_the_automatic_half_off(): void
    {
        $employee = $this->terminatedDaysAgo('HR-OFFICER-2026-0001', 400);
        config(['workforce.terminated_archive_after_days' => 0]);

        $this->artisan('employees:archive-terminated')
            ->expectsOutputToContain('Automatic archiving is switched off')
            ->assertSuccessful();

        $this->assertNull($employee->fresh()->archived_at);
    }

    public function test_a_dry_run_reports_without_touching_anything(): void
    {
        $employee = $this->terminatedDaysAgo('HR-OFFICER-2026-0001', 45);

        $this->artisan('employees:archive-terminated', ['--dry-run' => true])
            ->expectsOutputToContain('Would archive: '.$employee->full_name)
            ->assertSuccessful();

        $this->assertNull($employee->fresh()->archived_at);
        $this->assertTrue($employee->user->fresh()->is_active);
    }

    public function test_the_clock_starts_when_the_form_ends_the_employment(): void
    {
        $manager = $this->manager();
        $employee = $this->employee('HR-OFFICER-2026-0001');
        $this->assertNull($employee->terminated_at);

        $this->actingAs($manager)
            ->patch(route('employees.update', $employee), $this->payloadFor($employee, ['employment_status' => 'terminated']))
            ->assertSessionHasNoErrors();

        // Stamped from the save itself rather than read off updated_at, which
        // moves every time anybody corrects a phone number.
        $this->assertNotNull($employee->fresh()->terminated_at);
        $this->assertTrue($employee->fresh()->terminated_at->isToday());
    }

    public function test_reinstating_somebody_stops_the_clock_altogether(): void
    {
        $manager = $this->manager();
        $employee = $this->terminatedDaysAgo('HR-OFFICER-2026-0001', 90);
        $this->actingAs($manager)->post(route('employees.archive', $employee))->assertRedirect();
        $this->actingAs($manager)->post(route('employees.restore', $employee))->assertRedirect();

        $this->actingAs($manager)
            ->patch(route('employees.update', $employee), $this->payloadFor($employee->fresh(), ['employment_status' => 'active']))
            ->assertSessionHasNoErrors();

        $employee->refresh();
        // There is no termination left to count from, so there is no clock —
        // not a longer one, none at all.
        $this->assertNull($employee->terminated_at);
        $this->assertNull($employee->restored_at);
        $this->assertTrue($employee->user->fresh()->is_active);

        $this->artisan('employees:archive-terminated', ['--days' => 1])->assertSuccessful();
        $this->assertNull($employee->fresh()->archived_at);
    }

    /**
     * A seeded employee whose employment ended the given number of days ago,
     * with nothing left on the books — the sweep refuses loose ends, and every
     * seeded person starts the week rostered.
     */
    private function terminatedDaysAgo(string $employeeNumber, int $days): Employee
    {
        $employee = $this->employee($employeeNumber);
        $employee->scheduleAssignments()->delete();
        $employee->leaveRequests()->where('status', 'pending')->delete();
        Employee::query()->where('supervisor_id', $employee->id)->update(['supervisor_id' => null]);

        $employee->forceFill([
            'employment_status' => 'terminated',
            'terminated_at' => now()->subDays($days),
        ])->save();

        return $employee->fresh(['user']);
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function payloadFor(Employee $employee, array $overrides = []): array
    {
        return $overrides + [
            'first_name' => $employee->first_name,
            'last_name' => $employee->last_name,
            'email' => $employee->user->email,
            'department_id' => $employee->department_id,
            'position_id' => $employee->position_id,
            'employment_status' => $employee->employment_status,
            'hire_date' => $employee->hire_date->toDateString(),
        ];
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
