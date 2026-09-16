<?php

use App\Enums\TenantStatus;
use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    Route::middleware(['api', 'auth:sanctum', 'tenant'])->prefix('api/v1/tenancy-probe')->group(function () {
        Route::get('context', fn (TenantContext $context) => ['tenant_id' => $context->id()]);
        Route::get('customers', fn () => ['emails' => Customer::orderBy('email')->limit(10)->pluck('email')]);
        Route::get('customers/{customer}', fn (Customer $customer) => ['id' => $customer->id]);
    });

    [$this->acme, $this->globex] = Tenant::factory()->count(2)->create();
    $this->acmeOwner = User::factory()->for($this->acme)->owner()->create();
    $this->globexOwner = User::factory()->for($this->globex)->owner()->create();
    $this->acmeCustomer = Customer::factory()->for($this->acme)->create(['email' => 'jane@acme.test']);
    $this->globexCustomer = Customer::factory()->for($this->globex)->create(['email' => 'john@globex.test']);
});

it('sets the tenant context from the authenticated user', function () {
    Sanctum::actingAs($this->acmeOwner);

    $this->getJson('/api/v1/tenancy-probe/context')->assertOk()->assertExactJson(['tenant_id' => $this->acme->id]);
    $this->getJson('/api/v1/tenancy-probe/customers')->assertOk()->assertExactJson(['emails' => ['jane@acme.test']]);
});

it('ignores a tenant id supplied in a header, the query string or the body', function () {
    Sanctum::actingAs($this->acmeOwner);

    $this->json('GET', '/api/v1/tenancy-probe/customers?tenant_id='.$this->globex->id, ['tenant_id' => $this->globex->id], ['X-Tenant-ID' => $this->globex->id])
        ->assertOk()
        ->assertExactJson(['emails' => ['jane@acme.test']]);
});

it('resolves the tenant from a real bearer token', function () {
    $token = $this->globexOwner->createToken('probe')->plainTextToken;

    $this->withToken($token)->getJson('/api/v1/tenancy-probe/context')
        ->assertOk()
        ->assertExactJson(['tenant_id' => $this->globex->id]);
});

it('costs one query to resolve the tenant', function () {
    Sanctum::actingAs($this->acmeOwner);
    // Creating the fixtures warmed the tenant cache; the count below is for a cold one.
    Cache::flush();
    DB::enableQueryLog();

    $this->getJson('/api/v1/tenancy-probe/context')->assertOk();

    expect(DB::getQueryLog())->toHaveCount(1)
        ->and(DB::getQueryLog()[0]['query'])->toStartWith('select "id", "name", "slug", "status"');
});

it('does not carry context from one request into the next', function () {
    Sanctum::actingAs($this->acmeOwner);
    $this->getJson('/api/v1/tenancy-probe/context')->assertExactJson(['tenant_id' => $this->acme->id]);

    expect(app(TenantContext::class)->has())->toBeFalse();

    Sanctum::actingAs($this->globexOwner);
    $this->getJson('/api/v1/tenancy-probe/context')->assertExactJson(['tenant_id' => $this->globex->id]);
});

it('binds a route model from the current tenant only', function () {
    Sanctum::actingAs($this->acmeOwner);

    $this->getJson('/api/v1/tenancy-probe/customers/'.$this->acmeCustomer->id)
        ->assertOk()
        ->assertExactJson(['id' => $this->acmeCustomer->id]);
});

it('answers a cross-tenant id with a 404', function () {
    Sanctum::actingAs($this->acmeOwner);

    $this->getJson('/api/v1/tenancy-probe/customers/'.$this->globexCustomer->id)->assertNotFound();
});

it('refuses a platform admin, who has no tenant', function () {
    Sanctum::actingAs(User::factory()->platformAdmin()->create());

    $this->getJson('/api/v1/tenancy-probe/context')
        ->assertForbidden()
        ->assertJsonPath('message', 'This endpoint requires a tenant account.');
});

it('refuses a user of a suspended tenant', function () {
    $this->acme->forceFill(['status' => TenantStatus::Suspended])->save();
    Sanctum::actingAs($this->acmeOwner);

    $this->getJson('/api/v1/tenancy-probe/context')
        ->assertForbidden()
        ->assertJsonPath('message', 'This tenant account is suspended.');
});

it('rejects an unauthenticated request before resolving a tenant', function () {
    $this->getJson('/api/v1/tenancy-probe/context')->assertUnauthorized();
});

it('reads the tenant from the database once, then from cache', function () {
    Sanctum::actingAs($this->acmeOwner);
    $this->getJson('/api/v1/tenancy-probe/context')->assertOk();
    DB::enableQueryLog();

    $this->getJson('/api/v1/tenancy-probe/context')->assertOk()->assertExactJson(['tenant_id' => $this->acme->id]);

    expect(DB::getQueryLog())->toBe([]);
});
