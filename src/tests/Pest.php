<?php

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature');

/**
 * Drain the given Redis queue with a real in-process worker, the long-lived process where context could leak.
 */
function drainQueue(string $queue): void
{
    Artisan::call('queue:work', ['connection' => 'redis', '--queue' => $queue, '--stop-when-empty' => true, '--tries' => 1, '--sleep' => 0]);
}
