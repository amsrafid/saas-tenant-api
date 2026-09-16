<?php

namespace App\Models;

use App\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

/**
 * Sanctum's token model, loading its user before any tenant is known.
 */
class PersonalAccessToken extends SanctumPersonalAccessToken
{
    /**
     * The token's owner; the user is where the tenant comes from, so it cannot be tenant-scoped yet.
     *
     * @return MorphTo<User, $this>
     */
    public function tokenable(): MorphTo
    {
        return $this->morphTo('tokenable')->withoutGlobalScope(TenantScope::class);
    }
}
