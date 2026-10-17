<?php

namespace App\Domain\Tickets\Actions;

use App\Domain\Audit\Services\RecordActivity;
use App\Domain\Tickets\Enums\TicketStatus;
use App\Models\Category;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ChangeTicketStatus
{
    public function __construct(private RecordActivity $audit) {}

    public function execute(int $id, User $actor, TicketStatus $next): Ticket
    {
        return DB::transaction(function () use ($id, $actor, $next) {
            Category::query()->whereKey(Ticket::query()->select('category_id')->whereKey($id))->lockForUpdate()->firstOrFail();
            $ticket = Ticket::query()->lockForUpdate()->findOrFail($id);
            Gate::forUser($actor)->authorize(match ($next) {
                TicketStatus::Closed => 'close', TicketStatus::Resolved => 'resolve', default => 'addInternalNote'
            }, $ticket);
            abort_unless($ticket->status->canTransitionTo($next), 409, 'Invalid ticket status transition.');
            $old = $ticket->status->value;
            if ($next === TicketStatus::Resolved) {
                $ticket->resolved_at = now();
                if ($ticket->sla_due_at && $ticket->sla_due_at->lessThanOrEqualTo(now()) && ! $ticket->sla_breached_at) {
                    $ticket->sla_breached_at = $ticket->sla_due_at;
                }
            }
            if ($next === TicketStatus::Closed) {
                $ticket->closed_at = now();
            }
            $ticket->status = $next;
            $ticket->save();
            $this->audit->execute($ticket, $actor, 'ticket.'.$next->value, ['status' => $old], ['status' => $next->value]);

            return $ticket;
        }, 3);
    }
}
