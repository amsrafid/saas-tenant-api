<?php

use App\Jobs\RefreshPlatformStats;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    [$this->acme, $this->globex] = Tenant::factory()->count(2)->create();
    $this->plan = Plan::factory()->withLimits(['max_users' => 10, 'max_customers' => 3])->create(['slug' => 'pro']);
    Subscription::factory()->for($this->acme)->for($this->plan)->create();
    $this->owner = Model::withoutEvents(fn () => User::factory()->for($this->acme)->owner()->create());
});

/**
 * Overwrite a tenant's stored counts, so a test can tell them apart from the real row counts.
 */
function setStats(Tenant $tenant, int $users, int $customers): void
{
    DB::table('tenant_stats')->where('tenant_id', $tenant->id)->update(['users_count' => $users, 'customers_count' => $customers]);
}

/**
 * Queries in the log that count a whole tenant's users or customers.
 *
 * @return list<string>
 */
function tenantRangeCounts(): array
{
    return array_values(array_filter(
        array_column(DB::getQueryLog(), 'query'),
        fn (string $query) => (bool) preg_match('/count\(.*from "(users|customers)"/', $query) && ! str_contains($query, '"email" = ?'),
    ));
}

it('refuses a create at the limit from the stored count, whatever the table holds', function () {
    setStats($this->acme, 1, 3);
    Sanctum::actingAs($this->owner);

    $this->postJson('api/v1/customers', ['name' => 'New', 'email' => 'new@acme.test'])
        ->assertTooManyRequests()
        ->assertJsonPath('used', 3);
});

it('reports usage from the stored counts, without counting the tables', function () {
    setStats($this->acme, 7, 2);
    Sanctum::actingAs($this->owner);
    DB::enableQueryLog();

    $this->getJson('api/v1/subscription/usage')
        ->assertOk()
        ->assertJsonPath('data.max_users', ['used' => 7, 'limit' => 10, 'remaining' => 3])
        ->assertJsonPath('data.max_customers', ['used' => 2, 'limit' => 3, 'remaining' => 1]);

    expect(tenantRangeCounts())->toBeEmpty();
});

it('refuses a downgrade from the stored counts', function () {
    Plan::factory()->withLimits(['max_users' => 3, 'max_customers' => 1000])->create(['slug' => 'small']);
    setStats($this->acme, 4, 0);
    Sanctum::actingAs($this->owner);

    $this->patchJson('api/v1/subscription', ['plan' => 'small'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['plan' => 'Current max_users usage (4)']);
});

it('lists tenants with their stored counts, without counting the tables', function () {
    setStats($this->acme, 12, 3400);
    Sanctum::actingAs(User::factory()->platformAdmin()->create());
    DB::enableQueryLog();

    $this->getJson('api/v1/admin/tenants')
        ->assertOk()
        ->assertJsonPath('data.1.usage', ['users' => 12, 'customers' => 3400])
        ->assertJsonPath('data.0.usage', ['users' => 0, 'customers' => 0]);

    expect(tenantRangeCounts())->toBeEmpty();
});

it('sums the stored counts into the platform totals, without counting the tables', function () {
    setStats($this->acme, 12, 3400);
    setStats($this->globex, 3, 5);
    DB::enableQueryLog();

    RefreshPlatformStats::dispatchSync();

    expect(DB::table('platform_stats')->first(['users_count', 'customers_count']))->toEqual((object) ['users_count' => 15, 'customers_count' => 3405])
        ->and(tenantRangeCounts())->toBeEmpty();
});
