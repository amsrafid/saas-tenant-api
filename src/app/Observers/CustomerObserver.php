<?php

namespace App\Observers;

use App\Jobs\RefreshPlatformStats;
use App\Models\Customer;
use App\Models\TenantMonthlyStats;
use App\Models\TenantStats;
use App\Repositories\TenantRepository;
use App\Tenancy\TenantScope;
use Illuminate\Support\Facades\DB;

/**
 * Keeps the tenant's `customers_count` and monthly growth exact; runs inside the write's transaction, so both commit or roll back with the row.
 */
class CustomerObserver
{
    public function __construct(private readonly TenantRepository $tenants) {}

    /**
     * Count the new customer, and add it to the month it was created in.
     */
    public function created(Customer $customer): void
    {
        $this->adjustCount($customer, 1);
        $this->addToMonth($customer);
    }

    /**
     * Uncount the deleted customer; the growth series records additions only, so its month is left as is.
     */
    public function deleted(Customer $customer): void
    {
        $this->adjustCount($customer, -1);
    }

    private function adjustCount(Customer $customer, int $by): void
    {
        TenantStats::withoutGlobalScope(TenantScope::class)->whereKey($customer->tenant_id)->increment('customers_count', $by);

        // A builder increment fires no TenantStats event, so the platform refresh is queued here, once the write commits.
        DB::afterCommit(fn () => RefreshPlatformStats::dispatch());
    }

    private function addToMonth(Customer $customer): void
    {
        // The month is taken in the tenant's own timezone, read from its cache, so seeders and jobs bucket a customer exactly as a request does.
        $timezone = $this->tenants->find($customer->tenant_id)->timezone;

        TenantMonthlyStats::withoutGlobalScope(TenantScope::class)->upsert(
            [[
                'tenant_id' => $customer->tenant_id,
                'month' => $customer->created_at->copy()->setTimezone($timezone)->startOfMonth()->toDateString(),
                'customers_added' => 1,
            ]],
            ['tenant_id', 'month'],
            ['customers_added' => DB::raw('tenant_monthly_stats.customers_added + 1')],
        );
    }
}
