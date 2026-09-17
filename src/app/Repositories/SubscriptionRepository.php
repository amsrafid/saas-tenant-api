<?php

namespace App\Repositories;

use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;

/**
 * The current tenant's live subscription, cached for a day; SubscriptionObserver calls forget() after every subscription write.
 */
class SubscriptionRepository
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * The current tenant's active subscription with its plan's slug as `plan_slug`, or null.
     */
    public function current(): ?Subscription
    {
        // Only the slug is cached with the subscription: limits are read from the plan's own cache key,
        // which every plan write already drops, so a limit edit reaches every tenant without a fan-out.
        return Cache::remember($this->key($this->tenantContext->id()), now()->addDay(), fn () => Subscription::select([
            'subscriptions.id',
            'subscriptions.tenant_id',
            'subscriptions.plan_id',
            'subscriptions.status',
            'subscriptions.starts_at',
            'subscriptions.ends_at',
            'subscriptions.canceled_at',
            'plans.slug as plan_slug',
        ])
            ->join('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->where('subscriptions.status', SubscriptionStatus::Active)
            ->first());
    }

    /**
     * Drop the tenant's cached subscription after it was started, changed or canceled.
     */
    public function forget(int $tenantId): void
    {
        Cache::forget($this->key($tenantId));
    }

    /**
     * Drop the cached subscriptions of many tenants in one cache call.
     *
     * @param  list<int>  $tenantIds
     */
    public function forgetMany(array $tenantIds): void
    {
        Cache::deleteMultiple(array_map(fn (int $tenantId) => $this->key($tenantId), $tenantIds));
    }

    private function key(int $tenantId): string
    {
        return "tenant:{$tenantId}:subscription";
    }
}
