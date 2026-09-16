<?php

use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function () {
    [$this->acme, $this->globex] = Tenant::factory()->count(2)->create();
    $this->token = User::factory()->for($this->acme)->create()->createToken('api')->plainTextToken;
});

function listCustomers(string $query = ''): TestResponse
{
    return test()->withToken(test()->token)->getJson('api/v1/customers'.$query);
}

it('returns the first 15 rows newest first with pagination meta and links', function () {
    Customer::factory()->for($this->acme)->count(20)->sequence(fn ($sequence) => [
        'created_at' => Carbon::parse('2026-09-01')->addMinutes($sequence->index),
    ])->create();

    $response = listCustomers()
        ->assertOk()
        ->assertJsonStructure([
            'data' => ['*' => ['id', 'name', 'email', 'phone', 'status']],
            'meta' => ['current_page', 'per_page', 'total', 'last_page'],
            'links' => ['first', 'prev', 'next', 'last'],
        ])
        ->assertJsonPath('meta.current_page', 1)
        ->assertJsonPath('meta.per_page', 15)
        ->assertJsonPath('meta.total', 20)
        ->assertJsonPath('meta.last_page', 2)
        ->assertJsonPath('links.prev', null)
        ->assertJsonCount(15, 'data');

    $newestFirst = Customer::withoutGlobalScope(TenantScope::class)->orderByDesc('created_at')->limit(15)->pluck('id')->all();

    expect(array_column($response->json('data'), 'id'))->toBe($newestFirst)
        ->and($response->json('links.first'))->toEndWith('/api/v1/customers?page=1')
        ->and($response->json('links.next'))->toEndWith('/api/v1/customers?page=2')
        ->and($response->json('links.last'))->toEndWith('/api/v1/customers?page=2');
});

it('keeps the query string in pagination links', function () {
    Customer::factory()->for($this->acme)->count(3)->create(['name' => 'Alpha', 'status' => 'active']);

    expect(listCustomers('?per_page=1&filter[status]=active&search=a')->json('links.next'))
        ->toContain('per_page=1')->toContain('search=a')->toContain('filter%5Bstatus%5D=active')->toContain('page=2');
});

it('pages with a client-chosen page size', function () {
    Customer::factory()->for($this->acme)->count(5)->create();

    listCustomers('?per_page=2&page=3')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.current_page', 3)
        ->assertJsonPath('meta.per_page', 2)
        ->assertJsonPath('meta.total', 5)
        ->assertJsonPath('meta.last_page', 3)
        ->assertJsonPath('links.next', null);
});

it('refuses a page size above the cap or below one', function (string $perPage) {
    listCustomers('?per_page='.$perPage)->assertUnprocessable()->assertJsonValidationErrors('per_page');
})->with(['101', '0', 'all']);

it('accepts the maximum page size', function () {
    listCustomers('?per_page=100')->assertOk()->assertJsonPath('meta.per_page', 100);
});

it('answers a page past the end with empty data and valid meta', function () {
    Customer::factory()->for($this->acme)->count(3)->create();

    listCustomers('?page=99')
        ->assertOk()
        ->assertJsonPath('data', [])
        ->assertJsonPath('meta.current_page', 99)
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('meta.last_page', 1);
});

it('refuses a page below one', function () {
    listCustomers('?page=0')->assertUnprocessable()->assertJsonValidationErrors('page');
});

it('filters by a whitelisted column', function () {
    Customer::factory()->for($this->acme)->count(2)->create(['status' => 'active']);
    $inactive = Customer::factory()->for($this->acme)->create(['status' => 'inactive']);

    listCustomers('?filter[status]=inactive')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $inactive->id);
});

it('refuses an unknown filter key', function (string $method) {
    $this->withToken($this->token)->{$method}('api/v1/customers?filter[tenant_id]=1')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['filter' => 'The filter may only contain: status.']);
})->with([
    'json request' => ['getJson'],
    'plain request' => ['get'],
]);

it('refuses a filter value outside the enum', function () {
    listCustomers('?filter[status]=deleted')->assertUnprocessable()->assertJsonValidationErrors('filter.status');
});

it('refuses a filter that is not a keyed list', function () {
    listCustomers('?filter=active')->assertUnprocessable()->assertJsonValidationErrors('filter');
});

it('matches the search term as a case-insensitive prefix of any searchable column', function () {
    $byName = Customer::factory()->for($this->acme)->create(['name' => 'Janet Rahman', 'email' => 'jr@acme.test']);
    $byEmail = Customer::factory()->for($this->acme)->create(['name' => 'Karim', 'email' => 'JANE.k@acme.test']);
    Customer::factory()->for($this->acme)->create(['name' => 'Mary Jane', 'email' => 'mary@acme.test']);

    $ids = array_column(listCustomers('?search=jAnE')->assertOk()->json('data'), 'id');

    expect($ids)->toEqualCanonicalizing([$byName->id, $byEmail->id]);
});

it('treats LIKE wildcards in the search term literally', function (string $term, string $expected) {
    Customer::factory()->for($this->acme)->create(['name' => '100% Cotton']);
    Customer::factory()->for($this->acme)->create(['name' => '1000 Cranes']);
    Customer::factory()->for($this->acme)->create(['name' => 'a_b Ltd']);
    Customer::factory()->for($this->acme)->create(['name' => 'axb Ltd']);
    Customer::factory()->for($this->acme)->create(['name' => 'back\\slash']);
    Customer::factory()->for($this->acme)->create(['name' => 'backXslash']);

    listCustomers('?search='.urlencode($term))
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.name', $expected);
})->with([
    'percent' => ['100%', '100% Cotton'],
    'underscore' => ['a_b', 'a_b Ltd'],
    'backslash' => ['back\\', 'back\\slash'],
]);

it('refuses search on an over-long term', function () {
    listCustomers('?search='.str_repeat('a', 101))->assertUnprocessable()->assertJsonValidationErrors('search');
});

it('breaks ties on the primary key so rows never repeat or vanish across pages', function () {
    Customer::factory()->for($this->acme)->count(7)->create(['created_at' => '2026-09-01 00:00:00']);

    $seen = [];
    foreach ([1, 2, 3, 4] as $page) {
        $seen = [...$seen, ...array_column(listCustomers('?per_page=2&page='.$page)->json('data'), 'id')];
    }

    $expected = Customer::withoutGlobalScope(TenantScope::class)->orderByDesc('id')->pluck('id')->all();

    expect($seen)->toBe($expected);
});

it('never shows another tenant\'s rows, in data or in the total', function () {
    Customer::factory()->for($this->acme)->count(2)->create(['name' => 'Shared Name']);
    Customer::factory()->for($this->globex)->count(3)->create(['name' => 'Shared Name']);

    $response = listCustomers('?search=shared&filter[status]=active')
        ->assertOk()
        ->assertJsonPath('meta.total', 2)
        ->assertJsonCount(2, 'data');

    expect(Customer::withoutGlobalScope(TenantScope::class)->whereIn('id', array_column($response->json('data'), 'id'))->pluck('tenant_id')->unique()->all())
        ->toBe([$this->acme->id]);
});

it('costs the four authentication queries, one filtered count and one page', function () {
    Customer::factory()->for($this->acme)->count(20)->sequence(fn ($sequence) => ['name' => 'Alpha '.$sequence->index])->create();
    // Creating the fixtures warmed the tenant cache; the count below is for a cold one.
    Cache::flush();
    DB::enableQueryLog();

    listCustomers('?search=a&filter[status]=active&page=2&per_page=5')->assertOk();

    $listingQueries = array_values(array_filter(
        DB::getQueryLog(),
        fn (array $entry) => str_contains($entry['query'], '"customers"'),
    ));

    expect(DB::getQueryLog())->toHaveCount(6)
        ->and($listingQueries)->toHaveCount(2)
        ->and($listingQueries[1]['query'])->not->toContain('*');
});

it('takes the unfiltered total from tenant_stats and never counts the table', function () {
    Customer::factory()->for($this->acme)->count(3)->create();
    Customer::factory()->for($this->globex)->count(2)->create();
    // Diverged on purpose, so the total can only have come from the stored count.
    DB::table('tenant_stats')->where('tenant_id', $this->acme->id)->update(['customers_count' => 4000000]);
    DB::enableQueryLog();

    listCustomers('?per_page=2')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.total', 4000000)
        ->assertJsonPath('meta.last_page', 2000000);

    expect(strtolower(implode("\n", array_column(DB::getQueryLog(), 'query'))))->not->toContain('count(');
});

it('still counts the filtered set when a filter or a search is given', function (string $query) {
    Customer::factory()->for($this->acme)->count(3)->create(['name' => 'Alpha', 'status' => 'active']);
    DB::table('tenant_stats')->where('tenant_id', $this->acme->id)->update(['customers_count' => 4000000]);
    DB::enableQueryLog();

    listCustomers($query)->assertOk()->assertJsonPath('meta.total', 3);

    expect(implode("\n", array_column(DB::getQueryLog(), 'query')))->toContain('count(*)');
})->with(['?filter[status]=active', '?search=alp']);

it('skips the page query when nothing matches', function () {
    DB::enableQueryLog();

    listCustomers()->assertOk()->assertJsonPath('meta.total', 0);

    expect(DB::getQueryLog())->toHaveCount(5);
});

it('documents the listing parameters and the pagination envelope from the same declaration', function () {
    $operation = $this->getJson('docs/api.json')->assertOk()->json('paths./customers.get');

    expect(array_column($operation['parameters'], 'name'))->toBe(['page', 'per_page', 'search', 'filter[status]'])
        ->and($operation['parameters'][1]['schema']['maximum'])->toBe(100);

    $body = $operation['responses']['200']['content']['application/json']['schema']['properties'];

    expect(array_keys($body))->toEqualCanonicalizing(['data', 'links', 'meta'])
        ->and(array_keys($body['meta']['properties']))->toContain('current_page', 'per_page', 'total', 'last_page')
        ->and(array_keys($body['links']['properties']))->toEqualCanonicalizing(['first', 'last', 'prev', 'next']);
});
