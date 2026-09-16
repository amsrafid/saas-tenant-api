<?php

namespace App\Enums;

/**
 * Lifecycle state of a tenant; a suspended tenant is switched off by a platform admin.
 */
enum TenantStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
}
