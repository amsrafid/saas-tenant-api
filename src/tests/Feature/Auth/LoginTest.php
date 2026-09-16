<?php

use App\Enums\UserStatus;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    $this->user = User::factory()->for($this->tenant)->owner()->create(['email' => 'owner@acme.test']);
});

it('issues a token for valid credentials', function () {
    $response = $this->postJson('/api/v1/auth/login', ['email' => 'Owner@Acme.test', 'password' => 'password'])
        ->assertOk()
        ->assertJsonPath('data.token_type', 'Bearer')
        ->assertJsonPath('data.user.id', $this->user->id)
        ->assertJsonPath('data.user.role', 'owner')
        ->assertJsonPath('data.user.tenant.id', $this->tenant->id)
        ->assertJsonMissingPath('data.user.password');

    $this->withToken($response->json('data.access_token'))->getJson('/api/v1/auth/me')->assertOk();
});

it('answers an unknown email exactly like a wrong password', function () {
    $wrongPassword = $this->postJson('/api/v1/auth/login', ['email' => 'owner@acme.test', 'password' => 'wrong-password']);
    $unknownEmail = $this->postJson('/api/v1/auth/login', ['email' => 'nobody@acme.test', 'password' => 'wrong-password']);

    $wrongPassword->assertUnprocessable()->assertJsonValidationErrors(['email']);

    expect($unknownEmail->status())->toBe($wrongPassword->status())
        ->and($unknownEmail->getContent())->toBe($wrongPassword->getContent());
});

it('refuses a disabled user even with the right password', function () {
    $this->user->forceFill(['status' => UserStatus::Disabled])->save();

    $this->postJson('/api/v1/auth/login', ['email' => 'owner@acme.test', 'password' => 'password'])
        ->assertForbidden()
        ->assertJsonPath('message', 'This account is disabled.');

    expect($this->user->tokens()->count())->toBe(0);
});

it('answers a disabled user with a wrong password like any failed login', function () {
    $this->user->forceFill(['status' => UserStatus::Disabled])->save();

    $this->postJson('/api/v1/auth/login', ['email' => 'owner@acme.test', 'password' => 'wrong-password'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email']);
});

it('throttles after five attempts per IP and email', function () {
    foreach (range(1, 5) as $attempt) {
        $this->postJson('/api/v1/auth/login', ['email' => 'owner@acme.test', 'password' => 'wrong-password'])->assertUnprocessable();
    }

    $this->postJson('/api/v1/auth/login', ['email' => 'OWNER@acme.test', 'password' => 'password'])
        ->assertTooManyRequests()
        ->assertHeader('Retry-After');

    $this->postJson('/api/v1/auth/login', ['email' => 'someone-else@acme.test', 'password' => 'wrong-password'])->assertUnprocessable();
});

it('logs in a platform admin, who has no tenant', function () {
    $admin = User::factory()->platformAdmin()->create(['email' => 'admin@platform.test']);

    $this->postJson('/api/v1/auth/login', ['email' => 'admin@platform.test', 'password' => 'password'])
        ->assertOk()
        ->assertJsonPath('data.user.id', $admin->id)
        ->assertJsonPath('data.user.role', 'platform_admin')
        ->assertJsonPath('data.user.tenant', null);
});

it('rejects unknown fields', function () {
    $this->postJson('/api/v1/auth/login', ['email' => 'owner@acme.test', 'password' => 'password', 'remember' => true])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['remember']);
});
