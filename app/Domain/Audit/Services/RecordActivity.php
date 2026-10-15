<?php

namespace App\Domain\Audit\Services;

use App\Jobs\DeliverOutboxEvent;
use App\Models\OutboxEvent;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

class RecordActivity
{
    public function __construct(private ?NotificationRecipientResolver $resolver = null)
    {
        $this->resolver = $resolver ?? app(NotificationRecipientResolver::class);
    }

    public function execute(Ticket $ticket, ?User $actor, string $action, array $old = [], array $new = [], string $visibility = 'public', bool $notify = true): void
    {
        $ticket->activities()->create([
            'actor_id' => $actor?->id,
            'action' => $action,
            'old_values' => $old,
            'new_values' => $new,
            'visibility' => $visibility,
        ]);

        if ($notify) {
            $ids = $this->resolver->resolve($ticket, $actor, $action, $old, $new, $visibility);
            if ($ids !== []) {
                $payload = [
                    'actor_id' => $actor?->id,
                    'actor_name' => $actor?->name,
                    'actor_role' => $actor?->role,
                    'visibility' => $visibility,
                    'old' => $old,
                    'new' => $new,
                ];

                $event = OutboxEvent::create([
                    'ticket_id' => $ticket->id,
                    'event_type' => $action,
                    'recipient_ids' => $ids,
                    'payload' => $payload,
                ]);

                DB::afterCommit(function () use ($event): void {
                    try {
                        Bus::dispatch(new DeliverOutboxEvent($event->id));
                    } catch (\Throwable $exception) {
                        report($exception);
                    }
                });
            }
        }
    }
}
