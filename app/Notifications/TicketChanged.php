<?php

namespace App\Notifications;

use App\Models\Ticket;
use App\Models\User;
use App\Notifications\Channels\DatabaseChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TicketChanged extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public int $ticketId,
        public string $eventType,
        public int $eventId,
        public array $context = []
    ) {
        $this->onQueue(config('support.notifications_queue'));
    }

    public function via(object $notifiable): array
    {
        $channels = [DatabaseChannel::class];
        if (in_array(config('broadcasting.default'), ['pusher', 'reverb', 'ably', 'redis', 'mercure'], true)) {
            $channels[] = 'broadcast';
        }
        $channels[] = 'mail';

        return $channels;
    }

    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        $ticket = Ticket::find($this->ticketId);

        if (! $ticket || ! $notifiable->is_active) {
            return false;
        }

        // Authorization check: User must be authorized to view the ticket
        if (! $notifiable->can('view', $ticket)) {
            return false;
        }

        // Rule E: Internal notes MUST NEVER reach customers under any circumstances
        if ($this->isInternal() && $notifiable->role === 'customer') {
            return false;
        }

        // Rule G & H: Customer-facing SLA notifications must be explicitly enabled
        if (str_starts_with($this->eventType, 'sla.') && $notifiable->role === 'customer') {
            $key = $this->eventType === 'sla.breach' ? 'customer.sla_breach' : 'customer.sla_warning';
            if (! config("support.notifications.{$key}", false)) {
                return false;
            }
        }

        return true;
    }

    public function isInternal(): bool
    {
        return $this->eventType === 'ticket.internal_note'
            || ($this->context['visibility'] ?? null) === 'internal';
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        $ticket = Ticket::find($this->ticketId);

        return (new BroadcastMessage([
            'ticket_id' => $this->ticketId,
            'ticket_number' => $ticket?->ticket_number,
            'event' => $this->eventType,
            'event_id' => $this->eventId,
            'message' => $this->buildMessage($notifiable, $ticket),
            'created_at' => now()->toIso8601String(),
        ]))->onQueue(config('support.notifications_queue'));
    }

    public function toArray(object $notifiable): array
    {
        $ticket = Ticket::find($this->ticketId);

        return [
            'ticket_id' => $this->ticketId,
            'ticket_number' => $ticket?->ticket_number,
            'event' => $this->eventType,
            'event_id' => $this->eventId,
            'message' => $this->buildMessage($notifiable, $ticket),
            'url' => '/tickets/'.$this->ticketId,
            'created_at' => now()->toIso8601String(),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $ticket = Ticket::findOrFail($this->ticketId);

        return (new MailMessage)
            ->subject('Support ticket '.$ticket->ticket_number)
            ->line('Ticket '.$ticket->ticket_number.' was updated: '.$this->eventType.'.');
    }

    protected function buildMessage(object $notifiable, ?Ticket $ticket): string
    {
        return "Ticket #{$ticket?->ticket_number} was updated.";
    }
}
