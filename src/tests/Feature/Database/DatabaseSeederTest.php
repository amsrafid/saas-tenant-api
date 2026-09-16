<?php

use App\Enums\BillingPeriod;
use App\Enums\SubscriptionStatus;
use App\Enums\TenantRole;
use App\Models\Plan;
use App\Repositories\PlanRepository;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * Row count, latest update and a password fingerprint per seeded table, to detect any write on a re-run.
 *
 * @return array<string, mixed>
 */
function seededTableSnapshot(): array
{
    return collect(['plans', 'plan_features', 'tenants', 'tenant_stats', 'platform_stats', 'plan_stats', 'users', 'customers', 'subscriptions'])
        ->mapWithKeys(fn (string $table) => [$table => [
            'count' => DB::table($table)->count(),
            'updated_at' => DB::table($table)->max('updated_at'),
        ]])
        ->put('passwords', DB::table('users')->orderBy('id')->pluck('password')->all())
        ->all();
}

it('seeds the documented plans, tenants, users, customers and subscriptions', function () {
    $this->seed();

    expect(DB::table('plans')->count())->toBe(3)
        ->and(DB::table('plan_features')->count())->toBe(6)
        ->and(DB::table('tenants')->count())->toBe(2)
        ->and(DB::table('users')->count())->toBe(8)
        ->and(DB::table('customers')->count())->toBe(14)
        ->and(DB::table('subscriptions')->count())->toBe(2)
        ->and(DB::table('users')->whereNull('tenant_id')->pluck('role')->all())->toBe([TenantRole::PlatformAdmin->value])
        ->and(DB::table('customers')->distinct()->pluck('status')->sort()->values()->all())->toBe(['active', 'inactive']);
});

it('lets each account printed by the setup service log in', function (string $email) {
    $this->seed();

    $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => 'password'])->assertOk();
})->with(['admin@platform.test', 'owner@acme.test', 'owner@globex.test']);

it('writes nothing when run a second time', function () {
    $this->seed();
    $before = seededTableSnapshot();

    $this->travel(1)->hours();
    $this->seed();

    expect(seededTableSnapshot())->toBe($before);
});

it('stores each demo tenant\'s user and customer counts by the time seeding ends', function () {
    Queue::fake();

    $this->seed();

    expect(DB::table('tenant_stats')->join('tenants', 'tenants.id', '=', 'tenant_stats.tenant_id')->orderBy('tenants.slug')->get(['slug', 'users_count', 'customers_count'])->map(fn ($row) => (array) $row)->all())
        ->toBe([
            ['slug' => 'acme', 'users_count' => 4, 'customers_count' => 8],
            ['slug' => 'globex', 'users_count' => 3, 'customers_count' => 6],
        ]);
});

it('stores the platform analytics figures by the time seeding ends', function () {
    Queue::fake();

    $this->seed();

    expect(DB::table('platform_stats')->first(['tenants_count', 'suspended_tenants_count', 'users_count', 'customers_count']))
        ->toEqual((object) ['tenants_count' => 2, 'suspended_tenants_count' => 0, 'users_count' => 7, 'customers_count' => 14])
        ->and(DB::table('plan_stats')->join('plans', 'plans.id', '=', 'plan_stats.plan_id')->orderBy('plans.sort_order')->get(['slug', 'tenants_count', 'mrr_cents'])->map(fn ($row) => (array) $row)->all())
        ->toBe([
            ['slug' => 'free', 'tenants_count' => 1, 'mrr_cents' => 0],
            ['slug' => 'pro', 'tenants_count' => 1, 'mrr_cents' => 2900],
            ['slug' => 'enterprise', 'tenants_count' => 0, 'mrr_cents' => 0],
        ]);
});

it('seeds the Free plan registration depends on', function () {
    $this->seed();

    expect(Plan::where('slug', Plan::FREE_SLUG)->where('is_active', true)->exists())->toBeTrue();

    $this->postJson('/api/v1/auth/register', [
        'tenant_name' => 'Initech',
        'name' => 'Initech Owner',
        'email' => 'owner@initech.test',
        'password' => 'correct-horse',
    ])->assertCreated();
});

it('gives each demo tenant exactly one active subscription, on different plans', function () {
    $this->seed();

    $active = DB::table('subscriptions')
        ->join('plans', 'plans.id', '=', 'subscriptions.plan_id')
        ->where('subscriptions.status', SubscriptionStatus::Active->value)
        ->pluck('plans.slug', 'subscriptions.tenant_id');

    expect($active)->toHaveCount(2)
        ->and($active->unique())->toHaveCount(2)
        ->and(DB::table('subscriptions')->count())->toBe(2);
});

it('seeds at least one unlimited limit', function () {
    $this->seed();

    expect(DB::table('plan_features')->whereNull('limit_value')->exists())->toBeTrue();
});

it('drops the cached plan when the plan seeder runs again', function () {
    $this->seed(PlanSeeder::class);
    Plan::where('slug', Plan::FREE_SLUG)->update(['billing_period' => BillingPeriod::Yearly]);
    expect(app(PlanRepository::class)->findBySlug(Plan::FREE_SLUG)->billing_period)->toBe(BillingPeriod::Yearly);

    $this->seed(PlanSeeder::class);

    expect(app(PlanRepository::class)->findBySlug(Plan::FREE_SLUG)->billing_period)->toBe(BillingPeriod::Monthly);
});
