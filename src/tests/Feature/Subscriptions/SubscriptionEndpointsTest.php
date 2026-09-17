<?php

use App\Models\Customer;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Repositories\PlanRepository;
use App\Services\SubscriptionService;
use App\Tenancy\TenantScope;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-09-16 10:00:00');

    $this->free = subscribablePlan('free', [2, 3]);
    $this->pro = subscribablePlan('pro', [10, 1000]);
    $this->enterprise = subscribablePlan('enterprise', [null, null]);

    [$this->acme, $this->globex] = Tenant::factory()->count(2)->create();
    $this->owner = User::factory()->for($this->acme)->owner()->create();
    $this->subscription = Subscription::factory()->for($this->acme)->create(['plan_id' => $this->pro->id]);
    Sanctum::actingAs($this->owner);
});

/**
 * @param  array{int|null, int|null}  $limits
 */
function subscribablePlan(string $slug, array $limits): Plan
{
    $plan = Plan::factory()->create(['name' => ucfirst($slug), 'slug' => $slug]);

    foreach (['max_users', 'max_customers'] as $index => $key) {
        $plan->features()->create(['key' => $key, 'limit_value' => $limits[$index]]);
    }

    return $plan;
}

function liveSubscription(Tenant $tenant): ?Subscription
{
    return Subscription::withoutGlobalScope(TenantScope::class)
        ->where('tenant_id', $tenant->id)
        ->where('status', 'active')
        ->first();
}

it('shows the live subscription with its plan and limits', function () {
    $this->getJson('api/v1/subscription')
        ->assertOk()
        ->assertExactJson(['data' => [
            'id' => $this->subscription->id,
            'status' => 'active',
            'starts_at' => '2026-09-16T10:00:00.000000Z',
            'ends_at' => null,
            'canceled_at' => null,
            'plan' => [
                'id' => $this->pro->id,
                'name' => 'Pro',
                'slug' => 'pro',
                'price_cents' => $this->pro->price_cents,
                'currency' => 'USD',
                'billing_period' => 'monthly',
                'is_active' => true,
                'features' => ['max_users' => 10, 'max_customers' => 1000],
            ],
        ]]);
});

it('answers 404 when the tenant has no live subscription', function (string $method, string $uri, array $payload) {
    $this->subscription->forceFill(['status' => 'expired'])->save();

    $this->json($method, $uri, $payload)->assertNotFound();
})->with([
    ['GET', 'api/v1/subscription', []],
    ['GET', 'api/v1/subscription/usage', []],
    ['PATCH', 'api/v1/subscription', ['plan' => 'enterprise']],
    ['DELETE', 'api/v1/subscription', []],
]);

it('reports usage per feature against the plan\'s limits', function () {
    User::factory()->for($this->acme)->count(2)->create();
    Customer::factory()->for($this->acme)->count(4)->create();
    User::factory()->for($this->globex)->count(5)->create();
    Customer::factory()->for($this->globex)->count(5)->create();

    $this->getJson('api/v1/subscription/usage')
        ->assertOk()
        ->assertExactJson(['data' => [
            'max_users' => ['used' => 3, 'limit' => 10, 'remaining' => 7],
            'max_customers' => ['used' => 4, 'limit' => 1000, 'remaining' => 996],
        ]]);
});

it('reports a null limit and remaining for an unlimited feature, and never a negative remaining', function () {
    $this->subscription->update(['plan_id' => $this->enterprise->id]);
    Customer::factory()->for($this->acme)->count(3)->create();
    // Lowered below current usage behind the plan cache's back, as a direct database edit would leave it.
    $this->enterprise->features()->where('key', 'max_customers')->update(['limit_value' => 2]);
    app(PlanRepository::class)->forget('enterprise');

    $this->getJson('api/v1/subscription/usage')
        ->assertOk()
        ->assertJsonPath('data.max_users', ['used' => 1, 'limit' => null, 'remaining' => null])
        ->assertJsonPath('data.max_customers', ['used' => 3, 'limit' => 2, 'remaining' => 0]);
});

it('subscribes a tenant with no live subscription, running with no end, and answers 201', function () {
    $this->subscription->forceFill(['status' => 'expired'])->save();
    Carbon::setTestNow('2026-09-17 10:00:00');

    $this->postJson('api/v1/subscription', ['plan' => 'enterprise'])
        ->assertCreated()
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.starts_at', '2026-09-17T10:00:00.000000Z')
        ->assertJsonPath('data.ends_at', null)
        ->assertJsonPath('data.plan.slug', 'enterprise')
        ->assertJsonPath('data.plan.features.max_users', null);

    expect(liveSubscription($this->acme)->plan_id)->toBe($this->enterprise->id);
});

it('refuses to subscribe while a subscription is live', function () {
    $this->postJson('api/v1/subscription', ['plan' => 'enterprise'])
        ->assertConflict()
        ->assertJsonPath('message', 'The tenant already has an active subscription; change its plan instead.');

    expect(Subscription::withoutGlobalScope(TenantScope::class)->count())->toBe(1);
});

it('keeps exactly one live subscription when two subscribes race past the no-live-subscription check', function () {
    $subscriptions = app(SubscriptionService::class);
    $subscriptions->start($this->globex, $this->pro);

    // The savepoint keeps the test's own transaction usable after the violation, as the second request's rollback would.
    expect(fn () => DB::transaction(fn () => $subscriptions->start($this->globex, $this->enterprise)))
        ->toThrow(UniqueConstraintViolationException::class);

    expect(liveSubscription($this->globex)->plan_id)->toBe($this->pro->id)
        ->and(Subscription::withoutGlobalScope(TenantScope::class)->where('tenant_id', $this->globex->id)->count())->toBe(1);
});

it('refuses an unknown or inactive plan, or a malformed body', function (string $method, array $payload, string $error) {
    $this->subscription->forceFill(['status' => $method === 'POST' ? 'expired' : 'active'])->save();
    $this->enterprise->update(['is_active' => false]);

    $this->json($method, 'api/v1/subscription', $payload)
        ->assertUnprocessable()
        ->assertOnlyJsonValidationErrors([$error]);

    expect(Subscription::withoutGlobalScope(TenantScope::class)->count())->toBe(1);
})->with([
    'subscribe, unknown plan' => ['POST', ['plan' => 'missing'], 'plan'],
    'subscribe, inactive plan' => ['POST', ['plan' => 'enterprise'], 'plan'],
    'change, unknown plan' => ['PATCH', ['plan' => 'missing'], 'plan'],
    'change, inactive plan' => ['PATCH', ['plan' => 'enterprise'], 'plan'],
    'no plan' => ['PATCH', [], 'plan'],
    'unknown field' => ['PATCH', ['plan' => 'free', 'tenant_id' => 2], 'tenant_id'],
]);

it('changes plan by ending the current subscription now and starting a new one', function () {
    Carbon::setTestNow('2026-09-20 08:00:00');

    $response = $this->patchJson('api/v1/subscription', ['plan' => 'enterprise'])
        ->assertOk()
        ->assertJsonPath('data.plan.slug', 'enterprise')
        ->assertJsonPath('data.starts_at', '2026-09-20T08:00:00.000000Z')
        ->assertJsonPath('data.ends_at', null)
        ->assertJsonPath('data.canceled_at', null);

    $old = $this->subscription->fresh();

    expect($old->status->value)->toBe('canceled')
        ->and($old->plan_id)->toBe($this->pro->id)
        ->and($old->starts_at->toIso8601String())->toBe('2026-09-16T10:00:00+00:00')
        ->and($old->ends_at->toIso8601String())->toBe('2026-09-20T08:00:00+00:00')
        ->and($old->canceled_at->toIso8601String())->toBe('2026-09-20T08:00:00+00:00')
        ->and(liveSubscription($this->acme)->id)->toBe($response->json('data.id'))
        ->and(Subscription::withoutGlobalScope(TenantScope::class)->count())->toBe(2);
});

it('refuses a change to the plan already subscribed to', function () {
    $this->patchJson('api/v1/subscription', ['plan' => 'pro'])
        ->assertConflict()
        ->assertJsonPath('message', 'The tenant is already subscribed to this plan.');
});

it('refuses a downgrade below current usage, naming each feature over the limit', function () {
    User::factory()->for($this->acme)->count(2)->create();
    Customer::factory()->for($this->acme)->count(4)->create();

    $this->patchJson('api/v1/subscription', ['plan' => 'free'])
        ->assertUnprocessable()
        ->assertExactJson([
            'message' => 'Current max_users usage (3) exceeds the Free plan\'s limit of 2. (and 1 more error)',
            'errors' => ['plan' => [
                'Current max_users usage (3) exceeds the Free plan\'s limit of 2.',
                'Current max_customers usage (4) exceeds the Free plan\'s limit of 3.',
            ]],
        ]);

    expect(liveSubscription($this->acme)->id)->toBe($this->subscription->id)
        ->and(Subscription::withoutGlobalScope(TenantScope::class)->count())->toBe(1);
});

it('allows a downgrade when usage is exactly at the new limits, ignoring other tenants', function () {
    User::factory()->for($this->acme)->create();
    Customer::factory()->for($this->acme)->count(3)->create();
    User::factory()->for($this->globex)->count(5)->create();

    $this->patchJson('api/v1/subscription', ['plan' => 'free'])->assertOk()->assertJsonPath('data.plan.slug', 'free');
});

it('refuses to subscribe to a plan below current usage', function () {
    $this->subscription->forceFill(['status' => 'expired'])->save();
    Customer::factory()->for($this->acme)->count(4)->create();

    $this->postJson('api/v1/subscription', ['plan' => 'free'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.plan', ['Current max_customers usage (4) exceeds the Free plan\'s limit of 3.']);

    expect(liveSubscription($this->acme))->toBeNull();
});

it('cancels the subscription to the end of its current billing period, keeping it live, and refuses a second cancel', function (string $billingPeriod, string $startsAt, string $endsAt) {
    $this->pro->update(['billing_period' => $billingPeriod]);
    $this->subscription->update(['starts_at' => $startsAt]);
    Carbon::setTestNow('2026-09-18 12:00:00');

    $this->deleteJson('api/v1/subscription')
        ->assertOk()
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.canceled_at', '2026-09-18T12:00:00.000000Z')
        ->assertJsonPath('data.ends_at', $endsAt)
        ->assertJsonPath('data.plan.slug', 'pro');

    $this->getJson('api/v1/subscription')->assertOk()->assertJsonPath('data.ends_at', $endsAt);

    $this->deleteJson('api/v1/subscription')
        ->assertConflict()
        ->assertJsonPath('message', 'The subscription is already canceled.');

    expect(liveSubscription($this->acme))
        ->id->toBe($this->subscription->id)
        ->plan_id->toBe($this->pro->id)
        ->starts_at->toDateTimeString()->toBe($startsAt)
        ->canceled_at->toIso8601String()->toBe('2026-09-18T12:00:00+00:00');
})->with([
    'monthly, started months ago' => ['monthly', '2026-06-16 10:00:00', '2026-10-16T10:00:00.000000Z'],
    'monthly, a boundary exactly now runs to the next one' => ['monthly', '2026-08-18 12:00:00', '2026-10-18T12:00:00.000000Z'],
    'monthly, month end clamps without drifting' => ['monthly', '2026-01-31 10:00:00', '2026-09-30T10:00:00.000000Z'],
    'yearly, started years ago' => ['yearly', '2023-03-01 10:00:00', '2027-03-01T10:00:00.000000Z'],
]);

it('lets a canceled subscription change plan, starting a fresh one', function () {
    $this->deleteJson('api/v1/subscription')->assertOk();

    $this->patchJson('api/v1/subscription', ['plan' => 'enterprise'])
        ->assertOk()
        ->assertJsonPath('data.canceled_at', null);
});

it('serves the subscription from cache on the next request', function () {
    $this->getJson('api/v1/subscription')->assertOk();
    DB::enableQueryLog();

    $this->getJson('api/v1/subscription')->assertOk()->assertJsonPath('data.plan.slug', 'pro');

    expect(DB::getQueryLog())->toBeEmpty();
});

it('drops the cached subscription on every write', function (string $method, array $payload, string $path, mixed $expected) {
    $this->getJson('api/v1/subscription')->assertOk();

    $this->json($method, 'api/v1/subscription', $payload)->assertSuccessful();

    $this->getJson('api/v1/subscription')->assertOk()->assertJsonPath($path, $expected);
})->with([
    'change plan' => ['PATCH', ['plan' => 'enterprise'], 'data.plan.slug', 'enterprise'],
    'cancel' => ['DELETE', [], 'data.canceled_at', '2026-09-16T10:00:00.000000Z'],
]);

it('drops a cached absence when the tenant subscribes', function () {
    $this->subscription->forceFill(['status' => 'expired'])->save();
    $this->getJson('api/v1/subscription')->assertNotFound();

    $this->postJson('api/v1/subscription', ['plan' => 'enterprise'])->assertCreated();

    $this->getJson('api/v1/subscription')->assertOk()->assertJsonPath('data.plan.slug', 'enterprise');
});

it('shows a plan limit edited by a platform admin without waiting for the cache to expire', function () {
    $this->getJson('api/v1/subscription')->assertJsonPath('data.plan.features.max_users', 10);

    Sanctum::actingAs(User::factory()->platformAdmin()->create());
    $this->patchJson('api/v1/admin/plans/'.$this->pro->id, ['features' => ['max_users' => 20]])->assertOk();

    Sanctum::actingAs($this->owner);
    $this->getJson('api/v1/subscription')->assertJsonPath('data.plan.features.max_users', 20);
    $this->getJson('api/v1/subscription/usage')->assertJsonPath('data.max_users.limit', 20);
});

it('reads the cached subscription back through a serializing store', function () {
    config(['cache.stores.array.serialize' => true]);
    Cache::forgetDriver('array');

    $this->getJson('api/v1/subscription')->assertOk();

    $this->getJson('api/v1/subscription')->assertOk()->assertJsonPath('data.status', 'active')->assertJsonPath('data.plan.slug', 'pro');
});

it('holds an exact query count, with explicit columns, on every endpoint', function (string $method, string $uri, array $payload, bool $expired, int $queries) {
    Queue::fake();
    $this->subscription->forceFill(['status' => $expired ? 'expired' : 'active'])->save();
    DB::enableQueryLog();

    $this->json($method, $uri, $payload)->assertSuccessful();

    $log = array_column(DB::getQueryLog(), 'query');

    expect($log)->toHaveCount($queries)
        ->and(implode("\n", $log))->not->toContain('select *');
})->with([
    'show, cold cache: tenant, subscription, plan, features' => ['GET', 'api/v1/subscription', [], false, 4],
    'usage, cold cache: tenant, subscription, plan, features, usage counts' => ['GET', 'api/v1/subscription/usage', [], false, 5],
    'subscribe: tenant, subscription, plan, features, usage counts, insert' => ['POST', 'api/v1/subscription', ['plan' => 'enterprise'], true, 6],
    'change: tenant, subscription, 2 plans, 2 features, usage counts, update, insert' => ['PATCH', 'api/v1/subscription', ['plan' => 'enterprise'], false, 9],
    'cancel: tenant, subscription, plan, features, update' => ['DELETE', 'api/v1/subscription', [], false, 5],
]);

it('documents the subscription endpoints', function () {
    $spec = $this->getJson('docs/api.json')->assertOk()->json();

    expect(array_keys($spec['paths']['/subscription']))->toBe(['get', 'post', 'patch', 'delete'])
        ->and(array_keys($spec['paths']['/subscription/usage']))->toBe(['get'])
        ->and(array_keys($spec['components']['schemas']['SelectPlanRequest']['properties']))->toBe(['plan']);
});
