<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * The root route used to redirect guests to the sign-in form. It now
     * answers with the landing page instead, so this guards the one thing
     * that did not change: `/` is reachable signed out and does not demand
     * credentials to show something.
     *
     * What the page itself contains, and where a signed-in reader is sent,
     * are covered in LandingPageTest.
     */
    public function test_the_root_route_is_public(): void
    {
        $response = $this->get('/');

        $response->assertOk();
    }
}
