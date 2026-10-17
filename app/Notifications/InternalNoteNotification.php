<?php

namespace App\Notifications;

use App\Models\Ticket;
use Illuminate\Notifications\Messages\MailMessage;

class InternalNoteNotification extends TicketNotification
{
    public function shouldSend(object $notifiable, string $channel): bool
    {
        // Enforce that customers never receive internal notes under any circumstance
        if ($notifiable->role === 'customer') {
            return false;
        }

        return parent::shouldSend($notifiable, $channel);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $ticket = Ticket::findOrFail($this->ticketId);

        return (new MailMessage)
            ->subject("[{$ticket->ticket_number}] Internal Note Added")
            ->greeting("Hello {$notifiable->name},")
            ->line("An internal team note was added to ticket #{$ticket->ticket_number}.")
            ->line('Log in to the support workspace to review the internal note.')
            ->action('Open Ticket', url("/tickets/{$ticket->id}"));
    }

    protected function buildMessage(object $notifiable, ?Ticket $ticket): string
    {
        return "Internal note added to ticket #{$ticket?->ticket_number}.";
    }
}
