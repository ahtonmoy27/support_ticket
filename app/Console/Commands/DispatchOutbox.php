<?php

namespace App\Console\Commands;

use App\Models\OutboxEvent;
use App\Models\User;
use App\Notifications\TicketChanged;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DispatchOutbox extends Command
{
    protected $signature = 'tickets:dispatch-outbox';

    protected $description = 'Queue pending ticket notifications from the durable outbox';

    public function handle(): int
    {
        OutboxEvent::query()->whereNull('dispatched_at')->select('id')->chunkById(100, function ($events) {
            foreach ($events as $candidate) {
                DB::transaction(function () use ($candidate) {
                    $event = OutboxEvent::query()->lockForUpdate()->findOrFail($candidate->id);
                    if ($event->dispatched_at) {
                        return;
                    }
                    foreach (User::query()->whereIn('id', $event->recipient_ids)->where('is_active', true)->get() as $recipient) {
                        $recipient->notify(new TicketChanged($event->ticket_id, $event->event_type, $event->id));
                    }
                    $event->update(['dispatched_at' => now()]);
                }, 3);
            }
        });

        Cache::put('support.heartbeat.outbox', now()->timestamp, now()->addDay());

        return self::SUCCESS;
    }
}
