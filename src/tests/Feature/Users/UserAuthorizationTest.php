<?php

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    [$this->acme, $this->globex] = Tenant::factory()->count(2)->create();
    Subscription::factory()->for($this->acme)->for(Plan::factory()->withLimits())->create();
    $this->acmeOwner = User::factory()->for($this->acme)->owner()->create();
    $this->acmeMember = User::factory()->for($this->acme)->create();
    $this->globexUser = User::factory()->for($this->globex)->create();
});

function actAsRole(Tenant $tenant, string $role): User
{
    $user = User::factory()->for($tenant)->create(['role' => $role]);
    Sanctum::actingAs($user);

    return $user;
}

it('grants each role exactly the documented user abilities', function (string $role, string $method, string $path, array $payload, int $status) {
    actAsRole($this->acme, $role);
    $uri = 'api/v1/users'.str_replace('{id}', (string) $this->acmeMember->id, $path);

    $this->json($method, $uri, $payload)->assertStatus($status);
})->with([
    ['owner', 'DELETE', '/{id}', [], 204],
    ['admin', 'POST', '', ['name' => 'N', 'email' => 'n@acme.test', 'password' => 'secret123', 'role' => 'member'], 201],
    ['admin', 'PATCH', '/{id}', ['name' => 'Renamed'], 200],
    ['admin', 'DELETE', '/{id}', [], 403],
    ['member', 'GET', '', [], 200],
    ['member', 'GET', '/{id}', [], 200],
    ['member', 'POST', '', ['name' => 'N', 'email' => 'n@acme.test', 'password' => 'secret123', 'role' => 'member'], 403],
    ['member', 'PATCH', '/{id}', ['name' => 'Renamed'], 403],
    ['member', 'DELETE', '/{id}', [], 403],
]);

it('answers another tenant\'s user with a 404 and leaves it untouched', function (string $method, array $payload) {
    actAsRole($this->acme, 'owner');
    $before = $this->globexUser->fresh()->getAttributes();

    $this->json($method, 'api/v1/users/'.$this->globexUser->id, $payload)->assertNotFound();

    expect(User::withoutGlobalScope(TenantScope::class)->find($this->globexUser->id)->getAttributes())->toBe($before);
})->with([
    'read' => ['GET', []],
    'update' => ['PATCH', ['name' => 'Hijacked', 'status' => 'disabled']],
    'delete' => ['DELETE', []],
]);

it('refuses an admin granting a role above their own', function (string $method, string $path, array $payload) {
    actAsRole($this->acme, 'admin');

    $this->json($method, 'api/v1/users'.str_replace('{id}', (string) $this->acmeMember->id, $path), $payload)
        ->assertForbidden();

    expect($this->acmeMember->fresh()->role->value)->toBe('member');
})->with([
    'create' => ['POST', '', ['name' => 'N', 'email' => 'n@acme.test', 'password' => 'secret123', 'role' => 'owner']],
    'update' => ['PATCH', '/{id}', ['role' => 'owner']],
]);

it('refuses a member granting themselves or a new user owner', function (string $method, string $path, array $payload) {
    $member = actAsRole($this->acme, 'member');

    $this->json($method, 'api/v1/users'.str_replace('{id}', (string) $member->id, $path), $payload)->assertForbidden();

    expect($member->fresh()->role->value)->toBe('member')
        ->and(User::withoutGlobalScope(TenantScope::class)->where('role', 'owner')->count())->toBe(1);
})->with([
    'self' => ['PATCH', '/{id}', ['role' => 'owner']],
    'create' => ['POST', '', ['name' => 'N', 'email' => 'n@acme.test', 'password' => 'secret123', 'role' => 'owner']],
]);

it('refuses an admin editing the owner', function () {
    actAsRole($this->acme, 'admin');

    $this->patchJson('api/v1/users/'.$this->acmeOwner->id, ['role' => 'member'])->assertForbidden();

    expect($this->acmeOwner->fresh()->role->value)->toBe('owner');
});

it('refuses changing your own role', function () {
    $admin = actAsRole($this->acme, 'admin');

    $this->patchJson('api/v1/users/'.$admin->id, ['role' => 'member'])->assertForbidden();
});

it('keeps the last active owner', function (string $method, array $payload) {
    Sanctum::actingAs($this->acmeOwner);

    $this->json($method, 'api/v1/users/'.$this->acmeOwner->id, $payload)->assertConflict();

    expect($this->acmeOwner->fresh())->role->value->toBe('owner')->status->value->toBe('active');
})->with([
    'delete' => ['DELETE', []],
    'demote' => ['PATCH', ['role' => 'admin']],
    'disable' => ['PATCH', ['status' => 'disabled']],
]);

it('lets an owner go while another active owner remains', function (string $method, array $payload, int $status) {
    Sanctum::actingAs(User::factory()->for($this->acme)->owner()->create());

    $this->json($method, 'api/v1/users/'.$this->acmeOwner->id, $payload)->assertStatus($status);
})->with([
    'delete' => ['DELETE', [], 204],
    'demote' => ['PATCH', ['role' => 'admin'], 200],
    'disable' => ['PATCH', ['status' => 'disabled'], 200],
]);
