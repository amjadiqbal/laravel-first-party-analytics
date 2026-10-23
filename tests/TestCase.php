<?php

namespace AmjadIqbal\FirstPartyAnalytics\Tests;

use AmjadIqbal\FirstPartyAnalytics\Facades\Analytics;
use AmjadIqbal\FirstPartyAnalytics\FirstPartyAnalyticsServiceProvider;
use Illuminate\Auth\GenericUser;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    public const string CHROME = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36';

    public const string IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.6 Mobile/15E148 Safari/604.1';

    protected function getPackageProviders($app): array
    {
        return [FirstPartyAnalyticsServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('session.driver', 'array');

        // scripts/integration.sh runs this suite against real MySQL and PostgreSQL servers too.
        if (in_array(getenv('FPA_DB'), ['mysql', 'pgsql'], true)) {
            $app['config']->set('database.connections.testing', [
                'driver' => getenv('FPA_DB'),
                'host' => '127.0.0.1',
                'port' => (int) getenv('FPA_DB_PORT'),
                'database' => 'analytics',
                'username' => getenv('FPA_DB') === 'mysql' ? 'root' : 'postgres',
                'password' => 'secret',
                'charset' => getenv('FPA_DB') === 'mysql' ? 'utf8mb4' : 'utf8',
                'prefix' => '',
            ]);
        }
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->artisan('migrate:fresh')->run();
    }

    protected function defineRoutes($router): void
    {
        Route::middleware('web')->group(function (): void {
            Route::get('/', fn () => '<h1>Home</h1>');
            Route::get('/pricing', fn () => '<h1>Pricing</h1>');
            Route::get('/checkout', fn () => '<h1>Checkout</h1>');
            Route::get('/blog/{slug}', fn () => '<h1>Post</h1>');
            Route::get('/admin/users', fn () => '<h1>Admin</h1>');
            Route::get('/json', fn () => ['ok' => true]);
            Route::get('/go', fn () => redirect('/pricing'));
            Route::get('/missing', fn () => abort(404));
            Route::post('/form', fn () => '<p>Saved</p>');
            Route::get('/with-script', fn () => view('first-party-analytics::script', ['endpoint' => '/fpa/event', 'nonce' => null]));
            Route::get('/directive', fn () => Blade::render('<body>@firstPartyAnalytics</body>'));
            Route::post('/buy', function () {
                Analytics::track('purchase', ['plan' => 'pro', 'amount' => 49]);

                return '<p>Thanks</p>';
            });
            Route::post('/login-as/{id}', function (string $id) {
                Auth::login(new GenericUser(['id' => (int) $id, 'name' => 'User '.$id, 'remember_token' => null]));

                return '<p>Signed in</p>';
            });
        });
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Laravel 12 ValidateCsrfToken, Laravel 13 PreventRequestForgery: the test routes post without tokens.
        $this->withoutMiddleware([ValidateCsrfToken::class, 'Illuminate\\Foundation\\Http\\Middleware\\PreventRequestForgery']);
    }

    /**
     * @param  array<string, string>  $headers
     */
    public function browse(string $uri, array $headers = [], string $ua = self::CHROME, string $ip = '203.0.113.7'): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])->get($uri, ['User-Agent' => $ua, ...$headers]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers
     */
    public function postAs(string $uri, array $data = [], array $headers = [], string $ua = self::CHROME, string $ip = '203.0.113.7'): TestResponse
    {
        return $this->withCredentials()->withServerVariables(['REMOTE_ADDR' => $ip])->postJson($uri, $data, ['User-Agent' => $ua, ...$headers]);
    }

    protected function visits(): Builder
    {
        return DB::table('analytics_visits');
    }

    protected function events(): Builder
    {
        return DB::table('analytics_events');
    }

    protected function asLocal(): void
    {
        $this->app['env'] = 'local';
    }
}
