<?php

use App\Models\Customer;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->pro = Plan::factory()->create(['slug' => 'pro']);
    $this->enterprise = Plan::factory()->create(['slug' => 'enterprise']);

    foreach ([$this->pro, $this->enterprise] as $plan) {
        foreach (['max_users', 'max_customers'] as $key) {
            $plan->features()->create(['key' => $key, 'limit_value' => null]);
        }
    }

    [$this->acme, $this->globex] = Tenant::factory()->count(2)->create();
    $this->acmeSubscription = Subscription::factory()->for($this->acme)->create(['plan_id' => $this->pro->id]);
    $this->globexSubscription = Subscription::factory()->for($this->globex)->create(['plan_id' => $this->enterprise->id]);
});

function actAsSubscriber(Tenant $tenant, string $role): User
{
    $user = User::factory()->for($tenant)->create(['role' => $role]);
    Sanctum::actingAs($user);

    return $user;
}

/**
 * @return array{string, string, array<string, mixed>}
 */
function subscriptionRequest(string $action): array
{
    return match ($action) {
        'show' => ['GET', 'api/v1/subscription', []],
        'usage' => ['GET', 'api/v1/subscription/usage', []],
        'subscribe' => ['POST', 'api/v1/subscription', ['plan' => 'enterprise']],
        'change' => ['PATCH', 'api/v1/subscription', ['plan' => 'enterprise']],
        'cancel' => ['DELETE', 'api/v1/subscription', []],
    };
}

it('grants each role exactly the documented subscription abilities', function (string $role, string $action, int $status) {
    actAsSubscriber($this->acme, $role);

    [$method, $uri, $payload] = subscriptionRequest($action);

    $this->json($method, $uri, $payload)->assertStatus($status);
})->with([
    ['owner', 'show', 200],
    ['owner', 'usage', 200],
    ['owner', 'change', 200],
    ['owner', 'cancel', 200],
    ['admin', 'show', 200],
    ['admin', 'usage', 200],
    ['admin', 'subscribe', 403],
    ['admin', 'change', 403],
    ['admin', 'cancel', 403],
    ['member', 'show', 200],
    ['member', 'usage', 200],
    ['member', 'subscribe', 403],
    ['member', 'change', 403],
    ['member', 'cancel', 403],
]);

it('lets an owner subscribe a tenant with no live subscription', function () {
    $this->acmeSubscription->forceFill(['status' => 'expired'])->save();
    actAsSubscriber($this->acme, 'owner');

    $this->postJson('api/v1/subscription', ['plan' => 'enterprise'])->assertCreated();
});

it('authorizes before validating the body', function () {
    actAsSubscriber($this->acme, 'admin');

    $this->patchJson('api/v1/subscription', [])->assertForbidden();
});

it('shows only the tenant\'s own subscription, also once another tenant\'s is cached', function () {
    actAsSubscriber($this->globex, 'member');
    $this->getJson('api/v1/subscription')->assertOk()->assertJsonPath('data.plan.slug', 'enterprise');

    actAsSubscriber($this->acme, 'member');
    $this->getJson('api/v1/subscription')
        ->assertOk()
        ->assertJsonPath('data.id', $this->acmeSubscription->id)
        ->assertJsonPath('data.plan.slug', 'pro');
});

it('changes and cancels only the tenant\'s own subscription', function (string $action) {
    actAsSubscriber($this->acme, 'owner');
    $before = $this->globexSubscription->fresh()->getAttributes();

    [$method, $uri, $payload] = subscriptionRequest($action);
    $this->json($method, $uri, $payload)->assertSuccessful();

    expect($this->globexSubscription->fresh()->getAttributes())->toBe($before)
        ->and(Subscription::withoutGlobalScope(TenantScope::class)->where('tenant_id', $this->globex->id)->count())->toBe(1);
})->with(['change', 'cancel']);

it('counts only the tenant\'s own users and customers as usage', function () {
    User::factory()->for($this->globex)->count(3)->create();
    Customer::factory()->for($this->globex)->count(3)->create();
    actAsSubscriber($this->acme, 'member');

    $this->getJson('api/v1/subscription/usage')
        ->assertOk()
        ->assertJsonPath('data.max_users.used', 1)
        ->assertJsonPath('data.max_customers.used', 0);
});

it('refuses a platform admin, who belongs to no tenant', function (string $action) {
    Sanctum::actingAs(User::factory()->platformAdmin()->create());

    [$method, $uri, $payload] = subscriptionRequest($action);

    $this->json($method, $uri, $payload)
        ->assertForbidden()
        ->assertJsonPath('message', 'This endpoint requires a tenant account.');
})->with(['show', 'usage', 'subscribe', 'change', 'cancel']);

it('rejects an unauthenticated request', function (string $action) {
    [$method, $uri, $payload] = subscriptionRequest($action);

    $this->json($method, $uri, $payload)->assertUnauthorized();
})->with(['show', 'usage', 'subscribe', 'change', 'cancel']);
