<?php

use App\Models\Customer;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantScope;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    [$this->acme, $this->globex] = Tenant::factory()->count(2)->create();
    Subscription::factory()->for($this->acme)->for(Plan::factory()->withLimits())->create();
    Sanctum::actingAs(User::factory()->for($this->acme)->owner()->create());
});

function customerJson(Customer $customer): array
{
    return [
        'id' => $customer->id,
        'name' => $customer->name,
        'email' => $customer->email,
        'phone' => $customer->phone,
        'status' => $customer->status->value,
    ];
}

/**
 * @return list<string>
 */
function loggedQueries(): array
{
    return array_column(DB::getQueryLog(), 'query');
}

it('lists the tenant\'s customers with pagination meta and links', function () {
    $customer = Customer::factory()->for($this->acme)->create();
    Customer::factory()->for($this->globex)->create();

    $this->getJson('api/v1/customers')
        ->assertOk()
        ->assertJsonPath('data', [customerJson($customer)])
        ->assertJsonPath('meta.current_page', 1)
        ->assertJsonPath('meta.per_page', 15)
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('meta.last_page', 1)
        ->assertJsonPath('links.first', url('api/v1/customers?page=1'))
        ->assertJsonPath('links.next', null);
});

it('creates a customer with every field and answers 201', function () {
    $response = $this->postJson('api/v1/customers', [
        'name' => 'Bluefield Logistics',
        'email' => '  Orders@Bluefield.TEST ',
        'phone' => '+8801711000000',
        'status' => 'inactive',
    ])->assertCreated();

    $customer = Customer::withoutGlobalScope(TenantScope::class)->sole();

    $response->assertExactJson(['data' => [
        'id' => $customer->id,
        'name' => 'Bluefield Logistics',
        'email' => 'orders@bluefield.test',
        'phone' => '+8801711000000',
        'status' => 'inactive',
    ]]);

    expect($customer->tenant_id)->toBe($this->acme->id);
});

it('creates an active customer when only name and email are sent', function () {
    $this->postJson('api/v1/customers', ['name' => 'Harbor Point', 'email' => 'hello@harbor.test'])
        ->assertCreated()
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.phone', null);
});

it('shows a customer', function () {
    $customer = Customer::factory()->for($this->acme)->create();

    $this->getJson('api/v1/customers/'.$customer->id)
        ->assertOk()
        ->assertExactJson(['data' => customerJson($customer)]);
});

it('updates only the fields sent', function () {
    $customer = Customer::factory()->for($this->acme)->create(['name' => 'Old Name', 'phone' => '123']);

    $this->patchJson('api/v1/customers/'.$customer->id, ['status' => 'inactive'])
        ->assertOk()
        ->assertExactJson(['data' => [...customerJson($customer), 'status' => 'inactive']]);

    expect($customer->fresh())
        ->name->toBe('Old Name')
        ->phone->toBe('123');
});

it('clears a nullable field on update', function () {
    $customer = Customer::factory()->for($this->acme)->create(['phone' => '123']);

    $this->patchJson('api/v1/customers/'.$customer->id, ['phone' => null])
        ->assertOk()
        ->assertJsonPath('data.phone', null);
});

it('keeps its own email on update', function () {
    $customer = Customer::factory()->for($this->acme)->create(['email' => 'same@acme.test']);

    $this->patchJson('api/v1/customers/'.$customer->id, ['email' => 'SAME@acme.test'])
        ->assertOk()
        ->assertJsonPath('data.email', 'same@acme.test');
});

it('deletes a customer permanently', function () {
    $customer = Customer::factory()->for($this->acme)->create();

    $this->deleteJson('api/v1/customers/'.$customer->id)->assertNoContent();

    expect(Customer::withoutGlobalScope(TenantScope::class)->whereKey($customer->id)->exists())->toBeFalse();
});

it('refuses invalid input on create', function (array $payload, array $errors) {
    $this->postJson('api/v1/customers', $payload)
        ->assertUnprocessable()
        ->assertOnlyJsonValidationErrors($errors);

    expect(Customer::withoutGlobalScope(TenantScope::class)->count())->toBe(0);
})->with([
    'missing required fields' => [[], ['name', 'email']],
    'bad email' => [['name' => 'A', 'email' => 'not-an-email'], ['email']],
    'unknown status' => [['name' => 'A', 'email' => 'a@x.test', 'status' => 'deleted'], ['status']],
    'null status' => [['name' => 'A', 'email' => 'a@x.test', 'status' => null], ['status']],
    'phone too long' => [['name' => 'A', 'email' => 'a@x.test', 'phone' => str_repeat('1', 33)], ['phone']],
    'name too long' => [['name' => str_repeat('a', 256), 'email' => 'a@x.test'], ['name']],
    'tenant id' => [['name' => 'A', 'email' => 'a@x.test', 'tenant_id' => 1], ['tenant_id']],
    'unknown field' => [['name' => 'A', 'email' => 'a@x.test', 'nickname' => 'B'], ['nickname']],
]);

it('refuses an email already used in the same tenant, case-insensitively', function () {
    Customer::factory()->for($this->acme)->create(['email' => 'jane@shared.test']);

    $this->postJson('api/v1/customers', ['name' => 'Jane', 'email' => 'JANE@shared.test'])
        ->assertUnprocessable()
        ->assertExactJson([
            'message' => 'The email has already been taken.',
            'errors' => ['email' => ['The email has already been taken.']],
        ]);
});

it('allows an email already used at another tenant', function () {
    Customer::factory()->for($this->globex)->create(['email' => 'jane@shared.test']);

    $this->postJson('api/v1/customers', ['name' => 'Jane', 'email' => 'jane@shared.test'])->assertCreated();
});

it('refuses updating to an email another customer of the tenant holds', function () {
    Customer::factory()->for($this->acme)->create(['email' => 'taken@acme.test']);
    $customer = Customer::factory()->for($this->acme)->create();

    $this->patchJson('api/v1/customers/'.$customer->id, ['email' => 'taken@acme.test'])
        ->assertUnprocessable()
        ->assertOnlyJsonValidationErrors(['email']);
});

it('refuses invalid input on update, and an empty value for a required field', function (array $payload, array $errors) {
    $customer = Customer::factory()->for($this->acme)->create();

    $this->patchJson('api/v1/customers/'.$customer->id, $payload)
        ->assertUnprocessable()
        ->assertOnlyJsonValidationErrors($errors);
})->with([
    'null name' => [['name' => null], ['name']],
    'empty email' => [['email' => ''], ['email']],
    'unknown status' => [['status' => 'gone'], ['status']],
    'tenant id' => [['tenant_id' => 2], ['tenant_id']],
]);

it('leaves the unique index as the backstop when two creates race', function () {
    app(TenantContext::class)->set($this->acme);

    Customer::create(['name' => 'First', 'email' => 'race@acme.test']);

    expect(fn () => Customer::create(['name' => 'Second', 'email' => 'race@acme.test']))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('holds an exact query count, with explicit columns, on every endpoint', function (string $method, Closure $uri, array $payload, int $queries) {
    $customer = Customer::factory()->for($this->acme)->create();
    // Creating the fixture warmed the tenant cache; the counts below are for a cold one.
    Cache::flush();
    // The platform stats refresh is a queued job on the worker, not part of the request.
    Queue::fake();
    DB::enableQueryLog();

    $this->json($method, $uri($customer), $payload)->assertSuccessful();

    expect(loggedQueries())->toHaveCount($queries)
        ->and(implode("\n", loggedQueries()))->not->toContain('select *');
})->with([
    'index: tenant, stored total, page' => ['GET', fn () => 'api/v1/customers', [], 3],
    'store, unlimited plan: tenant, unique check, subscription, plan, features, insert, count increment, monthly growth' => ['POST', fn () => 'api/v1/customers', ['name' => 'N', 'email' => 'n@acme.test'], 8],
    'show: tenant, find' => ['GET', fn (Customer $c) => 'api/v1/customers/'.$c->id, [], 2],
    'update: tenant, unique check, find, update' => ['PATCH', fn (Customer $c) => 'api/v1/customers/'.$c->id, ['email' => 'new@acme.test'], 4],
    'delete: tenant, find, row lock, delete, count decrement' => ['DELETE', fn (Customer $c) => 'api/v1/customers/'.$c->id, [], 5],
]);

it('documents the five customer endpoints with bearer auth', function () {
    $spec = $this->getJson('docs/api.json')->assertOk()->json();

    expect(array_keys($spec['paths']['/customers']))->toBe(['get', 'post'])
        ->and(array_keys($spec['paths']['/customers/{id}']))->toBe(['get', 'patch', 'delete'])
        ->and(array_keys($spec['paths']['/customers']['post']['responses']))->toContain(201, 401, 403, 422)
        ->and(array_keys($spec['paths']['/customers/{id}']['delete']['responses']))->toContain(204, 403, 404)
        ->and($spec['paths']['/customers/{id}']['patch']['requestBody']['content']['application/json']['schema']['$ref'])
        ->toBe('#/components/schemas/UpdateCustomerRequest')
        ->and(array_keys($spec['components']['schemas']['StoreCustomerRequest']['properties']))->toBe(['name', 'email', 'phone', 'status'])
        ->and($spec['components']['schemas']['StoreCustomerRequest']['required'])->toBe(['name', 'email'])
        ->and(array_keys($spec['components']['schemas']['UpdateCustomerRequest']['properties']))->toBe(['name', 'email', 'phone', 'status'])
        ->and($spec['components']['schemas']['UpdateCustomerRequest'])->not->toHaveKey('required')
        ->and($spec['components']['securitySchemes'])->not->toBeEmpty();
});
