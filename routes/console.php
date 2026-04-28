<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

Schedule::command('rag:run-scheduled-crawls')
    ->cron((string) env('SCHEDULER_RUN_CRON', '* * * * *'))
    ->withoutOverlapping(60)
    ->name('rag-run-scheduled-crawls');
