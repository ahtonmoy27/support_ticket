<?php

namespace App\Notifications;

use App\Models\Ticket;
use Illuminate\Notifications\Messages\MailMessage;

class TicketReassignedNotification extends TicketNotification
{
    public function shouldSend(object $notifiable, string $channel): bool
    {
        $ticket = Ticket::find($this->ticketId);

        if (! $ticket || ! $notifiable->is_active) {
            return false;
        }

        $oldAgentId = $this->context['old']['assigned_agent_id'] ?? null;
        if ($oldAgentId !== null && (int) $notifiable->id !== (int) $oldAgentId) {
            return false;
        }

        return true;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $ticket = Ticket::findOrFail($this->ticketId);

        return (new MailMessage)
            ->subject("[{$ticket->ticket_number}] Ticket Reassigned: {$ticket->subject}")
            ->greeting("Hello {$notifiable->name},")
            ->line("Ticket #{$ticket->ticket_number} has been transferred and reassigned to another support agent.")
            ->line('You are no longer assigned to this ticket.')
            ->action('View Ticket Details', url("/tickets/{$ticket->id}"));
    }

    protected function buildMessage(object $notifiable, ?Ticket $ticket): string
    {
        return "Ticket #{$ticket?->ticket_number} was reassigned to another agent.";
    }
}
