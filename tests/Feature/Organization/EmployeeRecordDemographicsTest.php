<?php

namespace Tests\Feature\Organization;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Gender and the solo parent ID are what the statutory leave gates read, so
 * the record has to show them -- otherwise the only way to find out why
 * somebody cannot request maternity or solo parent leave is to try it and read
 * the refusal.
 *
 * They are not shown on the same terms. Gender sits on the open part of the
 * record; a solo parent ID is a government-issued number, and it goes behind
 * the same gate as the contact details.
 */
class EmployeeRecordDemographicsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
    }

    public function test_hr_sees_gender_and_the_solo_parent_id_on_the_record(): void
    {
        $manager = User::query()->where('email', 'hr.manager@hrms.local')->firstOrFail();
        $employee = $this->subject();

        $this->actingAs($manager)->get(route('employees.show', [$employee, 'panel' => 1]))
            ->assertOk()
            ->assertSee('Gender')
            ->assertSee('Female')
            ->assertSee('Solo parent ID')
            ->assertSee('SP-2026-0042')
            ->assertSee('valid to');
    }

    public function test_an_employee_sees_the_solo_parent_id_on_their_own_record(): void
    {
        $employee = $this->subject();
        $account = User::query()->findOrFail($employee->user_id);

        $this->actingAs($account)->get(route('employees.show', [$employee, 'panel' => 1]))
            ->assertOk()
            ->assertSee('SP-2026-0042');
    }

    /**
     * A lapsed ID is shown rather than hidden, and says what it costs. HR
     * needs to see why the seven days stopped being available.
     */
    public function test_a_lapsed_solo_parent_id_is_shown_with_its_consequence(): void
    {
        $manager = User::query()->where('email', 'hr.manager@hrms.local')->firstOrFail();
        $employee = $this->subject();
        $employee->update(['solo_parent_id_expires_on' => now()->subMonth()->toDateString()]);

        $this->actingAs($manager)->get(route('employees.show', [$employee, 'panel' => 1]))
            ->assertOk()
            ->assertSee('expired')
            ->assertSee('solo parent leave withheld until renewed');
    }

    public function test_an_unrecorded_attribute_reads_as_not_recorded(): void
    {
        $manager = User::query()->where('email', 'hr.manager@hrms.local')->firstOrFail();
        $employee = $this->subject();
        $employee->update([
            'gender' => null,
            'solo_parent_id_number' => null,
            'solo_parent_id_expires_on' => null,
        ]);

        $this->actingAs($manager)->get(route('employees.show', [$employee, 'panel' => 1]))
            ->assertOk()
            ->assertSeeInOrder(['Gender', 'Not recorded']);
    }

    private function subject(): Employee
    {
        $employee = Employee::query()->whereNotNull('user_id')->firstOrFail();

        $employee->update([
            'gender' => 'female',
            'solo_parent_id_number' => 'SP-2026-0042',
            'solo_parent_id_expires_on' => now()->addYear()->toDateString(),
        ]);

        return $employee->refresh();
    }
}
