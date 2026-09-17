<?php

namespace App\Jobs;

use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Repositories\SubscriptionRepository;
use App\Tenancy\TenantScope;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Expires every canceled subscription whose `ends_at` has passed, across all tenants; safe to run again at any time.
 */
class ProcessDueSubscriptions implements ShouldQueue
{
    use Queueable;

    /**
     * Expire the due subscriptions a chunk at a time, dropping each changed tenant's cached subscription.
     */
    public function handle(SubscriptionRepository $subscriptions): void
    {
        $expired = 0;

        // Only a canceled subscription has an ends_at, so the null of a running one never matches.
        Subscription::withoutGlobalScope(TenantScope::class)
            ->select(['id', 'tenant_id'])
            ->where('status', SubscriptionStatus::Active)
            ->where('ends_at', '<=', now())
            ->chunkById(1000, function (Collection $due) use ($subscriptions, &$expired) {
                $expired += Subscription::withoutGlobalScope(TenantScope::class)
                    ->whereIn('id', $due->modelKeys())
                    ->update(['status' => SubscriptionStatus::Expired]);

                $subscriptions->forgetMany($due->pluck('tenant_id')->all());
            });

        // A builder update fires no SubscriptionObserver, so the platform stats are refreshed here.
        if (! empty($expired)) {
            RefreshPlatformStats::dispatch();
        }
    }
}
