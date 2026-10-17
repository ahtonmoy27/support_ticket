<?php

namespace App\Notifications;

use App\Models\Ticket;
use Illuminate\Notifications\Messages\MailMessage;

class TicketReplyNotification extends TicketNotification
{
    public function toMail(object $notifiable): MailMessage
    {
        $ticket = Ticket::findOrFail($this->ticketId);

        $mail = (new MailMessage)
            ->subject("[{$ticket->ticket_number}] New Reply: {$ticket->subject}");

        if ($notifiable->role === 'customer') {
            $mail->greeting("Hello {$notifiable->name},")
                ->line("A support agent has replied to your ticket #{$ticket->ticket_number}.")
                ->action('View Reply', url("/tickets/{$ticket->id}"));
        } else {
            $mail->greeting("Hello {$notifiable->name},")
                ->line("The customer has posted a reply on ticket #{$ticket->ticket_number}.")
                ->action('View Reply', url("/tickets/{$ticket->id}"));
        }

        return $mail;
    }

    protected function buildMessage(object $notifiable, ?Ticket $ticket): string
    {
        if ($notifiable->role === 'customer') {
            return "A support specialist replied to ticket #{$ticket?->ticket_number}.";
        }

        return "Customer replied to ticket #{$ticket?->ticket_number}.";
    }
}
