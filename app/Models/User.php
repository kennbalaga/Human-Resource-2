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
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes', 'active_session_token', 'active_session_device_hash'])]
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
     * Roles that run the whole organisation. Everyone else with a supervisory
     * role runs one unit and must not read or write another unit's records.
     *
     * @var array<int, string>
     */
    public const ORGANISATION_WIDE_ROLES = ['system-administrator', 'hr-manager'];

    /**
     * Departments whose workforce records this account may touch.
     *
     * `null` means "every department" and is deliberately distinct from an
     * empty array: null is org-wide authority, `[]` is no supervisory reach at
     * all. Callers must therefore test for null explicitly rather than relying
     * on emptiness, which is why `scopeVisibleTo()` and `supervises()` exist —
     * so that distinction is made in one place instead of at every call site.
     *
     * A department head supervises the unit they are posted to. Their own
     * employee row is the only record of that posting, so a head without an
     * employee row, or without a department on it, supervises nothing rather
     * than everything.
     *
     * @return array<int, int>|null
     */
    public function supervisedDepartmentIds(): ?array
    {
        if ($this->hasAnyRole(self::ORGANISATION_WIDE_ROLES)) {
            return null;
        }

        if (! $this->hasRole('department-head')) {
            return [];
        }

        $departmentId = $this->employee?->department_id;

        return $departmentId === null ? [] : [(int) $departmentId];
    }

    /**
     * Whether this account may act on the given employee's workforce records.
     *
     * An employee with no department is visible only to the org-wide roles: a
     * department head has no unit in common with them, so there is nothing to
     * place them under that head's authority.
     */
    public function supervises(?Employee $employee): bool
    {
        if ($employee === null) {
            return false;
        }

        $departmentIds = $this->supervisedDepartmentIds();

        if ($departmentIds === null) {
            return true;
        }

        return $employee->department_id !== null
            && in_array((int) $employee->department_id, $departmentIds, true);
    }

    /**
     * Whether this account sees the whole organisation rather than one unit.
     * Used for the screens that show a department picker at all.
     */
    public function hasOrganisationWideReach(): bool
    {
        return $this->supervisedDepartmentIds() === null;
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
            'active_session_last_seen_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
