<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Populates the Horizon dashboard's metrics graphs (job/queue throughput,
// wait times). See config/horizon.php's `metrics.trim_snapshots`.
Schedule::command('horizon:snapshot')->everyFiveMinutes();

// TTL discipline for flash-sale Redis stock counters (REDIS_WORKPLAN.md
// Phase 6): counters carry no TTL while a sale is live, so this explicitly
// forgets them — and marks the sale 'ended' — once ends_at has passed.
Schedule::command('flash-sale:cleanup-stock')->everyFiveMinutes();
