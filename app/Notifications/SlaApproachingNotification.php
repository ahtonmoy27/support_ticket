<?php

namespace App\Notifications;

use App\Models\Ticket;
use Illuminate\Notifications\Messages\MailMessage;

class SlaApproachingNotification extends TicketNotification
{
    public function toMail(object $notifiable): MailMessage
    {
        $ticket = Ticket::findOrFail($this->ticketId);
        $due = $ticket->sla_due_at?->toDayDateTimeString() ?? 'soon';

        return (new MailMessage)
            ->subject("[URGENT] SLA Approaching: {$ticket->ticket_number} ({$ticket->priority->value})")
            ->greeting("Hello {$notifiable->name},")
            ->line("Ticket #{$ticket->ticket_number} is approaching its SLA resolution deadline.")
            ->line("Priority: {$ticket->priority->value}")
            ->line("Deadline: {$due}")
            ->action('View Ticket Now', url("/tickets/{$ticket->id}"));
    }

    protected function buildMessage(object $notifiable, ?Ticket $ticket): string
    {
        return "SLA warning: Ticket #{$ticket?->ticket_number} approaching resolution deadline.";
    }
}

