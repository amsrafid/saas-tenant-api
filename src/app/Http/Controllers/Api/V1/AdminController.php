<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TenantStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListTenantsRequest;
use App\Http\Requests\Admin\UpdateTenantRequest;
use App\Http\Resources\AdminTenantResource;
use App\Services\AdminService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Platform administration: every tenant, their suspension, and platform-wide analytics.
 */
class AdminController extends Controller
{
    public function __construct(private readonly AdminService $admin) {}

    /**
     * List tenants.
     *
     * Platform admin only. Highest id first, each with its live plan and usage; filter by `status` and `plan_id`, prefix-search `name` and `slug`.
     */
    public function tenants(ListTenantsRequest $request): AnonymousResourceCollection
    {
        $tenants = $this->admin->paginateTenants(
            $request->validated('filter.status'),
            $request->validated('filter.plan_id'),
            $request->validated('search'),
            $request->validated('per_page', 15),
        );

        return AdminTenantResource::collection($tenants);
    }

    /**
     * Suspend or reactivate a tenant.
     *
     * Platform admin only. A suspended tenant's users get 403 on every tenant route from their next request.
     */
    public function updateTenant(UpdateTenantRequest $request, int $id): AdminTenantResource
    {
        $tenant = $this->admin->setTenantStatus($this->admin->findTenant($id), $request->enum('status', TenantStatus::class));

        return AdminTenantResource::make($tenant);
    }

    /**
     * Get platform analytics.
     *
     * Platform admin only. Tenant, user and customer totals, and each plan's live tenants and MRR in cents, as last refreshed by a background job (about 30 to 60 seconds behind).
     *
     * @response array{data: array{tenants: array{total: int, active: int, suspended: int}, users: int, customers: int, plans: list<array{id: int, name: string, slug: string, tenants: int, currency: string, mrr_cents: int}>}}
     */
    public function analytics(): JsonResource
    {
        return JsonResource::make($this->admin->analytics());
    }
}
