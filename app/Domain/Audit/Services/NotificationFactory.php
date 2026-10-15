<?php

namespace App\Domain\Audit\Services;

use App\Models\OutboxEvent;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\InternalNoteNotification;
use App\Notifications\SlaApproachingNotification;
use App\Notifications\SlaBreachedNotification;
use App\Notifications\TicketAssignedNotification;
use App\Notifications\TicketChanged;
use App\Notifications\TicketCreatedNotification;
use App\Notifications\TicketNotification;
use App\Notifications\TicketReassignedNotification;
use App\Notifications\TicketReplyNotification;
use App\Notifications\TicketStatusChangedNotification;

class NotificationFactory
{
    public function createForRecipient(OutboxEvent $event, User $recipient, ?Ticket $ticket = null): ?TicketChanged
    {
        $ticket = $ticket ?? $event->ticket;
        if (! $ticket) {
            return null;
        }

        $eventType = $event->event_type;
        $context = $event->payload ?? [];

        // Security gate: Customers MUST NEVER receive internal notes
        if ($recipient->role === 'customer' && $event->isInternal()) {
            return null;
        }

        // Reassignment check: If recipient is the previous assigned agent
        $oldAgentId = $context['old']['assigned_agent_id'] ?? null;
        if (($eventType === 'ticket.reassigned' || $eventType === 'ticket.assigned')
            && $oldAgentId !== null
            && (int) $recipient->id === (int) $oldAgentId) {
            return new TicketReassignedNotification($ticket->id, 'ticket.reassigned', $event->id, $context);
        }

        return match ($eventType) {
            'ticket.created' => new TicketCreatedNotification($ticket->id, $eventType, $event->id, $context),
            'ticket.assigned', 'ticket.claimed', 'ticket.reassigned' => new TicketAssignedNotification($ticket->id, $eventType, $event->id, $context),
            'ticket.internal_note' => new InternalNoteNotification($ticket->id, $eventType, $event->id, $context),
            'ticket.replied' => ($context['visibility'] ?? null) === 'internal'
                ? new InternalNoteNotification($ticket->id, 'ticket.internal_note', $event->id, $context)
                : new TicketReplyNotification($ticket->id, $eventType, $event->id, $context),
            'ticket.resolved', 'ticket.closed' => new TicketStatusChangedNotification($ticket->id, $eventType, $event->id, $context),
            'sla.warning' => new SlaApproachingNotification($ticket->id, $eventType, $event->id, $context),
            'sla.breach' => new SlaBreachedNotification($ticket->id, $eventType, $event->id, $context),
            default => new TicketChanged($ticket->id, $eventType, $event->id, $context),
        };
    }
}
