<?php

use App\Jobs\ProcessTicketImport;
use App\Models\TicketImport;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('tickets:check-sla')->everyMinute()->withoutOverlapping()->onOneServer();
Schedule::command('tickets:dispatch-outbox')->everyMinute()->withoutOverlapping()->onOneServer();
Schedule::call(function () {
    TicketImport::query()->where('status', 'pending')->select('id')->chunkById(100, function ($imports) {
        foreach ($imports as $import) {
            ProcessTicketImport::dispatch($import->id);
        }
    });
})->name('tickets:recover-imports')->everyMinute()->withoutOverlapping()->onOneServer();
