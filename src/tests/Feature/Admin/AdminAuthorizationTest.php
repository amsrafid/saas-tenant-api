<?php

use App\Enums\TenantStatus;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
});

/**
 * @return array{string, string, array<string, mixed>}
 */
function adminRequest(string $action, Tenant $tenant): array
{
    return match ($action) {
        'tenants' => ['GET', 'api/v1/admin/tenants', []],
        'update tenant' => ['PATCH', 'api/v1/admin/tenants/'.$tenant->id, ['status' => 'suspended']],
        'analytics' => ['GET', 'api/v1/admin/analytics', []],
    };
}

it('lets a platform admin call every admin endpoint', function (string $action) {
    Sanctum::actingAs(User::factory()->platformAdmin()->create());

    [$method, $uri, $payload] = adminRequest($action, $this->tenant);

    $this->json($method, $uri, $payload)->assertOk();
})->with(['tenants', 'update tenant', 'analytics']);

it('refuses every tenant role, and leaves the tenant untouched', function (string $role, string $action) {
    Sanctum::actingAs(User::factory()->for($this->tenant)->create(['role' => $role]));

    [$method, $uri, $payload] = adminRequest($action, $this->tenant);

    $this->json($method, $uri, $payload)->assertForbidden();

    expect($this->tenant->fresh()->status)->toBe(TenantStatus::Active);
})->with(['owner', 'admin', 'member'])->with(['tenants', 'update tenant', 'analytics']);

it('authorizes before validating', function () {
    Sanctum::actingAs(User::factory()->for($this->tenant)->owner()->create());

    $this->patchJson('api/v1/admin/tenants/'.$this->tenant->id, [])->assertForbidden();
    $this->getJson('api/v1/admin/tenants?per_page=1000')->assertForbidden();
});

it('rejects an unauthenticated admin request', function (string $action) {
    [$method, $uri, $payload] = adminRequest($action, $this->tenant);

    $this->json($method, $uri, $payload)->assertUnauthorized();
})->with(['tenants', 'update tenant', 'analytics']);
