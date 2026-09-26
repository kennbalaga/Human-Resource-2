<?php

namespace Tests\Feature\Pwa;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The dashboard's phone fold.
 *
 * The same two facts as the desktop pair — today's attendance and today's
 * shift — composed into one card for a screen with one fold. Which of the two
 * is visible is a CSS decision; what these cover is that both are in the
 * document and that the phone one carries the things a phone needs.
 */
class StaffPhoneFoldTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
    }

    public function test_the_phone_fold_is_rendered_beside_the_desktop_pair(): void
    {
        $response = $this->actingAs($this->employeeUser())
            ->get('/dashboard')
            ->assertOk();

        $response->assertSee('staff-phone-fold', false);
        $response->assertSee('staff-desk-fold', false);
    }

    /**
     * The fold's whole argument is that the reader is the way time is recorded
     * and the phone is not. Both routes out of it have to be there.
     */
    public function test_it_offers_the_badge_beside_the_manual_punch(): void
    {
        $this->actingAs($this->employeeUser())
            ->get('/dashboard')
            ->assertOk()
            ->assertSee(route('profile.badge'), false)
            ->assertSee('show your badge at the entrance', false);
    }

    /**
     * Two clock forms are in the document at once — one per fold — so the
     * manual reason field cannot use the same id in both. A duplicate id would
     * point the desktop card's label at the phone card's input.
     */
    public function test_the_two_folds_do_not_share_a_field_id(): void
    {
        $html = $this->actingAs($this->employeeUser())
            ->get('/dashboard')
            ->assertOk()
            ->getContent();

        $this->assertLessThanOrEqual(1, substr_count($html, 'id="phoneManualReason"'));
    }

    private function employeeUser(): User
    {
        return User::query()->with('employee')->where('email', 'employee@hrms.local')->firstOrFail();
    }
}
