<?php

namespace App\Observers;

use App\Services\AdminService;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

/**
 * Drops the cached platform analytics when the platform totals are inserted or actually change.
 */
class PlatformStatsObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(private readonly AdminService $admin) {}

    /**
     * Drop the analytics after the first write.
     */
    public function created(): void
    {
        $this->admin->forgetAnalytics();
    }

    /**
     * Drop the analytics after a refresh that changed a figure; a save with nothing dirty fires no `updated`.
     */
    public function updated(): void
    {
        $this->admin->forgetAnalytics();
    }
}
