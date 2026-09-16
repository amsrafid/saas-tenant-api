<?php

use App\Models\Customer;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantMonthlyStats;
use App\Models\User;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    [$this->acme, $this->globex] = Tenant::factory()->count(2)->create();

    foreach ([$this->acme, $this->globex] as $tenant) {
        User::factory()->for($tenant)->create();
        Customer::factory()->for($tenant)->create();
        Subscription::factory()->for($tenant)->create();
    }
});

it('constrains every tenant-owned model to the current tenant', function (string $model) {
    app(TenantContext::class)->set($this->acme);

    expect($model::pluck('tenant_id')->all())->toBe([$this->acme->id]);

    app(TenantContext::class)->set($this->globex);

    expect($model::pluck('tenant_id')->all())->toBe([$this->globex->id]);
})->with([Customer::class, Subscription::class, User::class, TenantMonthlyStats::class]);

it('fails closed when no tenant is set', function (string $model) {
    $model::count();
})->with([Customer::class, Subscription::class, User::class])
    ->throws(RuntimeException::class, 'No tenant is set.');

it('stamps tenant_id from the context on create, ignoring a mass-assigned one', function () {
    app(TenantContext::class)->set($this->acme);

    $customer = Customer::create(['name' => 'Jane', 'email' => 'jane@acme.test', 'status' => 'active', 'tenant_id' => $this->globex->id]);

    expect($customer->tenant_id)->toBe($this->acme->id);
});

it('scopes bulk updates and deletes through the query builder', function () {
    app(TenantContext::class)->set($this->acme);

    Customer::where('status', 'active')->delete();

    expect(Customer::withoutGlobalScope(TenantScope::class)->pluck('tenant_id')->all())->toBe([$this->globex->id]);
});

it('sees every tenant through the explicit bypass, with or without a context', function () {
    expect(Customer::withoutGlobalScope(TenantScope::class)->count())->toBe(2);

    app(TenantContext::class)->set($this->acme);

    expect(Customer::withoutGlobalScope(TenantScope::class)->count())->toBe(2)
        ->and(Customer::where('status', 'active')->withoutGlobalScope(TenantScope::class)->count())->toBe(2);
});
