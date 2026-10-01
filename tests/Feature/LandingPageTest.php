<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The product's front door.
 *
 * `/` used to redirect to the sign-in form whoever asked for it, which left
 * the system with no public page at all. It now answers the two readers it
 * actually has: somebody signed out, who is shown what this is, and somebody
 * already signed in, who has no use for a sales page and is sent to work.
 */
class LandingPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_visitor_who_is_signed_out_gets_the_landing_page(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertViewIs('landing')
            ->assertSee(config('branding.tagline'))
            ->assertSee('Every shift, every hour, every department');
    }

    public function test_it_offers_the_sign_in_page_rather_than_a_form_of_its_own(): void
    {
        // Nothing on this page posts, so it carries no CSRF token and no
        // credential fields -- it hands the reader to the real form.
        $response = $this->get('/')->assertOk();

        $response->assertSee(route('login'), false);
        $response->assertDontSee('<form', false);
        $response->assertDontSee('type="password"', false);
    }

    /**
     * Every destination it names is a route in this application. A landing
     * page that advertises a module the product does not have is the one bug
     * nobody files, because the people who would notice never see it.
     */
    public function test_its_links_point_at_real_routes(): void
    {
        $response = $this->get('/')->assertOk();

        foreach ([route('login'), route('privacy-policy'), route('landing')] as $url) {
            $response->assertSee($url, false);
        }
    }

    public function test_a_signed_in_user_is_sent_to_their_dashboard_instead(): void
    {
        $this->seed();
        $user = User::query()->where('email', 'hr.manager@hrms.local')->firstOrFail();

        $this->actingAs($user)->get('/')->assertRedirect(route('dashboard'));
    }

    /** The staff who use this already know the address; a search engine does not need it. */
    public function test_it_asks_not_to_be_indexed(): void
    {
        $this->get('/')->assertOk()->assertSee('noindex', false);
    }
}
