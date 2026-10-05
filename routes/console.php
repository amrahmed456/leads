<?php

use Illuminate\Support\Facades\Schedule;

// Fail campaigns whose discovery worker died so they can be started again.
Schedule::command('campaigns:fail-stale')->everyFifteenMinutes()->withoutOverlapping();
