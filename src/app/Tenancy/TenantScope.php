<?php

namespace App\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Constrains every query on a tenant-owned model to the current tenant.
 */
class TenantScope implements Scope
{
    /**
     * Add the tenant constraint; throws when no tenant is set rather than returning every tenant's rows.
     */
    public function apply(Builder $builder, Model $model): void
    {
        $builder->where($model->qualifyColumn('tenant_id'), app(TenantContext::class)->id());
    }
}
