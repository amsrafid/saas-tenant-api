<?php

namespace App\Models;

use App\Enums\TenantRole;
use App\Enums\UserStatus;
use App\Models\Concerns\Searchable;
use App\Observers\UserObserver;
use App\Tenancy\BelongsToTenant;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\NewAccessToken;

/**
 * A staff member who authenticates against the API; a null tenant marks a platform admin.
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password'])]
#[ObservedBy(UserObserver::class)]
class User extends Authenticatable
{
    use BelongsToTenant;

    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    use Searchable;

    /**
     * The company this user works for.
     *
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Issue an API token expiring after the configured Sanctum lifetime, already linked back to this user.
     */
    public function createApiToken(): NewAccessToken
    {
        // Unrestricted: gates re-read the role on every request. Narrowing here first needs role changes to revoke tokens.
        $token = $this->createToken('api', ['*'], now()->addMinutes(config('sanctum.expiration')));
        $token->accessToken->setRelation('tokenable', $this);

        return $token;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => TenantRole::class,
            'status' => UserStatus::class,
            'password' => 'hashed',
        ];
    }
}
