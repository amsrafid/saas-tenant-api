<?php

namespace App\Enums;

/**
 * A plan feature that carries a limit.
 */
enum FeatureKey: string
{
    case MaxUsers = 'max_users';
    case MaxCustomers = 'max_customers';
}
