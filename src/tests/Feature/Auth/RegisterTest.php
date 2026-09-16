<?php

use App\Enums\BillingPeriod;
use App\Enums\SubscriptionStatus;
use App\Enums\TenantRole;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->freePlan = Plan::factory()->create(['slug' => Plan::FREE_SLUG, 'price_cents' => 0, 'billing_period' => BillingPeriod::Monthly]);
    $this->payload = [
        'tenant_name' => 'Acme Ltd',
        'name' => 'Jane Owner',
        'email' => 'Jane@Acme.test',
        'password' => 'correct-horse',
    ];
});

it('creates the tenant, its owner and an active Free subscription, and returns a token', function () {
    $response = $this->postJson('/api/v1/auth/register', $this->payload)
        ->assertCreated()
        ->assertJsonPath('data.token_type', 'Bearer')
        ->assertJsonPath('data.user.email', 'jane@acme.test')
        ->assertJsonPath('data.user.role', 'owner')
        ->assertJsonPath('data.user.status', 'active')
        ->assertJsonPath('data.user.tenant.name', 'Acme Ltd')
        ->assertJsonPath('data.user.tenant.slug', 'acme-ltd')
        ->assertJsonPath('data.user.tenant.status', 'active')
        ->assertJsonPath('data.user.tenant.timezone', 'Asia/Dhaka')
        ->assertJsonMissingPath('data.user.password');

    $tenant = Tenant::where('slug', 'acme-ltd')->sole();
    $owner = User::withoutGlobalScope(TenantScope::class)->where('email', 'jane@acme.test')->sole();
    $subscription = Subscription::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenant->id)->sole();

    expect($response->json('data.user.tenant.id'))->toBe($tenant->id)
        ->and($owner->tenant_id)->toBe($tenant->id)
        ->and($owner->role)->toBe(TenantRole::Owner)
        ->and($subscription->plan_id)->toBe($this->freePlan->id)
        ->and($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->ends_at)->toBeNull()
        ->and($response->json('data.expires_at'))->not->toBeNull();
});

it('creates the tenant\'s stats row with its owner already counted', function () {
    $this->postJson('/api/v1/auth/register', $this->payload)->assertCreated();

    $tenant = Tenant::where('slug', 'acme-ltd')->sole();

    expect(DB::table('tenant_stats')->where('tenant_id', $tenant->id)->first(['users_count', 'customers_count']))
        ->toEqual((object) ['users_count' => 1, 'customers_count' => 0]);
});

it('returns a token that authenticates the new owner', function () {
    $token = $this->postJson('/api/v1/auth/register', $this->payload)->assertCreated()->json('data.access_token');

    $this->withToken($token)->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.email', 'jane@acme.test')
        ->assertJsonPath('data.tenant.slug', 'acme-ltd');
});

it('rolls back the tenant and owner when the subscription insert fails', function () {
    Subscription::creating(fn () => throw new RuntimeException('Subscription insert failed.'));

    $this->postJson('/api/v1/auth/register', $this->payload)->assertServerError();

    expect(Tenant::count())->toBe(0)
        ->and(User::withoutGlobalScope(TenantScope::class)->count())->toBe(0)
        ->and(Subscription::withoutGlobalScope(TenantScope::class)->count())->toBe(0)
        ->and(DB::table('personal_access_tokens')->count())->toBe(0)
        ->and(DB::table('tenant_stats')->count())->toBe(0);
});

it('fails loudly, creating nothing, when the Free plan is missing', function () {
    $this->freePlan->delete();
    $this->withoutExceptionHandling();

    expect(fn () => $this->postJson('/api/v1/auth/register', $this->payload))->toThrow(RuntimeException::class, "The default plan 'free' is missing; run the plan seeder before accepting registrations.")
        ->and(Tenant::count())->toBe(0);
});

it('rejects an email that is already registered, whatever its case', function () {
    User::factory()->create(['email' => 'jane@acme.test']);

    $this->postJson('/api/v1/auth/register', $this->payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email']);

    expect(Tenant::count())->toBe(1);
});

it('gives a colliding company name a distinct slug', function () {
    Tenant::factory()->create(['slug' => 'acme-ltd']);

    $slug = $this->postJson('/api/v1/auth/register', $this->payload)->assertCreated()->json('data.user.tenant.slug');

    expect($slug)->toStartWith('acme-ltd-')->not->toBe('acme-ltd');
});

it('rejects role, status and tenant_id in the body instead of applying them', function () {
    $victim = Tenant::factory()->create();

    $this->postJson('/api/v1/auth/register', [...$this->payload, 'role' => 'platform_admin', 'status' => 'disabled', 'tenant_id' => $victim->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['role', 'status', 'tenant_id']);

    expect(User::withoutGlobalScope(TenantScope::class)->count())->toBe(0)
        ->and(Tenant::count())->toBe(1);
});

it('validates the required fields', function () {
    $this->postJson('/api/v1/auth/register', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['tenant_name', 'name', 'email', 'password']);
});

it('reads the Free plan from cache on the next registration', function () {
    $this->postJson('/api/v1/auth/register', $this->payload)->assertCreated();
    Queue::fake();
    DB::enableQueryLog();

    $this->postJson('/api/v1/auth/register', [...$this->payload, 'email' => 'john@globex.test', 'tenant_name' => 'Globex'])->assertCreated();

    expect(implode("\n", array_column(DB::getQueryLog(), 'query')))->not->toContain('from "plans"');
});
