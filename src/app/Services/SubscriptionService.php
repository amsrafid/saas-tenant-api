<?php

namespace App\Services;

use App\Enums\FeatureKey;
use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantStats;
use App\Repositories\PlanRepository;
use App\Repositories\SubscriptionRepository;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Subscription lifecycle for tenants: subscribe, change plan, cancel, and usage against the plan's limits.
 */
class SubscriptionService
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly SubscriptionRepository $subscriptions,
        private readonly PlanRepository $plans,
    ) {}

    /**
     * The current tenant's live subscription with its plan and limits.
     *
     * @throws ModelNotFoundException
     */
    public function current(): Subscription
    {
        $subscription = $this->subscriptions->current() ?? throw (new ModelNotFoundException)->setModel(Subscription::class);

        return $subscription->setRelation('plan', $this->plans->findBySlug($subscription->plan_slug));
    }

    /**
     * Subscribe the current tenant, which has no live subscription, to an active plan.
     *
     * @throws ValidationException
     */
    public function subscribe(string $planSlug): Subscription
    {
        abort_if(
            ! empty($this->subscriptions->current()),
            Response::HTTP_CONFLICT,
            'The tenant already has an active subscription; change its plan instead.',
        );

        $plan = $this->sellablePlan($planSlug);
        $this->ensureUsageFits($plan);

        return $this->start($this->tenantContext->get(), $plan)->setRelation('plan', $plan);
    }

    /**
     * End the current subscription now and start a new one on the given active plan.
     *
     * @throws ModelNotFoundException
     * @throws ValidationException
     */
    public function changePlan(string $planSlug): Subscription
    {
        $current = $this->current();
        $plan = $this->sellablePlan($planSlug);

        abort_if($current->plan_id === $plan->id, Response::HTTP_CONFLICT, 'The tenant is already subscribed to this plan.');

        $this->ensureUsageFits($plan);

        $subscription = DB::transaction(function () use ($current, $plan) {
            // Ended before the insert, so the one-live-subscription partial unique index never sees two rows.
            $current->forceFill([
                'status' => SubscriptionStatus::Canceled,
                'canceled_at' => now(),
                'ends_at' => now(),
            ])->save();

            return $this->start($this->tenantContext->get(), $plan);
        });

        return $subscription->setRelation('plan', $plan);
    }

    /**
     * Cancel the current subscription; it stays live until the end of its current billing period, which becomes `ends_at`.
     *
     * @throws ModelNotFoundException
     */
    public function cancel(): Subscription
    {
        $subscription = $this->current();

        abort_if(! empty($subscription->canceled_at), Response::HTTP_CONFLICT, 'The subscription is already canceled.');

        $subscription->canceled_at = now();
        $subscription->ends_at = $subscription->plan->billing_period->periodEndAfter($subscription->starts_at, now());
        $subscription->save();

        return $subscription;
    }

    /**
     * The current tenant's usage of each measured feature against its plan's limit; a null limit is unlimited.
     *
     * @return array<string, array{used: int, limit: int|null, remaining: int|null}>
     *
     * @throws ModelNotFoundException
     */
    public function usage(): array
    {
        $limits = $this->current()->plan->features->pluck('limit_value', 'key.value');
        $used = $this->used(FeatureKey::MaxUsers, FeatureKey::MaxCustomers);

        return collect($used)->map(fn (int $used, string $key) => [
            'used' => $used,
            'limit' => $limits[$key],
            'remaining' => is_null($limits[$key]) ? null : max(0, $limits[$key] - $used),
        ])->all();
    }

    /**
     * Refuse with 429 once the tenant's `max_users` or `max_customers` count has reached its plan's limit; a null limit is unlimited.
     * Call it inside the create's transaction: the stats row stays locked until commit, so concurrent creates cannot overshoot.
     *
     * @throws ModelNotFoundException
     * @throws HttpResponseException
     */
    public function ensureWithinLimit(FeatureKey $feature): void
    {
        $limit = $this->current()->plan->features->firstWhere('key', $feature)->limit_value;

        if (is_null($limit)) {
            return;
        }

        $used = TenantStats::lockForUpdate()->value($this->statsColumn($feature));

        if ($used < $limit) {
            return;
        }

        throw new HttpResponseException($this->limitReached($feature, $limit, $used));
    }

    /**
     * Start an active subscription to the plan, running until it is canceled or changed.
     */
    public function start(Tenant $tenant, Plan $plan): Subscription
    {
        $subscription = new Subscription([
            'plan_id' => $plan->id,
            'starts_at' => now(),
        ]);
        $subscription->tenant_id = $tenant->id;
        $subscription->status = SubscriptionStatus::Active;
        $subscription->save();

        return $subscription;
    }

    /**
     * @throws ValidationException
     */
    private function sellablePlan(string $slug): Plan
    {
        $plan = $this->plans->findBySlug($slug);

        if (empty($plan) || empty($plan->is_active)) {
            throw ValidationException::withMessages(['plan' => __('validation.exists', ['attribute' => 'plan'])]);
        }

        return $plan;
    }

    /**
     * Refuse a plan whose user or customer limit is below what the tenant already has.
     *
     * @throws ValidationException
     */
    private function ensureUsageFits(Plan $plan): void
    {
        $used = $this->used(FeatureKey::MaxUsers, FeatureKey::MaxCustomers);
        $limits = $plan->features->pluck('limit_value', 'key.value');

        $exceeded = collect([FeatureKey::MaxUsers, FeatureKey::MaxCustomers])
            ->filter(fn (FeatureKey $key) => ! is_null($limits[$key->value]) && $used[$key->value] > $limits[$key->value])
            ->map(fn (FeatureKey $key) => "Current {$key->value} usage ({$used[$key->value]}) exceeds the {$plan->name} plan's limit of {$limits[$key->value]}.");

        if ($exceeded->isNotEmpty()) {
            throw ValidationException::withMessages(['plan' => $exceeded->values()->all()]);
        }
    }

    /**
     * Current user and customer counts in one query, from `tenant_stats`.
     *
     * @return array<string, int>
     */
    private function used(FeatureKey ...$features): array
    {
        $query = DB::query();

        foreach ($features as $feature) {
            $query->selectSub(TenantStats::select($this->statsColumn($feature)), $feature->value);
        }

        return array_map(fn (?int $used) => $used ?? 0, (array) $query->first());
    }

    private function limitReached(FeatureKey $feature, int $limit, int $used): JsonResponse
    {
        return response()->json([
            'message' => "The plan's {$feature->value} limit of {$limit} has been reached.",
            'feature' => $feature->value,
            'limit' => $limit,
            'used' => $used,
        ], Response::HTTP_TOO_MANY_REQUESTS);
    }

    private function statsColumn(FeatureKey $feature): string
    {
        return match ($feature) {
            FeatureKey::MaxUsers => 'users_count',
            FeatureKey::MaxCustomers => 'customers_count',
        };
    }
}
