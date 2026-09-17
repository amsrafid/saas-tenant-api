<?php

use App\Jobs\RefreshPlatformStats;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    [$this->acme, $this->globex] = Tenant::factory()->count(2)->create();
    $this->plan = Plan::factory()->withLimits(['max_users' => 3, 'max_customers' => 3])->create();
    $this->subscription = Subscription::factory()->for($this->acme)->for($this->plan)->create();
    $this->owner = User::factory()->for($this->acme)->owner()->create();
    Sanctum::actingAs($this->owner);
});

dataset('limited resources', [
    'users' => [
        'users',
        'max_users',
        ['name' => 'New', 'email' => 'new@acme.test', 'password' => 'secret123', 'role' => 'member'],
        // The acting owner is already one of the tenant's users.
        fn (Tenant $tenant, int $total) => User::factory()->for($tenant)->count($total - 1)->create(),
    ],
    'customers' => [
        'customers',
        'max_customers',
        ['name' => 'New', 'email' => 'new@acme.test'],
        fn (Tenant $tenant, int $total) => Customer::factory()->for($tenant)->count($total)->create(),
    ],
]);

it('refuses a create at the limit with 429 naming the feature, limit and usage, and creates nothing', function (string $table, string $feature, array $payload, Closure $fill) {
    $fill($this->acme, 3);

    $this->postJson("api/v1/{$table}", $payload)
        ->assertTooManyRequests()
        ->assertHeaderMissing('Retry-After')
        ->assertExactJson([
            'message' => "The plan's {$feature} limit of 3 has been reached.",
            'feature' => $feature,
            'limit' => 3,
            'used' => 3,
        ]);

    expect(DB::table($table)->where('email', 'new@acme.test')->exists())->toBeFalse();
})->with('limited resources');

it('allows the create that reaches the limit, then refuses the next', function (string $table, string $feature, array $payload, Closure $fill) {
    $fill($this->acme, 2);

    $this->postJson("api/v1/{$table}", $payload)->assertCreated();
    $this->postJson("api/v1/{$table}", [...$payload, 'email' => 'next@acme.test'])->assertTooManyRequests()->assertJsonPath('used', 3);
})->with('limited resources');

it('refuses every create when the limit is zero', function () {
    $this->plan->features()->where('key', 'max_customers')->update(['limit_value' => 0]);

    $this->postJson('api/v1/customers', ['name' => 'New', 'email' => 'new@acme.test'])
        ->assertTooManyRequests()
        ->assertJsonPath('limit', 0)
        ->assertJsonPath('used', 0);
});

it('never refuses a create on an unlimited plan', function (string $table, string $feature, array $payload, Closure $fill) {
    $this->plan->features()->where('key', $feature)->update(['limit_value' => null]);
    $fill($this->acme, 5);

    $this->postJson("api/v1/{$table}", $payload)->assertCreated();
})->with('limited resources');

it('counts only the current tenant\'s rows', function (string $table, string $feature, array $payload, Closure $fill) {
    $fill($this->globex, 6);

    $this->postJson("api/v1/{$table}", $payload)->assertCreated();
})->with('limited resources');

it('applies a limit raised by a platform admin on the next create', function (string $table, string $feature, array $payload, Closure $fill) {
    $fill($this->acme, 3);
    $this->postJson("api/v1/{$table}", $payload)->assertTooManyRequests();

    Sanctum::actingAs(User::factory()->platformAdmin()->create());
    $this->patchJson('api/v1/admin/plans/'.$this->plan->id, ['features' => [$feature => 4]])->assertOk();

    Sanctum::actingAs($this->owner);
    $this->postJson("api/v1/{$table}", $payload)->assertCreated();
})->with('limited resources');

it('answers 404 on a create when the tenant has no live subscription', function (string $table, string $feature, array $payload, Closure $fill) {
    $this->subscription->forceFill(['status' => 'expired'])->save();

    $this->postJson("api/v1/{$table}", $payload)->assertNotFound();

    expect(DB::table($table)->where('email', 'new@acme.test')->exists())->toBeFalse();
})->with('limited resources');

it('checks the limit with one locked tenant_stats read of the created resource, then counts the insert, never a count(*), explicit columns', function (string $table, string $column, array $payload, int $queries) {
    Queue::fake();
    DB::enableQueryLog();

    $this->postJson("api/v1/{$table}", $payload)->assertCreated();

    $log = array_column(DB::getQueryLog(), 'query');

    expect($log)->toHaveCount($queries)
        ->and(array_values(array_filter($log, fn (string $query) => str_contains($query, 'from "tenant_stats"'))))
        ->toBe(["select \"{$column}\" from \"tenant_stats\" where \"tenant_stats\".\"tenant_id\" = ? limit 1 for update"])
        ->and($log[7])->toBe("update \"tenant_stats\" set \"{$column}\" = \"{$column}\" + 1, \"updated_at\" = ? where \"tenant_stats\".\"tenant_id\" = ?")
        ->and(implode("\n", $log))->not->toContain('select *')
        // The only count left is the unique-email validation rule, bound to one email by its unique index.
        ->and(array_filter($log, fn (string $query) => str_contains($query, 'count(') && ! str_contains($query, '"email" = ?')))->toBeEmpty();
})->with([
    'users: tenant, unique email, subscription, plan, features, locked stats, insert, increment' => ['users', 'users_count', ['name' => 'New', 'email' => 'new@acme.test', 'password' => 'secret123', 'role' => 'member'], 8],
    'customers: tenant, unique email, subscription, plan, features, locked stats, insert, increment, monthly growth' => ['customers', 'customers_count', ['name' => 'New', 'email' => 'new@acme.test'], 9],
]);

it('keeps the stored count exact at the limit: the refused create neither counts nor queues a refresh', function (string $table, string $feature, array $payload, Closure $fill) {
    $fill($this->acme, 2);
    $column = $feature === 'max_users' ? 'users_count' : 'customers_count';

    $this->postJson("api/v1/{$table}", $payload)->assertCreated();
    Queue::fake();
    $this->postJson("api/v1/{$table}", [...$payload, 'email' => 'next@acme.test'])->assertTooManyRequests();

    expect(DB::table('tenant_stats')->where('tenant_id', $this->acme->id)->value($column))->toBe(3)
        ->and(DB::table($table)->where('tenant_id', $this->acme->id)->count())->toBe(3);
    Queue::assertNotPushed(RefreshPlatformStats::class);
})->with('limited resources');
