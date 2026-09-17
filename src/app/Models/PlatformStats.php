<?php

namespace App\Models;

use App\Observers\PlatformStatsObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The platform-wide tenant, user and customer totals in a single row, kept by RefreshPlatformStats.
 */
#[Table('platform_stats', incrementing: false)]
#[ObservedBy(PlatformStatsObserver::class)]
class PlatformStats extends Model
{
    /**
     * The row is created once and only ever refreshed, so it keeps no creation time.
     */
    public const CREATED_AT = null;

    /**
     * The totals before the first refresh has run.
     *
     * @var array<string, int>
     */
    protected $attributes = [
        'tenants_count' => 0,
        'suspended_tenants_count' => 0,
        'users_count' => 0,
        'customers_count' => 0,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tenants_count' => 'integer',
            'suspended_tenants_count' => 'integer',
            'users_count' => 'integer',
            'customers_count' => 'integer',
        ];
    }
}
