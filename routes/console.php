<?php

use Illuminate\Support\Facades\Schedule;

// Fail campaigns whose discovery worker died so they can be started again.
Schedule::command('campaigns:fail-stale')->everyFifteenMinutes()->withoutOverlapping();

// Fail website audits that stopped responding or were never processed.
Schedule::command('audits:fail-stale')->everyFifteenMinutes()->withoutOverlapping();

// Record the health of every data source for Settings → Data Sources.
Schedule::command('providers:health')->hourly()->withoutOverlapping();

// Free sources: sync configured AllThePlaces spiders (each at most weekly) and the brand registry.
Schedule::command('sources:sync')->dailyAt('02:30')->withoutOverlapping();
Schedule::command('brands:refresh')->weeklyOn(1, '03:00')->withoutOverlapping();

// Re-evaluate businesses scored with older rules (after a config change or upgrade).
Schedule::command('businesses:recalculate-eligibility', ['--outdated'])->dailyAt('04:00')->withoutOverlapping();

// Shared hosting (cPanel): process the database queue from cron instead of a
// long-running worker. Each run exits when the queue is empty or after
// max_time seconds; the overlap lock expires after 10 minutes if a run crashes.
if (config('queue.scheduled_worker.enabled')) {
    $queues = implode(',', array_unique([config('discovery.queue'), config('auditing.queue'), config('sources.queue'), 'default']));

    Schedule::command('queue:work', [
        '--queue='.$queues,
        '--stop-when-empty',
        '--max-time='.config('queue.scheduled_worker.max_time'),
    ])->everyMinute()->withoutOverlapping(10)->runInBackground();
}
