<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\UpdateTenantRequest;
use App\Http\Resources\TenantResource;
use App\Tenancy\TenantContext;

/**
 * The current user's own tenant (company).
 */
class TenantController extends Controller
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * Get the tenant.
     *
     * The tenant of the authenticated user; there is no way to address another one.
     */
    public function show(): TenantResource
    {
        return TenantResource::make($this->tenantContext->get());
    }

    /**
     * Update the tenant.
     *
     * Owner and admin only. Partial: `name` and `timezone`; the slug is fixed and the status is the platform admin's.
     */
    public function update(UpdateTenantRequest $request): TenantResource
    {
        $tenant = $this->tenantContext->get();
        $tenant->update($request->validated());

        return TenantResource::make($tenant);
    }
}
