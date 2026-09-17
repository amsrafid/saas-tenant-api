<?php

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->acme = Tenant::factory()->create(['name' => 'Acme Corporation', 'slug' => 'acme', 'timezone' => 'Asia/Dhaka']);
    $this->globex = Tenant::factory()->create(['name' => 'Globex Corporation', 'slug' => 'globex']);
    Subscription::factory()->for($this->acme)->for(Plan::factory()->withLimits())->create();
});

function actAsTenantUser(Tenant $tenant, string $role): User
{
    $user = User::factory()->for($tenant)->create(['role' => $role]);
    Sanctum::actingAs($user);

    return $user;
}

it('returns the current tenant to any member', function (string $role) {
    actAsTenantUser($this->acme, $role);

    $this->getJson('api/v1/tenant')
        ->assertOk()
        ->assertExactJson(['data' => [
            'id' => $this->acme->id,
            'name' => 'Acme Corporation',
            'slug' => 'acme',
            'status' => 'active',
            'timezone' => 'Asia/Dhaka',
        ]]);
})->with(['owner', 'admin', 'member']);

it('reads the tenant without a database query once the cache is warm', function () {
    actAsTenantUser($this->acme, 'member');
    $this->getJson('api/v1/tenant')->assertOk();
    DB::enableQueryLog();

    $this->getJson('api/v1/tenant')->assertOk();

    expect(DB::getQueryLog())->toBe([]);
});

it('lets an owner or admin rename the tenant and change its timezone', function (string $role) {
    actAsTenantUser($this->acme, $role);
    $this->getJson('api/v1/tenant')->assertOk();

    $this->patchJson('api/v1/tenant', ['name' => 'Acme Ltd', 'timezone' => 'Europe/London'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Acme Ltd')
        ->assertJsonPath('data.timezone', 'Europe/London')
        ->assertJsonPath('data.slug', 'acme');

    expect(Cache::has("tenant:{$this->acme->id}"))->toBeFalse();

    $this->getJson('api/v1/tenant')
        ->assertJsonPath('data.name', 'Acme Ltd')
        ->assertJsonPath('data.timezone', 'Europe/London');

    expect($this->acme->fresh()->only(['name', 'timezone']))->toBe(['name' => 'Acme Ltd', 'timezone' => 'Europe/London']);
})->with(['owner', 'admin']);

it('changes only the fields sent', function () {
    actAsTenantUser($this->acme, 'owner');

    $this->patchJson('api/v1/tenant', ['timezone' => 'UTC'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Acme Corporation')
        ->assertJsonPath('data.timezone', 'UTC');
});

it('rejects an invalid name or timezone', function (array $payload, string $field) {
    actAsTenantUser($this->acme, 'owner');

    $this->patchJson('api/v1/tenant', $payload)->assertUnprocessable()->assertJsonValidationErrors($field);

    expect($this->acme->fresh()->only(['name', 'timezone']))->toBe(['name' => 'Acme Corporation', 'timezone' => 'Asia/Dhaka']);
})->with([
    'unknown timezone' => [['timezone' => 'Mars/Olympus'], 'timezone'],
    'offset, not a zone' => [['timezone' => '+06:00'], 'timezone'],
    'empty name' => [['name' => ''], 'name'],
    'long name' => [['name' => str_repeat('a', 256)], 'name'],
]);

it('refuses to change the slug or the status', function (array $payload) {
    actAsTenantUser($this->acme, 'owner');

    $this->patchJson('api/v1/tenant', $payload)->assertUnprocessable();

    $tenant = $this->acme->fresh();

    expect($tenant->slug)->toBe('acme')
        ->and($tenant->status->value)->toBe('active')
        ->and($tenant->name)->toBe('Acme Corporation');
})->with([
    'slug' => [['slug' => 'acme-ltd']],
    'status' => [['status' => 'suspended']],
    'an id' => [['id' => 999, 'name' => 'Hijacked']],
]);

it('forbids a member from updating the tenant', function () {
    actAsTenantUser($this->acme, 'member');

    $this->patchJson('api/v1/tenant', ['name' => 'Renamed'])->assertForbidden();

    expect($this->acme->fresh()->name)->toBe('Acme Corporation');
});

it('forbids a platform admin, who has no tenant', function (string $method) {
    Sanctum::actingAs(User::factory()->platformAdmin()->create());

    $this->json($method, 'api/v1/tenant', ['name' => 'Renamed'])->assertForbidden();
})->with(['GET', 'PATCH']);

it('requires authentication', function (string $method) {
    $this->json($method, 'api/v1/tenant', ['name' => 'Renamed'])->assertUnauthorized();
})->with(['GET', 'PATCH']);

it('only ever addresses the caller\'s own tenant', function () {
    actAsTenantUser($this->globex, 'owner');

    $this->getJson('api/v1/tenant')->assertJsonPath('data.id', $this->globex->id);
    $this->patchJson('api/v1/tenant', ['name' => 'Globex Ltd'])->assertOk();

    expect($this->acme->fresh()->name)->toBe('Acme Corporation')
        ->and($this->globex->fresh()->name)->toBe('Globex Ltd');
});
