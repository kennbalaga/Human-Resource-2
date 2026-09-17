<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Middleware\ConfirmPasswordForDownload;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ConfirmPasswordController extends Controller
{
    public function create(Request $request): View|RedirectResponse
    {
        $pending = $request->session()->get(ConfirmPasswordForDownload::PENDING_KEY);

        // Nothing is waiting on an answer, so there is nothing to confirm for.
        if ($pending === null) {
            return redirect()->route('dashboard');
        }

        return view('auth.confirm-password', [
            'cancelUrl' => $pending['return_to'] ?? route('dashboard'),
        ]);
    }

    public function store(Request $request): RedirectResponse|Response
    {
        $request->validate([
            'password' => ['required', 'string', 'max:255'],
        ]);

        $user = $request->user();

        if (! Hash::check((string) $request->input('password'), (string) $user->password)) {
            Log::notice('HRMS download password confirmation failed.', [
                'event' => 'password_confirmation.failure',
                'user_id' => $user->id,
                'ip_address' => $request->ip(),
                'request_id' => $request->headers->get('X-Request-ID'),
            ]);

            throw ValidationException::withMessages([
                'password' => ['The password is incorrect.'],
            ]);
        }

        $request->session()->passwordConfirmed();

        $pending = $request->session()->pull(ConfirmPasswordForDownload::PENDING_KEY);

        // The modal asked, so it starts the file itself: nowhere to redirect to
        // and nothing to reload, which is the point of asking in place.
        if ($request->expectsJson()) {
            return response()->noContent();
        }

        if ($pending === null) {
            return redirect()->route('dashboard');
        }

        // Back to the page the link was on, which starts the file itself: a
        // redirect straight to the download would leave this form on screen.
        return redirect()->to($pending['return_to'] ?? route('dashboard'))
            ->with('download.start', $pending['url']);
    }
}
