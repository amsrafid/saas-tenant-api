<?php

namespace App\Tenancy;

use Illuminate\Database\Eloquent\Model;

/**
 * Marks a model as tenant-owned: reads are scoped to the current tenant and new rows get its id.
 */
trait BelongsToTenant
{
    /**
     * Register the tenant scope and fill tenant_id on create.
     */
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (Model $model): void {
            $context = app(TenantContext::class);

            // Without a context this is trusted system code (seeder, registration) that set tenant_id itself.
            if ($context->has() && empty($model->tenant_id)) {
                $model->tenant_id = $context->id();
            }
        });
    }
}
