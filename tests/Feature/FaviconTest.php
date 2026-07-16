<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FaviconTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_and_authenticated_pages_use_the_same_hospital_favicon(): void
    {
        $this->seed();
        $faviconUrl = asset('favicon.svg').'?v=20260716';

        $this->get(route('login'))->assertOk()->assertSee($faviconUrl, false);
        $this->get(route('password.request'))->assertOk()->assertSee($faviconUrl, false);
        $this->get(route('password.reset', ['token' => 'preview-token']))->assertOk()->assertSee($faviconUrl, false);

        $user = User::query()->where('email', 'hr.manager@hrms.local')->firstOrFail();
        $this->actingAs($user)->get(route('dashboard'))->assertOk()->assertSee($faviconUrl, false);
        $this->actingAs($user)->get(route('attendance.index'))->assertOk()->assertSee($faviconUrl, false);

        $this->assertFileExists(public_path('favicon.svg'));
        $this->assertGreaterThan(0, filesize(public_path('favicon.svg')));
    }
}
