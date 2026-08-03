<?php

namespace Tests\Feature\Console;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class NotificationPruneTest extends TestCase
{
    use RefreshDatabase;

    public function test_prune_command_deletes_notifications_older_than_one_week_but_keeps_recent_ones(): void
    {
        $this->seed();
        $user = User::query()->where('email', 'employee@hrms.local')->firstOrFail();

        $old = $this->createNotification($user, 'Old notification');
        DB::table('notifications')->where('id', $old->id)->update(['created_at' => now()->subDays(8)]);

        $recent = $this->createNotification($user, 'Recent notification');
        DB::table('notifications')->where('id', $recent->id)->update(['created_at' => now()->subDays(6)]);

        Artisan::call('notifications:prune');

        $this->assertSame(1, $user->notifications()->count());
        $this->assertTrue($user->notifications()->whereKey($recent->id)->exists());
        $this->assertFalse($user->notifications()->whereKey($old->id)->exists());
    }

    private function createNotification(User $user, string $title): \Illuminate\Notifications\DatabaseNotification
    {
        return $user->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => 'App\\Notifications\\PreferenceMailNotification',
            'data' => ['title' => $title, 'message' => 'A workforce update.'],
        ]);
    }
}
