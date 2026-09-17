<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * A plan's live subscription count and monthly recurring revenue, kept by RefreshPlatformStats.
 */
#[Table('plan_stats', key: 'plan_id', incrementing: false)]
class PlanStats extends Model
{
    /**
     * The row is created once per plan and only ever refreshed, so it keeps no creation time.
     */
    public const CREATED_AT = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tenants_count' => 'integer',
            'mrr_cents' => 'integer',
        ];
    }
}
