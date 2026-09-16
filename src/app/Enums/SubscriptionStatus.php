<?php

namespace App\Enums;

/**
 * Lifecycle state of a subscription.
 */
enum SubscriptionStatus: string
{
    case Active = 'active';
    case Canceled = 'canceled';
    case Expired = 'expired';
}
