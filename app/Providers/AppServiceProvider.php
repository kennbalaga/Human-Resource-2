<?php

namespace App\Providers;

use App\Services\Security\AttachmentMalwareScanner;
use App\Services\Security\ClamAvAttachmentScanner;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Events\ValidTwoFactorAuthenticationCodeProvided;
use Laravel\Fortify\Fortify;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // The application keeps its employee-ID login and password-reset routes.
        Fortify::ignoreRoutes();

        $this->app->singleton(AttachmentMalwareScanner::class, ClamAvAttachmentScanner::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Password::defaults(fn () => Password::min(12)->mixedCase()->numbers()->symbols());

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(60)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('api-login', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));
        RateLimiter::for('password-reset', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));
        RateLimiter::for('csp-report', fn (Request $request) => Limit::perMinute(30)->by($request->ip()));
        RateLimiter::for('two-factor', fn (Request $request) => Limit::perMinute(5)->by(
            ($request->session()->get('login.id') ?: $request->ip()).'|'.$request->ip(),
        ));

        Fortify::twoFactorChallengeView(fn () => view('auth.two-factor-challenge'));

        $this->app['events']->listen(ValidTwoFactorAuthenticationCodeProvided::class, function ($event): void {
            $event->user->forceFill(['last_login_at' => now()])->save();
        });

        View::composer('partials.topbar', function ($view): void {
            $user = auth()->user();

            $view->with([
                'notificationItems' => $user?->unreadNotifications()->latest()->limit(5)->get() ?? collect(),
                'notificationUnreadCount' => $user?->unreadNotifications()->count() ?? 0,
            ]);
        });
    }
}
