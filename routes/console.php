<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('pipeline:dispatch-reminders')->everyMinute();
Schedule::command('pipeline:record-progress-snapshots')->dailyAt('23:55');
Schedule::command('businesses:clean-dormant')->dailyAt('03:00');

// Fire recurring income & expense occurrences that are due today.
Schedule::command('income:process-recurring')->dailyAt('00:05');
Schedule::command('expenses:process-recurring')->dailyAt('00:10');

Schedule::command('subscriptions:expire-trials')->dailyAt('02:00');
Schedule::command('subscriptions:renew')->dailyAt('02:15');
Schedule::command('subscriptions:suspend-past-due')->dailyAt('02:30');
Schedule::command('subscriptions:cancel-at-period-end')->dailyAt('02:45');

// Recognize earned deferred subscription revenue into software revenue.
Schedule::command('accounting:recognize-revenue')->dailyAt('03:30');
