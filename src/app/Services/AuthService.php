<?php

namespace App\Services;

use App\Enums\TenantRole;
use App\Enums\TenantStatus;
use App\Enums\UserStatus;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Repositories\PlanRepository;
use App\Repositories\TenantRepository;
use App\Tenancy\TenantScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\NewAccessToken;
use RuntimeException;

/**
 * Company registration and login; both end by issuing an API token.
 */
class AuthService
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly PlanRepository $plans,
        private readonly TenantRepository $tenants,
    ) {}

    /**
     * Create the tenant, its owner and a Free subscription in one transaction, and issue the owner a token.
     *
     * @param  array{tenant_name: string, name: string, email: string, password: string}  $data
     *
     * @throws RuntimeException
     */
    public function register(array $data): NewAccessToken
    {
        $plan = $this->freePlan();
        $slug = $this->uniqueSlug($data['tenant_name']);

        return DB::transaction(function () use ($data, $plan, $slug) {
            $tenant = $this->createTenant($data['tenant_name'], $slug);
            $owner = $this->createOwner($tenant, $data);
            $this->subscriptions->start($tenant, $plan);

            return $owner->createApiToken();
        });
    }

    /**
     * Verify the credentials and issue a token, without revealing whether the email exists.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function login(string $email, string $password): NewAccessToken
    {
        $user = $this->findByEmail($email);

        if (empty($this->passwordMatches($user, $password))) {
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        if ($user->status === UserStatus::Disabled) {
            throw new AuthorizationException('This account is disabled.');
        }

        $user->setRelation('tenant', $this->tenants->find($user->tenant_id));

        return $user->createApiToken();
    }

    private function freePlan(): Plan
    {
        return $this->plans->findBySlug(Plan::FREE_SLUG)
            ?? throw new RuntimeException("The default plan '".Plan::FREE_SLUG."' is missing; run the plan seeder before accepting registrations.");
    }

    private function uniqueSlug(string $name): string
    {
        $slug = Str::slug($name);

        if (Tenant::where('slug', $slug)->exists()) {
            $slug .= '-'.Str::lower(Str::random(6));
        }

        return $slug;
    }

    private function createTenant(string $name, string $slug): Tenant
    {
        $tenant = new Tenant(['name' => $name, 'slug' => $slug]);
        $tenant->status = TenantStatus::Active;
        $tenant->save();

        return $tenant;
    }

    /**
     * @param  array{name: string, email: string, password: string}  $data
     */
    private function createOwner(Tenant $tenant, array $data): User
    {
        $owner = new User([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
        ]);
        $owner->tenant_id = $tenant->id;
        $owner->role = TenantRole::Owner;
        $owner->status = UserStatus::Active;
        $owner->save();

        return $owner->setRelation('tenant', $tenant);
    }

    private function findByEmail(string $email): ?User
    {
        // Login happens before any tenant is known, so the lookup cannot be tenant-scoped.
        return User::withoutGlobalScope(TenantScope::class)
            ->select(['id', 'tenant_id', 'name', 'email', 'password', 'role', 'status'])
            ->where('email', $email)
            ->first();
    }

    private function passwordMatches(?User $user, string $password): bool
    {
        if (empty($user)) {
            // Hash anyway, so an unknown email costs as long as a wrong password and timing cannot tell them apart.
            Hash::make($password);

            return false;
        }

        return Hash::check($password, $user->password);
    }
}
