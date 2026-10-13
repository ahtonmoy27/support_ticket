<?php

namespace App\Domain\SLA\Services;

use App\Domain\Audit\Services\RecordActivity;
use App\Domain\Tickets\Enums\TicketStatus;
use App\Models\Ticket;
use Illuminate\Support\Facades\DB;

class SlaEvaluationService
{
    public function __construct(private RecordActivity $audit) {}

    public function execute(): int
    {
        $count = 0;
        Ticket::query()->whereIn('status', TicketStatus::activeValues())->where('sla_warning_at', '<=', now())->select('id')->chunkById(200, function ($tickets) use (&$count) {
            foreach ($tickets as $candidate) {
                $count += DB::transaction(function () use ($candidate) {
                    $ticket = Ticket::query()->lockForUpdate()->findOrFail($candidate->id);
                    if (! $ticket->status->isActive() || $ticket->sla_warning_at->greaterThan(now())) {
                        return 0;
                    }
                    $level = $ticket->sla_due_at->lessThanOrEqualTo(now()) ? 'breach' : 'warning';
                    if ($ticket->escalations()->where('cycle', 1)->where('level', $level)->exists()) {
                        return 0;
                    }
                    $triggered = $level === 'breach' ? $ticket->sla_due_at : $ticket->sla_warning_at;
                    $ticket->escalations()->create(['cycle' => 1, 'level' => $level, 'triggered_at' => $triggered]);
                    if ($level === 'breach' && ! $ticket->sla_breached_at) {
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
