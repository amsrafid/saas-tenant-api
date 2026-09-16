<?php

use Illuminate\Support\Facades\DB;

it('runs against the dedicated testing database, never the seeded one', function () {
    expect(DB::connection()->getDatabaseName())->toBe('saas_testing');
});
