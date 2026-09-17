<?php

use App\Models\Plan;
use App\Models\PlanFeature;
use App\Models\Subscription;
use App\Models\User;
use App\Tenancy\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->free = planWithFeatures(['name' => 'Free', 'slug' => 'free', 'price_cents' => 0, 'sort_order' => 1], [3, null]);
    $this->pro = planWithFeatures(['name' => 'Pro', 'slug' => 'pro', 'price_cents' => 2900, 'sort_order' => 2], [10, 1000]);
});

/**
 * @param  array<string, mixed>  $attributes
 * @param  array{int|null, int|null}  $limits
 */
function planWithFeatures(array $attributes, array $limits): Plan
{
    $plan = Plan::factory()->create($attributes);

    foreach (['max_users', 'max_customers'] as $index => $key) {
        $plan->features()->create(['key' => $key, 'limit_value' => $limits[$index]]);
    }

    return $plan;
}

function planJson(Plan $plan): array
{
    return [
        'id' => $plan->id,
        'name' => $plan->name,
        'slug' => $plan->slug,
        'price_cents' => $plan->price_cents,
        'currency' => 'USD',
        'billing_period' => 'monthly',
        'is_active' => $plan->is_active,
        'features' => $plan->features()->pluck('limit_value', 'key')->all(),
    ];
}

function actAsPlatformAdmin(): void
{
    Sanctum::actingAs(User::factory()->platformAdmin()->create());
}

/**
 * @return array<string, mixed>
 */
function newPlanPayload(): array
{
    return [
        'name' => 'Team',
        'slug' => 'team',
        'price_cents' => 4900,
        'currency' => 'EUR',
        'billing_period' => 'yearly',
        'is_active' => true,
        'sort_order' => 5,
        'features' => ['max_users' => 25, 'max_customers' => 5000],
    ];
}

it('lists active plans in display order with their features, without a token', function () {
    Plan::factory()->create(['is_active' => false]);

    $this->getJson('api/v1/plans')
        ->assertOk()
        ->assertJsonPath('data', [planJson($this->free), planJson($this->pro)])
        ->assertJsonPath('data.0.features.max_customers', null)
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('meta.per_page', 15)
        ->assertJsonPath('links.first', url('api/v1/plans?page=1'));
});

it('pages the active plans', function () {
    $this->getJson('api/v1/plans?per_page=1&page=2')
        ->assertOk()
        ->assertJsonPath('data', [planJson($this->pro)])
        ->assertJsonPath('meta.current_page', 2)
        ->assertJsonPath('meta.last_page', 2)
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('links.prev', url('api/v1/plans?per_page=1&page=1'));
});

it('refuses an invalid page or page size', function (string $query, string $error) {
    $this->getJson('api/v1/plans?'.$query)->assertUnprocessable()->assertOnlyJsonValidationErrors([$error]);
})->with([
    ['per_page=101', 'per_page'],
    ['per_page=0', 'per_page'],
    ['page=0', 'page'],
]);

it('shows a plan by slug, an inactive one included', function () {
    $this->pro->update(['is_active' => false]);

    $this->getJson('api/v1/plans/pro')
        ->assertOk()
        ->assertExactJson(['data' => [...planJson($this->pro->fresh()), 'is_active' => false]]);
});

it('answers an unknown slug with a 404', function (string $slug) {
    $this->getJson('api/v1/plans/'.$slug)->assertNotFound();
})->with(['missing', 'Not_A_Slug']);

it('serves the list and a plan from cache on the next request', function () {
    $this->getJson('api/v1/plans')->assertOk();
    $this->getJson('api/v1/plans/pro')->assertOk();
    DB::enableQueryLog();

    $this->getJson('api/v1/plans?page=1')->assertOk()->assertJsonPath('meta.total', 2);
    $this->getJson('api/v1/plans/pro')->assertOk()->assertJsonPath('data.features.max_users', 10);

    expect(DB::getQueryLog())->toBeEmpty();
});

it('reads the cached plans back through a serializing store', function () {
    config(['cache.stores.array.serialize' => true]);
    Cache::forgetDriver('array');

    $this->getJson('api/v1/plans')->assertOk();
    $this->getJson('api/v1/plans/pro')->assertOk();

    $this->getJson('api/v1/plans')->assertOk()->assertJsonPath('data.1.features.max_users', 10);
    $this->getJson('api/v1/plans/pro')->assertOk()->assertJsonPath('data.features.max_customers', 1000);
});

it('creates a plan with its features and answers 201', function () {
    actAsPlatformAdmin();

    $response = $this->postJson('api/v1/admin/plans', newPlanPayload())->assertCreated();

    $plan = Plan::firstWhere('slug', 'team');

    $response->assertExactJson(['data' => ['id' => $plan->id, ...Arr::except(newPlanPayload(), 'sort_order')]]);

    expect($plan->sort_order)->toBe(5)
        ->and($plan->features()->count())->toBe(2);
});

it('creates an active plan placed first when is_active and sort_order are not sent', function () {
    actAsPlatformAdmin();
    $payload = newPlanPayload();
    unset($payload['is_active'], $payload['sort_order']);

    $this->postJson('api/v1/admin/plans', $payload)
        ->assertCreated()
        ->assertJsonPath('data.is_active', true);

    expect(Plan::firstWhere('slug', 'team')->sort_order)->toBe(0);
});

it('refuses invalid input on create', function (array $overrides, array $errors) {
    actAsPlatformAdmin();

    $this->postJson('api/v1/admin/plans', [...newPlanPayload(), ...$overrides])
        ->assertUnprocessable()
        ->assertOnlyJsonValidationErrors($errors);

    expect(Plan::where('slug', 'team')->exists())->toBeFalse();
})->with([
    'taken slug' => [['slug' => 'pro'], ['slug']],
    'slug with capitals' => [['slug' => 'Team'], ['slug']],
    'negative price' => [['price_cents' => -1], ['price_cents']],
    'price above integer range' => [['price_cents' => 2147483648], ['price_cents']],
    'decimal price' => [['price_cents' => 49.5], ['price_cents']],
    'lower-case currency' => [['currency' => 'eur'], ['currency']],
    'unknown billing period' => [['billing_period' => 'weekly'], ['billing_period']],
    'null is_active' => [['is_active' => null], ['is_active']],
    'null features' => [['features' => null], ['features', 'features.max_users', 'features.max_customers']],
    'a feature left out' => [['features' => ['max_users' => 1]], ['features.max_customers']],
    'unknown feature' => [['features' => [...newPlanPayload()['features'], 'seats' => 1]], ['features', 'features.seats']],
    'negative limit' => [['features' => [...newPlanPayload()['features'], 'max_users' => -1]], ['features.max_users']],
    'unknown field' => [['trial_days' => 14], ['trial_days']],
]);

it('refuses missing required fields on create', function () {
    actAsPlatformAdmin();

    $this->postJson('api/v1/admin/plans', [])
        ->assertUnprocessable()
        ->assertOnlyJsonValidationErrors([
            'name', 'slug', 'price_cents', 'currency', 'billing_period', 'features',
            'features.max_users', 'features.max_customers',
        ]);
});

it('updates only the fields and feature limits sent', function () {
    actAsPlatformAdmin();

    $this->patchJson('api/v1/admin/plans/'.$this->pro->id, ['price_cents' => 3900, 'features' => ['max_users' => null]])
        ->assertOk()
        ->assertExactJson(['data' => [
            ...planJson($this->pro),
            'price_cents' => 3900,
            'features' => ['max_users' => null, 'max_customers' => 1000],
        ]]);

    expect($this->pro->fresh())
        ->name->toBe('Pro')
        ->sort_order->toBe(2)
        ->and(PlanFeature::where('plan_id', $this->pro->id)->count())->toBe(2);
});

it('refuses invalid input on update, the slug included', function (array $payload, array $errors) {
    actAsPlatformAdmin();

    $this->patchJson('api/v1/admin/plans/'.$this->pro->id, $payload)
        ->assertUnprocessable()
        ->assertOnlyJsonValidationErrors($errors);
})->with([
    'slug' => [['slug' => 'pro-plus'], ['slug']],
    'null name' => [['name' => null], ['name']],
    'null features' => [['features' => null], ['features']],
    'unknown feature' => [['features' => ['seats' => 1]], ['features', 'features.seats']],
    'string limit' => [['features' => ['max_users' => 'many']], ['features.max_users']],
]);

it('drops the cached list and plan when a plan is updated', function () {
    actAsPlatformAdmin();
    $this->getJson('api/v1/plans')->assertOk();
    $this->getJson('api/v1/plans/pro')->assertOk();

    $this->patchJson('api/v1/admin/plans/'.$this->pro->id, ['name' => 'Pro Plus', 'features' => ['max_users' => 20]])->assertOk();

    $this->getJson('api/v1/plans')->assertOk()->assertJsonPath('data.1.name', 'Pro Plus');
    $this->getJson('api/v1/plans/pro')->assertOk()->assertJsonPath('data.features.max_users', 20);
});

it('removes a deactivated plan from the list but still shows it', function () {
    actAsPlatformAdmin();
    $this->getJson('api/v1/plans')->assertJsonPath('meta.total', 2);

    $this->patchJson('api/v1/admin/plans/'.$this->pro->id, ['is_active' => false])->assertOk();

    $this->getJson('api/v1/plans')->assertOk()->assertJsonPath('meta.total', 1);
    $this->getJson('api/v1/plans/pro')->assertOk()->assertJsonPath('data.is_active', false);
});

it('adds a created plan to the cached list', function () {
    actAsPlatformAdmin();
    $this->getJson('api/v1/plans')->assertJsonPath('meta.total', 2);

    $this->postJson('api/v1/admin/plans', newPlanPayload())->assertCreated();

    $this->getJson('api/v1/plans')->assertOk()->assertJsonPath('meta.total', 3);
});

it('deletes a plan and its features, dropping it from the cache', function () {
    actAsPlatformAdmin();
    $this->getJson('api/v1/plans')->assertOk();
    $this->getJson('api/v1/plans/pro')->assertOk();

    $this->deleteJson('api/v1/admin/plans/'.$this->pro->id)->assertNoContent();

    expect(Plan::whereKey($this->pro->id)->exists())->toBeFalse()
        ->and(PlanFeature::where('plan_id', $this->pro->id)->exists())->toBeFalse();

    $this->getJson('api/v1/plans/pro')->assertNotFound();
    $this->getJson('api/v1/plans')->assertOk()->assertJsonPath('meta.total', 1);
});

it('refuses to delete a plan any subscription refers to, an expired one included', function (string $status) {
    actAsPlatformAdmin();
    Subscription::factory()->create(['plan_id' => $this->pro->id, 'status' => $status]);

    $this->deleteJson('api/v1/admin/plans/'.$this->pro->id)
        ->assertConflict()
        ->assertJsonPath('message', 'A plan with subscriptions cannot be deleted; deactivate it instead.');

    expect(Plan::whereKey($this->pro->id)->exists())->toBeTrue()
        ->and(Subscription::withoutGlobalScope(TenantScope::class)->count())->toBe(1);
})->with(['active', 'expired']);

it('answers a missing plan id with a 404', function (string $method) {
    actAsPlatformAdmin();

    $this->json($method, 'api/v1/admin/plans/999999', ['name' => 'X'])->assertNotFound();
})->with(['PATCH', 'DELETE']);

it('holds an exact query count, with explicit columns, on every endpoint', function (string $method, Closure $uri, array $payload, int $queries) {
    Queue::fake();
    actAsPlatformAdmin();
    DB::enableQueryLog();

    $this->json($method, $uri($this->pro), $payload)->assertSuccessful();

    $log = array_column(DB::getQueryLog(), 'query');

    expect($log)->toHaveCount($queries)
        // An EXISTS subquery reads no columns, so its `select *` is not a wide read.
        ->and(implode("\n", $log))->not->toMatch('/(?<!exists\()select \*/');
})->with([
    'index, cold cache: plans, features' => ['GET', fn () => 'api/v1/plans', [], 2],
    'show, cold cache: plan, features' => ['GET', fn () => 'api/v1/plans/pro', [], 2],
    'store: unique check, insert, features upsert, features' => ['POST', fn () => 'api/v1/admin/plans', newPlanPayload(), 4],
    'update: find, update, features upsert, features' => ['PATCH', fn (Plan $p) => 'api/v1/admin/plans/'.$p->id, ['name' => 'N', 'features' => ['max_users' => 1]], 4],
    'delete: find, subscription check, delete' => ['DELETE', fn (Plan $p) => 'api/v1/admin/plans/'.$p->id, [], 3],
]);
