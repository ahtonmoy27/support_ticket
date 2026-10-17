<?php

namespace App\Notifications;

use App\Models\Ticket;
use Illuminate\Notifications\Messages\MailMessage;

class TicketStatusChangedNotification extends TicketNotification
{
    public function toMail(object $notifiable): MailMessage
    {
        $ticket = Ticket::findOrFail($this->ticketId);
        $statusLabel = ucfirst(str_replace('_', ' ', $ticket->status->value));

        return (new MailMessage)
            ->subject("[{$ticket->ticket_number}] Status Updated: {$statusLabel}")
            ->greeting("Hello {$notifiable->name},")
            ->line("The status of ticket #{$ticket->ticket_number} has changed to {$statusLabel}.")
            ->action('View Ticket', url("/tickets/{$ticket->id}"));
    }

    protected function buildMessage(object $notifiable, ?Ticket $ticket): string
    {
        $status = ucfirst(str_replace('_', ' ', $ticket?->status?->value ?? 'updated'));

        return "Ticket #{$ticket?->ticket_number} marked as {$status}.";
    }
}
