<?php

namespace App\Services;

use App\Models\TenantMonthlyStats;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * The current tenant's dashboard, assembled from stored counters only: nothing is counted on the request.
 */
class DashboardService
{
    private const GROWTH_MONTHS = 12;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly SubscriptionService $subscriptions,
    ) {}

    /**
     * The live plan, usage against its limits, and customers added in each of the last twelve months of the tenant's timezone.
     *
     * @return array{plan: array{id: int, name: string, slug: string}, usage: array<string, array{used: int, limit: int|null, remaining: int|null}>, customer_growth: list<array{month: string, customers_added: int}>}
     *
     * @throws ModelNotFoundException
     */
    public function analytics(): array
    {
        $plan = $this->subscriptions->current()->plan;

        return [
            'plan' => ['id' => $plan->id, 'name' => $plan->name, 'slug' => $plan->slug],
            'usage' => $this->subscriptions->usage(),
            'customer_growth' => $this->customerGrowth(),
        ];
    }

    /**
     * @return list<array{month: string, customers_added: int}>
     */
    private function customerGrowth(): array
    {
        $firstMonth = now($this->tenantContext->get()->timezone)->startOfMonth()->subMonths(self::GROWTH_MONTHS - 1);

        $added = TenantMonthlyStats::where('month', '>=', $firstMonth->toDateString())->pluck('customers_added', 'month');

        // Months nobody was added in have no row, so the series is filled in here from at most twelve rows.
        return collect(range(0, self::GROWTH_MONTHS - 1))
            ->map(function (int $offset) use ($firstMonth, $added) {
                $month = $firstMonth->copy()->addMonths($offset);

                return ['month' => $month->format('Y-m'), 'customers_added' => $added[$month->toDateString()] ?? 0];
            })
            ->all();
    }
}
