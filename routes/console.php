<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

\Illuminate\Support\Facades\Schedule::command('tickets:check-sla')->everyMinute()->withoutOverlapping()->onOneServer();
\Illuminate\Support\Facades\Schedule::command('tickets:dispatch-outbox')->everyMinute()->withoutOverlapping()->onOneServer();
\Illuminate\Support\Facades\Schedule::call(function () {
    \App\Models\TicketImport::query()->where('status','pending')->select('id')->chunkById(100,function ($imports) {
        foreach ($imports as $import) { \App\Jobs\ProcessTicketImport::dispatch($import->id); }
    });
})->name('tickets:recover-imports')->everyMinute()->withoutOverlapping()->onOneServer();
