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

    /**
     * Changes ticket status and records activity log.
     * Enforces the valid transition lifecycle and sets resolved/closed timestamps.
     */
    public function execute(int $id, User $actor, TicketStatus $next): Ticket
    {
        return DB::transaction(function () use ($id, $actor, $next) {
            // 1. Lock category and ticket rows
            Category::query()->whereKey(Ticket::query()->select('category_id')->whereKey($id))->lockForUpdate()->firstOrFail();
            $ticket = Ticket::query()->lockForUpdate()->findOrFail($id);

            // 2. Check authorization for requested status action
            Gate::forUser($actor)->authorize(match ($next) {
                TicketStatus::Closed => 'close', TicketStatus::Resolved => 'resolve', default => 'addInternalNote'
            }, $ticket);

            // 3. Enforce valid state transition (e.g. open -> in_progress -> resolved -> closed)
            abort_unless($ticket->status->canTransitionTo($next), 409, 'Invalid ticket status transition.');

            $old = $ticket->status->value;

            // 4. Record timestamp and verify SLA breach if resolving
            if ($next === TicketStatus::Resolved) {
                $ticket->resolved_at = now();
                if ($ticket->sla_due_at && $ticket->sla_due_at->lessThanOrEqualTo(now()) && ! $ticket->sla_breached_at) {
                    $ticket->sla_breached_at = $ticket->sla_due_at;
                }
            }

            // 5. Record closed timestamp if closing
            if ($next === TicketStatus::Closed) {
                $ticket->closed_at = now();
            }

            // 6. Save new status and log change in audit history
            $ticket->status = $next;
            $ticket->save();
            $this->audit->execute($ticket, $actor, 'ticket.'.$next->value, ['status' => $old], ['status' => $next->value]);

            return $ticket;
        }, 3);
    }
}
