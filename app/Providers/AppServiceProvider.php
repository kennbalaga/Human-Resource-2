<?php

namespace App\Providers;

use App\Http\Middleware\ConfirmPasswordForDownload;
use App\Models\Employee;
use App\Models\User;
use App\Services\Security\AttachmentMalwareScanner;
use App\Services\Security\ClamAvAttachmentScanner;
use App\Services\SidebarBadgeService;
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
        // The punch endpoint is unauthenticated until its body signature is
        // checked, so it is limited twice, for two different problems.
        //
        // This one runs before the signature and keys on the address, to cap
        // what an anonymous caller can spend of the server's time. It is the
        // looser of the two because it is shared: one hospital NAT address
        // fronts every bridge PC.
        RateLimiter::for('biometric-bridge', fn (Request $request) => Limit::perMinute(
            (int) config('attendance.biometric_bridge.requests_per_minute', 120),
        )->by('biometric-bridge|'.$request->ip()));
        // This one runs after the signature, so the serial it keys on has been
        // proven rather than claimed -- a header alone could otherwise be
        // rotated to escape the limit. Sized for a 60-second poll plus the
        // burst of batches a bridge flushes when connectivity returns.
        RateLimiter::for('biometric-bridge-device', fn (Request $request) => Limit::perMinute(
            (int) config('attendance.biometric_bridge.device_requests_per_minute', 60),
        )->by('biometric-bridge-device|'.($request->headers->get('X-Bridge-Device') ?: $request->ip())));
        // Keyed to the account, not the IP: the point is to slow an account
        // being emptied, and a ward shares one address across many staff.
        RateLimiter::for('downloads', fn (Request $request) => Limit::perMinute(
            (int) config('security.downloads.per_minute', 20),
        )->by('downloads|'.($request->user()?->id ?: $request->ip())));
        RateLimiter::for('password-confirm', fn (Request $request) => Limit::perMinute(5)->by(
            'password-confirm|'.($request->user()?->id ?: $request->ip()),
        ));
        RateLimiter::for('two-factor', fn (Request $request) => Limit::perMinute(5)->by(
            ($request->session()->get('login.id') ?: $request->ip()).'|'.$request->ip(),
        ));

        Fortify::twoFactorChallengeView(fn () => view('auth.two-factor-challenge'));

        $this->app['events']->listen(ValidTwoFactorAuthenticationCodeProvided::class, function ($event): void {
            $event->user->forceFill(['last_login_at' => now()])->save();
        });

        // The modal needs to know whether the password is already in, so a
        // click inside the window downloads without being interrupted.
        View::composer('partials.download-confirm', function ($view): void {
            $view->with([
                'downloadConfirmedUntil' => ConfirmPasswordForDownload::confirmedUntil(request()),
                'downloadPasswordTimeout' => (int) config('security.downloads.password_timeout_seconds', 900),
            ]);
        });

        // The rail is on screen in every module, so what is waiting on the
        // reader rides along with it rather than only on the dashboard.
        View::composer(['partials.sidebar', 'partials.mobile-tabbar'], function ($view): void {
            $view->with('sidebarBadges', app(SidebarBadgeService::class)->forUser(auth()->user()));
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
                    $user->unreadNotifications()->getQuery()->reorder()->selectRaw('count(*)'),
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

        // Other people's burnout risk. Narrower than workforce.view on purpose:
        // a system administrator runs the platform, not the people in it, so
        // they see only their own card like any employee. HR sees the whole
        // hospital and a department head their own unit, which is the reach
        // User::supervisedDepartmentIds() already gives each of them.
        Gate::define('burnout.view-workforce', fn (User $user) => $user->hasAnyRole([
            'hr-manager', 'department-head',
        ]));

        Gate::define('attendance.override', fn (User $user) => $user->hasAnyRole([
            'system-administrator', 'hr-manager', 'department-head',
        ]) && $user->canManageData());

        // The role gates above answer "may this account use the supervisory
        // screens at all". These two answer the question that actually protects
        // one unit's records from another's: "may it act on THIS employee".
        // A department head passes the role gate for the whole organisation and
        // must still be turned away at every record outside their own unit.
        //
        // A null employee fails both. A record whose employee has been detached
        // is not a record anyone but the org-wide roles may reach into.
        Gate::define('workforce.view.record', fn (User $user, ?Employee $employee) => $user->hasAnyRole([
            'system-administrator', 'hr-manager', 'department-head',
        ]) && $user->supervises($employee));

        Gate::define('workforce.manage.record', fn (User $user, ?Employee $employee) => $user->hasAnyRole([
            'system-administrator', 'hr-manager', 'department-head',
        ]) && $user->canManageData() && $user->supervises($employee));

        Gate::define('attendance.override.record', fn (User $user, ?Employee $employee) => $user->hasAnyRole([
            'system-administrator', 'hr-manager', 'department-head',
        ]) && $user->canManageData() && $user->supervises($employee));
    }
}
