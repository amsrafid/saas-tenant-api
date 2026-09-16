<?php

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    [$this->acme, $this->globex] = Tenant::factory()->count(2)->create();
    $this->acmeAdmin = User::factory()->for($this->acme)->create(['role' => 'admin']);
    User::factory()->for($this->globex)->owner()->create();
});

it('returns the current user with role and tenant', function () {
    $token = $this->acmeAdmin->createToken('api')->plainTextToken;

    $this->withToken($token)->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertExactJsonStructure([
            'data' => ['id', 'name', 'email', 'role', 'status', 'tenant' => ['id', 'name', 'slug', 'status', 'timezone']],
        ])
        ->assertJsonPath('data.id', $this->acmeAdmin->id)
        ->assertJsonPath('data.role', 'admin')
        ->assertJsonPath('data.tenant.id', $this->acme->id);
});

it('never shows another tenant, whatever the request asks for', function () {
    $token = $this->acmeAdmin->createToken('api')->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/v1/auth/me?tenant_id='.$this->globex->id, ['X-Tenant-ID' => $this->globex->id])
        ->assertOk()
        ->assertJsonPath('data.tenant.id', $this->acme->id)
        ->assertJsonMissing(['name' => $this->globex->name]);
});

it('costs the token lookup, the user, the last-used stamp and the tenant, and nothing more', function () {
    $token = $this->acmeAdmin->createToken('api')->plainTextToken;
    DB::enableQueryLog();

    $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();

    expect(DB::getQueryLog())->toHaveCount(4);
});

it('returns a platform admin with a null tenant', function () {
    $admin = User::factory()->platformAdmin()->create();

    $this->withToken($admin->createToken('api')->plainTextToken)->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.role', 'platform_admin')
        ->assertJsonPath('data.tenant', null);
});

it('requires authentication', function () {
    $this->getJson('/api/v1/auth/me')->assertUnauthorized();
});

it('answers a missing token with a JSON 401 even without an Accept header', function () {
    $this->get('/api/v1/auth/me')
        ->assertUnauthorized()
        ->assertHeader('Content-Type', 'application/json');
});
