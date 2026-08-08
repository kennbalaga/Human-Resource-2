<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Models\User;
use Illuminate\Support\Facades\Gate;

trait AuthorizesWorkforce
{
    protected function canManage(User $user): bool
    {
        return Gate::forUser($user)->allows('workforce.view');
    }

    protected function requireManager(User $user): void
    {
        abort_unless($this->canManage($user) && $user->tokenCan('workforce:write'), 403, 'This token cannot manage workforce records.');
    }

    protected function requireRead(User $user): void
    {
        abort_unless($user->tokenCan('workforce:read'), 403, 'This token cannot read workforce records.');
    }
}
