<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Jadwal pembersihan harian file sementara foto estimasi kalori (> 24 jam)
Schedule::command('calorie-estimates:prune --hours=24')->daily();

// System health check every minute — stores metrics, triggers notifications on status change
Schedule::command('system:health-check')->everyMinute();
