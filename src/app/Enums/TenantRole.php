<?php

namespace App\Enums;

/**
 * A user's role; `platform_admin` sits outside tenancy and only exists with a null tenant.
 */
enum TenantRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Member = 'member';
    case PlatformAdmin = 'platform_admin';

    /**
     * Seniority within a tenant; a platform admin has none, so asking fails loudly.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Owner => 3,
            self::Admin => 2,
            self::Member => 1,
        };
    }
}
