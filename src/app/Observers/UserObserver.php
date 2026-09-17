<?php

namespace App\Observers;

use App\Jobs\RefreshPlatformStats;
use App\Models\TenantStats;
use App\Models\User;
use App\Tenancy\TenantScope;
use Illuminate\Support\Facades\DB;

/**
 * Keeps the tenant's `users_count` exact; runs inside the write's transaction, so the counter commits or rolls back with the row.
 */
class UserObserver
{
    /**
     * Count the new user.
     */
    public function created(User $user): void
    {
        $this->adjustCount($user, 1);
    }

    /**
     * Uncount the deleted user.
     */
    public function deleted(User $user): void
    {
        $this->adjustCount($user, -1);
    }

    private function adjustCount(User $user, int $by): void
    {
        // A platform admin belongs to no tenant, so there is no count to keep.
        if (empty($user->tenant_id)) {
            return;
        }

        TenantStats::withoutGlobalScope(TenantScope::class)->whereKey($user->tenant_id)->increment('users_count', $by);

        // A builder increment fires no TenantStats event, so the platform refresh is queued here, once the write commits.
        DB::afterCommit(fn () => RefreshPlatformStats::dispatch());
    }
}
