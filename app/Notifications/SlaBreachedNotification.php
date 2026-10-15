<?php

namespace App\Notifications;

use App\Models\Ticket;
use Illuminate\Notifications\Messages\MailMessage;

class SlaBreachedNotification extends TicketNotification
{
    public function toMail(object $notifiable): MailMessage
    {
        $ticket = Ticket::findOrFail($this->ticketId);
        $due = $ticket->sla_due_at?->toDayDateTimeString() ?? 'past due';

        return (new MailMessage)
            ->subject("[ESCALATION] SLA Breached: {$ticket->ticket_number} ({$ticket->priority->value})")
            ->greeting("Hello {$notifiable->name},")
            ->line("Ticket #{$ticket->ticket_number} has breached its SLA resolution deadline.")
            ->line("Priority: {$ticket->priority->value}")
            ->line("Target Deadline: {$due}")
            ->line("Immediate escalation action is required.")
            ->action('View Escalated Ticket', url("/tickets/{$ticket->id}"));
    }

    protected function buildMessage(object $notifiable, ?Ticket $ticket): string
    {
        return "SLA breached: Ticket #{$ticket?->ticket_number} has exceeded resolution deadline.";
    }
}

