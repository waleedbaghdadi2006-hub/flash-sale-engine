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
