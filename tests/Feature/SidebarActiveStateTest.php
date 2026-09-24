<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every page says where it is.
 *
 * A screen reached by a cross-link rather than by the rail -- "Manage rooms" on
 * the room board, the override screen under attendance -- used to leave the
 * whole rail unlit, so the reader had no way to tell which section they had
 * landed in. The rail's entries match on route name, and a route nobody
 * remembered to list simply matched nothing.
 *
 * The four screens with no rail entry at all (profile, settings, audit logs,
 * integrations) are covered by the topbar's context line instead, which is
 * asserted at the bottom.
 */
class SidebarActiveStateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function account(string $email): User
    {
        return User::query()->where('email', $email)->firstOrFail();
    }

    /** The label of the one rail entry marked as current. */
    private function activeRailEntry(string $url): string
    {
        // The HR manager rather than the administrator: system-administrator is
        // a READ_ONLY_ROLE here, so it is refused the attendance override screen.
        $html = $this->actingAs($this->account('hr.manager@hrms.local'))->get($url)->assertOk()->getContent();

        preg_match_all('/<a[^>]*title="([^"]+)"[^>]*class="sidebar-link active"[^>]*>/', $html, $matches);

        $this->assertCount(1, $matches[1], "Expected exactly one active rail entry on {$url}.");

        return $matches[1][0];
    }

    /** @return array<string, array{string, string}> */
    public static function railPages(): array
    {
        return [
            'rooms directory' => ['/rooms', 'Organization'],
            'employee directory' => ['/employees', 'Organization'],
            'attendance override' => ['/attendance/override', 'Attendance'],
            'attendance' => ['/attendance', 'Attendance'],
            'attendance report' => ['/attendance/reports', 'Reports'],
            'room board' => ['/schedules/rooms', 'Room board'],
            'schedules' => ['/schedules', 'Schedules'],
            'shift templates' => ['/shifts', 'Shift templates'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('railPages')]
    public function test_the_rail_marks_the_section_the_page_belongs_to(string $url, string $expected): void
    {
        $this->assertSame($expected, $this->activeRailEntry($url));
    }

    public function test_pages_without_a_rail_entry_are_named_by_the_topbar(): void
    {
        // Audit logs are a System Administrator tool, so that account opens both.
        $admin = $this->account('admin@hrms.local');

        foreach (['/settings' => 'Account', '/audit-logs' => 'Account'] as $url => $expected) {
            $html = $this->actingAs($admin)->get($url)->assertOk()->getContent();

            $this->assertMatchesRegularExpression(
                '/<span class="topbar-context">\s*<strong>'.preg_quote($expected, '/').'<\/strong>/',
                $html,
                "Expected the topbar to name {$expected} on {$url}.",
            );
        }
    }
}
