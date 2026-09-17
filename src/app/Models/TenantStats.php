<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * A tenant's user and customer counts, kept exact by the Customer and User observers so no request counts those tables.
 */
#[Table('tenant_stats', key: 'tenant_id', incrementing: false)]
class TenantStats extends Model
{
    use BelongsToTenant;

    /**
     * The row is created once per tenant and only ever adjusted, so it keeps no creation time.
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
            'users_count' => 'integer',
            'customers_count' => 'integer',
        ];
    }
}
