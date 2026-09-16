<?php

namespace Database\Factories;

use App\Enums\TenantRole;
use App\Enums\UserStatus;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * Builds User models for tests and seeders.
 *
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The hashed password shared by every generated user, computed once.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('password'),
            'role' => TenantRole::Member,
            'status' => UserStatus::Active,
        ];
    }

    /**
     * A tenant owner.
     */
    public function owner(): static
    {
        return $this->state(['role' => TenantRole::Owner]);
    }

    /**
     * A platform admin, outside every tenant.
     */
    public function platformAdmin(): static
    {
        return $this->state([
            'tenant_id' => null,
            'role' => TenantRole::PlatformAdmin,
        ]);
    }
}
