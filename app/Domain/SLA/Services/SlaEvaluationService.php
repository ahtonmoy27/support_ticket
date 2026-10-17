<?php

namespace App\Domain\SLA\Services;

use App\Domain\Audit\Services\RecordActivity;
use App\Domain\Tickets\Enums\TicketStatus;
use App\Models\Ticket;
use Illuminate\Support\Facades\DB;

class SlaEvaluationService
{
    public function __construct(private RecordActivity $audit) {}

    /**
     * Checks a single ticket and records an escalation if approaching warning or breached.
     */
    public function evaluateTicket(Ticket $ticket): bool
    {
        $now = now();
        if (! $ticket->status->isActive() || ! $ticket->sla_warning_at || $ticket->sla_warning_at->greaterThan($now)) {
            return false;
        }

        return (bool) DB::transaction(function () use ($ticket, $now) {
            $locked = Ticket::query()->lockForUpdate()->find($ticket->id);
            if (! $locked || ! $locked->status->isActive() || ! $locked->sla_warning_at || $locked->sla_warning_at->greaterThan($now)) {
                return false;
            }

            // Determine if ticket breached its deadline or is in warning period
            $level = ($locked->sla_due_at && $locked->sla_due_at->lessThanOrEqualTo($now)) ? 'breach' : 'warning';
            if ($locked->escalations()->where('cycle', 1)->where('level', $level)->exists()) {
                return false;
            }

            // Record escalation record and update breached timestamp
            $triggered = $level === 'breach' ? ($locked->sla_due_at ?? $locked->sla_warning_at) : $locked->sla_warning_at;
            $locked->escalations()->create(['cycle' => 1, 'level' => $level, 'triggered_at' => $triggered]);
            if ($level === 'breach' && ! $locked->sla_breached_at && $locked->sla_due_at) {
                $locked->update(['sla_breached_at' => $locked->sla_due_at]);
            }

            // Audit the escalation event (dispatches notification via outbox)
            $this->audit->execute($locked, null, 'sla.'.$level, [], ['triggered_at' => $triggered->toIso8601String()], 'internal');

            $ticket->refresh();

            return true;
        }, 3);
    }

    /**
     * Periodically evaluated by scheduler (tickets:check-sla) to escalate active tickets.
     */
    public function execute(): int
    {
        $count = 0;
        $now = now();
        Ticket::query()->whereIn('status', TicketStatus::activeValues())->where('sla_warning_at', '<=', $now)
            ->where(function ($query) use ($now) {
                $query->where(function ($breaches) use ($now) {
                    $breaches->where('sla_due_at', '<=', $now)
                        ->whereDoesntHave('escalations', fn ($events) => $events->where('cycle', 1)->where('level', 'breach'));
                })->orWhere(function ($warnings) use ($now) {
                    $warnings->where('sla_due_at', '>', $now)
                        ->whereDoesntHave('escalations', fn ($events) => $events->where('cycle', 1)->where('level', 'warning'));
                });
            })->select('id')->chunkById(200, function ($tickets) use (&$count) {
                foreach ($tickets as $candidate) {
                    $count += DB::transaction(function () use ($candidate) {
                        $ticket = Ticket::query()->lockForUpdate()->findOrFail($candidate->id);
                        if (! $ticket->status->isActive() || ! $ticket->sla_warning_at || $ticket->sla_warning_at->greaterThan(now())) {
                            return 0;
                        }
                        $level = ($ticket->sla_due_at && $ticket->sla_due_at->lessThanOrEqualTo(now())) ? 'breach' : 'warning';
                        if ($ticket->escalations()->where('cycle', 1)->where('level', $level)->exists()) {
                            return 0;
                        }
                        $triggered = $level === 'breach' ? ($ticket->sla_due_at ?? $ticket->sla_warning_at) : $ticket->sla_warning_at;
                        $ticket->escalations()->create(['cycle' => 1, 'level' => $level, 'triggered_at' => $triggered]);
                        if ($level === 'breach' && ! $ticket->sla_breached_at && $ticket->sla_due_at) {
                            $ticket->update(['sla_breached_at' => $ticket->sla_due_at]);
                        }
                        $this->audit->execute($ticket, null, 'sla.'.$level, [], ['triggered_at' => $triggered->toIso8601String()], 'internal');

                        return 1;
                    }, 3);
                }
            });

        return $count;
    }
}
