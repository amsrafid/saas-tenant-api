<?php

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    [$this->acme, $this->globex] = Tenant::factory()->count(2)->create();
    Subscription::factory()->for($this->acme)->for(Plan::factory()->withLimits())->create();
    $this->owner = User::factory()->for($this->acme)->owner()->create();
    Sanctum::actingAs($this->owner);
});

function userJson(User $user): array
{
    return [
        'id' => $user->id,
        'name' => $user->name,
        'email' => $user->email,
        'role' => $user->role->value,
        'status' => $user->status->value,
    ];
}

it('lists the tenant\'s users with pagination meta', function () {
    User::factory()->for($this->globex)->create();

    $this->getJson('api/v1/users')
        ->assertOk()
        ->assertJsonPath('data', [userJson($this->owner)])
        ->assertJsonPath('meta.per_page', 15)
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('links.next', null);
});

it('takes the unfiltered total from tenant_stats and counts only a filtered or searched listing', function (string $query, int $total, bool $counts) {
    User::factory()->for($this->acme)->create(['name' => 'Dana Admin', 'role' => 'admin']);
    DB::table('tenant_stats')->where('tenant_id', $this->acme->id)->update(['users_count' => 12]);
    DB::enableQueryLog();

    $this->getJson('api/v1/users'.$query)->assertOk()->assertJsonPath('meta.total', $total);

    expect(str_contains(implode("\n", array_column(DB::getQueryLog(), 'query')), 'count(*)'))->toBe($counts);
})->with([
    'unfiltered' => ['', 12, false],
    'role' => ['?filter[role]=admin', 1, true],
    'search' => ['?search=dana', 1, true],
]);

it('filters users by role and status and searches name and email', function (string $query, string $expected) {
    User::factory()->for($this->acme)->create(['name' => 'Dana Admin', 'role' => 'admin']);
    User::factory()->for($this->acme)->create(['name' => 'Off Member', 'status' => 'disabled']);

    $this->getJson('api/v1/users?'.$query)
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.name', $expected);
})->with([
    'role' => ['filter[role]=admin', 'Dana Admin'],
    'status' => ['filter[status]=disabled', 'Off Member'],
    'search' => ['search=DANA', 'Dana Admin'],
]);

it('refuses an unknown filter value, including platform_admin', function (string $query, string $error) {
    $this->getJson('api/v1/users?'.$query)
        ->assertUnprocessable()
        ->assertOnlyJsonValidationErrors([$error]);
})->with([
    ['filter[role]=platform_admin', 'filter.role'],
    ['filter[status]=gone', 'filter.status'],
    ['filter[name]=x', 'filter'],
]);

it('creates an active user in the current tenant and answers 201', function () {
    $response = $this->postJson('api/v1/users', [
        'name' => 'New Admin',
        'email' => ' New@Acme.TEST ',
        'password' => 'secret123',
        'role' => 'admin',
    ])->assertCreated();

    $user = User::withoutGlobalScope(TenantScope::class)->where('email', 'new@acme.test')->sole();

    $response->assertExactJson(['data' => userJson($user)]);
    expect($user)
        ->tenant_id->toBe($this->acme->id)
        ->role->value->toBe('admin')
        ->status->value->toBe('active')
        ->and(Hash::check('secret123', $user->password))->toBeTrue();
});

it('shows a user', function () {
    $this->getJson('api/v1/users/'.$this->owner->id)
        ->assertOk()
        ->assertExactJson(['data' => userJson($this->owner)]);
});

it('updates only the fields sent', function () {
    $user = User::factory()->for($this->acme)->create(['name' => 'Old Name']);

    $this->patchJson('api/v1/users/'.$user->id, ['role' => 'admin', 'status' => 'disabled'])
        ->assertOk()
        ->assertExactJson(['data' => [...userJson($user), 'role' => 'admin', 'status' => 'disabled']]);

    expect($user->fresh())
        ->name->toBe('Old Name')
        ->and(Hash::check('password', $user->fresh()->password))->toBeTrue();
});

it('deletes a user and their tokens', function () {
    $user = User::factory()->for($this->acme)->create();
    $user->createApiToken();

    $this->deleteJson('api/v1/users/'.$user->id)->assertNoContent();

    expect(User::withoutGlobalScope(TenantScope::class)->whereKey($user->id)->exists())->toBeFalse()
        ->and(DB::table('personal_access_tokens')->count())->toBe(0);
});

it('refuses invalid input on create', function (array $payload, array $errors) {
    User::factory()->for($this->globex)->create(['email' => 'taken@globex.test']);
    $valid = ['name' => 'A', 'email' => 'a@acme.test', 'password' => 'secret123', 'role' => 'member'];

    $this->postJson('api/v1/users', [...$valid, ...$payload])
        ->assertUnprocessable()
        ->assertOnlyJsonValidationErrors($errors);
})->with([
    'email taken at another tenant' => [['email' => 'TAKEN@globex.test'], ['email']],
    'unknown role' => [['role' => 'boss'], ['role']],
    'platform admin role' => [['role' => 'platform_admin'], ['role']],
    'password too short' => [['password' => 'short'], ['password']],
    'status' => [['status' => 'active'], ['status']],
]);

it('refuses invalid input on update', function (array $payload, array $errors) {
    User::factory()->for($this->acme)->create(['email' => 'taken@acme.test']);
    $user = User::factory()->for($this->acme)->create();

    $this->patchJson('api/v1/users/'.$user->id, $payload)
        ->assertUnprocessable()
        ->assertOnlyJsonValidationErrors($errors);
})->with([
    'email of another user' => [['email' => 'TAKEN@acme.test'], ['email']],
    'platform admin role' => [['role' => 'platform_admin'], ['role']],
    'unknown status' => [['status' => 'archived'], ['status']],
    'password' => [['password' => 'secret123'], ['password']],
]);

it('ends a disabled user\'s access on their next request', function () {
    $member = User::factory()->for($this->acme)->create();
    $memberToken = $member->createApiToken()->plainTextToken;
    $ownerToken = $this->owner->createApiToken()->plainTextToken;
    app('auth')->forgetGuards();

    $this->withToken($ownerToken)->patchJson('api/v1/users/'.$member->id, ['status' => 'disabled'])->assertOk();

    app('auth')->forgetGuards();
    $this->withToken($memberToken)->getJson('api/v1/users')->assertUnauthorized();
});

it('holds an exact query count, with explicit columns, on index and show', function (string $uri, int $queries) {
    DB::enableQueryLog();

    $this->getJson(str_replace('{id}', (string) $this->owner->id, $uri))->assertOk();

    $log = array_column(DB::getQueryLog(), 'query');
    expect($log)->toHaveCount($queries)
        ->and(implode("\n", $log))->not->toContain('select *');
})->with([
    'index: tenant, stored total, page' => ['api/v1/users', 3],
    'show: tenant, find' => ['api/v1/users/{id}', 2],
]);
