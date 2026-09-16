<?php

namespace Database\Seeders;

use App\Jobs\RefreshPlatformStats;
use Illuminate\Database\Seeder;

/**
 * Seeds the demo dataset a reviewer logs in with; safe to run on every setup. Model events stay on, so observers drop caches.
 */
class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            PlanSeeder::class,
            PlatformAdminSeeder::class,
            DemoTenantSeeder::class,
        ]);

        // Refreshed now rather than by the queued job 30 seconds later, so analytics are right the moment setup ends.
        app()->call([new RefreshPlatformStats, 'handle']);
    }
}
