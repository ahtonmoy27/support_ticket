<?php
namespace App\Notifications;
use App\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;
class TicketChanged extends Notification implements ShouldQueue {
    use Queueable;
    public int $tries = 3;
    public function __construct(public int $ticketId, public string $eventType, public int $eventId) {
        $this->onQueue('notifications');
    }
    public function via(object $notifiable): array { return ['mail',\App\Notifications\Channels\DatabaseChannel::class]; }
    public function backoff(): array { return [30,120,300]; }
    public function shouldSend(object $notifiable, string $channel): bool {
        $ticket = Ticket::find($this->ticketId);
        return $ticket && $notifiable->is_active && $notifiable->can('view',$ticket)
            && (! str_starts_with($this->eventType,'sla.') || $notifiable->role !== 'customer');
    }
    public function toMail(object $notifiable): MailMessage {
        $ticket = Ticket::findOrFail($this->ticketId);
        return (new MailMessage)->subject('Support ticket '.$ticket->ticket_number)->line('Ticket '.$ticket->ticket_number.' was updated: '.$this->eventType.'.');
    }
    public function toArray(object $notifiable): array { return ['ticket_id'=>$this->ticketId,'event'=>$this->eventType,'event_id'=>$this->eventId]; }
}
