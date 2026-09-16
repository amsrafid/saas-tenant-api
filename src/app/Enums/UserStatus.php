<?php

namespace App\Enums;

/**
 * Account state of a tenant staff member.
 */
enum UserStatus: string
{
    case Active = 'active';
    case Disabled = 'disabled';
}
