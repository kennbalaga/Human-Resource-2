<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Where a message lands after a page reloads.
 *
 * "It worked" goes to the shared toast on every page, so a success looks the
 * same wherever it happens. A success that still asks something of the reader
 * ('notice') stays a banner on the page, because the toast fades on its own.
 */
class FlashMessageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function manager(): User
    {
        return User::query()->where('email', 'hr.manager@hrms.local')->firstOrFail();
    }

    public function test_a_success_is_shown_in_the_toast_and_not_as_a_banner(): void
    {
        $html = $this->actingAs($this->manager())
            ->withSession(['success' => 'Leave request approved.'])
            ->get('/leaves')
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/<div class="app-toast"[^>]*data-toast\s*>.*?data-toast-message>Leave request approved\.<\/span>/s',
            $html,
            'The success should be drawn into the toast, visible.',
        );
        $this->assertStringNotContainsString('attendance-alert-success', $html);
    }

    public function test_the_toast_stays_hidden_when_there_is_nothing_to_say(): void
    {
        $html = $this->actingAs($this->manager())
            ->get('/leaves')
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('/data-toast\s+hidden/', $html);
    }

    public function test_a_notice_stays_on_the_page_as_a_banner(): void
    {
        $html = $this->actingAs($this->manager())
            ->withSession(['notice' => 'Ask them to download the new badge.'])
            ->get('/employees')
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/attendance-alert-success[^>]*>\s*<svg.*?<span>Ask them to download the new badge\.<\/span>/s',
            $html,
        );
        $this->assertMatchesRegularExpression('/data-toast\s+hidden/', $html);
    }
}
