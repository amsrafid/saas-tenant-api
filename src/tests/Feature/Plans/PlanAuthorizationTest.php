<?php

use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->plan = Plan::factory()->create(['slug' => 'pro']);
});

/**
 * @return array{string, string, array<string, mixed>}
 */
function adminPlanRequest(string $action, Plan $plan): array
{
    return match ($action) {
        'store' => ['POST', 'api/v1/admin/plans', [
            'name' => 'Team',
            'slug' => 'team',
            'price_cents' => 4900,
            'currency' => 'USD',
            'billing_period' => 'monthly',
            'features' => ['max_users' => 1, 'max_customers' => 1],
        ]],
        'update' => ['PATCH', 'api/v1/admin/plans/'.$plan->id, ['name' => 'Renamed']],
        'destroy' => ['DELETE', 'api/v1/admin/plans/'.$plan->id, []],
    };
}

it('lets only a platform admin manage plans', function (string $action, int $status) {
    Sanctum::actingAs(User::factory()->platformAdmin()->create());

    [$method, $uri, $payload] = adminPlanRequest($action, $this->plan);

    $this->json($method, $uri, $payload)->assertStatus($status);
})->with([
    ['store', 201],
    ['update', 200],
    ['destroy', 204],
]);

it('refuses every tenant role, and leaves the plan untouched', function (string $role, string $action) {
    Sanctum::actingAs(User::factory()->for(Tenant::factory())->create(['role' => $role]));
    $before = $this->plan->fresh()->getAttributes();

    [$method, $uri, $payload] = adminPlanRequest($action, $this->plan);

    $this->json($method, $uri, $payload)->assertForbidden();

    expect($this->plan->fresh()->getAttributes())->toBe($before)
        ->and(Plan::count())->toBe(1);
})->with(['owner', 'admin', 'member'])->with(['store', 'update', 'destroy']);

it('authorizes before validating the body', function () {
    Sanctum::actingAs(User::factory()->for(Tenant::factory())->owner()->create());

    $this->postJson('api/v1/admin/plans', [])->assertForbidden();
});

it('rejects an unauthenticated plan write', function (string $action) {
    [$method, $uri, $payload] = adminPlanRequest($action, $this->plan);

    $this->json($method, $uri, $payload)->assertUnauthorized();
})->with(['store', 'update', 'destroy']);

it('serves the plan catalogue to anyone, without a token', function (string $uri) {
    $this->getJson($uri)->assertOk();
})->with(['api/v1/plans', 'api/v1/plans/pro']);
