<?php

use App\Enums\BillingPeriod;
use App\Enums\SubscriptionStatus;
use App\Enums\TenantStatus;
use App\Jobs\RefreshPlatformStats;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->free = Plan::factory()->create(['slug' => 'free', 'price_cents' => 0, 'sort_order' => 0]);
    $this->pro = Plan::factory()->create(['slug' => 'pro', 'price_cents' => 2900, 'sort_order' => 1]);
    $this->annual = Plan::factory()->create(['slug' => 'annual', 'price_cents' => 12000, 'billing_period' => BillingPeriod::Yearly, 'sort_order' => 2]);

    [$this->acme, $this->globex] = Tenant::factory()->count(2)->create();
    $this->acmeSubscription = Subscription::factory()->for($this->acme)->for($this->pro)->create();
    Subscription::factory()->for($this->globex)->for($this->annual)->create();
    Subscription::factory()->for($this->globex)->for($this->pro)->create(['status' => SubscriptionStatus::Canceled]);
    Subscription::factory()->for(Tenant::factory()->create(['status' => TenantStatus::Suspended]))->for($this->pro)->create();
    Subscription::factory()->for(Tenant::factory())->for($this->annual)->create();

    DB::table('tenant_stats')->where('tenant_id', $this->acme->id)->update(['users_count' => 12, 'customers_count' => 3400]);
    DB::table('tenant_stats')->where('tenant_id', $this->globex->id)->update(['users_count' => 3, 'customers_count' => 5]);
});

/**
 * @return array<string, mixed>
 */
function storedPlatformStats(): array
{
    return (array) DB::table('platform_stats')->sole(['id', 'tenants_count', 'suspended_tenants_count', 'users_count', 'customers_count']);
}

/**
 * @return array<string, array{tenants_count: int, mrr_cents: int}>
 */
function storedPlanStats(): array
{
    return DB::table('plan_stats')
        ->join('plans', 'plans.id', '=', 'plan_stats.plan_id')
        ->get(['plans.slug', 'plan_stats.tenants_count', 'plan_stats.mrr_cents'])
        ->mapWithKeys(fn (object $row) => [$row->slug => ['tenants_count' => $row->tenants_count, 'mrr_cents' => $row->mrr_cents]])
        ->sortKeys()
        ->all();
}

it('creates the platform row and a row per plan, subscribed or not', function () {
    DB::table('platform_stats')->delete();
    DB::table('plan_stats')->delete();

    RefreshPlatformStats::dispatchSync();

    expect(storedPlatformStats())->toBe([
        'id' => 1,
        'tenants_count' => 4,
        'suspended_tenants_count' => 1,
        'users_count' => 15,
        'customers_count' => 3405,
    ])->and(storedPlanStats())->toBe([
        'annual' => ['tenants_count' => 2, 'mrr_cents' => 2000],
        'free' => ['tenants_count' => 0, 'mrr_cents' => 0],
        'pro' => ['tenants_count' => 2, 'mrr_cents' => 5800],
    ]);
});

it('refreshes the existing rows in place', function () {
    RefreshPlatformStats::dispatchSync();
    DB::table('subscriptions')->where('id', $this->acmeSubscription->id)->update(['status' => SubscriptionStatus::Expired]);
    DB::table('tenants')->where('id', $this->acme->id)->update(['status' => TenantStatus::Suspended]);

    RefreshPlatformStats::dispatchSync();

    expect(storedPlatformStats())->toMatchArray(['tenants_count' => 4, 'suspended_tenants_count' => 2])
        ->and(storedPlanStats()['pro'])->toBe(['tenants_count' => 1, 'mrr_cents' => 2900])
        ->and(DB::table('plan_stats')->count())->toBe(3);
});

it('queues one refresh 30 seconds out, however many writes arrive', function () {
    Queue::fake();

    RefreshPlatformStats::dispatch();
    RefreshPlatformStats::dispatch();
    RefreshPlatformStats::dispatch();

    Queue::assertPushed(RefreshPlatformStats::class, 1);
    Queue::assertPushed(RefreshPlatformStats::class, fn (RefreshPlatformStats $job) => $job->delay === 30);
});
