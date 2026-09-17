<?php

namespace App\Observers;

use App\Jobs\RefreshPlatformStats;
use App\Models\Tenant;
use App\Models\TenantStats;
use App\Repositories\TenantRepository;
use Illuminate\Support\Facades\DB;

/**
 * Gives a new tenant its stats row inside the creating transaction; cache drops and platform refreshes wait for the commit.
 */
class TenantObserver
{
    public function __construct(private readonly TenantRepository $tenants) {}

    /**
     * Create the zeroed stats row in the same transaction, so the owner's counter increment that follows finds it.
     */
    public function created(Tenant $tenant): void
    {
        $stats = new TenantStats;
        $stats->tenant_id = $tenant->id;
        $stats->save();

        DB::afterCommit(fn () => RefreshPlatformStats::dispatch());
    }

    /**
     * Drop the cached tenant, and queue the refresh when its status changed; a save with nothing dirty fires no `updated`.
     */
    public function updated(Tenant $tenant): void
    {
        $statusChanged = $tenant->wasChanged('status');

        DB::afterCommit(function () use ($tenant, $statusChanged) {
            $this->tenants->forget($tenant->id);

            if ($statusChanged) {
                RefreshPlatformStats::dispatch();
            }
        });
    }

    /**
     * Drop the cached tenant and queue the refresh after a delete.
     */
    public function deleted(Tenant $tenant): void
    {
        DB::afterCommit(function () use ($tenant) {
            $this->tenants->forget($tenant->id);
            RefreshPlatformStats::dispatch();
        });
    }
}
