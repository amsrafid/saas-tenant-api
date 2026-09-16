<?php

namespace App\Tenancy;

use App\Models\Tenant;
use RuntimeException;

/**
 * The tenant of the current request or job, resolved once and read from memory everywhere else.
 * Reading it before it is set throws, so a missing context can never widen a query to every tenant.
 */
class TenantContext
{
    private ?Tenant $tenant = null;

    /**
     * Make the given tenant the current one.
     */
    public function set(Tenant $tenant): void
    {
        $this->tenant = $tenant;
    }

    /**
     * Whether a tenant is currently set.
     */
    public function has(): bool
    {
        return ! empty($this->tenant);
    }

    /**
     * The current tenant.
     *
     * @throws RuntimeException
     */
    public function get(): Tenant
    {
        return $this->tenant ?? throw new RuntimeException('No tenant is set.');
    }

    /**
     * The current tenant's id.
     *
     * @throws RuntimeException
     */
    public function id(): int
    {
        return $this->get()->getKey();
    }

    /**
     * Clear the current tenant at the end of a request or job.
     */
    public function forget(): void
    {
        $this->tenant = null;
    }
}
