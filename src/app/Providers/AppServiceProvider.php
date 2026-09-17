<?php

namespace App\Providers;

use App\Models\User;
use App\Services\AuthUserService;
use Dedoc\Scramble\Scramble;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

/**
 * Application-wide service registration and bootstrapping.
 */
class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Scramble's own UI is replaced by Swagger UI; its spec route is registered in routes/web.php.
        Scramble::ignoreDefaultRoutes();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::define('viewApiDocs', fn (?User $user) => ! app()->isProduction());

        FormRequest::failOnUnknownFields();

        // A relation read that was not eager loaded fails loudly outside production instead of becoming an N+1.
        Model::preventLazyLoading(! app()->isProduction());

        // At most 18 digits, so an {id} always fits a PHP int and a bigint.
        Route::pattern('id', '[0-9]{1,18}');

        $this->configureRateLimiting();
    }

    private function configureRateLimiting(): void
    {
        RateLimiter::for('auth', function (Request $request) {
            $email = $request->input('email');

            return Limit::perMinute(5)->by(Str::lower(trim(is_string($email) ? $email : '')).'|'.$request->ip());
        });

        RateLimiter::for('register', fn (Request $request) => Limit::perHour(10)->by($request->ip()));

        RateLimiter::for('public', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));

        RateLimiter::for('api', fn () => Limit::perMinute(60)->by($this->app->make(AuthUserService::class)->user()->id));
    }
}
