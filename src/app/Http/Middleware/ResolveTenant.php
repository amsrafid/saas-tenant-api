<?php

namespace App\Http\Middleware;

use App\Enums\TenantStatus;
use App\Models\Tenant;
use App\Models\User;
use App\Repositories\TenantRepository;
use App\Services\AuthUserService;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sets the tenant context from the authenticated user; nothing in the request can choose the tenant.
 */
class ResolveTenant
{
    public function __construct(
        private readonly AuthUserService $authUser,
        private readonly TenantContext $tenantContext,
        private readonly TenantRepository $tenants,
    ) {}

    /**
     * Resolve the user's tenant, refusing platform admins and suspended tenants.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $this->tenantContext->set($this->activeTenantOf($this->authUser->user()));

        return $next($request);
    }

    private function activeTenantOf(User $user): Tenant
    {
        $tenant = $this->tenants->find($user->tenant_id);

        abort_if(empty($tenant), Response::HTTP_FORBIDDEN, 'This endpoint requires a tenant account.');
        abort_if($tenant->status === TenantStatus::Suspended, Response::HTTP_FORBIDDEN, 'This tenant account is suspended.');

        return $tenant;
    }
}
