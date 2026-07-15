<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\View\View;

class PasswordResetLinkController extends Controller
{
    private const GENERIC_STATUS = 'If the details match an active account, a password reset link has been sent to the registered work email.';

    public function create(): View
    {
        return view('auth.forgot-password');
    }

    public function store(ForgotPasswordRequest $request): RedirectResponse
    {
        $employeeNumber = Str::upper(trim((string) $request->validated('employee_id')));
        $email = Str::lower(trim((string) $request->validated('email')));

        $user = User::query()
            ->where('email', $email)
            ->where('is_active', true)
            ->whereHas('employee', fn (Builder $query) => $query
                ->where('employee_number', $employeeNumber)
                ->where('employment_status', 'active'))
            ->first();

        if ($user !== null) {
            Password::sendResetLink(['email' => $user->email]);
        }

        return back()->with('status', self::GENERIC_STATUS);
    }
}
