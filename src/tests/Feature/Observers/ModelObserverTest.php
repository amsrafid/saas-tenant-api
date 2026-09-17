<?php

use App\Enums\SubscriptionStatus;
use App\Enums\TenantStatus;
use App\Jobs\RefreshPlatformStats;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\PlatformStats;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Repositories\PlanRepository;
use App\Repositories\SubscriptionRepository;
use App\Repositories\TenantRepository;
use App\Services\AdminService;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->acme = Tenant::factory()->create();
    $this->plan = Plan::factory()->withLimits()->create();
    $this->subscription = Subscription::factory()->for($this->acme)->for($this->plan)->create();
});

/**
 * Fill the analytics cache, then assert the write drops it.
 */
function expectWriteDropsAnalytics(Closure $write): void
{
    app(AdminService::class)->analytics();
    expect(Cache::has('platform:analytics'))->toBeTrue();

    $write();

    expect(Cache::has('platform:analytics'))->toBeFalse();
}

/**
 * @return array{users_count: int, customers_count: int}
 */
function storedCounts(Tenant $tenant): array
{
    return (array) DB::table('tenant_stats')->where('tenant_id', $tenant->id)->first(['users_count', 'customers_count']);
}

describe('tenant stats', function () {
    it('counts a customer or a tenant user exactly on create and delete, and only for its own tenant', function (Closure $create, string $column) {
        $globex = Tenant::factory()->create();

        $models = [$create($this->acme), $create($this->acme), $create($globex)];
        expect(storedCounts($this->acme)[$column])->toBe(2)
            ->and(storedCounts($globex)[$column])->toBe(1);

        $models[0]->delete();
        expect(storedCounts($this->acme)[$column])->toBe(1)
            ->and(storedCounts($globex)[$column])->toBe(1);
    })->with([
        'customer' => [fn (Tenant $tenant) => Customer::factory()->for($tenant)->create(), 'customers_count'],
        'user' => [fn (Tenant $tenant) => User::factory()->for($tenant)->create(), 'users_count'],
    ]);

    it('leaves the count unchanged when the write rolls back', function () {
        expect(fn () => DB::transaction(function () {
            Customer::factory()->for($this->acme)->create();
            User::factory()->for($this->acme)->create();

            throw new RuntimeException('Rolled back.');
        }))->toThrow(RuntimeException::class);

        expect(storedCounts($this->acme))->toBe(['users_count' => 0, 'customers_count' => 0]);
    });

    it('touches no stats row for a platform admin, and none on a customer update', function () {
        $customer = Customer::factory()->for($this->acme)->create();
        DB::enableQueryLog();

        User::factory()->platformAdmin()->create()->delete();
        $customer->forceFill(['name' => 'Renamed'])->save();

        expect(implode("\n", array_column(DB::getQueryLog(), 'query')))->not->toContain('tenant_stats')
            ->and(storedCounts($this->acme))->toBe(['users_count' => 0, 'customers_count' => 1]);
    });

    it('queues the platform refresh only once the write commits', function () {
        Queue::fake();

        DB::transaction(function () {
            Customer::factory()->for($this->acme)->create();

            Queue::assertNotPushed(RefreshPlatformStats::class);
        });

        Queue::assertPushed(RefreshPlatformStats::class);
    });

    it('keeps the counts right through the API', function () {
        $owner = User::factory()->for($this->acme)->owner()->create();
        Sanctum::actingAs($owner);

        $id = $this->postJson('api/v1/customers', ['name' => 'One', 'email' => 'one@acme.test'])->assertCreated()->json('data.id');
        $this->postJson('api/v1/customers', ['name' => 'Two', 'email' => 'two@acme.test'])->assertCreated();
        $userId = $this->postJson('api/v1/users', ['name' => 'New', 'email' => 'new@acme.test', 'password' => 'secret123', 'role' => 'member'])->assertCreated()->json('data.id');
        $this->deleteJson('api/v1/customers/'.$id)->assertNoContent();
        $this->deleteJson('api/v1/users/'.$userId)->assertNoContent();

        expect(storedCounts($this->acme))->toBe(['users_count' => 1, 'customers_count' => 1]);
    });

    it('gives every new tenant a zeroed stats row', function () {
        expect(storedCounts(Tenant::factory()->create()))->toBe(['users_count' => 0, 'customers_count' => 0]);
    });
});

describe('platform stats', function () {
    it('queues a refresh on every write its figures come from', function (Closure $write) {
        Queue::fake();

        $write($this->acme, $this->plan, $this->subscription);

        Queue::assertPushed(RefreshPlatformStats::class);
    })->with([
        'customer created' => [fn (Tenant $tenant) => Customer::factory()->for($tenant)->create()],
        'customer deleted' => [fn (Tenant $tenant) => Model::withoutEvents(fn () => Customer::factory()->for($tenant)->create())->delete()],
        'tenant user created' => [fn (Tenant $tenant) => User::factory()->for($tenant)->create()],
        'tenant user deleted' => [fn (Tenant $tenant) => Model::withoutEvents(fn () => User::factory()->for($tenant)->create())->delete()],
        'new tenant' => [fn () => Tenant::factory()->create()],
        'tenant status change' => [fn (Tenant $tenant) => $tenant->forceFill(['status' => TenantStatus::Suspended])->save()],
        'tenant deleted' => [fn () => Model::withoutEvents(fn () => Tenant::factory()->create())->delete()],
        'subscription created' => [fn (Tenant $tenant, Plan $plan) => Subscription::factory()->for($tenant)->for($plan)->create(['status' => SubscriptionStatus::Canceled])],
        'subscription updated' => [fn (Tenant $tenant, Plan $plan, Subscription $subscription) => $subscription->forceFill(['status' => SubscriptionStatus::Canceled])->save()],
        'subscription deleted' => [fn (Tenant $tenant, Plan $plan, Subscription $subscription) => $subscription->delete()],
        'plan created' => [fn () => Plan::factory()->create()],
        'plan updated' => [fn (Tenant $tenant, Plan $plan) => $plan->forceFill(['price_cents' => 1])->save()],
        'plan deleted' => [fn () => Model::withoutEvents(fn () => Plan::factory()->create())->delete()],
    ]);

    it('queues nothing when a tenant is renamed', function () {
        Queue::fake();

        $this->acme->forceFill(['name' => 'Renamed'])->save();

        Queue::assertNotPushed(RefreshPlatformStats::class);
    });
});

describe('cache invalidation', function () {
    it('drops the cached tenant after any tenant write, a model save outside a service included', function () {
        app(TenantRepository::class)->find($this->acme->id);

        $this->acme->forceFill(['name' => 'Renamed'])->save();

        expect(Cache::has("tenant:{$this->acme->id}"))->toBeFalse()
            ->and(app(TenantRepository::class)->find($this->acme->id)->name)->toBe('Renamed');
    });

    it('drops the cached plan and active list after a plan is saved or deleted', function (Closure $write) {
        app(PlanRepository::class)->findBySlug($this->plan->slug);
        app(PlanRepository::class)->active();

        $write($this->plan);

        expect(Cache::has("plan:{$this->plan->slug}"))->toBeFalse()
            ->and(Cache::has('plans:active'))->toBeFalse();
    })->with([
        'saved' => [fn (Plan $plan) => $plan->forceFill(['name' => 'Renamed'])->save()],
        'deleted' => [function (Plan $plan) {
            DB::table('subscriptions')->delete();
            $plan->delete();
        }],
    ]);

    it('drops the cached plan when only its limits change, with model events off', function () {
        Sanctum::actingAs(User::factory()->platformAdmin()->create());
        app(PlanRepository::class)->findBySlug($this->plan->slug);

        Plan::withoutEvents(fn () => $this->patchJson('api/v1/admin/plans/'.$this->plan->id, ['features' => ['max_users' => 42]])->assertOk());

        expect(app(PlanRepository::class)->findBySlug($this->plan->slug)->features->firstWhere('key.value', 'max_users')->limit_value)->toBe(42);
    });

    it('drops the tenant\'s cached subscription after a subscription is saved or deleted', function (Closure $write) {
        app(TenantContext::class)->set($this->acme);
        app(SubscriptionRepository::class)->current();

        $write($this->subscription);

        expect(Cache::has("tenant:{$this->acme->id}:subscription"))->toBeFalse();
    })->with([
        'saved' => [fn (Subscription $subscription) => $subscription->forceFill(['status' => SubscriptionStatus::Expired])->save()],
        'deleted' => [fn (Subscription $subscription) => $subscription->delete()],
    ]);

    it('drops the cache only once the surrounding transaction commits', function () {
        app(TenantRepository::class)->find($this->acme->id);

        DB::transaction(function () {
            $this->acme->forceFill(['status' => TenantStatus::Suspended])->save();

            expect(Cache::has("tenant:{$this->acme->id}"))->toBeTrue();
        });

        expect(Cache::has("tenant:{$this->acme->id}"))->toBeFalse();
    });

    it('drops platform analytics when the platform or a plan\'s figures change', function (Closure $write) {
        expectWriteDropsAnalytics($write);
    })->with([
        'platform stats' => [fn () => PlatformStats::select(['id', 'users_count'])->sole()->forceFill(['users_count' => 99])->save()],
        'refresh that changes a plan figure' => [function () {
            DB::table('plan_stats')->update(['mrr_cents' => 99]);
            RefreshPlatformStats::dispatchSync();
        }],
        'refresh that changes a figure' => [function () {
            DB::table('tenant_stats')->update(['customers_count' => 42]);
            RefreshPlatformStats::dispatchSync();
        }],
    ]);

    it('keeps platform analytics cached when a refresh or a save changes nothing', function (Closure $write) {
        RefreshPlatformStats::dispatchSync();
        app(AdminService::class)->analytics();

        $write();

        expect(Cache::has('platform:analytics'))->toBeTrue();
    })->with([
        'identical refresh' => [fn () => RefreshPlatformStats::dispatchSync()],
        'clean platform stats save' => [fn () => PlatformStats::select(['id', 'users_count'])->sole()->save()],
    ]);
});
