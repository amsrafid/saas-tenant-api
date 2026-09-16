<?php

namespace App\Providers;

use App\Enums\Ability;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Services\AuthUserService;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

/**
 * Registers the per-unit-of-work context singletons, clears them around every request and worker job,
 * and defines one gate per tenant ability from the role map on `Ability`.
 */
class TenancyServiceProvider extends ServiceProvider
{
    /**
     * Register the context singletons.
     */
    public function register(): void
    {
        $this->app->singleton(TenantContext::class);
        $this->app->singleton(AuthUserService::class);
    }

    /**
     * Define the ability gates and reset the context when a request or a worker job starts or finishes.
     */
    public function boot(): void
    {
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        $this->defineAbilityGates();

        Event::listen(RequestHandled::class, fn () => $this->forgetContext());
        Event::listen(JobProcessing::class, fn (JobProcessing $event) => $this->forgetContextAroundWorkerJob($event->job));
        Event::listen(JobAttempted::class, fn (JobAttempted $event) => $this->forgetContextAroundWorkerJob($event->job));
    }

    private function defineAbilityGates(): void
    {
        foreach (Ability::cases() as $ability) {
            Gate::define($ability->value, fn (User $user) => in_array($user->role, $ability->roles(), true));
        }
    }

    private function forgetContextAroundWorkerJob(Job $job): void
    {
        // A sync job runs inside its caller's request or command, which owns that context and resets it itself.
        if ($job instanceof SyncJob) {
            return;
        }

        $this->forgetContext();
    }

    private function forgetContext(): void
    {
        $this->app->make(TenantContext::class)->forget();
        $this->app->make(AuthUserService::class)->forget();
    }
}
