<?php

use App\Enums\SubscriptionStatus;
use App\Jobs\ProcessDueSubscriptions;
use App\Jobs\RefreshPlatformStats;
use App\Models\Subscription;
use App\Tenancy\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    $this->travelTo(Carbon::parse('2026-09-17 10:00:00'));
});

/**
 * A live subscription canceled on 1 September that runs until the given instant.
 */
function canceledSubscription(string $endsAt): Subscription
{
    return Subscription::factory()->create([
        'starts_at' => '2026-08-01 09:00:00',
        'canceled_at' => '2026-09-01 12:00:00',
        'ends_at' => $endsAt,
    ]);
}

/**
 * Run the sweep in-process; the queue is faked so the refreshes it dispatches can be counted.
 */
function sweepDueSubscriptions(): void
{
    app()->call([new ProcessDueSubscriptions, 'handle']);
}

function reloaded(Subscription $subscription): Subscription
{
    return Subscription::withoutGlobalScope(TenantScope::class)->findOrFail($subscription->id);
}

it('queues the sweep', function () {
    ProcessDueSubscriptions::dispatch();

    Queue::assertPushed(ProcessDueSubscriptions::class);
});

it('expires a canceled subscription whose period is over, keeping its dates', function (string $endsAt) {
    $subscription = canceledSubscription($endsAt);

    sweepDueSubscriptions();

    expect(reloaded($subscription))
        ->status->toBe(SubscriptionStatus::Expired)
        ->ends_at->toDateTimeString()->toBe($endsAt)
        ->canceled_at->toDateTimeString()->toBe('2026-09-01 12:00:00');
})->with(['an hour ago' => '2026-09-17 09:00:00', 'exactly now' => '2026-09-17 10:00:00']);

it('leaves a subscription that is running, canceled but not yet due, or already ended untouched', function () {
    $subscriptions = [
        Subscription::factory()->create(['starts_at' => '2025-01-01 09:00:00']),
        canceledSubscription('2026-09-17 11:00:00'),
        Subscription::factory()->create(['status' => SubscriptionStatus::Canceled, 'canceled_at' => '2026-09-01 09:00:00', 'ends_at' => '2026-09-01 09:00:00']),
        Subscription::factory()->create(['status' => SubscriptionStatus::Expired, 'canceled_at' => '2026-08-01 09:00:00', 'ends_at' => '2026-09-01 09:00:00']),
    ];
    $before = collect($subscriptions)->map(fn (Subscription $subscription) => reloaded($subscription)->toArray());
    Queue::fake();

    sweepDueSubscriptions();

    expect(collect($subscriptions)->map(fn (Subscription $subscription) => reloaded($subscription)->toArray()))->toEqual($before);
    Queue::assertNotPushed(RefreshPlatformStats::class);
});

it('drops the cached subscription of the expired tenants only', function () {
    $expiring = canceledSubscription('2026-09-17 09:00:00');
    $notDue = canceledSubscription('2026-09-18 09:00:00');
    $running = Subscription::factory()->create();

    foreach ([$expiring, $notDue, $running] as $subscription) {
        Cache::put("tenant:{$subscription->tenant_id}:subscription", 'cached', now()->addDay());
    }

    sweepDueSubscriptions();

    expect(Cache::has("tenant:{$expiring->tenant_id}:subscription"))->toBeFalse()
        ->and(Cache::has("tenant:{$notDue->tenant_id}:subscription"))->toBeTrue()
        ->and(Cache::has("tenant:{$running->tenant_id}:subscription"))->toBeTrue();
});

it('queues one platform stats refresh when subscriptions expired', function () {
    canceledSubscription('2026-09-17 09:00:00');
    canceledSubscription('2026-09-17 08:00:00');
    Queue::fake();

    sweepDueSubscriptions();

    Queue::assertPushed(RefreshPlatformStats::class, 1);
});

it('changes nothing when run again', function () {
    $expired = canceledSubscription('2026-09-17 09:00:00');
    sweepDueSubscriptions();
    $afterFirstRun = reloaded($expired)->toArray();
    Queue::fake();
    $this->travel(5)->minutes();

    sweepDueSubscriptions();

    expect(reloaded($expired)->toArray())->toEqual($afterFirstRun);
    Queue::assertNotPushed(RefreshPlatformStats::class);
});

it('runs the same number of queries however many subscriptions are due', function (int $due) {
    Subscription::factory()->count(3)->create();
    foreach (range(1, $due) as $index) {
        canceledSubscription('2026-09-17 09:00:00');
    }
    DB::enableQueryLog();

    sweepDueSubscriptions();

    // One chunk: select the due ids, update them; a chunk under 1,000 rows ends the sweep without another select.
    expect(DB::getQueryLog())->toHaveCount(2)
        ->and(Subscription::withoutGlobalScope(TenantScope::class)->where('status', SubscriptionStatus::Expired)->count())->toBe($due);
})->with([2, 20]);

it('schedules the sweep hourly and the token prune daily', function () {
    Artisan::call('schedule:list');

    expect(Artisan::output())
        ->toMatch('/0 \* \* \* \*.*ProcessDueSubscriptions/')
        ->toMatch('/0 0 \* \* \*.*sanctum:prune-expired --hours=24/');
});
