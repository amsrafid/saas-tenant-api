<?php

use App\Models\Customer;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    Carbon::setTestNow('2026-09-16 10:00:00');

    [$this->acme, $this->globex] = Tenant::factory()->count(2)->create();
    $this->plan = Plan::factory()->withLimits(['max_users' => 10, 'max_customers' => 1000])
        ->create(['name' => 'Pro', 'slug' => 'pro']);
    Subscription::factory()->for($this->acme)->for($this->plan)->create();
    Subscription::factory()->for($this->globex)->for($this->plan)->create();
    $this->owner = User::factory()->for($this->acme)->owner()->create();
});

function addCustomers(Tenant $tenant, int $count, string $createdAt): void
{
    Customer::factory()->for($tenant)->count($count)->create(['created_at' => $createdAt]);
}

/**
 * The months of the series, oldest first, with the given additions and zero everywhere else.
 *
 * @param  array<string, int>  $added
 * @return list<array{month: string, customers_added: int}>
 */
function growthSeries(string $lastMonth, array $added = []): array
{
    $last = Carbon::parse($lastMonth.'-01');

    return array_map(function (int $monthsAgo) use ($last, $added) {
        $month = $last->copy()->subMonths($monthsAgo)->format('Y-m');

        return ['month' => $month, 'customers_added' => $added[$month] ?? 0];
    }, range(11, 0));
}

function dashboard(User $user): TestResponse
{
    Sanctum::actingAs($user);

    return test()->getJson('api/v1/dashboard/analytics');
}

it('shows the plan, usage and twelve months of customer growth with the gaps filled', function () {
    addCustomers($this->acme, 2, '2025-09-30 12:00:00');
    addCustomers($this->acme, 3, '2025-10-01 12:00:00');
    addCustomers($this->acme, 1, '2026-03-15 12:00:00');
    addCustomers($this->acme, 4, '2026-09-16 09:00:00');
    User::factory()->for($this->acme)->create();

    dashboard($this->owner)
        ->assertOk()
        ->assertExactJson(['data' => [
            'plan' => ['id' => $this->plan->id, 'name' => 'Pro', 'slug' => 'pro'],
            'usage' => [
                'max_users' => ['used' => 2, 'limit' => 10, 'remaining' => 8],
                'max_customers' => ['used' => 10, 'limit' => 1000, 'remaining' => 990],
            ],
            'customer_growth' => growthSeries('2026-09', ['2025-10' => 3, '2026-03' => 1, '2026-09' => 4]),
        ]]);
});

it('buckets a customer into the month of the tenant\'s timezone, not the UTC month', function () {
    $utc = Tenant::factory()->create(['timezone' => 'UTC']);
    Subscription::factory()->for($utc)->for($this->plan)->create();
    Carbon::setTestNow('2026-09-30 23:30:00');
    addCustomers($this->acme, 1, now()->toDateTimeString());
    addCustomers($utc, 1, now()->toDateTimeString());

    Carbon::setTestNow('2026-10-01 00:30:00');

    // Asia/Dhaka (+06) was already in October at 23:30 UTC on 30 September; UTC was not.
    dashboard($this->owner)->assertOk()
        ->assertJsonPath('data.customer_growth', growthSeries('2026-10', ['2026-10' => 1]));
    dashboard(User::factory()->for($utc)->create())->assertOk()
        ->assertJsonPath('data.customer_growth', growthSeries('2026-10', ['2026-09' => 1]));
});

it('ends the series at the tenant\'s current month', function () {
    Carbon::setTestNow('2026-09-30 19:00:00');

    dashboard($this->owner)->assertOk()
        ->assertJsonPath('data.customer_growth.0.month', '2025-11')
        ->assertJsonPath('data.customer_growth.11.month', '2026-10');
});

it('shows only the tenant\'s own figures', function () {
    addCustomers($this->globex, 5, '2026-08-10 12:00:00');
    User::factory()->for($this->globex)->count(3)->create();

    dashboard($this->owner)->assertOk()
        ->assertJsonPath('data.usage.max_users.used', 1)
        ->assertJsonPath('data.usage.max_customers.used', 0)
        ->assertJsonPath('data.customer_growth', growthSeries('2026-09'));
});

it('shows a customer written through the API on the very next dashboard and usage read', function () {
    dashboard($this->owner)->assertOk()->assertJsonPath('data.usage.max_customers.used', 0);

    $id = $this->postJson('api/v1/customers', ['name' => 'New', 'email' => 'new@acme.test'])->assertCreated()->json('data.id');

    dashboard($this->owner)->assertOk()
        ->assertJsonPath('data.usage.max_customers.used', 1)
        ->assertJsonPath('data.customer_growth.11', ['month' => '2026-09', 'customers_added' => 1]);
    $this->getJson('api/v1/subscription/usage')->assertOk()->assertJsonPath('data.max_customers.used', 1);

    $this->deleteJson("api/v1/customers/{$id}")->assertNoContent();

    dashboard($this->owner)->assertOk()->assertJsonPath('data.usage.max_customers.used', 0);
    $this->getJson('api/v1/subscription/usage')->assertOk()->assertJsonPath('data.max_customers.used', 0);
});

it('keeps a deleted customer in the month it was added', function () {
    addCustomers($this->acme, 2, '2026-08-10 12:00:00');

    Customer::withoutGlobalScopes()->where('tenant_id', $this->acme->id)->first()->delete();

    dashboard($this->owner)->assertOk()
        ->assertJsonPath('data.usage.max_customers.used', 1)
        ->assertJsonPath('data.customer_growth', growthSeries('2026-09', ['2026-08' => 2]));
});

it('leaves the series unchanged when the create rolls back', function () {
    addCustomers($this->acme, 1, '2026-09-01 12:00:00');

    expect(fn () => DB::transaction(function () {
        addCustomers($this->acme, 2, '2026-09-02 12:00:00');

        throw new RuntimeException('Rolled back.');
    }))->toThrow(RuntimeException::class);

    expect(DB::table('tenant_monthly_stats')->where('tenant_id', $this->acme->id)->get(['month', 'customers_added'])->map(fn ($row) => (array) $row)->all())
        ->toBe([['month' => '2026-09-01', 'customers_added' => 1]]);
});

it('reads stored rows only: the same queries however many customers, no aggregate, explicit columns', function (int $months) {
    for ($monthsAgo = 0; $monthsAgo < $months; $monthsAgo++) {
        addCustomers($this->acme, 3, Carbon::parse('2026-09-10')->subMonths($monthsAgo)->toDateTimeString());
    }
    dashboard($this->owner)->assertOk();
    DB::enableQueryLog();

    dashboard($this->owner)->assertOk();

    $log = array_column(DB::getQueryLog(), 'query');
    $sql = strtolower(implode("\n", $log));

    // The tenant, subscription and plan are cached by the first call; what remains is a primary-key read and a primary-key range of at most twelve rows.
    expect($log)->toBe([
        'select (select "users_count" from "tenant_stats" where "tenant_stats"."tenant_id" = ?) as "max_users", (select "customers_count" from "tenant_stats" where "tenant_stats"."tenant_id" = ?) as "max_customers" limit 1',
        'select "customers_added", "month" from "tenant_monthly_stats" where "month" >= ? and "tenant_monthly_stats"."tenant_id" = ?',
    ])
        ->and($sql)->not->toContain('count(')
        ->and($sql)->not->toContain('sum(')
        ->and($sql)->not->toContain('group by')
        ->and($sql)->not->toContain('select *');
})->with(['no customers' => 0, 'one month' => 1, 'twelve months' => 12]);

it('grants every member the dashboard', function (string $role) {
    dashboard(User::factory()->for($this->acme)->create(['role' => $role]))->assertOk();
})->with(['owner', 'admin', 'member']);

it('answers 404 when the tenant has no live subscription', function () {
    Subscription::withoutGlobalScopes()->where('tenant_id', $this->acme->id)->update(['status' => 'expired']);

    dashboard($this->owner)->assertNotFound();
});

it('refuses a platform admin, who belongs to no tenant', function () {
    dashboard(User::factory()->platformAdmin()->create())->assertForbidden();
});

it('rejects an unauthenticated request', function () {
    $this->getJson('api/v1/dashboard/analytics')->assertUnauthorized();
});

it('seeds a growth series that matches the seeded customers, also after a re-run', function () {
    $this->seed(DatabaseSeeder::class);
    $this->seed(DatabaseSeeder::class);

    $stored = DB::table('tenant_monthly_stats')->sum('customers_added');
    $owner = User::withoutGlobalScopes()->where('email', 'owner@acme.test')->first();

    expect((int) $stored)->toBe(DB::table('customers')->count());
    dashboard($owner)->assertOk()
        ->assertJsonPath('data.customer_growth', growthSeries('2026-09', [
            '2025-10' => 1, '2025-11' => 1, '2026-01' => 1, '2026-03' => 1, '2026-04' => 1, '2026-06' => 1, '2026-08' => 1, '2026-09' => 1,
        ]));
});
