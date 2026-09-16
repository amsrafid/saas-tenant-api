<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('revokes only the presented token', function () {
    $user = User::factory()->owner()->create();
    $presented = $user->createToken('laptop')->plainTextToken;
    $other = $user->createToken('phone')->plainTextToken;

    $this->withToken($presented)->postJson('/api/v1/auth/logout')->assertNoContent();

    app('auth')->forgetGuards();
    $this->withToken($presented)->getJson('/api/v1/auth/me')->assertUnauthorized();

    app('auth')->forgetGuards();
    $this->withToken($other)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.id', $user->id);
});

it('lets a platform admin log out', function () {
    $token = User::factory()->platformAdmin()->create()->createToken('api')->plainTextToken;

    $this->withToken($token)->postJson('/api/v1/auth/logout')->assertNoContent();
});

it('requires authentication', function () {
    $this->postJson('/api/v1/auth/logout')->assertUnauthorized();
});
