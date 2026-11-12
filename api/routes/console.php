<?php

use Illuminate\Support\Facades\Schedule;

$interval = max(1, (int) config('sovera.sync.interval_minutes', 5));

// Fetch new mail every few minutes, but only while the Sovera Mail window is open.
Schedule::command('sovera:sync-accounts --only-while-open')
    ->cron("*/{$interval} * * * *")
    ->withoutOverlapping()
    ->runInBackground();

// The local AI is never scheduled: it runs only when the user clicks "Process new".

// Keep the local database tidy: failed jobs older than a week.
Schedule::command('queue:prune-failed --hours=168')->daily();
