<?php

declare(strict_types=1);

namespace AmjadIqbal\FirstPartyAnalytics;

use AmjadIqbal\FirstPartyAnalytics\Console\PruneCommand;
use AmjadIqbal\FirstPartyAnalytics\Contracts\DetectsBots;
use AmjadIqbal\FirstPartyAnalytics\Http\Authorize;
use AmjadIqbal\FirstPartyAnalytics\Http\DashboardController;
use AmjadIqbal\FirstPartyAnalytics\Http\EventController;
use AmjadIqbal\FirstPartyAnalytics\Http\TrackVisits;
use AmjadIqbal\FirstPartyAnalytics\Support\CrawlerDetectBots;
use Composer\InstalledVersions;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Registered;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Throwable;

final class FirstPartyAnalyticsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/first-party-analytics.php', 'first-party-analytics');
        $this->app->singleton(Store::class);
        $this->app->singleton(Recorder::class);
        $this->app->singleton(Visits::class);
        $this->app->singleton(Analytics::class);
        $this->app->singletonIf(DetectsBots::class, CrawlerDetectBots::class);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'first-party-analytics');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/first-party-analytics.php' => config_path('first-party-analytics.php')], 'first-party-analytics-config');
            $this->publishes([__DIR__.'/../database/migrations' => database_path('migrations')], 'first-party-analytics-migrations');
            $this->publishes([__DIR__.'/../resources/views' => resource_path('views/vendor/first-party-analytics')], 'first-party-analytics-views');
            $this->commands([PruneCommand::class]);
        }

        // The default until the app defines its own gate (app providers boot after this one, so theirs wins).
        if (! Gate::has('viewFirstPartyAnalytics')) {
            Gate::define('viewFirstPartyAnalytics', fn ($user = null): bool => $this->app->environment('local'));
        }

        $router = $this->app->make(Router::class);
        $router->aliasMiddleware('analytics.track', TrackVisits::class);

        if (config('first-party-analytics.auto_track', true)) {
            // Through the HTTP kernel: it owns the groups and syncs them to the router (a direct router
            // push is overwritten when the kernel syncs).
            try {
                $this->app->make(HttpKernel::class)->appendMiddlewareToGroup('web', TrackVisits::class);
            } catch (Throwable) {
                // A custom kernel without that method, or no "web" group yet.
                $router->pushMiddlewareToGroup('web', TrackVisits::class);
            }
        }

        $this->registerRoutes();

        // Everything a request recorded is written once, after the response has been sent.
        $this->app->terminating(fn () => $this->app->make(Recorder::class)->flush());

        // Signing in or signing up links the visit (and, with the visitor cookie, earlier visits) to the user.
        Event::listen(Login::class, fn (Login $event) => $this->safely(fn () => $this->app->make(Analytics::class)->identify($event->user)));
        Event::listen(Registered::class, fn (Registered $event) => $this->safely(fn () => $this->app->make(Analytics::class)->identify($event->user)));

        Blade::directive('firstPartyAnalytics', fn (): string => '<?php echo \\'.self::class.'::script(); ?>');

        AboutCommand::add('First-Party Analytics', fn (): array => [
            'Version' => self::version(),
            'Tracking' => config('first-party-analytics.enabled') ? (config('first-party-analytics.cookies') ? 'on, with cookies' : 'on, no cookies') : 'off',
            'Dashboard' => config('first-party-analytics.dashboard.enabled') ? '/'.trim((string) config('first-party-analytics.dashboard.path'), '/') : 'off',
            'Support' => 'https://amjad.com.pk/contact?utm_source=artisan&utm_medium=about&utm_campaign=laravel-first-party-analytics',
        ]);
    }

    /** The inline browser script (@firstPartyAnalytics): analytics.track() and data-analytics-event clicks. */
    public static function script(): string
    {
        if (! config('first-party-analytics.enabled', true) || ! config('first-party-analytics.js.enabled', true)) {
            return '';
        }

        try {
            $nonce = Vite::cspNonce();
        } catch (Throwable) {
            $nonce = null;
        }

        return view('first-party-analytics::script', [
            'endpoint' => route('first-party-analytics.event', absolute: false),
            'nonce' => $nonce,
        ])->render();
    }

    public static function version(): string
    {
        try {
            return InstalledVersions::getPrettyVersion('amjadiqbal/laravel-first-party-analytics') ?? 'dev';
        } catch (Throwable) {
            return 'dev';
        }
    }

    private function registerRoutes(): void
    {
        if (config('first-party-analytics.js.enabled', true)) {
            RateLimiter::for('first-party-analytics', fn (Request $request) => Limit::perMinute(max(1, (int) config('first-party-analytics.js.rate_limit', 120)))->by((string) $request->ip()));

            Route::middleware(array_values(array_filter((array) config('first-party-analytics.js.middleware', ['web']), 'is_string')))
                ->withoutMiddleware([ValidateCsrfToken::class, 'Illuminate\\Foundation\\Http\\Middleware\\PreventRequestForgery', 'App\\Http\\Middleware\\VerifyCsrfToken', TrackVisits::class])
                ->middleware('throttle:first-party-analytics')
                ->post(trim((string) config('first-party-analytics.js.endpoint', 'fpa/event'), '/'), EventController::class)
                ->name('first-party-analytics.event');
        }

        if (config('first-party-analytics.dashboard.enabled', true)) {
            $middleware = array_values(array_filter((array) config('first-party-analytics.dashboard.middleware', ['web']), 'is_string'));

            Route::group([
                'prefix' => trim((string) config('first-party-analytics.dashboard.path', 'analytics'), '/'),
                'domain' => config('first-party-analytics.dashboard.domain'),
                'middleware' => [...$middleware, Authorize::class],
            ], function (): void {
                Route::get('/', DashboardController::class)->name('first-party-analytics.dashboard');
            });
        }
    }

    private function safely(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
