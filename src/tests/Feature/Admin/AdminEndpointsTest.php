<?php

use App\Enums\BillingPeriod;
use App\Enums\SubscriptionStatus;
use App\Enums\TenantStatus;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->pro = Plan::factory()->withLimits()->create(['name' => 'Pro', 'slug' => 'pro', 'price_cents' => 2900, 'sort_order' => 1]);
    $this->acme = Tenant::factory()->create(['name' => 'Acme', 'slug' => 'acme']);
    $this->globex = Tenant::factory()->create(['name' => 'Globex', 'slug' => 'globex']);
    Subscription::factory()->for($this->acme)->for($this->pro)->create();
    User::factory()->for($this->acme)->count(2)->create();
    User::factory()->for($this->globex)->create();
    Customer::factory()->for($this->acme)->count(3)->create();

    Sanctum::actingAs(User::factory()->platformAdmin()->create());
});

it('lists every tenant, highest id first, with its live plan and usage', function () {
    $this->getJson('api/v1/admin/tenants')
        ->assertOk()
        ->assertJsonPath('data', [
            [
                'id' => $this->globex->id,
                'name' => 'Globex',
                'slug' => 'globex',
                'status' => 'active',
                'timezone' => 'Asia/Dhaka',
                'plan' => null,
                'usage' => ['users' => 1, 'customers' => 0],
            ],
            [
                'id' => $this->acme->id,
                'name' => 'Acme',
                'slug' => 'acme',
                'status' => 'active',
                'timezone' => 'Asia/Dhaka',
                'plan' => ['id' => $this->pro->id, 'name' => 'Pro', 'slug' => 'pro'],
                'usage' => ['users' => 2, 'customers' => 3],
            ],
        ])
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('meta.per_page', 15);
});

it('shows only the live subscription\'s plan, one row per tenant', function () {
    Subscription::factory()->for($this->acme)->for(Plan::factory())->create(['status' => SubscriptionStatus::Canceled]);
    Subscription::factory()->for($this->acme)->for(Plan::factory())->create(['status' => SubscriptionStatus::Expired]);

    $this->getJson('api/v1/admin/tenants')
        ->assertOk()
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('data.1.plan.slug', 'pro');
});

it('filters by status and plan, and prefix-searches name and slug', function (Closure $query, array $names) {
    $this->globex->forceFill(['status' => TenantStatus::Suspended])->save();

    $response = $this->getJson('api/v1/admin/tenants?'.$query($this->pro))->assertOk();

    expect(array_column($response->json('data'), 'name'))->toBe($names);
})->with([
    'status' => [fn () => 'filter[status]=suspended', ['Globex']],
    'plan' => [fn (Plan $pro) => 'filter[plan_id]='.$pro->id, ['Acme']],
    'unknown plan' => [fn () => 'filter[plan_id]=999999', []],
    'name prefix, any case' => [fn () => 'search=GLO', ['Globex']],
    'slug prefix' => [fn () => 'search=acm', ['Acme']],
    'no infix match' => [fn () => 'search=lobex', []],
]);

it('refuses an invalid listing query', function (string $query, array $errors) {
    $this->getJson('api/v1/admin/tenants?'.$query)
        ->assertUnprocessable()
        ->assertOnlyJsonValidationErrors($errors);
})->with([
    'unknown filter' => ['filter[role]=owner', ['filter']],
    'unknown status' => ['filter[status]=deleted', ['filter.status']],
    'non-integer plan' => ['filter[plan_id]=pro', ['filter.plan_id']],
    'per_page above 100' => ['per_page=101', ['per_page']],
]);

it('holds the listing at two queries, with explicit columns, however many tenants there are', function (int $extraTenants) {
    Tenant::factory()->count($extraTenants)->create()->each(function (Tenant $tenant) {
        Subscription::factory()->for($tenant)->for($this->pro)->create();
        User::factory()->for($tenant)->create();
        Customer::factory()->for($tenant)->create();
    });
    DB::enableQueryLog();

    $this->getJson('api/v1/admin/tenants?per_page=100')->assertOk()->assertJsonCount(2 + $extraTenants, 'data');

    $queries = array_column(DB::getQueryLog(), 'query');

    expect($queries)->toHaveCount(2)
        ->and(implode("\n", $queries))->not->toContain('select *');
})->with([0, 20]);

it('suspends a tenant, drops its cached copy, and locks its users out of tenant routes', function () {
    $owner = User::factory()->for($this->acme)->owner()->create();
    Sanctum::actingAs($owner);
    $this->getJson('api/v1/customers')->assertOk();
    expect(Cache::has("tenant:{$this->acme->id}"))->toBeTrue();

    Sanctum::actingAs(User::factory()->platformAdmin()->create());
    $this->patchJson('api/v1/admin/tenants/'.$this->acme->id, ['status' => 'suspended'])
        ->assertOk()
        ->assertJsonPath('data.status', 'suspended')
        ->assertJsonPath('data.plan.slug', 'pro')
        ->assertJsonPath('data.usage', ['users' => 3, 'customers' => 3]);

    expect(Cache::has("tenant:{$this->acme->id}"))->toBeFalse()
        ->and($this->acme->fresh()->status)->toBe(TenantStatus::Suspended);

    Sanctum::actingAs($owner);
    $this->getJson('api/v1/customers')->assertForbidden()->assertJsonPath('message', 'This tenant account is suspended.');
});

it('reactivates a suspended tenant, letting its users back in', function () {
    $this->acme->forceFill(['status' => TenantStatus::Suspended])->save();
    $owner = User::factory()->for($this->acme)->owner()->create();
    Sanctum::actingAs($owner);
    $this->getJson('api/v1/customers')->assertForbidden();

    Sanctum::actingAs(User::factory()->platformAdmin()->create());
    $this->patchJson('api/v1/admin/tenants/'.$this->acme->id, ['status' => 'active'])
        ->assertOk()
        ->assertJsonPath('data.status', 'active');

    Sanctum::actingAs($owner);
    $this->getJson('api/v1/customers')->assertOk();
});

it('updates a tenant in two queries: find, update', function () {
    Queue::fake();
    DB::enableQueryLog();

    $this->patchJson('api/v1/admin/tenants/'.$this->globex->id, ['status' => 'suspended'])->assertOk();

    expect(DB::getQueryLog())->toHaveCount(2);
});

it('answers 404 for an unknown tenant', function () {
    $this->patchJson('api/v1/admin/tenants/999999', ['status' => 'suspended'])->assertNotFound();
});

it('refuses an invalid tenant update', function (array $payload, array $errors) {
    $this->patchJson('api/v1/admin/tenants/'.$this->acme->id, $payload)
        ->assertUnprocessable()
        ->assertOnlyJsonValidationErrors($errors);

    expect($this->acme->fresh()->status)->toBe(TenantStatus::Active);
})->with([
    'missing status' => [[], ['status']],
    'unknown status' => [['status' => 'deleted'], ['status']],
    'other field' => [['status' => 'suspended', 'name' => 'Renamed'], ['name']],
]);

it('reports platform totals, live tenants per plan, and MRR normalised to a month, once the refresh has run', function () {
    $free = Plan::factory()->create(['name' => 'Free', 'slug' => 'free', 'price_cents' => 0, 'sort_order' => 0]);
    $yearly = Plan::factory()->create(['name' => 'Annual', 'slug' => 'annual', 'price_cents' => 12000, 'billing_period' => BillingPeriod::Yearly, 'sort_order' => 2]);
    Subscription::factory()->for($this->globex)->for($yearly)->create();
    Subscription::factory()->for(Tenant::factory()->create(['status' => TenantStatus::Suspended]))->for($this->pro)->create();
    Subscription::factory()->for($this->globex)->for($this->pro)->create(['status' => SubscriptionStatus::Canceled]);
    Subscription::factory()->for(Tenant::factory())->for($yearly)->create();

    $this->getJson('api/v1/admin/analytics')
        ->assertOk()
        ->assertExactJson(['data' => [
            'tenants' => ['total' => 4, 'active' => 3, 'suspended' => 1],
            'users' => 3,
            'customers' => 3,
            'plans' => [
                ['id' => $free->id, 'name' => 'Free', 'slug' => 'free', 'tenants' => 0, 'currency' => 'USD', 'mrr_cents' => 0],
                ['id' => $this->pro->id, 'name' => 'Pro', 'slug' => 'pro', 'tenants' => 2, 'currency' => 'USD', 'mrr_cents' => 5800],
                ['id' => $yearly->id, 'name' => 'Annual', 'slug' => 'annual', 'tenants' => 2, 'currency' => 'USD', 'mrr_cents' => 2000],
            ],
        ]]);
});

it('reads the stored stats in two plain queries, with no aggregate and explicit columns', function () {
    DB::table('platform_stats')->update(['tenants_count' => 900, 'suspended_tenants_count' => 50, 'users_count' => 7000, 'customers_count' => 9000000]);
    DB::table('plan_stats')->update(['tenants_count' => 850, 'mrr_cents' => 2465000]);
    DB::enableQueryLog();

    $this->getJson('api/v1/admin/analytics')
        ->assertOk()
        ->assertExactJson(['data' => [
            'tenants' => ['total' => 900, 'active' => 850, 'suspended' => 50],
            'users' => 7000,
            'customers' => 9000000,
            'plans' => [['id' => $this->pro->id, 'name' => 'Pro', 'slug' => 'pro', 'tenants' => 850, 'currency' => 'USD', 'mrr_cents' => 2465000]],
        ]]);

    $sql = strtolower(implode("\n", array_column(DB::getQueryLog(), 'query')));

    expect(DB::getQueryLog())->toHaveCount(2)
        ->and($sql)->not->toContain('count(')
        ->and($sql)->not->toContain('sum(')
        ->and($sql)->not->toContain('group by')
        ->and($sql)->not->toContain('select *');
});

it('answers zeros before the first refresh has run', function () {
    DB::table('plan_stats')->delete();
    DB::table('platform_stats')->delete();

    $this->getJson('api/v1/admin/analytics')
        ->assertOk()
        ->assertExactJson(['data' => [
            'tenants' => ['total' => 0, 'active' => 0, 'suspended' => 0],
            'users' => 0,
            'customers' => 0,
            'plans' => [],
        ]]);
});

it('serves analytics from cache on the next call, with no queries', function () {
    $this->getJson('api/v1/admin/analytics')->assertOk();
    DB::enableQueryLog();

    $this->getJson('api/v1/admin/analytics')->assertOk()->assertJsonPath('data.customers', 3);

    expect(DB::getQueryLog())->toBeEmpty();
});
