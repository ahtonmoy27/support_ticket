<?php

namespace App\Notifications;

use App\Models\Ticket;
use Illuminate\Notifications\Messages\MailMessage;

class TicketAssignedNotification extends TicketNotification
{
    public function toMail(object $notifiable): MailMessage
    {
        $ticket = Ticket::findOrFail($this->ticketId);

        $mail = (new MailMessage)
            ->subject("[{$ticket->ticket_number}] Ticket Assigned: {$ticket->subject}");

        if ($notifiable->id === $ticket->assigned_agent_id) {
            $mail->greeting("Hello {$notifiable->name},")
                ->line("Ticket #{$ticket->ticket_number} has been assigned to you.")
                ->line("Subject: {$ticket->subject}")
                ->line("Priority: {$ticket->priority->value}")
                ->action('View Ticket', url("/tickets/{$ticket->id}"));
        } elseif ($notifiable->role === 'customer') {
            $mail->greeting("Hello {$notifiable->name},")
                ->line("A support specialist has been assigned to your ticket #{$ticket->ticket_number}.")
                ->action('View Ticket', url("/tickets/{$ticket->id}"));
        } else {
            $agentName = $ticket->assignedAgent?->name ?? 'An agent';
            $mail->greeting("Hello {$notifiable->name},")
                ->line("Ticket #{$ticket->ticket_number} was assigned to {$agentName}.")
                ->action('View Ticket', url("/tickets/{$ticket->id}"));
        }

        return $mail;
    }

    protected function buildMessage(object $notifiable, ?Ticket $ticket): string
    {
        if ($notifiable->id === $ticket?->assigned_agent_id) {
            return "Ticket #{$ticket?->ticket_number} has been assigned to you.";
        }

        if ($notifiable->role === 'customer') {
            return "Your ticket #{$ticket?->ticket_number} has been assigned to an agent.";
        }

        return "Ticket #{$ticket?->ticket_number} was assigned to {$ticket?->assignedAgent?->name}.";
    }
}
