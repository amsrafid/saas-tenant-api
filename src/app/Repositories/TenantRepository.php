<?php

namespace App\Repositories;

use App\Models\Tenant;
use Illuminate\Support\Facades\Cache;

/**
 * Tenants by id, cached for a day; TenantObserver calls forget() after every tenant write.
 */
class TenantRepository
{
    /**
     * The tenant with the given id, or null for no id (a platform admin) or a missing tenant.
     */
    public function find(?int $id): ?Tenant
    {
        if (empty($id)) {
            return null;
        }

        // A null result is stored but read back as a miss, so a missing tenant is never served from cache.
        return Cache::remember($this->key($id), now()->addDay(), fn () => Tenant::select(['id', 'name', 'slug', 'status', 'timezone'])->find($id));
    }

    /**
     * Drop the cached tenant after it was changed.
     */
    public function forget(int $id): void
    {
        Cache::forget($this->key($id));
    }

    private function key(int $id): string
    {
        return "tenant:{$id}";
    }
}
