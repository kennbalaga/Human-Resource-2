<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A phone whose app lock stands in for the authenticator code.
 *
 * Holds no PIN and no biometric — see the migration for why that is a rule
 * rather than a coincidence. `token_hash` is hidden for the same reason every
 * other credential digest on {@see User} is: nothing has a reason to serialise
 * it, and the one place it is compared reads it off the model directly.
 */
#[Fillable(['user_id', 'device_hash', 'token_hash', 'armed_at', 'last_used_at'])]
#[Hidden(['token_hash', 'device_hash'])]
class TrustedMobileDevice extends Model
{
    protected function casts(): array
    {
        return [
            'armed_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
