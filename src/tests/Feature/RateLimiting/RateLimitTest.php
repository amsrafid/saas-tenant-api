<?php

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    [$this->acme, $this->globex] = Tenant::factory()->count(2)->create();
    $this->acmeUser = User::factory()->for($this->acme)->create();
    $this->globexUser = User::factory()->for($this->globex)->create();
    Sanctum::actingAs($this->acmeUser);
});

it('limits an authenticated user to 60 requests a minute, per user', function () {
    foreach (range(1, 60) as $request) {
        $this->getJson('api/v1/auth/me')->assertOk();
    }

    $this->getJson('api/v1/auth/me')->assertTooManyRequests()->assertHeader('Retry-After', 60);

    Sanctum::actingAs($this->globexUser);
    $this->getJson('api/v1/auth/me')->assertOk();

    $this->travel(1)->minute();
    Sanctum::actingAs($this->acmeUser);
    $this->getJson('api/v1/auth/me')->assertOk();
});

it('limits the public plan catalogue to 60 requests a minute per IP', function () {
    foreach (range(1, 60) as $request) {
        $this->getJson('api/v1/plans')->assertOk();
    }

    $this->getJson('api/v1/plans')->assertTooManyRequests();
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->getJson('api/v1/plans')->assertOk();
});

it('limits registration to 10 an hour per IP, whatever the email', function () {
    foreach (range(1, 10) as $attempt) {
        $this->postJson('api/v1/auth/register', ['email' => "new{$attempt}@example.test"])->assertUnprocessable();
    }

    $this->postJson('api/v1/auth/register', ['email' => 'another@example.test'])->assertTooManyRequests();

    $this->travel(1)->hour();
    $this->postJson('api/v1/auth/register', ['email' => 'another@example.test'])->assertUnprocessable();
});
