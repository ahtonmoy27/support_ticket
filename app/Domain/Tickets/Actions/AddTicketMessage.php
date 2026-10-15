<?php

namespace App\Domain\Tickets\Actions;

use App\Domain\Audit\Services\RecordActivity;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class AddTicketMessage
{
    public function __construct(private RecordActivity $audit) {}

    /** @param array{body:string,visibility:string} $data */
    public function execute(int $ticketId, User $actor, array $data): TicketMessage
    {
        return DB::transaction(function () use ($ticketId, $actor, $data): TicketMessage {
            $ticket = Ticket::query()->lockForUpdate()->findOrFail($ticketId);
            Gate::forUser($actor)->authorize($data['visibility'] === 'internal' ? 'addInternalNote' : 'reply', $ticket);
            abort_if(! $ticket->status->isActive(), 409, 'Resolved or closed tickets cannot receive messages.');
            $message = $ticket->messages()->create([
                'body' => $data['body'], 'visibility' => $data['visibility'], 'author_id' => $actor->id,
            ]);
            $action = $data['visibility'] === 'internal' ? 'ticket.internal_note' : 'ticket.replied';
            $this->audit->execute($ticket, $actor, $action, [], ['message_id' => $message->id], $message->visibility);

            return $message;
        }, 3);
    }
}
