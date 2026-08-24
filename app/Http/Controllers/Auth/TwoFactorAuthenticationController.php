<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Services\TwoFactorService;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class TwoFactorAuthenticationController extends Controller
{
    public function __construct(private readonly TwoFactorService $twoFactor) {}

    public function setup(Request $request): View|RedirectResponse
    {
        $user = $request->user();
        if ($user->two_factor_enabled_at) {
            return redirect()->route('two-factor.challenge');
        }
        $secret = $request->session()->remember('two_factor_setup_secret', fn () => $this->twoFactor->createSecret());
        $label = rawurlencode('Workforce HRMS:'.$user->email);
        $uri = "otpauth://totp/{$label}?secret={$secret}&issuer=".rawurlencode('Workforce HRMS').'&algorithm=SHA1&digits=6&period=30';

        $options = new QROptions([
            'outputType' => QRCode::OUTPUT_MARKUP_SVG,
            'eccLevel' => QRCode::ECC_M,
            'scale' => 5,
            'svgAddXmlHeader' => false,
        ]);

        $qrCode = (new QRCode($options))->render($uri);

        // The QR code is a base64 data URI, we need to add width/height to the SVG inside
        // Extract the SVG content, add width/height, and re-encode
        $svg = base64_decode(str_replace('data:image/svg+xml;base64,', '', $qrCode));
        $svg = str_replace('<svg ', '<svg width="245" height="245" ', $svg);
        $qrCode = 'data:image/svg+xml;base64,'.base64_encode($svg);

        return view('auth.two-factor.setup', compact('secret', 'qrCode'));
    }

    public function confirmSetup(Request $request): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'digits:6']]);
        $secret = $request->session()->get('two_factor_setup_secret');
        if (! $secret || ! $this->twoFactor->verify($secret, $data['code'])) {
            throw ValidationException::withMessages(['code' => 'That code is not valid. Check your device time and try again.']);
        }
        $codes = $this->twoFactor->enroll($request->user(), $secret);
        $request->session()->forget('two_factor_setup_secret');
        $request->session()->put('two_factor_verified_at', now()->toIso8601String());
        $request->session()->put('two_factor_recovery_codes', $codes);
        $this->twoFactor->audit($request, $request->user(), 'two_factor.enrolled');

        return redirect()->route('two-factor.recovery-codes');
    }

    public function challenge(Request $request): View|RedirectResponse
    {
        if (! $request->user()->two_factor_enabled_at) {
            return redirect()->route('two-factor.setup');
        }

        return view('auth.two-factor.challenge');
    }

    public function verify(Request $request): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:20']]);
        $user = $request->user();
        $key = 'two-factor:'.$user->id.'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5) || ($user->two_factor_locked_until?->isFuture())) {
            $seconds = max(RateLimiter::availableIn($key), (int) now()->diffInSeconds($user->two_factor_locked_until, false));
            throw ValidationException::withMessages(['code' => "Too many invalid codes. Please try again in {$seconds} seconds."]);
        }

        $code = strtoupper(trim($data['code']));
        $recovery = $this->twoFactor->validRecoveryCode($user, $code);
        $valid = preg_match('/^\d{6}$/', $code) && $this->twoFactor->verify($this->twoFactor->secret($user), $code);
        if (! $valid && ! $recovery) {
            RateLimiter::hit($key, 900);
            $attempts = $user->two_factor_failed_attempts + 1;
            $user->forceFill(['two_factor_failed_attempts' => $attempts, 'two_factor_locked_until' => $attempts >= 5 ? now()->addMinutes(15) : null])->save();
            $this->twoFactor->audit($request, $user, 'two_factor.failed', ['attempt' => $attempts]);
            throw ValidationException::withMessages(['code' => 'The authentication code is invalid.']);
        }

        if ($recovery) {
            $recovery->update(['used_at' => now()]);
            $this->twoFactor->audit($request, $user, 'two_factor.recovery_code_used');
        } else {
            $this->twoFactor->audit($request, $user, 'two_factor.verified');
        }
        RateLimiter::clear($key);
        $user->forceFill(['two_factor_failed_attempts' => 0, 'two_factor_locked_until' => null])->save();
        $request->session()->regenerate();
        $request->session()->put('two_factor_verified_at', now()->toIso8601String());

        return redirect()->intended(route('dashboard'));
    }

    public function recoveryCodes(Request $request): View
    {
        abort_unless($request->session()->has('two_factor_recovery_codes'), 403);

        return view('auth.two-factor.recovery-codes', ['codes' => $request->session()->get('two_factor_recovery_codes')]);
    }

    public function downloadRecoveryCodes(Request $request)
    {
        abort_unless($request->session()->has('two_factor_recovery_codes'), 403);
        $contents = "Workforce HRMS recovery codes\r\nStore these offline. Each code works once.\r\n\r\n".implode("\r\n", $request->session()->pull('two_factor_recovery_codes'));

        return response($contents, 200, ['Content-Type' => 'text/plain; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="workforce-2fa-recovery-codes.txt"']);
    }

    public function reset(Request $request, Employee $employee): RedirectResponse
    {
        abort_unless($request->user()->roles->pluck('slug')->intersect(['system-administrator', 'hr-manager'])->isNotEmpty(), 403);
        $request->validate(['identity_verified' => ['accepted']]);
        $target = $employee->user;
        abort_unless($target, 404);
        $target->recoveryCodes()->delete();
        $target->forceFill(['two_factor_secret' => null, 'two_factor_enabled_at' => null, 'two_factor_failed_attempts' => 0, 'two_factor_locked_until' => null])->save();
        DB::table('sessions')->where('user_id', $target->id)->delete();
        $target->tokens()->delete();
        $this->twoFactor->audit($request, $target, 'two_factor.reset_by_administrator', ['reset_by' => $request->user()->id]);

        return back()->with('success', '2FA was reset after identity verification. The employee must enroll again at next sign-in.');
    }
}
