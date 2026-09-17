<?php

namespace App\Services;

use App\Enums\SubscriptionStatus;
use App\Enums\TenantStatus;
use App\Models\PlanStats;
use App\Models\PlatformStats;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;

/**
 * Platform administration across every tenant: tenants with their plan and counts, suspension, and cached analytics.
 */
class AdminService
{
    private const ANALYTICS_KEY = 'platform:analytics';

    /**
     * Tenants by descending id, with their live plan and usage, filtered by status and plan, prefix-searched on name and slug.
     *
     * @return LengthAwarePaginator<int, Tenant>
     */
    public function paginateTenants(?string $status, ?int $planId, ?string $search, int $perPage): LengthAwarePaginator
    {
        return $this->tenantsWithPlanAndUsage()
            ->when($status, fn (Builder $query, string $status) => $query->where('tenants.status', $status))
            ->when($planId, fn (Builder $query, int $planId) => $query->where('subscriptions.plan_id', $planId))
            ->search(['tenants.name', 'tenants.slug'], $search)
            ->orderByDesc('tenants.id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * The tenant with the given id, with its live plan and usage.
     *
     * @throws ModelNotFoundException
     */
    public function findTenant(int $id): Tenant
    {
        return $this->tenantsWithPlanAndUsage()->findOrFail($id);
    }

    /**
     * Suspend or reactivate the tenant; its users are refused or let back in on their next request.
     */
    public function setTenantStatus(Tenant $tenant, TenantStatus $status): Tenant
    {
        $tenant->status = $status;
        $tenant->save();

        return $tenant;
    }

    /**
     * Platform-wide totals and each plan's live tenants and MRR, read from the job-maintained stats tables and cached for a day.
     *
     * @return array{tenants: array{total: int, active: int, suspended: int}, users: int, customers: int, plans: list<array<string, mixed>>}
     */
    public function analytics(): array
    {
        return Cache::remember(self::ANALYTICS_KEY, now()->addDay(), fn () => [
            ...$this->platformTotals(),
            'plans' => $this->planFigures(),
        ]);
    }

    /**
     * Drop the cached analytics; called by the PlatformStats observer and RefreshPlatformStats.
     */
    public function forgetAnalytics(): void
    {
        Cache::forget(self::ANALYTICS_KEY);
    }

    /**
     * @return array{tenants: array{total: int, active: int, suspended: int}, users: int, customers: int}
     */
    private function platformTotals(): array
    {
        // Zeros until RefreshPlatformStats first runs on a database nothing has been written to.
        $stats = PlatformStats::select(['tenants_count', 'suspended_tenants_count', 'users_count', 'customers_count'])
            ->first() ?? new PlatformStats;

        return [
            'tenants' => [
                'total' => $stats->tenants_count,
                'active' => $stats->tenants_count - $stats->suspended_tenants_count,
                'suspended' => $stats->suspended_tenants_count,
            ],
            'users' => $stats->users_count,
            'customers' => $stats->customers_count,
        ];
    }

    /**
     * Every plan with stored figures, in display order; the catalogue is a handful of platform-owned rows, so it is read whole.
     *
     * @return list<array<string, mixed>>
     */
    private function planFigures(): array
    {
        return PlanStats::join('plans', 'plans.id', '=', 'plan_stats.plan_id')
            ->select(['plans.id', 'plans.name', 'plans.slug', 'plans.currency', 'plan_stats.tenants_count', 'plan_stats.mrr_cents'])
            ->orderBy('plans.sort_order')
            ->orderBy('plans.id')
            ->get()
            ->map(fn (PlanStats $plan) => [
                'id' => $plan->id,
                'name' => $plan->name,
                'slug' => $plan->slug,
                'tenants' => $plan->tenants_count,
                'currency' => $plan->currency,
                'mrr_cents' => $plan->mrr_cents,
            ])
            ->all();
    }

    /**
     * @return Builder<Tenant>
     */
    private function tenantsWithPlanAndUsage(): Builder
    {
        // The join matches the partial unique index's predicate, so each tenant joins at most one row and totals stay exact.
        return Tenant::select([
            'tenants.id',
            'tenants.name',
            'tenants.slug',
            'tenants.status',
            'tenants.timezone',
            'plans.id as plan_id',
            'plans.name as plan_name',
            'plans.slug as plan_slug',
            'tenant_stats.users_count',
            'tenant_stats.customers_count',
        ])
            ->leftJoin('subscriptions', fn (JoinClause $join) => $join
                ->on('subscriptions.tenant_id', '=', 'tenants.id')
                ->where('subscriptions.status', SubscriptionStatus::Active))
            ->leftJoin('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->leftJoin('tenant_stats', 'tenant_stats.tenant_id', '=', 'tenants.id');
    }
}
