<?php

namespace Database\Seeders;

use App\Enums\TenantRole;
use App\Enums\UserStatus;
use App\Models\User;
use App\Tenancy\TenantScope;
use Illuminate\Database\Seeder;

/**
 * Seeds the platform admin account, which belongs to no tenant.
 */
class PlatformAdminSeeder extends Seeder
{
    /**
     * The platform admin's login, printed by the setup service.
     */
    public const EMAIL = 'admin@platform.test';

    /**
     * Create the platform admin unless the account already exists.
     */
    public function run(): void
    {
        if (User::withoutGlobalScope(TenantScope::class)->where('email', self::EMAIL)->exists()) {
            return;
        }

        User::forceCreate([
            'tenant_id' => null,
            'name' => 'Platform Admin',
            'email' => self::EMAIL,
            'password' => 'password',
            'role' => TenantRole::PlatformAdmin,
            'status' => UserStatus::Active,
        ]);
    }
}
