<?php

use App\Jobs\ProcessDueSubscriptions;
use Illuminate\Support\Facades\Schedule;

Schedule::job(new ProcessDueSubscriptions)->hourly();

Schedule::command('sanctum:prune-expired', ['--hours' => 24])->daily();
