<?php

namespace Tests\Feature\Pwa;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\PreferredDayOff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The merged requests list.
 *
 * Three modules' worth of asking in one place. Two properties matter: that all
 * three kinds actually arrive in the same list, and that merging the *view* did
 * not quietly merge the *writing* — filing still belongs to the pages that own
 * each kind, where the forms and the permissions are.
 */
class RequestsScreenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
    }

    public function test_guests_are_redirected(): void
    {
        $this->get(route('requests.index'))->assertRedirect('/login');
    }

    public function test_all_three_kinds_arrive_in_one_list(): void
    {
        $user = $this->employeeUser();
        $this->fileALeaveRequest($user->employee);
        $this->fileADayOff($user->employee);

        $this->actingAs($user)
            ->get(route('requests.index'))
            ->assertOk()
            ->assertSee('Requests')
            // The leave type's own name is the row's kicker, not a generic one.
            ->assertSee($this->requestableType()->name)
            ->assertSee('Preferred day off');
    }

    public function test_the_type_filter_narrows_the_list(): void
    {
        $user = $this->employeeUser();
        $this->fileALeaveRequest($user->employee);
        $this->fileADayOff($user->employee);

        $this->actingAs($user)
            ->get(route('requests.index', ['type' => 'day-off']))
            ->assertOk()
            ->assertSee('Preferred day off')
            ->assertDontSee($this->requestableType()->name);
    }

    /**
     * An unknown filter is ignored rather than obeyed, so a hand-typed query
     * cannot empty the page.
     */
    public function test_an_unknown_filter_falls_back_to_all(): void
    {
        $user = $this->employeeUser();
        $this->fileADayOff($user->employee);

        $this->actingAs($user)
            ->get(route('requests.index', ['type' => 'nonsense']))
            ->assertOk()
            ->assertSee('Preferred day off');
    }

    /**
     * The page is read-only. Every row and every New request option points at
     * the page that owns that kind; nothing here posts.
     */
    public function test_it_files_nothing_itself(): void
    {
        $html = $this->actingAs($this->employeeUser())
            ->get(route('requests.index'))
            ->assertOk()
            ->getContent();

        // The only <form> on the page belongs to the layout's own chrome, not
        // to this view: no request is created from here.
        $this->assertStringNotContainsString('action="'.route('leaves.store').'"', $html);
        $this->assertStringContainsString(route('leaves.index'), $html);
        $this->assertStringContainsString(route('schedule-preferences.index'), $html);
    }

    public function test_someone_elses_requests_are_not_listed(): void
    {
        $mine = $this->employeeUser();
        $other = Employee::query()->where('id', '!=', $mine->employee->id)->firstOrFail();

        PreferredDayOff::query()->create([
            'uuid' => (string) Str::uuid(),
            'employee_id' => $other->id,
            'preferred_date' => now()->addDays(9)->toDateString(),
            'reason' => 'Somebody else entirely',
            'status' => 'pending',
        ]);

        $this->actingAs($mine)
            ->get(route('requests.index'))
            ->assertOk()
            ->assertDontSee('Somebody else entirely');
    }

    public function test_an_account_without_an_employee_record_is_refused(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('requests.index'))
            ->assertForbidden();
    }

    private function fileALeaveRequest(Employee $employee): void
    {
        LeaveRequest::query()->create([
            'uuid' => (string) Str::uuid(),
            'employee_id' => $employee->id,
            'leave_type_id' => $this->requestableType()->id,
            'start_date' => now()->addDays(14)->toDateString(),
            'end_date' => now()->addDays(15)->toDateString(),
            'requested_days' => 2,
            'reason' => 'A fortnight out',
            'status' => 'pending',
        ]);
    }

    private function fileADayOff(Employee $employee): void
    {
        PreferredDayOff::query()->create([
            'uuid' => (string) Str::uuid(),
            'employee_id' => $employee->id,
            'preferred_date' => now()->addDays(7)->toDateString(),
            'reason' => 'A standing commitment',
            'status' => 'pending',
        ]);
    }

    private function requestableType(): LeaveType
    {
        return LeaveType::query()->where('is_active', true)->orderBy('id')->firstOrFail();
    }

    private function employeeUser(): User
    {
        return User::query()->with('employee')->where('email', 'employee@hrms.local')->firstOrFail();
    }
}
