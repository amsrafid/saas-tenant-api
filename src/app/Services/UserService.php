<?php

namespace App\Services;

use App\Enums\FeatureKey;
use App\Enums\TenantRole;
use App\Enums\UserStatus;
use App\Models\TenantStats;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * The current tenant's staff users and the role rules guarding them; tenant isolation comes from the model's global scope.
 */
class UserService
{
    public function __construct(
        private readonly AuthUserService $authUser,
        private readonly SubscriptionService $subscriptions,
    ) {}

    /**
     * Newest users first, optionally filtered by role and status and prefix-searched on name and email.
     *
     * @return LengthAwarePaginator<int, User>
     */
    public function paginate(?string $role, ?string $status, ?string $search, int $perPage): LengthAwarePaginator
    {
        return User::select(['id', 'name', 'email', 'role', 'status'])
            ->when($role, fn (Builder $query, string $role) => $query->where('role', $role))
            ->when($status, fn (Builder $query, string $status) => $query->where('status', $status))
            ->search(['name', 'email'], $search)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            // Unfiltered, the total is the tenant's stored count, so the listing never counts the whole table.
            ->paginate($perPage, total: empty($role) && empty($status) && empty($search) ? TenantStats::value('users_count') : null)
            ->withQueryString();
    }

    /**
     * The user with the given id; another tenant's user is not found.
     *
     * @throws ModelNotFoundException
     */
    public function find(int $id): User
    {
        return User::select(['id', 'tenant_id', 'name', 'email', 'role', 'status'])->findOrFail($id);
    }

    /**
     * Create an active user for the current tenant, with a role no higher than the acting user's, within the plan's `max_users` limit.
     *
     * @param  array{name: string, email: string, password: string, role: string}  $data
     */
    public function create(array $data): User
    {
        $role = TenantRole::from($data['role']);
        $this->ensureCanGrant($role);

        return DB::transaction(function () use ($data, $role) {
            $this->subscriptions->ensureWithinLimit(FeatureKey::MaxUsers);

            $user = new User($data);
            $user->role = $role;
            $user->status = UserStatus::Active;
            $user->save();

            return $user;
        });
    }

    /**
     * Apply the given fields to the user, enforcing the role rules.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(User $user, array $data): User
    {
        $this->ensureCanManage($user);
        $wasActiveOwner = $this->isActiveOwner($user);

        // Role and status are not fillable; the validated values are applied here and checked before saving.
        $user->forceFill($data);

        // The last-owner check comes first: a sole owner demoting themselves is told why, not just "your own role".
        if ($wasActiveOwner && empty($this->isActiveOwner($user))) {
            $this->ensureAnotherActiveOwner($user);
        }

        if ($user->isDirty('role')) {
            abort_if($user->is($this->authUser->user()), Response::HTTP_FORBIDDEN, 'You cannot change your own role.');
            $this->ensureCanGrant($user->role);
        }

        $user->save();

        if ($user->wasChanged('status') && $user->status === UserStatus::Disabled) {
            // Tokens carry every ability and the tenant middleware does not check status, so only this ends access.
            $user->tokens()->delete();
        }

        return $user;
    }

    /**
     * Delete the user, their tokens and their count permanently; the last active owner is kept.
     *
     * @throws ModelNotFoundException
     */
    public function delete(User $user): void
    {
        $this->ensureCanManage($user);

        if ($this->isActiveOwner($user)) {
            $this->ensureAnotherActiveOwner($user);
        }

        DB::transaction(function () use ($user) {
            // A concurrent delete of the same user waits here, then finds nothing, so the count drops once.
            User::select(['id'])->lockForUpdate()->findOrFail($user->id);
            $user->tokens()->delete();
            $user->delete();
        });
    }

    private function ensureCanGrant(TenantRole $role): void
    {
        abort_if(
            $role->rank() > $this->authUser->user()->role->rank(),
            Response::HTTP_FORBIDDEN,
            'You cannot grant a role above your own.',
        );
    }

    private function ensureCanManage(User $user): void
    {
        abort_if(
            $user->role->rank() > $this->authUser->user()->role->rank(),
            Response::HTTP_FORBIDDEN,
            'You cannot modify a user whose role is above your own.',
        );
    }

    private function isActiveOwner(User $user): bool
    {
        return $user->role === TenantRole::Owner && $user->status === UserStatus::Active;
    }

    private function ensureAnotherActiveOwner(User $user): void
    {
        $hasAnother = User::where('role', TenantRole::Owner)
            ->where('status', UserStatus::Active)
            ->whereKeyNot($user->id)
            ->exists();

        abort_if(empty($hasAnother), Response::HTTP_CONFLICT, 'The tenant must keep at least one active owner.');
    }
}
