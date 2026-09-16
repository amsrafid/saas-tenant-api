<?php

namespace App\Enums;

/**
 * Business state of a tenant's customer record.
 */
enum CustomerStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
}
