<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Security\AttachmentMalwareScanner;
use App\Services\Security\ClamAvAttachmentScanner;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
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
        $this->registerAuthorizationGates();

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

            if ($user === null) {
                $view->with(['notificationItems' => collect(), 'notificationUnreadCount' => 0]);

                return;
            }

            // The topbar renders on every page, so the unread total rides along as
            // a subselect instead of costing a second round trip to the database.
            $items = $user->unreadNotifications()
                ->select('notifications.*')
                ->selectSub(
                    $user->unreadNotifications()->getQuery()->selectRaw('count(*)'),
                    'unread_total',
                )
                ->latest()
                ->limit(5)
                ->get();

            $view->with([
                'notificationItems' => $items,
                'notificationUnreadCount' => (int) ($items->first()->unread_total ?? 0),
            ]);
        });
    }

    /**
     * The three role bundles repeated across the workforce/leave/schedule
     * surfaces, centralized here instead of duplicating
     * roles->pluck('slug')->intersect([...]) in every controller/request.
     */
    private function registerAuthorizationGates(): void
    {
        Gate::define('workforce.view', fn (User $user) => $user->hasAnyRole([
            'system-administrator', 'hr-manager', 'department-head',
        ]));

        Gate::define('workforce.manage', fn (User $user) => $user->hasAnyRole([
            'system-administrator', 'hr-manager', 'department-head',
        ]) && $user->canManageData());

        Gate::define('hr.view', fn (User $user) => $user->hasAnyRole([
            'system-administrator', 'hr-manager',
        ]));

        Gate::define('hr.manage', fn (User $user) => $user->hasAnyRole([
            'system-administrator', 'hr-manager',
        ]) && $user->canManageData());

        Gate::define('system.manage', fn (User $user) => $user->hasRole('system-administrator'));

        Gate::define('attendance.override', fn (User $user) => $user->hasAnyRole([
            'system-administrator', 'hr-manager', 'department-head',
        ]) && $user->canManageData());
    }
}
