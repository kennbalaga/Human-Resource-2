<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'is_active', 'last_login_at'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, TwoFactorAuthenticatable;

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class)->withTimestamps();
    }

    public function employee(): HasOne
    {
        return $this->hasOne(Employee::class);
    }

    public function preference(): HasOne
    {
        return $this->hasOne(UserPreference::class)->withDefault([
            'timezone' => 'Asia/Manila',
            'theme' => 'system',
            'email_notifications' => true,
            'attendance_reminders' => true,
            'schedule_updates' => true,
            'leave_updates' => true,
            'compact_navigation' => false,
            'reduce_motion' => false,
        ]);
    }

    /**
     * Roles that administer the system but must not alter the records inside it.
     * They keep org-wide visibility for support and audit work; the writing is
     * left to HR. See EnforceReadOnlyRole for the routes this actually blocks.
     *
     * @var array<int, string>
     */
    public const READ_ONLY_ROLES = ['system-administrator'];

    public function hasRole(string $slug): bool
    {
        return $this->roles->contains('slug', $slug);
    }

    /**
     * @param  array<int, string>  $slugs
     */
    public function hasAnyRole(array $slugs): bool
    {
        return $this->roles->pluck('slug')->intersect($slugs)->isNotEmpty();
    }

    public function isReadOnly(): bool
    {
        return $this->hasAnyRole(self::READ_ONLY_ROLES);
    }

    /**
     * Whether this account may change workforce records at all. Read-only roles
     * still see everything; this only gates the writing.
     */
    public function canManageData(): bool
    {
        return ! $this->isReadOnly();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
