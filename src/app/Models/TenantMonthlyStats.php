<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * A tenant's customers added per month of its own timezone, kept by CustomerObserver so the dashboard counts nothing.
 */
#[Table('tenant_monthly_stats', incrementing: false, timestamps: false)]
class TenantMonthlyStats extends Model
{
    use BelongsToTenant;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'customers_added' => 'integer',
        ];
    }
}
