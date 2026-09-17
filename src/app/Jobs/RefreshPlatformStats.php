<?php

namespace App\Jobs;

use App\Enums\BillingPeriod;
use App\Enums\SubscriptionStatus;
use App\Enums\TenantStatus;
use App\Models\Plan;
use App\Models\PlanStats;
use App\Models\PlatformStats;
use App\Models\Tenant;
use App\Models\TenantStats;
use App\Services\AdminService;
use App\Tenancy\TenantScope;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Aggregates the platform totals into `platform_stats` and each plan's subscribers and MRR into `plan_stats`.
 * Unique platform-wide until it starts and delayed, so a burst of writes collapses into one refresh and none is lost.
 */
class RefreshPlatformStats implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    /**
     * Seconds before the unique lock expires on its own, so a lost payload cannot block refreshes for good.
     */
    public int $uniqueFor = 600;

    /**
     * Queue the refresh 30 seconds out.
     */
    public function __construct()
    {
        $this->delay(30);
    }

    /**
     * Aggregate over tenants, tenant_stats and active subscriptions, then store every row in one transaction.
     */
    public function handle(AdminService $admin): void
    {
        $totals = $this->platformTotals();
        $plans = $this->plansWithSubscriberCounts();

        DB::transaction(function () use ($totals, $plans, $admin) {
            $this->storePlatformStats($totals);
            $this->storePlanStats($plans, $admin);
        });
    }

    private function platformTotals(): object
    {
        // Users and customers are summed from tenant_stats, one row per tenant, so the cost never follows a tenant's size.
        return Tenant::selectRaw('count(*) as tenants_count')
            ->selectRaw('count(*) filter (where status = ?) as suspended_tenants_count', [TenantStatus::Suspended])
            ->selectSub(TenantStats::withoutGlobalScope(TenantScope::class)->selectRaw('coalesce(sum(users_count), 0)::bigint'), 'users_count')
            ->selectSub(TenantStats::withoutGlobalScope(TenantScope::class)->selectRaw('coalesce(sum(customers_count), 0)::bigint'), 'customers_count')
            ->toBase()
            ->first();
    }

    /**
     * Every plan with its active subscription count; the catalogue is a handful of platform-owned rows.
     *
     * @return Collection<int, Plan>
     */
    private function plansWithSubscriberCounts(): Collection
    {
        return Plan::select(['plans.id', 'plans.price_cents', 'plans.billing_period'])
            ->selectRaw('count(subscriptions.id) as active_subscriptions')
            ->leftJoin('subscriptions', fn (JoinClause $join) => $join
                ->on('subscriptions.plan_id', '=', 'plans.id')
                ->where('subscriptions.status', SubscriptionStatus::Active))
            ->groupBy('plans.id')
            ->get();
    }

    private function storePlatformStats(object $totals): void
    {
        $stats = PlatformStats::select(['id', 'tenants_count', 'suspended_tenants_count', 'users_count', 'customers_count'])
            ->first() ?? new PlatformStats;

        $stats->forceFill(['id' => 1, ...(array) $totals])->save();
    }

    /**
     * @param  Collection<int, Plan>  $plans
     */
    private function storePlanStats(Collection $plans, AdminService $admin): void
    {
        $existing = PlanStats::select(['plan_id', 'tenants_count', 'mrr_cents'])->get()->keyBy('plan_id');

        $changed = $plans
            ->map(fn (Plan $plan) => [
                'plan_id' => $plan->id,
                'tenants_count' => $plan->active_subscriptions,
                'mrr_cents' => $this->monthlyRevenueCents($plan),
            ])
            ->reject(fn (array $row) => $existing->has($row['plan_id'])
                && $existing[$row['plan_id']]->tenants_count === $row['tenants_count']
                && $existing[$row['plan_id']]->mrr_cents === $row['mrr_cents'])
            ->values()
            ->all();

        if (empty($changed)) {
            return;
        }

        PlanStats::upsert($changed, ['plan_id'], ['tenants_count', 'mrr_cents']);

        // An upsert fires no model event, so the cached analytics are dropped here once the refresh commits.
        DB::afterCommit(fn () => $admin->forgetAnalytics());
    }

    /**
     * A yearly price is spread over 12 months, rounded down.
     */
    private function monthlyRevenueCents(Plan $plan): int
    {
        $months = match ($plan->billing_period) {
            BillingPeriod::Monthly => 1,
            BillingPeriod::Yearly => 12,
        };

        return intdiv($plan->price_cents * $plan->active_subscriptions, $months);
    }
}
