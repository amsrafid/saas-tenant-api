<?php

use App\Enums\Ability;
use App\Enums\TenantRole;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    [$this->acme, $this->globex] = Tenant::factory()->count(2)->create();
    Subscription::factory()->for($this->acme)->for(Plan::factory()->withLimits())->create();
    $this->acmeCustomer = Customer::factory()->for($this->acme)->create(['name' => 'Acme Customer']);
    $this->globexCustomer = Customer::factory()->for($this->globex)->create(['name' => 'Globex Customer', 'email' => 'john@globex.test']);
});

function actAs(Tenant $tenant, string $role): User
{
    $user = User::factory()->for($tenant)->create(['role' => $role]);
    Sanctum::actingAs($user);

    return $user;
}

it('grants each role exactly the documented customer abilities', function (string $role, string $method, string $path, array $payload, int $status) {
    actAs($this->acme, $role);
    $uri = 'api/v1/customers'.str_replace('{id}', (string) $this->acmeCustomer->id, $path);

    $this->json($method, $uri, $payload)->assertStatus($status);
})->with([
    ['owner', 'GET', '', [], 200],
    ['owner', 'POST', '', ['name' => 'N', 'email' => 'n@acme.test'], 201],
    ['owner', 'GET', '/{id}', [], 200],
    ['owner', 'PATCH', '/{id}', ['name' => 'Renamed'], 200],
    ['owner', 'DELETE', '/{id}', [], 204],
    ['admin', 'GET', '', [], 200],
    ['admin', 'POST', '', ['name' => 'N', 'email' => 'n@acme.test'], 201],
    ['admin', 'GET', '/{id}', [], 200],
    ['admin', 'PATCH', '/{id}', ['name' => 'Renamed'], 200],
    ['admin', 'DELETE', '/{id}', [], 204],
    ['member', 'GET', '', [], 200],
    ['member', 'POST', '', ['name' => 'N', 'email' => 'n@acme.test'], 201],
    ['member', 'GET', '/{id}', [], 200],
    ['member', 'PATCH', '/{id}', ['name' => 'Renamed'], 200],
    ['member', 'DELETE', '/{id}', [], 403],
]);

it('authorizes before validating the body', function () {
    actAs($this->acme, 'owner');
    Gate::before(fn () => false);

    $this->postJson('api/v1/customers', ['tenant_id' => $this->globex->id])
        ->assertForbidden();
});

it('answers another tenant\'s customer with a 404 and leaves it untouched', function (string $method, array $payload) {
    actAs($this->acme, 'owner');
    $before = $this->globexCustomer->fresh()->getAttributes();

    $this->json($method, 'api/v1/customers/'.$this->globexCustomer->id, $payload)->assertNotFound();

    expect(Customer::withoutGlobalScope(TenantScope::class)->find($this->globexCustomer->id)->getAttributes())->toBe($before);
})->with([
    'read' => ['GET', []],
    'update' => ['PATCH', ['name' => 'Hijacked', 'email' => 'john@globex.test']],
    'delete' => ['DELETE', []],
]);

it('never lists another tenant\'s customers', function () {
    actAs($this->acme, 'member');

    $this->getJson('api/v1/customers')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $this->acmeCustomer->id);
});

it('refuses a platform admin, who belongs to no tenant', function (string $method, string $path) {
    Sanctum::actingAs(User::factory()->platformAdmin()->create());

    $this->json($method, 'api/v1/customers'.str_replace('{id}', (string) $this->acmeCustomer->id, $path))
        ->assertForbidden()
        ->assertJsonPath('message', 'This endpoint requires a tenant account.');
})->with([
    ['GET', ''],
    ['POST', ''],
    ['GET', '/{id}'],
    ['PATCH', '/{id}'],
    ['DELETE', '/{id}'],
]);

it('rejects an unauthenticated request', function () {
    $this->getJson('api/v1/customers')->assertUnauthorized();
});

it('never lets a platform admin through a tenant ability gate, even past the tenant middleware', function (Ability $ability) {
    expect(Gate::forUser(User::factory()->platformAdmin()->create())->allows($ability->value))->toBeFalse();
})->with(array_filter(Ability::cases(), fn (Ability $ability) => ! in_array(TenantRole::PlatformAdmin, $ability->roles(), true)));
