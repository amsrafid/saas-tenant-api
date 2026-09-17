<?php

namespace App\Observers;

use App\Jobs\RefreshPlatformStats;
use App\Models\Plan;
use App\Repositories\PlanRepository;
use App\Services\AdminService;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

/**
 * Drops the cached plan, the active plan list and platform analytics, and queues a platform stats refresh, after any plan write that changed a row.
 */
class PlanObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        private readonly PlanRepository $plans,
        private readonly AdminService $admin,
    ) {}

    /**
     * Drop the caches and queue the refresh after a create.
     */
    public function created(Plan $plan): void
    {
        $this->afterWrite($plan);
    }

    /**
     * Drop the caches and queue the refresh after an update; a save with nothing dirty fires no `updated`.
     */
    public function updated(Plan $plan): void
    {
        $this->afterWrite($plan);
    }

    /**
     * Drop the caches and queue the refresh after a delete.
     */
    public function deleted(Plan $plan): void
    {
        $this->afterWrite($plan);
    }

    private function afterWrite(Plan $plan): void
    {
        $this->plans->forget($plan->slug);
        // Analytics shows each plan's name and currency, which no stats refresh changes for a plan without subscribers.
        $this->admin->forgetAnalytics();
        RefreshPlatformStats::dispatch();
    }
}
