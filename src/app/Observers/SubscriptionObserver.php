<?php

namespace App\Observers;

use App\Jobs\RefreshPlatformStats;
use App\Models\Subscription;
use App\Repositories\SubscriptionRepository;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

/**
 * Drops the tenant's cached subscription and queues a platform stats refresh after any subscription write that changed a row.
 */
class SubscriptionObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(private readonly SubscriptionRepository $subscriptions) {}

    /**
     * Drop the cache and queue the refresh after a create.
     */
    public function created(Subscription $subscription): void
    {
        $this->afterWrite($subscription);
    }

    /**
     * Drop the cache and queue the refresh after an update; a save with nothing dirty fires no `updated`.
     */
    public function updated(Subscription $subscription): void
    {
        $this->afterWrite($subscription);
    }

    /**
     * Drop the cache and queue the refresh after a delete.
     */
    public function deleted(Subscription $subscription): void
    {
        $this->afterWrite($subscription);
    }

    private function afterWrite(Subscription $subscription): void
    {
        $this->subscriptions->forget($subscription->tenant_id);
        RefreshPlatformStats::dispatch();
    }
}
