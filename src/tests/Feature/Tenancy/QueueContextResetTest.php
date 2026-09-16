<?php

use App\Models\Customer;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Fixtures\RecordVisibleCustomerEmails;

uses(RefreshDatabase::class);

beforeEach(function () {
    [$this->acme, $this->globex] = Tenant::factory()->count(2)->create();
    Customer::factory()->for($this->acme)->create(['email' => 'jane@acme.test']);
    Customer::factory()->for($this->globex)->create(['email' => 'john@globex.test']);

    RecordVisibleCustomerEmails::$observations = [];
    $this->queue = 'tenancy-test-'.Str::lower(Str::random(12));
});

it('does not leak tenant context between jobs run by the same worker', function () {
    RecordVisibleCustomerEmails::dispatch($this->acme->id)->onConnection('redis')->onQueue($this->queue);
    RecordVisibleCustomerEmails::dispatch(null)->onConnection('redis')->onQueue($this->queue);
    RecordVisibleCustomerEmails::dispatch($this->globex->id)->onConnection('redis')->onQueue($this->queue);

    drainQueue($this->queue);

    expect(RecordVisibleCustomerEmails::$observations)->toBe([
        ['had_context' => false, 'emails' => ['jane@acme.test']],
        ['had_context' => false, 'emails' => null],
        ['had_context' => false, 'emails' => ['john@globex.test']],
    ]);
});

it('clears the context after a job that throws', function () {
    RecordVisibleCustomerEmails::dispatch($this->acme->id, throwAfterwards: true)->onConnection('redis')->onQueue($this->queue);

    drainQueue($this->queue);

    expect(RecordVisibleCustomerEmails::$observations)->toHaveCount(1)
        ->and(app(TenantContext::class)->has())->toBeFalse();
});

it('leaves the caller context in place for a sync job, which runs inside the caller', function () {
    app(TenantContext::class)->set($this->acme);

    RecordVisibleCustomerEmails::dispatch(null)->onConnection('sync');

    expect(RecordVisibleCustomerEmails::$observations)->toBe([['had_context' => true, 'emails' => ['jane@acme.test']]])
        ->and(app(TenantContext::class)->id())->toBe($this->acme->id);
});
