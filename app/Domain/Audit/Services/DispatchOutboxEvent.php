<?php

namespace App\Domain\Audit\Services;

use App\Models\OutboxEvent;
use App\Models\User;
use App\Notifications\TicketChanged;
use Illuminate\Support\Facades\DB;

class DispatchOutboxEvent
{
    public function execute(int $eventId): void
    {
        DB::transaction(function () use ($eventId): void {
            $event = OutboxEvent::query()->lockForUpdate()->find($eventId);
            if ($event === null || $event->dispatched_at !== null) {
                return;
            }
            foreach (User::query()->whereIn('id', $event->recipient_ids)->where('is_active', true)->get() as $recipient) {
                $recipient->notify(new TicketChanged($event->ticket_id, $event->event_type, $event->id));
            }
            $event->update(['dispatched_at' => now()]);
        }, 3);
    }
}
