<?php

namespace App\Domain\Audit\Services;

use App\Models\OutboxEvent;
use App\Models\Ticket;
use App\Models\User;

class RecordActivity
{
    public function execute(Ticket $ticket, ?User $actor, string $action, array $old = [], array $new = [], string $visibility = 'public', bool $notify = true): void
    {
        $ticket->activities()->create(['actor_id' => $actor?->id, 'action' => $action, 'old_values' => $old, 'new_values' => $new, 'visibility' => $visibility]);
        if ($notify) {
            $ids = $visibility === 'internal' ? [] : [$ticket->customer_id];
            if ($ticket->assigned_agent_id) {
                $ids[] = $ticket->assigned_agent_id;
            }
            if (str_starts_with($action, 'sla.')) {
                $ids = array_merge($ids, User::query()->where('role', 'admin')->where('is_active', true)->pluck('id')->all());
            }
            $ids = array_values(array_unique(array_filter($ids, fn (int $id): bool => $id !== $actor?->id
                || ($action === 'ticket.created' && $id === $ticket->customer_id))));
            if ($ids !== []) {
                OutboxEvent::create(['ticket_id' => $ticket->id, 'event_type' => $action, 'recipient_ids' => $ids]);
            }
        }
    }
}
