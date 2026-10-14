<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('tickets:check-sla')->everyMinute()->withoutOverlapping()->onOneServer();
Schedule::command('tickets:dispatch-outbox')->everyMinute()->withoutOverlapping()->onOneServer();
Schedule::command('tickets:recover-imports')->everyMinute()->withoutOverlapping()->onOneServer();
Schedule::command('tickets:monitor')->everyMinute()->withoutOverlapping()->onOneServer();
