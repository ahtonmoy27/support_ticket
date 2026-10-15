<?php

namespace App\Notifications;

use App\Models\Ticket;
use Illuminate\Notifications\Messages\MailMessage;

class TicketCreatedNotification extends TicketNotification
{
    public function toMail(object $notifiable): MailMessage
    {
        $ticket = Ticket::findOrFail($this->ticketId);

        $mail = (new MailMessage)
            ->subject("[{$ticket->ticket_number}] Ticket Created: {$ticket->subject}");

        if ($notifiable->role === 'customer') {
            $mail->greeting("Hello {$notifiable->name},")
                ->line("Your support ticket #{$ticket->ticket_number} has been created successfully.")
                ->line("Subject: {$ticket->subject}")
                ->line("Our support team will review your request shortly.")
                ->action('View Ticket', url("/tickets/{$ticket->id}"));
        } else {
            $customerName = $ticket->customer?->name ?? 'A customer';
            $mail->greeting("Hello {$notifiable->name},")
                ->line("A new support ticket #{$ticket->ticket_number} has been created by {$customerName}.")
                ->line("Subject: {$ticket->subject}")
                ->line("Priority: {$ticket->priority->value}")
                ->action('View Ticket in Desk', url("/tickets/{$ticket->id}"));
        }

        return $mail;
    }

    protected function buildMessage(object $notifiable, ?Ticket $ticket): string
    {
        if ($notifiable->role === 'customer') {
            return "Your ticket #{$ticket?->ticket_number} was created successfully.";
        }

        return "New ticket #{$ticket?->ticket_number} created by {$ticket?->customer?->name}.";
    }
}

