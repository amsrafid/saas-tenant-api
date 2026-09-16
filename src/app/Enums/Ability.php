<?php

namespace App\Enums;

/**
 * An action a role may be allowed to perform, and the single map of which roles hold it.
 */
enum Ability: string
{
    case ViewTenant = 'tenant:view';
    case UpdateTenant = 'tenant:update';
    case ViewCustomers = 'customers:view';
    case CreateCustomers = 'customers:create';
    case UpdateCustomers = 'customers:update';
    case DeleteCustomers = 'customers:delete';
    case ViewUsers = 'users:view';
    case CreateUsers = 'users:create';
    case UpdateUsers = 'users:update';
    case DeleteUsers = 'users:delete';
    case ManagePlans = 'plans:manage';
    case ViewSubscription = 'subscription:view';
    case ManageSubscription = 'subscription:manage';
    case ViewDashboard = 'dashboard:view';
    case ManageTenants = 'tenants:manage';
    case ViewPlatformAnalytics = 'platform-analytics:view';

    /**
     * The roles holding this ability; no default arm, so a new case without roles fails loudly instead of opening up.
     *
     * @return list<TenantRole>
     */
    public function roles(): array
    {
        return match ($this) {
            self::ViewTenant => [TenantRole::Owner, TenantRole::Admin, TenantRole::Member],
            self::UpdateTenant => [TenantRole::Owner, TenantRole::Admin],
            self::ViewCustomers,
            self::CreateCustomers,
            self::UpdateCustomers => [TenantRole::Owner, TenantRole::Admin, TenantRole::Member],
            self::DeleteCustomers => [TenantRole::Owner, TenantRole::Admin],
            self::ViewUsers => [TenantRole::Owner, TenantRole::Admin, TenantRole::Member],
            self::CreateUsers,
            self::UpdateUsers => [TenantRole::Owner, TenantRole::Admin],
            self::DeleteUsers => [TenantRole::Owner],
            self::ManagePlans => [TenantRole::PlatformAdmin],
            self::ViewSubscription => [TenantRole::Owner, TenantRole::Admin, TenantRole::Member],
            self::ManageSubscription => [TenantRole::Owner],
            self::ViewDashboard => [TenantRole::Owner, TenantRole::Admin, TenantRole::Member],
            self::ManageTenants,
            self::ViewPlatformAnalytics => [TenantRole::PlatformAdmin],
        };
    }
}
