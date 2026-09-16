<?php

namespace Database\Seeders;

use App\Enums\CustomerStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\TenantRole;
use App\Enums\TenantStatus;
use App\Enums\UserStatus;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SubscriptionService;
use App\Tenancy\TenantScope;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;

/**
 * Seeds the Acme and Globex demo tenants with staff, customers and a subscription each.
 * Rows are created only when missing, so re-running never duplicates or overwrites a reviewer's changes.
 */
class DemoTenantSeeder extends Seeder
{
    /**
     * Demo tenants keyed by slug.
     *
     * @var array<string, array{name: string, plan: string, users: list<array{0: string, 1: string, 2: TenantRole}>, customers: list<array{0: string, 1: string, 2: CustomerStatus, 3: int}>}>
     */
    private const TENANTS = [
        'acme' => [
            'name' => 'Acme Corporation',
            'plan' => 'pro',
            'users' => [
                ['Acme Owner', 'owner@acme.test', TenantRole::Owner],
                ['Acme Admin', 'admin@acme.test', TenantRole::Admin],
                ['Acme Member One', 'member1@acme.test', TenantRole::Member],
                ['Acme Member Two', 'member2@acme.test', TenantRole::Member],
            ],
            'customers' => [
                ['Bluefield Logistics', 'orders@bluefield-logistics.test', CustomerStatus::Active, 11],
                ['Harbor Point Bakery', 'hello@harborpoint-bakery.test', CustomerStatus::Active, 10],
                ['Granite Hill Builders', 'sales@granitehill.test', CustomerStatus::Active, 8],
                ['Maple Lane Dental', 'contact@maplelane-dental.test', CustomerStatus::Active, 6],
                ['Copperleaf Studio', 'billing@copperleaf.test', CustomerStatus::Active, 5],
                ['Riverbend Outfitters', 'info@riverbend.test', CustomerStatus::Inactive, 3],
                ['Silver Pine Hotel', 'frontdesk@silverpine.test', CustomerStatus::Active, 1],
                ['Oakridge Print House', 'team@oakridge-print.test', CustomerStatus::Inactive, 0],
            ],
        ],
        'globex' => [
            'name' => 'Globex Corporation',
            'plan' => Plan::FREE_SLUG,
            'users' => [
                ['Globex Owner', 'owner@globex.test', TenantRole::Owner],
                ['Globex Admin', 'admin@globex.test', TenantRole::Admin],
                ['Globex Member', 'member@globex.test', TenantRole::Member],
            ],
            'customers' => [
                ['Lakeshore Solar', 'energy@lakeshore-solar.test', CustomerStatus::Active, 9],
                ['Greenfield Grocers', 'orders@greenfield-grocers.test', CustomerStatus::Active, 7],
                ['Summit Fitness Club', 'members@summit-fitness.test', CustomerStatus::Active, 4],
                ['Brightwater Interiors', 'studio@brightwater.test', CustomerStatus::Inactive, 2],
                ['Westgate Transit', 'fleet@westgate-transit.test', CustomerStatus::Active, 1],
                ['Cedar Row Books', 'shop@cedarrow-books.test', CustomerStatus::Inactive, 0],
            ],
        ],
    ];

    /**
     * Create whichever demo tenants, staff, customers and subscriptions do not exist yet.
     */
    public function run(SubscriptionService $subscriptions): void
    {
        $plans = Plan::select(['id', 'slug'])
            ->whereIn('slug', array_column(self::TENANTS, 'plan'))
            ->get();

        foreach (self::TENANTS as $slug => $definition) {
            $tenant = $this->tenant($slug, $definition['name']);

            $this->seedUsers($tenant, $definition['users']);
            $this->seedCustomers($tenant, $definition['customers']);
            $this->seedSubscription($tenant, $plans->firstOrFail('slug', $definition['plan']), $subscriptions);
        }
    }

    private function tenant(string $slug, string $name): Tenant
    {
        return Tenant::select(['id'])->firstWhere('slug', $slug)
            ?? Tenant::forceCreate(['name' => $name, 'slug' => $slug, 'status' => TenantStatus::Active]);
    }

    /**
     * @param  list<array{0: string, 1: string, 2: TenantRole}>  $users
     */
    private function seedUsers(Tenant $tenant, array $users): void
    {
        // Email is unique across tenants, so the lookup is deliberately not tenant-scoped.
        $existing = User::withoutGlobalScope(TenantScope::class)->whereIn('email', array_column($users, 1))->pluck('email');
        $missing = $this->missing($users, $existing);

        if ($missing->isEmpty()) {
            return;
        }

        // Hashed once rather than per user; the hashed cast keeps a value that is already a hash.
        $password = Hash::make('password');

        $missing->each(fn (array $user) => User::forceCreate([
            'tenant_id' => $tenant->id,
            'name' => $user[0],
            'email' => $user[1],
            'password' => $password,
            'role' => $user[2],
            'status' => UserStatus::Active,
        ]));
    }

    /**
     * Each customer is back-dated by its months-ago offset, so the dashboard's growth series has a history to show.
     *
     * @param  list<array{0: string, 1: string, 2: CustomerStatus, 3: int}>  $customers
     */
    private function seedCustomers(Tenant $tenant, array $customers): void
    {
        $existing = Customer::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenant->id)
            ->whereIn('email', array_column($customers, 1))
            ->pluck('email');

        $this->missing($customers, $existing)->each(fn (array $customer) => Customer::forceCreate([
            'tenant_id' => $tenant->id,
            'name' => $customer[0],
            'email' => $customer[1],
            'status' => $customer[2],
            'created_at' => now()->subMonthsNoOverflow($customer[3]),
        ]));
    }

    private function seedSubscription(Tenant $tenant, Plan $plan, SubscriptionService $subscriptions): void
    {
        $hasActive = Subscription::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenant->id)
            ->where('status', SubscriptionStatus::Active)
            ->exists();

        if (empty($hasActive)) {
            $subscriptions->start($tenant, $plan);
        }
    }

    /**
     * @param  list<array{0: string, 1: string, 2: mixed}>  $rows
     * @param  Collection<int, string>  $existingEmails
     * @return Collection<int, array{0: string, 1: string, 2: mixed}>
     */
    private function missing(array $rows, Collection $existingEmails): Collection
    {
        return collect($rows)->reject(fn (array $row) => $existingEmails->contains($row[1]));
    }
}
